<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Operator-only subject catalogue. Subjects are never deleted: deactivating
 * one hides it from new applications and bookings while every session that
 * used it keeps its subject.
 */
class SubjectController extends Controller
{
    public function index(): View
    {
        return view('operator.subjects.index', [
            'subjects' => Subject::query()->withCount('tutors')->orderByDesc('is_active')->orderBy('name')->get(),
            'levels' => Subject::LEVELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertUnique($data);

        Subject::create($data + ['is_active' => true]);

        return redirect()->route('operator.subjects.index')->with('status', 'Subject added.');
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertUnique($data, $subject);

        $subject->update($data);

        return redirect()->route('operator.subjects.index')->with('status', 'Subject updated.');
    }

    public function activate(Subject $subject): RedirectResponse
    {
        $subject->update(['is_active' => true]);

        return redirect()->route('operator.subjects.index')->with('status', "{$subject->name} is offered again.");
    }

    public function deactivate(Subject $subject): RedirectResponse
    {
        $subject->update(['is_active' => false]);

        return redirect()->route('operator.subjects.index')
            ->with('status', "{$subject->name} is hidden from new bookings. Past sessions keep it.");
    }

    /** @return array{name: string, level: string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'level' => ['required', Rule::in(array_keys(Subject::LEVELS))],
        ]);
        $data['name'] = trim($data['name']);

        return $data;
    }

    /** @param  array{name: string, level: string}  $data */
    private function assertUnique(array $data, ?Subject $ignore = null): void
    {
        $exists = Subject::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])
            ->where('level', $data['level'])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'That subject already exists at this level.']);
        }
    }
}
