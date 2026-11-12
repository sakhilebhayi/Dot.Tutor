<?php

namespace App\Domain\Community;

use App\Domain\Academic\Translations;
use App\Domain\Community\Listeners\ActivatePaidRoomPlaces;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Policies\PostPolicy;
use App\Domain\Community\Support\EloquentRoomDirectory;
use App\Domain\Community\Support\RoomPurchasable;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Purchasables;
use App\Domain\Safety\Contracts\RoomDirectory;
use App\Domain\Safety\Support\SafeguardingDenied;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * R-47 community domain (docs/R47_COMMUNITY.md). Implements safety's RoomDirectory seam (community owns rooms),
 * registers the "room" purchasable with money, the "community.post" translatable type with academic's
 * Translations (the AI translation endpoint then works for posts), and the "room_post" report alias with safety.
 * Everything is registered here, never in route files (route:cache).
 */
class TutorCommunityServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        // Community owns rooms: replace safety's honest null directory (it was bound with bindIf).
        $this->app->singleton(RoomDirectory::class, EloquentRoomDirectory::class);
    }

    public function boot(): void
    {
        Gate::policy(Post::class, PostPolicy::class);
        Gate::define('community.moderate', fn (User $user) => (bool) $user->is_platform_operator);

        $this->app->make(Purchasables::class)->register('room', function (int $id) {
            $room = Room::query()->find($id);

            return $room !== null && $room->isTutorRoom() ? new RoomPurchasable($room) : null;
        });

        $this->app->make(Translations::class)->register('community.post', Post::class, ['title', 'body']);

        config(['safety.reports.reportable.room_post' => Post::class]);

        Event::listen(OrderPaid::class, ActivatePaidRoomPlaces::class);

        RateLimiter::for('community-writes', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('community-polls', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // JSON errors for the community API answer {error, message} like the rest of R-47.
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (ValidationException $e, Request $request) {
                if ($request->is('api/tutor/community/*')) {
                    return response()->json(['error' => 'validation_failed', 'message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
                }
            });
            $handler->renderable(function (HttpExceptionInterface $e, Request $request) {
                if ($request->is('api/tutor/community/*') && ! $e instanceof SafeguardingDenied && ! $e instanceof Support\CommunityRefusal) {
                    return response()->json(['error' => Str::snake(str_replace('HttpException', '', class_basename($e))) ?: 'http_error', 'message' => $e->getMessage() ?: 'Request refused.'],
                        $e->getStatusCode(), $e->getHeaders());
                }
            });
        }
    }
}
