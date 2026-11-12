<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Services\ProgressService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The learner's own courses (enrolments with progress and resume) and certificates (D-1111). Read-only. */
class CoursesController extends Controller
{
    public function __invoke(Request $request, ProgressService $progress): View
    {
        $user = $request->user();
        $enrolments = Enrolment::query()->with(['course.currentVersion', 'version', 'certificate'])
            ->where('user_id', $user->id)->whereIn('status', ['active', 'completed'])
            ->orderByRaw('completed_at is not null')->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(100)->get()
            ->filter(fn (Enrolment $e) => $e->course !== null)
            ->map(fn (Enrolment $e) => [
                'enrolment' => $e,
                'title' => $e->version?->snapshot['course']['title'] ?? $e->course->title,
                'summary' => $progress->summary($e),
                'newer' => $e->course_version_id !== $e->course->current_version_id,
            ])->values();

        return view('student.courses', [
            'inProgress' => $enrolments->filter(fn ($r) => $r['summary']['completed_at'] === null)->values(),
            'finished' => $enrolments->filter(fn ($r) => $r['summary']['completed_at'] !== null)->values(),
            'certificates' => Certificate::query()->where('user_id', $user->id)->orderByDesc('issued_at')->get(),
        ]);
    }
}
