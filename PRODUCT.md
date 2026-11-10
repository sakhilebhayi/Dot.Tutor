# Product

<!-- impeccable:product-schema 1 -->

> Written by the R-47 ds agent on 2026-11-10 without an interview: the owner's standing order for R-47 is "no questions", so every fact below is inferred from the owner's brief (`docs/R47_BRIEF.md`), the architecture contract (`docs/R47_ARCHITECTURE.md`) and the audit (`docs/R47_AUDIT.md`). Lines marked *(inferred)* are the agent's reading, not an owner answer.

## Platform

web

## Users

- **Learners from Grade 1 to doctoral level**, South Africa first: a Grade 3 child on a parent's phone, a Grade 11 learner revising after school on a prepaid data bundle, a first-year student, a master's or PhD candidate who needs a methods tutor. *(inferred: phones and patchy networks are the common case, brief section 23)*
- **Tutors**: academically strong learners and university students who tutor peers, independent tutors, qualified teachers and lecturers, institution-affiliated educators. They come to build a business: profile, offerings, bookings, students, earnings, payouts.
- **Guardians** of minors, who approve bookings, payments and rooms and see what their child does.
- **Course creators** who publish structured courses and study material.
- **Operators**: academic editors, verification reviewers, safeguarding officers, moderators, support and finance.

## Product Purpose

Anyone who wants to learn finds someone who can teach them; anyone with knowledge worth sharing can earn from it, regardless of age, level, place or language. Success: a learner says what they need in plain words and leaves with an explained match, a room of classmates at their level and subject, and visible progress; a tutor turns knowledge into a sustainable income.

## Positioning

One place that joins peer tutoring, professional tutoring and structured courses, organised by **academic rooms** (country, curriculum, level, subject) and made **language-aware**: a learner who needs Grade 11 Maths explained in isiZulu is matched with tutors who genuinely teach in isiZulu, never by interface language alone. Verification is evidence-based and shown honestly (a peer tutor is never presented as a professional educator).

## Operating Context

- InfoDot is the only sign-in (Dot ecosystem SSO). Local registration is closed in production.
- Shared cPanel host: no Node at runtime, no websockets, polling only, cron-driven queue.
- Money in ZAR first, integer minor units; Paystack for payments.
- Academic structure is data: CAPS and IEB (Grades 1 to 12), a tertiary track (undergraduate to doctoral and professional) and Cambridge as a second example.
- Eleven official South African written languages plus Portuguese, French and Swahili seeded.

## Capabilities and Constraints

- Existing and kept: InfoDot sign-in, tutor profile application and operator review, subjects, weekly availability, slot finder, session booking lifecycle, session ratings, session-scoped files.
- Being built in R-47 (not yet live): learner profiles, academic rooms, matching, offerings, courses, resource library, payments and earnings, safeguarding, live lessons, AI assistant (off until a key is set).
- No runtime CDNs (a nonce CSP is planned). Fonts and icons are self-hosted.

## Brand Commitments

- Name: **Dot.Tutor**, part of the Dot ecosystem (InfoDot). Existing logo and mark in `public/images/`.
- Owner's design brief (section 4): knowledge, growth, achievement, trust, curiosity, opportunity, community, professionalism; educational without childish; never a generic school administration system; credible to a university lecturer; avoid generic SaaS layouts, excessive cards, unnecessary gradients and decorative animation that interferes with learning.
- Ecosystem rule (owner): the Dot anti-slop bar. No warm cream + serif + terracotta, no near-black + one acid accent, no four equal metric tiles, no side-stripe card borders, no radial blooms; Inter, Space Grotesk, Geist, Plus Jakarta Sans are on the owner's reject list.

## Evidence on Hand

- Real: the product brief, the seeded academic structure and languages, the existing booking engine. 
- Absent and never to be invented: tutor names, learner counts, reviews, ratings, earnings, partner logos, testimonials, press. The landing describes the product and its mechanism only.

## Product Principles

1. Say what you need in plain words; the platform explains why a match fits.
2. Complexity follows the person: a Grade 3 learner sees less than a tutor running a business.
3. Language is a matching dimension, not a UI label.
4. Honest states everywhere: verification, translation provenance, AI guidance and unconfigured providers are labelled for what they are.
5. Minors are protected server-side; the interface never implies a safety it does not enforce.

## Accessibility & Inclusion

WCAG 2.2 AA target. Mobile-first, low-bandwidth (small pages, self-hosted subset fonts, no heavy media by default), keyboard and screen-reader complete, reduced-motion honoured, 44px touch targets, readable type for young readers, eleven-language content (long words in isiZulu and Afrikaans must not break layouts).
