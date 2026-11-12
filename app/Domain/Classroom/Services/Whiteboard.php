<?php

namespace App\Domain\Classroom\Services;

use App\Domain\Classroom\Models\BoardOp;
use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Safety\Services\ContactRedactor;
use App\Models\User;

/**
 * The shared whiteboard: an append-only log of drawing operations per page, replayed by every browser. Coordinates are
 * fractions of the page (0 to 1) so the board looks the same on a phone and a wide screen. Colours are named, never
 * free values, so nothing a person sends is used as CSS.
 */
class Whiteboard
{
    public const KINDS = ['pen', 'erase', 'line', 'rect', 'ellipse', 'text', 'clear'];

    public const COLOURS = ['ink', 'primary', 'danger', 'info', 'warning', 'success'];

    public function __construct(private readonly ClassroomChat $chat, private readonly ContactRedactor $redactor) {}

    /** @param array<string, mixed> $data */
    public function add(Classroom $classroom, User $by, int $page, string $kind, array $data): BoardOp
    {
        if ($page < 1 || $page > $classroom->board_pages) {
            throw new ClassroomException('bad_page', 'That whiteboard page does not exist.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new ClassroomException('bad_op', 'Unknown whiteboard tool.');
        }

        $clean = match ($kind) {
            'pen', 'erase' => ['points' => $this->points($data['points'] ?? null), 'width' => $this->width($data['width'] ?? 3), 'colour' => $this->colour($data['colour'] ?? 'ink')],
            'line', 'rect', 'ellipse' => ['from' => $this->point($data['from'] ?? null), 'to' => $this->point($data['to'] ?? null), 'width' => $this->width($data['width'] ?? 3), 'colour' => $this->colour($data['colour'] ?? 'ink')],
            'text' => ['at' => $this->point($data['at'] ?? null), 'text' => $this->text($classroom, $data['text'] ?? ''), 'size' => max(12, min(72, (int) ($data['size'] ?? 24))), 'colour' => $this->colour($data['colour'] ?? 'ink')],
            'clear' => [],
        };
        if ($kind === 'erase') {
            unset($clean['colour']);
        }

        return BoardOp::query()->create([
            'classroom_id' => $classroom->id, 'user_id' => $by->id, 'page' => $page, 'kind' => $kind, 'data' => $clean, 'created_at' => now(),
        ]);
    }

    public function addPage(Classroom $classroom): int
    {
        $max = (int) config('classroom.board.max_pages');
        $updated = Classroom::query()->whereKey($classroom->id)->where('board_pages', '<', $max)->increment('board_pages');
        if ($updated === 0) {
            throw new ClassroomException('too_many_pages', "The whiteboard has {$max} pages, the most it can hold.");
        }

        return (int) $classroom->refresh()->board_pages;
    }

    /** @return list<array<string, mixed>> */
    public function since(Classroom $classroom, int $since, int $limit = 500): array
    {
        return BoardOp::query()->where('classroom_id', $classroom->id)->where('id', '>', $since)->orderBy('id')->limit($limit)->get()
            ->map(fn (BoardOp $op) => ['id' => $op->id, 'page' => $op->page, 'kind' => $op->kind, 'data' => $op->data, 'by' => $op->user_id])->all();
    }

    /** @return list<array{0: float, 1: float}> */
    private function points(mixed $points): array
    {
        if (! is_array($points) || count($points) < 1) {
            throw new ClassroomException('bad_op', 'A stroke needs points.');
        }
        if (count($points) > (int) config('classroom.board.max_points')) {
            throw new ClassroomException('bad_op', 'That stroke is too long.');
        }

        return array_map(fn ($p) => $this->point($p), array_values($points));
    }

    /** @return array{0: float, 1: float} */
    private function point(mixed $p): array
    {
        if (! is_array($p) || count($p) !== 2 || ! is_numeric($p[0] ?? null) || ! is_numeric($p[1] ?? null)) {
            throw new ClassroomException('bad_op', 'A whiteboard point is two numbers.');
        }

        return [round(max(0, min(1, (float) $p[0])), 4), round(max(0, min(1, (float) $p[1])), 4)];
    }

    private function width(mixed $w): int
    {
        return max(1, min(40, (int) $w));
    }

    private function colour(mixed $c): string
    {
        return in_array($c, self::COLOURS, true) ? $c : 'ink';
    }

    private function text(Classroom $classroom, mixed $text): string
    {
        $text = trim((string) $text);
        if ($text === '' || mb_strlen($text) > (int) config('classroom.board.max_text')) {
            throw new ClassroomException('bad_op', 'Text on the whiteboard is 1 to '.config('classroom.board.max_text').' characters.');
        }

        return $this->chat->minorInLesson($classroom) ? $this->redactor->redact($text)['text'] : $text;
    }
}
