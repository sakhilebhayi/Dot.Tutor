<?php

namespace App\Domain\Teach\Support;

use App\Domain\Money\Money;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/** The signed-in tutor's own profile. Every workspace query is scoped through it, never through an id in the URL. */
final class TutorContext
{
    public static function profile(Request $request): TutorProfile
    {
        $profile = $request->user()->tutorProfile()->first();
        abort_if($profile === null, 404);

        return $profile;
    }

    public static function tz(TutorProfile $profile): string
    {
        return $profile->scheduleTimezone();
    }

    /** A learner's name as the tutor sees it: the account name, or a plain stand-in for a deleted account. */
    public static function learnerName(?User $user): string
    {
        return $user?->name ?: 'Deleted account';
    }

    /** The kit's money rendering for a stat strip value (which takes a string or HtmlString). */
    public static function money(Money|int $amount, ?string $currency = null): HtmlString
    {
        $m = $amount instanceof Money ? $amount : Money::of($amount, $currency);

        return new HtmlString(Blade::render('<x-td.money :minor="$minor" :currency="$currency" />', ['minor' => $m->minor, 'currency' => $m->currency]));
    }
}
