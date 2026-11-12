<?php

namespace Tests\Concerns;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Subject;
use App\Models\User;
use Database\Seeders\AcademicReferenceSeeder;

/** The academic reference data (the same code `academic:sync` runs), plus small lookups by code. */
trait SeedsAcademicStructure
{
    protected function seedAcademic(): void
    {
        $this->seed(AcademicReferenceSeeder::class);
    }

    protected function level(string $curriculum, string $code): AcademicLevel
    {
        return AcademicLevel::query()->whereHas('curriculum', fn ($q) => $q->where('code', $curriculum))->where('code', $code)->firstOrFail();
    }

    protected function subject(string $slug): Subject
    {
        return Subject::query()->where('slug', $slug)->firstOrFail();
    }

    protected function academicOperator(): User
    {
        $user = User::factory()->create(['name' => 'Academic Operator']);
        $user->forceFill(['is_platform_operator' => true])->save();

        return $user;
    }
}
