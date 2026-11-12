<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;

/**
 * The person's real in-app notifications, newest first, each with what happened to its other copies as recorded.
 * Nothing here is invented: an empty inbox is empty. The unread count is one per subject (collapse key), so one
 * booking's six updates count once, and the badge is capped ("9+").
 */
final class Inbox
{
    public function __construct(private readonly CommsCategories $categories) {}

    public function page(User $user, int $perPage = 20, bool $unreadOnly = false): CursorPaginator
    {
        $query = $user->notifications()->getQuery()->reorder()->orderByDesc('created_at')->orderByDesc('id');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->cursorPaginate($perPage)->withQueryString();
    }

    public static function unreadCount(User $user): int
    {
        $keys = [];
        $loose = 0;

        $user->unreadNotifications()->getQuery()->reorder()->select(['id', 'data'])->orderByDesc('created_at')->limit(1000)
            ->each(function (DatabaseNotification $n) use (&$keys, &$loose): void {
                $key = ((array) $n->data)['collapse_key'] ?? null;
                is_string($key) && $key !== '' ? $keys[$key] = true : $loose++;
            });

        return $loose + count($keys);
    }

    /** The badge text: the count, capped ("9+"); null when there is nothing unread. */
    public static function badge(int $count): ?string
    {
        $cap = max(1, (int) config('comms.badge_cap', 9));

        return $count <= 0 ? null : ($count > $cap ? $cap.'+' : (string) $count);
    }

    /** Marks every unread notification about one subject read (the person opened it). */
    public static function markSubjectRead(User $user, string $collapseKey): int
    {
        $ids = $user->unreadNotifications()->getQuery()->reorder()->select(['id', 'data'])->limit(500)->get()
            ->filter(fn (DatabaseNotification $n) => (((array) $n->data)['collapse_key'] ?? null) === $collapseKey)
            ->pluck('id');

        return $ids->isEmpty() ? 0 : DatabaseNotification::query()->whereIn('id', $ids)->update(['read_at' => now()]);
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<array<string, mixed>>
     */
    public function present(User $user, iterable $notifications): array
    {
        $list = collect($notifications);
        $deliveries = NotificationDelivery::where('user_id', $user->id)
            ->whereIn('notification_id', $list->pluck('id')->all())
            ->where('channel', '!=', NotificationDelivery::IN_APP)
            ->orderBy('channel')
            ->get()
            ->groupBy('notification_id');

        // The unread count is one per subject (collapse key), so only the newest unread row of a subject carries the
        // "Unread" marker; its older unread updates say "Earlier update" (fix round D-2306: 3 unread, 7 markers).
        $seenSubjects = [];

        return $list->map(function (DatabaseNotification $n) use ($deliveries, &$seenSubjects) {
            $data = (array) $n->data;
            $type = (string) ($data['type'] ?? 'general');
            $category = (string) ($data['category'] ?? $this->categories->categoryFor($type));
            $expiresAt = isset($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : null;
            $key = is_string($data['collapse_key'] ?? null) && $data['collapse_key'] !== '' ? $data['collapse_key'] : null;
            $marker = null;
            if ($n->read_at === null) {
                $marker = $key !== null && isset($seenSubjects[$key]) ? 'earlier' : 'unread';
                if ($key !== null) {
                    $seenSubjects[$key] = true;
                }
            }

            return [
                'id' => $n->id,
                'type' => $type,
                'category' => $category,
                'category_label' => $this->categories->label($category),
                'mandatory' => $this->categories->isMandatory($category),
                'title' => (string) ($data['title'] ?? $this->categories->label($category)),
                'message' => (string) ($data['message'] ?? ''),
                'url' => self::localUrl(isset($data['url']) ? (string) $data['url'] : null),
                'calendar_url' => isset($data['ics_session_id']) ? route('comms.calendar.lesson', ['session' => (int) $data['ics_session_id']], false) : null,
                'read' => $n->read_at !== null,
                'unread_marker' => $marker,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
                'expired' => $expiresAt !== null && $expiresAt->isPast(),
                'deliveries' => ($deliveries[$n->id] ?? collect())->map(fn (NotificationDelivery $d) => $d->toPublicArray())->values()->all(),
            ];
        })->values()->all();
    }

    /** Links only to this site: a relative path, or an absolute URL on the app's own host. */
    public static function localUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $requestHost = request()?->getHost();

        if ($host !== null && ($host === $appHost || $host === $requestHost)) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
            $query = parse_url($url, PHP_URL_QUERY);

            return $path.($query ? '?'.$query : '');
        }

        return null;
    }
}
