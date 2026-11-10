# R-47 product research

Each agent appends its own section.

## Tutor discovery and matching (marketplace agent, 2026-11-10)

Method: desk research from general knowledge of how these platforms present themselves publicly. Nothing below was checked against the live sites on 2026-11-10; every claim is marked **(unverified)** and should be re-checked before it is quoted to anyone. Figures (commissions, prices) are deliberately left out because they change and could not be confirmed.

| Platform | How learners find tutors | Ranking and pricing | Verification | Strengths | Weaknesses |
|---|---|---|---|---|---|
| Preply | Directory filtered by subject, price, country of birth, availability, native speaker; a short quiz in some flows (unverified) | Tutors set their own price; ranking influenced by the platform's own signals, including responsiveness and booking history; trial lesson model; commission falls as a tutor teaches more hours (unverified) | Identity and some certificate checks on tutor profiles; many tutors are not formally qualified (unverified) | Huge supply, language focus, low-commitment trial lesson | Opaque ranking; new tutors compete on price; school curricula are an afterthought |
| Wyzant | Search by subject and zip code, online or in person, then browse profiles (unverified) | Tutors set hourly rates; platform fee on each lesson; ranking from relevance, reviews and responsiveness (unverified) | Background checks offered in the US; subject quizzes for listing subjects (unverified) | Strong local, in-person option; per-lesson, no subscription | US-centric; profiles lean on reviews, so newcomers struggle |
| Superprof | Search by subject and city; very large catalogue including non-academic subjects (unverified) | Tutors set prices; learners pay a subscription to contact tutors; many tutors offer a free first lesson (unverified) | Light checks; reviews and "verified" badges of varying meaning (unverified) | Reach, free first lessons, in-person coverage | Paying to message; verification wording is easy to over-read |
| Varsity Tutors | Learner describes the need; the company matches a tutor rather than the learner browsing (unverified) | Packages and memberships sold by the company; prices not set by tutors (unverified) | Company-vetted tutors with screening (unverified) | Low effort for families; structured programmes | Little learner choice or transparency; tutors are contractors with no storefront of their own |
| Tutor.com | On-demand: connect to an available tutor in minutes, often through a library, school or employer licence (unverified) | Institutional subscriptions; no per-tutor pricing (unverified) | Company-screened tutors (unverified) | Instant homework help | No ongoing relationship; no tutor business |
| An African platform (example: South African tutoring agencies such as Teach Me 2 / Master Maths) (unverified) | Agency matching by phone or form, or franchise centres by location (unverified) | Agency or centre sets the price, often packages; the tutor sees a fraction (unverified) | Agency interviews and checks (unverified) | Knows CAPS and IEB; in-person reach; parents trust a known brand | Little transparency; tutors cannot build their own business; language of instruction rarely a first-class choice |

### What Dot.Tutor does differently (built in this branch)

- **Explained, structured matching** instead of a popularity directory or a black-box agent: every result lists its scoring terms and plain reasons, and there is no paid placement (docs/R47_MATCHING.md).
- **Curriculum and level are data** (CAPS, IEB, Cambridge, tertiary) and part of the fit, through the academic seam, rather than a free-text "subject".
- **Teaching language is a matching dimension** (isiZulu, Sesotho, Afrikaans ... and bilingual tutoring), never inferred from the interface language.
- **Verification levels from evidence** with an operator review and an audit trail, and labels that cannot claim more than was checked (a peer tutor is never shown as a qualified teacher).
- **Fair to newcomers**: a capped new-tutor term and a minimum review count, so a tutor is discoverable before they have reviews but cannot outrank a strong record on an equal fit.
- **Low commitment and many formats**: short consultations, homework help, exam prep, revision, groups, packages and weekly lessons, each with its price and cancellation terms shown before booking.
- **A tutor's own storefront and offerings**, so a student tutor can grow into an independent tutor and a small business on the same profile (brief section 18).
- **Minors**: bookings by minors wait for a guardian through the safety seam; the marketplace never auto-approves.
