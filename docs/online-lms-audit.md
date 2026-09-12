# Online LMS scope migration — 12 September 2026

## Audit and dependency decisions

The application uses Laravel 13, PHP 8.3, MySQL, server-rendered Blade, and local CSS/JavaScript. There is no separate frontend build or API application. The audit covered routes, controllers, requests, policies, models, services, jobs, commands, migrations, seeders, factories, views, assets, configuration, documentation and tests.

| Area | Existing dependency | Decision |
| --- | --- | --- |
| Login/navigation | Role assignments route people to the university workspace | Use the LMS dashboard and account roles only |
| Course access | Linked sections use active program registrations and selected role contexts | Convert active registrations to online enrollments; preserve active co-instructors |
| Content and assessments | Courses own lessons, materials, assignments, quizzes, submissions and grades | Preserve records and identifiers; remove section/context checks |
| Study notes | Generation checks a course policy; delivery has a section-specific branch | Apply online enrollment policy consistently; keep private ownership |
| Assessment locking | Shared academic lock introduced for timetable races | Retain a generic LMS write lock for grading, enrollment and permissions |
| University ERP | Organization, curriculum, terms, holds, registration approval, room booking, attendance and timetables | Remove routes, services, models, requests, views, seeders and permissions; drop obsolete tables after converting access |
| Historical migrations | Campus creation migrations would recreate obsolete schema on new installations | Move copies into upgrade-test fixtures, outside the application's migration path |
| UI | Shared navigation has university labels; radio inputs inherit full-width text styles; pagination expects unavailable Tailwind utilities | Use shared online navigation, native choice controls, branded pagination and consistent responsive layouts |

No payments, subscriptions, certificates, question bank, discussions, ratings, category management, video player, or separate online exam module were implemented at audit time. They are not removed or represented as completed. Quizzes and assignments remain available as online assessments. This migration does not establish production readiness by itself.

Before removal, source and database backups are taken outside the application. Retain these backups securely for rollback. No Git operations or dependency upgrades are part of this work.
