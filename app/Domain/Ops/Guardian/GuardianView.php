<?php

namespace App\Domain\Ops\Guardian;

use App\Domain\Money\Models\Order;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Services\GuardianDashboard;
use App\Domain\Safety\Services\Guardians;
use App\Models\User;

/**
 * What a guardian sees: safety's dashboard for each linked child (standing, approvals, lessons, purchase requests,
 * messages summary, reports, settings), with the money orders each child placed (the dashboard's own payload says
 * orders are not connected there) and the warnings or suspensions on the child's account. Nothing about other
 * children, and nothing a guardian could not already see through the safety domain.
 */
class GuardianView
{
    public function __construct(private readonly GuardianDashboard $dashboard, private readonly Guardians $guardians, private readonly GuardianSafety $safety) {}

    /** @return array<string, mixed> */
    public function for(User $guardian): array
    {
        $data = $this->dashboard->for($guardian);
        $links = GuardianLink::query()->active()->where('guardian_id', $guardian->id)->pluck('id', 'learner_id');

        $children = User::query()->whereIn('id', collect($data['children'])->pluck('id'))->get()->keyBy('id');

        $data['children'] = collect($data['children'])->map(function (array $child) use ($links, $children, $guardian) {
            $child['link_id'] = $links[$child['id']] ?? null;
            $child['orders'] = Order::query()->with('lines:id,order_id,title')->where('buyer_id', $child['id'])->orderByDesc('id')->limit(20)->get()
                ->map(fn (Order $o) => [
                    'uuid' => $o->uuid,
                    'status' => $o->status,
                    'total_minor' => $o->total_minor,
                    'refunded_minor' => $o->refunded_minor,
                    'currency' => $o->currency,
                    'items' => $o->lines->pluck('title')->join(', '),
                    'created_at' => $o->created_at,
                    'paid_by_guardian' => $o->paid_by !== null && $o->paid_by !== $o->buyer_id,
                ])->all();
            $child['notices'] = AccountRestriction::query()->where('user_id', $child['id'])->orderByDesc('id')->limit(10)->get()
                ->map(fn (AccountRestriction $r) => [
                    'kind' => $r->kind,
                    'reason' => $r->reason,
                    'starts_at' => $r->starts_at,
                    'ends_at' => $r->ends_at,
                    'lifted_at' => $r->lifted_at,
                ])->all();
            $child['pending'] = array_values(array_filter($child['approvals'], fn ($a) => $a['status'] === 'pending'));
            $child['safety'] = $this->safety->rowsForChild($children[$child['id']], $guardian);
            $child['urgent'] = $this->safety->urgent($child['safety']);
            $child['open_reports'] = count(array_filter($child['safety'], fn ($r) => $r['open'] && $r['kind'] !== 'notice'));

            return $child;
        })->all();

        $data['decisions'] = $this->decisions($guardian, $data['children']);
        $data['urgent'] = collect($data['children'])->flatMap(fn ($c) => $c['urgent'])->values()->all();

        $data['orders_connected'] = true;
        $data['waiting_total'] = collect($data['children'])->sum(fn ($c) => count($c['pending']));
        $data['is_guardian'] = $data['children'] !== [];

        return $data;
    }

    /**
     * Approvals that are no longer waiting (approved, declined, expired, withdrawn), newest first, so a decision
     * leaves a trace (D-2102). Says who decided: this guardian or another of the child's guardians.
     *
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>>
     */
    private function decisions(User $guardian, array $children): array
    {
        $names = collect($children)->pluck('name', 'id');
        if ($names->isEmpty()) {
            return [];
        }

        return GuardianApproval::query()->whereIn('learner_id', $names->keys())
            ->where(fn ($q) => $q->where('status', '!=', GuardianApproval::PENDING)->orWhere('expires_at', '<=', now()))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(50)->get()
            ->map(fn (GuardianApproval $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'child_id' => $a->learner_id,
                'child' => $names[$a->learner_id],
                'summary' => $a->summary,
                'amount_minor' => $a->amount_minor,
                'currency' => $a->currency,
                'status' => $a->effectiveStatus(),
                'by_you' => $a->decided_by_id === $guardian->id,
                'note' => $a->note,
                'at' => $a->decided_at ?? ($a->effectiveStatus() === GuardianApproval::EXPIRED && $a->status === GuardianApproval::PENDING ? $a->expires_at : $a->updated_at),
            ])->all();
    }
}
