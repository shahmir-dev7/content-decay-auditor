# Testing: Content Decay Auditor 1.0.0

Runs on 2026-10-26, Windows host, Docker Desktop, wp-env, WordPress 7.1.3.

| Check | Result |
|---|---|
| `php -l` | clean |
| PHPCS (WordPress-Extra, Docs, PHPCompatibilityWP `7.4-`) | 0 errors, 0 warnings (`tests/` excluded) |
| PHPStan level 6 | 0 errors (level 8 not attempted) |
| Unit, no WordPress (`phpunit.unit.xml`) | 5 tests, 14 assertions OK |
| WordPress suite, PHP 7.4.33 | 21 tests, 81 assertions OK |
| WordPress suite, PHP 8.3 (fresh wp-env project) | 21 tests, 81 assertions OK; **89.21% line coverage** on `includes/` (248/278) |
| Plugin Check on the release zip (PHP 8.3 site) | 0 errors, 0 warnings |
| Playwright, Chromium (zip installed on a fresh site) | 1 passed: activate, scan, queue, mark reviewed, change setting, deactivate, delete, clean database |
| Queries per front-end request, plugin off vs on (5 samples each) | `/sample-page/` 8 vs 8; `/missing-xyz/` 21 vs 21: **+0** |
| Package | zip built from `.distignore`, installed via WP-CLI |

Release zip SHA-256: `A2E53616AE5BB257E82BFCF35CF035A6BBAA0EB5AE7EDFB32CCCC0ED877FBBAB`.

A path-traversal test (`/wp-content/../wp-config.php`) was confirmed to FAIL on the earlier code and PASS after the realpath fix.

## Coverage gaps
`Lifecycle` (multisite branch) and `Plugin::init` (runs before coverage starts) account for most uncovered lines.

## Not verified (honest limits)
- **Multisite** network activation/uninstall branches: not run.
- **PHP × WordPress matrix:** only PHP 7.4.33 and 8.3 on WordPress 7.1.3 locally. WordPress latest-1 and PHP 8.4+ are in `.github/workflows/ci.yml` as **CI-only, not locally verified**; that workflow has not been run.
- **PHPStan level 8** not attempted.
- **Link checker accuracy** is a heuristic, tested on a handful of URL shapes, not on a real site with custom routes.
- **Large archives:** not benchmarked with thousands of posts. Batching is tested with 105 posts.
- **WP-Cron actually firing** on schedule: only the scheduling logic is tested.
- **Coverage** was measured on PHP 8.3; pcov could not be installed on the PHP 7.4 container.
- Chromium only, one scenario.

## Running
The `tests-cli` container needs PHPUnit 9.6 and `yoast/phpunit-polyfills` (see README > Development), and `WP_TESTS_PHPUNIT_POLYFILLS_PATH`.

## Browser test re-verified (2026-10-26)
The browser test's delete step originally had a race (it could click Delete before WordPress's JavaScript loaded and follow a confirmation link that deletes nothing) and a check that could pass falsely. Both were fixed: the test now waits, confirms from the plugin list that the plugin is gone, and uses a positive control for the clean-database check. It passes on the final zip. The release zip changed only in the author header since the numbers above were taken; tests were not otherwise re-run on it beyond Plugin Check (0 errors) and this browser test.
