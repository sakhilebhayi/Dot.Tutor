<?php

namespace App\Domain\Classroom\Services;

use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Safety\Services\ContactRedactor;
use App\Models\User;

/**
 * One shared plain-text page per lesson that everyone in the room can edit. Concurrency is optimistic: a save names
 * the version it started from; if someone saved in between, the save is refused with the current text and the browser
 * keeps the person's own text beside it to merge (no silent overwrite).
 */
class SharedNotes
{
    public function __construct(private readonly ClassroomChat $chat, private readonly ContactRedactor $redactor) {}

    /** @return array{version: int, body: string} */
    public function save(Classroom $classroom, User $by, string $body, int $baseVersion): array
    {
        if (mb_strlen($body) > (int) config('classroom.notes.max_length')) {
            throw new ClassroomException('notes_too_long', 'Shared notes can be up to '.config('classroom.notes.max_length').' characters.');
        }
        if ($this->chat->minorInLesson($classroom)) {
            $body = $this->redactor->redact($body)['text'];
        }

        $updated = Classroom::query()->whereKey($classroom->id)->where('shared_notes_version', $baseVersion)->update([
            'shared_notes' => $body,
            'shared_notes_version' => $baseVersion + 1,
            'shared_notes_updated_by' => $by->id,
            'updated_at' => now(),
        ]);
        $classroom->refresh();

        if ($updated === 0) {
            throw new ClassroomException('notes_conflict', 'Someone else saved the notes while you were typing. Their version is shown; your text is kept so you can add it back.', 409, [
                'version' => $classroom->shared_notes_version,
                'body' => (string) $classroom->shared_notes,
            ]);
        }

        return ['version' => $classroom->shared_notes_version, 'body' => (string) $classroom->shared_notes];
    }
}
