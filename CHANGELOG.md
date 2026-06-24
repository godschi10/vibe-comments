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

## [3.2.4] — 2026-06-24

### Security — Critical (from external audit)
- **[C1] `jwk_to_pem()` DER off-by-one fixed** — The outer SEQUENCE length in the SubjectPublicKeyInfo structure was computed incorrectly: `strlen(rsa_oid) + 1 + der_length_size(len) + len` miscounts by 1 because the leading `\x00` byte of the BIT STRING is part of the inner content but was excluded from the inner length calculation. Fix: build the complete BIT STRING object first (`$bitstring_content = "\x00" . $rsa_key_der; $bitstring = "\x03" . der_length(strlen($bitstring_content)) . $bitstring_content`), then compute the outer SEQUENCE length from its actual byte size. Also removes the now-unused `der_length_size()` helper method.
- **[C2] Login-CSRF via anonymous OAuth state** — `wp_create_nonce()` for anonymous users (uid=0, no session token) produces identical values within each ~12-hour WP tick. Any anonymous user who initiates a Google login gets the same state token as every other anonymous user in that window — a CSRF attacker can re-use anyone's state to complete their own login flow. Fix: `wp_generate_password(32, false, false)` generates a cryptographically random 32-char state token. The token is stored in a transient keyed by its MD5 hash AND simultaneously set as an HttpOnly SameSite=Lax browser cookie. The callback verifies BOTH (`hash_equals()` on cookie vs URL state). An attacker who intercepts a state token from a different browser cannot replay it — the cookie won't be present in their request.
- **[C3] `$wpdb->insert()` bypassed WordPress spam pipeline** — Direct DB insertion skipped `preprocess_comment`, `pre_comment_approved` (Akismet, Antispam Bee), Discussion Settings approval rules (manual approval, disallowed word list, commenter whitelist). Replaced with `wp_new_comment($data, true)` which runs all filters and returns `WP_Error` on failure instead of calling `wp_die()`.
- **[C4] Hardcoded approval status ignored Discussion Settings** — `$approved = 1` for logged-in users and `$approved = 0` for guests, regardless of site configuration. `wp_new_comment()` now determines the approval status via `wp_allow_comment()` which respects the Discussion Settings panel.

### Security — High
- **[H2] Debug endpoint gated on `WP_DEBUG`** — `WP_DEBUG` is routinely enabled on production sites for error logging. Replaced with a dedicated `VIBE_COMMENTS_DEBUG_TOOLS` constant that must be explicitly defined in `wp-config.php`. `WP_DEBUG` no longer exposes the debug endpoint.
- **[H3] `uninstall.php` incomplete cleanup** — On plugin deletion, per-post comment count options (`vibe_comment_count_{id}`), `_vibe_pinned` commentmeta, and all plugin transients were not removed. Rewritten to clean all three via LIKE queries on `wp_options` and a direct `commentmeta` delete.

### Fixed — Medium
- **[M1] Duplicate `initPinComment` function** — Two definitions existed. JavaScript uses the last definition wins, which was the weaker version lacking `restoreChronologicalOrder()`, `.catch()`, and `fetchWithTimeout()`. The weaker duplicate removed.
- **[M2] Dead `render_comment()` in class-template-loader.php** — Called `get_like_count()` and `user_has_liked()` which were deleted in v3.0.0. Any code path reaching this method would fatal-error. Method and its dead `wp_list_comments` registration removed.
- **[M3] `refresh_nonce()` rate limit was decorative** — Checked the transient but always returned a fresh nonce regardless. Now returns `wp_send_json_error(..., 429)` when the rate key is active.
- **[M4] OAuth had two entry points** — `add_action('init', 'maybe_handle_oauth')` processed callbacks on any front-end URL via `?vibe-google-callback=1`, duplicating the REST route handler. `init` hook removed. REST route is now the sole callback endpoint.

### Fixed — Low
- **[L2] `$guest_token` out of scope in `format_comment_tree()`** — Avatar hash used `md5('vibe_guest_' . $comment_id . '_' . ($guest_token ?? ''))` but `$guest_token` is only available at request time in `toggle_reaction()`, not in the comment formatting path. Simplified to `md5('vibe_' . $comment_id)` — unique per comment, deterministic, no undefined variable.
- **[L3] Hardcoded `'subscriber'` role for OAuth users** — Replaced with `get_option('default_role', 'subscriber')`. New Google users now receive whatever role the site administrator has configured.
- **[L4] `wp_die()` on OAuth errors replaced** — All OAuth failure paths now call `oauth_error($return_url, $message)` which redirects to the post URL with `?vibe_auth_error=message`. The JS reads this query param on `DOMContentLoaded`, displays it via `showError()`, and removes it from the URL via `history.replaceState()`.
- **[L5] `wpdb` write return values unchecked in `toggle_reaction()`** — All three branches (`delete`, `update`, `insert`) now check `=== false` and return `WP_Error` on DB failure.
- **[L6] `register_setting()` missing `sanitize_callback`** — Any string including HTML/JS could be saved to `vibe_comments_google_settings`. Added `sanitize_settings()` callback. `client_secret` field changed from `type="text"` to `type="password"` with `autocomplete="new-password"`. Empty submission preserves the existing secret rather than blanking it.

### Added
- **`oauth_error()` private method** — Single redirect-based error handler for all OAuth failure paths.
- **`process_oauth_callback()` method** — Replaces `maybe_handle_oauth()`. Single REST entry point with cookie+transient state validation.
- **`vibe_auth_error` URL param handling in JS** — `showError()` called on `DOMContentLoaded` if `?vibe_auth_error` present in URL. Param removed from URL via `history.replaceState()` so refresh doesn't re-show the error.
- **`sanitize_settings()` in class-admin.php** — Sanitizes client_id (text), client_secret (text, preserves existing on empty), enable_google_login (bool).

---

## [3.2.3] — 2026-06-23

### Security — Critical
- **Google JWT signature now fully verified (RS256)** — `class-oauth-google.php` completely rewritten. The original `decode_jwt_payload()` only base64-decoded the JWT payload and checked the `aud` claim — it never verified the RS256 signature. An attacker who knew your public Google Client ID (it's embedded in the JS config, so always visible) could forge a JWT, set any email in the payload, and the plugin would accept it — creating a WordPress session for any account including admins. Fix: `verify_jwt()` fetches Google's JWKS from `googleapis.com/oauth2/v3/certs`, caches the keys for 1 hour, matches the token's `kid` to the correct key, and calls `openssl_verify()` against the RS256 signature. No match = null return = authentication rejected. `jwk_to_pem()` converts Google's JWK format to PEM in-process — no external library needed.
- **`email_verified === true` now enforced** — Google can return accounts with unverified email addresses (e.g. federated identity providers). These are now rejected before any user lookup or creation.
- **Dead REST endpoints removed** — `class-rest-api.php` registered 6 endpoints (`/like`, `/likes/{id}`, `/comment`, `/comments/{id}`, `/likes-batch`, `/test`) that the frontend stopped calling in v2.0.0 when operations moved to admin-ajax. These endpoints still called DB methods (`toggle_like`, `user_has_liked`, `get_like_count`, `get_like_counts_batch`, `get_user_liked_batch`) that were deleted in v3.0.0 when the reactions system was introduced. Any direct HTTP request to those endpoints would have caused a PHP fatal error. All 6 removed. Only the debug endpoint (admin + WP_DEBUG only) and REST-based OAuth callback remain.
- **REST rate limiters used worker-local cache** — `class-rest-api.php` `check_rate_limit()` used `wp_cache_get/set` which is per-process. On a multi-worker PHP-FPM server (any VPS), attackers could bypass rate limits by distributing requests across workers. Moot now that the endpoints are removed, but the REST class is clean.
- **`uniqid()` replaced with `wp_generate_password(8, false)` for OAuth usernames** — `uniqid()` generates time-based values with limited entropy. `wp_generate_password(8, false)` is cryptographically random alphanumeric — 36^8 ≈ 2.8 trillion possibilities.

### Fixed
- **Comment count stale on trash/spam/delete** — `on_comment_approved()` only fired on `transition_comment_status` (approval direction). Trashing, spamming, or permanently deleting an approved comment left the `wp_options` count and all cache layers stale until the next approval event. Added `on_comment_deleted()` (hooks `delete_comment`) and `on_comment_status_set()` (hooks `wp_set_comment_status`) — both funnel through the new `sync_and_purge()` method which recalculates from DB, writes to `wp_options`, and busts all cache layers.
- **`anonymous@example.com` Gravatar collision** — all guest comments with no email address resolved to the same Gravatar (the identicon for `anonymous@example.com`). Every guest comment now gets a unique deterministic avatar via `md5('vibe_guest_' . $comment_id)@comments.local`. Different comment = different identicon = no collision.

### Added
- **`sync_and_purge()` private method** — single source of truth for count persistence and cache invalidation. All three comment status hooks call this instead of duplicating logic. Writes live count to `wp_options` before purging edge caches so cache rebuilds always read the fresh value.
- **`README.md`** — replaces `readme.txt`. Full documentation: feature list, caching architecture table, Cloudflare setup, security scorecard, DB schema, Google OAuth setup. Renders properly on GitHub.

### Removed
- **`readme.txt`** — replaced by `README.md`.

---

## [3.2.2] — 2026-06-21

### Changed
- **Comment count stored in `wp_options`, zero live DB queries on page render** — `get_option('vibe_comment_count_{post_id}')` is read by PHP on every page request. The option is written by `on_comment_approved()` immediately before the page cache is purged, so the cache rebuild always reads the accurate value. First render (option not yet seeded) falls back to `get_comments_number()` and seeds the option. `get_option()` reads from WP's in-memory options cache (or Redis/Memcached) — zero live DB queries on any subsequent page render.
- **Comment form hidden until "Load Comments" is clicked** — form moved inside `#vibe-comments-container` which starts `display:none`. No AJAX, no DB, no PHP overhead until the user explicitly requests the comment section. Keeps the page fully static for the majority of visitors who don't comment.
- **Heading auto-hides at zero comments via CSS `:empty`** — when count is 0, PHP renders an empty `<h2>`. The `:empty` CSS rule hides it automatically — no `display:none` attribute, no JS. When JS populates the text after Load, the `:empty` rule stops matching and the heading appears.

### Fixed
- **`fetchCommentCount()` causing "No Comments Yet" bug** — AJAX count call on page load was hitting a stale transient (`0` from before any comments existed) and overwriting the correct PHP-rendered heading. `fetchCommentCount()` function and its call site removed entirely. Count is now PHP-rendered from `wp_options` (zero AJAX needed).
- **Heading field name mismatch** — JS was reading `data.total` but `load_comments` refactor renamed the PHP response field to `data.total_count`. JS now reads `data.total_count || data.total` for backward compatibility.
- **IntersectionObserver auto-loading comments** — `rootMargin: '200px'` was triggering on mobile immediately because the comments section was within 200px of the initial viewport, making "Load Comments" click-to-load useless. `IntersectionObserver` removed entirely. `initCommentsTrigger` is now pure click-to-load.

### Added
- **Cloudflare API purge (all plans including Free)** — `purge_page_cache()` now calls the CF URL purge API directly when `VIBE_CF_ZONE_ID` and `VIBE_CF_API_TOKEN` constants (or `wp_options`) are set. Works on CF Free — purges by URL, not Cache-Tag. Fire-and-forget (`blocking: false`) — never slows comment approval.

---

## [3.2.1] — 2026-06-21

### Fixed
- **Comments auto-loading on mobile** — IntersectionObserver `rootMargin: 200px` fired immediately on mobile because the comments section is always within 200px of the bottom of the visible content. Removed. `initCommentsTrigger` is click-only.
- **"No Comments Yet" heading bug** — `fetchCommentCount()` was AJAX-calling the count endpoint on every page load and overwriting the correct PHP-rendered heading with a stale cached `0`. Removed the function and its call entirely.
- **`data.total` field mismatch** — JS read `data.total` but `load_comments` PHP response uses `data.total_count`. Fixed to `data.total_count || data.total`.

---

## [3.2.0] — 2026-06-21

### Added — Edge Caching Architecture
- **`Cache-Control: public, max-age=120, s-maxage=120`** — `load_comments` AJAX endpoint now sends public cache headers instead of `no-cache, no-store`. Cloudflare and LiteSpeed cache the comment JSON at the edge. A post with 10,000 visitors in 2 minutes costs 1 PHP boot, not 10,000.
- **LiteSpeed tag-based caching** — `do_action('litespeed_control_set_maxage', 120)` and `do_action('litespeed_tag_add', 'vibe-comments')` instruct LiteSpeed to cache and tag-purge comment responses.
- **Server-side transient cache** — comment JSON cached via `set_transient('vc_load_{post_id}_{page}_{per_page}', $result, 120)`. PHP requests that miss the edge cache still skip all DB queries. Polling requests (`since > 0`) excluded.
- **Polling early-exit** — when polling detects zero new comments and no reaction IDs to refresh, returns immediately with a single integer COUNT query. No comment tree built, no reactions fetched.
- **Reaction counts in polling response** — polling now accepts `comment_ids[]` from JS and returns fresh `reaction_counts` in the same response. Eliminates the separate `syncReactions()` call on every poll interval.
- **`getUserReactionFromDOM(commentId)` helper** — reads the user's active reaction from the DOM, used by polling to preserve user state when refreshing counts without closing open pickers.
- **`purge_comments_data_cache($post_id)` method** — invalidates transients for pages 1–5 at standard `per_page` values, plus LiteSpeed tag purge and Cloudflare Cache-Tag purge. Called on every comment status change.
- **Skip `syncReactions()` for guest users** — guests' reactions are embedded in the comment response. Skipping the separate sync call saves one HTTP request per page load for the majority of visitors.

### Changed
- **All rate limits migrated from `wp_cache_*` to `set_transient/get_transient`** — `wp_cache_set()` is worker-local. On a multi-worker PHP-FPM server (any VPS), a user can bypass rate limits by distributing requests across workers. Transients write to DB or Redis — shared globally. Affects: reaction rate limit, comment submission rate limit, nonce refresh rate limit.
- **`get_comment_count` endpoint migrated to transients** — same reason. `wp_cache_get/set` → `get_transient/set_transient`.
- **`update_meta_cache('comment', $all_ids)` before format loop** — primes the WP comment meta cache for all comment IDs in one query before the `format_comment_tree` loop. Without this, `get_comment_meta($id, '_vibe_pinned')` fires one DB query per comment. After this call, all subsequent meta reads hit the in-memory cache.

---

## [3.1.4] — 2026-06-20

### Fixed
- **Rate limiting bypassed on multi-worker servers** — both `vibe_toggle_like` and `vibe_submit_comment` rate limits used `wp_cache_set()` which is per-PHP-worker. Migrated to `set_transient()`.
- **N+1 on `_vibe_pinned` comment meta** — `get_comment_meta($id, '_vibe_pinned')` was called once per comment inside `format_comment_tree`, firing one DB query per comment. Fixed with `update_meta_cache('comment', $all_ids)` before the loop.

### Added
- **`IntersectionObserver` pre-fetch** — comments start loading when the trigger button enters the viewport with a 200px margin, so they're ready before the user reaches them. Falls back to click-only on browsers without IO support.

---

## [3.1.3] — 2026-06-20

### Fixed
- **Reaction summary scattered on react** — `buildSummaryInner()` had a two-state branch that switched to per-type counts after the user reacted. This caused layout changes and visual inconsistency. Summary is now always: stacked emoji bubbles + one aggregate total. Per-type counts live exclusively inside the picker (below each emoji, `flex-direction: column`).
- **Picker counts not updating** — `updateReactionDisplay()` was not patching `.vibe-rx-picker-n` elements after a reaction toggle. Fixed.

### Changed
- **Picker buttons redesigned** — each emoji option now renders as a column (emoji above, count below). `min-height` on the count span reserves space even when count is zero so the picker never jumps in height.

---

## [3.1.2] — 2026-06-20

### Fixed
- **Reaction picker causing layout blowout** — picker was inside `.vibe-reactions` which was a flex child of the footer row alongside Reply and Pin. On mobile, opening the picker pushed all footer buttons off screen. Fix: `buildReactionBar()` now returns two separate HTML strings. `pickerHtml` renders as a direct child of `<article>`, between the comment content and footer. Footer never sees the picker. Zero overflow possible regardless of screen width.
- **Unpin doesn't restore position** — clicking "Unpin" removed the class but left the comment at the top of the list. `restoreChronologicalOrder()` now sorts all top-level `<li>` elements by their `<time datetime="">` ISO value, then `hoistPinnedComments()` re-hoists any still-pinned comments. Comment snaps back to its date-based position instantly, no page refresh.
- **`initPinComment` missing `.catch()`** — silently swallowed network errors on pin/unpin.

### Added
- **`getSortedReactions(reactions)` helper** — sorts reactions by count descending. Highest-count reaction always appears first in the summary.
- **`buildSummaryInner(sorted, userReaction)` helper** — builds summary HTML from sorted reactions. User's own reaction count gets primary-colour accent via `.vibe-rx-mine`.
- **`restoreChronologicalOrder(list)` helper** — sorts `<li>` elements by `datetime` attribute, falls back to comment ID for ties.
- **Overlapping emoji bubble stack** — up to 3 emoji rendered as overlapping circles (7px negative margin, z-index stacking) + aggregate total. Mirrors Facebook's reaction pile.

---

## [3.1.1] — 2026-06-20

### Fixed
- **Reaction picker layout blowout on mobile** — picker was a flex sibling of Reply and Pin in the footer row. Opening it pushed other buttons offscreen. Picker moved outside the footer.
- **Summary sort order** — reactions now sorted highest count first in the summary display.
- **Per-reaction counts in summary** — summary was showing only total. Now shows per-type counts sorted by count descending.

### Changed  
- **Plugin header** — Author changed from "Vibe Coder" to "G-will Chijioke" with Author URI `https://gwillchijioke.com`. Added Plugin URI, License URI, Requires at least, Requires PHP fields.

---

## [3.1.0] — 2026-06-19

### Added — Reactions redesign
- **Facebook-style compact summary + expandable picker** — replaced the 2×2 grid of pill buttons. Each comment now has one compact summary pill (stacked emoji + aggregate count). Tapping opens a picker row with 4 large emoji options. After reacting, picker closes and summary updates.
- **One reaction per user per comment, three-state toggle** — same reaction = DELETE (toggle off), different reaction = UPDATE in place, no reaction = INSERT. Single row in DB per user per comment.
- **Spring animations** — summary pulse uses `cubic-bezier(0.34, 1.56, 0.64, 1)`. Picker entry uses the same curve. Emoji hover lifts 3px at 1.45× scale. All compositor-only.
- **Outside-click close** — any click outside `.vibe-reaction-picker` or `.vibe-reaction-summary` closes all open pickers.
- **Guest identity "Not you?" notice** — when `restoreGuestIdentity()` pre-fills the form from localStorage, a notice appears: "Commenting as [Name]. [Not you?]". Clicking "Not you?" clears localStorage and wipes the fields. Name is `escapeHtml()`-sanitized before injection.

### Changed
- **`buildReactionBar()` returns split object** — `{ summaryHtml, pickerHtml }` instead of a single string. Enables placing picker and summary in separate DOM positions.
- **`updateReactionDisplay()` scoped to article** — all DOM lookups use `getElementById('div-comment-N')` as root, not document-wide `querySelector`. Eliminates ambiguity when multiple comments share the page.
- **`initReactions()` scoped to article** — picker found via `closest('article.vibe-comment-body')`, not via `.vibe-reactions` parent (which no longer contains the picker).

---

## [3.0.0] — 2026-06-19

### Added — Reactions System
- **4 emoji reactions: 👍 ❤️ 🔥 😂** — replaces the binary like/unlike system.
- **`reaction_type VARCHAR(20) DEFAULT 'like'` column** — added to `wp_vibe_comment_likes` via `ALTER TABLE` in `maybe_upgrade()`. Existing likes become `reaction_type = 'like'` via the DEFAULT clause. No data migration, no new table.
- **`get_reaction_counts_batch()` method** — fetches reaction counts for all 4 types across N comments in a single `GROUP BY comment_id, reaction_type` query. Uses WP object cache per-comment; only queries DB for uncached IDs.
- **`get_user_reactions_batch()` method** — fetches each user's active reaction type for N comments in one query. Stores `''` for "no reaction" to distinguish cache miss (`false`) from confirmed absence.
- **`toggle_reaction($comment_id, $user_id, $guest_token, $reaction_type)` method** — same/different/none logic: DELETE, UPDATE, or INSERT. Returns `{ action, user_reaction, reactions }`.
- **`REACTION_TYPES` constant** — server-side whitelist `['like', 'heart', 'fire', 'laugh']`. Client can never inject arbitrary types.
- **`REACTION_DEFAULTS` constant** — `{ like:0, heart:0, fire:0, laugh:0 }` returned for comments with no reactions.
- **`invalidate_reaction_cache()` private method** — deletes per-comment and per-user reaction cache keys after every toggle.

### Changed
- **`vibe_toggle_like` AJAX handler** — now accepts `reaction_type` POST param, validated against `REACTION_TYPES` whitelist. Returns `{ reactions, user_reaction }` instead of `{ like_count, user_liked }`.
- **`vibe_sync_likes` AJAX handler** — returns `{ reactions, user_reaction }` per comment instead of `{ like_count, user_liked }`.
- **`format_comment_tree()` return value** — `likes` field replaced by `reactions` (full type map) and `user_reaction` (current user's type or null).
- **`REACTION_DEFAULTS` used as fallback** — throughout PHP where `0` was previously used.
- **`syncReactions()` JS function** — replaces `syncLikeCounts()`. POSTs to `vibe_sync_likes`, receives `{ reactions, user_reaction }` per ID.
- **Sort by most liked** — reads `bar.dataset.totalReactions` (kept in sync by `updateReactionDisplay`) instead of `.vibe-like-count` text content.

### Removed
- **Like/heart binary system** — `vibe-like-btn`, `vibe-like-count`, `liked` CSS class, `vibe-heart` SVG, `vibe-liked-animate`, `vibe-like-pulse` animation all removed.
- **`get_like_counts_batch()` DB method** — replaced by `get_reaction_counts_batch()`.
- **`get_user_liked_batch()` DB method** — replaced by `get_user_reactions_batch()`.
- **`user_has_liked()` DB method** — replaced by reaction system.
- **`toggle_like()` DB method** — replaced by `toggle_reaction()`.
- **`initLikes()` JS function** — replaced by `initReactions()`.
- **`syncLikeCounts()` JS function** — replaced by `syncReactions()`.
- **`updateLikeDisplay()` JS function** — replaced by `updateReactionDisplay()`.
- **Live preview (Write/Preview tabs)** — removed. No value for a comment box on mobile.
- **Share/copy link button** — removed as requested.

### Security
- **Reaction type whitelisted server-side** — `in_array($reaction_type, self::REACTION_TYPES, true)` before any DB write. Client can never inject arbitrary values.
- **DB version bumped to `1.3.0`** — `maybe_upgrade()` checks and runs `ALTER TABLE` only if `reaction_type` column is absent.

---

## [2.7.0] — 2026-06-17

### Added
- **`incrementCommentHeading()`** — heading count increments immediately after a successful comment submission. No page refresh needed.
- **Warm personalised success message** — "Thanks Oluchi! Your comment is now live." / "Thanks Oluchi! Your comment is pending review." Name pulled from form field. Replaces generic "Comment submitted" text.
- **Empty state CTA** — replaces the previous "Be the first to leave a comment." with a styled card containing "No comments yet" and "Be the first to share your thoughts ✨".

### Security (Audit — 8 fixes)
- **`X-Forwarded-For` removed from IP resolution** — `resolve_client_ip()` previously trusted `X-Forwarded-For` which any client can forge, allowing rate limit bypass and guest like-token manipulation. Now only trusts `CF-Connecting-IP` (validated with `filter_var(FILTER_VALIDATE_IP)`) and `REMOTE_ADDR`.
- **Content length now enforces configured max** — server previously checked against 65,535 (DB column limit). Now checks against `apply_filters('vibe_comments_max_length', 2000)` — the UX limit. Direct API calls can no longer bypass the JS limit.
- **Author name length capped** — `mb_strlen($author) > 255` check added. MySQL `tinytext` column limit is 255 bytes; without this, truncation was silent.
- **`comment_approved` format string fixed** — was `%s`, now `%d`. Correct type for integer column in `$wpdb->insert()`.
- **`escapeHtml()` now encodes `"`** — `.replace(/"/g, '&quot;')` added. Without this, user-supplied strings in HTML attributes could break out of double quotes.
- **`comment.id` coerced to integer** — `const cid = parseInt(comment.id, 10)` in `createCommentElement()`. Belt-and-suspenders against any future data-path changes.
- **`javascript:` URIs replaced** — "Try again" links replaced with `<button class="vibe-retry-btn">` elements handled by delegated listener. CSP-safe.
- **IP validated with `filter_var(FILTER_VALIDATE_IP)`** — after `preg_replace` sanitisation, result is now verified to be a valid IP address with `'0.0.0.0'` fallback.

---

## [2.6.3] — 2026-06-16

### Fixed
- **Pinned comment lost on sort change** — every sort mode (oldest, newest, liked) now calls `hoistPinnedComments()` after reordering via a shared `applySort()` function.
- **Toolbar layout blowout on mobile** — sort `<select>` replaced with a compact cycling icon button (↑ / ↓ / ♥, ~42px fixed width). Search wrap gets `flex: 1; min-width: 0` — mandatory on flex children to prevent overflow. Search input gets `min-width: 0; width: 100%`.
- **Old `<select>` CSS removed** — `.vibe-sort-select`, `.vibe-sort-btn` dead CSS stripped.

---

## [2.6.2] — 2026-06-16

### Fixed
- **Pinned comment lost on page refresh** — `hoistPinnedComments()` called after initial comment render and after banner flush. `is_pinned` field comes from server so it's always accurate on reload.
- **Toolbar layout** — search injected into toolbar div (beside sort select) instead of as a separate block below. `display: flex; align-items: center` on toolbar. `min-width: 0` on search wrap.

---

## [2.6.1] — 2026-06-16

### Fixed
- **Pin button no-op** — nonce check used `'vibe_comments_nonce'` but JS sends `'wp_rest'` nonce. Fixed to `check_ajax_referer('wp_rest', 'nonce', false)`.
- **Share/copy link button removed** — per user request. `initShare`, `fallbackCopy`, `showShareToast` functions removed. CSS stripped.
- **Sort replaced with `<select>` dropdown** — three options: ↑ Ascending, ↓ Descending, ♡ Most liked. Driven by `change` event.

---

## [2.6.0] — 2026-06-16

### Added
- **Ctrl+Enter / Cmd+Enter to submit** — fires on textarea `keydown` in both main form and inline reply form.
- **Basic Markdown** — `**bold**`, `*italic*`, `` `code` ``, `> blockquote`. HTML-escaped before parsing (XSS-safe). Server stores raw text; rendering is client-only.
- **Auto-link bare URLs** — `/(https?:\/\/[^\s<>"&]+)/gi` → `<a rel="noopener noreferrer ugc">`. Applied after escaping so no XSS surface.
- **Collapse long comments** — comments >300 chars get `-webkit-line-clamp: 4` with "Read more" / "Show less" toggle button.
- **Sort by most liked** — sort toggle extends to three states: oldest / newest / most liked. "Most liked" sorts by `.vibe-like-count` text content in the DOM.
- **Comment search** — search input above the list. Filters visible comments on `input` event with 200ms debounce. Shows "N found" status. Zero server requests.
- **Author badge** — "Author" pill on comments where `comment.user_id === post_author_id`. Determined server-side.
- **Pinned comment** — admin-only "Pin" / "Unpin" button per comment. Stored in `commentmeta` as `_vibe_pinned`. Pinned comment appears at top of list. `is_pinned` and `is_author` fields added to PHP response.
- **`date_gmt` field** — returned by PHP, used to set `<time datetime="">` attribute for semantic timestamp and JS relative-time refresh.
- **Relative timestamp refresh** — `initRelativeTime()` recalculates every 60 seconds via `timeAgo()`. Full date shown in `title` attribute on hover.
- **`isAdmin` in localized config** — passed to JS to conditionally render pin buttons.

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

[Unreleased]: https://github.com/godschi10/vibe-comments/compare/v3.2.3...HEAD
[3.2.3]: https://github.com/godschi10/vibe-comments/compare/v3.2.2...v3.2.3
[3.2.2]: https://github.com/godschi10/vibe-comments/compare/v3.2.1...v3.2.2
[3.2.1]: https://github.com/godschi10/vibe-comments/compare/v3.2.0...v3.2.1
[3.2.0]: https://github.com/godschi10/vibe-comments/compare/v3.1.4...v3.2.0
[3.1.4]: https://github.com/godschi10/vibe-comments/compare/v3.1.3...v3.1.4
[3.1.3]: https://github.com/godschi10/vibe-comments/compare/v3.1.2...v3.1.3
[3.1.2]: https://github.com/godschi10/vibe-comments/compare/v3.1.1...v3.1.2
[3.1.1]: https://github.com/godschi10/vibe-comments/compare/v3.1.0...v3.1.1
[3.1.0]: https://github.com/godschi10/vibe-comments/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/godschi10/vibe-comments/compare/v2.7.0...v3.0.0
[2.7.0]: https://github.com/godschi10/vibe-comments/compare/v2.6.3...v2.7.0
[2.6.3]: https://github.com/godschi10/vibe-comments/compare/v2.6.2...v2.6.3
[2.6.2]: https://github.com/godschi10/vibe-comments/compare/v2.6.1...v2.6.2
[2.6.1]: https://github.com/godschi10/vibe-comments/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/godschi10/vibe-comments/compare/v2.0.5...v2.6.0
[2.0.5]: https://github.com/godschi10/vibe-comments/compare/v2.0.4...v2.0.5
[2.0.4]: https://github.com/godschi10/vibe-comments/compare/v2.0.3...v2.0.4
[2.0.3]: https://github.com/godschi10/vibe-comments/compare/v2.0.2...v2.0.3
[2.0.2]: https://github.com/godschi10/vibe-comments/compare/v2.0.1...v2.0.2
[2.0.1]: https://github.com/godschi10/vibe-comments/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/godschi10/vibe-comments/compare/v1.3.0...v2.0.0
[1.3.0]: https://github.com/godschi10/vibe-comments/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/godschi10/vibe-comments/compare/v1.1.3...v1.2.0
[1.1.3]: https://github.com/godschi10/vibe-comments/releases/tag/v1.1.3
