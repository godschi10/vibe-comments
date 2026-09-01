# Vibe Comments

A performance-focused custom comment plugin for WordPress, built for [gwillchijioke.com](https://gwillchijioke.com).

**Author:** [G-will Chijioke](https://gwillchijioke.com)  
**Version:** 3.14.1  
**Requires WordPress:** 6.0+  
**Requires PHP:** 7.4+  
**License:** GPL v2 or later

---

## How It Scales to 10M+ Monthly Visits

The key insight: **the page never hits the DB for comments on a normal visit.** Here is the exact flow:

### Page Load (every visitor)
```
Browser → Cloudflare edge cache → Static HTML (zero PHP, zero DB)
```
The comment count in the heading is read from `wp_options` (WP's in-memory options cache).
No live DB query. No PHP. No AJAX. Pure cache hit.

### "Load Comments" click (only visitors who want to read comments)
Assume 30% of visitors click. At 10M monthly = ~3M click events = ~100K/day.

```
Click → admin-ajax.php?action=vibe_load_comments
          │
          └─ Transient cache (DB/Redis, 120s TTL, PER POST/PAGE — not per visitor)
              PHP boots skip the DB query and get_replies_map()/reaction count
              queries entirely on a cache hit; one lightweight per-request
              lookup (get_user_reactions_batch) still runs to overlay THIS
              visitor's own reaction state on top of the shared cached list —
              see "Why this isn't edge-cached" below.
```

**Why this isn't edge/CDN-cached (as of v3.5.0):** every `load_comments()`/`load_replies()` response is personalized — it always carries the requesting visitor's own `user_reaction` values on top of the shared comment list. `Cache-Control` is deliberately `private, max-age=120` (browser-only), not `public, s-maxage=...` (CDN-shareable). An earlier version of this plugin did instruct Cloudflare/LiteSpeed to cache the full response at the edge — that was only safe back when the response contained no visitor-specific data at all; once `user_reaction` was added to close a correctness bug (guests never saw their own reaction highlighted), a shared/edge-cacheable response would have meant one visitor's private reaction state getting served to every other visitor of the same post for up to 2 minutes. The PHP-side transient layer (which is what actually avoids the DB query) is completely unaffected by this — only the CDN-level layer was removed, and only because it became unsafe to keep.

**Actual DB load at 10M monthly visits:**
`100,000 clicks/day ÷ 86,400s ≈ 1.2 clicks/sec`
With 120s cache window: `1.2 ÷ 120 ≈ 0.01 PHP boots/sec` per post that need the FULL query; every request still needs the one lightweight per-visitor reaction overlay lookup regardless of cache state, but that's a single batched query against an indexed table, not the comment-list query itself.

### Reactions (interactive users only — ~5% of visitors)
```
React → admin-ajax.php?action=vibe_toggle_like (DB write — never cached)
         │
         └─ purge_comments_data_cache($post_id) ← same purge as comment events (see below)
Sync  → included in the next polling response (no separate request)
```
The reaction toggle itself always hits the DB live. But its count is also embedded
in the cached `load_comments()` JSON response (see Cache Invalidation below) — a
reaction write purges that cache too, fixed in v3.3.2. Before that fix, a reaction
could show correctly in the toggle response while still reverting on a refresh that
landed inside the still-valid 120-second `vc_load_*` window.

### Live Polling (every 30s for users who loaded comments)
```
Poll → admin-ajax.php?action=vibe_load_comments&since=TIMESTAMP&comment_ids[]=N
         │
         └─ Fast path: COUNT query only (1 integer query)
              If count = 0 AND no reaction IDs → immediate response, ~1ms
              If reactions requested → 1 batch query for all IDs
              If new comments → full load (rare)
```

### Cache Invalidation (comment approved/trashed/deleted, or a reaction toggled)
```
on_comment_approved / on_comment_deleted / on_comment_status_set / toggle_like (reaction)
  │
  ├─ update_option('vibe_comment_count_{id}', $count)   ← BEFORE purge (comment events only)
  ├─ delete_transient('vc_load_{id}_*')                 ← kill JSON cache (all 4 triggers)
  ├─ litespeed_purge_tag('vibe-comments')               ← instant on Hetzner
  └─ CF API /purge_cache (files: [post_url])            ← CF Free compatible
```

Count in `wp_options` is written **before** the cache is purged. The next PHP render that rebuilds the cache always reads the accurate value. Never stale.

A reaction toggle purges the same `vc_load_*` cache via the same function (`purge_comments_data_cache()`) but does not touch `vibe_comment_count_{id}` — reactions don't change the comment count, only per-comment reaction totals embedded in that cached response.

---

## Features

**Core**
- Threaded comments (up to 4-level depth), collapsed by default — top-level comments show a "View N replies" button; clicking fetches and reveals the entire nested subtree for that thread in one request (Instagram/Reddit-style), not embedded on every page load
- AJAX submission, rate limiting, honeypot spam protection
- Gravatar/avatar support, Google OAuth (with RS256 JWT signature verification), WordPress login
- Guest commenting with UUID-based browser identity (localStorage key `vibe_gid`, generated via `crypto.randomUUID()`; hashed server-side with `AUTH_KEY` before DB storage) — eliminates NAT-collision reaction conflicts where multiple users behind the same IP shared an identity. Falls back to IP+date hash when localStorage is unavailable. Includes display-name/email persistence + "Not you?" clear
- Draft auto-save (localStorage, 7-day TTL), Ctrl+Enter to submit

**Engagement**
- 4 emoji reactions (👍 ❤️ 🔥 😂) — Facebook-style compact summary + expandable picker
- One reaction per user per comment, toggleable, switchable
- Reactions sorted by count descending (highest first) in summary
- Per-type counts visible in picker (below each emoji)
- User's own reaction indicated by blue count number only — pill border stays neutral
- Pinned comment (admin-only, survives page refresh, instant unpin restore)
- Sort by newest (default) / oldest / most liked
- Comment search (client-side, 200ms debounce, zero requests) — scoped to comment text and author name only
- Relative timestamps refreshed every 60s
- Author badge on post-author comments
- Collapse long comments (>300 chars) with Read more / Show less
- Basic Markdown: `**bold**`, `*italic*`, `` `code` ``, `> blockquote`
- Auto-link bare URLs (XSS-safe)
- Empty state CTA when no comments exist
- Live comment count update after submission, decoupled from page cache — see Database section below

**Performance**
- Zero DB queries on the cached page render itself (count baked in at cache-build time from `wp_options`); one lightweight, transient-backed request patches it live on every page load without a page-cache purge — see Database section
- Zero AJAX until "Load Comments" clicked
- 3-layer edge + server + transient comment JSON cache
- Reply content fetched only for threads the user actually expands — a post with hundreds of replies spread across many threads no longer transfers all of it on every load
- Batch reaction loading (2 queries for N comments)
- `update_meta_cache` eliminates N+1 on `_vibe_pinned`
- Guest `syncReactions()` skipped (saves 1 request for majority of visitors)
- Polling early-exit when nothing changed (1 COUNT query, immediate return)
- Reaction counts refreshed in polling response (no separate sync call)

---

## Installation

1. Upload the `vibe-comments` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Plugin hooks into `comments_template` automatically

---

## Cloudflare Cache Purge (all plans including Free)

Add to `wp-config.php`:

```php
define('VIBE_CF_ZONE_ID',   'your-zone-id');
define('VIBE_CF_API_TOKEN', 'your-cache-purge-only-token');
```

The API token needs only the **Cache Purge** permission — minimum scope.  
Fire-and-forget (`blocking: false`) — never delays comment approval.

---

## Nginx FastCGI Cache Purge (Nginx Helper plugin)

The plugin auto-detects **Nginx Helper** (WordPress.org) with FastCGI purge enabled and fires the native `nginx_helper_purge_url` action on every comment event (approve, trash, delete, status change) **and** reaction toggle. This busts the Nginx page cache for the specific post URL instantly — keeping comment counts and content fresh without manual intervention.

**Requirements:**
- Nginx Helper plugin installed & active
- Nginx Helper → Settings → **Enable Purge** checked
- Nginx Helper → Settings → **Purge Method: FastCGI** selected
- Nginx FastCGI cache configured with a `fastcgi_cache_key` that includes the request URI (standard WP Nginx configs already do this)

**How it works:**

```php
// In purge_comments_data_cache($post_id):
if ( function_exists( 'nginx_helper_purge_url' ) ) {
    do_action( 'nginx_helper_purge_url', get_permalink( $post_id ) );
}
```

This runs alongside (not instead of) the existing purge mechanisms:
- `litespeed_purge_tag('vibe-comments')` — instant on Hetzner/OpenLiteSpeed
- Cloudflare Cache Purge API — CF Free compatible
- `delete_transient('vc_load_{id}_*')` — kills the PHP transient cache
- `update_option('vibe_comment_count_{id}', $count)` — written **before** purge, so the rebuilt cache reads the correct count

**No extra config needed** — if Nginx Helper is active with FastCGI purge enabled, it just works. If Nginx Helper isn't active or FastCGI purge isn't selected, the action simply does nothing (graceful no-op).

## Google OAuth Setup

1. [Google Cloud Console](https://console.cloud.google.com) → APIs & Services → Credentials → Create OAuth 2.0 Client ID
2. Add Authorized redirect URI:
   ```
   https://yoursite.com/wp-json/vibe-comments/v1/google-callback
   ```
3. Save Client ID and Secret in **Settings → Vibe Comments → Google OAuth**

JWT signatures are verified against Google's JWKS on every callback. JWKS cached for 1 hour.

**Account matching:** a verified Google login is matched to an existing WordPress account by email address. This is standard "Sign in with X" behavior, not a plugin-specific choice — but it's worth being aware of on this specific site: if a WordPress user account already exists with the same email a visitor's Google account uses, signing in with Google logs them into *that* existing account, with whatever role and capabilities it already has. Only relevant if WordPress accounts are ever created with emails a site visitor might also control via Google.

---

## Security

| Control | Status |
|---|---|
| CSRF (nonces on all writes) | ✅ `wp_rest` nonce on every AJAX action |
| Post visibility | ✅ `load_comments()`/`load_replies()` check the post's status against WordPress's own public-status list (`get_post_stati(['public' => true])`), falling back to `current_user_can('read_post', ...)` only for non-public posts (so the post's own author/an editor can still see their own draft), plus `post_password_required()` — a post moved to draft/private, or password-protected, no longer serves its comment content to unauthorized visitors regardless of post_id |
| SQL injection | ✅ `$wpdb->prepare()` on all parameterized queries; IN() clauses use `absint()`-cast ID arrays interpolated directly (not injectable — every element is guaranteed a non-negative integer — but not literally `prepare()`, since MySQL's `IN()` has no native array placeholder) |
| XSS (server) | ✅ `sanitize_text_field`, `sanitize_textarea_field` |
| XSS (client) | ✅ `escapeHtml()` with `"` encoding before any DOM write |
| Rate limiting | ✅ Transients — shared across all PHP-FPM workers; comment submission scoped to IP + post_id (prevents cross-post NAT collision); `sync_likes` capped at 1 request per 3 seconds per IP; `load_replies` capped at 1 request per 2 seconds per IP+comment |
| JSON-LD structured data | ✅ Every text field passed through `wp_strip_all_tags()`; encoding additionally hardened with `JSON_HEX_TAG`/`JSON_HEX_AMP` as defense-in-depth |
| Guest token forgery | ✅ Strict canonical UUID v4 regex match required before hashing — malformed input falls through to IP-based fallback rather than being cleaned and accepted |
| IP spoofing | ✅ `CF-Connecting-IP` only trusted when `REMOTE_ADDR` (unspoofable — the actual TCP connection source) falls within Cloudflare's own published IP ranges; otherwise falls back to `REMOTE_ADDR` directly. `X-Forwarded-For` never trusted at all |
| Content length | ✅ Enforced server-side against configured UX limit |
| Honeypot spam | ✅ CSS off-screen, fake-success response to bots |
| Reaction type whitelist | ✅ Server-side `in_array` before any DB write |
| OAuth CSRF (state) | ✅ Crypto-random 32-char token + HttpOnly SameSite=Lax cookie binding |
| OAuth open redirect | ✅ `wp_validate_redirect()` |
| OAuth JWT signature | ✅ RS256 verified against Google JWKS |
| OAuth `email_verified` | ✅ Enforced — unverified emails rejected |
| OAuth username entropy | ✅ `wp_generate_password(8, false)` — cryptographically random |
| Author name length | ✅ Capped at 255 bytes (MySQL `tinytext` limit) |
| Debug endpoints | ✅ Behind `VIBE_COMMENTS_DEBUG_TOOLS` constant only |

---

## Database

One custom table: `wp_vibe_comment_likes`

```sql
CREATE TABLE wp_vibe_comment_likes (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_id    BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    guest_token   VARCHAR(64)     NOT NULL DEFAULT '',
    reaction_type VARCHAR(20)     NOT NULL DEFAULT 'like',
    created_at    DATETIME        DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_like (comment_id, user_id, guest_token),
    KEY comment_id (comment_id),
    KEY user_id (user_id)
);
```

`wp_options` keys (no extra table):
- `vibe_comment_count_{post_id}` — approved comment count per post, autoload=false, kept synchronously correct by `sync_and_purge()` on every comment event

Transient cache keys (in addition to `vc_load_{post_id}_{page}_{per_page}`, documented in the architecture diagram above):
- `vibe_count_{post_id}` — 20s TTL, backs the decoupled `get_comment_count()` endpoint. Busted immediately by `sync_and_purge()` on every comment event.
- `vc_replies_{comment_id}` — 120s TTL, one entry per expanded thread, backs `load_replies()`. Busted only for the specific parent affected by `purge_reply_cache_if_needed()`, called from `submit_comment()` and all three comment-status hooks — no enumeration, the affected parent is always already known at each call site.

**`guest_token` column** — For logged-in users this is always `''`. For guests it is `md5(AUTH_KEY . uuid)[:32]` where `uuid` is the UUID v4 generated by the browser and persisted in `localStorage` as `vibe_gid`. Sent to the server as the `vibe_guest_id` POST parameter and validated against the strict canonical UUID format (`/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i`) before being hashed — anything that doesn't match falls through to the IP-based fallback below. The raw UUID is never stored — only the salted hash. Falls back to `md5(IP . AUTH_KEY . UTC_date)[:32]` when localStorage is unavailable or the supplied value isn't a well-formed UUID.

Existing installs migrate automatically via `maybe_upgrade()` on `init`. No deactivate/reactivate needed.

`maybe_upgrade()` compares `vibe_comments_db_version` (stored in `wp_options`) against `Vibe_Comments_Activator::DB_VERSION`. On a version mismatch it runs schema migrations, flushes all `vc_load_*` transients to prevent stale cached comment JSON from surviving the upgrade, then updates the stored version. If `DB_VERSION` has not changed (typical between patch releases with no schema change), the function returns immediately.

---

## Internationalization

All user-facing strings in `class-ajax-handler.php` (form validation errors, rate-limit messages) and `class-oauth-google.php` (sign-in failure messages) are wrapped in `__('...', 'vibe-comments')`.

`load_plugin_textdomain('vibe-comments', false, dirname(plugin_basename(__FILE__)) . '/languages')` is called on `init`. Drop `.mo` files into `/languages` named `vibe-comments-{locale}.mo` (e.g. `vibe-comments-fr_FR.mo`) and WordPress will pick them up automatically — no further code changes needed.

Note: this covers PHP-rendered strings only. JS-rendered UI text (button labels, placeholder text) is not yet wired through `wp_set_script_translations()` — translating those currently requires editing `vibe-comments.js` directly.

---

## Accessibility

Keyboard focus indicators (`:focus-visible` outline rings) are present on every interactive control: the sort toggle, the search input, reaction summary pills, reaction picker buttons, and the moderator pin button. `:focus-visible` only activates for keyboard navigation, so mouse and touch interactions are visually unaffected.

**Added in v3.5.0:**
- `<noscript>` fallback notice (with the existing comment count, properly pluralized) — this plugin's comment system is entirely AJAX-driven with no server-rendered fallback content, so JS-disabled visitors previously saw an inert "Load Comments" button with no explanation.
- New-comments polling banner announces itself via `role="status"`/`aria-live="polite"`.
- Focus moves to a newly-posted comment after successful submission, confirming to keyboard/screen-reader users that it landed and where.
- The comment textarea's character limit is linked via `aria-describedby`.
- `prefers-reduced-motion: reduce` is respected globally — disables all animations/transitions and forces instant (non-smooth) scrolling, including for JS `scrollIntoView({behavior:'smooth'})` calls, which browsers honor `scroll-behavior: auto` for even when set via CSS rather than the JS call site itself.
- No inline styles remain anywhere in the JS (previously used for error/success messages and the reply-form container) — all moved to CSS classes for compatibility with a strict Content-Security-Policy.

---

## Debug Logging

Gate: add to `wp-config.php`:

```php
define('VIBE_COMMENTS_DEBUG_TOOLS', true);
```

When enabled, `vibe_log()` writes to `wp-content/logs/vibe-comments-debug.log`. The directory is created automatically on first write.

**Do not use `WP_DEBUG` as a gate** — `WP_DEBUG` is commonly enabled on production sites for PHP error capture. Tying the log to it creates a predictable file at a guessable URL.

**Nginx / LiteSpeed / OpenLiteSpeed**: the auto-written `.htaccess` in `wp-content/logs/` only protects the directory on Apache. On other servers, add a server-level deny rule manually:

```nginx
# Nginx
location ~ ^/wp-content/logs/ { deny all; }
```

```apache
# .htaccess (Apache 2.4 — already written automatically)
Require all denied
```

---

## SEO — Structured Data

On every singular post, the plugin outputs a `Schema.org` JSON-LD block in `<head>` containing:

- **`commentCount`** on a `WebPage` entity — a direct engagement quality signal Google uses in ranking. Sourced from the same `vibe_comment_count_{post_id}` option used everywhere else — no extra DB query.
- **Individual `Comment` entities** for each approved comment (up to 100), with `parentItem` links for threaded replies and `author.url` for commenters with a website.

**Why this is necessary:** Comments load via AJAX behind a click-to-load button. Googlebot does not click buttons — without JSON-LD the entire discussion is invisible to search crawlers. The schema block is present in the initial HTML response and requires no JavaScript.

**Compatibility:** Works alongside Yoast SEO, Rank Math, and any theme. The `WebPage` entity uses the plain post URL as `@id`, which Google merges with any existing schema rather than creating a duplicate.

## Uninstall Safety

`uninstall.php` checks whether `vibe-comments/vibe-comments.php` is still in `active_plugins` (and `active_sitewide_plugins` on multisite) before touching any data. If the canonical plugin is still active, the file exits immediately. This prevents data loss when an off-slug copy of the plugin (e.g. uploaded under a wrong directory name) is deleted while the main plugin is still running.

## Spam Scorer (v3.14.0)

The WP admin comments list and moderation queue gain a **Spam** column: every comment scored 0–100 — **Clean** (green) / **Suspicious** (amber) / **Likely spam** (red) — with the reasons in the hover tooltip. The heuristics are structural and language-neutral (link counts and stuffing, ALL-CAPS, character/punctuation runs, known spam phrases, gibberish blobs, author-name signals), computed fresh on every render from the comment alone: no storage, no drift, nothing to configure. Display-only by design — it flags for the human moderator, never auto-deletes; your own moderation settings stay the sole judge. `vibe_comments_spam_score` filter for custom tuning.

## 5-Minute Edit Window (v3.13.0)

Commenters — guests and members — can edit their own comments for **5 minutes** after posting: a subtle **Edit** pill beside Reply opens an inline editor (Enter saves, Esc cancels), the fixed text re-renders with all markdown intact, and a quiet *(edited)* badge appears on the meta line. Ownership is proven server-side on every attempt (guests via the same browser token reactions use, members via account), the window is anchored to the original submission time and can never be extended, and pending comments are editable too — fix the typo before moderation ever sees it. Zero DB cost when nobody's in-window.

## "Top" Sort (v3.11.0)

The sort toggle's third mode ranks comments by **total reactions** (like + heart + fire + laugh) with newest-first tiebreaker — the community's favorites rise to the top without a single server round-trip. Fully client-side; nested reply threads stay intact in their parents.

## Comment Analytics Dashboard (v3.10.0)

A top-level **Vibe Comments** menu in wp-admin (visible to anyone with `moderate_comments`) carrying every comment stat on one screen: 16 stat cards (status counts, unique commenters, reactions, threading depth, notification-rail subscribers, guest/member split, avg length, reply velocity), 4 hand-built SVG charts (12-month trend, reactions donut, hour-of-day, weekday), leaderboards (top posts, top commenters, most-reacted comments), and engagement-quality tables (% answered, avg time to first reply). Zero dependencies — no chart libraries, no external assets. Data cached 5 minutes with a nonce-gated refresh link. Driver-portable: all time-series parse in PHP from one bulk fetch (no SQL date functions — works identically on MySQL and the SQLite dropin).

## Reply Notifications via Email (v3.9.0)

A second checkbox under the form — **"Email me about replies"** — sends a branded email the moment someone's reply is approved. **Free and unlimited by architecture**: the plugin rides `wp_mail()`, the universal WordPress mail channel — no paid APIs, no per-email services, no third parties. On hosts with server mail (cPanel/Exim/LiteSpeed) it works with zero configuration; where outbound port 25 is blocked (like this VPS), the `GWILL_SMTP_*` constants any GWill theme supports (Brevo relay, 300/day free) light it up. The consent flag lives on the comment itself; the notification address is always the comment's own author email — the feature can never be used to email a stranger. Anti-storm: one email per reply (dedup across all approval paths), 3 per hour per thread. Fully uninstallable — `uninstall.php` sweeps the meta.

## @Mentions with autocomplete (v3.8.0)

Type `@` in the comment box → a GitHub-style autocomplete opens listing this post's commenters (and the post author). Pick a name → it inserts as plain text and renders as a brand-pill. When the comment is approved, the mentioned author gets a web-push notification — *"X mentioned you in a comment"* — through the same self-hosted rail as Reply Push (they receive it only if they opted in via "Notify me about replies" on that post; guests included). Multi-word names work; longest-name-first matching; mention notifications cap at 5 per comment. Everything degrades to plain text where JS can't run — the DB stores `@Name` as-is.

## Reply Push Notifications (v3.7.0)

Commenters can tick **"Notify me about replies"** under the form and get a web-push notification on their device the moment someone replies to their comment. Fully self-hosted — no email server, no OneSignal, no third parties.

### Requirements

The feature arms automatically when the active theme provides a push rail (both GWill themes do):

- `gwill_push_vapid()` — VAPID keys
- `gwill_push_stream()` — a `Minishlink\WebPush\WebPush` instance
- `gwill_push_obfuscate()` / `gwill_push_deobfuscate()` — key-at-rest helpers
- A service worker with a `push` handler honoring the `{ title, body, icon, badge, url }` payload

On any other theme the checkbox is never rendered and every code path no-ops — no fatal, no dead UI.

### How it works

1. **Opt-in**: tick the checkbox → the browser permission prompt fires (only on the user's gesture) → the subscription is created with the theme's VAPID public key. It is the SAME origin subscription the site's notification bell uses — one per browser; only the server-side routing differs.
2. **Storage**: the subscription is stored on the comment itself (`_vibe_reply_push` commentmeta, keys obfuscated at rest). Lifecycle is automatic — the comment is deleted → the subscription dies with it. No new tables, no new options.
3. **Delivery**: when a reply is approved — instantly on submit or by a moderator — the plugin queues one notification through the theme's stream: *"Chidi replied to your comment"* + an excerpt, tapping lands on the reply's anchor. A `410 Gone` push report prunes the stored meta automatically.
4. **Guards**: top-level comments, self-replies, unapproved replies, and unsubscribed parents never notify. Every failure path is silent-safe — a broken push can never disturb the comment flow that triggered it.

### Server-side validation

`http://` endpoints are rejected (https-only, same guard as the theme's REST route); malformed keys are rejected by a `Subscription::create()` round-trip before anything touches the database.

### Site-owner controls (filters)

```php
// Turn the feature off:
add_filter( 'vibe_comments_reply_push_enabled', '__return_false' );

// Point the notification icon at your own asset:
add_filter( 'vibe_comments_push_icon', function () {
    return get_template_directory_uri() . '/assets/brand/my-icon.png';
} );
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Repository

[github.com/godschi10/vibe-comments](https://github.com/godschi10/vibe-comments)