<?php

namespace App\Domain\Comms\Http\Controllers;

use App\Domain\Comms\Inbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/** The person's own notifications: list, unread summary, mark one or all read. JSON for scripts, a page otherwise. */
class InboxController extends Controller
{
    public function index(Request $request, Inbox $inbox): View|JsonResponse
    {
        $user = $request->user();
        $page = $inbox->page($user, min(50, max(5, (int) $request->query('per_page', 20))), $request->boolean('unread'));
        $items = $inbox->present($user, $page->items());
        $unread = Inbox::unreadCount($user);

        if ($request->expectsJson()) {
            return response()->json([
                'items' => $items,
                'unread' => $unread,
                'badge' => Inbox::badge($unread),
                'next_cursor' => $page->nextCursor()?->encode(),
            ]);
        }

        return view('comms::inbox', [
            'items' => $items,
            'unread' => $unread,
            'nextUrl' => $page->nextPageUrl(),
            'onlyUnread' => $request->boolean('unread'),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $unread = Inbox::unreadCount($request->user());

        return response()->json(['unread' => $unread, 'badge' => Inbox::badge($unread)])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $record = DatabaseNotification::query()->whereKey($notification)
            ->where('notifiable_type', $request->user()->getMorphClass())->where('notifiable_id', $request->user()->id)->first();

        abort_if($record === null, 404);

        $record->markAsRead();
        $data = (array) $record->data;
        // One subject, one count: opening an update reads its siblings about the same booking or order.
        if (is_string($data['collapse_key'] ?? null)) {
            Inbox::markSubjectRead($request->user(), $data['collapse_key']);
        }

        if ($request->expectsJson()) {
            $unread = Inbox::unreadCount($request->user());

            return response()->json(['read' => true, 'unread' => $unread, 'badge' => Inbox::badge($unread)]);
        }

        if ($request->boolean('open') && ($url = Inbox::localUrl($data['url'] ?? null)) !== null) {
            return redirect($url);
        }

        return back()->with('status', 'Marked as read.');
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $count = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson()
            ? response()->json(['marked' => $count, 'unread' => 0, 'badge' => null])
            : back()->with('status', $count === 1 ? 'One notification marked as read.' : "{$count} notifications marked as read.");
    }
}
