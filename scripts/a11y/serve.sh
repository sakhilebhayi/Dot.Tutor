#!/usr/bin/env sh
# R-47 a11y (DEVELOPMENT ONLY): the dev server on 8721 over the demo database (every Demo* seeder), assets built.
#   php artisan migrate:fresh && php artisan academic:sync && for s in DemoStudentSeeder DemoOpsSeeder \
#     DemoTutorWorkspaceSeeder DemoCommunitySeeder DemoClassroomSeeder; do php artisan db:seed --class=$s; done
cd "$(dirname "$0")/../.."
npm run build >/dev/null && PHP_CLI_SERVER_WORKERS=4 exec php artisan serve --host=127.0.0.1 --port="${PORT:-8721}"
