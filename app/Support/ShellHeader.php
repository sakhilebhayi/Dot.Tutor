<?php

namespace App\Support;

use App\Domain\Comms\Inbox;
use App\Domain\Ops\Guardian\GuardianShell;
use App\Models\User;
use App\Support\Search\TutorSearch;
use Dot\Ui\PlatformSwitcher;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;
use Throwable;

/**
 * What the shared dot-ui header needs for the signed-in frame (layouts/partials/frame.blade.php), worked out once and in plain PHP so it can be
 * tested: the account menu (name, links, team switch, sign-out), the unread count for the bell, the search scope and the platform switcher model.
 * Nothing here decides who may see what; the routes behind every link do that.
 */
final class ShellHeader
{
    public function __construct(private readonly TutorSearch $search, private readonly GuardianShell $guardian) {}

    /** The search scope a shell stands for: the guardian view inside the learner frame is its own scope. */
    public function searchScope(string $shell): string
    {
        $scope = match ($shell) {
            'workspace' => 'teach',
            'operations' => 'ops',
            default => $shell === 'learner' && $this->guardian->applies() ? 'guardian' : 'learn',
        };

        $user = request()->user();

        return $user ? $this->search->resolveScope($user, $scope) : 'learn';
    }

    public function unread(User $user): int
    {
        return Inbox::unreadCount($user);
    }

    /**
     * The props of <x-dot.account-menu>. The team switch lives here (it was in the rail's footer): only a tutor or operator who really uses
     * teams (more than one) sees it, never a learner or a guardian ("Sipho's Team", R-47 D-2023).
     *
     * @return array<string, mixed>
     */
    public function account(User $user, string $shell): array
    {
        $showTeams = Jetstream::hasTeamFeatures() && $user->currentTeam
            && ($user->is_platform_operator || $user->tutorProfile()->exists())
            && $user->allTeams()->count() > 1;

        $unread = $this->unread($user);
        $links = [];

        if (Route::has('comms.inbox')) {
            $links[] = ['label' => 'Notifications', 'href' => route('comms.inbox'), 'badge' => $unread > 0 ? Inbox::badge($unread) : null, 'current' => request()->routeIs('comms.*')];
        }
        if ($shell === 'learner' && Route::has('learn.account')) {
            $links[] = ['label' => 'Your account', 'href' => route('learn.account'), 'current' => request()->routeIs('learn.account*')];
        }
        $links[] = ['label' => 'Profile and security', 'href' => route('profile.show'), 'current' => request()->routeIs('profile.show')];

        if ($showTeams) {
            $links[] = ['rule' => true];
            $links[] = ['label' => 'Team settings', 'href' => route('teams.show', $user->currentTeam->id), 'current' => request()->routeIs('teams.show')];
            if ($user->can('create', Jetstream::newTeamModel())) {
                $links[] = ['label' => 'Create new team', 'href' => route('teams.create'), 'current' => request()->routeIs('teams.create')];
            }
            $links[] = ['heading' => 'Switch team'];
            foreach ($user->allTeams() as $team) {
                $links[] = [
                    'label' => $team->name,
                    'current' => $user->isCurrentTeam($team),
                    'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $team->id]],
                ];
            }
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'team' => $showTeams ? $user->currentTeam->name : null,
            'avatar' => $user->profile_photo_path ? $user->profile_photo_url : null,
            'links' => $links,
            'signOut' => ['action' => route('logout')],
            'theme' => true,
        ];
    }

    /**
     * The switcher's view model. The package component builds it itself when none is given, and prints NOTHING if reading the configuration throws; a
     * shell that loses its platform switcher with no error is worse than one that says it cannot check, so a failure falls back to the plain
     * state (the same tiles InfoDot would open, with the "could not check" line) instead of an empty slot.
     *
     * @return array{company: ?string, name: ?string, model: array<string, mixed>}
     */
    public function switcher(User $user): array
    {
        $team = $user->currentTeam;
        // The current team only when it is a real InfoDot company (the one the entitlement check reads, EnsureCompanyEntitled); a personal or
        // unlinked team has no company, so the switcher shows its personal state and asks InfoDot nothing.
        $company = $team && ! $team->personal_team && filled($team->infodot_team_uuid) ? (string) $team->infodot_team_uuid : null;

        try {
            $model = PlatformSwitcher::fromConfig('tutor')->for($company);
        } catch (Throwable) {
            $model = PlatformSwitcher::plain('tutor');
        }

        return ['company' => $company, 'name' => $company ? $team->name : null, 'model' => $model];
    }
}
