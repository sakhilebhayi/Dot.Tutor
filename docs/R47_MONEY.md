# R-47 Money: orders, Paystack, ledger, commission, earnings, payouts, refunds, coupons, credits, receipts

Owner: money agent (wave 1). Code: `app/Domain/Money/**` (provider `App\Domain\Money\TutorMoneyServiceProvider`, views `app/Domain/Money/Views` as `money::`), `routes/tutor/money.php`, `config/money.php`, migrations `2026_11_10_5000xx`. Decisions D-500 to D-520. Research: `docs/R47_RESEARCH.md`, "Tutor economics". Patterns adapted (copied, not imported) from Dot.Ehail R-46 (`docs/R46_MONEY.md` there): Paystack client and test-mode guard, webhook inbox, immutable ledger with database triggers, reconciliation.

All amounts are integer minor units with an ISO currency. `App\Domain\Money\Money` is the value object: string decimal parsing, basis-point percentages, half-up rounding, allocation whose parts always sum to the whole, and formatting as `R 1 250.00` with a narrow no-break space (U+202F) between the symbol and the figure and between thousands (D-514).

## 1. Flow: a learner pays for a lesson

1. **Quote** (`POST /api/tutor/money/quote`): the price comes from the `Purchasable` (never from the client), with coupon and credit applied. Nothing is written. This is the "transparent price before committing" the brief asks for.
2. **Order** (`POST /api/tutor/money/orders`, `Idempotency-Key` header required, 428 without it): created once per key (a double click returns the same order, 201 then 200). The buyer row is locked while the order is priced, so credit reservations and duplicate checks cannot race. A one-off item already owned is refused (`already_purchased`); one already in another open order is refused with that order's id (`already_in_open_order`). Each line freezes its economics: price, tutor-funded discount, platform-funded discount, credit, cash, commission rule and percentage, other charges and the tutor's net. The coupon use is reserved. Guardian check (section 6): allowed orders are `pending_payment`, held ones `awaiting_guardian`. An unpaid order holds its items for `MONEY_ORDER_TTL_MINUTES` (60; a held one for 7 days).
3. **Pay** (`POST /api/tutor/money/orders/{order}/pay`, or the checkout page button): only the buyer (or whoever `PurchaseApprovals::canPayFor` allows). Each item is checked again (a lesson cancelled meanwhile closes the order). Nothing due at a card (free item, 100% coupon, credit) settles at once. Otherwise a Paystack hosted checkout is initialised under a row lock on the order, so a second click waits and gets the same page (one `initialize` call). The redirect is to Paystack's own hosts only.
4. **Return** (`GET /money/checkout/return?reference=`): the server asks Paystack's verify endpoint. **Paid only when Paystack says `success` and reference, amount and currency match.** A mismatch fails the payment and raises a critical finance issue.
5. **Settlement** (one transaction, exactly once): order `paid`, sale journal posted, a pending earning per tutor line, access granted (`money_entitlements`), coupon use confirmed, receipt issued (`TUT-00000001`, gapless). `OrderPaid` is dispatched after commit for other domains.

A payment that arrives for an order already paid (two tabs) is kept and listed as `duplicate_payment`; for an expired or cancelled order as `paid_after_close`. Nothing is silently absorbed.

## 2. Paystack

- `App\Domain\Money\Paystack\PaystackClient` is the only code that calls `api.paystack.co`: bearer secret key, 10 s timeouts, no redirects, https base URL pinned, typed results, failures as `PaymentGatewayException` with a message safe to show. Logs carry the endpoint, HTTP status and our reference only: never the key, request body, response body, payer email or checkout URL.
- **Test mode by default.** `PAYSTACK_SECRET_KEY=sk_test_...`. A `sk_live_...` key is refused (never sent, never used to check a webhook) unless `PAYSTACK_ALLOW_LIVE=true` is also set. No key = online payment off; the checkout says so plainly.
- **Webhook:** `POST /api/tutor/money/webhooks/paystack` (no session, no CSRF, throttled, 1 MB cap). HMAC-SHA512 of the raw body with the secret key, compared with `hash_equals`; a forged or missing signature is 401 and nothing is stored. The event is stored once (`money_webhook_events`; identity = hash of event + object id + reference + status + codes, since Paystack sends no event id) with customer, authorization, metadata and evidence stripped, then processed by the queued `ProcessPaystackWebhook` job (claimed atomically, 5 attempts, then a `webhook_failed` finance issue). Processing never trusts the body: `charge.success` -> verify; `refund.*` -> `GET /refund/{id}`; `transfer.*` -> `GET /transfer/verify/{reference}`; `charge.dispute.*` -> recorded for finance. A `charge.success` for a reference we never issued is verified and kept as `unmatched_payment` for review.
- Every Paystack call in tests is answered by `Tests\Support\FakePaystack` (`Http::preventStrayRequests()`).

## 3. Ledger

Double-entry, append-only (`money_ledger_journals`, `money_ledger_entries`): `Ledger::post` refuses an unbalanced or empty journal; posting the same idempotency key twice writes once; model guards and database triggers refuse UPDATE/DELETE (SQLite, PostgreSQL; MySQL attempted and logged if the host refuses); each entry is exactly one positive side (trigger/check); PostgreSQL also refuses to commit an unbalanced journal (deferred constraint trigger). Receipts are immutable the same way.

| Account | Nature | Meaning |
|---|---|---|
| `gateway_clearing` | asset | money held at Paystack (card receipts in; refunds, transfers, lost disputes out) |
| `platform_bank` | asset | the operator's bank (manual payouts) |
| `tutor_pending:{tutor}` | liability | earnings not yet available |
| `tutor_payable:{tutor}` | liability | earnings available to pay out |
| `learner_credit:{user}` | liability | promotional credit a learner can spend |
| `platform_commission` | revenue | commission |
| `platform_fees` | revenue | other charges to tutors (only when configured) |
| `platform_sales` | revenue | items the platform itself sells (seller null) |
| `gateway_fees` | expense | Paystack's fee on each charge (from verify's `fees`) |
| `promotions_expense` | expense | platform-funded coupons and credits |
| `refunds` | expense | the platform's share of revenue returned in refunds |
| `dispute_losses` | expense | card disputes lost |

| Event | Key | Lines |
|---|---|---|
| Order paid | `order-paid:{order}` | Dr gateway_clearing (cash - fee), Dr gateway_fees, Dr learner_credit (credit used), Dr promotions_expense (platform discounts); Cr tutor_pending (net), Cr platform_commission, Cr platform_fees, Cr platform_sales |
| Earning available | `earning-released:{earning}` | Dr tutor_pending, Cr tutor_payable (reverse for a pending refund reversal) |
| Refund processed | `refund:{refund}` | Cr gateway_clearing (cash), Cr promotions_expense and Cr learner_credit (their share back); Dr tutor_pending or tutor_payable (tutor share), Dr refunds (platform share and rounding) |
| Payout paid / reversed | `payout:{id}` / `payout-reversal:{id}` | Dr tutor_payable, Cr platform_bank (manual) or gateway_clearing (transfer), and the mirror |
| Credit granted / revoked | `credit:{grant}` | Dr promotions_expense, Cr learner_credit (mirror to revoke) |
| Dispute lost | `dispute-lost:{dispute}` | Dr dispute_losses, Cr gateway_clearing |

**Commission base** (D-503): the tutor's selling price after their own discount. Platform-funded discounts and credits are platform money: the tutor earns as if the learner paid in full. Paystack's fee is carried by the platform unless `MONEY_PASS_GATEWAY_FEE_PERCENT` is set.

**Invariants (tested):** every journal balances; total debits equal total credits; each tutor's pending earnings equal `tutor_pending:{id}` and available earnings equal `tutor_payable:{id}`; no learner's credit is negative; every paid order, processed refund and paid payout has its journal. `assertLedgerHealthy()` runs the reconciliation report after every flow in the feature tests; a 150-step random property test keeps the trial balance at zero.

## 4. Commission and the tutor's preview

`money_commission_rules`: a platform default (category null) and per-category overrides (`lesson`, `course`, `resource`, `bundle`, any category a purchasable reports), versioned: a new version supersedes the current one from its effective time, rules are never edited or deleted (model guard), and each order line stores the rule id and percentage it was priced with. Without any rule, `MONEY_COMMISSION_PERCENT` (15, a placeholder: see research) applies.

`POST /api/tutor/money/commission/preview` `{price_minor, category}` answers price, commission (percent, amount, rule version), other charges (each labelled), and net, with notes on who pays Paystack's fee and how coupons affect earnings. Marketplace and learning call it before an offering, course or resource is published.

## 5. Earnings and payouts

- One sale earning per tutor line: gross, commission, other charges, net, `pending`. Lessons (`on_delivery`): when the purchasable reports `delivered` (session `completed`), `release_after` = delivered + `MONEY_LESSON_HOLD_HOURS` (24) for complaints. Digital items (`after_window`): paid + refund window (`MONEY_REFUND_WINDOW_DAYS`, 7; per type for course and resource). The sweep makes them `available` and posts the journal. A cancelled paid lesson or a no-show goes to the finance exceptions list (`paid_item_cancelled`, `delivery_needs_review`); finance refunds it or releases it (`POST .../ops/orders/{order}/lines/{line}/release`, note required, audited). A line refunded in full while pending is cancelled with no money moving.
- Tutor views: `GET /api/tutor/money/tutor/earnings` (balances pending / available / paid out / lifetime and recent earnings with commission and charges), `GET .../tutor/statement?from=&to=` (totals: gross, commission, other charges, refunded, net), `GET .../tutor/payouts`, `GET .../tutor/payouts/{payout}` (payout statement: every earning in it), page `/money/earnings`.
- Bank account: `PUT /api/tutor/money/tutor/payout-account` (approved tutors). Holder and number encrypted (`encrypted` cast); only the last four digits are ever returned; an HMAC blind index flags one account registered by two tutors (critical issue).
- **Manual batch (always available):** `money:draft-payouts` on `MONEY_PAYOUT_DAY` (Monday) at 06:00, or `POST .../ops/payout-batches`. Per tutor: available earnings not in a payout (net of refund reversals), at least `MONEY_PAYOUT_MINIMUM_MINOR` (R100) and with a bank account, else carried forward with the reason. Export `GET .../ops/payout-batches/{batch}/export` (full numbers, finance only, `no-store`, formula-neutralised cells, audited); pay from the bank; `POST .../ops/payouts/{payout}/paid`. `failed` returns the earnings to the next batch (reversing the journal if it had been marked paid).
- **Paystack Transfers (built, off):** `MONEY_PAYSTACK_TRANSFERS=true` + a configured key. `POST .../ops/payouts/{payout}/transfer` creates the `basa` recipient once and the transfer with our reference; the payout is `processing` until `transfer/verify` says success (webhook or sweep).

## 6. Guardian approval for minors (brief section 15)

Money does not edit the safety domain. It defines its own seams and binds honest defaults with `bindIf`:
- `Money\Contracts\BuyerAge::bandFor(User): child|teen|adult|null`. Default `UnknownBuyerAge` returns null (money holds no date of birth).
- `Money\Contracts\PurchaseApprovals::assess(User, Order): ApprovalDecision` and `canPayFor(User, Order)`. Default `AgeBandPurchaseApprovals`: adults pass; a child or teen is **held** (`awaiting_guardian`, payment refused with "A parent or guardian must approve this purchase"); an unknown age passes unless `MONEY_HOLD_UNKNOWN_AGE=true` (D-509).
- The guardian's answer comes in through `Orders::recordGuardianDecision($order, $approved, $reference)`; refusal closes the order.
- **Integration (wave 2) must** bind `BuyerAge` to `Safety\AgeBand`, and `PurchaseApprovals` to an adapter over `Safety\Contracts\GuardianApprovals` + `SafeguardingGate::allows($buyer, 'pay', $order)`, with `canPayFor` letting the approving guardian pay.

## 7. Refunds, disputes, coupons, credits, receipts

- **Refunds:** `POST .../ops/orders/{order}/lines/{line}/refunds` `{amount_minor, reason, note}` with `Idempotency-Key`. Gate `money.finance` (platform operators, narrowed by `MONEY_FINANCE_OPERATOR_EMAILS`, until the ops domain adds the finance role); the service checks the gate again. Never more than the line's cash minus refunded and open refunds. Sent with Paystack's refund API; processed only when Paystack says processed (answer, or webhook then fetch); Paystack unreachable = stays `requested` and the sweep retries; refused = `failed` + issue. Processing posts the reversal, adds a negative earning for the tutor's share (pending or available), returns platform credit, marks the order `partially_refunded`/`refunded`, revokes access when the line is fully refunded, and dispatches `OrderRefunded`. Paystack's original fee is not returned by Paystack and stays a platform cost (D-508).
- **Disputes:** `charge.dispute.*` webhooks create or update a record and a critical issue; finance records the outcome (`POST .../ops/disputes/{dispute}`), and `lost` posts the loss once. Finance can also enter a dispute by payment reference.
- **Coupons:** tutor-owned (`/tutor/coupons`, approved tutors; valid only on that tutor's items; lowers the commission base) or platform-funded (`/ops/coupons`, finance; promotions expense). Percent or fixed (fixed allocated across eligible lines), start, expiry, total redemptions, per-person limit, minimum order, item type. Redemptions are reserved under a coupon row lock, confirmed on payment, released when the order closes unpaid.
- **Credits:** finance grants and revokes (`/ops/credits`, `/ops/credits/revoke`, idempotency key); the balance is the ledger account; open orders reserve what they use; credits do not expire (D-513). `GET /api/tutor/money/credits` shows balance, reserved and available.
- **Bundles:** `POST /api/tutor/money/tutor/bundles`: two or more of the tutor's own digital items (not lessons) at one price; buying grants every item (`bundle` source); the bundle's own earning releases after the refund window.
- **Receipts:** gapless `TUT-` numbers from a row-locked sequence, frozen snapshot, operator identity from `MONEY_OPERATOR_*` (a missing value is left off, never invented), VAT line ("Includes VAT at 15%") only when `MONEY_VAT_REGISTERED=true` **and** `MONEY_VAT_NUMBER` is set (prices are VAT-inclusive). HTML at `/money/receipts/{id}` with print styles (no PDF library: the browser's print-to-PDF), JSON at `/api/tutor/money/receipts/{id}`. Buyer and finance only.

## 8. Seams other domains use

| Seam | Implementation | How to use |
|---|---|---|
| `Money\Contracts\Purchasable` | owning domain | implement it (or an adapter) and register: `app(Purchasables::class)->register('course', fn (int $id) => ...)` in your provider's `boot()`. Types: booking, offering, package, course, resource, room, bundle. Money registers `booking` (adapter over `tutor_sessions`, `registerIfMissing`, so marketplace can register its own first) and `bundle`. |
| `Money\Contracts\Checkout` | `Services\Orders` | `createOrder($buyer, [['type'=>'course','id'=>7]], $idempotencyKey, $coupon, $useCredit)`, `startPayment($order, $actor)` -> `CheckoutStart` (redirect / settled / held_for_guardian / unavailable), `status($order)` |
| `Money\Contracts\EntitlementCheck` | `Services\Entitlements` | `owns($userId, $type, $id)`, `source(...)`: paid, free, gifted, bundle or null. Server side, before serving paid content. |
| Events | `OrderPaid($orderId)`, `OrderRefunded($orderId, $lineId, $refundId, $fullyRefunded)` | marketplace may confirm a booking on `OrderPaid`; learning may close access on `OrderRefunded` (access is already revoked in money_entitlements). |
| `PurchaseApprovals`, `BuyerAge`, `MoneyAudit` | defaults in `Seams/` | integration binds the safety adapters (section 6) and `Safety\Audit` for `MoneyAudit` (default: one structured log line, ids only). |

## 9. API (JSON; errors `{error, message}` with proper status)

Learner: `POST quote`, `GET|POST orders`, `GET orders/{uuid}`, `POST orders/{uuid}/pay`, `POST orders/{uuid}/cancel`, `GET credits`, `GET entitlements/check?type=&id=`, `GET receipts`, `GET receipts/{id}`. Tutor: `POST commission/preview`, `GET tutor/earnings`, `GET tutor/statement`, `GET tutor/payouts`, `GET tutor/payouts/{id}`, `GET|PUT tutor/payout-account`, `GET|POST tutor/coupons`, `POST tutor/coupons/{id}/deactivate`, `GET|POST tutor/bundles`. Finance (`can:money.finance`): `ops/orders`, `ops/orders/{uuid}`, `ops/orders/{uuid}/lines/{line}/refunds`, `.../release`, `ops/refunds`, `ops/finance-issues` (+ resolve), `ops/credits` (+ revoke), `ops/commission-rules`, `ops/coupons`, `ops/payout-batches` (list, draft, show, export), `ops/payouts/{id}/paid|failed|transfer`, `ops/disputes` (list, create, outcome), `ops/entitlements` (gift), `ops/reconciliation`, `ops/trial-balance`. All under `/api/tutor/money/`. Pages: `/money/checkout/{uuid}` (+ pay, cancel, return), `/money/receipts/{id}`, `/money/earnings`, `/money/ops`.

## 10. Automatic vs manual

| Automatic | Manual (finance) |
|---|---|
| `money:settle` every 5 min: verify open checkouts (a payer who closed the tab is credited), process stored webhooks, expire unpaid orders (after verifying), release due earnings, retry unsent refunds, re-check pending refunds and transfers | Every refund; dispute outcomes; resolving exceptions; releasing a reviewed no-show |
| `money:reconcile --record` daily 02:30 (internal checks; `--paystack-csv=` compares a Paystack transactions export) | Exporting the payout file, paying from the bank, marking paid/failed; pressing "transfer" when enabled |
| `money:draft-payouts` daily 06:00, acting on the payout day | Commission rules, platform coupons, credits, gifts |

## 11. Owner steps

1. Paystack: a South African Paystack business; test keys first (`PAYSTACK_SECRET_KEY=sk_test_...`). Live only with `sk_live_...` **and** `PAYSTACK_ALLOW_LIVE=true`. Webhook URL in the dashboard: `https://<host>/api/tutor/money/webhooks/paystack`.
2. Cron: the existing `schedule:run` and `queue:work --stop-when-empty` run everything here.
3. Commission: decide the default (`MONEY_COMMISSION_PERCENT`, 15 is a placeholder) and category rules; decide whether tutors carry Paystack's fee.
4. Refund policy wording and windows (`MONEY_REFUND_WINDOW_DAYS`, lesson hold hours); legal review of the CPA/ECTA position (research note).
5. Payouts: day, minimum; for automatic transfers a Registered Business with transfers enabled and a funded balance, then `MONEY_PAYSTACK_TRANSFERS=true`.
6. VAT: registration status and number; receipts: `MONEY_OPERATOR_LEGAL_NAME`, `..._REGISTRATION`, `..._ADDRESS`, `MONEY_SUPPORT_EMAIL`.
7. Finance operators: `MONEY_FINANCE_OPERATOR_EMAILS` until the ops roles land.
8. Dot.Billing / Dot.Finance: not called (no documented contract for Tutor); export the trial balance for the accountant.

## 12. Not built, and why

- **Recurring subscriptions** (D-511): Paystack Plans/Subscriptions need saved card authorisations, dunning and cancellation flows, and minors' recurring charges need a guardian mandate the safety domain does not have yet. Packages (prepaid lesson bundles, marketplace's `package` purchasable) and bundles cover the use honestly for now.
- **Saved cards / one-click repeat payment:** not needed for hosted checkout; avoids storing card tokens.
- **Paystack split payments (subaccounts):** would need every tutor onboarded as a Paystack subaccount; the ledger plus payouts keeps one integration and works for peer tutors without business registration.
- **Automatic dispute accounting from webhook wording:** the resolution vocabulary is not verified, so finance records the outcome.
- Notifications (receipt email, payout notices): comms domain, wave 2.
