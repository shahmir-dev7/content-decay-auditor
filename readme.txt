=== Content Decay Auditor ===
Contributors: shahmir-dev7
Tags: content audit, stale content, seo, refresh, editorial
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds old, thin or stale posts and builds a prioritised refresh queue.

== Description ==
* Decay score from last update, age, word count and broken internal links
* Ranked refresh queue with one-click "Mark reviewed"
* Batch scanning that resumes where it stopped; weekly via WP-Cron
* WP-CLI: ``wp decay scan``, ``wp decay report``, ``wp decay review``
* No external services, no tracking, nothing on the front end

== Installation ==
Upload the plugin, activate it, then open Tools > Content Decay.

== Changelog ==
= 1.0.0 =
* Initial release.