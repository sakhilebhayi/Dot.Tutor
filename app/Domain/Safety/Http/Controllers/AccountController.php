<?php

namespace App\Domain\Safety\Http\Controllers;

use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\Guardians;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's own safeguarding standing: age band, limited or not, guardians, open approvals. */
class AccountController extends Controller
{
    public function show(Request $request, AccountStanding $standing, Guardians $guardians): JsonResponse|View
    {
        $user = $request->user();

        $data = [
            'account' => $standing->summary($user),
            'guardians' => $guardians->guardiansOf($user)->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all(),
            'pending_invitations' => GuardianInvitation::query()->where('learner_id', $user->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                ->orderByDesc('id')->get(['id', 'email', 'relationship', 'expires_at'])->all(),
            'approvals' => GuardianApproval::query()->where('learner_id', $user->id)->orderByDesc('id')->limit(20)->get()
                ->map(fn (GuardianApproval $a) => [
                    'id' => $a->id, 'kind' => $a->kind, 'summary' => $a->summary, 'status' => $a->effectiveStatus(),
                    'expires_at' => $a->expires_at->toIso8601String(),
                ])->all(),
            'is_guardian' => $guardians->childrenOf($user)->isNotEmpty(),
        ];

        return $request->expectsJson() ? response()->json($data) : view('safety::account', $data);
    }
}
