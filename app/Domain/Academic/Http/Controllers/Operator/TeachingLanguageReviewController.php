<?php

namespace App\Domain\Academic\Http\Controllers\Operator;

use App\Domain\Academic\Contracts\AcademicAudit;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\UserLanguage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A tutor's teaching languages are self-declared. An operator who has checked
 * one (an interview, a recorded lesson, a qualification) marks it verified;
 * the marketplace shows "verified" only for these.
 */
class TeachingLanguageReviewController extends Controller
{
    public function __construct(private readonly AcademicAudit $audit) {}

    public function index(Request $request): View
    {
        return view('academic::operator.teaching-languages', [
            'rows' => UserLanguage::query()
                ->with(['user', 'language'])
                ->where('role', LanguageRole::CanTeachIn->value)
                ->when($request->input('state') === 'unverified', fn ($q) => $q->whereNull('verified_at'))
                ->when($request->input('state') === 'verified', fn ($q) => $q->whereNotNull('verified_at'))
                ->orderByRaw('verified_at is not null')
                ->orderBy('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function verify(Request $request, UserLanguage $userLanguage): RedirectResponse
    {
        abort_unless($userLanguage->role === LanguageRole::CanTeachIn, 404);
        abort_if($userLanguage->user_id === $request->user()->id, 403, 'Operators cannot verify their own teaching languages.');

        $userLanguage->forceFill(['verified_at' => now(), 'verified_by_id' => $request->user()->id])->save();
        $this->audit->record($request->user(), 'academic.teaching_language.verified', $userLanguage, [
            'user_id' => $userLanguage->user_id, 'language' => $userLanguage->language->code, 'proficiency' => $userLanguage->proficiency?->value,
        ]);

        return back()->with('status', 'Marked as verified.');
    }

    public function unverify(Request $request, UserLanguage $userLanguage): RedirectResponse
    {
        abort_unless($userLanguage->role === LanguageRole::CanTeachIn, 404);

        $userLanguage->forceFill(['verified_at' => null, 'verified_by_id' => null])->save();
        $this->audit->record($request->user(), 'academic.teaching_language.unverified', $userLanguage, ['user_id' => $userLanguage->user_id]);

        return back()->with('status', 'Verification removed.');
    }
}
