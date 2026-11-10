# R-47 decisions log

Format: `D-n (agent, date): decision. Why.` Each agent appends in its own number block (docs/R47_ARCHITECTURE.md section 1).

D-1 (coordinator, 2026-11-10): Build in waves of parallel agents on worktrees off the trunk `feat/r47-tutor`, as Dot.Ehail R-46 did. Why: the brief spans a dozen domains; one contract plus owned paths lets them proceed in parallel without collisions.
D-2 (coordinator, 2026-11-10): Keep the shared-host constraints: no daemons, polling instead of websockets, cron-driven queue. Why: the live host is shared cPanel, the same as every other Dot app.
D-3 (coordinator, 2026-11-10): The academic structure is data (countries, curricula, frameworks, stages, levels, subject offerings), seeded with CAPS, IEB, a tertiary track and Cambridge. Why: brief section 5 forbids hardcoding one country's system.
D-4 (coordinator, 2026-11-10): Paystack for payments, integer minor units, a double-entry ledger. Why: owner decision 2026-10-18 and the money rules learned on Dot.Ehail and Dot.Finance.
D-5 (coordinator, 2026-11-10): Live lessons are native WebRTC with polling signalling (no TURN yet, honest fallback to the tutor's own meeting link). Why: Dot.Engage proved the approach on the same host; brief section 12 forbids fake video.
D-6 (coordinator, 2026-11-10): AI through an `AiTutor` interface with an Anthropic driver, off until the owner sets a key; Dot.Agents, Dot.Brain and Dot.Memory are not called because no documented cross-app API exists for this use. Why: brief sections 14 and 21 require genuine integrations only.
D-7 (coordinator, 2026-11-10): Minors are protected server-side through one `SafeguardingGate` seam that every talk, meet, pay and publish action asks. Why: brief section 15; one gate is testable and cannot be skipped by a UI.
