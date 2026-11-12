<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Models\AuditEntry;
use App\Domain\Safety\Services\DatabaseAuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The audit history viewer (brief section 20): safety's append-only, hash-chained log, filtered by action prefix,
 * actor or subject, with the chain check on request (it reads every row; safety:audit-verify also runs it daily). Reading the log is itself recorded.
 */
class AuditController extends OpsController
{
    public function __invoke(Request $request, DatabaseAuditLog $log): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
        ]);

        Audit::record($request->user(), 'ops.audit.read', null, array_filter($filters));

        $entries = AuditEntry::query()
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($filters['actor_id'] ?? null, fn ($q, $a) => $q->where('actor_id', $a))
            ->when($filters['subject_id'] ?? null, fn ($q, $i) => $q->where('subject_id', $i))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('ops.audit', [
            'entries' => $entries,
            'filters' => $filters,
            'checked' => $request->boolean('verify'),
            'brokenAt' => $request->boolean('verify') ? $log->verify() : null,
            'total' => AuditEntry::query()->count(),
        ]);
    }
}
