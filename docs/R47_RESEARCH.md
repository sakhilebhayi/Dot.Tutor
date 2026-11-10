# R-47 research

## Tutor economics (money agent, 2026-11-10)

Desk research from memory of public pages; **every figure below is unverified** and must be checked against the provider's current terms before it is quoted to anyone.

| Platform | Model (unverified) | What Dot.Tutor takes from it |
|---|---|---|
| Preply | Takes the whole first (trial) lesson with a new student, then a commission that falls from about 33% to about 18% as the tutor's hours grow; tutors withdraw through PayPal, Payoneer, Skrill or bank | Commission rules are per category and versioned, so a sliding scale can be added later; we do not take a whole lesson |
| Superprof | Tutors keep their lesson fees; learners buy a membership to contact tutors; tutors can pay for visibility | Shows a no-commission alternative; ours is shown openly in the preview |
| Udemy | Instructor share differs by channel (much lower for sales Udemy drives than for the instructor's own coupon links); 30-day refund window; payouts monthly after the window | Refund window before earnings are available; tutor-funded coupons |
| Teachable | Creator platform: monthly plan plus a transaction fee on lower plans; creator sets prices, coupons, bundles | Bundles and tutor coupons; tutor controls price |
| Wyzant | A flat commission (about 25%) on lessons; tutors set rates | Simple, visible percentage on lessons |

South African payment habits (unverified): cards (debit more than credit) and instant EFT are the main online methods; QR wallets (SnapScan, Zapper) are common in person; for school learners a parent usually pays; much private tutoring is still paid in cash or EFT directly to the tutor, so the platform's value must be safety, receipts and reliability, not only matching.

Paystack features usable here (South Africa; unverified for availability on the owner's account): hosted checkout via `transaction/initialize` and `transaction/verify` (used); card and EFT channels; Refunds API (used); Transfers to South African bank accounts for Registered Businesses with a funded balance (built, off); Disputes API and `charge.dispute.*` webhooks (recorded); Plans/Subscriptions (not built, D-511); Subaccounts and split payments (not used: every tutor would need onboarding as a subaccount). Local card fees are reported around 2.9% plus a small fixed amount; the platform absorbs them by default (D-502).

Legal points for the owner's adviser (not compliance claims): the Electronic Communications and Transactions Act gives a cooling-off period for many electronic transactions, with exclusions; the Consumer Protection Act governs cancellations and refunds; VAT registration depends on turnover and on whether the platform is principal or agent for tutor sales; POPIA treats children's information as special and requires a competent person's consent. The refund windows, the agent model and the receipt wording follow these as configurable settings, not as verified conclusions.
