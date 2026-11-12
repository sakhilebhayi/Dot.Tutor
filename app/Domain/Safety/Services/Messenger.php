<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\RoomDirectory;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\ConversationParticipant;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\MessageAttachment;
use App\Domain\Safety\Support\Participants;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Conversations and messages, with the safeguarding rules applied here (not in views): the gate decides who may
 * talk, guardians of a minor are added to every conversation the minor is in, contact details are hidden when a
 * minor is involved, and rate limits hold for every caller.
 */
class Messenger
{
    public function __construct(
        private readonly SafeguardingGate $gate,
        private readonly AccountStanding $standing,
        private readonly ContactRedactor $redactor,
        private readonly RoomDirectory $rooms,
    ) {}

    /**
     * Open (or reuse) a conversation and post the first message.
     *
     * @param  list<int>  $recipientIds  direct: one person; room: members of the room; booking/support: ignored
     * @param  list<UploadedFile>  $attachments
     */
    public function start(User $actor, string $contextType, ?int $contextId, array $recipientIds, string $body, ?string $subject = null, array $attachments = []): Message
    {
        $memberIds = $this->membersFor($actor, $contextType, $contextId, $recipientIds);
        $others = array_values(array_diff($memberIds, [$actor->id]));

        $this->gate->allows($actor, 'message', new Participants($others), ['context_type' => $contextType, 'context_id' => $contextId])->authorize();

        $conversation = $this->existing($contextType, $contextId, $memberIds);

        if ($conversation === null) {
            $this->throttleNewConversations($actor);

            $conversation = DB::transaction(function () use ($actor, $contextType, $contextId, $memberIds, $subject) {
                $conversation = Conversation::create([
                    'context_type' => $contextType,
                    'context_id' => $contextId,
                    'subject' => $subject !== null ? mb_substr($subject, 0, 160) : null,
                    'created_by_id' => $actor->id,
                ]);

                foreach ($memberIds as $id) {
                    $conversation->participants()->create(['user_id' => $id, 'role' => ConversationParticipant::MEMBER]);
                }

                return $conversation;
            });

            $this->syncConversationGuardians($conversation);

            Audit::record($actor, 'safety.conversation.started', $conversation, [
                'context_type' => $contextType, 'context_id' => $contextId, 'involves_minor' => $conversation->involves_minor,
            ]);
        }

        return $this->send($conversation, $actor, $body, $attachments);
    }

    /** @param list<UploadedFile> $attachments */
    public function send(Conversation $conversation, User $sender, string $body, array $attachments = []): Message
    {
        if ($conversation->context_type === Conversation::SUPPORT && $sender->is_platform_operator
            && $conversation->participantFor($sender) === null) {
            $conversation->participants()->updateOrCreate(['user_id' => $sender->id], ['role' => ConversationParticipant::OPERATOR, 'left_at' => null]);
        }

        $this->gate->allows($sender, 'message', $conversation)->authorize();

        if ($attachments !== []) {
            $this->gate->allows($sender, 'upload', $conversation)->authorize();
        }

        $body = trim($body);

        if ($body === '' && $attachments === []) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a file.']);
        }

        if (mb_strlen($body) > (int) config('safety.messaging.max_length')) {
            throw ValidationException::withMessages(['body' => 'This message is too long.']);
        }

        $this->throttleMessages($sender);

        // Re-check on every send: a member's age band can change (a guardian confirms a date of birth).
        $involvesMinor = $this->involvesMinor($conversation);

        if ($involvesMinor !== $conversation->involves_minor) {
            $conversation->forceFill(['involves_minor' => $involvesMinor])->save();
        }

        $redaction = $involvesMinor ? $this->redactor->redact($body) : ['text' => $body, 'redactions' => []];
        $stored = [];

        try {
            $message = DB::transaction(function () use ($conversation, $sender, $body, $redaction, $attachments, &$stored) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'body' => $redaction['text'],
                    'original_body' => $redaction['redactions'] === [] ? null : $body,
                    'redactions' => $redaction['redactions'] === [] ? null : $redaction['redactions'],
                ]);

                foreach ($attachments as $file) {
                    $stored[] = $this->storeAttachment($message, $file);
                }

                $conversation->forceFill(['last_message_at' => now()])->save();
                $conversation->participants()->where('user_id', $sender->id)->update(['last_read_at' => now()]);

                return $message;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk(config('safety.messaging.disk'))->delete($path);
            }

            throw $e;
        }

        if ($redaction['redactions'] !== []) {
            Audit::record($sender, 'safety.message.redacted', $message, ['kinds' => $redaction['redactions']]);
        }

        return $message;
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $conversation->participants()->where('user_id', $user->id)->update(['last_read_at' => now()]);
    }

    public function unreadCount(Conversation $conversation, User $user): int
    {
        $participant = $conversation->participantFor($user);

        return $conversation->messages()
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
            ->when($participant?->last_read_at, fn ($q, $at) => $q->where('created_at', '>', $at))
            ->count();
    }

    /** Add the active guardians of every minor member to each of the learner's conversations; drop revoked ones. */
    public function syncGuardians(User $learner): void
    {
        Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $learner->id)->where('role', ConversationParticipant::MEMBER))
            ->get()
            ->each(fn (Conversation $c) => $this->syncConversationGuardians($c));
    }

    public function syncConversationGuardians(Conversation $conversation): void
    {
        $memberIds = $conversation->memberIds();
        $members = User::query()->whereIn('id', $memberIds)->get();
        $wanted = [];

        foreach ($members as $member) {
            if (! $this->standing->isMinor($member)) {
                continue;
            }

            foreach (GuardianLink::query()->active()->where('learner_id', $member->id)->pluck('guardian_id') as $guardianId) {
                if (! in_array((int) $guardianId, $memberIds, true)) {
                    $wanted[(int) $guardianId] = $member->id;
                }
            }
        }

        foreach ($wanted as $guardianId => $learnerId) {
            $conversation->participants()->updateOrCreate(
                ['user_id' => $guardianId],
                ['role' => ConversationParticipant::GUARDIAN, 'guardian_of_id' => $learnerId, 'left_at' => null],
            );
        }

        $conversation->participants()->where('role', ConversationParticipant::GUARDIAN)->whereNull('left_at')
            ->whereNotIn('user_id', array_keys($wanted) ?: [0])
            ->update(['left_at' => now()]);

        $involvesMinor = $members->contains(fn (User $m) => $this->standing->isMinor($m));

        if ($involvesMinor !== $conversation->involves_minor) {
            $conversation->forceFill(['involves_minor' => $involvesMinor])->save();
        }
    }

    public function involvesMinor(Conversation $conversation): bool
    {
        return User::query()->whereIn('id', $conversation->memberIds())->get()
            ->contains(fn (User $m) => $this->standing->isMinor($m));
    }

    /**
     * @param  list<int>  $recipientIds
     * @return list<int>
     */
    private function membersFor(User $actor, string $contextType, ?int $contextId, array $recipientIds): array
    {
        $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));

        switch ($contextType) {
            case Conversation::BOOKING:
                $session = TutorSession::query()->with('tutorProfile')->find($contextId);

                if ($session === null) {
                    throw new NotFoundHttpException('Booking not found.');
                }

                $members = [(int) $session->student_id, (int) $session->tutorProfile->user_id];

                if (! in_array($actor->id, $members, true)) {
                    abort(403, 'Only the learner and the tutor of this booking can message about it.');
                }

                return $members;

            case Conversation::ROOM:
                $room = $contextId ? $this->rooms->find($contextId) : null;

                if ($room === null) {
                    throw new NotFoundHttpException('Room not found.');
                }

                $roomMembers = $room->safetyMemberIds();

                if (! in_array($actor->id, $roomMembers, true) || array_diff($recipientIds, $roomMembers) !== [] || $recipientIds === []) {
                    abort(403, 'Room conversations are between members of the room.');
                }

                return array_values(array_unique([$actor->id, ...$recipientIds]));

            case Conversation::SUPPORT:
                return [$actor->id];

            case Conversation::DIRECT:
                if (count($recipientIds) !== 1 || $recipientIds[0] === $actor->id || ! User::query()->whereKey($recipientIds[0])->exists()) {
                    throw ValidationException::withMessages(['recipient_id' => 'Choose one other person.']);
                }

                return [$actor->id, $recipientIds[0]];
        }

        throw ValidationException::withMessages(['context_type' => 'Unknown conversation context.']);
    }

    /** @param list<int> $memberIds */
    private function existing(string $contextType, ?int $contextId, array $memberIds): ?Conversation
    {
        if ($contextType === Conversation::SUPPORT) {
            return null; // each support request is its own thread
        }

        sort($memberIds);

        return Conversation::query()->where('context_type', $contextType)->where('context_id', $contextId)
            ->whereNull('closed_at')->orderBy('id')->get()
            ->first(function (Conversation $c) use ($memberIds) {
                $ids = $c->memberIds();
                sort($ids);

                return $ids === $memberIds;
            });
    }

    private function storeAttachment(Message $message, UploadedFile $file): string
    {
        $maxBytes = (int) config('safety.messaging.attachment_max_kb') * 1024;
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if ($file->getSize() > $maxBytes || ! in_array($mime, (array) config('safety.messaging.attachment_mimes'), true)) {
            throw ValidationException::withMessages(['attachments' => 'Attach PDF, PNG, JPEG or plain text files up to '.config('safety.messaging.attachment_max_kb').' KB.']);
        }

        $contents = $this->stripImageMetadata($mime, (string) file_get_contents($file->getRealPath()));
        $extension = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'text/plain' => 'txt'][$mime];
        $path = 'messages/'.$message->conversation_id.'/'.bin2hex(random_bytes(16)).'.'.$extension;

        Storage::disk(config('safety.messaging.disk'))->put($path, $contents);

        MessageAttachment::create([
            'message_id' => $message->id,
            'path' => $path,
            'original_name' => mb_substr(preg_replace('/[^\w.\- ]+/u', '_', $file->getClientOriginalName()) ?: 'file', 0, 200),
            'mime' => $mime,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]);

        return $path;
    }

    /** Re-encode images so location and camera metadata never reach the other side (needs GD; kept as-is without). */
    private function stripImageMetadata(string $mime, string $contents): string
    {
        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || ! function_exists('imagecreatefromstring')) {
            return $contents;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw ValidationException::withMessages(['attachments' => 'That image could not be read.']);
        }

        ob_start();
        $mime === 'image/png' ? imagepng($image) : imagejpeg($image, null, 88);
        $clean = (string) ob_get_clean();

        return $clean;
    }

    private function throttleMessages(User $sender): void
    {
        $key = 'safety-messages:'.$sender->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('safety.messaging.per_minute'))) {
            throw new ThrottleRequestsException('Too many messages. Wait a moment.');
        }

        RateLimiter::hit($key, 60);
    }

    private function throttleNewConversations(User $actor): void
    {
        $key = 'safety-conversations:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('safety.messaging.new_conversations_per_hour'))) {
            throw new ThrottleRequestsException('Too many new conversations. Try again later.');
        }

        RateLimiter::hit($key, 3600);
    }
}
