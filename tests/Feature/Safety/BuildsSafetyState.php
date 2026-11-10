<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Contracts\BirthDateSource;
use App\Domain\Safety\Enums\ScreeningKind;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Domain\Safety\Services\Guardians;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsMarketplace;

/**
 * Explicit builders for safeguarding state. Every test builds exactly the people it asserts on.
 */
trait BuildsSafetyState
{
    use BuildsMarketplace;

    /** A person whose date of birth makes them $years old today (recorded as operator-verified). */
    protected function personAged(int $years, string $name = 'Person'): User
    {
        $user = $this->makeUser($name);
        $operator = User::factory()->create(['is_platform_operator' => true]);
        $operator->forceFill(['is_platform_operator' => true])->save();

        AgeRecord::create([
            'user_id' => $user->id,
            'birth_date' => now()->subYears($years)->subMonths(2)->toDateString(),
            'source' => 'operator_verified',
            'recorded_by_id' => $operator->id,
        ]);

        return $user;
    }

    protected function child(string $name = 'Child'): User
    {
        return $this->personAged(9, $name);
    }

    protected function teen(string $name = 'Teen'): User
    {
        return $this->personAged(15, $name);
    }

    protected function adult(string $name = 'Adult'): User
    {
        return $this->personAged(30, $name);
    }

    /** Link a guardian through the real invitation and consent flow. */
    protected function linkGuardian(User $learner, ?User $guardian = null, bool $recording = false, ?int $learnerAge = null): GuardianLink
    {
        $guardian ??= $this->adult('Guardian');
        Notification::fake();

        $guardians = app(Guardians::class);
        $result = $guardians->invite($learner, $guardian->email, 'parent');
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        $birthDate = $learnerAge !== null
            ? CarbonImmutable::now()->subYears($learnerAge)->subMonths(2)
            : (app(\App\Domain\Safety\Services\AgeResolver::class)->birthDate($learner) ?? CarbonImmutable::now()->subYears(10));

        return $guardians->accept($result['invitation'], $query['token'], $guardian, $birthDate, $recording);
    }

    /** Record and review every required screening kind (two different operators, evidence attached). */
    protected function screen(User $tutor): void
    {
        $recorder = User::factory()->create();
        $recorder->forceFill(['is_platform_operator' => true])->save();
        $reviewer = User::factory()->create();
        $reviewer->forceFill(['is_platform_operator' => true])->save();

        foreach (config('safety.screening.required_kinds') as $kind) {
            ScreeningCheck::create([
                'user_id' => $tutor->id,
                'kind' => ScreeningKind::from($kind),
                'status' => ScreeningCheck::CLEARED,
                'checked_on' => now()->subDay()->toDateString(),
                'evidence_path' => 'screening/'.$tutor->id.'/'.$kind.'.pdf',
                'recorded_by_id' => $recorder->id,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
        }
    }

    protected function operator(string $name = 'Safeguarding'): User
    {
        $operator = $this->makeUser($name);
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    /** @param array<int, string> $dates user id => Y-m-d */
    protected function bindBirthDates(array $dates): void
    {
        $this->app->instance(BirthDateSource::class, new class($dates) implements BirthDateSource
        {
            public function __construct(private array $dates) {}

            public function birthDateFor(User $user): ?CarbonImmutable
            {
                return isset($this->dates[$user->id]) ? CarbonImmutable::parse($this->dates[$user->id]) : null;
            }
        });
    }
}
