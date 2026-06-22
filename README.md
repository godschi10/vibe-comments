# Vibe Comments

A performance-focused custom comment plugin built for [gwillchijioke.com](https://gwillchijioke.com).

**Author:** [G-will Chijioke](https://gwillchijioke.com)  
**Version:** 3.2.2  
**Requires WordPress:** 6.0+  
**Requires PHP:** 7.4+  
**License:** GPL v2 or later

---

## What It Does

Replaces WordPress's default comment system with a fully custom implementation. Zero external dependencies, zero jQuery, no DB bloat.

**Core features:**
- Threaded comments (3-level depth), AJAX submission with nonce protection
- 4 emoji reactions (👍 ❤️ 🔥 😂) — one per user per comment, toggleable
- Gravatar/avatar support, Google OAuth, WordPress login
- Guest commenting with localStorage identity persistence
- Rate limiting (transient-based, multi-worker safe)
- Honeypot spam protection (fake success to fool bots)
- Draft auto-save (localStorage, 7-day TTL)
- Pinned comment (admin-only, survives page refresh)
- Sort by oldest / newest / most liked
- Comment search (client-side, 200ms debounce)
- Relative timestamps, refreshed every 60s
- Author badge on post-author comments
- Collapse long comments (>300 chars)
- Basic Markdown: `**bold**`, `*italic*`, `` `code` ``, `> blockquote`
- Auto-link bare URLs (XSS-safe)
- Live polling for new comments (without DB calls for unchanged posts)
- Ctrl+Enter to submit

---

## Caching Architecture (scales to 10M+ monthly visits)

Page load → Cloudflare / LiteSpeed serves **static HTML** with comment count from `wp_options`.  
**Zero DB queries, zero AJAX until the user clicks "Load Comments".**

After click:

| Layer | What it does | TTL |
|---|---|---|
| Cloudflare edge | Caches comment JSON at the nearest PoP | 120s |
| LiteSpeed | Server-side cache with tag-based purge | 120s |
| Transient | PHP-level cache, Redis-compatible | 120s |
| wp_options count | Zero-query comment count on every page render | Permanent, busted on approval |

**Invalidation:** When a comment is approved, trashed, spammed, or deleted — all three layers are busted atomically before the page cache is purged, so the cache rebuild always reads a fresh count.

---

## Cloudflare Cache Purge (Free Plan)

Add to `wp-config.php`:

```php
define('VIBE_CF_ZONE_ID',   'your-zone-id');
define('VIBE_CF_API_TOKEN', 'your-token-with-Cache-Purge-permission');
```

The token only needs the **Cache Purge** permission — do not use a full-access token.  
Purge is fire-and-forget (`blocking => false`) and never slows the comment approval flow.

---

## Security

| Control | Status |
|---|---|
| CSRF (nonces on all writes) | ✅ |
| SQL injection | ✅ Prepared statements throughout |
| XSS (server) | ✅ `sanitize_text_field` / `sanitize_textarea_field` |
| XSS (client) | ✅ `escapeHtml()` before markdown render |
| Rate limiting | ✅ Transient-based, shared across all PHP workers |
| IP spoofing | ✅ Only `CF-Connecting-IP` and `REMOTE_ADDR` trusted |
| Content length | ✅ Enforced server-side against configured UX limit |
| Honeypot | ✅ Returns fake success — bots don't retry |
| OAuth CSRF (state) | ✅ Transient-stored nonce |
| OAuth open redirect | ✅ `wp_validate_redirect()` |
| **OAuth JWT signature** | ✅ RS256 verified against Google's JWKS |
| OAuth `email_verified` | ✅ Enforced — unverified emails rejected |
| Reaction type whitelist | ✅ Server-side, never trust client |

---

## Installation

1. Upload `vibe-comments` to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **Settings → Vibe Comments** to configure Google OAuth (optional)

The plugin hooks into `comments_template` and replaces the default template automatically.

---

## Google OAuth Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → APIs & Services → Credentials
2. Create an OAuth 2.0 Client ID (Web application)
3. Add this as an Authorized redirect URI:
   ```
   https://yoursite.com/wp-json/vibe-comments/v1/google-callback
   ```
4. Copy Client ID and Client Secret into **Settings → Vibe Comments → Google OAuth**

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

Existing installs are migrated automatically via `maybe_upgrade()` on `plugins_loaded`.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full version history.
