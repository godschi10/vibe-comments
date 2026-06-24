# Vibe Comments

A performance-focused custom comment plugin for WordPress, built for [gwillchijioke.com](https://gwillchijioke.com).

**Author:** [G-will Chijioke](https://gwillchijioke.com)  
**Version:** 3.2.4  
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
React → admin-ajax.php?action=vibe_toggle_like (never cached — always live)
Sync  → included in the next polling response (no separate request)
```

### Live Polling (every 30s for users who loaded comments)
```
Poll → admin-ajax.php?action=vibe_load_comments&since=TIMESTAMP&comment_ids[]=N
         │
         └─ Fast path: COUNT query only (1 integer query)
              If count = 0 AND no reaction IDs → immediate response, ~1ms
              If reactions requested → 1 batch query for all IDs
              If new comments → full load (rare)
```

### Cache Invalidation (when a comment is approved/trashed/deleted)
```
on_comment_approved / on_comment_deleted / on_comment_status_set
  │
  ├─ update_option('vibe_comment_count_{id}', $count)   ← BEFORE purge
  ├─ delete_transient('vc_load_{id}_*')                 ← kill JSON cache
  ├─ litespeed_purge_tag('vibe-comments')               ← instant on Hetzner
  └─ CF API /purge_cache (files: [post_url])            ← CF Free compatible
```

Count in `wp_options` is written **before** the cache is purged. The next PHP render that rebuilds the cache always reads the accurate value. Never stale.

---

## Features

**Core**
- Threaded comments (3-level depth), AJAX submission, rate limiting, honeypot spam protection
- Gravatar/avatar support, Google OAuth (with RS256 JWT signature verification), WordPress login
- Guest commenting with localStorage identity persistence + "Not you?" clear
- Draft auto-save (localStorage, 7-day TTL), Ctrl+Enter to submit

**Engagement**
- 4 emoji reactions (👍 ❤️ 🔥 😂) — Facebook-style compact summary + expandable picker
- One reaction per user per comment, toggleable, switchable
- Reactions sorted by count descending (highest first) in summary
- Per-type counts visible in picker (below each emoji)
- Pinned comment (admin-only, survives page refresh, instant unpin restore)
- Sort by oldest / newest / most liked
- Comment search (client-side, 200ms debounce, zero requests)
- Relative timestamps refreshed every 60s
- Author badge on post-author comments
- Collapse long comments (>300 chars) with Read more / Show less
- Basic Markdown: `**bold**`, `*italic*`, `` `code` ``, `> blockquote`
- Auto-link bare URLs (XSS-safe)
- Empty state CTA when no comments exist
- Live comment count update after submission

**Performance**
- Zero DB queries on page load (count from `wp_options`)
- Zero AJAX until "Load Comments" clicked
- 3-layer edge + server + transient comment JSON cache
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
| Rate limiting | ✅ Transients — shared across all PHP-FPM workers |
| IP spoofing | ✅ Only `CF-Connecting-IP` and `REMOTE_ADDR` trusted |
| Content length | ✅ Enforced server-side against configured UX limit |
| Honeypot spam | ✅ CSS off-screen, fake-success response to bots |
| Reaction type whitelist | ✅ Server-side `in_array` before any DB write |
| OAuth CSRF (state) | ✅ Transient-stored nonce |
| OAuth open redirect | ✅ `wp_validate_redirect()` |
| OAuth JWT signature | ✅ RS256 verified against Google JWKS |
| OAuth `email_verified` | ✅ Enforced — unverified emails rejected |
| OAuth username entropy | ✅ `wp_generate_password(8, false)` — cryptographically random |
| Author name length | ✅ Capped at 255 bytes (MySQL `tinytext` limit) |
| Debug endpoints | ✅ Admin + `WP_DEBUG` only |

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
- `vibe_comment_count_{post_id}` — approved comment count per post, autoload=false

Existing installs migrate automatically via `maybe_upgrade()` on `plugins_loaded`. No deactivate/reactivate needed.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Repository

[github.com/godschi10/vibe-comments](https://github.com/godschi10/vibe-comments)
