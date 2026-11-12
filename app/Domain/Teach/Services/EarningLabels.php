<?php

namespace App\Domain\Teach\Services;

use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Money\Models\Earning;
use App\Domain\Teach\Support\TutorContext;
use App\Models\TutorSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * What the tutor sold and to whom, for the earnings screen and income report (D-2207). The order line's frozen title
 * is written for the buyer ("Mathematics lesson with <tutor>"), so on the seller's side it named the tutor herself.
 * Here the label comes from what was bought: the subject or offering and the lesson time in the tutor's time zone,
 * and the learner (the order's buyer) as the person. Lines whose item is gone fall back to the frozen title.
 */
class EarningLabels
{
    /**
     * @param  iterable<Earning>  $earnings
     * @return array<int, array{what: string, who: ?string}> keyed by earning id
     */
    public function for(iterable $earnings, string $tz): array
    {
        $earnings = EloquentCollection::make($earnings);
        $earnings->loadMissing('orderLine.order.buyer');
        $lines = $earnings->pluck('orderLine')->filter();

        $ids = fn (string $type) => $lines->where('purchasable_type', $type)->pluck('purchasable_id')->unique()->all();
        $sessions = TutorSession::query()->with(['subject:id,name', 'offering:id,title'])->whereIn('id', $ids('booking'))->get()->keyBy('id');
        $seats = TutorSessionParticipant::query()->with(['session.subject:id,name', 'session.offering:id,title'])->whereIn('id', $ids('seat'))->get()->keyBy('id');
        $packages = TutorPackagePurchase::query()->with('offering:id,title')->whereIn('id', $ids('package'))->get()->keyBy('id');

        $lesson = function (?TutorSession $s, string $kind) use ($tz): ?string {
            if (! $s) {
                return null;
            }
            $what = $s->offering?->title ?: trim(($s->subject?->name ?? '').' '.$kind);

            return $what.', '.$s->starts_at->copy()->timezone($tz)->format('j M H:i');
        };

        $labels = [];
        foreach ($earnings as $earning) {
            $line = $earning->orderLine;
            $what = match ($line?->purchasable_type) {
                'booking' => $lesson($sessions[$line->purchasable_id] ?? null, 'lesson'),
                'seat' => $lesson(($seats[$line->purchasable_id] ?? null)?->session, 'group lesson'),
                'package' => ($p = $packages[$line->purchasable_id] ?? null) ? ($p->offering?->title ?: 'Lesson package').', '.$p->lessons_total.' lessons' : null,
                default => null,
            };
            $buyer = $line?->order?->buyer;
            $labels[$earning->id] = [
                'what' => $what ?? ($line?->title ?: 'Sale'),
                'who' => $line?->order ? TutorContext::learnerName($buyer) : null,
            ];
        }

        return $labels;
    }
}
