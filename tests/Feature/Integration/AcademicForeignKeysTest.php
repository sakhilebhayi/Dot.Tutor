<?php

namespace Tests\Feature\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsOfferings;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

/** The academic foreign keys (D-1406): refused on orphans with nothing changed, enforced once added, reversible. */
class AcademicForeignKeysTest extends TestCase
{
    use BuildsOfferings;
    use RefreshDatabase;
    use SeedsAcademicStructure;

    private const PATH = 'database/migrations/2026_11_10_900000_add_academic_foreign_keys.php';

    public function test_orphans_stop_the_migration_and_the_keys_hold_once_added(): void
    {
        $this->seedAcademic();
        $tutor = $this->makeTutor();
        $subjectId = $tutor->subjects()->value('subjects.id');
        $grade10 = $this->level('za-caps', 'grade-10');

        $this->artisan('migrate:rollback', ['--path' => self::PATH])->assertSuccessful();
        DB::table('tutor_teaching_scopes')->insert(['tutor_profile_id' => $tutor->id, 'subject_id' => $subjectId, 'level_min_id' => 999999, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tutor_teaching_scopes')->insert(['tutor_profile_id' => $tutor->id, 'subject_id' => $subjectId, 'curriculum_id' => $grade10->curriculum_id, 'level_min_id' => $grade10->id, 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->artisan('migrate', ['--path' => self::PATH]);
            $this->fail('The migration must refuse orphaned academic ids.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tutor_teaching_scopes.level_min_id -> academic_levels: 999999', $e->getMessage());
        }
        $this->assertSame(2, DB::table('tutor_teaching_scopes')->count(), 'Nothing was rewritten');

        DB::table('tutor_teaching_scopes')->where('level_min_id', 999999)->update(['level_min_id' => null]);
        $this->artisan('migrate', ['--path' => self::PATH])->assertSuccessful();
        $this->assertSame(2, DB::table('tutor_teaching_scopes')->count());

        $this->expectException(QueryException::class);
        DB::table('tutor_teaching_scopes')->insert(['tutor_profile_id' => $tutor->id, 'subject_id' => $subjectId, 'level_max_id' => 999999, 'created_at' => now(), 'updated_at' => now()]);
    }
}
