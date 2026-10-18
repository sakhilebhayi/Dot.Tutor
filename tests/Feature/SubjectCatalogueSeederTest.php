<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Database\Seeders\SubjectCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectCatalogueSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_starter_catalogue_is_seeded_idempotently_and_never_overwrites_operator_edits(): void
    {
        $this->seed(SubjectCatalogueSeeder::class);
        $count = Subject::count();
        $this->assertGreaterThan(5, $count);

        Subject::where('name', 'Mathematics')->update(['is_active' => false]);
        $this->seed(SubjectCatalogueSeeder::class);

        $this->assertSame($count, Subject::count());
        $this->assertFalse((bool) Subject::where('name', 'Mathematics')->firstOrFail()->is_active);
    }

    public function test_the_seeder_creates_no_tutors_or_sessions(): void
    {
        $this->seed(SubjectCatalogueSeeder::class);

        $this->assertSame(0, TutorProfile::count());
        $this->assertSame(0, TutorSession::count());
    }
}
