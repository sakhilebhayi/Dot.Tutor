<?php

namespace App\Domain\Security\Http\Controllers;

use App\Domain\Safety\Audit;
use App\Domain\Security\Services\PersonalDataExport;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Your data and account" (R-47 security S-20, S-21; docs/R47_PRIVACY.md): download every record about you, or
 * close the account. Both need a recent sign-in, because InfoDot accounts have no password to confirm here (the
 * Jetstream delete form asks for one, which an InfoDot user never had).
 */
class PrivacyController extends Controller
{
    public function show(Request $request): View
    {
        return view('security.privacy', ['user' => $request->user()]);
    }

    public function export(Request $request, PersonalDataExport $export): Response
    {
        $user = $request->user();
        Audit::record($user, 'account.data_exported', $user);

        return response()->json($export->for($user), 200, [
            'Content-Disposition' => 'attachment; filename="dot-tutor-my-data-'.now()->format('Y-m-d').'.json"',
            'Cache-Control' => 'no-store, private',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function close(Request $request, DeletesUsers $deleter): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string', 'in:CLOSE']], ['confirm.in' => 'Type CLOSE in capitals to close the account.']);

        $user = $request->user();
        $deleter->delete($user->fresh());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'Your Dot.Tutor account is closed.');
    }
}
