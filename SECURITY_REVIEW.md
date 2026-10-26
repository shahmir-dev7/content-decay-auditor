# Security review: Content Decay Auditor 1.0.0

Manual review by the author; not independent.

## Superglobals
| Location | Input | Handling |
|---|---|---|
| `Admin::handle_review` | `$_POST['post_id']` | `absint`; nonce action is per post (`cdaudit_review_<id>`); capability is `edit_post` on that post. |
| `Admin::render` | `$_GET['cdaudit_msg']` | `sanitize_key`, then matched against fixed codes or `^(done\|more)-\d+$` and cast to `int`. Display only. |
| (none) | `$_SERVER`, `$_FILES`, `$_COOKIE` | Not used. |

## Endpoints
No REST routes, no `wp_ajax_*`. Two `admin_post_*` actions. Both call `guard()` (capability, then `check_admin_referer`). Not registered for `nopriv`. Menu and screen require `edit_others_posts`. The settings form (`options.php`) is shown only to `manage_options` and the option has a sanitize callback (clamped 1-100).

## Output
Titles, reasons and dates pass `esc_html`/`esc_url`/`esc_attr`; IDs and scores are cast to `int`. Covered by a test that renders a `<script>` title.

## Queries
One custom query (`Scanner::scan_batch`): `$wpdb->prepare` with `%i` for the table, `%s` per post type and `%d` for cursor and size. Everything else uses `WP_Query` and post-meta APIs. No custom tables.

## Files / network
None. Link checking never makes HTTP requests; it only maps URLs to local posts, files, terms and authors (`url_to_postid`, `term_exists`, `get_user_by`, `file_exists` limited to paths under `wp-content` as resolved by `WP_CONTENT_DIR`).

## Findings
1. **Accepted: a scan batch can be slow.** `url_to_postid` per link is not free; a user with `edit_others_posts` can trigger a 100-post batch repeatedly. Trusted role, bounded batch. No timing benchmark on a large archive was done.
2. **Accepted: the queue shows titles of all published posts** to anyone with `edit_others_posts`. Those titles are public already; drafts, private and scheduled posts are excluded.
3. **Fixed during review: path traversal in the file-exists check.** `/wp-content/../wp-config.php` made the checker test a file outside `wp-content` (result was only a boolean, never shown). Now resolved with `realpath` and confined to `WP_CONTENT_DIR`. A test fails on the old code and passes on the fix.
4. **Verified by tests:** subscriber/contributor rejected, wrong-post nonce rejected, escaping, uninstall removal.