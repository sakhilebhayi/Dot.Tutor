<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Push\Base64Url;
use App\Domain\Comms\Push\EcKeys;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsOfferings;
use Tests\Feature\Safety\BuildsSafetyState;

/** Builders and assertions for the comms tests. People are built explicitly per test. */
trait BuildsComms
{
    use BuildsOfferings, BuildsSafetyState {
        BuildsOfferings::makeSubject insteadof BuildsSafetyState;
        BuildsOfferings::makeUser insteadof BuildsSafetyState;
        BuildsOfferings::makeCompany insteadof BuildsSafetyState;
        BuildsOfferings::makeTutorIn insteadof BuildsSafetyState;
        BuildsOfferings::stampSession insteadof BuildsSafetyState;
        BuildsOfferings::makeOperator insteadof BuildsSafetyState;
        BuildsOfferings::makeTutor insteadof BuildsSafetyState;
        BuildsOfferings::openWeek insteadof BuildsSafetyState;
        BuildsOfferings::slotAt insteadof BuildsSafetyState;
        BuildsOfferings::makeSession insteadof BuildsSafetyState;
        BuildsOfferings::bookingPayload insteadof BuildsSafetyState;
    }

    /** @return Collection<int, DatabaseNotification> */
    protected function inbox(User $user, ?string $type = null): Collection
    {
        return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('notifiable_type', $user->getMorphClass())
            ->orderBy('created_at')->get()
            ->filter(fn (DatabaseNotification $n) => $type === null || (((array) $n->data)['type'] ?? null) === $type)->values();
    }

    protected function delivery(User $user, string $type, string $channel): ?NotificationDelivery
    {
        return NotificationDelivery::query()->where('user_id', $user->id)->where('type', $type)->where('channel', $channel)->orderByDesc('id')->first();
    }

    protected function configurePush(): void
    {
        $keys = EcKeys::generate();
        config([
            'comms.push.public_key' => Base64Url::encode($keys['public']),
            'comms.push.private_key' => Base64Url::encode($keys['private']),
        ]);
    }

    /** A browser subscription with real keys (the test keeps the private half to decrypt what was sent). */
    protected function subscribeBrowser(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        $ua = EcKeys::generate();
        $auth = random_bytes(16);
        app(\App\Domain\Comms\Push\PushSubscriptions::class)->subscribe($user, $endpoint, Base64Url::encode($ua['public']), Base64Url::encode($auth), 'Mozilla/5.0 (Linux; Android 14) Chrome/130');

        return ['ua' => $ua, 'auth' => $auth];
    }

    protected function deliversMail(): void
    {
        config(['mail.default' => 'smtp']);
    }

    /** Link a guardian through the real flow, then put real notifications back (the safety builder fakes them). */
    protected function guardianFor(User $learner, ?User $guardian = null): User
    {
        $guardian ??= $this->adult('Guardian');
        $this->linkGuardian($learner, $guardian);
        \Illuminate\Support\Facades\Notification::swap(new \Illuminate\Notifications\ChannelManager($this->app));
        $this->forgetAges();

        return $guardian;
    }

    /** Forget the per-request age memo (ages change inside a test). */
    protected function forgetAges(): void
    {
        $routing = app(MinorRouting::class);
        if (method_exists($routing, 'forget')) {
            $routing->forget();
        }
    }
}
