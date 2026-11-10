# R-47 matching (marketplace agent)

`App\Domain\Marketplace\Services\MatchingService::match(LearningNeed $need, ?User $learner, ?now)` ranks approved tutors for a structured need and explains each result. API: `POST /api/tutor/match`. Page: `/find-a-tutor`. Tests: `tests/Feature/Marketplace/MatchingTest.php`.

## The need

`App\Domain\Marketplace\Support\LearningNeed` (validated by `LearningNeed::rules()`):

| Field | Meaning |
|---|---|
| `subject_id` (required) | Subject from the catalogue |
| `level_id`, `curriculum_id` | Academic ids (academic agent's tables). Ignored with a note while the academic seam is the null default |
| `languages[]` | BCP 47 codes of acceptable TEACHING languages, most preferred first. Never the interface locale |
| `bilingual_ok` | A tutor teaching in two of the listed languages fits fully |
| `format`, `area` | `online`, `in_person` or `either`; area matched as a case-insensitive substring either way |
| `from_date`, `to_date`, `weekdays[]`, `from_time`, `to_time`, `timezone` | Availability window, in the learner's timezone |
| `budget_per_hour_minor`, `currency` | Budget per hour in minor units |
| `help_type` | An offering type (one_to_one, group, consultation, homework_help, exam_prep, revision, package, recurring_weekly) |
| `duration_minutes` | Lesson length used to look for open slots (default 60) |

Turning a sentence ("Grade 11 Maths in isiZulu after school, under R250") into this structure is not built here; it belongs to the wave-2 AI agent, which should fill the same fields.

## Hard filters (a tutor is left out, not ranked low)

1. Not approved, or does not teach the subject (the `tutor_subject` list, which scopes keep in step).
2. The learner themself.
3. Format: online need and the tutor does not teach online; in-person need and the tutor does not teach in person, or the area does not match.
4. Language: the tutor has listed teaching languages and none is in the learner's list. A tutor who listed none is kept and scores 0 on language.
5. Budget: more than `budget_tolerance` (20%) over budget, comparing the cheapest relevant offering's hourly equivalent (a package compares per lesson), or the profile rate when the tutor has no offerings.
6. Level: every scope for the subject has an explicit level range and the level is more than one step outside all of them (same curriculum).
7. Help type: group, package or weekly requests need an offering of that type.

## Terms and weights (sum 100, `config/marketplace.php`)

Each term is a value in [0, 1] times its weight. Every result carries every term (`term`, `weight`, `value`, `points`, `note`) and plain-language `reasons`. The score is the sum of points.

| Term | Weight | Value |
|---|---|---|
| scope | 25 | 1.0 level inside a declared range (or no level asked); 0.7 scope not narrowed to levels; 0.6 subject only (no scope rows, or academic data unavailable); 0.4 range in another curriculum; 0.3 one level outside |
| curriculum | 10 | 0 when not asked; 1.0 a scope names the learner's curriculum; 0.5 the tutor has not said; 0 another curriculum |
| language | 15 | 0 when not asked or not recorded; 1.0 teaches in the first choice, or (bilingual_ok) in two listed languages; 0.7 teaches in another listed language |
| availability | 15 | open slots from the real `SlotFinder` (weekly hours, blocked dates, existing bookings, DST-safe) that fall in the window over the next 14 days, divided by 6, capped at 1. For group requests: scheduled group lessons with free seats in the window |
| budget | 10 | 0 when not asked; 1.0 within budget; linear from 1 to 0 across the tolerance band; 0 when the currency differs |
| help_type | 5 | 1.0 an offering of that type; 0.5 a one-to-one lesson can deliver it (homework, exam prep, revision, consultation); 0 otherwise |
| verification | 8 | operator-assigned level: peer 0.4, independent 0.6, verified qualified 0.8, professional or institution 1.0; none 0 |
| reviews | 7 | from `min_reviews` (3) published reviews: ((average - 1) / 4) x min(1, count / 10). Below the minimum: 0, and the reason says how many there are |
| new_tutor | 5 | 1.0 when approved within 90 days and below the review minimum. Capped below the review term, so a newcomer is findable but cannot outrank a strong record on an otherwise equal fit |

## Fairness rules

- No paid placement: there is no boost, sponsored or featured column (a test asserts it), and nothing outside the terms above enters the score.
- Not popularity: completed-session counts are shown on the storefront but are not a term; reviews need a minimum and saturate at 10.
- Ties: broken by `sha256(need fingerprint | tutor id)`. Deterministic for a given need, but different needs give different orders among equals, so no tutor is always first. The browse directory (`GET /api/tutor/tutors`) uses a daily-seeded order for the same reason.
- "Previous tutoring outcomes": no outcome data exists yet (learning progress arrives with the learning agent). Not used, and not faked.

## Limits

- At most 300 candidates are scored per request (after the SQL filters) and 20 returned; availability runs the slot finder per candidate (three queries each). Fine at current scale; a cached availability summary is the next step if the tutor count grows.
- "New tutor" uses `reviewed_at` as the approval time, which a later suspend or reinstate also updates.
- Level and curriculum terms only work once the academic seam is bound; until then the result says so in `notes`.
