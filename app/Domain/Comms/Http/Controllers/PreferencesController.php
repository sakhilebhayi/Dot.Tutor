<?php

namespace App\Domain\Comms\Http\Controllers;

use App\Domain\Comms\ChannelStatus;
use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Preferences;
use App\Domain\Comms\PreferenceLocked;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Domain\Comms\Push\VapidKeys;
use App\Domain\Comms\Support\ShellFor;
use App\Domain\Comms\QuietHours;
use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Channel choices per category and quiet hours. Mandatory categories are shown as facts, not as switches. */
class PreferencesController extends Controller
{
    public function __construct(
        private readonly Preferences $preferences,
        private readonly QuietHours $quiet,
        private readonly ChannelStatus $status,
        private readonly MinorRouting $minors,
    ) {}

    public function show(Request $request, PushSubscriptions $subscriptions, VapidKeys $vapid): View|JsonResponse
    {
        $user = $request->user();
        $payload = [
            'categories' => $this->preferences->matrix($user),
            'quiet_hours' => $this->quiet->settings($user),
            'channels' => $this->status->summary(),
            'age_band' => $this->minors->band($user),
            'devices' => $subscriptions->active($user)->map(fn ($s) => ['label' => $s->device_label ?? 'A browser', 'since' => $s->created_at?->toIso8601String()])->values()->all(),
            'push_key' => $vapid->publicKeyBase64(),
        ];

        return $request->expectsJson() ? response()->json($payload) : view('comms::preferences', $payload + [
            'timezones' => DateTimeZone::listIdentifiers(),
            'shell' => ShellFor::user($user),
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'choices' => ['sometimes', 'array', 'max:60'],
            'choices.*.category' => ['required', 'string', 'max:40'],
            'choices.*.channel' => ['required', Rule::in(['push', 'email'])],
            'choices.*.enabled' => ['required', 'boolean'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['required_with:quiet_hours', 'boolean'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.timezone' => ['nullable', 'string', 'max:64'],
        ]);

        // A form posts every switch of the categories it shows; unticked boxes arrive as absent, so the page sends
        // the full list of shown switches in `shown` and comms reads "absent" as off.
        $choices = $data['choices'] ?? [];
        if (! $request->expectsJson() && is_array($request->input('shown'))) {
            $on = (array) $request->input('on', []);
            $choices = collect($request->input('shown'))->filter(fn ($v) => is_string($v) && str_contains($v, '.'))
                ->map(function (string $pair) use ($on) {
                    [$category, $channel] = explode('.', $pair, 2);

                    return ['category' => $category, 'channel' => $channel, 'enabled' => in_array($pair, $on, true)];
                })->values()->all();
        }

        $matrix = $this->preferences->matrix($user);

        try {
            DB::transaction(function () use ($user, $choices, $data, $matrix) {
                foreach ($choices as $choice) {
                    $current = $matrix[$choice['category']]['channels'][$choice['channel']] ?? null;
                    // Mandatory categories: an unchanged "on" from a form is fine; trying to turn one off is refused.
                    if ($current !== null && $current['locked'] && (bool) $choice['enabled']) {
                        continue;
                    }
                    $this->preferences->set($user, $choice['category'], $choice['channel'], (bool) $choice['enabled']);
                }

                if (isset($data['quiet_hours'])) {
                    $this->quiet->save($user, (bool) $data['quiet_hours']['enabled'], $data['quiet_hours']['start'], $data['quiet_hours']['end'], $data['quiet_hours']['timezone'] ?? null);
                }
            });
        } catch (PreferenceLocked $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'locked', 'message' => $e->getMessage(), 'category' => $e->category], 422);
            }
            throw ValidationException::withMessages(['choices' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'invalid_choice', 'message' => $e->getMessage()], 422);
            }
            throw ValidationException::withMessages(['choices' => $e->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['saved' => true, 'categories' => $this->preferences->matrix($user), 'quiet_hours' => $this->quiet->settings($user)])
            : back()->with('success', 'Your notification settings are saved.');
    }
}
