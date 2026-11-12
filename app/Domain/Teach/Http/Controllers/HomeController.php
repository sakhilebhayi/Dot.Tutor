<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Services\Messenger;
use App\Domain\Teach\Services\BookingQueries;
use App\Domain\Teach\Services\MoneyGlance;
use App\Domain\Teach\Services\OnboardingProgress;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * The workspace home (brief section 7, "tutor business dashboard"): what needs the tutor today and this week,
 * requests to answer, lessons waiting on a guardian, money at a glance and unread messages.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, BookingQueries $bookings, MoneyGlance $money, OnboardingProgress $progress, Messenger $messenger): View
    {
        $profile = TutorContext::profile($request);
        $tz = TutorContext::tz($profile);
        $now = CarbonImmutable::now($tz);
        $user = $request->user();

        $today = $bookings->between($profile, $now->startOfDay(), $now->endOfDay()->addSecond(), ['confirmed', 'in_progress', 'awaiting_payment', 'completed']);
        $week = $bookings->between($profile, $now->endOfDay()->addSecond(), $now->startOfDay()->addDays(7));

        $conversations = Conversation::query()->visibleTo($user)->get();
        $unread = $conversations->sum(fn (Conversation $c) => $messenger->unreadCount($c, $user));

        return view('teach.home', [
            'profile' => $profile,
            'tz' => $tz,
            'now' => $now,
            'state' => $progress->state($profile),
            'missing' => $progress->missing($profile),
            'today' => $today,
            'week' => $week,
            'requests' => $bookings->requests($profile),
            'series' => $bookings->pendingSeries($profile),
            'guardianHolds' => $bookings->guardianHoldCount($profile),
            'attention' => $bookings->needingAttention($profile),
            'money' => $money->for($user, $tz),
            'unread' => $unread,
            'conversationCount' => $conversations->count(),
            'messagesUrl' => Route::has('messages.index') ? route('messages.index') : route('safety.conversations.index'),
            'assistantUrl' => Route::has('ai.tutor.index') ? route('ai.tutor.index') : null,
        ]);
    }
}
