<?php

/*
| Shell navigation (ds agent, docs/R47_DS.md section "Navigation").
|
| Each item names candidate routes in order of preference; the first route that exists is linked and an item
| whose routes do not exist yet is not shown. Wave-2 screens light up their place in a shell by registering a
| route with one of these names. "match" is a routeIs() pattern (or a list) that marks the item current.
| "steps" limits an item to the young, standard or dense step; "bottom" puts it in the learner's phone bar.
*/

return [
    'learner' => [
        [
            'label' => null,
            'items' => [
                ['label' => 'Home', 'route' => ['learn.home', 'dashboard'], 'icon' => 'house', 'match' => ['learn.home', 'dashboard'], 'bottom' => true],
                ['label' => 'Find a tutor', 'young_label' => 'Get help', 'route' => ['match.index', 'marketplace.find', 'tutors.browse'], 'icon' => 'search', 'match' => ['match.*', 'marketplace.find', 'marketplace.tutors.*', 'learn.book*', 'ai.need.*', 'tutors.*'], 'bottom' => true],
                ['label' => 'My rooms', 'route' => 'rooms.index', 'icon' => 'library', 'match' => 'rooms.*', 'bottom' => true],
                ['label' => 'Lessons', 'route' => ['learn.lessons', 'marketplace.bookings'], 'icon' => 'calendar', 'match' => ['learn.lessons*', 'marketplace.bookings*', 'sessions.*', 'classroom.*'], 'bottom' => true],
                ['label' => 'Courses', 'route' => ['learn.courses', 'learning.courses.index'], 'icon' => 'book-open', 'match' => ['learn.courses*', 'courses.*', 'learning.courses.*', 'learning.learn', 'learning.certificates.*'], 'steps' => ['standard', 'dense']],
                ['label' => 'Study library', 'route' => ['library.index', 'learning.library.index'], 'icon' => 'book-marked', 'match' => ['library.*', 'learning.library.*']],
                ['label' => 'Practice', 'route' => 'practice.index', 'icon' => 'list-checks', 'match' => ['practice.*', 'learning.assessments.*', 'learning.attempts.*'], 'bottom' => true],
                ['label' => 'Progress', 'route' => 'learn.progress', 'icon' => 'chart-no-axes-column', 'match' => 'learn.progress*'],
                ['label' => 'Study assistant', 'young_label' => 'Ask a question', 'route' => 'ai.index', 'icon' => 'circle-help', 'match' => ['ai.index', 'ai.ask', 'ai.interactions.*']],
                ['label' => 'Messages', 'route' => ['messages.index', 'safety.conversations.index'], 'icon' => 'message-square', 'match' => ['messages.*', 'safety.conversations.*'], 'steps' => ['standard', 'dense']],
            ],
        ],
        [
            'label' => 'You',
            'items' => [
                ['label' => 'Learning profile', 'route' => ['learn.profile', 'academic.profile.edit'], 'icon' => 'graduation-cap', 'match' => ['learn.profile*', 'academic.profile.*', 'learn.onboarding*']],
                ['label' => 'Notifications', 'route' => 'comms.inbox', 'icon' => 'bell', 'match' => ['comms.inbox*', 'comms.preferences*']],
                ['label' => 'Guardian', 'route' => 'guardian.home', 'icon' => 'hand-heart', 'match' => 'guardian.*'],
                ['label' => 'Payments', 'route' => 'money.orders', 'icon' => 'receipt', 'match' => ['money.orders*', 'money.checkout.*', 'money.receipts.*'], 'steps' => ['standard', 'dense']],
                ['label' => 'Account', 'route' => ['learn.account', 'profile.show'], 'icon' => 'settings', 'match' => ['learn.account*', 'profile.show', 'academic.locale.*']],
            ],
        ],
    ],

    'workspace' => [
        [
            'label' => null,
            'items' => [
                ['label' => 'Today', 'route' => ['teach.home', 'dashboard'], 'icon' => 'house', 'match' => ['teach.home', 'dashboard']],
                ['label' => 'Requests and bookings', 'route' => 'teach.bookings', 'icon' => 'inbox', 'match' => ['teach.bookings*', 'sessions.*']],
                ['label' => 'Calendar', 'route' => ['teach.calendar', 'tutor.availability.index'], 'icon' => 'calendar', 'match' => ['teach.calendar*', 'tutor.availability.*']],
                ['label' => 'Students', 'route' => 'teach.students', 'icon' => 'users', 'match' => 'teach.students*'],
                ['label' => 'Messages', 'route' => 'messages.index', 'icon' => 'message-square', 'match' => 'messages.*'],
            ],
        ],
        [
            'label' => 'Your practice',
            'items' => [
                ['label' => 'Offerings', 'route' => 'teach.offerings', 'icon' => 'layers', 'match' => 'teach.offerings*'],
                ['label' => 'Courses', 'route' => 'teach.courses', 'icon' => 'book-open', 'match' => 'teach.courses*'],
                ['label' => 'Materials', 'route' => 'teach.materials', 'icon' => 'folder', 'match' => 'teach.materials*'],
                ['label' => 'Tutor rooms', 'route' => 'teach.rooms', 'icon' => 'library', 'match' => 'teach.rooms*'],
                ['label' => 'Profile and verification', 'route' => ['teach.profile', 'tutor.profile.edit'], 'icon' => 'badge-check', 'match' => ['teach.profile*', 'tutor.profile.*', 'teach.verification*']],
            ],
        ],
        [
            'label' => 'Money',
            'items' => [
                ['label' => 'Earnings', 'route' => 'teach.earnings', 'icon' => 'wallet', 'match' => 'teach.earnings*'],
                ['label' => 'Payouts', 'route' => 'teach.payouts', 'icon' => 'banknote', 'match' => 'teach.payouts*'],
                ['label' => 'Insights', 'route' => 'teach.insights', 'icon' => 'chart-no-axes-column', 'match' => 'teach.insights*'],
            ],
        ],
    ],

    'operations' => [
        [
            'label' => null,
            'items' => [
                ['label' => 'Overview', 'route' => ['ops.home', 'dashboard'], 'icon' => 'house', 'match' => ['ops.home', 'dashboard']],
            ],
        ],
        [
            'label' => 'Quality',
            'items' => [
                ['label' => 'Tutor verification', 'route' => ['ops.verification', 'operator.tutor-profiles.index'], 'icon' => 'clipboard-check', 'match' => ['ops.verification*', 'operator.tutor-profiles.*']],
                ['label' => 'Reviews and disputes', 'route' => 'ops.reviews', 'icon' => 'scale', 'match' => 'ops.reviews*'],
                ['label' => 'Content moderation', 'route' => 'ops.moderation', 'icon' => 'flag', 'match' => 'ops.moderation*'],
            ],
        ],
        [
            'label' => 'Safeguarding',
            'items' => [
                ['label' => 'Reports and incidents', 'route' => 'ops.safeguarding', 'icon' => 'shield-check', 'match' => 'ops.safeguarding*'],
                ['label' => 'Audit log', 'route' => 'ops.audit', 'icon' => 'eye', 'match' => 'ops.audit*'],
            ],
        ],
        [
            'label' => 'Academic structure',
            'items' => [
                ['label' => 'Curricula and levels', 'route' => 'ops.academic', 'icon' => 'school', 'match' => 'ops.academic*'],
                ['label' => 'Subjects', 'route' => ['ops.subjects', 'operator.subjects.index'], 'icon' => 'book-marked', 'match' => ['ops.subjects*', 'operator.subjects.*']],
                ['label' => 'Languages', 'route' => 'ops.languages', 'icon' => 'languages', 'match' => 'ops.languages*'],
            ],
        ],
        [
            'label' => 'Money',
            'items' => [
                ['label' => 'Orders and payments', 'route' => 'ops.payments', 'icon' => 'receipt', 'match' => 'ops.payments*'],
                ['label' => 'Payouts', 'route' => 'ops.payouts', 'icon' => 'banknote', 'match' => 'ops.payouts*'],
            ],
        ],
        [
            'label' => 'People',
            'items' => [
                ['label' => 'Users and roles', 'route' => 'ops.users', 'icon' => 'users', 'match' => 'ops.users*'],
                ['label' => 'Support', 'route' => 'ops.support', 'icon' => 'life-buoy', 'match' => 'ops.support*'],
            ],
        ],
    ],
];
