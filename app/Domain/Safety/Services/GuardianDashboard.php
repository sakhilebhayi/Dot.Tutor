<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\ConversationParticipant;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\Report;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;

/**
 * Everything a guardian sees about each linked child, from real rows only. Purchases are the purchase and
 * paid-room approvals held here; the order history arrives when the money domain is wired in (wave 2), and the
 * payload says so ('orders_connected' => false) rather than showing an empty list as if nothing was bought.
 */
class GuardianDashboard
{
    public function __construct(
        private readonly AccountStanding $standing,
        private readonly Guardians $guardians,
        private readonly Messenger $messenger,
        private readonly AgeResolver $ages,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $guardian): array
    {
        $links = GuardianLink::query()->active()->where('guardian_id', $guardian->id)->with('learner')->orderBy('id')->get();

        return [
            'guardian' => ['id' => $guardian->id, 'name' => $guardian->name],
            'children' => $links->map(fn (GuardianLink $link) => $this->child($link, $guardian))->values()->all(),
            'orders_connected' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function child(GuardianLink $link, User $guardian): array
    {
        $child = $link->learner;
        $band = $this->standing->band($child);

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $child->id)->where('role', ConversationParticipant::MEMBER))
            ->orderByDesc('last_message_at')->get();

        $approvals = GuardianApproval::query()->where('learner_id', $child->id)->orderByDesc('id')->limit(50)->get();

        $messageIds = Message::query()->whereIn('conversation_id', $conversations->pluck('id'))->select('id');

        $reports = Report::query()
            ->where(fn ($q) => $q->where('reporter_id', $child->id)->orWhere('reported_user_id', $child->id)
                ->orWhere(fn ($q) => $q->where('reportable_type', (new Message)->getMorphClass())->whereIn('reportable_id', $messageIds)))
            ->orderByDesc('id')->limit(20)->get();

        return [
            'id' => $child->id,
            'name' => $child->name,
            'relationship' => $link->relationship,
            'age_band' => $band->value,
            'age_band_label' => $band->label(),
            'account' => $this->standing->summary($child),
            'birth_date_known' => $this->ages->knownBand($child) !== null,
            'recording_consent' => $this->guardians->hasRecordingConsent($child),
            'approval_settings' => $band === AgeBand::Teen
                ? collect(config('safety.approvals.kinds'))->mapWithKeys(fn ($kind) => [$kind => app(GuardianApprovals::class)->requires($child, $kind)])->all()
                : null,
            'upcoming_lessons' => TutorSession::query()->with(['tutorProfile.user', 'subject'])
                ->where('student_id', $child->id)
                ->whereIn('status', SessionStatus::holdingTime())
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')->limit(20)->get()
                ->map(fn (TutorSession $s) => [
                    'id' => $s->id,
                    'subject' => $s->subject?->name,
                    'tutor' => $s->tutorProfile?->user?->name,
                    'starts_at' => $s->starts_at->toIso8601String(),
                    'duration_minutes' => $s->duration_minutes,
                    'status' => $s->status,
                ])->all(),
            'approvals' => $approvals->map(fn (GuardianApproval $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'summary' => $a->summary,
                'amount_minor' => $a->amount_minor,
                'currency' => $a->currency,
                'status' => $a->effectiveStatus(),
                'expires_at' => $a->expires_at->toIso8601String(),
            ])->all(),
            'purchases' => $approvals->whereIn('kind', ['purchase', 'paid_room'])->map(fn (GuardianApproval $a) => [
                'approval_id' => $a->id,
                'summary' => $a->summary,
                'amount_minor' => $a->amount_minor,
                'currency' => $a->currency,
                'status' => $a->effectiveStatus(),
            ])->values()->all(),
            'messages' => [
                'conversations' => $conversations->count(),
                'last_message_at' => $conversations->max('last_message_at')?->toIso8601String(),
                'unread_for_you' => $conversations->sum(fn (Conversation $c) => $this->messenger->unreadCount($c, $guardian)),
                'redacted_messages' => Message::query()->whereIn('conversation_id', $conversations->pluck('id'))->whereNotNull('redactions')->count(),
                'items' => $conversations->take(20)->map(fn (Conversation $c) => [
                    'id' => $c->id,
                    'context' => $c->context_type,
                    'subject' => $c->subject,
                    'last_message_at' => $c->last_message_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'reports' => $reports->map(fn (Report $r) => [
                'id' => $r->id,
                'category' => $r->category->value,
                'category_label' => $r->category->label(),
                'status' => $r->status,
                'filed_by_child' => $r->reporter_id === $child->id,
                'created_at' => $r->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
