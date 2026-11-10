<?php

namespace App\Domain\Safety\Http\Controllers;

use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Safety\Support\ConsentText;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuardianInvitationController extends Controller
{
    /** A learner (or an operator on a learner's behalf, later) invites a parent or guardian by email. */
    public function store(Request $request, Guardians $guardians, AccountStanding $standing): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'relationship' => ['required', Rule::in(Guardians::RELATIONSHIPS)],
        ]);

        $user = $request->user();

        if ($standing->isSuspended($user)) {
            abort(403, 'This account is suspended.');
        }

        $result = $guardians->invite($user, $validated['email'], $validated['relationship']);

        if ($request->expectsJson()) {
            return response()->json([
                'invitation' => ['id' => $result['invitation']->id, 'email' => $result['invitation']->email, 'expires_at' => $result['invitation']->expires_at->toIso8601String()],
                // The same link that was emailed, for when mail is slow: it only works for the invited address.
                'accept_url' => $result['url'],
            ], 201);
        }

        return redirect()->route('safety.account')->with('status', 'Invitation sent to '.$result['invitation']->email.'.');
    }

    /** The emailed, signed link. The guardian is signed in (InfoDot) by the auth middleware first. */
    public function show(Request $request, int $invitation, Guardians $guardians): View
    {
        $found = $guardians->findUsableInvitation($invitation, (string) $request->query('token'));

        abort_if($found === null, 410, 'This invitation link is no longer valid. Ask the learner to send a new one.');

        return view('safety::guardian-accept', [
            'invitation' => $found,
            'token' => (string) $request->query('token'),
            'learnerName' => $found->learner->name,
            'consentText' => ConsentText::PROCESSING,
            'recordingText' => ConsentText::LESSON_RECORDING,
            'consentVersion' => config('safety.guardians.consent_version'),
            'emailMatches' => strcasecmp((string) $request->user()->email, $found->email) === 0,
        ]);
    }

    public function accept(Request $request, int $invitation, Guardians $guardians): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'consent' => ['accepted'],
            'recording_consent' => ['sometimes', 'boolean'],
        ]);

        $found = $guardians->findUsableInvitation($invitation, $validated['token']);
        abort_if($found === null, 410, 'This invitation link is no longer valid. Ask the learner to send a new one.');

        $link = $guardians->accept($found, $validated['token'], $request->user(),
            CarbonImmutable::createFromFormat('Y-m-d', $validated['birth_date'])->startOfDay(),
            (bool) ($validated['recording_consent'] ?? false));

        if ($request->expectsJson()) {
            return response()->json(['link' => ['id' => $link->id, 'learner_id' => $link->learner_id, 'status' => $link->status]], 201);
        }

        return redirect()->route('safety.guardian.dashboard')->with('status', 'You are now linked as a guardian.');
    }
}
