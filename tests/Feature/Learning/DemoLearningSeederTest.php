<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\Course;
use Database\Seeders\DemoLearningSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoLearningSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_published_demo_course_locally_and_is_idempotent(): void
    {
        $this->seed(DemoLearningSeeder::class);
        $this->seed(DemoLearningSeeder::class);

        $course = Course::query()->where('slug', 'demo-algebra-foundations')->firstOrFail();
        $this->assertSame('published', $course->status->value);
        $this->assertSame(1, $course->versions()->count());
        $this->assertStringStartsWith('Demo', $course->title);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->seed(DemoLearningSeeder::class);
    }
}
