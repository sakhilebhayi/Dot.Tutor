<?php

namespace App\Domain\Safety\Http\Controllers;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\ConversationParticipant;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\MessageAttachment;
use App\Domain\Safety\Services\ContactRedactor;
use App\Domain\Safety\Services\Messenger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request, Messenger $messenger): JsonResponse|View
    {
        $user = $request->user();
        $conversations = Conversation::query()->visibleTo($user)->with('users:id,name')
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get();

        $items = $conversations->map(fn (Conversation $c) => [
            'id' => $c->id,
            'context' => $c->context_type,
            'context_id' => $c->context_id,
            'subject' => $c->subject,
            'involves_minor' => $c->involves_minor,
            'your_role' => $c->users->firstWhere('id', $user->id)?->pivot->role,
            'people' => $c->users->where('id', '!=', $user->id)->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->pivot->role])->values()->all(),
            'unread' => $messenger->unreadCount($c, $user),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ])->all();

        return $request->expectsJson() ? response()->json(['conversations' => $items]) : view('safety::conversations', ['conversations' => $items]);
    }

    public function store(Request $request, Messenger $messenger): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'context_type' => ['required', Rule::in([Conversation::BOOKING, Conversation::ROOM, Conversation::SUPPORT, Conversation::DIRECT])],
            'context_id' => ['nullable', 'integer', 'min:1', Rule::requiredIf(fn () => in_array($request->input('context_type'), [Conversation::BOOKING, Conversation::ROOM], true))],
            'recipient_ids' => ['nullable', 'array', 'max:20'],
            'recipient_ids.*' => ['integer', 'min:1'],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:'.config('safety.messaging.max_length')],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:'.config('safety.messaging.attachment_max_kb')],
        ]);

        $message = $messenger->start(
            $request->user(),
            $validated['context_type'],
            isset($validated['context_id']) ? (int) $validated['context_id'] : null,
            $validated['recipient_ids'] ?? [],
            (string) ($validated['body'] ?? ''),
            $validated['subject'] ?? null,
            $request->file('attachments', []),
        );

        if ($request->expectsJson()) {
            return response()->json(['conversation_id' => $message->conversation_id, 'message' => $this->present($message, $request->user())], 201);
        }

        return redirect()->route('safety.conversations.show', $message->conversation_id, 303);
    }

    public function show(Request $request, Conversation $conversation, Messenger $messenger): JsonResponse|View
    {
        Gate::authorize('view', $conversation);
        $user = $request->user();
        $participant = $conversation->participantFor($user);

        if ($participant === null) {
            // An operator reading a reported or support conversation without being part of it: on the record.
            Audit::record($user, 'safety.conversation.operator_read', $conversation);
        } else {
            $messenger->markRead($conversation, $user);
        }

        $messages = $conversation->messages()->with(['sender:id,name', 'attachments'])->orderBy('id')->limit(500)->get()
            ->map(fn (Message $m) => $this->present($m, $user))->all();

        $data = [
            'conversation' => [
                'id' => $conversation->id,
                'context' => $conversation->context_type,
                'context_id' => $conversation->context_id,
                'subject' => $conversation->subject,
                'involves_minor' => $conversation->involves_minor,
                'closed' => $conversation->closed_at !== null,
                'your_role' => $participant?->role ?? 'operator_reader',
                'people' => $conversation->users()->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->pivot->role])->all(),
                'notice' => $conversation->involves_minor
                    ? 'A learner under 18 is part of this conversation. Their parent or guardian can read it, and contact details are hidden.'
                    : null,
            ],
            'messages' => $messages,
        ];

        return $request->expectsJson() ? response()->json($data) : view('safety::conversation', $data);
    }

    public function send(Request $request, Conversation $conversation, Messenger $messenger): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $conversation);

        $validated = $request->validate([
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:'.config('safety.messaging.max_length')],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:'.config('safety.messaging.attachment_max_kb')],
        ]);

        $message = $messenger->send($conversation, $request->user(), (string) ($validated['body'] ?? ''), $request->file('attachments', []));

        return $request->expectsJson()
            ? response()->json(['message' => $this->present($message, $request->user())], 201)
            : redirect()->route('safety.conversations.show', $conversation, 303);
    }

    public function attachment(Request $request, MessageAttachment $attachment): Response
    {
        Gate::authorize('view', $attachment->message);

        $disk = Storage::disk(config('safety.messaging.disk'));
        abort_unless($disk->exists($attachment->path), 404);

        return response($disk->get($attachment->path), 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($attachment->original_name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Message $message, User $viewer): array
    {
        $hidden = $message->isHidden();
        $canSeeHidden = (bool) $viewer->is_platform_operator;

        return [
            'id' => $message->id,
            'sender' => $message->sender ? ['id' => $message->sender->id, 'name' => $message->sender->name] : null,
            'mine' => $message->sender_id === $viewer->id,
            'body' => $hidden && ! $canSeeHidden ? null : $message->body,
            'hidden' => $hidden,
            'hidden_note' => $hidden ? 'A moderator removed this message.' : null,
            'redacted' => ! empty($message->redactions),
            'redaction_note' => ! empty($message->redactions) ? ContactRedactor::NOTE : null,
            'attachments' => $hidden && ! $canSeeHidden ? [] : $message->attachments->map(fn (MessageAttachment $a) => [
                'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size_bytes' => $a->size_bytes,
                'url' => route('safety.attachments.show', $a),
            ])->all(),
            'sent_at' => $message->created_at->toIso8601String(),
        ];
    }
}
