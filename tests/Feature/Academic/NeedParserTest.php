<?php

namespace Tests\Feature\Academic;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Services\NeedParser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

class NeedParserTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAcademicStructure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>, 3: list<string>}> */
    public static function needs(): array
    {
        return [
            'the brief example' => ['I need help with Grade 11 Mathematics in isiZulu', ['grade-11'], ['mathematics'], ['zu:stated']],
            'abbreviations, no space, nge- form' => ['gr10 phys sci ngesiXhosa', ['grade-10'], ['physical-sciences'], ['xh:stated']],
            'Afrikaans words' => ['Graad 12 Wiskunde', ['grade-12'], ['mathematics'], []],
            'matric and maths lit' => ['matric maths lit', ['grade-12'], ['mathematical-literacy'], []],
            'number words' => ['grade eleven accounting', ['grade-11'], ['accounting'], []],
            'tertiary' => ['first year calculus', ['undergraduate-1'], ['calculus'], []],
            'doctoral' => ['PhD research methods', ['doctoral'], ['research-methods'], []],
            'Cambridge, Portuguese' => ['IGCSE chemistry in Portuguese', ['igcse'], ['chemistry'], ['pt:stated']],
            'French subject name' => ['mathématiques A level en français', ['a-level'], ['mathematics'], ['fr:possible']],
            'Swahili subject name' => ['hisabati grade 5 kwa Kiswahili', ['grade-5'], ['mathematics'], ['sw:stated']],
            'sign language' => ['grade 8 natural sciences in sign language', ['grade-8'], ['natural-sciences'], ['sfs:stated']],
        ];
    }

    /**
     * @param  list<string>  $levels
     * @param  list<string>  $subjects
     * @param  list<string>  $languages
     */
    #[DataProvider('needs')]
    public function test_it_recognises_levels_subjects_and_teaching_languages(string $text, array $levels, array $subjects, array $languages): void
    {
        $result = app(NeedParser::class)->parse($text);

        $levelCodes = array_values(array_unique(array_map(fn ($l) => AcademicLevel::find($l['id'])->code, $result['levels'])));
        $this->assertSame($levels, $levelCodes, 'levels');
        $this->assertSame($subjects, array_map(fn ($s) => Subject::find($s['id'])->slug, $result['subjects']), 'subjects');
        $this->assertSame($languages, array_map(fn ($l) => $l['code'].':'.$l['certainty'], $result['teaching_languages']), 'languages');
        $this->assertNotEmpty($result['offerings']);
    }

    public function test_a_language_named_without_a_marker_is_offered_as_subject_and_as_possible_teaching_language(): void
    {
        $result = app(NeedParser::class)->parse('help with Afrikaans grade 9');

        $slugs = array_map(fn ($s) => Subject::find($s['id'])->slug, $result['subjects']);
        $this->assertContains('af-home-language', $slugs);
        $this->assertContains('af-first-additional-language', $slugs);
        $this->assertSame([['code' => 'af', 'certainty' => 'possible']], array_map(fn ($l) => ['code' => $l['code'], 'certainty' => $l['certainty']], $result['teaching_languages']));
        $this->assertSame([], array_filter($result['notes'], fn ($n) => $n['key'] === 'not_offered'), 'Afrikaans HL/FAL are offered, so no not-offered note');
    }

    public function test_a_subject_not_offered_at_the_level_is_reported_honestly(): void
    {
        $result = app(NeedParser::class)->parse('Grade 3 Accounting');

        $this->assertSame([], $result['offerings']);
        $this->assertSame([['key' => 'not_offered', 'subject' => 'Accounting', 'level' => 'Grade 3']], $result['notes']);
    }

    public function test_ordinary_words_do_not_become_levels_or_subjects(): void
    {
        $result = app(NeedParser::class)->parse('I want a professional tutor who can master it and help with art of patience');

        $this->assertSame([], $result['levels']);
        $this->assertSame(['visual-arts'], array_map(fn ($s) => Subject::find($s['id'])->slug, $result['subjects']));
        $this->assertContains(['key' => 'no_level'], $result['notes']);
    }

    public function test_the_learners_curriculum_is_preferred(): void
    {
        $ieb = Curriculum::query()->where('code', 'za-ieb')->value('id');
        $result = app(NeedParser::class)->parse('grade 10 maths', $ieb);

        $this->assertSame('za-ieb', $result['levels'][0]['curriculum_code']);
        $this->assertTrue($result['levels'][0]['preferred']);
        $this->assertFalse($result['levels'][1]['preferred']);
    }

    public function test_the_json_endpoint_requires_sign_in_and_validates(): void
    {
        $this->postJson(route('academic.describe'), ['text' => 'grade 10 maths'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated'); // one JSON error policy (D-1404)

        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('academic.describe'), ['text' => ''])->assertUnprocessable()->assertJsonPath('error', 'validation_failed');
        $this->actingAs($user)->postJson(route('academic.describe'), ['text' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

        $this->actingAs($user)->postJson(route('academic.describe'), ['text' => 'Grade 11 Mathematics in isiZulu'])
            ->assertOk()
            ->assertJsonPath('subjects.0.name', 'Mathematics')
            ->assertJsonPath('teaching_languages.0.code', 'zu')
            ->assertJsonCount(2, 'levels');
    }

    public function test_the_endpoint_is_throttled(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->postJson(route('academic.describe'), ['text' => 'maths'])->assertOk();
        }
        $this->actingAs($user)->postJson(route('academic.describe'), ['text' => 'maths'])->assertStatus(429);
    }
}
