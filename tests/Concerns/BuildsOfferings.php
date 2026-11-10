<?php

namespace Tests\Concerns;

use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Contracts\CheckoutPort;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Contracts\LearnerAgePort;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Support\CheckoutResult;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;

/**
 * Marketplace v2 builders and seam fakes for tests. Fakes stand in for the
 * academic, safety and money domains, which are not on this branch.
 */
trait BuildsOfferings
{
    use BuildsMarketplace;

    /** @param  array<string, mixed>  $attributes */
    protected function makeOffering(TutorProfile $profile, Subject $subject, array $attributes = []): TutorOffering
    {
        $offering = TutorOffering::create(array_merge([
            'tutor_profile_id' => $profile->id,
            'type' => 'one_to_one',
            'title' => 'One-to-one lesson',
            'price_minor' => 30000,
            'currency' => 'ZAR',
            'duration_minutes' => 60,
            'format' => 'online',
            'capacity' => 1,
            'cancellation_notice_hours' => 24,
            'late_cancellation_refund_percent' => 50,
            'is_active' => true,
        ], $attributes));
        $offering->subjects()->attach($subject->id);

        return $offering;
    }

    /** A tutor with open hours every day 08:00-20:00 UTC and one offering. */
    protected function bookableTutor(array $offering = [], array $profile = [], ?Subject $subject = null): array
    {
        $subject ??= $this->makeSubject();
        $tutor = $this->makeTutor($profile, $subject);
        $this->openWeek($tutor);

        return [$tutor, $subject, $this->makeOffering($tutor, $subject, $offering)];
    }

    protected function fakeMinors(User ...$minors): void
    {
        // Wave-1 marketplace tests run against the marketplace's own null guardian port; the integration provider
        // binds the safety adapter over it (D-1400), whose own tests live in tests/Feature/Integration.
        $this->app->bind(\App\Domain\Marketplace\Contracts\GuardianApprovalsPort::class, \App\Domain\Marketplace\Seams\NullGuardianApprovals::class);
        $ids = array_map(fn (User $u) => $u->id, $minors);
        $this->app->instance(LearnerAgePort::class, new class($ids) implements LearnerAgePort
        {
            public function __construct(private array $ids) {}

            public function isMinor(User $learner): ?bool
            {
                return in_array($learner->id, $this->ids, true);
            }
        });
    }

    /** A checkout that verifies payment immediately (as a webhook would), returning 'paid'. */
    protected function fakePayingCheckout(): object
    {
        $fake = new class implements CheckoutPort
        {
            public array $calls = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function start(User $payer, array $purchasable, string $idempotencyKey): CheckoutResult
            {
                $this->calls[] = [$purchasable, $idempotencyKey];

                return new CheckoutResult('paid', 'Paid.', 'ORD-'.$purchasable['type'].'-'.$purchasable['id']);
            }
        };
        $this->app->instance(CheckoutPort::class, $fake);

        return $fake;
    }

    /** @param  array<int, list<array{code: string, name: string, level: ?string, verified: bool}>>  $byUserId */
    protected function fakeLanguages(array $byUserId): void
    {
        $this->app->instance(LanguageProfilePort::class, new class($byUserId) implements LanguageProfilePort
        {
            public function __construct(private array $byUserId) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function teachingLanguages(User $tutor): array
            {
                return $this->byUserId[$tutor->id] ?? [];
            }

            public function languageName(string $code): ?string
            {
                return $code;
            }
        });
    }

    /**
     * One curriculum (id 1) with levels 1..12 ranked by id (Grade n = id n), a second curriculum (id 2) with level 101.
     */
    protected function fakeAcademic(): void
    {
        $this->app->instance(AcademicScopePort::class, new class implements AcademicScopePort
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function levelRank(int $levelId): ?int
            {
                return ($levelId >= 1 && $levelId <= 12) || $levelId === 101 ? $levelId : null;
            }

            public function levelCurriculum(int $levelId): ?int
            {
                return $levelId === 101 ? 2 : (($levelId >= 1 && $levelId <= 12) ? 1 : null);
            }

            public function levelName(int $levelId): ?string
            {
                return $levelId <= 12 ? "Grade {$levelId}" : 'Year 1';
            }

            public function curriculumName(int $curriculumId): ?string
            {
                return [1 => 'CAPS', 2 => 'Cambridge'][$curriculumId] ?? null;
            }

            public function validateTeachingScope(int $subjectId, ?int $curriculumId, ?int $levelMinId, ?int $levelMaxId): ?string
            {
                if ($levelMinId !== null && $levelMaxId !== null && $levelMinId > $levelMaxId) {
                    return 'The lowest level must come before the highest.';
                }

                return null;
            }
        });
    }
}
