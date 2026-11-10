<?php

/*
|--------------------------------------------------------------------------
| Money (R-47): orders, Paystack, ledger, commission, earnings, payouts,
| refunds, coupons, credits, receipts. docs/R47_MONEY.md explains each one.
|--------------------------------------------------------------------------
| All amounts are integer minor units (cents for ZAR). Every figure below is
| an operator setting, not a market fact: the owner decides commission,
| refund windows, payout cadence, minimum payout and VAT.
*/

return [

    'currency' => env('MONEY_CURRENCY', env('TUTOR_CURRENCY', 'ZAR')),

    /*
    | Paystack. TEST MODE ONLY unless switched on twice: a secret key starting
    | "sk_live_" is refused (never sent, never used to check a webhook) unless
    | PAYSTACK_ALLOW_LIVE=true is ALSO set. Blank key = online payment is off:
    | paid items cannot be checked out and the checkout says so.
    */
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'allow_live' => (bool) env('PAYSTACK_ALLOW_LIVE', false),
        'timeout' => 10,
        // A started checkout is reused for this long ("Pay" twice reopens the same Paystack page).
        'attempt_ttl_minutes' => (int) env('PAYSTACK_ATTEMPT_TTL_MINUTES', 30),
        // Payment channels offered on the Paystack page (empty = Paystack's defaults for the account).
        'channels' => array_values(array_filter(explode(',', (string) env('PAYSTACK_CHANNELS', '')))),
    ],

    /*
    | Orders. An unpaid order holds its items (and any credit or coupon use)
    | for ttl_minutes, then the sweep verifies any open payment with Paystack
    | and expires it.
    */
    'orders' => [
        'ttl_minutes' => (int) env('MONEY_ORDER_TTL_MINUTES', 60),
        'max_lines' => 20,
    ],

    /*
    | Commission. The platform default percent applies when no versioned rule
    | exists in money_commission_rules (operators add rules; a rule is never
    | edited, a new version supersedes it). Placeholder setting, not a market
    | figure: see docs/R47_RESEARCH.md "Tutor economics".
    */
    'commission' => [
        'default_percent' => (float) env('MONEY_COMMISSION_PERCENT', 15),
    ],

    /*
    | Other charges a tutor bears on each sale, shown in the price preview.
    | Paystack's own processing fee is absorbed by the platform unless
    | pass_gateway_fee_percent is set (percent of the sale, e.g. 2.9).
    */
    'charges' => [
        'pass_gateway_fee_percent' => (float) env('MONEY_PASS_GATEWAY_FEE_PERCENT', 0),
        'fixed_fee_minor' => (int) env('MONEY_TUTOR_FIXED_FEE_MINOR', 0),
    ],

    /*
    | Earnings. Lessons: pending until the lesson is completed, plus a hold
    | for complaints. Courses, resources, bundles, rooms: pending until the
    | refund window ends. Days per purchasable type; "default" otherwise.
    */
    'earnings' => [
        'hold_hours_after_lesson' => (int) env('MONEY_LESSON_HOLD_HOURS', 24),
        'refund_window_days' => [
            'default' => (int) env('MONEY_REFUND_WINDOW_DAYS', 7),
            'course' => (int) env('MONEY_COURSE_REFUND_WINDOW_DAYS', 7),
            'resource' => (int) env('MONEY_RESOURCE_REFUND_WINDOW_DAYS', 7),
        ],
    ],

    /*
    | Payouts. Manual CSV batch is always available. Paystack Transfers is OFF
    | until the owner has a Registered Business with transfers enabled and sets
    | MONEY_PAYSTACK_TRANSFERS=true.
    */
    'payouts' => [
        'minimum_minor' => (int) env('MONEY_PAYOUT_MINIMUM_MINOR', 10000),
        'schedule_day' => (int) env('MONEY_PAYOUT_DAY', 1),
        'paystack_transfers' => (bool) env('MONEY_PAYSTACK_TRANSFERS', false),
    ],

    /*
    | Guardian approval for minors. Until the safety domain binds the real
    | age band, a buyer's age is unknown; hold_unknown_age decides whether an
    | unknown age is held for a guardian (true) or treated as an adult (false).
    */
    'guardian' => [
        'hold_unknown_age' => (bool) env('MONEY_HOLD_UNKNOWN_AGE', false),
    ],

    /*
    | Finance operators (refunds, credits, payouts, coupons funded by the
    | platform). Until the ops domain adds roles, a platform operator whose
    | email is listed here; an empty list means every platform operator.
    */
    'finance_operators' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONEY_FINANCE_OPERATOR_EMAILS', ''))))),

    /*
    | VAT on receipts: shown only when registered AND a number is set. Prices
    | are VAT-inclusive; the receipt shows the VAT inside the total.
    */
    'vat' => [
        'registered' => (bool) env('MONEY_VAT_REGISTERED', false),
        'number' => env('MONEY_VAT_NUMBER'),
        'rate_percent' => (float) env('MONEY_VAT_RATE', 15),
    ],

    /*
    | The operating company on receipts. A missing value is left off the
    | document; nothing is invented.
    */
    'operator' => [
        'legal_name' => env('MONEY_OPERATOR_LEGAL_NAME'),
        'trading_name' => env('MONEY_OPERATOR_TRADING_NAME', env('APP_NAME', 'Dot.Tutor')),
        'registration_number' => env('MONEY_OPERATOR_REGISTRATION'),
        'address' => env('MONEY_OPERATOR_ADDRESS'),
        'support_email' => env('MONEY_SUPPORT_EMAIL'),
    ],

    'receipts' => [
        'prefix' => env('MONEY_RECEIPT_PREFIX', 'TUT'),
    ],

];
