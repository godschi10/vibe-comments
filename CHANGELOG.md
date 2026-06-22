# Changelog

All notable changes to **Vibe Comments** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)  
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Types of changes:
- `Added` — new features
- `Changed` — changes to existing functionality
- `Fixed` — bug fixes
- `Removed` — removed features
- `Deprecated` — features that will be removed in a future release
- `Security` — changes that address vulnerabilities

---

## [Unreleased]

---

## [2.0.5] — 2026-06-12

### Added — Features
- **Character counter** — live counter below the textarea shows characters typed vs. limit (default 2000, filterable via `vibe_comments_max_length`). Warns at 90%, turns red at limit. `maxlength` attribute also set on the textarea so the browser enforces it natively.
- **Sort toggle** — "↑ Newest first / ↓ Oldest first" button appears above the comment list after load. Pure DOM reversal of top-level `<li>` children — zero server calls, nested replies stay under their parent.
- **Like pulse animation** — heart icon plays a scale pulse (`@keyframes vibe-like-pulse`) when a comment is liked. Fires on like only, not unlike.

### Added — Security
- **Honeypot spam trap** — hidden `<input name="vibe_hp">` rendered off-screen in the form. Server checks for a non-empty value before the nonce check; bots that fill it receive a convincing fake-success response and stop retrying. Zero UX impact on real users.
- **`author_url` sanitized** — `$user->user_url` now passed through `esc_url_raw()` before storage. Prevents potential `javascript:` URI injection from user profiles.
- **`get_comment_count` post existence check** — invalid `post_id` now returns an error instead of running a DB query against a non-existent post.
- **`refresh_nonce` rate-limited** — 2-second IP cooldown prevents rapid-fire abuse of the nonce endpoint. Always returns a valid nonce even when rate-limited so the UI never stalls.

### Added — Performance
- **2-minute object cache on `get_comment_count`** — reduces DB load on high-traffic pages. Invalidated immediately when a new approved comment is posted or when `purge_page_cache` fires.
- **Skip `refreshNonce()` for logged-in users** — the nonce embedded in the page is fresh for authenticated requests; the async refresh was wasted work.
- **`checkNewComments` per_page 5 → 20** — prevented burst scenarios where 6+ simultaneous new comments would miss everything beyond the first 5. The missed comments were permanently skipped because `lastCheckTime` advanced past them.
- **`AbortController` timeouts on all fetch calls** — 8–15s limits depending on operation. Prevents zombie requests from holding connections open on slow servers.

---

## [2.0.4] — 2026-06-12

### Fixed — Performance (N+1 elimination)
- **`format_comment_tree` / `format_comment_with_children`** — each comment previously fired a `get_comments(['parent' => id])` query for children and a `get_like_count()` query for likes. A thread with 50 comments fired 100+ queries. Now:
  - `Vibe_Comments_Database::get_children_map($post_id)` — one SQL query loads every nested comment for the post and returns a `[parent_id => [children]]` map.
  - `get_like_counts_batch($ids)` — one SQL query fetches all like counts, respects the object cache, and populates the cache for uncached IDs.
  - `get_user_liked_batch($ids, $user_id, $guest_token)` — same pattern for liked state.
  - Both `format_comment_tree` (AJAX) and `format_comment_with_children` (REST) now receive pre-built maps and make zero DB calls per comment.
  - `load_comments` (AJAX) and `get_live_comments` (REST) now run **3 total queries** regardless of thread depth or comment count.
- **Count queries merged** — two separate `get_comments(['count' => true])` calls (top-level + total) replaced with one `SELECT COUNT(*), SUM(comment_parent = 0)` query in both AJAX and REST handlers.
- **`sync_likes`** — replaced per-comment `get_like_count` + `user_has_liked` loop with `get_like_counts_batch` + `get_user_liked_batch`. N sync operations now cost 2 queries total.
- **`get_db()` in REST class** — was creating a new `Vibe_Comments_Database` instance on every call. Now instance-cached via `$this->db_instance`.

### Fixed — Security
- **REST `handle_like`** — added nonce verification (`wp_rest`). Also added comment existence + approved status check before toggling.
- **REST `get_likes_batch`** — added nonce verification.
- **REST `submit_comment`** — added nonce verification; added empty content and max-length (65535) guards matching the AJAX handler.
- **REST `get_live_comments`** — `page` and `per_page` params now clamped (min/max) to prevent edge-case abuse.

### Removed
- **`class-magic-link.php`** — fully implemented but never wired up to any hook, route, or template. Dead file removed entirely.

---

## [2.0.3] — 2026-06-12

### Added
- **Disable Google login option** — new "Enable Google Login" checkbox in Settings → Vibe Comments. Defaults to enabled on existing installs that already have credentials saved; defaults to disabled on fresh installs. Gates the button in the PHP template, the OAuth hook registration, and a JS guard for cached pages that may still render the button.

### Fixed
- **`appendCommentWithChildren` only rendered 2 levels deep** — depth-3 children were silently dropped on "Load More". Now delegates to `buildCommentTree` which is recursive and handles all depths. `knownCommentIds` population also simplified to a single `querySelectorAll` pass.
- **Pointless `new Vibe_Comments_Database()` in `init()`** — the Database constructor has no hook registrations; the object was created and immediately discarded on every request.
- **REST `submit_comment` had no rate limiting** — endpoint was publicly accessible with `permission_callback: __return_true` and no cooldown, allowing bots to bypass AJAX protections entirely. Added 5s IP-based cooldown matching the AJAX handler.
- **REST `/likes-batch` had no rate limiting** — added 2s IP cooldown to prevent bulk enumeration abuse.
- **`ajax_google_auth` return URL unreliable under strict Referrer-Policy** — JS now passes `window.location.href` as `return_url`; PHP validates it's same-host via `wp_validate_redirect` and falls back to `wp_get_referer()`.
- **OAuth username collision** — `wp_rand(100, 999)` has only 900 slots; replaced with `uniqid()` suffix for effectively collision-free usernames.
- **Dead `clear_comment_cache` method in REST class** — defined but never hooked; removed.
- **REST `/test` endpoint live in production** — revealed plugin presence and namespace to any visitor. Gated on `WP_DEBUG` like the debug endpoint.

### Performance
- **`current_time('timestamp', true)` called once per comment** in recursive format functions. Now computed once per request and threaded through both `format_comment_tree` (AJAX) and `format_comment_with_children` (REST) as `$now` parameter.
- **`visibilitychange` fired two AJAX requests on every tab switch** with no throttle. Now gated: only fires if at least 30s have elapsed since the last poll, matching the interval timer cadence.

---

## [2.0.2] — 2026-06-12

### Fixed
- **Cloudflare IP resolution** — `get_remote_ip()` in both AJAX and REST handlers, and `get_guest_token()` in the Database class, now resolve the real client IP via `HTTP_CF_CONNECTING_IP` → `HTTP_X_FORWARDED_FOR` → `REMOTE_ADDR`. Behind Cloudflare, `REMOTE_ADDR` is always the edge IP, meaning all guests previously shared one token — the first guest to like a comment would exhaust the unique DB key for every other guest. New shared static method `Vibe_Comments_Database::resolve_client_ip()` used by all three call sites.
- **Like counts always zero on load** — `format_comment_tree()`, `format_comment_with_children()` were returning hardcoded `'likes' => 0`. Both now call `$db->get_like_count()`. A single `Vibe_Comments_Database` instance is created per request and threaded through recursive calls to avoid N redundant instantiations.
- **Google OAuth redirect** — post-login redirect was using `wp_get_referer()` inside the callback, where the HTTP referer header points to `accounts.google.com`. Return URL is now stored in the state transient during `ajax_google_auth` (before the OAuth redirect) and retrieved reliably on callback.
- **JWT audience not verified** — `decode_jwt_payload()` now checks `$payload['aud'] === $client_id` before accepting a token. Prevents token substitution from another Google OAuth client.
- **`sync_likes` had no nonce check** — added `check_ajax_referer('wp_rest', 'nonce', false)`. The JS already sends the nonce; no client-side change needed.
- **Live polling scroll-hijack** — `checkNewComments()` called `appendComment()` which fired `scrollIntoView` on every polled comment, interrupting reading. `appendComment()` now accepts a `scroll` parameter (default `true`); polling passes `false`.
- **`loadMoreComments` page counter incremented before success** — a server-side error response left `currentPage` permanently incremented, skipping the next page. Counter now only commits (`currentPage = nextPage`) inside the success block; network errors no longer need a rollback.
- **Debug REST endpoint live in production** — `/debug-comment` route now only registers when `WP_DEBUG` is true.
- **`get_user_agent()` missing `wp_unslash` in REST class** — added to match AJAX handler.
- **`render_comment()` instantiated `Vibe_Comments_Database` per comment** — replaced with a `static $db` variable; single instance reused across the entire `wp_list_comments` callback loop.
- **REST `submit_comment` never purged page cache** — added `purge_page_cache()` private method (mirrors AJAX handler) and calls it on approved comment insertion. REST endpoint is not used by the frontend but was still a correctness gap.
- **Redundant transient in OAuth state** — transient previously stored the nonce value (already verified independently by `wp_verify_nonce`). Now repurposed to store the return URL, which is the data actually needed at callback time.
- **Premature `syncLikeCounts()` in `initLivePolling()`** — called on page load before any comment elements exist in the DOM. Removed; syncing already fires at the end of `initComments()` after render.

---

## [2.0.1] — 2026-06-12

### Fixed
- **Long URLs overflowing comment cards** — added `overflow-wrap: break-word` and `word-break: break-word` to `.vibe-comment-content`. Unbreakable strings (URLs, long tokens) now wrap inside the card instead of bleeding past the right border.

---

## [2.0.0] — 2026-06-12

> Major release. Architectural shift from REST API to admin-ajax for all
> comment operations. Guest likes. On-demand comment loading. Full-page
> cache integration. Multiple JS rendering bugs fixed.

### Added
- **On-demand comment loading** — comments no longer render at page load; a "View X Comments" button triggers the first fetch. Keeps cached HTML fully static regardless of cache TTL.
- **`fetchCommentCount()`** — lightweight AJAX call on every page load (`vibe_get_comment_count`) returns the live comment count and updates the heading and trigger button, bypassing full-page cache without loading any comment data.
- **Guest likes** — unauthenticated users can now like comments. Likes are tracked by a daily IP-based token (`user_id = 0, guest_token = md5(IP + AUTH_KEY + date)`). Token rotates at UTC midnight.
- **`Vibe_Comments_Database::get_guest_token()`** — static method generating the guest identifier from request IP, WP auth key, and current UTC date.
- **`vibe_load_comments` admin-ajax action** — paginated comment fetch with nested children, replaces the REST API `/comments/{post_id}` endpoint for all comment loading operations.
- **`vibe_sync_likes` admin-ajax action** — batch like count sync, replaces the REST API `/likes-batch` endpoint. Capped at 100 comment IDs per request.
- **`vibe_toggle_like` admin-ajax action** — handles like/unlike for both logged-in users and guests, replaces the REST API `/like` endpoint.
- **`vibe_get_comment_count` admin-ajax action** — returns total approved comment count (all depths) for a post. Sends `Cache-Control: no-cache` headers and fires `litespeed_control_set_nocache`.
- **`purge_page_cache()` private method** — purges post page cache across LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, and Comet Cache on comment approval.
- **`on_comment_approved()` hook** — listens on `transition_comment_status`; purges page cache only when a comment transitions to `approved` (e.g. admin approves a pending guest comment). Does not fire on spam, trash, or hold transitions.
- **`fetchCommentCount()` JS function** — fires on `DOMContentLoaded` in parallel with `refreshNonce()`; updates heading and trigger button text with live DB count.
- **`initCommentsTrigger()` JS function** — attaches click handler to the trigger button; calls `initComments()` on click then reveals the comment container.
- **`buildCommentTree()` JS function** — recursively builds a comment `<li>` with nested children `<ul>` without scroll side effects; used for initial render only.
- **`initComments()` JS function** — fetches page 1 from `vibe_load_comments`, renders via `buildCommentTree()`, updates heading count, seeds `knownCommentIds`, shows/hides Load More, calls `syncLikeCounts()` after render.
- **`refreshNonce()` JS function** — silently fetches a fresh `wp_rest` nonce from `vibe_refresh_nonce` in the background to replace the one baked into full-page cache.
- **DB migration `maybe_upgrade()`** — runs on every `init` via `Vibe_Comments_Activator::maybe_upgrade()`; checks installed DB version, adds `guest_token` column and rebuilds unique key on existing installs without requiring manual deactivation/reactivation.
- **`saveGuestIdentity()` JS function** — saves guest name and email to `localStorage` after successful comment submission.
- **`restoreGuestIdentity()` JS function** — restores saved guest name/email into form fields on page load.
- **`initGuestAutoSave()` JS function** — attaches `blur` event listeners to guest name/email inputs to auto-save identity.
- **Reply for guests** — non-logged-in users now see a Reply button on every comment. Clicking it moves the form to that comment and auto-expands the guest fields.
- **`siteName` in localized config** — `get_bloginfo('name')` now passed to JS via `wp_localize_script` as `config.siteName`.
- **Google OAuth REST callback route** — `/vibe-comments/v1/google-callback` registered via `rest_api_init`, matching the redirect URI shown in the admin settings page. Return URL stored in transient during AJAX initiation and retrieved on callback.
- **Login to site name** — "WordPress Login" button in the auth bar now reads "Login to [Site Name]" using `get_bloginfo('name')`.

### Changed
- **All comment operations moved from REST API to admin-ajax** — `initComments`, `loadMoreComments`, `checkNewComments`, `syncLikeCounts`, and `toggle_like` all now use `config.ajaxUrl` instead of `config.restUrl`. REST API endpoints remain registered but are no longer called by the front end.
- **Like button always renders as `<button>`** — previously conditional on `config.isLoggedIn`; the static `<span class="vibe-like-count-static">` path removed entirely. All users get a clickable button; the server resolves identity.
- **Reply button always renders as `<button>`** — previously showed "Log in to Reply" anchor for guests. Now a button for all users.
- **`syncLikeCounts()` timing** — moved from `initLivePolling()` (fires before comment elements exist) to end of `initComments()` success block (fires after elements are rendered). Counts now reflect reality immediately instead of after the 30-second polling interval.
- **`updateLikeDisplay()` simplified** — now queries `.vibe-like-btn[data-comment-id="X"]` directly instead of navigating via `.vibe-comment-body`. Static span fallback branch removed.
- **`hasMorePages` initial value** — changed from `true` to `false`. Set to the actual value by `initComments()` after first fetch. Prevents Load More from firing before the initial load completes.
- **Comment count includes nested replies** — `load_comments` and REST `get_live_comments` now use two separate queries: `$top_level_count` (for `has_more` pagination logic) and `$total_count` (all depths, for the heading). Previously only top-level comments were counted.
- **DB schema** — `vibe_comment_likes` table gains `guest_token VARCHAR(64) NOT NULL DEFAULT ''`. Unique key changed from `(comment_id, user_id)` to `(comment_id, user_id, guest_token)`.
- **`toggle_like()` signature** — now accepts `$guest_token = ''` as third parameter.
- **`user_has_liked()` signature** — now accepts `$guest_token = ''` as third parameter. Cache key updated to incorporate token.
- **`delete_user_like_cache()` signature** — now accepts `$guest_token = ''` as third parameter.
- **`debug_comment` REST endpoint** — `permission_callback` changed from `__return_true` to `function() { return current_user_can('manage_options'); }`. Admin-only access.
- **`/like` REST endpoint** — `permission_callback` changed from `check_logged_in` to `__return_true`. Guest access enabled.
- **Cache purge timing** — previously purged on every `submit_comment()` call regardless of approval status; now only purges when `$approved == 1`. Pending guest comments do not invalidate the cache at submission; cache is purged via `on_comment_approved()` when admin approves.
- **`vibe_log()` function** — now a no-op when `WP_DEBUG` is `false`. Log file moved from `wp-content/vibe-debug.log` (publicly accessible) to `wp-content/logs/vibe-comments-debug.log` with an `.htaccess` blocking direct web access.
- **`format_comment_with_children()` depth** — hardcoded to `3` instead of incorrectly passing `$per_page` (typically 10) as the nesting depth, which triggered up to 10 levels of recursive DB queries.
- **Total count query** — `'number' => 0` instead of `'number' => ''` in `get_comments()` count query (`0` is the correct WP convention for no limit).
- **`get_live_comments()` `$per_page`** — clamped to `min(50, max(1, absint($per_page)))` to prevent zero or abusive values.
- **Plugin version** — bumped from `1.1.3` to `2.0.0`.
- **DB version** — `Vibe_Comments_Activator::DB_VERSION` set to `1.2.0` to track schema migration state independently from plugin version.

### Fixed
- **"1" rendering on every comment** — `replyHtml` was assigned via a broken ternary with no `?`/`:` operators. JavaScript's ASI turned it into `const replyHtml = config.isLoggedIn;`. Because `wp_localize_script` serializes PHP `true` as the string `"1"`, `replyHtml` was `"1"` for logged-in users and `""` for guests. This string concatenated as text into every comment's footer.
- **Load More loading nothing** — `hasMorePages` defaulted to `true`, causing Load More to fire before `initComments()` had completed, fetching page 2 against an empty list. Now starts `false` and is set by `initComments()`.
- **Like counts stuck at 0** — `syncLikeCounts()` was called from `initLivePolling()` before any comment elements existed in the DOM. Nothing to sync, so all counts showed `0` until the 30-second polling interval.
- **Stale comment count in heading after new comments** — JS heading was updated from `$total` which only counted top-level comments. A post with 1 top-level thread and 5 nested replies showed "1 Comment".
- **`saveGuestIdentity` undefined error** — function was called on line 512 but never defined, causing a silent `ReferenceError` inside the `.then()` Promise chain that surfaced as "Something went wrong" to the user after a successful submission.
- **`catch(Exception $e)` throughout main class** — PHP 8 `TypeError` and `Error` subclasses do not extend `Exception`; changed to `catch(Throwable $e)` in all six instantiation blocks in `Vibe_Comments::init()`.
- **`comment_post` action crashes fatal** — a conflicting plugin on the host hooks into `comment_post` and throws a fatal. Wrapped `do_action('comment_post', ...)` in `catch(Throwable)` — comment is already saved, error is logged, response still returns success.
- **`clean_comment_cache()` and `clean_post_cache()` not called** — direct `$wpdb->insert()` bypasses WordPress core internals. Both caches now cleared explicitly after each insert.
- **`wp_update_comment_count()` missing** — manual `SELECT COUNT → UPDATE wp_posts` replaced with the correct WordPress function.
- **Google OAuth callback URL mismatch** — admin settings showed `rest_url('vibe-comments/v1/google-callback')` as the redirect URI but the handler listened on `?vibe-google-callback` query parameter. A REST route for `/google-callback` now registered via `rest_api_init`.
- **Return URL lost after Google OAuth** — `wp_get_referer()` at callback time returns Google's domain, not the originating WordPress page. Return URL now stored in a transient keyed to the state parameter during the AJAX initiation step.
- **Debug log publicly readable** — `wp-content/vibe-debug.log` written unconditionally on every page load with no access protection. Log now only written when `WP_DEBUG` is true.
- **Debug REST endpoint public** — `/vibe-comments/v1/debug-comment` returned PHP version, WP version, and raw DB errors to unauthenticated requests. Restricted to `manage_options`.
- **`likes-batch` unlimited IDs** — no cap on the `comment_ids` array meant an attacker could trigger thousands of DB queries in a single request. Capped at 100.
- **`format_comment_with_children()` passed `$per_page` as `$depth`** — with a default `per_page` of 10, the function recursed 10 levels deep fetching children, instead of the intended 3.
- **`get_live_comments()` total count query** — `'number' => ''` is invalid for `get_comments()`; corrected to `'number' => 0`.

### Removed
- **Magic link login** — `class-magic-link.php` deleted. AJAX action, REST route, auth bar button, inline email form, and `initMagicLink()` JS function all removed. Guest commenting and Google OAuth cover the use case without requiring email delivery infrastructure or creating subscriber account bloat.
- **"WordPress Login" label** — replaced with "Login to [Site Name]".
- **Static like count span** — `.vibe-like-count-static` element and its rendering path in `createCommentElement()` and `updateLikeDisplay()` removed. All users now get a clickable like button.
- **Server-rendered comment list** — `wp_list_comments()` call removed from `templates/comments.php`. Comment data no longer baked into cached HTML.
- **Login redirect on like** — non-logged-in users were redirected to `wp-login.php` when clicking like. Redirect removed; guests can now like.
- **`Log in to Reply` link** — replaced with a Reply button for all users.

### Security
- **CSRF protection on AJAX comment submission** — `check_ajax_referer('wp_rest', 'nonce', false)` added to `vibe_submit_comment` handler. Nonce included in all JS fetch calls.
- **Rate limiting on AJAX comment submission** — 5-second per-IP cooldown using `wp_cache_*`, matching the existing REST API rate limiting.
- **Rate limiting on like actions** — 10-second cooldown per user/guest per comment.
- **Content length validation** — comment content now validated for minimum 1 character and maximum 65,535 characters (MySQL `TEXT` column limit) in both AJAX and REST handlers.
- **Guest token validation** — `toggle_like()` returns `WP_Error` if called with `user_id = 0` and empty `guest_token`, preventing anonymous writes with no identifier.
- **Return URL host validation in OAuth** — `$return_url` compared against `home_url()` host; off-domain values silently replaced with `home_url()` to prevent open redirect.

---

## [1.3.0] — 2026-06-10

> Security and stability audit. All CSRF, PHP 8 compatibility, and
> cache invalidation issues identified and addressed.

### Added
- Nonce verification (`check_ajax_referer`) on `vibe_submit_comment` AJAX action.
- Rate limiting (5-second window) on AJAX comment submission.
- `ajaxUrl` added to `wp_localize_script` output.
- `comment_post` action now fired after direct `$wpdb->insert()` with `Throwable` guard.
- `clean_comment_cache()` and `clean_post_cache()` called after every direct insert.
- `wp_update_comment_count()` replaces manual `SELECT COUNT / UPDATE` pattern.

### Fixed
- `catch(Exception $e)` blocks that missed PHP 8 `TypeError` and `Error`; changed to `catch(Throwable $e)`.
- Debug log (`vibe-debug.log`) written on every request without access control; gated behind `WP_DEBUG`, moved to `wp-content/logs/`, protected by `.htaccess`.
- `/debug-comment` REST endpoint was publicly accessible; restricted to `manage_options`.
- `likes-batch` accepted unlimited IDs; capped at 100.
- `format_comment_with_children()` received `$per_page` as `$depth`; hardcoded to `3`.
- Count query used `'number' => ''`; corrected to `'number' => 0`.

### Security
- Debug REST endpoint restricted to admin (`manage_options`).
- Rate limit added to AJAX comment submission.
- Nonce check added to AJAX comment submission.

---

## [1.2.0] — 2026-06-09

> First working release. Fatal error on comment submission resolved by
> bypassing the REST API entirely and routing through admin-ajax.php.
> Identified root cause: a conflicting plugin hooked into `comment_post`
> and threw a fatal error on every comment insertion site-wide.

### Added
- `class-ajax-handler.php` — new file. Handles comment submission via `wp_ajax_vibe_submit_comment` and `wp_ajax_nopriv_vibe_submit_comment`, bypassing the REST API hook conflict entirely.
- Guest identity persistence via `localStorage` (`vibe_guest_name`, `vibe_guest_email`).
- Gravatar fallback for guest comments with no email — uses `anonymous@example.com` instead of empty string.
- `ajaxUrl` in `wp_localize_script`.

### Fixed
- Fatal error on comment submission — REST API `/comment` endpoint triggered `comment_post` action which a conflicting plugin crashed. Submission now uses `admin-ajax.php` with direct `$wpdb->insert()`, bypassing all problematic hooks.
- Like/unlike toggle — `toggle_like()` always performed `DELETE + INSERT IGNORE` regardless of current state, making it impossible to unlike. Fixed to check existing state first and perform the correct operation.

### Changed
- Comment submission endpoint changed from `POST /wp-json/vibe-comments/v1/comment` to `POST /wp-admin/admin-ajax.php?action=vibe_submit_comment`.

---

## [1.1.3] — 2026-06-08

> Original release. Plugin uploaded as starting point.

### Known Issues at This Version
- Fatal error on every comment submission (REST API hook conflict with another plugin).
- Like button always liked, never unliked.
- No nonce verification on comment submission (CSRF vulnerability).
- Debug log (`wp-content/vibe-debug.log`) written on every request, publicly accessible.
- `saveGuestIdentity()` called but never defined, causing silent ReferenceError on submission.
- Guest users redirected to wp-login.php when clicking Like.
- Reply button showed "Log in to Reply" with no guest path.
- Google OAuth callback URL mismatch (admin showed REST URL, handler listened on query param).

---

[Unreleased]: https://github.com/your-username/vibe-comments/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/your-username/vibe-comments/compare/v1.3.0...v2.0.0
[1.3.0]: https://github.com/your-username/vibe-comments/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/your-username/vibe-comments/compare/v1.1.3...v1.2.0
[1.1.3]: https://github.com/your-username/vibe-comments/releases/tag/v1.1.3
