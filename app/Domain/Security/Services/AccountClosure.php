<?php

namespace App\Domain\Security\Services;

use App\Domain\Safety\Audit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Closing an account that other people's records depend on (R-47 security S-21, D-1520). Money records (orders,
 * receipts, earnings, payouts, the ledger) must stay for tax and accounting, and safeguarding records (reports,
 * restrictions, incidents, integrity cases, screening) must stay for the people they protect, so the database
 * refuses to delete such a person. Instead the account is closed: nobody can sign in to it again, the contact details
 * and the profile data that only served the person are erased, and their name is replaced by "Closed account" unless
 * a safeguarding record is about them, in which case the name stays with that record and the closing email address is
 * kept in the append-only audit log, so a moderator can recognise the person if they return.
 */
class AccountClosure
{
    /** Rows that only describe this person and serve no one else: deleted on closure. table => user column */
    private const PERSONAL = [
        'learner_profiles' => 'user_id',
        'user_languages' => 'user_id',
        'student_preferences' => 'user_id',
        'comms_preferences' => 'user_id',
        'comms_user_settings' => 'user_id',
        'comms_push_subscriptions' => 'user_id',
        'learning_resource_recommendations' => 'user_id',
        'learning_flashcard_reviews' => 'user_id',
        'notifications' => 'notifiable_id',
        'personal_access_tokens' => 'tokenable_id',
        'sessions' => 'user_id',
        'team_user' => 'user_id',
    ];

    /** Records about the person that safeguarding keeps (the name stays with them). table => columns */
    private const SAFEGUARDING = [
        'safety_reports' => ['reported_user_id'],
        'safety_account_restrictions' => ['user_id'],
        'safety_incidents' => ['learner_id'],
        'safety_integrity_cases' => ['subject_user_id'],
        'safety_moderation_actions' => ['target_user_id'],
        'safety_screening_checks' => ['user_id'],
    ];

    public function close(User $user): void
    {
        DB::transaction(function () use ($user) {
            $concern = $this->hasSafeguardingRecords($user);
            $originalEmail = (string) $user->email;

            foreach (self::PERSONAL as $table => $column) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $query = DB::table($table)->where($column, $user->id);

                if ($table === 'notifications') {
                    $query->where('notifiable_type', User::class);
                }

                if ($table === 'personal_access_tokens') {
                    $query->where('tokenable_type', User::class);
                }

                $query->delete();
            }

            DB::table('safety_guardian_links')->where('status', 'active')
                ->where(fn ($q) => $q->where('learner_id', $user->id)->orWhere('guardian_id', $user->id))
                ->update(['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'account_closed', 'updated_at' => now()]);

            $user->deleteProfilePhoto();

            $user->forceFill([
                'name' => $concern ? $user->name : 'Closed account',
                'email' => 'closed-'.$user->id.'-'.Str::lower(Str::random(12)).'@closed.invalid',
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => Str::random(60),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'current_team_id' => null,
            ])->save();

            Audit::record($user, 'account.closed', $user, array_filter([
                'kept_for' => $concern ? 'safeguarding and accounting records' : 'accounting records',
                'closing_email' => $concern ? $originalEmail : null,
            ]));
        });
    }

    public function hasSafeguardingRecords(User $user): bool
    {
        foreach (self::SAFEGUARDING as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && DB::table($table)->where($column, $user->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
