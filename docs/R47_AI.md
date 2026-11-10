# R-47 AI learning assistant (ai agent)

Owner: ai agent (decisions D-900 to D-918). Code: `app/Domain/Ai/**`, routes `routes/tutor/ai.php`, config `config/ai.php`, migration `2026_11_10_900000_create_ai_tables.php`, views `resources/views/ai/**`, tests `tests/Feature/Ai/**` (39 tests). Brief sections 10, 13, 14 and 21.

**Off by default.** Nothing is sent anywhere until `AI_ENABLED=true` and `ANTHROPIC_API_KEY` are set. Until then every AI screen says "The AI assistant is not configured yet", JSON answers `503 {error: ai_not_configured}`, and nothing is logged (nothing happened).

## 1. Shape

```
controller -> StudentAssistant | TutorAssistant | TranslationAssistant | NeedAssistant
           -> AiGateway: configured? -> Eligibility -> UsageLimits -> task refusals (IntegrityGuard, access)
                         -> minimised prompt (PromptBuilder) -> AiTutor driver -> output post-processing -> ai_interactions log
```

- `Contracts\AiTutor` with two drivers. `AnthropicAiTutor` calls the Messages API through the official Anthropic PHP SDK (`anthropic-ai/sdk` 1.8, Apache-2.0); `NullAiTutor` sends nothing. `TutorAiServiceProvider` binds the Anthropic driver only when the switch and the key are both present (`bindIf`, so tests and the integration provider can rebind).
- Tests never reach the network: `FakeAiTutor` replaces the driver, and `AiDriverTest` runs the real SDK against a fake PSR-18 transport to check the request shape (model, `fallbacks`, beta header, `output_config`, no sampling parameters) and the handling of refusal, truncation and a 401.
- `Seams\LearningRecords` is the one place that reads the learning domain (attempts, assignments, enrolments, mastery via `ProgressFeed`, library access via `LibraryService`, course visibility via `CourseAccess`).

## 2. Model and cost (claude-api skill)

| Setting | Default | Why |
|---|---|---|
| `AI_MODEL` | `claude-opus-5-5` | The skill's default for every task. Adaptive thinking is always on for this model; effort is the control. |
| `AI_MODEL_LIGHT` | same model | Used for translation and need parsing. The skill says not to downgrade on the owner's behalf, so the cheaper path is lower effort on the same model. The owner may set `claude-haiku-5-5` here. |
| Effort | `medium` standard, `low` light | Opus 5.5 defaults to medium; set explicitly. |
| `max_tokens` | 16 000 standard, 8 000 light | Non-streaming default from the skill; a cut-off answer is shown as partial. |
| Refusal fallback | on (`fallbacks: "default"`, beta `server-side-fallback-2026-07-01`) | Skill default for Opus 5.5. The model that actually answered is read from the response and logged. A refusal that stands is shown as "the assistant declined". |
| Timeout, retries | 60 s, 1 retry | A web request waits for the answer (no queue worker on the shared host). |

Prices (US cents per million tokens, skill table cached 2026-10-06): Opus 5.5 input 400 / output 2000; Haiku 5.5 10 / 50. A typical explanation (about 1 500 input and 1 500 output tokens including thinking) costs about $0.036 on Opus 5.5 and about $0.001 on Haiku 5.5. The log records tokens and an estimated cost per request in micro-dollars; the Anthropic Console bill is the source of truth. Prompt caching is not used: the system prompts are shorter than the minimum cacheable prefix.

## 3. Guardrails (all enforced server-side, all tested)

| Guardrail | How | Test |
|---|---|---|
| Labelled as AI guidance | Every answer carries `label: "AI guidance"`, a disclaimer, the model name, an "Ask a tutor instead" link and a report route | `AiGuardrailsTest::test_every_answer_is_labelled...` |
| Age bands | Band from safety's `AgeResolver` (known date of birth), an active guardian link means teen, unknown age uses `AI_UNKNOWN_AGE_BAND` (child by default, D-905). Child and teen get different rules in the system prompt (simple language, school topics only, the Childline 116 instruction) | `test_age_bands_change_the_rules...`, `test_an_unknown_age_is_answered_with_the_child_rules...` |
| Guardian consent | A minor safety treats as limited (no consenting guardian) is refused before anything is sent (D-907); suspended accounts too | `test_a_minor_without_guardian_consent...` |
| Curriculum and level | The learner's curriculum, level, stage and subjects (academic `AcademicScope`) go into the prompt | prompt assertions |
| Help me understand | Default mode: hints and steps, never the final answer to the learner's own problem. Adults may ask for a worked example of a similar problem; minors always get "understand" | `test_help_me_understand_is_the_only_mode_for_minors` |
| Integrity | A timed attempt in progress pauses the assistant; text matching a question from an assessment in progress, an assigned tutor assessment not yet passed, or a quiz of an enrolled course is refused (70% word containment, or the whole question for short ones); requests to do assessed work ("do my assignment for me", "answers to my exam", "I am writing a test right now") are refused. Practice assessments are not protected | `test_a_timed_assessment...`, `test_a_copied_question...`, `test_requests_to_do_assessed_work...` |
| Explaining mistakes | Only the learner's own submitted, wrong answers. The answer key and the tutor's explanation are sent only when the assessment reveals answers after submission; otherwise the model is told not to reveal or hint at the answer | `StudentAssistanceTest` |
| Not verified fact | Prompts forbid invented facts, sources, quotations, statistics, page numbers and links, and ask the model to say when unsure | prompt text |
| Data minimisation | Only the band, curriculum, level, subjects and answer language describe the person; never name, email, date of birth, school or guardian. Contact details in typed text are removed (safety `ContactRedactor`) before sending and storing; answers to minors are redacted too. The pseudonymous `metadata.user_id` is an HMAC of the user id. Names typed into free text cannot be removed reliably; the forms ask people not to type them | `test_contact_details_are_removed...`, `test_contact_details_in_an_answer_never_reach_a_minor` |
| Rate and cost limits | Per-minute limiter `ai-requests`; per-day request and cost limits per band (child 15 / $0.25, teen 30 / $0.50, adult 50 / $0.75, tutor 60 / $1.50); a platform monthly budget (`AI_MONTHLY_BUDGET_USD`, default 100). Counted from the log, only requests that reached the model | `test_daily_and_monthly_limits...` |
| Safeguarding signals | A small English/Afrikaans pattern list (self-harm, abuse, danger) flags the interaction for review and adds fixed help text (Childline 116, 10111, 112). It will miss things; that is why every answer has a report button | `test_safeguarding_words...` |
| Report button | Files the interaction to safety's `ReportSink` (it implements `Reportable`: the learner is involved, so the minor priority rules apply; the snapshot holds the request and answer) and keeps it as evidence | `test_the_report_button...` |
| Safeguarding log and retention | Every request, answered or refused, is an `ai_interactions` row; request, prompt and answer text are encrypted at rest. `ai:purge-interactions` (daily 03:30 UTC) deletes rows after 180 days, flagged or reported rows after 730 days. Operators review at `/ai/operator/log` (flagged and reported first); opening one is written to the safety audit log | `test_operators_review...`, `test_retention...` |

## 4. Student assistance (`POST /ai/ask`, `task`)

`explain`, `simplify`, `practice` (structured: question, hint, answer, explanation; the answer sits behind a disclosure), `revision_summary`, `study_plan` (built from the learner's measured mastery rows; says so when there are none), `explain_mistake` (`attempt_id`, `question_id`), `resource_help` (`resource_id`: a library PDF or image the learner may download, entitlement re-checked through `LibraryService`, sent as a document or image block; the log keeps a reference, never the bytes). Any task can be answered in another written language (`language`): the answer carries a note that AI answers in that language have not been checked by a native-speaking educator.

## 5. Tutor assistance (`POST /ai/tutor/ask`)

Needs a tutor profile. `lesson_outline`, `revision_exercises`, `course_description` (own courses only; a suggestion, never saved into the course), `quiz_drafts` (into the tutor's own bank; structured output; each draft must pass learning's `Marker::normaliseKey` or it is dropped and counted; drafts live in `ai_question_drafts` and become learning questions only when the tutor accepts one, optionally edited, at `POST /ai/drafts/{id}/accept`; `discard` otherwise), `struggle_summary` (topic counts aggregated across the tutor's learners from real mastery: sessions confirmed or completed, active enrolments in their courses, assignments they set; no names are sent; the counted table shows without AI too), `resource_suggestions` (the model chooses only from up to 25 real published library resources; any id not in that list is dropped).

## 6. Translation assistance

`POST /ai/translations {type, id, field, locale}` machine-translates a field into the academic `Translations` store with provenance `machine`, the model that answered and review status "not reviewed". The original is never overwritten. An up-to-date stored translation is reused without a model call. Academic's `NotationGuard` checks every formula, LaTeX segment, chemical formula and number survived; if not, the translation is rejected, not stored, and the missing items are listed. `GET /ai/translations/{type}/{id}/{field}?locale=` shows the original and the translation side by side with provenance, out-of-date and notation warnings. `POST /ai/translations/{id}/review` (approved or rejected): operators, or the content's owner when their language profile holds the target language (D-914); audited.

Translatable types registered by this domain when their owners have not (D-912): `learning.course` (title, description), `learning.resource` (title, description; through `Support\TranslatableResource`, which reads the resource's `language` as its original locale), `marketplace.tutor_profile` (headline, bio, teaching approach). Discussion posts: any type the community domain registers works through the same endpoint with that model's `view` policy. Who may request a translation: anyone who may see the content (course outline visibility, library visibility, an approved or own tutor profile; academic reference data: operators only).

## 7. "Describe what you need" with an AI fallback

`POST /ai/describe {text, use_ai}`. The academic `NeedParser` runs first. Only when it is unsure (no level or no subject) and the person ticks "let the AI reword it" does the model rewrite the text into plain fields (grade or level, subjects, teaching languages); the same deterministic parser then matches those against the seeded catalogue, so no id comes from the model. The result is `awaiting_confirmation`; `POST /ai/describe/{id}/confirm` accepts only candidates the parser produced and returns the tutor-matching link. Screen: `/ai/describe`.

## 8. Dot.Agents, Dot.Brain, Dot.Memory (brief section 21)

Inspected 2026-11-10 (D-916). Not integrated:
- **Dot.Agents** (`routes/api.php`, `docs/openapi.yaml`): a Sanctum and organisation-scoped API for managing agent deployments and running skills of an organisation's deployed agents. There is no learner or tutoring capability, it needs an organisation account and token Dot.Tutor does not have, and routing children's words through a second application adds a processor without a consent basis.
- **Dot.Brain**: `brain.api.md` specifies DKP ingestion and read-only graph queries, but no service in the repository serves them (no `/v1/dkp` or `/v1/query` handler, no HTTP server in `services/*/src`); its services are internal workers (guardian, intelligence-loop client, insight delivery, revenue and market research). Nothing serves educational context to call.
- **Dot.Memory** (`routes/api.php`): Sanctum endpoints for the platform intelligence loop (events, decisions, actions, outcomes, ops incidents). It holds no user-approved learning preferences and has no consent model for minors' data.
Revisit when one of them documents an authenticated, consented API for learning context.

## 9. Routes

Web under `/ai`, JSON twins under `/api/tutor/ai` (session auth, CSRF), names `ai.*` and `api.ai.*`: `GET /`, `POST ask`, `GET interactions/{id}`, `POST interactions/{id}/report`, `GET tutor`, `POST tutor/ask`, `POST drafts/{id}/accept|discard`, `POST translations`, `GET translations/{type}/{id}/{field}`, `POST translations/{id}/review`, `GET|POST describe`, `POST describe/{id}/confirm`, `GET operator/log` (operator middleware and `ai.review-log` gate). JSON errors are `{error, message, data}`: 503 not configured, 403 eligibility, 429 limits, 404 access, 422 other refusals, 502 provider failure.

## 10. Screens (functional, on the ds kit)

`/ai` (learner shell; young step for the child band), `/ai/interactions/{id}`, `/ai/tutor` (workspace shell), `/ai/translations/...`, `/ai/describe`, `/ai/operator/log` (operations shell). Checked with Playwright at 390 px and 1440 px (no horizontal overflow), `impeccable detect`: no findings. The shells' navigation config (`config/tutor_nav.php`, ds-owned) has no entry for the assistant yet: the student and tutor UI agents should add `ai.index` and `ai.tutor.index`. LaTeX is shown as written (`$x^2$`): there is no maths renderer (KaTeX would be a new dependency; left to the UI agents).

## 11. Not built, or left to others

- Streaming answers, background jobs (shared host: answers wait in the request, up to 60 s).
- Recommending tutors from the assistant (matching already explains tutor fits; the answer links to it).
- Lesson transcript and subtitle translation (no transcripts exist yet; classroom domain).
- Automatic reporting from safeguarding signals (signals flag for review; people file reports).
- A per-language quality measurement: no language is claimed as reviewed.
- Owner items: set `AI_ENABLED` and the key; decide `AI_MODEL_LIGHT` (Haiku 5.5 cuts translation cost about 40x); set the monthly budget; confirm Anthropic's usage-policy requirements for products used by minors and the POPIA basis for sending minors' questions to a US processor (lawyer); a safeguarding lead to review the Childline/10111/112 help text and the signal list; Anthropic data-retention settings for the organisation (zero data retention needs Anthropic's approval).
