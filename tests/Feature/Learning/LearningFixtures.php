<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Services\CoursePublisher;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/** Builders shared by the learning feature tests. */
trait LearningFixtures
{
    protected function approvedTutor(string $name = 'Thandi Tutor'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        TutorProfile::create(['user_id' => $user->id, 'hourly_rate' => 200, 'status' => 'approved']);

        return $user;
    }

    protected function learner(string $name = 'Sipho Learner'): User
    {
        return User::factory()->withPersonalTeam()->create(['name' => $name]);
    }

    protected function operator(): User
    {
        return User::factory()->withPersonalTeam()->create(['name' => 'Olwethu Operator', 'is_platform_operator' => true]);
    }

    protected function subject(string $name = 'Mathematics'): Subject
    {
        return Subject::query()->firstOrCreate(['name' => $name], ['level' => 'high_school']);
    }

    /**
     * A course with one section: a free-preview reading lesson and a second reading lesson.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function draftCourse(User $tutor, array $overrides = []): Course
    {
        $course = Course::create(array_merge([
            'owner_id' => $tutor->id,
            'slug' => 'algebra-'.uniqid(),
            'title' => 'Grade 10 Algebra foundations',
            'description' => 'Factorising, linear equations and inequalities, step by step with worked examples.',
            'subject_id' => $this->subject()->id,
            'original_locale' => 'en',
            'objectives' => ['Factorise trinomials', 'Solve linear equations'],
            'pricing' => 'free',
            'currency' => 'ZAR',
            'status' => CourseStatus::Draft,
        ], $overrides));
        $section = $course->sections()->create(['title' => 'Getting started', 'position' => 1]);
        $section->lessons()->create(['course_id' => $course->id, 'title' => 'Welcome', 'type' => 'text', 'body' => 'Welcome to **algebra**.', 'is_preview' => true, 'position' => 1]);
        $section->lessons()->create(['course_id' => $course->id, 'title' => 'Factorising', 'type' => 'text', 'body' => 'Secret paid content: x^2 + 5x + 6 = (x + 2)(x + 3).', 'position' => 2]);

        return $course;
    }

    /** @param  array<string, mixed>  $overrides */
    protected function publishedCourse(User $tutor, array $overrides = []): Course
    {
        $course = $this->draftCourse($tutor, $overrides);
        app(CoursePublisher::class)->publish($course, $tutor);

        return $course->fresh();
    }

    protected function lessonIds(Course $course): array
    {
        return $course->lessons()->orderBy('position')->pluck('id')->all();
    }

    protected function pdfUpload(string $name = 'notes.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    protected function mp4Upload(string $name = 'lesson.mp4', int $bytes = 8192): UploadedFile
    {
        $header = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41";

        return UploadedFile::fake()->createWithContent($name, $header.str_repeat("\x00", $bytes - strlen($header)));
    }

    protected function docxUpload(bool $withMacros = false, string $name = 'worksheet.docx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $withMacros ? '<Types><Override ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>' : '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        if ($withMacros) {
            $zip->addFromString('word/vbaProject.bin', 'macro');
        }
        $zip->close();

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }
}
