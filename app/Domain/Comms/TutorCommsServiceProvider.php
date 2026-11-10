<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Calendar\LessonCalendar;
use App\Domain\Comms\Console\DeliverCommand;
use App\Domain\Comms\Console\PruneCommand;
use App\Domain\Comms\Console\RemindersCommand;
use App\Domain\Comms\Console\VapidKeysCommand;
use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Events\StudyGroupUpdated;
use App\Domain\Comms\Listeners\AccountNotifications;
use App\Domain\Comms\Listeners\BookingEventsObserver;
use App\Domain\Comms\Listeners\LearningNotifications;
use App\Domain\Comms\Listeners\MoneyNotifications;
use App\Domain\Comms\Listeners\SourceNotificationListener;
use App\Domain\Comms\Listeners\StudyGroupNotifications;
use App\Domain\Comms\Notify\NotifyClient;
use App\Domain\Comms\Sms\NullSmsSender;
use App\Domain\Comms\Sms\SmsSender;
use App\Domain\Comms\Support\SafetyMinorRouting;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\ResourceReview;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Models\VerificationDecision;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Events\OrderRefunded;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Safety\Models\Report;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * R-47 comms: notifications, reminders, channels (docs/R47_COMMS.md). Binds the comms services, an honest SMS
 * adapter (not configured), and MinorRouting over the safety domain (bindIf, so the integration provider may
 * replace it). Subscribes to the other domains' events and rows here, never in route files (route:cache).
 */
class TutorCommsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(MinorRouting::class, SafetyMinorRouting::class, true);
        $this->app->bindIf(SmsSender::class, NullSmsSender::class, true);

        foreach ([CommsCategories::class, Preferences::class, QuietHours::class, ChannelStatus::class, NotifyClient::class,
            FanOut::class, NotificationRouter::class, DeliveryWorker::class, Inbox::class, Messenger::class, Reminders::class,
            LessonCalendar::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        // Views live under resources/views/comms so Tailwind's @source sees them.
        $this->loadViewsFrom(resource_path('views/comms'), 'comms');

        RateLimiter::for('comms', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('comms-push-test', fn (Request $request) => Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));

        $this->subscribe();

        if ($this->app->runningInConsole()) {
            $this->commands([DeliverCommand::class, RemindersCommand::class, PruneCommand::class, VapidKeysCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('comms:deliver')->everyMinute()->withoutOverlapping(5)->onOneServer();
            $schedule->command('comms:reminders')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
            $schedule->command('comms:prune')->dailyAt('03:40')->timezone('UTC')->onOneServer();
        });

        $this->jsonErrors();
    }

    /** The other domains' events and rows. Every handler runs after the writing transaction commits and never throws. */
    private function subscribe(): void
    {
        TutorSessionEvent::observe(BookingEventsObserver::class);

        Event::listen(NotificationSent::class, [SourceNotificationListener::class, 'sent']);
        Event::listen(NotificationFailed::class, [SourceNotificationListener::class, 'failed']);
        Event::listen(OrderPaid::class, [MoneyNotifications::class, 'orderPaid']);
        Event::listen(OrderRefunded::class, [MoneyNotifications::class, 'orderRefunded']);
        Event::listen(StudyGroupUpdated::class, [StudyGroupNotifications::class, 'handle']);

        $after = fn (string $class, string $method) => function ($model) use ($class, $method) {
            DB::afterCommit(fn () => app($class)->{$method}($model));
        };

        Payment::updated($after(MoneyNotifications::class, 'paymentUpdated'));
        Payout::created($after(MoneyNotifications::class, 'payoutCreated'));
        Payout::updated($after(MoneyNotifications::class, 'payoutUpdated'));
        Enrolment::created($after(LearningNotifications::class, 'enrolmentCreated'));
        Assignment::saved($after(LearningNotifications::class, 'assignmentSaved'));
        ResourceReview::created($after(LearningNotifications::class, 'resourceReviewCreated'));
        LibraryResource::updated($after(LearningNotifications::class, 'resourceUpdated'));
        VerificationDecision::created($after(AccountNotifications::class, 'verificationDecided'));
        Report::updated($after(AccountNotifications::class, 'reportUpdated'));
    }

    private function jsonErrors(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(function (ValidationException $e, Request $request) {
            if ($request->is('comms/*') && $request->expectsJson()) {
                return response()->json(['error' => 'validation_failed', 'message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
            }
        });
        $handler->renderable(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('comms/*') && $request->expectsJson()) {
                return response()->json(['error' => Str::snake(str_replace('HttpException', '', class_basename($e))) ?: 'error', 'message' => $e->getMessage() ?: 'Request refused.'],
                    $e->getStatusCode(), $e->getHeaders());
            }
        });
    }
}
