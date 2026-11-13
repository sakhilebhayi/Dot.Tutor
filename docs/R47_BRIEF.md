# R-47 owner brief (verbatim, 2026-11-10)

Owner's order with it: follow the prompt until everything is designed, built and deployed; no questions or permission asks; if credits run out, save the session so work continues after the reset.

---

Dot.Tutor — World-Class Learning, Tutoring & Educational Entrepreneurship Ecosystem
1. The Vision: Transform How People Learn, Teach and Earn
Let's transform Dot.Tutor into one of the most innovative, accessible and powerful educational platforms available.
I do not want another conventional tutoring marketplace where students search through tutor profiles, send messages and book lessons.
I want Dot.Tutor to create an educational ecosystem where learning and teaching happen naturally.
The platform should connect:

* Students who need academic assistance.
* Students who are academically strong and want to earn money by tutoring others.
* Professional tutors who want to build sustainable businesses.
* University students who want to share knowledge and help others.
* Educators who want to create and sell structured courses.
* Learners who want access to study materials, revision resources and educational communities.

The central idea is simple:
Anyone who wants to learn should be able to find someone who can teach them. Anyone with knowledge worth sharing should have the opportunity to earn from it.
Dot.Tutor must make this possible regardless of someone's age, academic level, location, background or preferred language.
The platform should support learning from Grade 1 through to postgraduate and university-level education, with an architecture capable of expanding into professional development, vocational training and lifelong learning.
It must feel educational, inspiring, trustworthy, welcoming and premium.
The ambition is to build something that genuinely changes how people access education and how tutors turn their knowledge into income.
2. First Priority: Complete Existing Platform Audit
Before introducing new features, conduct a comprehensive audit of the existing Dot.Tutor application.
The platform may already contain useful functionality. Understand what exists before deciding what needs to change.
Inspect:

* Public landing pages.
* Registration and authentication.
* Student profiles.
* Tutor profiles.
* Tutor onboarding.
* Tutor verification.
* Academic levels and grade structures.
* Subjects and subject categories.
* Tutor discovery and search.
* Student-to-tutor matching.
* Booking and scheduling.
* Online learning functionality.
* Learning rooms and communities.
* Course creation.
* Course sales.
* Study material uploads.
* Resource libraries.
* Payments and commissions.
* Tutor earnings and payouts.
* Reviews and ratings.
* Messaging and notifications.
* Assessments and progress tracking.
* Admin and moderation tools.
* Multilingual functionality.
* Database architecture.
* APIs and external integrations.
* Security and permissions.
* Existing tests and deployment configuration.

Classify each feature as:

1. Fully functional.
2. Functional but requires improvement.
3. Partially implemented.
4. UI-only or simulated.
5. Broken.
6. Missing but essential.

Identify root causes of existing issues.
Preserve working functionality and existing data. Avoid unnecessary rewrites and prevent regressions.
Do not mistake a visually complete page for a functional educational experience.
3. Product Research and Competitive Positioning
Study existing educational and tutoring platforms, including:

* Preply.
* Wyzant.
* Superprof.
* Varsity Tutors.
* Tutor.com.
* Khan Academy.
* Coursera.
* Udemy.
* Skillshare.
* Quizlet.
* Chegg.
* Duolingo.
* Brilliant.
* Other relevant African and international education platforms.

Investigate how they handle:

* Tutor discovery.
* Matching.
* Scheduling.
* Payments.
* Learning communities.
* Courses.
* Study materials.
* Assessments.
* Multilingual education.
* Tutor monetisation.
* Student engagement.
* Educational quality.

Understand their strengths and weaknesses.
Look for opportunities that existing platforms have not adequately addressed, particularly the gap between informal peer-to-peer learning, professional tutoring and structured online education.
Do not simply copy their features.
Use research to create a distinct Dot.Tutor product strategy.
The objective is to create a platform that combines the best aspects of tutoring marketplaces, educational communities, course platforms and digital learning environments without becoming a confusing collection of unrelated tools.
4. Mandatory Design and Engineering Skills
Frontend and visual design
Discover all installed skills relevant to:

* Awwwards-level frontend design.
* UI/UX design.
* Educational product design.
* Design systems.
* Motion design.
* Micro-interactions.
* Responsive design.
* Accessibility.
* Information architecture.
* Interactive learning experiences.
* Data visualisation.
* Mobile-first interfaces.

Read the relevant skill instructions before implementation.
Apply the skills meaningfully throughout the platform.
If a requested skill is unavailable, report that honestly. Never claim to have used a skill that was not installed or applied.
Backend
Use Laravel Boost as the primary backend engineering and architecture assistant.
Inspect the current Laravel implementation and follow the established application conventions.
Design direction
Dot.Tutor should look and feel like a premium educational destination.
The design should communicate:

* Knowledge.
* Growth.
* Achievement.
* Trust.
* Curiosity.
* Opportunity.
* Community.
* Professionalism.

Create an identity that feels educational without becoming childish or looking like a generic school administration system.
Use:

* Excellent typography.
* Deliberate spacing.
* Beautiful learning dashboards.
* Intuitive navigation.
* Clear academic progress indicators.
* Purposeful animations.
* Smooth transitions.
* Engaging but restrained visual elements.
* Excellent empty, loading and success states.

The interface must work for a Grade 1 learner while remaining credible to a university lecturer or professional tutor.
Avoid generic SaaS layouts, excessive cards, unnecessary gradients and decorative animation that interferes with learning.
5. The Core Platform Structure: Academic Learning Rooms
One of the defining features of Dot.Tutor should be its structured academic learning environment.
Create dedicated learning spaces for different academic levels.
School education
Support:

* Grade 1.
* Grade 2.
* Grade 3.
* Grade 4.
* Grade 5.
* Grade 6.
* Grade 7.
* Grade 8.
* Grade 9.
* Grade 10.
* Grade 11.
* Grade 12.

Higher education
Support:

* University undergraduate studies.
* Honours.
* Postgraduate diplomas.
* Master's programmes.
* Doctoral studies.
* Other tertiary and professional qualifications.

The academic structure must be configurable rather than hardcoded to one country's education system.
For example, a learner should be able to identify their country, curriculum, qualification framework, institution and academic level.
Support different education systems, including South Africa's CAPS and IEB pathways, while allowing international curricula such as Cambridge and other relevant systems.
How learning rooms should work
Each academic level should have organised subject spaces.
For example:
Grade 10:

* Mathematics.
* Physical Sciences.
* Life Sciences.
* Accounting.
* Geography.
* History.
* English.
* Afrikaans.
* Other applicable subjects.

Each subject space should provide access to:

* Learning discussions.
* Questions and answers.
* Study notes.
* Revision material.
* Practice questions.
* Tutor recommendations.
* Available lessons.
* Relevant courses.
* Shared learning resources.

Students should be able to learn from other students in their academic environment.
However, maintain appropriate separation between academic levels, subjects and curricula so that students are not overwhelmed with irrelevant information.
Learning room interactions
Allow students to:

* Ask questions.
* Share explanations.
* Upload useful study material.
* Participate in discussions.
* Share revision techniques.
* Create study groups.
* Collaborate on learning activities.
* Recommend useful resources.
* Request tutoring assistance.

Implement moderation, reporting and appropriate access restrictions.
For younger learners, include age-appropriate protections and adult oversight where required.
6. Tutor Discovery and Student Matching
This is the heart of Dot.Tutor.
Connecting students who need help with the right people who can teach them must be exceptionally easy.
Do not make users navigate complicated tutor directories.
Student experience
A student should be able to communicate their need in simple language.
For example:
"I need help with Grade 11 Mathematics."
The platform should help identify:

* The correct academic level.
* The relevant subject.
* The student's curriculum.
* The preferred language.
* Their location or online learning preference.
* Their availability.
* Their budget.
* The type of assistance they need.

Then recommend suitable tutors.
Intelligent matching
Create a matching system that considers:

* Subject expertise.
* Academic level.
* Curriculum compatibility.
* Tutor qualifications and experience.
* Language compatibility.
* Availability.
* Location and teaching format.
* Tutor pricing.
* Student budget.
* Reviews and verified feedback.
* Learning preferences.
* Previous tutoring outcomes, where meaningful data exists.

Do not rank tutors based purely on popularity or payment.
Give newer tutors a fair opportunity to become discoverable.
Explain why a tutor has been recommended.
Different types of tutoring
Support:

* One-on-one tutoring.
* Group tutoring.
* Peer-to-peer tutoring.
* Online tutoring.
* In-person tutoring where supported.
* Homework assistance.
* Exam preparation.
* Revision sessions.
* Ongoing academic support.
* Short consultations.
* Scheduled recurring lessons.

Allow students to find help without feeling that they must commit to an expensive long-term arrangement.
7. Tutor Entrepreneurship: Help Tutors Build Businesses
This must be one of Dot.Tutor's biggest competitive advantages.
Dot.Tutor should not simply help tutors find occasional students.
It should give tutors the tools to build legitimate, sustainable tutoring businesses.
Tutor professional profiles
Allow tutors to create professional profiles containing:

* Profile photograph.
* Introduction.
* Academic qualifications.
* Subjects taught.
* Academic levels supported.
* Curriculum expertise.
* Languages.
* Teaching experience.
* Certifications.
* Teaching approach.
* Availability.
* Pricing.
* Reviews.
* Courses.
* Study materials.
* Teaching formats.

Clearly distinguish verified qualifications from unverified claims.
Tutor business dashboard
Give tutors a dedicated business workspace.
Include:

* Student management.
* Lesson scheduling.
* Bookings.
* Course management.
* Study material management.
* Pricing management.
* Earnings.
* Payment history.
* Payout tracking.
* Student feedback.
* Performance insights.
* Business profile analytics.
* Promotional tools.

Tutor monetisation
Support multiple ways for tutors to earn:

1. Individual tutoring sessions.
2. Group lessons.
3. Paid courses.
4. Downloadable study materials.
5. Revision packages.
6. Exam preparation programmes.
7. Recurring tutoring subscriptions, where appropriate.
8. Paid workshops and educational events.
9. Structured learning programmes.

Give tutors control over their offerings and pricing within applicable platform policies.
Tutor business growth
Consider providing:

* Public tutor storefronts.
* Course catalogues.
* Promotional links.
* Student referrals.
* Discount codes.
* Bundled lessons.
* Subscription packages.
* Customer management.
* Income reporting.
* Business performance insights.

A successful tutor should be able to build a recognisable educational business around Dot.Tutor.
The platform should create opportunities for tutors to grow beyond trading hours for money.
8. Course Creation and Digital Learning Marketplace
Tutors should be able to create, publish and sell their own courses.
Build a complete course management system.
Course creation
Allow tutors to create courses containing:

* Course title.
* Description.
* Subject.
* Academic level.
* Curriculum.
* Language.
* Learning objectives.
* Prerequisites.
* Course structure.
* Sections.
* Lessons.
* Videos.
* Documents.
* Images.
* Presentations.
* Practice activities.
* Quizzes.
* Assessments.
* Downloadable materials.

Make course creation intuitive, even for tutors who have never published an online course.
Course monetisation
Support:

* Free courses.
* Paid courses.
* Single purchases.
* Course bundles.
* Discounted courses.
* Subscriptions where appropriate.
* Tutor-controlled pricing.
* Coupons and promotions.

Clearly display:

* Course price.
* Course content.
* Tutor identity.
* Language.
* Academic level.
* Course duration or estimated workload.
* Learning objectives.
* Requirements.
* Refund policy.

Student learning experience
Students should be able to:

* Enrol in courses.
* Access purchased content.
* Track progress.
* Resume where they left off.
* Complete lessons.
* Attempt quizzes.
* Download permitted resources.
* Receive completion certificates where supported.
* Review courses.

Protect paid course content through appropriate access controls.
Do not rely on frontend-only restrictions to protect purchased material.
9. Study Material Sharing and Educational Resource Library
Build an educational resource ecosystem where knowledge can be shared.
Students and tutors should be able to upload relevant material.
Support:

* Study notes.
* Revision guides.
* Past examination papers.
* Summaries.
* Flashcards.
* Presentations.
* Worksheets.
* Practice questions.
* Educational videos.
* Diagrams.
* Reference documents.
* Lesson plans.

Resource discovery
Organise resources by:

* Country.
* Curriculum.
* Academic level.
* Grade.
* Institution where relevant.
* Subject.
* Topic.
* Language.
* Resource type.
* Difficulty.

Resource contribution
Allow users to:

* Upload resources.
* Describe their materials.
* Tag relevant topics.
* Share free materials.
* Sell original educational resources where permitted.
* Recommend resources.
* Report incorrect material.
* Suggest improvements.

Introduce quality controls.
Resources should have clear ownership, licensing information and appropriate moderation.
Do not allow the distribution of copyrighted examination material or other content without appropriate rights.
Verified educational resources
Provide a mechanism for trusted tutors, educators and moderators to review resources.
Distinguish:

* Community-contributed material.
* Tutor-created material.
* Reviewed educational material.
* Officially sourced material.

Never display a verification label unless a real review process has occurred.
10. Multilingual Learning: Remove Language Barriers
Multilingual education must be one of Dot.Tutor's defining strengths.
Language should not prevent someone from accessing knowledge or finding a tutor.
Build the platform around multilingual discovery and learning.
Language support
Support a configurable multilingual architecture.
South African language priorities should include:

* English.
* isiZulu.
* isiXhosa.
* Afrikaans.
* Sepedi.
* Setswana.
* Sesotho.
* Xitsonga.
* siSwati.
* Tshivenda.
* isiNdebele.

Design the architecture to support additional African and international languages.
Language-aware matching
A student should be able to specify:

* Preferred interface language.
* Preferred teaching language.
* Languages they understand.
* Languages they are learning.
* Whether they are comfortable with bilingual instruction.

Tutor discovery should prioritise actual language compatibility.
A student who needs Mathematics explained in isiZulu should be able to find tutors who can genuinely teach in isiZulu.
Do not match users based on interface language alone.
Translation and multilingual content
Where technically supported, provide:

* Interface translations.
* Resource translation assistance.
* Course subtitle support.
* Lesson transcript translation.
* Discussion translation.
* Tutor profile translation.
* AI-assisted explanations in a preferred language.

Preserve the original content and allow users to compare it with translated versions.
Translation quality must be communicated honestly.
Educational terminology, formulas and technical concepts must be preserved accurately.
Remove unnecessary language restrictions
Do not prevent users from accessing the platform simply because they speak different languages.
Instead:

* Make discovery language-aware.
* Allow multilingual discussions where practical.
* Offer optional translation.
* Recommend tutors who can bridge language gaps.
* Clearly label the original language of resources and courses.

The objective is to remove language barriers, not create new ones.
11. Learning Rooms, Study Groups and Collaboration
Go beyond static academic discussion boards.
Create interactive spaces where students can learn together.
Potential capabilities:

* Subject-specific study groups.
* Exam preparation groups.
* Group tutoring rooms.
* Live discussions.
* Shared study resources.
* Group assignments.
* Collaborative revision.
* Study sessions.
* Tutor-led workshops.
* Question-and-answer sessions.
* Peer learning.

Allow tutors to create paid or free learning rooms.
Support room-level permissions, enrolment rules, moderation and scheduling.
Students should be able to discover relevant rooms without navigating a complicated community interface.
The environment should encourage genuine learning rather than social media engagement.
Avoid likes, popularity contests and engagement mechanics that distract from education.
Use meaningful indicators such as:

* Questions resolved.
* Lessons completed.
* Resources contributed.
* Learning milestones.
* Study group participation.

12. Live Tutoring and Virtual Classrooms
Investigate how Dot.Tutor can provide a reliable online learning environment.
Where technically feasible, support:

* Live video lessons.
* Audio communication.
* Screen sharing.
* Digital whiteboards.
* Shared documents.
* Lesson recordings with appropriate consent.
* Chat.
* File sharing.
* Interactive exercises.
* Group sessions.
* Tutor presentation tools.

A virtual classroom should make teaching and learning easier.
Consider:

* Scheduling and reminders.
* Student attendance.
* Tutor lesson notes.
* Lesson resources.
* Post-lesson summaries.
* Follow-up assignments.

Use reliable third-party services or suitable infrastructure.
Do not build fake video interfaces or claim real-time capabilities that do not exist.
For younger learners, implement age-appropriate controls and appropriate adult involvement.
13. Assessments, Practice and Learning Progress
Dot.Tutor should help students understand whether they are actually improving.
Consider:

* Practice quizzes.
* Topic assessments.
* Revision exercises.
* Flashcards.
* Question banks.
* Mock examinations.
* Progress tracking.
* Learning goals.
* Topic-level strengths and weaknesses.
* Study recommendations.

Allow tutors to create assessments for their students.
Students should be able to view their progress in an understandable way.
Avoid reducing learning to meaningless scores or competitive leaderboards.
Provide useful insights such as:
"You have improved in algebra, but quadratic equations still need attention."
AI-generated academic feedback must be grounded in actual student responses and clearly distinguish guidance from verified assessment results.
14. AI Learning Assistant
Investigate opportunities for AI to improve learning without replacing human tutors.
Potential capabilities:
Student assistance

* Explain difficult concepts.
* Simplify complex topics.
* Generate practice questions.
* Create revision summaries.
* Explain mistakes.
* Provide study plans.
* Help students understand uploaded materials.
* Translate explanations.
* Recommend relevant tutors and resources.

Tutor assistance

* Generate lesson outlines.
* Create quizzes.
* Prepare revision exercises.
* Organise courses.
* Create course descriptions.
* Generate educational summaries.
* Identify topics students are struggling with.
* Suggest teaching resources.

AI guardrails
AI must:

* Avoid presenting fabricated facts as educational truth.
* Provide sources or explanations where appropriate.
* Respect the relevant curriculum and academic level.
* Adapt language and complexity to the learner.
* Encourage understanding instead of simply giving answers.
* Protect student information.
* Avoid completing prohibited assessments or facilitating academic dishonesty.

For younger learners, use appropriate safeguards and age-aware response behaviour.
Integrate Dot.Agents, Dot.Brain and Dot.Memory only through actual supported APIs.
Do not simulate AI integrations or invent capabilities.
15. Academic Integrity, Child Safety and Trust
Because Dot.Tutor will serve learners from Grade 1 onwards, safety must be foundational.
Implement age-appropriate experiences and safeguarding controls.
Consider:

* Age verification where appropriate.
* Parent or guardian involvement for minors.
* Guardian-approved bookings and payments.
* Restricted communication for minors.
* Verified tutor identity.
* Appropriate tutor screening.
* Reporting and moderation.
* Protection against harassment.
* Restricted sharing of personal contact details.
* Safe virtual classroom controls.
* Document and media moderation.
* Escalation procedures.
* Audit trails.

Do not assume that every user is an adult.
Clearly separate features available to minors from those available to adult students.
Implement appropriate safeguards for one-to-one tutoring, direct messages, live lessons and paid services.
Research applicable child protection, education, privacy and consumer protection requirements in South Africa and other supported jurisdictions.
Do not make legal compliance claims without verifying the relevant requirements.
Academic integrity
Support learning without facilitating cheating.
Create policies and controls around:

* Plagiarism.
* Copyright infringement.
* Examination misconduct.
* Impersonation.
* Misleading tutor qualifications.
* Fraudulent course sales.
* Fake reviews.
* Misrepresented educational resources.

16. Tutor Verification and Educational Quality
Trust is essential to a platform where students pay for educational assistance.
Create clear tutor verification levels.
Potential categories:

* Student peer tutor.
* Experienced independent tutor.
* Verified qualified tutor.
* Professional educator.
* Institution-affiliated educator.

Verification must be based on evidence and a real review process.
Allow tutors to submit relevant qualifications and experience.
Display verification status accurately.
Do not imply that a peer tutor holds professional teaching credentials.
Build fair review and dispute processes.
17. Payments, Commissions and Tutor Earnings
Build a flexible financial system that supports the tutoring economy.
Support:

* Individual tutoring payments.
* Group lessons.
* Course purchases.
* Digital resource sales.
* Tutor subscriptions.
* Refunds.
* Platform commissions.
* Tutor payouts.
* Promotional credits.
* Discount codes.
* Transaction history.
* Financial reporting.

Tutors should clearly understand:

* Their selling price.
* Platform commission.
* Other applicable charges.
* Net earnings.
* Pending balances.
* Available balances.
* Payout status.

Students should receive transparent pricing before committing to a purchase.
Use real payment gateway integrations.
Verify payments server-side and protect against duplicate transactions, fraudulent callbacks and unauthorised refunds.
Integrate Dot.Finance only where real APIs and clear integration contracts exist.
18. Tutor Marketplace and Business Growth
Give tutors the tools to establish a professional educational presence.
Create tutor storefronts containing:

* Tutor biography.
* Subjects.
* Academic levels.
* Languages.
* Qualifications.
* Services.
* Courses.
* Study materials.
* Pricing.
* Availability.
* Reviews.
* Booking options.

Allow tutors to build a recognisable identity.
Consider tools for:

* Promotional pages.
* Shareable tutor profiles.
* Referral links.
* Course bundles.
* Student management.
* Recurring bookings.
* Lesson packages.
* Business analytics.
* Income reporting.

The platform should support a progression from student peer tutor to independent tutor to professional educational business.
Make this progression achievable without forcing every tutor to become a registered business immediately.
19. Notifications, Scheduling and Communication
Create reliable communication workflows.
Support:

* Lesson booking confirmations.
* Lesson reminders.
* Booking changes.
* Tutor availability.
* Course enrolment.
* Payment confirmations.
* Assignment reminders.
* Study group updates.
* Resource approvals.
* Tutor application updates.
* Payout notifications.

Support appropriate in-app, email and push notifications, with SMS where necessary.
Provide clear communication preferences.
For minors, apply the appropriate guardian and communication restrictions.
Avoid unnecessary notifications and duplicate messages.
20. Admin and Educational Operations Command Centre
Build a comprehensive administration environment.
Administrators should be able to manage:
Platform users

* Students.
* Tutors.
* Guardians where applicable.
* Educational institutions.
* Account restrictions.
* Verification.
* Suspensions.
* Reports.

Academic structure

* Countries.
* Curricula.
* Academic levels.
* Grades.
* Subjects.
* Topics.
* Institutions.
* Languages.

Educational content

* Learning rooms.
* Courses.
* Study materials.
* Resource moderation.
* Content ownership.
* Copyright complaints.
* Educational quality reviews.

Marketplace operations

* Tutor bookings.
* Course sales.
* Payments.
* Commissions.
* Refunds.
* Payouts.
* Disputes.

Safeguarding

* Safety reports.
* Minor account controls.
* Tutor verification.
* Communication restrictions.
* Incident escalation.
* Audit history.

Business intelligence

* Active students.
* Active tutors.
* Tutor earnings.
* Course sales.
* Lesson completion.
* Academic engagement.
* Learning resource usage.
* Language distribution.
* Geographic distribution.
* Revenue.
* Retention.
* Student satisfaction.

All dashboard metrics must come from real data.
Do not display fake numbers or simulated operational activity.
21. Dot Ecosystem Integration
Dot.Tutor should integrate naturally into the wider Dot ecosystem.
Investigate opportunities with:

* InfoDot: Central authentication and user identity.
* Dot.Files: Secure study material and course document storage.
* Dot.Finance: Financial insights for tutors who operate businesses.
* Dot.Notify: Notifications and reminders.
* Dot.Agents: AI-assisted learning and tutoring workflows.
* Dot.Brain: Contextual educational intelligence where supported.
* Dot.Memory: User-approved learning preferences and context where supported.
* Dot.Analytics: Educational and tutor business insights.
* Dot.Pulse: Educational discovery and relevant community interactions.
* Dot.Engage: Formal agreements or engagements where genuinely relevant.

Do not duplicate the responsibilities of other Dot applications.
All integrations must use actual APIs, supported authentication and documented permissions.
InfoDot remains the central identity and authentication gateway. Do not create a separate competing authentication system.
22. Backend Architecture and Code Quality
Use Laravel Boost as the primary backend engineering assistant.
Inspect the existing Laravel architecture before implementing changes.
Build clean, maintainable and scalable domain boundaries around:

* User profiles.
* Student records.
* Tutor profiles.
* Guardians and safeguarding.
* Academic structures.
* Curricula.
* Languages.
* Subjects.
* Tutor discovery.
* Matching.
* Bookings.
* Scheduling.
* Learning rooms.
* Study groups.
* Courses.
* Course content.
* Educational resources.
* Assessments.
* Learning progress.
* Payments.
* Commissions.
* Payouts.
* Reviews.
* Notifications.
* Moderation.
* Administration.

Prioritise:

* Strong validation.
* Policy-based authorisation.
* Tenant and ownership isolation.
* Secure file handling.
* Queue-based processing.
* Event-driven workflows where useful.
* Database optimisation.
* Transaction integrity.
* Idempotency.
* Audit logging.
* API consistency.
* Automated testing.

Design the academic structure to support multiple countries and education systems without hardcoding one curriculum into the database.
Support multilingual content properly, including original language, translated versions and translation provenance.
Protect course and resource ownership and access rights.
Do not overengineer the system or introduce microservices without a genuine technical requirement.
23. Accessibility, Mobile Experience and Performance
The platform must work exceptionally well on mobile devices.
Many learners will access Dot.Tutor through smartphones and varying network conditions.
Prioritise:

* Mobile-first navigation.
* Lightweight page loading.
* Responsive learning rooms.
* Accessible forms.
* Readable typography.
* Clear touch targets.
* Low-bandwidth behaviour.
* Efficient media delivery.
* Resumable uploads where appropriate.
* Accessible video and audio controls.
* Keyboard navigation.
* Screen-reader support.
* Reduced-motion preferences.

The interface should adapt to the user's academic level and role without compromising its visual consistency.
A Grade 3 learner should not see the same complexity as a university tutor managing multiple courses and students.
24. Implementation Roadmap
Work in controlled phases.
Phase 1 — Discovery and Audit

* Inspect the existing application.
* Identify working and broken features.
* Review architecture and data structures.
* Research competitors.
* Discover and inspect installed design and motion skills.
* Identify safety and compliance requirements.

Phase 2 — Product Architecture and Experience Design

* Define student and tutor journeys.
* Define academic structures.
* Design learning rooms.
* Define multilingual architecture.
* Establish the visual design system.
* Define permissions and safeguarding.

Phase 3 — Core Tutoring Marketplace

* Tutor profiles.
* Student discovery.
* Intelligent matching.
* Bookings.
* Scheduling.
* Messaging.
* Reviews.
* Payments.

Phase 4 — Learning Communities

* Grade and university rooms.
* Subject communities.
* Study groups.
* Questions and answers.
* Resource sharing.
* Moderation.

Phase 5 — Tutor Business and Course Marketplace

* Tutor dashboards.
* Course creation.
* Course sales.
* Digital study materials.
* Earnings.
* Payouts.
* Tutor storefronts.

Phase 6 — Multilingual and AI Learning

* Multilingual interface.
* Language-aware discovery.
* Translation capabilities.
* AI learning assistance.
* Tutor productivity assistance.

Phase 7 — Advanced Education

* Virtual classrooms.
* Assessments.
* Learning progress.
* Group tutoring.
* Workshops.
* Advanced learning analytics.

Phase 8 — Safety, Quality and Production Readiness

* Safeguarding verification.
* Security testing.
* Payment testing.
* End-to-end workflows.
* Performance testing.
* Accessibility review.
* Production readiness.

Prioritise foundations and complete workflows over superficial feature expansion.
25. Mandatory End-to-End Acceptance Tests
Student journey

1. Register and authenticate through InfoDot.
2. Select country and academic curriculum.
3. Select academic level and subjects.
4. Set preferred languages.
5. Search for tutoring.
6. Receive relevant tutor recommendations.
7. Review tutor qualifications and pricing.
8. Book a tutoring session.
9. Complete payment.
10. Attend the lesson.
11. Review the tutor.
12. Track learning progress.

Tutor journey

1. Register.
2. Create a tutor profile.
3. Select subjects and academic levels.
4. Specify languages.
5. Submit verification documents where applicable.
6. Configure availability.
7. Set pricing.
8. Receive a tutoring request.
9. Accept the booking.
10. Deliver the lesson.
11. Track earnings.
12. Receive or track payout.

Course creator journey

1. Create a course.
2. Add sections and lessons.
3. Upload learning material.
4. Configure course language and academic level.
5. Set pricing.
6. Publish.
7. Receive enrolments.
8. Track student progress.
9. Receive earnings.

Learning community journey

1. Enter the appropriate academic room.
2. Select a subject.
3. Ask a question.
4. Share study material.
5. Receive useful answers.
6. Find relevant tutors and courses.
7. Report inappropriate content.

Multilingual journey

1. Select a preferred interface language.
2. Search for a tutor in a preferred teaching language.
3. Discover compatible tutors.
4. Access a resource in its original language.
5. Use supported translation functionality.
6. Verify that the original content remains accessible.

Safety journey

1. Create an age-appropriate student account.
2. Apply required guardian controls.
3. Attempt restricted interactions.
4. Verify that permissions are enforced server-side.
5. Report an unsafe interaction.
6. Confirm that the moderation and escalation workflow operates correctly.

Test failure conditions as well as successful workflows.
26. Definition of Done
Dot.Tutor must not be considered complete until:

1. The existing application has been comprehensively audited.
2. The student and tutor experiences are both intuitive.
3. The academic structure supports Grade 1 through university and postgraduate levels.
4. Academic rooms are functional and properly organised.
5. Tutor discovery and matching work end to end.
6. Tutors can build professional profiles.
7. Tutors can manage bookings and earnings.
8. Tutors can create and sell courses.
9. Students can access purchased courses.
10. Users can upload and discover educational resources.
11. Resource ownership and permissions are protected.
12. Multilingual functionality works beyond simply changing interface labels.
13. Language-aware tutor discovery works.
14. Learning communities are functional and moderated.
15. Payments and payouts use real integrations.
16. Child safety and age-appropriate restrictions are enforced.
17. AI capabilities work through genuine integrations where implemented.
18. InfoDot authentication is correctly integrated.
19. Mobile usability and accessibility are properly tested.
20. Critical automated tests pass.
21. There are no known critical security, payment or data-integrity issues.
22. No feature is presented as complete when it is only a mockup.

27. Final Implementation Report
At completion, provide a comprehensive report covering:

* Initial platform audit.
* Competitive research findings.
* Student experience improvements.
* Tutor experience improvements.
* Academic room architecture.
* Tutor matching implementation.
* Course marketplace.
* Study material library.
* Multilingual capabilities.
* AI integrations.
* Safeguarding and child protection.
* Payment and commission architecture.
* Dot ecosystem integrations.
* Frontend and motion skills actually applied.
* Laravel Boost backend improvements.
* Security improvements.
* Performance improvements.
* Test results.
* Remaining limitations.
* Production risks.
* Recommended next improvements.

Clearly distinguish implemented, partially implemented and planned functionality.
Final Product Standard
I want Dot.Tutor to become a platform where:

* A Grade 4 learner can find help understanding Mathematics.
* A Grade 12 student can find someone to prepare with for final examinations.
* A university student can help another student understand a difficult subject and earn money.
* A qualified educator can build a profitable tutoring business.
* A tutor can create a course once and sell it to many students.
* Someone who speaks isiZulu, isiXhosa or another supported language can find educational assistance without being disadvantaged by language.
* A student can find a community where asking questions is encouraged rather than embarrassing.
* A parent can trust the platform's safeguards when their child uses it.

The platform must feel accessible to a young learner but powerful enough to support a professional educational business.
Do not measure success by the number of features added. Measure it by how easily students find the help they need, how effectively they learn and how successfully tutors can build sustainable income.
Dot.Tutor — Share Knowledge. Unlock Potential. Earn Through Teaching.
Build a platform that makes education more accessible and gives knowledge the opportunity to create value.
