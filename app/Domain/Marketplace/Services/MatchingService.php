<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Contracts\BatchLanguageLookup;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Enums\OfferingType;
use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Support\LearningNeed;
use App\Domain\Marketplace\Support\MinorUnits;
use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use App\Services\Scheduling\SlotFinder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Brief section 6: rank tutors for a learner's need and say why.
 *
 * Deterministic: the same need, data and clock give the same list. Every
 * score is a sum of named terms (docs/R47_MATCHING.md), each in [0, 1] times a
 * configured weight, and every result carries those terms and plain-language
 * reasons. Nothing in the score depends on payment: there is no boost column
 * and no paid placement. A capped "new tutor" term keeps newcomers
 * discoverable without letting them outrank a clearly better fit. Ties are
 * broken by a hash of the need and the tutor id, so no tutor is permanently
 * first or last among equals.
 *
 * Hard filters (a tutor is left out, never just ranked low): not approved,
 * does not teach the subject, cannot teach in the requested format or area,
 * teaches only languages the learner did not list, more than the budget
 * tolerance over budget, a level outside every declared range by more than one
 * step, or no offering able to deliver a group/package/weekly request.
 */
class MatchingService
{
    public const TERMS = ['scope', 'curriculum', 'language', 'availability', 'budget', 'help_type', 'verification', 'reviews', 'new_tutor'];

    /** @var Collection<int, Collection<int, TutorSession>>|null the candidates' booked sessions over the matching horizon, by tutor profile id */
    private ?Collection $busy = null;

    public function __construct(
        private SlotFinder $slots,
        private AcademicScopePort $academic,
        private LanguageProfilePort $languages,
    ) {}

    /**
     * @return array{need: array<string, mixed>, results: list<array<string, mixed>>, considered: int, excluded: int, notes: list<string>}
     */
    public function match(LearningNeed $need, ?User $learner = null, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::createFromTimestamp(($now ?? Carbon::now())->getTimestamp(), 'UTC');
        $weights = (array) config('marketplace.matching.weights');

        $candidates = TutorProfile::query()
            ->with(['user', 'teachingScopes', 'availabilityWindows', 'blockedDates', 'offerings' => fn ($q) => $q->where('is_active', true)->with('subjects')])
            ->where('status', TutorProfileStatus::Approved->value)
            ->whereHas('subjects', fn ($q) => $q->where('subjects.id', $need->subjectId)->where('subjects.is_active', true))
            ->when($learner, fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $learner->id)))
            ->orderBy('id')
            ->limit((int) config('marketplace.matching.max_candidates'))
            ->get();

        $reviewStats = $this->reviewStats($candidates->pluck('id')->all());

        // One query each for every candidate's teaching languages and booked times instead of one per candidate
        // (R-47 D-1602: 1,219 statements for 300 candidates before, docs/R47_PERF.md).
        if ($this->languages instanceof BatchLanguageLookup) {
            $this->languages->primeTeachingLanguages($candidates->pluck('user')->filter()->all());
        }
        $this->busy = $this->bookedSessions($candidates->pluck('id')->all(), $now)->groupBy('tutor_profile_id');

        $results = [];
        $excluded = 0;
        foreach ($candidates as $profile) {
            $scored = $this->score($profile, $need, $now, $weights, $reviewStats[$profile->id] ?? ['count' => 0, 'avg' => null]);
            if ($scored === null) {
                $excluded++;

                continue;
            }
            $scored['tie_break'] = hash('sha256', $need->fingerprint().'|'.$profile->id);
            $results[] = $scored;
        }

        usort($results, fn ($a, $b) => [$b['score'], $a['tie_break']] <=> [$a['score'], $b['tie_break']]);
        $results = array_map(function ($r) {
            unset($r['tie_break']);

            return $r;
        }, array_slice($results, 0, (int) config('marketplace.matching.max_results')));

        $notes = [];
        if (! $this->academic->isAvailable() && ($need->levelId !== null || $need->curriculumId !== null)) {
            $notes[] = 'Levels and curricula are not connected yet, so tutors were matched on subject alone for those.';
        }
        if (! $this->languages->isAvailable() && $need->languages !== []) {
            $notes[] = 'Teaching languages are not connected yet, so language could not be checked.';
        }

        return [
            'need' => $need->toArray(),
            'results' => $results,
            'considered' => $candidates->count(),
            'excluded' => $excluded,
            'notes' => $notes,
        ];
    }

    /**
     * @param  array<string, int|float>  $weights
     * @param  array{count: int, avg: ?float}  $reviews
     * @return array<string, mixed>|null null when a hard filter excludes the tutor
     */
    private function score(TutorProfile $profile, LearningNeed $need, CarbonImmutable $now, array $weights, array $reviews): ?array
    {
        $reasons = [];
        $terms = [];

        // Format and place (hard filter).
        $formatNote = $this->formatFit($profile, $need);
        if ($formatNote === false) {
            return null;
        }
        if ($formatNote !== null) {
            $reasons[] = $formatNote;
        }

        // Offerings that could deliver this need.
        $relevant = $profile->offerings
            ->filter(fn (TutorOffering $o) => $o->subjects->contains('id', $need->subjectId))
            ->filter(fn (TutorOffering $o) => $o->teachesLevel($need->levelId))
            ->filter(fn (TutorOffering $o) => $this->offeringFormatOk($o, $need))
            ->values();

        // help_type
        [$helpValue, $helpNote, $offeringsForPrice] = $this->helpTypeFit($profile, $relevant, $need);
        if ($helpValue === null) {
            return null;
        }
        $terms['help_type'] = $this->term('help_type', $weights, $helpValue, $helpNote);
        if ($helpNote !== null && $helpValue > 0) {
            $reasons[] = $helpNote;
        }

        // scope (subject + level range)
        [$scopeValue, $scopeNote] = $this->scopeFit($profile, $need);
        if ($scopeValue === null) {
            return null;
        }
        $terms['scope'] = $this->term('scope', $weights, $scopeValue, $scopeNote);
        $reasons[] = $scopeNote;

        // curriculum
        [$curValue, $curNote] = $this->curriculumFit($profile, $need);
        $terms['curriculum'] = $this->term('curriculum', $weights, $curValue, $curNote);
        if ($curNote !== null) {
            $reasons[] = $curNote;
        }

        // teaching language (never interface locale)
        [$langValue, $langNote] = $this->languageFit($profile, $need);
        if ($langValue === null) {
            return null;
        }
        $terms['language'] = $this->term('language', $weights, $langValue, $langNote);
        if ($langNote !== null) {
            $reasons[] = $langNote;
        }

        // budget
        [$budgetValue, $budgetNote, $priceMinor, $priceCurrency] = $this->budgetFit($profile, $offeringsForPrice, $need);
        if ($budgetValue === null) {
            return null;
        }
        $terms['budget'] = $this->term('budget', $weights, $budgetValue, $budgetNote);
        if ($budgetNote !== null) {
            $reasons[] = $budgetNote;
        }

        // availability, from the real slot finder (or open group seats)
        [$availValue, $availNote, $nextSlots] = $this->availabilityFit($profile, $need, $now);
        $terms['availability'] = $this->term('availability', $weights, $availValue, $availNote);
        $reasons[] = $availNote;

        // verification level
        $level = $profile->verificationLevel();
        $verNote = match (true) {
            $level !== null => $level->label().': '.$level->description(),
            TutorPresenter::hasVerifiedRecord($profile) => TutorPresenter::CHECKED_LABEL.'.',
            default => 'Not yet verified by Dot.Tutor.',
        };
        $terms['verification'] = $this->term('verification', $weights, $level?->matchingWeight() ?? 0.0, $verNote);
        $reasons[] = $verNote;

        // review evidence (minimum count)
        $min = (int) config('marketplace.matching.min_reviews');
        $full = max(1, (int) config('marketplace.matching.full_confidence_reviews'));
        if ($reviews['count'] >= $min && $reviews['avg'] !== null) {
            $reviewValue = (($reviews['avg'] - 1) / 4) * min(1, $reviews['count'] / $full);
            $reviewNote = sprintf('Rated %.1f out of 5 from %d published %s.', $reviews['avg'], $reviews['count'], $reviews['count'] === 1 ? 'review' : 'reviews');
        } else {
            $reviewValue = 0.0;
            $reviewNote = $reviews['count'] === 0
                ? 'No reviews yet.'
                : sprintf('%d published %s so far; reviews count once there are %d.', $reviews['count'], $reviews['count'] === 1 ? 'review' : 'reviews', $min);
        }
        $terms['reviews'] = $this->term('reviews', $weights, $reviewValue, $reviewNote);
        $reasons[] = $reviewNote;

        // capped new-tutor exposure
        $approvedAt = $profile->reviewed_at ? Carbon::parse($profile->reviewed_at) : $profile->created_at;
        $isNew = $reviews['count'] < $min
            && $approvedAt !== null
            && $approvedAt->greaterThanOrEqualTo($now->subDays((int) config('marketplace.matching.new_tutor_days')));
        $newNote = $isNew ? 'New on Dot.Tutor. New tutors get a small, capped lift so they can be found before they have reviews.' : null;
        $terms['new_tutor'] = $this->term('new_tutor', $weights, $isNew ? 1.0 : 0.0, $newNote);
        if ($newNote) {
            $reasons[] = $newNote;
        }

        $score = round(array_sum(array_column($terms, 'points')), 2);

        return [
            'tutor' => [
                'id' => $profile->id,
                'name' => $profile->displayName(),
                'headline' => $profile->headline,
                'verification' => (new TutorPresenter)->verification($profile),
                'has_photo' => $profile->photo_path !== null,
                'currency' => $profile->currency,
            ],
            'score' => $score,
            'terms' => array_values(array_map(fn ($t) => $terms[$t], self::TERMS)),
            'reasons' => array_values(array_filter($reasons)),
            'price' => $priceMinor === null ? null : [
                'per_hour_minor' => $priceMinor,
                'currency' => $priceCurrency,
                'display' => MinorUnits::format($priceMinor, $priceCurrency).' per hour',
            ],
            'offerings' => $relevant->map(fn (TutorOffering $o) => ['id' => $o->id, 'type' => $o->type, 'title' => $o->title])->all(),
            'next_slots' => $nextSlots,
        ];
    }

    /**
     * @param  array<string, int|float>  $weights
     * @return array{term: string, weight: float, value: float, points: float, note: ?string}
     */
    private function term(string $name, array $weights, float $value, ?string $note): array
    {
        $value = max(0.0, min(1.0, $value));
        $weight = (float) ($weights[$name] ?? 0);

        return ['term' => $name, 'weight' => $weight, 'value' => round($value, 4), 'points' => round($value * $weight, 2), 'note' => $note];
    }

    /** @return string|false|null false = excluded; a sentence when worth saying */
    private function formatFit(TutorProfile $profile, LearningNeed $need): string|false|null
    {
        if ($need->format === 'online') {
            return $profile->teaches_online ? 'Teaches online.' : false;
        }
        if ($need->format === 'in_person') {
            if (! $profile->teaches_in_person) {
                return false;
            }
            if ($need->area === null) {
                return 'Teaches in person in '.$profile->in_person_area.'.';
            }

            return $this->areaMatches($profile->in_person_area, $need->area) ? 'Teaches in person in '.$profile->in_person_area.'.' : false;
        }

        return null;
    }

    private function areaMatches(?string $tutorArea, string $wanted): bool
    {
        $a = mb_strtolower(trim((string) $tutorArea));
        $b = mb_strtolower(trim($wanted));

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private function offeringFormatOk(TutorOffering $offering, LearningNeed $need): bool
    {
        $format = $offering->formatEnum();

        return match ($need->format) {
            'online' => $format->allowsOnline(),
            'in_person' => $format->allowsInPerson() && ($need->area === null || $this->areaMatches($offering->area, $need->area)),
            default => true,
        };
    }

    /**
     * @param  Collection<int, TutorOffering>  $relevant
     * @return array{0: ?float, 1: ?string, 2: Collection<int, TutorOffering>}
     */
    private function helpTypeFit(TutorProfile $profile, Collection $relevant, LearningNeed $need): array
    {
        $type = $need->helpType;
        if ($type === null) {
            return [0.0, null, $relevant];
        }

        $exact = $relevant->filter(fn (TutorOffering $o) => $o->type === $type->value)->values();
        if ($exact->isNotEmpty()) {
            return [1.0, 'Offers '.mb_strtolower($type->label()).'.', $exact];
        }

        // Structural requests need the matching offering.
        if (in_array($type, [OfferingType::Group, OfferingType::Package, OfferingType::RecurringWeekly], true)) {
            return [null, null, $relevant];
        }

        // A regular lesson can deliver homework help, exam prep, revision or a consultation.
        $lessons = $relevant->filter(fn (TutorOffering $o) => $o->type === OfferingType::OneToOne->value)->values();
        if ($lessons->isNotEmpty()) {
            return [0.5, 'No '.mb_strtolower($type->label()).' offering, but a one-to-one lesson can cover it.', $lessons];
        }
        if ($profile->offerings->isEmpty()) {
            return [0.5, 'Books one-to-one lessons at an hourly rate.', $relevant];
        }

        return [0.0, null, $relevant];
    }

    /** @return array{0: ?float, 1: string} */
    private function scopeFit(TutorProfile $profile, LearningNeed $need): array
    {
        $scopes = $profile->teachingScopes->where('subject_id', $need->subjectId)->values();

        if ($scopes->isEmpty()) {
            return [0.6, 'Teaches this subject (has not said which levels).'];
        }
        if ($need->levelId === null) {
            return [1.0, 'Teaches this subject.'];
        }
        if (! $this->academic->isAvailable()) {
            return [0.6, 'Teaches this subject; the level could not be checked yet.'];
        }

        $rank = $this->academic->levelRank($need->levelId);
        $curriculum = $this->academic->levelCurriculum($need->levelId);
        $levelName = $this->academic->levelName($need->levelId) ?? 'your level';
        $best = null;
        $bestNote = '';
        $allRanged = true;

        foreach ($scopes as $scope) {
            if ($scope->level_min_id === null && $scope->level_max_id === null) {
                $allRanged = false;
                [$value, $note] = [0.7, 'Teaches this subject at all levels (not narrowed down).'];
            } else {
                $minId = $scope->level_min_id ?? $scope->level_max_id;
                $maxId = $scope->level_max_id ?? $scope->level_min_id;
                $min = $this->academic->levelRank((int) $minId);
                $max = $this->academic->levelRank((int) $maxId);
                $sameCurriculum = $curriculum === null || $this->academic->levelCurriculum((int) $minId) === $curriculum;
                if ($rank === null || $min === null || $max === null || ! $sameCurriculum) {
                    [$value, $note] = [0.4, 'Teaches this subject, in a different level range or curriculum.'];
                } elseif ($rank >= $min && $rank <= $max) {
                    [$value, $note] = [1.0, "Teaches this subject at {$levelName}."];
                } elseif ($rank === $min - 1 || $rank === $max + 1) {
                    [$value, $note] = [0.3, "Teaches this subject one level away from {$levelName}."];
                } else {
                    [$value, $note] = [null, ''];
                }
            }
            if ($value !== null && ($best === null || $value > $best)) {
                [$best, $bestNote] = [$value, $note];
            }
        }

        if ($best === null) {
            return $allRanged ? [null, ''] : [0.7, 'Teaches this subject.'];
        }

        return [$best, $bestNote];
    }

    /** @return array{0: float, 1: ?string} */
    private function curriculumFit(TutorProfile $profile, LearningNeed $need): array
    {
        if ($need->curriculumId === null) {
            return [0.0, null];
        }

        $scopes = $profile->teachingScopes->where('subject_id', $need->subjectId);
        if ($scopes->contains(fn ($s) => $s->curriculum_id === $need->curriculumId)) {
            $name = $this->academic->curriculumName($need->curriculumId);

            return [1.0, $name ? "Follows {$name}, your curriculum." : 'Follows your curriculum.'];
        }
        if ($scopes->isEmpty() || $scopes->contains(fn ($s) => $s->curriculum_id === null)) {
            return [0.5, 'Has not said which curricula they teach.'];
        }

        return [0.0, 'Teaches this subject in a different curriculum.'];
    }

    /** @return array{0: ?float, 1: ?string} */
    private function languageFit(TutorProfile $profile, LearningNeed $need): array
    {
        if ($need->languages === []) {
            return [0.0, null];
        }
        if (! $this->languages->isAvailable() || $profile->user === null) {
            return [0.0, 'Teaching languages are not recorded yet.'];
        }

        $teaches = collect($this->languages->teachingLanguages($profile->user))
            ->keyBy(fn ($l) => strtolower($l['code']));
        if ($teaches->isEmpty()) {
            return [0.0, 'Has not listed the languages they teach in.'];
        }

        $shared = array_values(array_filter($need->languages, fn ($code) => $teaches->has($code)));
        if ($shared === []) {
            return [null, null];
        }

        $names = array_map(fn ($code) => $teaches[$code]['name'] ?? $code, $shared);
        $verified = array_filter($shared, fn ($code) => (bool) ($teaches[$code]['verified'] ?? false));
        $suffix = $verified !== [] ? ' (language verified)' : '';

        if ($need->bilingualOk && count($shared) >= 2) {
            return [1.0, 'Can teach bilingually in '.implode(' and ', array_slice($names, 0, 2)).$suffix.'.'];
        }
        if ($shared[0] === $need->languages[0]) {
            return [1.0, 'Teaches in '.$names[0].$suffix.'.'];
        }

        return [0.7, 'Teaches in '.$names[0].', one of your other languages'.$suffix.'.'];
    }

    /**
     * @param  Collection<int, TutorOffering>  $offerings
     * @return array{0: ?float, 1: ?string, 2: ?int, 3: ?string}
     */
    private function budgetFit(TutorProfile $profile, Collection $offerings, LearningNeed $need): array
    {
        if ($offerings->isNotEmpty()) {
            $cheapest = $offerings->sortBy(fn (TutorOffering $o) => [$o->hourlyEquivalentMinor(), $o->id])->first();
            [$price, $currency] = [$cheapest->hourlyEquivalentMinor(), $cheapest->currency];
        } elseif ($profile->offerings->isEmpty() && $profile->hourly_rate_minor !== null) {
            [$price, $currency] = [(int) $profile->hourly_rate_minor, $profile->currency ?: config('tutor.currency')];
        } else {
            [$price, $currency] = [null, null];
        }

        if ($need->budgetPerHourMinor === null) {
            return [0.0, null, $price, $currency];
        }
        if ($price === null) {
            return [0.0, 'No price published for this kind of help.', null, null];
        }
        $wantCurrency = $need->currency ?: config('tutor.currency');
        if ($currency !== $wantCurrency) {
            return [0.0, "Prices in {$currency}, not {$wantCurrency}.", $price, $currency];
        }

        $budget = $need->budgetPerHourMinor;
        $display = MinorUnits::format($price, $currency).' per hour';
        if ($price <= $budget) {
            return [1.0, "Within your budget ({$display}).", $price, $currency];
        }

        $tolerance = (float) config('marketplace.matching.budget_tolerance');
        $over = $budget > 0 ? ($price - $budget) / $budget : INF;
        if ($over > $tolerance) {
            return [null, null, $price, $currency];
        }

        return [1 - $over / max(0.0001, $tolerance), "A little over your budget ({$display}).", $price, $currency];
    }

    /**
     * @return array{0: float, 1: string, 2: list<string>}
     */
    private function availabilityFit(TutorProfile $profile, LearningNeed $need, CarbonImmutable $now): array
    {
        $full = max(1, (int) config('marketplace.matching.availability_full_slots'));
        $tz = $need->timezone;

        if ($need->helpType === OfferingType::Group) {
            $open = $this->openGroupSessions($profile, $need, $now);
            $count = count($open);

            return [
                min(1, $count / $full),
                $count === 0 ? 'No group lessons scheduled in your window yet.' : sprintf('%d group %s with free seats in your window.', $count, $count === 1 ? 'lesson' : 'lessons'),
                array_slice($open, 0, 3),
            ];
        }

        if ($profile->availabilityWindows->isEmpty()) {
            return [0.0, 'Has not published open hours yet.', []];
        }

        $horizon = (int) config('marketplace.matching.availability_days');
        $lastDay = $need->toDate !== null
            ? CarbonImmutable::parse($need->toDate, $tz)->endOfDay()
            : $now->addDays($horizon);
        $days = (int) max(1, min((int) config('tutor.booking_horizon_days', 14), ceil(($lastDay->getTimestamp() - $now->getTimestamp()) / 86400) + 1));

        // Timestamps and native DateTime here: this loop runs for every open slot of every candidate (thousands
        // per search), and Carbon per slot was most of the page's time (D-1602).
        $zone = new \DateTimeZone($tz);
        $matching = [];
        foreach ($this->slots->startTimestamps($profile, $need->durationMinutes, $now, $days, $this->busy?->get($profile->id, new Collection)) as $day) {
            foreach ($day['starts'] as $ts) {
                if ($this->timestampInWindow($ts, $need, $zone)) {
                    $matching[] = $ts;
                }
            }
        }
        $count = count($matching);
        $matching = array_map(fn (int $ts) => CarbonImmutable::createFromTimestamp($ts, 'UTC')->toIso8601String(), array_slice($matching, 0, 3));

        $note = $count === 0
            ? ($need->hasWindow() ? 'No open times in your window in the next two weeks.' : 'No open times in the next two weeks.')
            : sprintf('%d open %s %s in the next two weeks.', $count, $count === 1 ? 'time' : 'times', $need->hasWindow() ? 'in your window' : '');

        return [min(1, $count / $full), preg_replace('/\s+/', ' ', $note), $matching];
    }

    /** inWindow() for a slot start given as a timestamp, without Carbon (same rules, same absolute-time end). */
    private function timestampInWindow(int $ts, LearningNeed $need, \DateTimeZone $zone): bool
    {
        if (! $need->hasWindow()) {
            return true;
        }
        [$date, $weekday, $time] = explode('|', (new \DateTimeImmutable('@'.$ts))->setTimezone($zone)->format('Y-m-d|N|H:i'));
        if ($need->fromDate !== null && $date < $need->fromDate) {
            return false;
        }
        if ($need->toDate !== null && $date > $need->toDate) {
            return false;
        }
        if ($need->weekdays !== [] && ! in_array((int) $weekday, $need->weekdays, true)) {
            return false;
        }
        if ($need->fromTime !== null && $time < $need->fromTime) {
            return false;
        }
        if ($need->toTime !== null) {
            [$endDate, $endTime] = explode('|', (new \DateTimeImmutable('@'.($ts + $need->durationMinutes * 60)))->setTimezone($zone)->format('Y-m-d|H:i'));
            if ($endTime > $need->toTime && $endDate === $date) {
                return false;
            }
        }

        return true;
    }

    private function inWindow(CarbonImmutable $local, LearningNeed $need): bool
    {
        $date = $local->toDateString();
        if ($need->fromDate !== null && $date < $need->fromDate) {
            return false;
        }
        if ($need->toDate !== null && $date > $need->toDate) {
            return false;
        }
        if ($need->weekdays !== [] && ! in_array($local->isoWeekday(), $need->weekdays, true)) {
            return false;
        }
        $time = $local->format('H:i');
        if ($need->fromTime !== null && $time < $need->fromTime) {
            return false;
        }
        if ($need->toTime !== null && $local->addMinutes($need->durationMinutes)->format('H:i') > $need->toTime && $local->addMinutes($need->durationMinutes)->toDateString() === $date) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    private function openGroupSessions(TutorProfile $profile, LearningNeed $need, CarbonImmutable $now): array
    {
        $sessions = TutorSession::query()
            ->where('tutor_profile_id', $profile->id)
            ->where('kind', TutorSession::KIND_GROUP)
            ->where('subject_id', $need->subjectId)
            ->where('status', SessionStatus::Confirmed->value)
            ->where('starts_at', '>', $now)
            ->withCount(['participants as seats_taken' => fn ($q) => $q->whereIn('status', SeatStatus::holding())])
            ->orderBy('starts_at')
            ->limit(50)
            ->get();

        return $sessions
            ->filter(fn (TutorSession $s) => $s->seats_taken < (int) $s->capacity)
            ->filter(fn (TutorSession $s) => $this->inWindow(CarbonImmutable::createFromTimestamp($s->starts_at->getTimestamp(), $need->timezone), $need))
            ->map(fn (TutorSession $s) => $s->starts_at->copy()->utc()->toIso8601String())
            ->values()->all();
    }

    /**
     * Every candidate's sessions that could hold time over the matching horizon (with the slot finder's margin for
     * the longest lesson before it), in one query; SlotFinder filters them per tutor exactly as its own query does.
     *
     * @param  list<int>  $profileIds
     * @return Collection<int, TutorSession>
     */
    private function bookedSessions(array $profileIds, CarbonImmutable $now): Collection
    {
        if ($profileIds === []) {
            return new Collection;
        }
        $horizon = max((int) config('tutor.booking_horizon_days', 14), (int) config('marketplace.matching.availability_days')) + 2;

        return TutorSession::query()
            ->whereIn('tutor_profile_id', $profileIds)
            ->whereIn('status', SessionStatus::holdingTime())
            ->where('starts_at', '>', $now->subDays(2)->subMinutes(TutorSession::longestDurationMinutes()))
            ->where('starts_at', '<', $now->addDays($horizon))
            ->get(['id', 'tutor_profile_id', 'status', 'starts_at', 'duration_minutes']);
    }

    /**
     * Published review count and average per tutor, in one query.
     *
     * @param  list<int>  $profileIds
     * @return array<int, array{count: int, avg: ?float}>
     */
    private function reviewStats(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        return SessionRating::query()
            ->join('tutor_sessions', 'tutor_sessions.id', '=', 'session_ratings.session_id')
            ->whereIn('tutor_sessions.tutor_profile_id', $profileIds)
            ->where('session_ratings.status', ReviewStatus::Published->value)
            ->groupBy('tutor_sessions.tutor_profile_id')
            ->selectRaw('tutor_sessions.tutor_profile_id as pid, COUNT(*) as n, AVG(session_ratings.rating) as a')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->pid => ['count' => (int) $row->n, 'avg' => $row->a !== null ? round((float) $row->a, 2) : null]])
            ->all();
    }
}
