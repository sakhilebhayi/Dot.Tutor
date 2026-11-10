<?php

namespace App\Domain\Comms\Http\Controllers;

use App\Domain\Comms\Push\PushResult;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Domain\Comms\Push\VapidKeys;
use App\Domain\Comms\Push\WebPushClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Turn push on or off for this browser, and send yourself a test (reporting what each push service answered). */
class PushController extends Controller
{
    public function key(VapidKeys $vapid): JsonResponse
    {
        return response()->json(['configured' => $vapid->configured(), 'public_key' => $vapid->publicKeyBase64()]);
    }

    public function subscribe(Request $request, PushSubscriptions $subscriptions, VapidKeys $vapid): JsonResponse
    {
        if (! $vapid->configured()) {
            return response()->json(['error' => 'push_not_configured', 'message' => 'Push notifications are not set up on Dot.Tutor yet.'], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:64'],
        ]);

        try {
            $subscription = $subscriptions->subscribe($request->user(), $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $request->userAgent());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'subscription_refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['subscribed' => true, 'device' => $subscription->device_label], 201);
    }

    public function unsubscribe(Request $request, PushSubscriptions $subscriptions): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        return response()->json(['unsubscribed' => $subscriptions->unsubscribe($request->user(), $data['endpoint'])]);
    }

    public function test(Request $request, PushSubscriptions $subscriptions, WebPushClient $push, VapidKeys $vapid): JsonResponse
    {
        if (! $vapid->configured()) {
            return response()->json(['error' => 'push_not_configured', 'message' => 'Push notifications are not set up on Dot.Tutor yet.'], 503);
        }

        $results = [];
        foreach ($subscriptions->active($request->user()) as $subscription) {
            $result = $push->send($subscription, ['title' => 'Test from Dot.Tutor', 'body' => 'Push notifications reach this device.', 'url' => '/comms/preferences', 'tag' => 'test']);
            if ($result->status === PushResult::GONE) {
                $subscriptions->revoke($subscription, (string) $result->reason);
            }
            $results[] = ['device' => $subscription->device_label ?? 'A browser', 'status' => $result->status, 'reason' => $result->reason];
        }

        return response()->json(['devices' => $results]);
    }
}
