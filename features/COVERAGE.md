# cron-command Behat coverage

Run like the other package suites: copy `features/` into a core checkout's `features/` and run `vendor/bin/behat` from the core root as root (`tools/run-behat.sh` pattern). Useful tag filter: `--tags='~@destructive'` (never stops, removes or deliberately breaks the shared scheduler; every cron change still restarts it and regenerates the config, and on a machine with no other cron jobs CRON-1 still removes or loops it when the suite's last job goes). Don't use `--stop-on-failure` or interrupt a run: Behat then skips the `@AfterFeature` cleanup that restores the scheduler and deletes the suite's sites.

Totals: 7 features, 110 scenarios (outline examples expanded). `@destructive`: 7, `@le`: 0, `@remote`: 0. No `@known-bug` scenarios ship here: the 8 scenario rows written for open bugs (CRON-3, CRON-5, CRON-7, CRON-8, CRON-9, CRON-10) are held back until their fixes land and then return as regular scenarios. Neither Let's Encrypt nor rclone is involved in cron-command.

Estimated runtime: about 30 minutes for the full suite, dominated by site creation (about 18 php/html sites and one WordPress site), site deletion, the scheduler restart on every cron change, and the schedule-driven waits (two minute-boundary waits, several 10 to 30 second stay-up checks).

Environment: sites are `bh-cron-command-<n>.test` (n = 1 to 16). Host jobs carry `bh-cron-command-` in their command. The `@AfterFeature` hook deletes only those jobs (in SQL, then one `ee cron delete` so the config is regenerated and the scheduler restarted once) and sites, removes `/tmp/bh-cron-command-*` markers, and starts or recreates `services_global-cron_1` if it existed before the feature or a step removed it (CRON-1 removes it). After every `@destructive` scenario a hook deletes the suite's host jobs that have a user (CRON-4 crash loop) and starts or recreates the scheduler; after every scenario another deletes the suite's jobs that fire every few seconds, in case the scenario failed before its own delete step. The two CRON-1 scenarios need an empty cron table and fail on their guard step when jobs not owned by the suite exist (any WordPress site has one).

## Inventory

### Commands, arguments and flags

| Item | Covered by |
|---|---|
| `ee cron` (usage, subcommand list) | cron.feature: "ee cron lists its subcommands", "An unknown cron subcommand is rejected" |
| `ee help cron <sub>` | cron.feature: "ee help documents every cron subcommand" |
| `cron create [<site-name>]` site | cron-create.feature: "A five-field schedule gets a seconds field ..." and most others |
| `cron create host` | cron-create.feature: "A host job is a job-local without container or user"; cron-scheduler.feature: host scenarios |
| `cron create` site from cwd (auto_site_name) | cron-create.feature: "The site is detected from the current directory", "A wrong site name inside a site directory suggests the detected site" |
| `cron create` without site / unknown site | cron-create.feature: "Without a site name outside a site directory ...", "An unknown site is rejected" |
| `cron create` trailing slash | cron-create.feature: "A trailing slash on the site name is ignored" |
| `cron create` too many positionals / unknown flag | cron-create.feature: "Too many positional arguments are rejected", "An unknown flag is rejected" |
| `--command` required, `sh -c` wrapping, pre-wrapped command, `&&`/pipes/redirects | cron-create.feature: "Required flags are enforced", "A five-field schedule ...", "A command that already uses sh -c is not wrapped again", "Pipes, redirects and && are accepted" |
| `--command` with `;` or `#` rejected | cron-create.feature and cron-update.feature: "Commands that break the INI config are rejected" |
| `--command` with a single quote | not covered yet: CRON-5 (its scenario comes back with the fix) |
| `--schedule` required | cron-create.feature: "Required flags are enforced" |
| `--schedule` five fields (seconds field prepended), whitespace | cron-create.feature: "A five-field schedule ...", "Extra whitespace between schedule fields is tolerated" |
| `--schedule` helpers `@yearly @annually @monthly @weekly @daily @midnight @hourly @every <d>` | cron-create.feature: "Schedule helpers are stored verbatim" (9 examples) |
| `--schedule` wrong field count / empty | cron-create.feature: "Schedules without exactly five fields are rejected", "An empty schedule is rejected" |
| `--schedule` invalid helper or values | cron-create.feature: "Invalid schedules are rejected at creation" (CRON-2, fixed in v4.13.1) |
| `--user` (default www-data for sites, custom) | cron-create.feature: "A five-field schedule ...", "A custom user is stored ..."; cron-run-now.feature: user scenarios |
| `--user` on host jobs | cron-scheduler.feature: "A host job with --user keeps the scheduler running"; cron-update.feature: "Setting a user on a host job keeps the scheduler running" (both `@destructive`, CRON-4 fixed in v4.13.1) |
| `create` on html site (no php) | cron-create.feature: "A site without a php container is rejected" |
| `create` on disabled site | cron-create.feature: "A disabled site is rejected" |
| `create` duplicate job | cron-create.feature: "Creating the same job twice stores two independent jobs" |
| `cron list <site>` | cron-list.feature: "Listing a site shows its jobs with every column", "Listing a site shows only that site's jobs" |
| `cron list host` | cron-list.feature: "Listing host shows only host jobs" (CRON-12, fixed in v4.13.1: any listed host job without a user fails with "Invalid field: user.") |
| `cron list --all`, site + `--all` | cron-list.feature: "--all lists site and host jobs together" (CRON-12, fixed in v4.13.1), "A site argument wins over --all" |
| `cron list` from cwd / no site / unknown site | cron-list.feature: "The site is detected from the current directory", "Without a site name ...", "An unknown site is rejected" |
| `cron list` empty result | cron-list.feature: "A site without jobs reports that nothing was found" |
| `cron list` disabled site | cron-list.feature: "Listing a disabled site still works" |
| `cron list <site>/` | not covered yet: CRON-10 (its scenario comes back with the fix) |
| `cron list --all` with an empty table | not covered: needs an empty table plus a listing, only meaningful on a dedicated machine |
| `http://`/`https://` site names, `update --site=<site>/`, extra positionals on update/run-now/list, `list host --all` | not covered: minor argument handling (`auto_site_name()` strips the scheme only for its lookup, so create/list then fail or find nothing) |
| `cron update <id>` usage without id | cron.feature: "Subcommands that need an id print their usage without one" |
| `update --command` | cron-update.feature: "Updating the command wraps it and regenerates the config", "Updating a host job's command" |
| `update --user` | cron-update.feature: "Updating the user"; on a host job "Setting a user on a host job keeps the scheduler running" (`@destructive`, CRON-4 fixed in v4.13.1) |
| `update --schedule` helpers | cron-update.feature: "Updating to a schedule helper stores it verbatim" |
| `update --schedule` five fields | cron-update.feature: "Valid five-field schedules are accepted by update like by create", "A garbage schedule is rejected by update" (CRON-2, fixed in v4.13.1), "A five-field schedule gets a seconds field on update too" (CRON-11, fixed in v4.13.1), "A four-field schedule is rejected"; cron-scheduler.feature: "A job updated to a five-field schedule still fires once a minute" (CRON-11, fixed in v4.13.1) |
| `update --site` | cron-update.feature: "Moving a job to another site"; unknown site and html site (CRON-6, fixed in v4.13.1); `--site=host` (`@destructive`, CRON-4 fixed in v4.13.1) |
| `update` several flags, idempotency | cron-update.feature: "Updating several fields at once", "Updating a job twice with the same values is harmless" |
| `update` without flags / unknown flag | cron-update.feature: "At least one field is required", "An unknown flag is rejected" |
| `update` unknown id | cron-update.feature: "Updating an unknown id fails" (CRON-6, fixed in v4.13.1) |
| `cron delete <cron-id>` | cron-delete.feature: "Deleting a job removes it from the DB and the config and keeps the others", "Deleting a host job", "Deleting a job of a disabled site" |
| `delete` twice / unknown / non-numeric / no id / extra args | cron-delete.feature: "Deleting the same job twice fails the second time", "Unknown ids are rejected", "Too many positional arguments are rejected"; cron.feature usage outline |
| `delete` of the last job | cron-scheduler.feature: "Deleting the last job keeps the scheduler running" (`@destructive`, regression check, CRON-1 fixed in v4.13.1) |
| `cron run-now <cron-id>` site job, default and custom user, webroot, repeat | cron-run-now.feature: first four scenarios |
| `run-now` host job (runs on the host) | cron-run-now.feature: "A host job runs on the host" |
| `run-now` unknown / non-numeric / no id | cron-run-now.feature: "Unknown ids are rejected"; cron.feature usage outline |
| `run-now` exit code of a failing job | not covered yet: CRON-7 (its scenario comes back with the fix) |
| `run-now` disabled site | not covered yet: CRON-7 (its scenario comes back with the fix) |
| `run-now` host PHP warning | not covered yet: CRON-8 (its scenario comes back with the fix) |

### Behaviours and side effects

| Behaviour | Covered by |
|---|---|
| `cron` table rows (site_url, command, schedule, user) | every create/update/delete scenario via sqlite steps |
| Config `{EE_ROOT_DIR}/services/cron/conf/config.ini`: job type, schedule, command, user, container, no-overlap | cron-create.feature, cron-update.feature, cron-delete.feature, cron-scheduler.feature config steps |
| Scheduler restart on create/update/delete | cron-scheduler.feature: first scenario; cron-update.feature and cron-delete.feature first scenarios |
| Ofelia registers the job (and doesn't log "Failed to register job" for it) | cron-scheduler.feature: "Creating a job restarts the scheduler with the new config and ofelia registers it" |
| Jobs actually fire on schedule (as the configured user, second-zero semantics) | cron-scheduler.feature: "A site job runs on schedule ...", "A five-field schedule fires at second zero, not every second" |
| Invalid schedules silently dropped by ofelia | cron-scheduler.feature: "The scheduler never drops a job for an invalid schedule" (CRON-2, fixed in v4.13.1) |
| `create` boots a stopped or removed scheduler (`init_global_container`) | cron-scheduler.feature: "Creating a job starts a stopped scheduler", "Creating a job after the scheduler was removed brings it back" (both `@destructive`) |
| Scheduler with zero jobs (restart loop) | cron-scheduler.feature: "The scheduler stays up when the last site with jobs is deleted" (`@destructive`, regression check, CRON-1 fixed in v4.13.1) |
| `ee site delete` removes the site's jobs and regenerates config (site-command) | cron-scheduler.feature: "Deleting a site removes its jobs from the DB and the config", "Deleting a WordPress site removes its wp-cron job" |
| WordPress site create adds `wp cron event run --due-now` every 10 minutes (site-type-wp) and it runs | cron-scheduler.feature: "A WordPress site gets a wp-cron job that runs" |
| Disable/enable leaves a stale config | not covered yet: CRON-9 (its scenario comes back with the fix) |
| Host jobs run inside the scheduler container, and the documented `wp` example | not covered yet: CRON-3 (its 2 scenarios come back with the fix) |
| Migrations (`create_table_cron`, `no_overlap`, `update_cron_user`, `cleanup_old_entries`, `recreate_crons`) and the core `ee-update-cron-config` update step | not covered: they run once during `ee cli update`/migrate and need a crafted pre-upgrade DB; belongs to a core migration suite |
| macOS paths | not covered: Linux CI only |

Coverage estimate: about 88% of commands, flags and behaviours (every subcommand, positional and flag, every validation branch in `Cron_Command.php`, both helper functions and the site-command/site-type-wp integration points). The gaps are the migrations, an empty-table `list --all` and minor argument handling (scheme-prefixed site names, extra positionals).

## Known bugs

Fixed and covered by regular scenarios (regression checks):

| ID | Scenario(s) |
|---|---|
| CRON-1 | cron-scheduler.feature: "Deleting the last job keeps the scheduler running", "The scheduler stays up when the last site with jobs is deleted" |
| CRON-2 | cron-create.feature: "Invalid schedules are rejected at creation"; cron-update.feature: "Valid five-field schedules are accepted by update like by create" (also needs CRON-11), "A garbage schedule is rejected by update"; cron-scheduler.feature: "The scheduler never drops a job for an invalid schedule" |
| CRON-4 | `user` in a job-local makes ofelia exit ("can't store data at section job-local ... variable user"): cron-scheduler.feature "A host job with --user keeps the scheduler running", cron-update.feature "Moving a site job to host keeps the scheduler running", "Setting a user on a host job keeps the scheduler running" |
| CRON-11 | `update` stores five-field schedules without the seconds field create adds, so ofelia reads `* * * * *` as every second and `0 0 * * *` as hourly: cron-update.feature "A five-field schedule gets a seconds field on update too", cron-scheduler.feature "A job updated to a five-field schedule still fires once a minute" |
| CRON-6 | cron-update.feature: "Updating an unknown id fails", "Moving a job to an unknown site fails", "Moving a job to a site without php fails" |
| CRON-12 | cron-list.feature: "Listing host shows only host jobs", "--all lists site and host jobs together" |

All of them landed in cron-command v2.1.1 (#60, core v4.13.1). The keepalive `ee-keepalive` section never matches the config steps (they select jobs by `<site>-` name prefix).

Open (scenarios held back until fixed):

| ID | Held-back scenario(s) |
|---|---|
| CRON-3 | cron-scheduler.feature: "A host job runs on the host on schedule", "The documented host wp example has the tools it needs" |
| CRON-5 | cron-run-now.feature: "A command with a single quote runs correctly" |
| CRON-7 | cron-run-now.feature: "A failing job makes run-now fail", "Running a job of a disabled site fails with a clear error" |
| CRON-8 | cron-run-now.feature: "Running a host job prints no PHP warnings" |
| CRON-9 | cron-scheduler.feature: "A site's jobs target its php container again after disable and enable" |
| CRON-10 | cron-list.feature: "A trailing slash on the site name is ignored" |
