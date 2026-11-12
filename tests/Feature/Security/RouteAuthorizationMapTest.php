<?php

namespace Tests\Feature\Security;

use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseMaterial;
use App\Domain\Learning\Models\Flashcard;
use App\Domain\Learning\Models\FlashcardSet;
use App\Domain\Learning\Models\Lesson;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Ops\Access\RoutePermissions;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Models\GuardianLink;
use App\Models\Team;
use App\Models\TutorBlockedDate;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-47 security, scope 1: authorisation, checked mechanically over EVERY route (docs/R47_SECURITY.md section 3).
 *
 * 1. Every route is classified: public (listed below with a reason), guest-only, or signed-in; operator routes are
 *    also in the ops permission matrix (tests/Feature/Ops/OperatorRouteMatrixTest checks each role against it).
 * 2. A guest gets nothing from any route that is not public: never a 2xx, never a 5xx.
 * 3. A stranger who is at once an adult learner, an approved tutor and a guardian of their own child calls every
 *    route that carries an id with someone else's ids (a world built by the Demo seeders, plus one row of every bound
 *    model nobody seeded). Nothing may succeed (no 2xx) and nothing may crash (no 5xx), except the SHARED routes,
 *    which act on things published to every signed-in person by design (each with its reason); those may not crash.
 */
class RouteAuthorizationMapTest extends TestCase
{
    use RefreshDatabase;

    /** Routes a guest may reach. Name (or "METHOD uri" when unnamed) => why. */
    private const PUBLIC = [
        'GET /' => 'landing page',
        'GET up' => 'health check',
        'GET auth/ecosystem' => 'legacy SSO link: redirects to login without reading the token',
        'ecosystem.auth' => 'InfoDot SSO hand-off (token in a POST body, redeemed server-to-server)',
        'money.webhooks.paystack' => 'Paystack webhook (HMAC-SHA512 signature, then re-verified with Paystack)',
        'learning.certificates.verify' => 'public certificate check by code (48-bit code, initials only, throttled)',
        'cookies' => 'cookie policy',
        'policy.show' => 'privacy policy',
        'terms.show' => 'terms',
        'csp.report' => 'browser CSP reports (no session, throttled)',
        'academic.locale.index' => 'interface language chooser',
        'academic.locale.update' => 'interface language choice (session only, throttled)',
        'GET livewire/livewire.js' => 'Livewire asset',
        'GET livewire/livewire.min.js.map' => 'Livewire asset',
        'livewire.preview-file' => 'Livewire temporary upload preview (signed URL)',
        'default.livewire.update' => 'Livewire endpoint (components authorise themselves; checksummed snapshots)',
        'livewire.upload-file' => 'Livewire upload endpoint (signed URL, throttled)',
        'register' => 'closed: redirects to login',
        'sanctum.csrf-cookie' => 'CSRF cookie',
        'teach.handle' => 'a tutor\'s public link (redirects to the public storefront)',
        // Public discovery (R-47 polish D-2003): approved tutors only, tutor free text through view_contact (contact
        // details hidden from guests), no learner data; booking, paying, messaging and reporting need sign-in.
        'marketplace.find' => 'public tutor search: approved tutors only, guests rate-limited (tutor-guest-search)',
        'match.search' => 'the find form\'s redirect to /find-a-tutor (reads only the chosen level; rate-limited)',
        'marketplace.tutors.show' => 'an approved tutor\'s public storefront (404 otherwise; contact details hidden from guests)',
        'marketplace.tutors.photo' => 'an approved tutor\'s profile photo (404 for anyone else\'s unapproved profile)',
        'boost.browser-logs' => 'Laravel Boost, development only (not installed with --no-dev)',
    ];

    /** Signed-in routes that act on something published to every signed-in person by design. Name => why. */
    private const SHARED = [
        'academic.offerings' => 'reference data: subjects offered at a level',
        'academic.topics' => 'reference data: topics of a subject offering',
        'tutors.show' => 'approved tutor\'s public profile (legacy page)',
        'marketplace.api.tutors.show' => 'approved tutor\'s storefront (JSON)',
        'marketplace.api.tutors.availability' => 'an approved tutor\'s open hours (no learner data)',
        'marketplace.api.tutors.offerings' => 'an approved tutor\'s published offerings',
        'marketplace.api.tutors.group-sessions' => 'an approved tutor\'s scheduled group lessons (counts, no names)',
        'marketplace.api.tutors.reviews' => 'published reviews',
        'learning.courses.show' => 'published course page',
        'api.learning.courses.show' => 'published course page (JSON)',
        'learning.courses.quote' => 'price of a published course',
        'api.learning.courses.quote' => 'price of a published course (JSON)',
        'learning.courses.reviews.index' => 'published course reviews',
        'api.learning.courses.reviews.index' => 'published course reviews (JSON)',
        'learning.courses.enrol' => 'enrolling in a free published course; paid ones go through checkout',
        'api.learning.courses.enrol' => 'enrolling in a free published course (JSON)',
        'learning.courses.progress' => 'the caller\'s own progress in a course they enrolled in',
        'api.learning.courses.progress' => 'the caller\'s own progress (JSON)',
        'learning.courses.upgrade' => 'the caller\'s own enrolment moved to the latest version',
        'api.learning.courses.upgrade' => 'the caller\'s own enrolment upgrade (JSON)',
        'learning.assessments.take' => 'a quiz of a course the caller enrolled in (answer keys never sent)',
        'api.learning.assessments.take' => 'quiz page (JSON)',
        'learning.assessments.attempts.store' => 'starting the caller\'s own attempt on a quiz they may take',
        'api.learning.assessments.attempts.store' => 'starting an own attempt (JSON)',
        'learning.library.show' => 'a published library resource page',
        'api.learning.library.show' => 'a published library resource (JSON)',
        'learning.library.reports.store' => 'reporting a published resource',
        'learning.library.recommend' => 'recommending (or un-recommending) a published resource',
        'api.learning.library.recommend' => 'recommending a published resource (JSON)',
        'api.learning.library.reports.store' => 'reporting a published resource (JSON)',
        'rooms.show' => 'an adult subject room (the caller\'s own audience)',
        'community.api.show' => 'an adult subject room (JSON)',
        'rooms.activity' => 'activity of a room the caller may read',
        'community.api.activity' => 'activity of a room (JSON)',
        'rooms.ask' => 'the ask form of a room the caller may join',
        'community.api.ask' => 'the ask form (JSON)',
        'rooms.join' => 'joining an open adult room (gate: join_room)',
        'community.api.join' => 'joining an open adult room (JSON)',
        'rooms.leave' => 'the caller leaving a room (no-op when not a member)',
        'community.api.leave' => 'leaving a room (JSON)',
        'rooms.levels.show' => 'rooms at a level (reference data)',
        'community.api.levels.show' => 'rooms at a level (JSON)',
        'rooms.open' => 'opening the caller\'s audience room for a subject',
        'community.api.open' => 'opening a subject room (JSON)',
        'rooms.posts.show' => 'a visible post in a room the caller may read',
        'community.api.posts.show' => 'a visible post (JSON)',
        'rooms.posts.history' => 'edit history of a visible post',
        'community.api.posts.history' => 'edit history (JSON)',
        'rooms.groups.show' => 'a study group in a room the caller may read',
        'community.api.groups.show' => 'a study group (JSON)',
        'rooms.groups.join' => 'joining an open study group in a room the caller is in',
        'rooms.groups.leave' => 'the caller leaving a group (no-op when not a member)',
        'community.api.groups.join' => 'joining an open study group (JSON)',
        'community.api.groups.leave' => 'leaving a group (JSON)',
        'rooms.sessions.calendar' => 'calendar file of a study session in a room the caller may read',
        'community.api.sessions.calendar' => 'calendar file (JSON route)',
        'learning.learn' => 'a published course\'s preview lessons (others need an enrolment: tests/Feature/Learning/ProtectedDeliveryTest)',
        'api.learning.learn' => 'preview lessons (JSON)',
        'learning.lessons.complete' => 'the caller\'s own progress in a course they enrolled in',
        'api.learning.lessons.complete' => 'own progress (JSON)',
        'learn.book' => 'the booking page of an approved tutor\'s published offering',
        'learning.lessons.position' => 'the caller\'s own reading position in a course they enrolled in',
        'api.learning.lessons.position' => 'own reading position (JSON)',
        'learning.courses.reviews.store' => 'reviewing a course the caller enrolled in',
        'api.learning.courses.reviews.store' => 'reviewing an enrolled course (JSON)',
        'marketplace.api.reviews.report' => 'reporting a published review',
        'rooms.posts.reply' => 'answering in a room the caller joined',
        'community.api.posts.reply' => 'answering in a joined room (JSON)',
        'rooms.posts.resolve' => 'an approved tutor in the room may mark a question resolved (community D-7xx, R47_COMMUNITY section 7)',
        'community.api.posts.resolve' => 'marking resolved as a tutor in the room (JSON)',
        'classroom.api.leave' => 'the caller leaving a classroom (no-op when not in it)',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        foreach (['learning', 'safety', 'marketplace', 'classroom', 'lesson_resources', 'local'] as $disk) {
            Storage::fake($disk);
        }
    }

    private static function key(RoutingRoute $route): string
    {
        return $route->getName() ?? (collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first().' '.$route->uri());
    }

    private static function isSignedIn(RoutingRoute $route): bool
    {
        return collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate:')));
    }

    private static function isGuestOnly(RoutingRoute $route): bool
    {
        return collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'guest') || str_contains($m, 'RedirectIfAuthenticated')));
    }

    /** @return list<RoutingRoute> */
    private function routes(): array
    {
        return array_values(Route::getRoutes()->getRoutes());
    }

    public function test_every_route_is_public_by_name_guest_only_or_behind_sign_in(): void
    {
        $unclassified = collect($this->routes())
            ->reject(fn (RoutingRoute $r) => isset(self::PUBLIC[self::key($r)]) || self::isSignedIn($r) || self::isGuestOnly($r))
            ->map(fn (RoutingRoute $r) => self::key($r).' '.$r->uri())->values()->all();

        $this->assertSame([], $unclassified, "Classify these routes (public with a reason in PUBLIC, or behind sign-in):\n".implode("\n", $unclassified));

        $stale = collect(array_keys(self::PUBLIC))
            ->reject(fn ($k) => str_starts_with($k, 'boost.')) // registered only in local development
            ->reject(fn ($k) => collect($this->routes())->contains(fn ($r) => self::key($r) === $k))->values()->all();
        $this->assertSame([], $stale, 'PUBLIC lists routes that no longer exist');

        $sharedStale = collect(array_keys(self::SHARED))
            ->reject(fn ($k) => Route::has($k))->values()->all();
        $this->assertSame([], $sharedStale, 'SHARED lists routes that no longer exist');
    }

    public function test_a_guest_gets_nothing_from_any_route_that_is_not_public(): void
    {
        $this->seedWorld();
        $bad = [];

        foreach ($this->routes() as $route) {
            if (isset(self::PUBLIC[self::key($route)]) || self::isGuestOnly($route)) {
                continue;
            }

            [$method, $uri] = $this->callable($route);
            if ($uri === null) {
                continue;
            }

            $status = $this->json($method, $uri)->getStatusCode();

            if ($status < 300 || $status >= 500) {
                $bad[] = "$status $method ".self::key($route)." /$uri";
            }
        }

        $this->assertSame([], $bad, "A guest reached these:\n".implode("\n", $bad));
    }

    public function test_a_stranger_reaches_nobody_elses_things(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedWorld();
        $attacker = $this->attacker();
        $hits = [];
        $unexercised = [];

        // Reads first, so a write cannot change what a later read sees.
        $routes = collect($this->routes())->filter(fn (RoutingRoute $r) => $r->parameterNames() !== [] && self::isSignedIn($r))
            ->sortBy(fn (RoutingRoute $r) => in_array('GET', $r->methods(), true) ? 0 : 1)->values();

        foreach ($routes as $route) {
            [$method, $uri] = $this->callable($route);

            if ($uri === null) {
                $unexercised[] = self::key($route);

                continue;
            }

            $status = $this->attempt($attacker, $method, $uri);
            $shared = isset(self::SHARED[self::key($route)]);
            if (getenv('SWEEP_LOG')) {
                file_put_contents(getenv('SWEEP_LOG'), "$status $method ".self::key($route)." /$uri\n", FILE_APPEND);
            }

            if ($status >= 500 || (! $shared && $status < 300)) {
                $hits[] = "$status $method ".self::key($route)." /$uri";
            }
        }

        $this->assertSame([], $hits, "A stranger got these with someone else's ids:\n".implode("\n", $hits));
        $this->assertSame([], $unexercised, 'Routes the sweep could not build a URL for');
        $this->assertGreaterThan(300, $routes->count(), 'The sweep must cover the domains\' routes');
    }

    /**
     * Call as the stranger. A 422 may only mean the body was empty, which would hide a missing ownership check
     * behind validation: the body is then filled from the validation errors (up to three rounds) and sent again.
     */
    private function attempt(User $attacker, string $method, string $uri): int
    {
        $body = [];

        for ($round = 0; $round < 4; $round++) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAs($attacker)->json($method, $uri, $body);

            if ($response->getStatusCode() !== 422 || $round === 3) {
                return $response->getStatusCode();
            }

            $errors = array_keys((array) $response->json('errors'));
            $added = false;

            foreach ($errors as $field) {
                if (str_contains($field, '.') || array_key_exists($field, $body)) {
                    continue;
                }

                $body[$field] = $this->plausible($field);
                $added = true;
            }

            if (! $added) {
                return 422;
            }
        }

        return 422;
    }

    private function plausible(string $field): mixed
    {
        return match (true) {
            (bool) preg_match('/(^|_)(id|ids)$/', $field) => str_ends_with($field, 'ids') ? [1] : 1,
            (bool) preg_match('/accept|consent|confirm|agree|approve|publish|rights|good_faith/', $field) => true,
            (bool) preg_match('/decision|outcome|action|verdict/', $field) => 'approve',
            (bool) preg_match('/rating|stars|score|position|seconds|minutes|percent|amount|weeks|capacity/', $field) => 4,
            (bool) preg_match('/date|_at$|starts|ends/', $field) => now()->addDays(3)->setTime(10, 0)->toIso8601String(),
            (bool) preg_match('/email/', $field) => 'someone@example.test',
            (bool) preg_match('/url|link/', $field) => 'https://meet.google.com/abc-defg-hij',
            default => 'A long enough test text for the validation rules here.',
        };
    }

    public function test_operator_routes_refuse_every_non_operator(): void
    {
        $this->seedWorld();
        $attacker = $this->attacker();
        $bad = [];

        foreach ($this->routes() as $route) {
            if (! RoutePermissions::isMapped($route->getName()) || ! self::isSignedIn($route)) {
                continue;
            }

            [$method, $uri] = $this->callable($route);
            if ($uri === null) {
                continue;
            }

            $this->app['auth']->forgetGuards();
            $status = $this->actingAs($attacker)->json($method, $uri)->getStatusCode();

            if ($status !== 403 && $status !== 404) {
                $bad[] = "$status $method ".self::key($route)." /$uri";
            }
        }

        $this->assertSame([], $bad, "A non-operator was not refused:\n".implode("\n", $bad));
    }

    /**
     * The other people: the Demo seeders' learners, children, guardians, tutors, rooms, courses, orders and
     * classrooms, then one row of every model a route binds that nobody seeded.
     */
    private function seedWorld(): void
    {
        foreach (['SubjectCatalogueSeeder', 'AcademicReferenceSeeder', 'DemoStudentSeeder', 'DemoTutorWorkspaceSeeder',
            'DemoLearningSeeder', 'DemoCommunitySeeder', 'DemoClassroomSeeder', 'DemoOpsSeeder'] as $seeder) {
            $this->seed('Database\\Seeders\\'.$seeder);
        }

        $victim = User::query()->orderBy('id')->firstOrFail();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $victim->id,
            'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($this->routes() as $route) {
            foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
                $class = $parameter->getType()?->getName();

                if ($class && class_exists($class) && ! $class::query()->exists()) {
                    $this->fill($class);
                }
            }
        }
    }

    /** One plausible row of a model, every required column filled from the schema (foreign keys from existing rows). */
    private function fill(string $class): Model
    {
        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();
        $foreign = collect(Schema::getForeignKeys($table))->mapWithKeys(fn ($fk) => [$fk['columns'][0] => $fk['foreign_table']]);
        $casts = $model->getCasts();
        $attributes = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];

            if ($column['auto_increment'] || in_array($name, ['created_at', 'updated_at'], true)) {
                continue;
            }

            if ($column['nullable'] && ! isset($foreign[$name])) {
                continue;
            }

            if ($column['default'] !== null && ! isset($foreign[$name])) {
                continue;
            }

            $cast = $casts[$name] ?? null;
            $type = strtolower((string) $column['type_name']);

            $attributes[$name] = match (true) {
                isset($foreign[$name]) => DB::table($foreign[$name])->orderBy('id')->value('id') ?? $this->fill($this->modelFor($foreign[$name]))->getKey(),
                is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class) => $cast::cases()[0],
                in_array($cast, ['array', 'json', 'collection', 'encrypted:array', 'encrypted:json', 'object'], true) => [],
                in_array($cast, ['bool', 'boolean'], true) => false,
                str_contains($type, 'int') || str_contains($type, 'numeric') || str_contains($type, 'decimal') || str_contains($type, 'real') || str_contains($type, 'float') => 1,
                str_contains($type, 'date') || str_contains($type, 'time') => now(),
                str_contains($type, 'json') => [],
                str_contains($name, 'email') => Str::random(8).'@example.test',
                $name === 'currency' => 'ZAR',
                // PostgreSQL enforces varchar lengths that SQLite ignores.
                default => Str::lower(Str::random(min(12, preg_match('/\((\d+)\)/', (string) $column['type'], $m) ? (int) $m[1] : 12))),
            };
        }

        Model::withoutEvents(fn () => $model->forceFill($attributes)->save());

        return $model;
    }

    private function modelFor(string $table): string
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! str_contains($file->getPathname(), '/Models/') || $file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path().'/'));

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract() && (new $class)->getTable() === $table) {
                return $class;
            }
        }

        $this->fail("No model for table $table");
    }

    /** An adult learner, an approved tutor and the guardian of their own child, with nothing of anyone else's. */
    private function attacker(): User
    {
        $attacker = User::factory()->withPersonalTeam()->create(['name' => 'Mallory Stranger', 'email_verified_at' => now()]);
        AgeRecord::create(['user_id' => $attacker->id, 'birth_date' => now()->subYears(35)->toDateString(), 'source' => 'operator_verified', 'recorded_by_id' => $attacker->id]);
        TutorProfile::create(['user_id' => $attacker->id, 'hourly_rate' => 200, 'status' => 'approved']);

        $child = User::factory()->create(['name' => 'Mallory Child']);
        AgeRecord::create(['user_id' => $child->id, 'birth_date' => now()->subYears(10)->toDateString(), 'source' => 'operator_verified', 'recorded_by_id' => $attacker->id]);
        GuardianLink::query()->forceCreate(['learner_id' => $child->id, 'guardian_id' => $attacker->id, 'relationship' => 'parent', 'status' => GuardianLink::ACTIVE]);

        return $attacker;
    }

    /** @return array{0: string, 1: ?string} the method and a URI with another person's ids, or null when unbuildable */
    private function callable(RoutingRoute $route): array
    {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $types = [];

        foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
            $types[$parameter->getName()] = $parameter->getType()?->getName();
        }

        $values = [];

        foreach ($route->parameterNames() as $name) {
            $value = $this->valueFor($route, $name, $types[$name] ?? null);

            if ($value === null) {
                return [$method, null];
            }

            $values[$name] = $value;
        }

        $uri = $route->uri();
        foreach ($values as $name => $value) {
            $uri = preg_replace('/\{'.$name.'\??\}/', rawurlencode((string) $value), $uri);
        }

        return [$method, preg_replace('/\{\w+\?\}/', '', $uri)];
    }

    private function valueFor(RoutingRoute $route, string $name, ?string $class): string|int|null
    {
        $firstOther = fn (string $model, string $ownerColumn = 'id') => $model::query()
            ->whereNotIn($ownerColumn, User::query()->where('name', 'like', 'Mallory%')->pluck('id'))
            ->orderBy((new $model)->getKeyName())->first()?->getRouteKey();

        if ($class !== null && class_exists($class)) {
            return match ($class) {
                User::class => $firstOther(User::class),
                TutorProfile::class => $firstOther(TutorProfile::class, 'user_id'),
                default => $class::query()->orderBy((new $class)->getKeyName())->first()?->getRouteKey(),
            };
        }

        // Plain integer ids (the controller loads and authorises them itself).
        $byName = [
            'lesson' => Lesson::class,
            'material' => CourseMaterial::class,
            'offering' => TutorOffering::class,
            'course' => Course::class,
            'bank' => QuestionBank::class,
            'assessment' => Assessment::class,
            'question' => Question::class,
            'set' => FlashcardSet::class,
            'card' => Flashcard::class,
        ];

        if (isset($byName[$name])) {
            $model = $byName[$name];

            return $model::query()->orderBy('id')->value('id') ?? $this->fill($model)->getKey();
        }

        return match ($name) {
            'learner' => User::query()->whereIn('id', TutorSession::query()->whereNotNull('student_id')->select('student_id'))->orderBy('id')->value('id'),
            'session' => TutorSession::query()->orderBy('id')->value('id'),
            'notification' => DB::table('notifications')->orderBy('created_at')->value('id'),
            'invitation' => str_starts_with($route->uri(), 'team-invitations')
                ? (DB::table('team_invitations')->value('id') ?? 1) : GuardianInvitation::query()->orderBy('id')->value('id'),
            'team' => Team::query()->orderBy('id')->value('id'),
            'window' => DB::table('tutor_availability_windows')->orderBy('id')->value('id'),
            'blockedDate' => TutorBlockedDate::query()->orderBy('id')->value('id') ?? 1,
            'action' => 'cancel',
            'decision' => 'approve',
            'type' => 'course',
            'field' => 'title',
            'id' => 1,
            'kind' => 'lesson',
            'item' => 1,
            'code' => 'unknown-code',
            'handle' => 'unknown-handle',
            'token' => Str::random(40),
            'path' => 'private/x.txt',
            'filename' => 'x.txt',
            'resource' => 'curricula',
            default => null,
        };
    }
}
