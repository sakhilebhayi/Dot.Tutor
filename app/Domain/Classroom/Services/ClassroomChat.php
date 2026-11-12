<?php

namespace App\Domain\Classroom\Services;

use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Models\ClassroomMessage;
use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\ContactRedactor;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The lesson chat: to the whole room only (no private messages between people in a lesson), stored with the lesson,
 * reportable to the safeguarding queue. When a minor is booked into the lesson, contact details are hidden with
 * safety's ContactRedactor and the original kept encrypted for a safeguarding officer.
 */
class ClassroomChat
{
    public function __construct(
        private readonly ContactRedactor $redactor,
        private readonly AccountStanding $standing,
        private readonly ReportSink $reports,
    ) {}

    public function send(Classroom $classroom, User $sender, string $body): ClassroomMessage
    {
        $body = trim(preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '', $body) ?? '');
        if ($body === '') {
            throw new ClassroomException('empty_message', 'Write a message first.');
        }
        if (mb_strlen($body) > (int) config('classroom.chat.max_length')) {
            throw new ClassroomException('message_too_long', 'Messages can be up to '.config('classroom.chat.max_length').' characters.');
        }
        $key = 'classroom-chat:'.$sender->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('classroom.chat.per_minute'))) {
            throw new ClassroomException('too_many_messages', 'You are sending messages very quickly. Wait a moment.', 429);
        }
        RateLimiter::hit($key, 60);

        $original = null;
        $redactions = null;
        if ($this->minorInLesson($classroom)) {
            $result = $this->redactor->redact($body);
            if ($result['redactions'] !== []) {
                $original = $body;
                $redactions = $result['redactions'];
                $body = $result['text'];
            }
        }

        return ClassroomMessage::query()->create([
            'classroom_id' => $classroom->id,
            'user_id' => $sender->id,
            'body' => $body,
            'original_body' => $original,
            'redactions' => $redactions,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function since(Classroom $classroom, int $since, User $viewer): array
    {
        return ClassroomMessage::query()->with('sender:id,name')->where('classroom_id', $classroom->id)->where('id', '>', $since)
            ->orderBy('id')->limit(100)->get()
            ->map(fn (ClassroomMessage $m) => $this->present($m, $viewer))->all();
    }

    /** @return array<string, mixed> */
    public function present(ClassroomMessage $m, User $viewer): array
    {
        return [
            'id' => $m->id,
            'from' => $m->sender?->name ?? 'Former member',
            'mine' => $m->user_id === $viewer->id,
            'body' => $m->hidden_at ? null : $m->body,
            'hidden' => $m->hidden_at !== null,
            'redacted' => ! empty($m->redactions),
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    public function report(User $reporter, ClassroomMessage $message, string $category, ?string $details): Report
    {
        $cat = ReportCategory::tryFrom($category) ?? ReportCategory::Other;

        return $this->reports->report($reporter, $message, $cat, $details ? mb_substr($details, 0, 2000) : null);
    }

    public function minorInLesson(Classroom $classroom): bool
    {
        foreach ($classroom->learnerIds() as $id) {
            $learner = User::query()->find($id);
            if ($learner && $this->standing->isMinor($learner)) {
                return true;
            }
        }

        return false;
    }
}
