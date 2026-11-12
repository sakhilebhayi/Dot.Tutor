<?php

namespace App\Domain\Security\Services;

use App\Domain\Money\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Download my data" (R-47 security S-20, POPIA s23 access by the data subject; docs/R47_PRIVACY.md). Every row in
 * which the person is the subject, the author, the buyer, the seller, the learner or the guardian, by table, as JSON.
 * Left out on purpose: secrets (password and two-factor data, tokens and their hashes), storage paths, blind indexes
 * and encrypted bank details (the payout account is shown by its last four digits), and other people's rows (a
 * conversation's other messages, a report someone filed about this person, restrictions and their reasons, which stay
 * with safeguarding and are given on request after a safeguarding officer's review: owner decision D-1522).
 */
class PersonalDataExport
{
    /** table => the columns that point at the person */
    private const TABLES = [
        'learner_profiles' => ['user_id'],
        'user_languages' => ['user_id'],
        'student_preferences' => ['user_id'],
        'tutor_profiles' => ['user_id'],
        'tutor_sessions' => ['student_id'],
        'session_ratings' => ['rated_by'],
        'lesson_resources' => ['uploader_id'],
        'tutor_booking_series' => ['learner_id'],
        'tutor_package_purchases' => ['learner_id'],
        'tutor_session_participants' => ['learner_id'],
        'safety_age_records' => ['user_id'],
        'safety_guardian_links' => ['learner_id', 'guardian_id'],
        'safety_consents' => ['learner_id', 'guardian_id'],
        'safety_guardian_approvals' => ['learner_id'],
        'safety_guardian_invitations' => ['learner_id'],
        'safety_messages' => ['sender_id'],
        'learning_enrolments' => ['user_id'],
        'learning_certificates' => ['user_id'],
        'learning_course_reviews' => ['user_id'],
        'learning_resources' => ['owner_id'],
        'learning_resource_reviews' => ['reviewer_id'],
        'learning_attempts' => ['user_id'],
        'learning_flashcard_sets' => ['owner_id'],
        'learning_courses' => ['owner_id'],
        'money_orders' => ['buyer_id'],
        'money_receipts' => ['buyer_id'],
        'money_earnings' => ['seller_id'],
        'money_payouts' => ['payee_id'],
        'money_credit_grants' => ['user_id'],
        'money_entitlements' => ['user_id'],
        'money_coupons' => ['owner_id'],
        'community_room_members' => ['user_id'],
        'community_group_members' => ['user_id'],
        'community_posts' => ['author_id'],
        'classroom_attendees' => ['user_id'],
        'ai_interactions' => ['user_id'],
        'comms_preferences' => ['user_id'],
        'comms_user_settings' => ['user_id'],
        'notifications' => ['notifiable_id'],
    ];

    private const SECRET = '/password|token|secret|recovery|_hash$|^hash$|path$|blind_index|account_number|holder_name|recipient_code|authorization|original_body|signature|payload|evidence|ip$|user_agent|endpoint|p256dh|auth_key|prompt_hash/i';

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $tables = [];

        foreach (self::TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table)->where(function ($q) use ($columns, $user) {
                foreach ($columns as $column) {
                    $q->orWhere($column, $user->id);
                }
            });

            if ($table === 'notifications') {
                $query->where('notifiable_type', User::class);
            }

            $rows = $query->orderBy(Schema::hasColumn($table, 'id') ? 'id' : $columns[0])->limit(5000)->get()
                ->map(fn ($row) => array_filter((array) $row, fn ($value, $key) => ! preg_match(self::SECRET, (string) $key), ARRAY_FILTER_USE_BOTH))
                ->all();

            if ($rows !== []) {
                $tables[$table] = $rows;
            }
        }

        $account = PayoutAccount::query()->where('user_id', $user->id)->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'about' => 'Your personal information held by Dot.Tutor, by record type. Secrets, storage locations and full bank account numbers are left out; ask support for anything you think is missing.',
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            ],
            'payout_account' => $account ? ['bank' => $account->bank_name, 'account_ending' => $account->account_last4, 'type' => $account->account_type] : null,
            'records' => $tables,
        ];
    }
}
