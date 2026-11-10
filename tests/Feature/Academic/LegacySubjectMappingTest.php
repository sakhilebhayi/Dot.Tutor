<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The legacy mapping on a POPULATED database: the pre-R-47 schema is migrated,
 * filled with legacy subjects, tutors and sessions, then the R-47 migrations
 * and `academic:sync` run on top. No row may be lost and no id may change.
 * (No RefreshDatabase: this test drives the migrations itself.)
 */
class LegacySubjectMappingTest extends TestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $legacyMigrations = collect(File::files(database_path('migrations')))
            ->map(fn ($file) => $file->getPathname())
            ->filter(fn (string $path) => basename($path) < '2026_11_10')
            ->sort()->values()->all();

        Artisan::call('migrate:fresh', ['--path' => $legacyMigrations, '--realpath' => true]);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('subjects', 'slug'), 'Precondition: only the pre-R-47 schema exists.');

        $now = now();
        $subject = fn (string $name, string $level, bool $active = true) => DB::table('subjects')->insertGetId([
            'name' => $name, 'level' => $level, 'is_active' => $active, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Inserted in this order on purpose: the high-school Mathematics row is the OLDEST, so it is adopted.
        $this->ids = [
            'maths_high' => $subject('Mathematics', 'high_school'),
            'maths_primary' => $subject('Mathematics', 'primary'),
            'physics_lower' => $subject('physical sciences', 'high_school'),
            'calculus' => $subject('Calculus', 'university'),
            'robotics' => $subject('Robotics', 'high_school'),
            'robotics_primary' => $subject('ROBOTICS', 'primary', false),
            'odd_level' => $subject('Chess', 'grade_nine_ish'),
        ];

        $student = User::factory()->create();
        $tutorUser = User::factory()->create();
        $profileId = DB::table('tutor_profiles')->insertGetId([
            'user_id' => $tutorUser->id, 'hourly_rate' => 300, 'status' => 'approved', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($this->ids as $id) {
            DB::table('tutor_subject')->insert(['tutor_profile_id' => $profileId, 'subject_id' => $id]);
            DB::table('tutor_sessions')->insert([
                'tutor_profile_id' => $profileId, 'student_id' => $student->id, 'subject_id' => $id, 'status' => 'completed',
                'starts_at' => $now->copy()->subDays(3), 'duration_minutes' => 60, 'rate' => 300, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Artisan::call('migrate');
        Artisan::call('academic:sync');
    }

    protected function tearDown(): void
    {
        // This test leaves its own rows behind (no RefreshDatabase). On SQLite in memory the next test gets a new
        // database anyway; on PostgreSQL the next RefreshDatabase test must rebuild the schema (R-47 D-1407).
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_no_subject_row_is_lost_and_every_id_and_reference_stays_valid(): void
    {
        foreach ($this->ids as $key => $id) {
            $this->assertTrue(DB::table('subjects')->where('id', $id)->exists(), "{$key} must keep id {$id}");
        }
        $this->assertSame(count($this->ids), DB::table('tutor_subject')->count());
        $this->assertSame(count($this->ids), DB::table('tutor_sessions')->count());
        $this->assertSame(0, DB::table('tutor_sessions')->whereNotIn('subject_id', DB::table('subjects')->select('id'))->count());

        // Names, legacy levels and the active flag are untouched.
        $this->assertSame('physical sciences', DB::table('subjects')->where('id', $this->ids['physics_lower'])->value('name'));
        $this->assertSame('primary', DB::table('subjects')->where('id', $this->ids['maths_primary'])->value('level'));
        $this->assertSame(0, (int) DB::table('subjects')->where('id', $this->ids['robotics_primary'])->value('is_active'));
    }

    public function test_legacy_rows_are_adopted_or_linked_to_the_catalogue(): void
    {
        $mathsHigh = Subject::query()->findOrFail($this->ids['maths_high']);
        $this->assertSame('mathematics', $mathsHigh->slug, 'The oldest legacy row with a catalogue name is adopted');
        $this->assertNull($mathsHigh->canonical_subject_id);
        $this->assertContains('wiskunde', $mathsHigh->aliases);

        $this->assertSame($mathsHigh->id, Subject::query()->findOrFail($this->ids['maths_primary'])->canonical_subject_id);
        $this->assertSame('physical-sciences', Subject::query()->findOrFail($this->ids['physics_lower'])->slug, 'Name match is case-insensitive');
        $this->assertSame('calculus', Subject::query()->findOrFail($this->ids['calculus'])->slug);

        // A legacy subject with no catalogue match becomes a catalogue subject; its duplicate links to it.
        $robotics = Subject::query()->findOrFail($this->ids['robotics']);
        $this->assertSame('robotics', $robotics->slug);
        $this->assertSame($robotics->id, Subject::query()->findOrFail($this->ids['robotics_primary'])->canonical_subject_id);

        // Exactly one catalogue Mathematics row exists.
        $this->assertSame(1, Subject::query()->canonical()->whereRaw('lower(name) = ?', ['mathematics'])->count());
    }

    public function test_bookings_on_a_legacy_duplicate_resolve_to_the_catalogue_subject_and_its_levels(): void
    {
        $scope = app(AcademicScope::class);

        $this->assertSame($this->ids['maths_high'], $scope->canonicalSubjectId($this->ids['maths_primary']));

        $primaryLevels = AcademicLevel::query()->whereIn('id', $scope->levelIdsForLegacySubject($this->ids['maths_primary']))->with('curriculum')->get();
        $caps = $primaryLevels->where('curriculum.code', 'za-caps')->pluck('code')->all();
        $this->assertSame(array_map(fn ($n) => "grade-{$n}", range(1, 7)), $caps);

        $university = AcademicLevel::query()->whereIn('id', $scope->levelIdsForLegacySubject($this->ids['calculus']))->pluck('code')->all();
        $this->assertContains('undergraduate-1', $university);
        $this->assertNotContains('grade-12', $university);

        // A legacy primary-school Mathematics booking counts as offered at CAPS Grade 4 via its catalogue row.
        $grade4 = AcademicLevel::query()->where('code', 'grade-4')->whereHas('curriculum', fn ($q) => $q->where('code', 'za-caps'))->firstOrFail();
        $this->assertTrue($scope->isOffered($this->ids['maths_primary'], $grade4->id));

        $this->assertSame([], $scope->levelIdsForLegacySubject($this->ids['odd_level']));
    }

    public function test_unmapped_legacy_level_strings_are_reported_not_rewritten(): void
    {
        $this->artisan('academic:sync')
            ->expectsOutputToContain('grade_nine_ish')
            ->assertSuccessful();

        $this->assertSame('grade_nine_ish', DB::table('subjects')->where('id', $this->ids['odd_level'])->value('level'));
    }

    public function test_the_legacy_operator_screen_and_booking_browse_still_work_on_the_mapped_catalogue(): void
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        $this->actingAs($operator)->get('/operator/subjects')->assertOk()->assertSee('Robotics');
        $this->actingAs($operator)->get('/tutors?subject='.$this->ids['maths_primary'])->assertOk();
    }
}
