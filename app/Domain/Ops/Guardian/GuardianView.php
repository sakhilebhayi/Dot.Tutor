<?php

namespace App\Domain\Ops\Guardian;

use App\Domain\Money\Models\Order;
use App\Domain\Safety\Models\AccountRestriction;
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
    public function __construct(private readonly GuardianDashboard $dashboard, private readonly Guardians $guardians) {}

    /** @return array<string, mixed> */
    public function for(User $guardian): array
    {
        $data = $this->dashboard->for($guardian);
        $links = GuardianLink::query()->active()->where('guardian_id', $guardian->id)->pluck('id', 'learner_id');

        $data['children'] = collect($data['children'])->map(function (array $child) use ($links) {
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

            return $child;
        })->all();

        $data['orders_connected'] = true;
        $data['waiting_total'] = collect($data['children'])->sum(fn ($c) => count($c['pending']));
        $data['is_guardian'] = $data['children'] !== [];

        return $data;
    }
}
