<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Academic\Models\Country;
use App\Domain\Integration\TutorIntegrationServiceProvider;
use App\Domain\Money\Models\Order;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsMoney;
use Tests\Concerns\SeedsAcademicStructure;

/**
 * Shared steps for the brief section-25 acceptance journeys. Everything a person does goes over HTTP; the only
 * direct writes are what has no HTTP surface yet (the operator's off-platform screening record). Every port is
 * the real binding: assertRealBindings() fails if any wave-1 null default is still bound.
 */
trait BuildsJourneys
{
    use BuildsMoney;
    use SeedsAcademicStructure;

    protected User $operator;

    protected function setUpWorld(): void
    {
        $this->seedAcademic();
        $this->setUpPaystack();
        foreach (['local', 'learning', 'safety'] as $disk) {
            Storage::fake($disk);
        }
        $this->operator = $this->makeOperator();
        $this->assertRealBindings();
    }

    protected function assertRealBindings(): void
    {
        foreach (TutorIntegrationServiceProvider::BINDINGS as $port => $adapter) {
            $this->assertInstanceOf($adapter, app($port), "{$port} is bound to its owner's implementation");
        }
    }

    protected function person(string $name): User
    {
        return User::factory()->create(['name' => $name, 'email' => Str::slug($name).'-'.Str::random(5).'@example.test']);
    }

    /** A learner profile through /learn/profile: country, curriculum level, subjects, languages, birth month. */
    protected function learnerProfile(User $learner, int $age, string $curriculum = 'za-caps', string $level = 'grade-11', array $languages = ['zu', 'en']): void
    {
        $born = now()->subYears($age)->subMonths(2);
        $this->actingAs($learner)->put(route('academic.profile.update'), [
            'country_id' => Country::query()->where('code', 'ZA')->value('id'),
            'academic_level_id' => $this->level($curriculum, $level)->id,
            'subjects' => [$this->subject('mathematics')->id],
            'birth_year' => $born->year,
            'birth_month' => $born->month,
            'teaching' => $languages,
            'understands' => ['en'],
            'learning' => [],
            'bilingual_ok' => '1',
        ])->assertSessionHasNoErrors();
    }

    /** A tutor applies (legacy profile form), an operator approves, and the tutor sets up profile v2 over the API. */
    protected function tutorReadyToTeach(string $name, int $priceMinor = 25000): array
    {
        $user = $this->person($name);
        $maths = $this->subject('mathematics');

        $this->actingAs($user)->post(route('tutor.profile.store'), [
            'headline' => 'CAPS Mathematics, Grades 10 to 12', 'bio' => 'I teach FET Mathematics in isiZulu and English.',
            'qualifications' => 'BSc Mathematics', 'hourly_rate' => '250.00', 'timezone' => 'Africa/Johannesburg', 'subjects' => [$maths->id],
        ])->assertSessionHasNoErrors();
        $profile = TutorProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->actingAs($this->operator)->post(route('operator.tutor-profiles.approve', $profile))->assertSessionHasNoErrors();
        $this->assertSame('approved', $profile->fresh()->status);

        $this->actingAs($user)->patchJson('/api/tutor/me/profile', [
            'introduction' => 'Patient, exam-focused lessons.', 'teaches_online' => true, 'hourly_rate_minor' => $priceMinor,
        ])->assertOk();

        // Teaching scope: CAPS Grades 10 to 12, validated against the academic structure.
        $this->actingAs($user)->putJson('/api/tutor/me/scopes', ['scopes' => [[
            'subject_id' => $maths->id, 'curriculum_id' => $this->level('za-caps', 'grade-10')->curriculum_id,
            'level_min_id' => $this->level('za-caps', 'grade-10')->id, 'level_max_id' => $this->level('za-caps', 'grade-12')->id,
        ]]])->assertOk();

        // Teaching languages (academic language profile), self-declared.
        $this->actingAs($user)->putJson('/api/tutor/me/teaching-languages', ['languages' => [
            ['code' => 'zu', 'proficiency' => 'native'], ['code' => 'en', 'proficiency' => 'fluent'],
        ]])->assertOk()->assertJsonPath('data.0.label', 'Self-declared');

        // Availability: every day 08:00 to 20:00 in the tutor's timezone.
        foreach (range(1, 7) as $weekday) {
            $this->actingAs($user)->post(route('tutor.availability.windows.store'), ['weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '20:00'])
                ->assertSessionHasNoErrors();
        }

        // The commission preview before publishing a price, then the offering.
        $this->actingAs($user)->postJson('/api/tutor/money/commission/preview', ['price_minor' => $priceMinor, 'category' => 'lesson'])
            ->assertOk()->assertJsonPath('net.amount_minor', $priceMinor - intdiv($priceMinor * 15, 100));
        $offeringId = $this->actingAs($user)->postJson('/api/tutor/me/offerings', [
            'type' => 'one_to_one', 'title' => 'Grade 11 Maths lesson', 'price_minor' => $priceMinor, 'currency' => 'ZAR',
            'duration_minutes' => 60, 'format' => 'online', 'subject_ids' => [$maths->id],
            'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50,
        ])->assertCreated()->json('data.id');

        return [$user, $profile->fresh(), $offeringId];
    }

    protected function requestLesson(User $learner, int $offeringId, TutorProfile $profile, int $daysAhead = 3): TestResponse
    {
        return $this->actingAs($learner)->withHeader('Idempotency-Key', 'book-'.$learner->id.'-'.$daysAhead)
            ->postJson("/api/tutor/offerings/{$offeringId}/bookings", [
                'subject_id' => $this->subject('mathematics')->id,
                'starts_at' => $this->slotAt($profile, $daysAhead)->format('Y-m-d\TH:i:s\Z'),
            ]);
    }

    /** Paystack (faked HTTP) sends a signed charge.success for the latest checkout; money verifies it server-side. */
    protected function paystackConfirmsLatestPayment(): Order
    {
        $reference = $this->paystack->lastReference();
        $this->assertNotNull($reference, 'A Paystack checkout was started');
        $this->signedWebhook(['event' => 'charge.success', 'data' => [
            'id' => 777, 'reference' => $reference, 'status' => 'success',
            'amount' => $this->paystack->transactions[$reference]['amount'], 'currency' => 'ZAR',
        ]])->assertOk();

        return Order::query()->whereHas('payments', fn ($q) => $q->where('reference', $reference))->firstOrFail();
    }
}
