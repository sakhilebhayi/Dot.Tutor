<?php

namespace App\Domain\Safety\Http\Controllers\Operator;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\AuditEntry;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\DatabaseAuditLog;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Safety\Services\Messenger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Minor account controls for safeguarding staff: standing, verified date of birth, guardian links, audit trail. */
class AccountsController extends Controller
{
    public function show(Request $request, User $user, AccountStanding $standing, Guardians $guardians): JsonResponse
    {
        Audit::record($request->user(), 'safety.account.viewed', $user);

        return response()->json([
            'user' => $user->only(['id', 'name']),
            'account' => $standing->summary($user),
            'guardians' => GuardianLink::query()->where('learner_id', $user->id)->orderByDesc('id')->get()
                ->map(fn (GuardianLink $l) => ['id' => $l->id, 'guardian_id' => $l->guardian_id, 'status' => $l->status, 'relationship' => $l->relationship])->all(),
            'children' => $guardians->childrenOf($user)->map(fn (User $c) => $c->only(['id', 'name']))->all(),
        ]);
    }

    /** Record a date of birth an operator verified (it outranks self-declared and guardian-declared dates). */
    public function age(Request $request, User $user, Messenger $messenger): JsonResponse
    {
        $validated = $request->validate([
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        AgeRecord::create([
            'user_id' => $user->id,
            'birth_date' => $validated['birth_date'],
            'source' => 'operator_verified',
            'recorded_by_id' => $request->user()->id,
            'note' => $validated['note'],
        ]);

        $messenger->syncGuardians($user);
        Audit::record($request->user(), 'safety.age.verified', $user);

        return response()->json(['account' => app(AccountStanding::class)->summary($user)], 201);
    }

    public function revokeLink(Request $request, GuardianLink $link, Guardians $guardians): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $guardians->revoke($link, $request->user(), $validated['reason']);

        return response()->json(['link' => ['id' => $link->id, 'status' => $link->fresh()->status]]);
    }

    public function audit(Request $request, DatabaseAuditLog $log): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer'],
        ]);

        $entries = AuditEntry::query()
            ->when($validated['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($validated['actor_id'] ?? null, fn ($q, $a) => $q->where('actor_id', $a))
            ->when($validated['subject_type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
            ->when($validated['subject_id'] ?? null, fn ($q, $i) => $q->where('subject_id', $i))
            ->orderByDesc('id')->limit(200)->get(['id', 'actor_id', 'actor_label', 'action', 'subject_type', 'subject_id', 'context', 'created_at']);

        return response()->json(['entries' => $entries, 'chain_broken_at' => $log->verify()]);
    }
}
