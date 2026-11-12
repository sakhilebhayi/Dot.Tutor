<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\LegacyLevelMapping;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps pre-R-47 subject rows onto the global catalogue without losing a row
 * or changing an id (tutor_subject and tutor_sessions point at subjects.id):
 *
 *  - a legacy row whose name matches a catalogue row (case-insensitive) is
 *    linked to it with canonical_subject_id ("Mathematics, primary" ->
 *    "Mathematics");
 *  - a legacy row with no match becomes a catalogue row itself (it gets a slug);
 *  - the legacy `level` string is never rewritten; it maps onto academic levels
 *    through legacy_subject_level_map. Strings with no mapping are reported.
 */
class LegacySubjectMapper
{
    /**
     * @return array{linked: int, catalogued: int, unmapped_levels: list<string>, rows_before: int, rows_after: int}
     */
    public function run(): array
    {
        $before = Subject::query()->count();
        $linked = 0;
        $catalogued = 0;

        DB::transaction(function () use (&$linked, &$catalogued): void {
            $pending = Subject::query()->whereNull('slug')->whereNull('canonical_subject_id')->orderBy('id')->get();

            foreach ($pending as $subject) {
                $match = Subject::query()
                    ->whereNotNull('slug')
                    ->whereKeyNot($subject->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim($subject->name))])
                    ->orderBy('id')
                    ->first();

                if ($match) {
                    $subject->forceFill(['canonical_subject_id' => $match->canonicalId()])->save();
                    $linked++;

                    continue;
                }

                $subject->forceFill(['slug' => $this->uniqueSlug($subject)])->save();
                $catalogued++;
            }
        });

        return [
            'linked' => $linked,
            'catalogued' => $catalogued,
            'unmapped_levels' => $this->unmappedLevels(),
            'rows_before' => $before,
            'rows_after' => Subject::query()->count(),
        ];
    }

    /**
     * Legacy level strings in use that map onto no academic level.
     *
     * @return list<string>
     */
    public function unmappedLevels(): array
    {
        $mapped = LegacyLevelMapping::query()->distinct()->pluck('legacy_level')->all();

        return Subject::query()
            ->whereNotIn('level', $mapped ?: [''])
            ->distinct()
            ->orderBy('level')
            ->pluck('level')
            ->values()
            ->all();
    }

    /**
     * Give catalogue rows created by the sync a truthful legacy `level` band
     * (the band covering most of the levels the subject is offered at), so the
     * pre-R-47 screens that still show the band label it sensibly.
     *
     * @param  list<int>  $subjectIds
     */
    public function assignBands(array $subjectIds): void
    {
        if ($subjectIds === []) {
            return;
        }

        $bandByLevel = LegacyLevelMapping::query()->get(['legacy_level', 'academic_level_id'])
            ->groupBy('academic_level_id')
            ->map(fn ($rows) => $rows->first()->legacy_level);

        foreach ($subjectIds as $subjectId) {
            $counts = SubjectOffering::query()->where('subject_id', $subjectId)->pluck('academic_level_id')
                ->map(fn (int $levelId) => $bandByLevel[$levelId] ?? null)
                ->filter()
                ->countBy()
                ->sortDesc();

            if ($counts->isNotEmpty()) {
                Subject::query()->whereKey($subjectId)->update(['level' => $counts->keys()->first()]);
            }
        }
    }

    private function uniqueSlug(Subject $subject): string
    {
        $base = Str::limit(Str::slug($subject->name) ?: 'subject', 70, '');
        $slug = $base;

        if (Subject::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$subject->id}";
        }

        return $slug;
    }
}
