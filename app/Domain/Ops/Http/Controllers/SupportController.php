<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\ConversationParticipant;
use App\Domain\Safety\Services\Messenger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Support conversations (safety messaging, context "support"): each request is its own thread, open to limited
 * minors too. Replies go through safety's Messenger, so the gate, rate limits, contact redaction for minors and
 * guardian copies all apply. Reading a thread is audited, as safety does for operator reads.
 */
class SupportController extends OpsController
{
    public function index(): View
    {
        $threads = Conversation::query()->where('context_type', Conversation::SUPPORT)
            ->orderByRaw('case when closed_at is null then 0 else 1 end')->orderByDesc('last_message_at')->paginate(30);

        $lastSenders = DB::table('safety_messages')->whereIn('conversation_id', $threads->pluck('id'))
            ->select('conversation_id', DB::raw('max(id) as id'))->groupBy('conversation_id')->pluck('id', 'conversation_id');
        $senders = DB::table('safety_messages')->whereIn('id', $lastSenders->values())->pluck('sender_id', 'conversation_id');
        $operators = DB::table('users')->whereIn('id', $senders->values()->filter())->where('is_platform_operator', true)->pluck('id')->flip();

        return view('ops.support.index', [
            'threads' => $threads,
            'waiting' => fn (Conversation $c) => isset($senders[$c->id]) && ! isset($operators[$senders[$c->id]]),
            'members' => fn (Conversation $c) => $c->participants()->where('role', ConversationParticipant::MEMBER)->with('user:id,name')->get()->map(fn ($p) => $p->user?->name)->filter()->join(', '),
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        abort_unless($conversation->context_type === Conversation::SUPPORT, 404);
        Audit::record($request->user(), 'safety.conversation.operator_read', $conversation);

        return view('ops.support.show', [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->with('sender:id,name,is_platform_operator')->orderBy('id')->get(),
            'canReply' => $this->can(OpsPermission::SUPPORT_ACT),
        ]);
    }

    public function reply(Request $request, Conversation $conversation, Messenger $messenger): RedirectResponse
    {
        abort_unless($conversation->context_type === Conversation::SUPPORT, 404);
        abort_unless($this->can(OpsPermission::SUPPORT_ACT), 403, 'Your operator role does not include this.');
        $data = $request->validate(['body' => ['required', 'string', 'max:'.config('safety.messaging.max_length')]]);

        $messenger->send($conversation, $request->user(), $data['body']);

        return $this->done('Reply sent.');
    }
}
