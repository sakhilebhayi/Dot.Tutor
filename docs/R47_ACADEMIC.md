# R-47 academic domain

Owner: academic agent (decisions D-200 onward). Code: `app/Domain/Academic/**`, routes `routes/tutor/academic.php`, provider `App\Domain\Academic\TutorAcademicServiceProvider`. Brief sections 5, 10, 20, 22, 25.

## 1. Model

```
countries ─┐
           ├─ qualification_frameworks ─┐
           └──────────────── curricula ─┴─ academic_levels (ordered, position) ── academic_stages (seeded)
                                  │              │
subjects (global catalogue) ──────┴── curriculum_subject_levels (offering) ── academic_topics (tree)
institutions (country)        legacy_subject_level_map (legacy level string -> level)
languages ── user_languages (role, proficiency, verified_at)
learner_profiles ── learner_profile_subjects (subject + offering)
translations (alias, id, field, locale, provenance, review, source_hash)
```

- A curriculum belongs to a framework and to one country, or to none (`country_id` null = international, available in every country: Cambridge).
- Levels are ordered by `position` inside one curriculum and grouped into stages. Stages are data: `foundation`, `intermediate`, `senior`, `fet`, the four Cambridge stages, `undergraduate`, `honours`, `pg_diploma`, `masters`, `doctoral`, `professional`.
- Subjects are one global catalogue on the existing `subjects` table (same ids as `App\Models\Subject`; `App\Domain\Academic\Models\Subject` extends it). An offering says which subject is offered at which level; its `curriculum_id` is always copied from the level (model hook).
- Nothing in the structure is deleted: rows are deactivated (`is_active`), and every foreign key that history, a learner or a tutor may hold restricts deletes.

## 2. Seeded reference data (`php artisan academic:sync`)

Inserted by `App\Domain\Academic\Services\ReferenceDataSync` from `app/Domain/Academic/Data/*.php`. Idempotent and edit-safe: matched on stable codes, only missing rows are inserted, an operator's rename, alias change or deactivation is never overwritten, nothing is deleted. `AcademicReferenceSeeder` and `DatabaseSeeder` call the same code. **It is not a migration**: the deploy must run `php artisan academic:sync` after `migrate` (owner/deploy item). Kept out of migrations so the existing 293 tests (which assume an empty subject table) stay unchanged.

| Data | Seeded |
|---|---|
| Countries | South Africa |
| Frameworks | GFETQSF (Umalusi), HEQSF (CHE), Cambridge International |
| Curricula | CAPS and IEB (Grades 1 to 12; Grade R not seeded), South African higher education (undergraduate years 1 to 4, Honours, PG diploma, Master's, Doctoral, Professional), Cambridge (Primary Stages 1 to 6, Lower Secondary 7 to 9, IGCSE, AS Level, A Level) |
| Subjects | 71 catalogue subjects: HL and FAL for all 11 official languages, general English and Afrikaans, the CAPS phase subjects, 23 FET subjects, IEB Advanced Programme Mathematics, tertiary and Cambridge subjects |
| Offerings | about 880: CAPS per phase (Foundation: languages, Mathematics, Life Skills; Intermediate: + NS and Technology, Social Sciences; Senior: + Natural Sciences, Technology, EMS, LO, Creative Arts; FET: languages, Mathematics, Mathematical Literacy, Technical Mathematics, LO and electives); IEB the same plus AP Mathematics at FET; higher education; Cambridge |
| Topics | CAPS Mathematics Grades 1 to 9 (the five content areas), Mathematics Grades 10, 11, 12 (FET topics), Physical Sciences Grade 10 (knowledge areas and sub-topics), Life Sciences Grade 10. Nothing else has topics. |
| Institutions | The 26 South African public universities (names and short names only; no schools, no addresses) |
| Languages | 11 official written languages, South African Sign Language (`sfs`, signed, teaching only), Portuguese, French, Swahili |

Not seeded, deliberately: Grade R, Second Additional Language subjects, the smaller FET electives (Marine Sciences, the technology specialisations, Dance, Design, etc.), TVET colleges, private institutions, schools. Names were written from the build agent's knowledge of the DBE, IEB and Cambridge documents, not checked against the live documents: an academic editor should review them (owner item). The IEB subject is named "Advanced Programme Mathematics" with "Further Studies Mathematics" as an alias because the IEB's current naming could not be confirmed.

## 3. Legacy subjects

The pre-R-47 `subjects` rows (name + free-text `level`: primary, high_school, university, adult) are mapped without losing a row or changing an id (`tutor_subject` and `tutor_sessions` point at them):

1. A catalogue entry whose name matches an existing row (case-insensitive) **adopts** the oldest such row: it gets the slug, aliases and family; its id is kept.
2. Other legacy rows with the same name are **linked** (`canonical_subject_id`) to the catalogue row.
3. A legacy row with no catalogue match **becomes a catalogue row** (it gets a slug); later duplicates link to it.
4. The legacy `level` string is never rewritten. `legacy_subject_level_map` maps it onto levels in every curriculum where the band applies (primary: CAPS/IEB Grades 1 to 7 and Cambridge Primary + Lower Secondary 7; high_school: Grades 8 to 12, Cambridge 8, 9, IGCSE, AS, A; university: undergraduate to doctoral; adult: professional). Unknown strings are reported by `academic:sync` and left alone.
5. New catalogue rows get a legacy `level` band (the band covering most of their offerings) so the old screens still label them.

`LegacySubjectMappingTest` runs the pre-R-47 migrations, fills them with subjects, a tutor and sessions, then runs the R-47 migrations and the sync on top.

## 4. Seams (architecture section 3)

| Seam | Default binding | Notes |
|---|---|---|
| `Contracts\AcademicScope` | `DatabaseAcademicScope` | `forLearner`, `isOffered`, `validateTeachingScope` (reasons: unknown_subject, unknown_level, inactive, not_offered), `canonicalSubjectId`, `levelIdsForLegacySubject`, `offeringsAtLevel` |
| `Contracts\LanguageProfile` | `DatabaseLanguageProfile` | `codes(role)`, `interfaceLocale`, `teachingLanguages`, batch `teachingLanguagesFor(ids)` for matching, `learnerPreferences` (teaching, understands, learning, bilingualOk; never the interface locale), `sync`, `syncTeachingLanguages` (verification survives only an unchanged claim) |
| `Contracts\AgeBandResolver` | `UnknownAgeBand` (null) | The safety domain owns the rule; the integration provider binds it. Callers treat null as the most protected band. |
| `Contracts\AcademicAudit` | `LogAcademicAudit` (application log) | Rebind to `Safety\Audit::record` in integration. |
| `Translations` (singleton) | | Content translation store; other domains `register(alias, class, fields)` in their provider. |

For the safety seam `BirthDateSource` (safety, merged later): `LearnerProfiles::birthDateFor($user)` returns the conservative birth date (last day of the stored month) or null. The integration provider should bind `BirthDateSource` to it.

## 5. Learner profile and onboarding

`/learn/profile` (functional wave-1 view): describe what you need (fills the form), country, one grade/level select grouped by curriculum, subjects at that level (language subjects folded into a disclosure), university at tertiary levels only, month and year of birth, teaching / understood / learning languages, bilingual preference. Works without JavaScript. Stored minimum: country, curriculum, level, subjects (+ offering), institution (tertiary only), `birth_month` (1st of the month), bilingual flag. No school name for anyone. Deleting the account deletes the profile and language holdings.

`NeedParser` (deterministic, no AI): grade patterns ("Grade 11", "gr11", "Graad 12", "ibanga 5", number words in English and Afrikaans, "matric"), level aliases (first year, honours, PhD, IGCSE, A level...), subject names and aliases (abbreviations; Afrikaans, French, Portuguese, Swahili and a few isiZulu names), language names in several languages, teaching markers ("in", "em", "kwa", "through", "ngesiZulu"; not "en", which is "and" in Afrikaans). An unmarked language name comes back as language-subject candidates and a "possible" teaching language. Not-offered combinations are reported. `POST /learn/describe` returns JSON (throttled 30/min).

## 6. Operator screens

`/operator/academic` behind the existing `operator` middleware: countries, frameworks, curricula, levels, subjects, offerings, topics, institutions, languages (list + filters + add + edit + deactivate), and tutors' teaching-language verification (operators cannot verify their own). One generic controller and view render every resource from `Http\Operator\OperatorResources`. Every change goes through `AcademicAudit`. The legacy `/operator/subjects` screen is untouched; wave-2 ops UI should fold it in.

## 7. Routes

`GET|POST /language`, `GET|PUT /learn/profile`, `POST /learn/describe`, `GET /academic/curricula`, `GET /academic/levels/{level}/offerings`, `GET /academic/offerings/{offering}/topics`, `GET /translations/{type}/{id}/{field}?locale=`, `/operator/academic/**`. JSON endpoints answer `{error, message}` with 422/403/404 (the app only renders JSON errors under `api/*`, so they validate themselves).

## 8. Tests

`tests/Feature/Academic/*` (68 tests): reference data, idempotency and edit-safety, legacy mapping on a populated database, seams, NeedParser cases, learner profile flow and validation, locale resolution and coverage honesty, translations provenance and notation guard, operator CRUD and verification.
