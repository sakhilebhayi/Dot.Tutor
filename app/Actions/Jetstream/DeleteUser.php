<?php

namespace App\Actions\Jetstream;

use App\Enums\SessionStatus;
use App\Models\Team;
use App\Models\TutorSession;
use App\Models\User;
use App\Services\TutorModeration;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->preserveMarketplaceHistory($user);
            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->notifications()->delete();
            $user->delete();
        });
    }

    /**
     * Deleting an account must not erase other people's history. The foreign
     * keys now null the pointer instead of cascading (see the 2026-10-18
     * protect_history migration); here we also free the calendar: a tutor's
     * profile is taken off the marketplace (kept, because their students'
     * sessions hang off it) and future open sessions on either side are
     * cancelled. Past sessions, ratings and files are left exactly as they are.
     */
    protected function preserveMarketplaceHistory(User $user): void
    {
        if ($profile = $user->tutorProfile) {
            app(TutorModeration::class)->closeAccount($profile);
        }

        TutorSession::query()
            ->where('student_id', $user->id)
            ->whereIn('status', SessionStatus::holdingTime())
            ->where('starts_at', '>', now())
            ->update(['status' => SessionStatus::Cancelled->value]);
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }
}
