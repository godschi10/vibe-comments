# Vibe Comments

A performance-focused custom comment plugin for WordPress, built for [gwillchijioke.com](https://gwillchijioke.com).

**Author:** [G-will Chijioke](https://gwillchijioke.com)  
**Version:** 3.4.0  
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
          ├─ Layer 1: Cloudflare edge (s-maxage=120)
          │   Same post, 100 clicks in 2 min = 1 PHP boot, 99 CF hits
          │
          ├─ Layer 2: LiteSpeed server cache (120s TTL, tag-based purge)
          │   Visitors bypassing CF still hit zero PHP
          │
          └─ Layer 3: Transient (DB/Redis, 120s TTL)
              PHP boots that miss both edge caches skip all DB queries
              One DB read (options table) → pre-serialized JSON returned
```

**Actual DB load at 10M monthly visits:**  
`100,000 clicks/day ÷ 86,400s ≈ 1.2 clicks/sec`  
With 120s cache window: `1.2 ÷ 120 ≈ 0.01 PHP boots/sec` per post  
On a post getting 10,000 visitors in 2 minutes: **1 PHP boot, 9,999 cache hits**

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

## Google OAuth Setup

1. [Google Cloud Console](https://console.cloud.google.com) → APIs & Services → Credentials → Create OAuth 2.0 Client ID
2. Add Authorized redirect URI:
   ```
   https://yoursite.com/wp-json/vibe-comments/v1/google-callback
   ```
3. Save Client ID and Secret in **Settings → Vibe Comments → Google OAuth**

JWT signatures are verified against Google's JWKS on every callback. JWKS cached for 1 hour.

---

## Security

| Control | Status |
|---|---|
| CSRF (nonces on all writes) | ✅ `wp_rest` nonce on every AJAX action |
| SQL injection | ✅ `$wpdb->prepare()` throughout; `absint()` on all IDs |
| XSS (server) | ✅ `sanitize_text_field`, `sanitize_textarea_field` |
| XSS (client) | ✅ `escapeHtml()` with `"` encoding before any DOM write |
| Rate limiting | ✅ Transients — shared across all PHP-FPM workers; comment submission scoped to IP + post_id (prevents cross-post NAT collision); `sync_likes` capped at 1 request per 3 seconds per IP |
| Guest token forgery | ✅ Strict canonical UUID v4 regex match required before hashing — malformed input falls through to IP-based fallback rather than being cleaned and accepted |
| IP spoofing | ✅ Only `CF-Connecting-IP` and `REMOTE_ADDR` trusted |
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

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Repository

[github.com/godschi10/vibe-comments](https://github.com/godschi10/vibe-comments)
