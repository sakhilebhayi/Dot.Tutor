<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Teach\Models\StudentNote;
use App\Domain\Teach\Services\StudentBook;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/** The tutor's students: who they teach, how often, progress the learner shares through courses and assessments, and private notes. */
class StudentsController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request, StudentBook $book): View
    {
        $profile = TutorContext::profile($request);
        $search = trim((string) $request->query('q', '')) ?: null;
        $filter = $request->query('filter') === 'repeat' ? 'repeat' : null;
        // A page at a time: a tutor with a popular course can have thousands of students, and one table of all of
        // them was 1.3 MB of HTML (R-47 D-1606, docs/R47_PERF.md).
        $page = $book->page($profile, $search, $filter === 'repeat', self::PER_PAGE, LengthAwarePaginator::resolveCurrentPage(),
            ['path' => $request->url(), 'query' => $request->query()]);

        return view('teach.students', [
            'profile' => $profile,
            'tz' => TutorContext::tz($profile),
            'rows' => $page['rows'],
            'total' => $page['total'],
            'repeat' => $page['repeat'],
            'search' => $search,
            'filter' => $filter,
        ]);
    }

    public function show(Request $request, int $learner, StudentBook $book): View
    {
        $profile = TutorContext::profile($request);
        // Only someone this tutor has actually taught or sold to; anyone else is not found.
        abort_unless($book->isStudent($profile, $learner), 404);
        $user = User::query()->findOrFail($learner);

        return view('teach.student', [
            'profile' => $profile,
            'tz' => TutorContext::tz($profile),
            'learner' => $user,
            ...$book->detail($profile, $user),
        ]);
    }

    public function note(Request $request, int $learner, StudentBook $book): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        abort_unless($book->isStudent($profile, $learner), 404);
        $body = trim((string) $request->validate(['body' => ['nullable', 'string', 'max:5000']])['body']);

        if ($body === '') {
            StudentNote::query()->where('tutor_profile_id', $profile->id)->where('learner_id', $learner)->delete();

            return back()->with('success', 'Note cleared.');
        }

        StudentNote::query()->updateOrCreate(['tutor_profile_id' => $profile->id, 'learner_id' => $learner], ['body' => $body]);

        return back()->with('success', 'Note saved. Only you can read it.');
    }
}
