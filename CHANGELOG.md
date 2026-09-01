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

## [3.17.0] — 2026-09-01

### Added — Daily Digest Email (Feature #9): the admin's morning paper

One branded email per day (08:00 WAT / 07:00 UTC) to the site admin: yesterday's comment activity in a single summary — stat cards (approved / pending / comments / replies), the pending moderation queue with each comment's spam-score badge (v3.14.0 scorer integrated), most-reacted comments, per-post breakdown, top voices. Every entry deep-links to its admin row.

- **New class** `includes/class-digest.php` — single-event self-chaining cron (idempotent arm, never double-schedules; survives re-activation), full-calendar-day window (yesterday 00:00–24:00 UTC), one batched query for counts, one for the comment list, one for reactions, one for post titles; 100-comment cap.
- **Settings** — Settings → Vibe Comments: enable toggle + recipient email (defaults to admin_email), with a **Preview button** that renders the exact digest HTML in an iframe via admin-ajax (mod-cap only, wp_rest nonce) — the SMTP-free window: the preview works regardless of mail transport, and shares ONE build path with the cron so preview and inbox can never drift.
- **Delivery law unchanged**: the plugin never touches SMTP — `wp_mail()` carries it. On this host the transport remains blocked (empty Brevo key); the worker runs, builds, attempts, and honestly error-logs. The moment `GWILL_SMTP_USER`/`GWILL_SMTP_PASS` land in wp-config.php, the already-armed cron lights up with zero further work.

**Proofs**: live build — subject "[tech] Daily digest — 10 comments, 1 pending", counts {approved:10, pending:1, top_level:4, replies:7}, pending section with planted spam scored live ("suspicious 35%"), per-post table, top voices; visual render proof (screenshot): dark header + gold site name, 4 stat cards, moderation cards with colored badges, clean table — zero visual defects; cron chain live: armed for 2026-09-02 07:00 UTC, idempotent re-arm (no double-schedule), worker run recorded `last_run`, self-chained for tomorrow; send path: `wp_mail` returned false through the known-blocked rail (honest failure, no silent success). Probe comments cleaned.

## [3.16.0] — 2026-09-01

### Added — In-thread search (Feature #7): whole-thread, server-side

The search box now queries the ENTIRE thread server-side — every top-level comment AND every reply at any depth — not just the first 10 comments loaded in the DOM. The old client-side filter (which silently missed the other 90% of a long thread and could never surface a matched reply inside a collapsed thread) remains as an automatic fallback when the endpoint is unreachable.

- **New endpoint** `vibe_search_comments` — DB-backed LIKE with `esc_like()`-escaped wildcards (SQLite-portable — no MATCH...AGAINST), searches content + author name, approved comments only, post-visibility gate (draft/private/protected threads are not searchable), hard cap 50 results newest-first.
- **Cache-before-ratelimit** — results cached 60s per (post, term); a cached answer serves without touching the 1-fresh-search-per-2s-per-IP gate (so repeat queries within the debounce window never 429 — found live during the E2E and fixed before ship).
- **Reply context** — matched replies render flat with an "in reply to @author" chip (one batched parent query, chip suppressed when the parent is unapproved/deleted so the reply stands alone).
- **Client** — the existing box (B6-fixed) upgraded: 300ms debounce, out-of-order response guard (a newer keystroke always wins), thread snapshot + clean restore on clear, "Type at least 2 characters…" hint, graceful fallback to the local filter on endpoint error, reactions carried on results.

**Proofs**: live endpoint battery — 'lazy' 2 results correct authors; immediate repeat serves from cache (no 429); 'thanks' found a planted reply with correct `reply_to` parent-author; rate-limit gate verified firing on fresh-burst only. Browser E2E (Obscura CDP): typed 'lazy' → 2 flat results + "2 found" status; cleared → thread restored intact (3 comments, no residue); reply chip rendered "in reply to Amaka" live. `php -l` + `node --check` + CSS brace-balance all clean.

## [3.15.0] — 2026-09-01

### Added — Q&A mode per post (Feature #3, minimal cut)

Stack-Overflow-style Q&A on any post, enabled by a per-post **"Enable Q&A mode"** checkbox in the editor sidebar. The post becomes the Question; top-level comments become Answers; reactions serve as upvotes; the post author (or any moderator) can mark exactly one answer as **Accepted** — green check badge, hoisted to position 1.

- **New class** `includes/class-qa.php` — toggle helpers (`_vibe_qa_mode` post meta), accepted-answer pointer (`_vibe_qa_accepted` post meta — one row per question, not per comment), editor meta box (nonce + cap + autosave guarded), and the `vibe_accept_answer` AJAX endpoint with toggle semantics: accept → switch → unaccept in one endpoint; nonce + cross-post + approval + mode + permission gates (403/404/400).
- **Schema** — Q&A posts emit schema.org **QAPage → Question (name, text, answerCount, acceptedAnswer, suggestedAnswer[])** replacing the WebPage+Comment graph for those posts only; classic posts unchanged. Answer upvoteCount proxies from total reactions (batch query, one per request).
- **Payload** — `is_qa` + `is_accepted` universal-truth fields baked into the cached list; accepted answer hoisted server-side BEFORE formatting (an accepted answer on page 2 still leads page 1); phantom-hoist guard: a later-deleted/unapproved accepted answer is not resurrected.
- **Client** — green "✓ Accepted" badge on the meta line (leads the author name so the eye lands on the verdict), "✓ Accept / Unaccept" pill button in the footer (author/moderator only, top-level answers only), instant hoist + badge swap + label flip on click, `data-accepted` attr on the li drives the green left-border rail via CSS.
- **Config** — `vibeComments.qa` localize key: `{mode, acceptedId, canAccept, questionUrl}`; false on classic posts (JS renders the classic UI unchanged).

**Proofs**: PHP battery 23/23 (toggle helpers round-trip, absint hygiene on the accepted pointer, permission matrix guest/stranger/author/moderator, all five endpoint rejection gates, accept→switch→unaccept semantics, localize shape). Live E2E on the real site (Obscura/browser-harness CDP): reader view — 3 answers, Amaka's hoisted first with green badge, 0 accept buttons for readers; moderator session — 3 Accept buttons with correct labels, click Accept on a second answer → hoist + badge transfer + label flip live; click Unaccept → clean neutral state, zero badges. QAPage JSON-LD parsed live: correct question, answerCount 3, acceptedAnswer = Amaka, 2 suggestedAnswer entries, zero classic Comment entities. Accepted state restored to the original test answer; temp admin deleted.

## [3.14.1] — 2026-08-31

### Fixed — @mention dropdown invisible (King-reported: "I never saw the feature")

**Root cause**: the mention dropdown is `position:fixed` (viewport-anchored), but the code positioned it with `rect.bottom + window.scrollY` — page coordinates. `getBoundingClientRect()` already returns viewport space; adding `scrollY` pushed the dropdown that many pixels BELOW the caret. On any page scrolled down to the comment form (i.e., every real usage), the dropdown rendered thousands of pixels off-screen — the feature worked, but was invisible. This explains the King never seeing it despite the v3.8.0 ship and its E2E (which ran unscrolled, where scrollY=0 made the math accidentally correct).

**Fix**: viewport-rect anchoring with flip-above when the dropdown would clip the bottom edge, and side-clamping to the viewport. Dropdown now appears within 4px of the caret everywhere: scrolled pages, phones, short viewports.

**Proof**: live E2E before fix — dropdown rect at y:5979 with a 437px viewport (off-screen by miles); after fix — dropdown at the caret, `nearCaret:true`, Enter inserts `@Groot` and closes. Verified on the live site post-deploy.

## [3.14.0] — 2026-08-31

### Added — Heuristic spam scorer for the moderation queue (Feature #6)

A **Spam** column in the WP admin comments list & moderation queue scores every comment 0–100 with a colored badge: **Clean** (green, <30) / **Suspicious** (amber, 30–59) / **Likely spam** (red, ≥60). Hovering shows exactly WHY (the matched heuristics).

- **Pure + stateless**: computed from the comment's own fields on render — zero DB reads, zero writes, zero network, nothing stored, no drift. Score is reproducible forever.
- **Language-neutral heuristics only** (structural tells, never vocabulary — pidgin and mixed-English comments stay clean): link count (the classic blog-spam tell, 1→5+ weighted), link-to-word ratio (stuffing), ALL-CAPS ratio, repeated-character runs, punctuation-run frequency, 28 known spam phrase families (capped at 2), space-less 60+ char blobs (gibberish/data-URI), author-name signals (all-caps, keyword-stuffed).
- **Display-only by design** — never changes a comment's status, never auto-deletes. The site's own moderation settings remain the sole judge; the badge gives the human moderator a why-flagged score at a glance. Auto-action was rejected in design: a false positive hiding a real reader's comment costs more than a false negative already in review.
- **Column plumbing**: `manage_edit-comments_columns` + `manage_comments_custom_column` + scoped `admin_print_styles-edit-comments.php` CSS; not marked sortable (WP has no persisted score field — a fake sort would lie). `vibe_comments_spam_score` filter for custom tuning.
- **Portability**: badge HTML escapes everything (XSS-safe with hostile author names — battery-proven); works on any WP install, SQLite included (no DB at all).

**Proof**: battery **23/23** (clean prose, pidgin neutrality, link ladder 1→5, stuffing ratio, caps bands, char/punct runs, phrase hits incl. cap, no-space blob, author signals, band edges 29/30/59/60, clamp 100, XSS escape). Live admin E2E: logged-in admin saw the Spam column + 28 green badges on real comments (one honest "Clean 10% — heavy caps"); a planted spam comment rendered **red "Likely spam 100%"** with all 5 reasons in the tooltip (server score 100: 3 links, repeated characters, 4 spam phrases, ALL-CAPS name, keyword name). Probe cleaned up.

## [3.13.0] — 2026-08-31

### Added — 5-minute comment edit window (Feature #5)

Commenters can fix typos in their own comments for **5 minutes** after posting — guests and members alike, top-level comments and replies. After the window the comment is immutable, exactly like every classic comment system.

- **Ownership rail**: guests get `_vibe_owner` commentmeta at submit — the SAME SHA256 browser token the reaction system uses (zero new identity infrastructure); members match via `user_id`. Authorization is re-derived server-side on every edit attempt; `hash_equals` timing-safe comparison.
- **Window**: anchored to the ORIGINAL `comment_date_gmt` — an edit at 4:59 never extends the window. Clock-skew clamp included.
- **Pending comments are editable** (author fixing a typo before moderation sees it — a feature, not a hole); spam/trash are barred.
- **Payload**: `is_edited` baked at format time (cache-safe, like `is_pinned`); `can_edit` patched per-request via `apply_edit_window_overlay()` — the same never-cached contract as the reactions overlay, riding all 4 response sites (fresh + cache-hit × comments + replies). Zero DB cost when no comment is in-window.
- **UI**: subtle Edit pill (kin of Reply) in the footer; inline textarea with Save/Cancel (Enter saves, Esc cancels); content re-renders through the same markdown/mentions/linkify pipeline; italic "(edited)" badge on the meta line; the button self-removes at window expiry without a server round-trip.
- **Cache purges**: full rail on every edit (vc_load_* snapshots, thread transients, sync_and_purge edge caches).
- **uninstall.php**: sweeps `_vibe_owner` + `_vibe_edited` meta.

**Proof**: PHP shim battery **12/12** (window math incl. skew, overlay ownership matrix incl. children recursion, fresh-shape contract). Live E2E on the real site: guest submit → Edit rendered with deadline → live save → "(edited)" badge → still-editable; **window-closed 403** ("The 5-minute edit window has closed."); **stranger-token 403** ("You can only edit your own comments."). Probe comments cleaned from the DB.

## [3.12.0] — 2026-08-31

### Fixed — Analytics dashboard mobile layout blowout (King-reported)

The **"Most-reacted comments" table blew the whole dashboard to ~972px on a 375px phone** (2.6x viewport): its two `.vibe-an-excerpt` columns were each capped at `max-width:420px` with `white-space:nowrap`, and long unbreakable tokens (URLs in excerpts) set the table's min-content width past the viewport — stretching EVERY section (cards, charts, leaderboards, engagement) with horizontal scroll.

**Fix (surgical, mobile-scoped `@media (max-width: 782px)`):** `.vibe-an-table { width:100%; max-width:100% }` + `overflow-wrap:anywhere` on cells/excerpts (wraps long URLs) + text wrapping replaces the nowrap-ellipsis cap on phones. Desktop ≥783px untouched.

**Proof (real Chrome, real admin login, real data):** measured at 375px — `scrollWidth` 972px → **375px exact** (zero page overflow, zero elements beyond viewport, all 5 tables at container width); regression sweep desktop 1185px (2-col intact) / 600px / 320px — all clean; 16 cards, 4 SVG charts, all sections render; visual screenshot verified clean.

## [3.11.0] — 2026-08-31

### Added — "Top" sort mode (total reactions ranking)

The comment-list sort toggle's third mode is now **⭐ Top** — comments ranked by **total reactions** (like + heart + fire + laugh — the same live number the reaction engine maintains on `data-total-reactions`), with newest-first as the tiebreaker. Supersedes the v3.4 "liked ♥" mode: likes are included in the total, so every ranking that mode produced is preserved; hearts/fires/laughs now count too. Client-side only (zero server changes) and scoped to direct children of the list — nested reply threads are never flattened out of their parents.

### Fixed — Settings submenu callable (review finding)

The v3.10.0 dashboard's Settings submenu registered `array( 'Vibe_Comments_Admin', 'render_page' )` — a **non-static** method passed as a class-string callable, which PHP 8.3 rejects in isolation. WordPress only tolerated it because the duplicate `vibe-comments` slug resolved to the legacy Settings registration's valid instance callable. Now it's a proper delegate: `array( $this, 'render_settings_page' )` on the analytics instance, rendering through a real `Vibe_Comments_Admin` instance — valid whichever registration wins the slug.

## [3.10.0] — 2026-08-31

### Added — Comment Analytics Dashboard (pure SVG, zero dependencies)

A top-level **Vibe Comments** menu in wp-admin (capability `moderate_comments` — editors see it too; it's comment data, not plugin settings) with the full analytics screen: **every comment stat on one page.**

**Stat cards (16):** all comments by status (approved / awaiting moderation / spam / trash), approved, unique commenters (deduped by email), reactions given, threaded replies, top-level comments, push subscriptions, email opt-ins, pinned, guest vs member split, deepest thread, avg comment length, avg time to first reply.

**Charts (4, hand-built SVG — no chart libraries):** comments per month (12-month bars), reactions split (donut with totals + percentages), comments by hour of day (UTC), comments by weekday.

**Leaderboards:** top 10 posts by comments (linked), top 10 commenters (deduped by email), most-reacted comments (excerpt + post).

**Engagement quality:** % of top-level comments that received a reply, avg time from comment to first reply, deepest thread, avg comments per post, total notification-rail subscribers (push + email).

**Engineering decisions:**
- **Driver-portable by construction**: the time-series, threading and velocity stats derive from ONE bulk fetch of approved comments, parsed in PHP — no `DATE_FORMAT`/`strftime` (the SQLite dropin dialect trap). SQL is used only for `GROUP BY` leaderboards, which both drivers share.
- **Cached 5 minutes** in a transient; the "Refresh data" link busts it with a nonce.
- **Escaped everything**: all output through `esc_html`/`esc_attr`/`esc_url` — the SVG is built with `sprintf` + escaping, never raw user content.
- The Settings page keeps its legacy home under Settings (back-compat) and gains a submenu link here.

## [3.9.0] — 2026-08-31

### Added — Reply notifications via EMAIL: free, unlimited, any-server

A second checkbox under the form — **"Email me about replies"** — sends a branded email the moment someone's reply is approved. **Free and unlimited by architecture**: the plugin rides `wp_mail()`, the universal WordPress mail channel — zero paid APIs, zero per-email services, zero third parties. It works on ANY server by definition:

- **Hosts with server mail** (cPanel/Exim/LiteSpeed): zero config, works out of the box.
- **Hosts with port 25 blocked** (like this VPS on Oracle Cloud): the `GWILL_SMTP_*` constants any GWill theme already supports (`phpmailer_init` rail in `inc/forms.php`; e.g. Brevo relay, 300/day free) — plug in the constants, everything lights up. The plugin itself never touches SMTP.

**Design decisions:**
- **Consent flag, not a stored address**: commentmeta `_vibe_reply_email` stores only `'1'` on the comment. The notification address is ALWAYS the comment's own `comment_author_email` — the feature can never be used to email a stranger, and comment deleted → consent gone. `uninstall.php` sweeps it.
- **Anti-abuse by construction** + **anti-storm cap**: per-process dedup across all 3 approval paths (instant, transition, status-set); self-reply skip; 3-per-hour cap per parent thread (brigade-proof — the inbox never gets buried).
- **Branded email**: inline-styled HTML (email clients strip `<style>`), dark header strip, reply excerpt card, one CTA to the reply anchor, honest consent footer.
- **Failure isolation**: a transport failure is swallowed and logged (`wp_mail returned false`) — the comment flow is never disturbed.

## [3.8.0] — 2026-08-31

### Added — @Mentions with autocomplete (guests included)

Type `@` in the comment box → a GitHub-style autocomplete opens with this post's commenters (and the post author). Pick one → a green-ish brand-token pill renders in the comment body. **Notifications ride the exact same self-hosted push rail as v3.7.0** (zero new storage, zero third parties): when a comment containing `@Name` is approved, the mentioned author gets *"X mentioned you in a comment"* — **if and only if they have a live reply-push subscription** on that post (subscribed via "Notify me about replies" on any of their comments there).

**Design decisions:**
- **Plaintext storage**: the DB keeps plain `@Name` text. Pills are render-time only (client-side pass between the markdown renderer and linkify, split-on-tags so attributes/URLs/code spans are never touched). Feeds, admin, no-JS, other themes — all degrade to natural text.
- **Zero new storage**: no new table, no new meta. The notification resolves at approval-time: parse `@Name` → the mentioned author's newest subscribed comment on that post → its `_vibe_reply_push` subscription → the theme's stream (same send + 410-prune contract).
- **Longest-name-first matching** (PHP + JS, mirrored logic): "Ada Lovelace" always wins over "Ada"; terminator + pre-boundary guards mean "email@Ada" and "@Adaeze extra" never false-match. Multi-word names work; fragments may contain spaces.
- **Mentionable set** = approved commenters on the post + post author, deduped case-insensitively; the seed is localized and the client merges a live DOM scan — the dropdown stays current even mid-poll. Self-mention is filtered from the dropdown (a no-op event).
- **Guards on notify**: rail absent → silent skip; no subscription → skip (never invented notifications); self-mention → skip; mentioned-parent double-buzz guard (the direct parent's author already got the reply push — the pill renders, no second buzz); cap 5 pushes per comment (mention-storm safety).
- **Dropdown UX**: arrow keys navigate, Enter/Tab select, Escape closes, click-away closes, `mousedown` (not click) so the textarea never blurs mid-pick. Dropdown never inserts raw HTML — `textContent` only. `role="listbox"`/`role="option"` + active-descendant semantics.
- **Portability**: pill rendering works on any theme (client-side). Notifications require the theme's push rail (both GWill themes ship it) — otherwise pills render, pushes silently skip.

## [3.7.0] — 2026-08-30

### Added — Reply Push Notifications (self-hosted, zero third parties)

A commenter can tick **"Notify me about replies"** under the form and receive a **web-push notification on their device** the moment someone replies to their comment — no email server, no OneSignal, no external service. The notification carries the replier's name, an excerpt of their reply, and tapping it lands directly on the new reply's anchor.

- **New class `includes/class-reply-push.php`** — the whole feature in one self-contained class (`Vibe_Comments_Reply_Push`): availability check, storage, notify, send, prune.
- **Storage**: the browser subscription lives on the comment itself — `_vibe_reply_push` commentmeta (JSON blob; keys obfuscated with the theme's `gwill_push_obfuscate()` + base64'd, the identical at-rest treatment the site-wide subscriber table uses). Perfect 1:1 mapping with automatic lifecycle: comment deleted → subscription dies; plugin uninstalled → the meta sweep (added to `uninstall.php`) removes it. No new tables, no new options.
- **Integration contract, not duplication**: the plugin ships NO push stack of its own. It arms only when the active theme provides the existing GWill rail — `gwill_push_vapid()`, `gwill_push_stream()`, the obfuscation helpers, and a sw.js `push` handler with the `{title, body, icon, badge, url}` payload contract. On any other theme the checkbox never renders and every method no-ops. `vibe_comments_reply_push_enabled` filter for site owners; `vibe_comments_push_icon` filter for the notification icon.
- **Client flow** (`vibe-comments.js`): tick → `Notification.requestPermission()` FIRST (the dead-bell law — subscribing without asking silently fails with NotAllowedError and never shows the OS prompt) → subscribe with the theme's VAPID public key (localized via `config.replyPush.publicKey`), reusing the origin's existing subscription when present → subscription attached to the submit payload as PHP bracket notation (`vibe_reply_push[endpoint]` etc. — `URLSearchParams` stringifies nested objects to `[object Object]`; bracket keys rebuild the array server-side). Un-tick before posting stores nothing — the honest opt-out; after posting, the site bell's unsubscribe governs the browser side and a 410/404 push report prunes the comment meta server-side.
- **Three approval paths all wired, one notification**: instant approval (inline in `submit_comment` — `wp_new_comment()` does NOT fire `transition_comment_status` on first save), moderated approval (`transition_comment_status`), and admin status-set (`wp_set_comment_status`). Per-process static dedup makes the overlaps double-push-proof.
- **Guards**: top-level comments never notify; self-replies (same author email) never notify; unapproved replies never notify; rail-absent sites never arm; every push failure is silent-safe (logged via `vibe_log()` when debug tools are on) — a broken push can never disturb the comment flow that triggered it.
- **Validation mirrors the theme's REST route**: https-only endpoints, library `Subscription::create()` round-trip before anything is stored.
- **Styles**: brand-neutral `.vibe-reply-push-*` rules using the plugin's own `--vibe-*` tokens; 44px touch target; theme override files re-token automatically.

**Battery (10/10, standalone shim harness)**: happy path (queued once, correct title, `#comment-N` anchor), rail-absent, top-level, self-reply, unsubscribed parent, unapproved reply, http-endpoint rejection, 410 prune, dedup, obfuscate round-trip.

**Harness lesson**: a namespaced vendor stub cannot mix with bracketed namespace declarations in one file — provide it via a tiny separate namespaced file `require`d from the harness.

## [3.6.3] — 2026-08-30

### Fixed

- **README version line drift** — the README still declared `3.6.0` through the 3.6.1 and 3.6.2 releases (the header/constant were bumped but the README line was missed both times). README now carries the true version, and the release checklist in the repo notes that all four version carriers (header, `VIBE_COMMENTS_VERSION`, README, this CHANGELOG) must move together.
- **Docs release** — version alignment verified against live sites after deployment; no code changes in this release.

## [3.6.2] — 2026-08-24

### Fixed

- **Stale asset cache-buster regression** — `VIBE_COMMENTS_VERSION` was left at `3.6.0` when the header bumped to 3.6.1 (the same drift fixed in 3.6.0, reintroduced by the 3.6.1 commit which edited the header line only). Enqueued JS/CSS URLs still served `?ver=3.6.0`. Constant now matches the header at **3.6.1→3.6.2**, both aligned. Found via the 2026-08-24 plugin/theme conflict audit.

## [3.6.1] — 2026-08-22

### Changed

- **Deduplicated the "should Vibe render on this singular view?" condition.** The identical logic existed in `Vibe_Comments_Template_Loader::load_template()` (negated form) and `vibe-comments.php::enqueue_assets()` (positive form). These two copies drifted apart once before (pre-3.5.0: template rendered but CSS/JS never loaded). Both now call one shared static method, `Vibe_Comments_Template_Loader::should_render()` — single source of truth, found via the 2026-08-22 plugin/theme conflict audit.

## [3.6.0] — 2026-08-19

### Changed
- **Version re-numbered 3.5.10 → 3.6.0** — a two-digit patch ("3.5.10") parses ambiguously as "3.5.1", which naive readers and parsers rank *lower* than 3.5.8. The tenth patch in the 3.5 line is a minor release by any convention, so the line continues as 3.6.0.

### Fixed
- **Stale asset cache-buster** — `VIBE_COMMENTS_VERSION` was still `3.5.8` after the v3.5.9 release (the version header was bumped but the constant wasn't), so every enqueued JS/CSS URL served `?ver=3.5.8` and browsers with cached 3.5.8 assets would never fetch newer files after future releases. Constant now matches the header at 3.6.0.
- **Version-drift cleanup** — plugin header, `VIBE_COMMENTS_VERSION` constant, CHANGELOG and README all now align at 3.6.0.

---

## [3.5.9] — 2026-08-18

### Fixed
- **Reactions silently failing on SQLite hosts** — the official WordPress SQLite Database Integration plugin's `dbDelta()` records every non-primary-key column of `wp_vibe_comment_likes` with `EXTRA = 'auto_increment'` in its MySQL `INFORMATION_SCHEMA` mirror. The driver's INSERT translator trusts that flag and rewrites inserted values as `NULLIF(CAST(x AS INTEGER), 0)`, so guest reactions (`user_id = 0`) and the `guest_token` / `reaction_type` strings became `NULL` → `NOT NULL constraint failed` → `toggle_reaction()` returned "Failed to save reaction." The real SQLite table was always correct; only the mirror was corrupted. New `Vibe_Comments_Activator::maybe_repair_sqlite_schema()` (called from `maybe_upgrade()` on every request, idempotent, no-op on MySQL) detects and repairs the mirror in place without touching stored reaction data.
- **Repair detection case-bug** — the driver's `fetchAll(PDO::FETCH_ASSOC)` returns UPPERCASE keys (`COLUMN_NAME`, `EXTRA`); detection now normalises each row with `array_change_key_case($col, CASE_LOWER)` before the lookup, so the repair actually fires.
- **Version-drift cleanup** — header said 3.5.8 while CHANGELOG/README still documented 3.5.7; both now align at 3.5.9.

## [Unreleased]

---

## [3.5.7] — 2026-07-28

### Added
- **Nginx FastCGI cache auto-purge on comment events** — `purge_comments_data_cache()` now fires `do_action('nginx_helper_purge_url', $url)` when the Nginx Helper plugin is active with FastCGI purge enabled. This busts the Nginx page cache for the specific post URL immediately when a comment is approved, trashed, deleted, or reacted to — keeping comment counts and content fresh without manual intervention.
  - Requires: Nginx Helper plugin active, 'Enable Purge' checked, 'Purge Method: FastCGI' selected.
  - Works alongside existing LiteSpeed tag purge, Cloudflare Cache-Tag purge, and transient invalidation.
- **SHA256 guest tokens** — Guest identity tokens now use SHA256 (64-char) instead of MD5 (32-char). DB column `guest_token` remains VARCHAR(64). Legacy 32-char MD5 tokens detected on migration and regenerated on next user interaction.
- **CLI command class** — New `class-cli.php` adds WP-CLI commands for managing comments, likes, and cache operations.
- **CSS custom properties** — Full tokenized color system with `--vibe-*` CSS variables for seamless dark mode support. Theme only needs to override variables under `[data-theme="dark"] .vibe-comments-section`.

### Changed
- **Google OAuth cookie SameSite: Strict** — OAuth state cookie now uses `SameSite=Strict` (was `Lax`) to prevent login-CSRF on top-level navigations from external links. Cookie still sent on same-site requests (typing URL, bookmarks, same-origin links).
- **Removed CI/CD** — Deleted `.github/workflows/deploy.yml` and `.gitlab-ci.yml`. Manual `git push origin main` only.

### Fixed
- **SHA256 token generation** — `get_guest_token()` in `class-database.php` now returns 64-char SHA256 hash instead of 32-char MD5 substring.
- **Guest token docblocks** — Updated return type annotations from "32 hex chars" to "64 hex chars (SHA256)".

---

## [3.5.6] — 2026-07-04

A third-party audit document was received and every finding checked against actual current code before acting on any of it. The document's own header states "Version Audited: 3.2.2" and describes failed network fetches ("Network constraints prevented fetching all submodule files") — it was generated by attempting to pull the GitHub repository directly and getting an incomplete, many-releases-stale snapshot, not a review of this codebase as it currently exists. Of 14 substantive findings, one was real and is fixed below; the rest were either factually incorrect (including about the stale version it did fetch), already resolved in earlier releases this session, or restated as "High Severity" something functionally identical to what's already implemented.

### Fixed
- **[Real, confirmed] IP spoofing via unverified `CF-Connecting-IP`.** `resolve_client_ip()` trusted the `CF-Connecting-IP` header without verifying the request actually originated from Cloudflare's network — any client can set an arbitrary value for this header on a request sent directly to the origin. The same function's own docblock already correctly reasoned about this exact risk for `X-Forwarded-For` ("it can be spoofed by any client") without applying the same logic to `CF-Connecting-IP`, which is equally spoofable by anyone who can reach the origin server directly (rather than being forced through Cloudflare's proxy). Since this value feeds both IP-based rate limiting and the guest-identity fallback, an attacker could rotate it per-request to bypass rate limits entirely. Fixed: `CF-Connecting-IP` is now only trusted when `REMOTE_ADDR` (the actual TCP connection source, which cannot be spoofed by the client) falls within Cloudflare's own published IP ranges (verified current as of this release against two independent sources; Cloudflare changes these infrequently and with advance notice — if the list goes stale, the fallback is graceful: genuine Cloudflare traffic is treated the same as any other request, keyed on Cloudflare's edge IP rather than the real visitor IP, not a security failure). Applies to both `class-database.php`'s `resolve_client_ip()` and `class-ajax-handler.php`'s `get_remote_ip()`, which is a thin wrapper around the same function.

### Checked and found to not apply (verified against actual current code, not dismissed on assertion)
- **Cache-Control headers "contradicting" the edge-caching architecture** — conflated two unrelated functions. `add_cache_headers()` (which sets `no-store`) is gated behind `REST_REQUEST` and only ever touches the OAuth callback and debug REST routes — which correctly should never be cached (caching an OAuth redirect response would let a CDN serve one visitor's "signed in" state to a different visitor). It has no connection to `load_comments()`/`load_replies()`, which use `admin-ajax.php` entirely, with their own separate, already-correct (`private, max-age=120`, see v3.5.0/v3.5.5) header logic.
- **Guest tokens "forgeable" to bypass reaction limits** — the raw client-supplied UUID is never stored; only `md5(AUTH_KEY . uuid)`, salted with a server-side secret the client never has access to. Rotating your own client-side UUID creates a new anonymous identity, still bound by the identical one-reaction-per-identity unique constraint — it does not bypass that constraint for an existing identity. This is an inherent, unavoidable property of any unauthenticated commenting system, not a fixable defect in this one.
- **Rate limiting "not multi-worker safe" via a counter race** — the described mechanism (read count, increment, write count, two workers under-counting) does not match the actual implementation, which is a boolean presence check (`get_transient`/`set_transient`), not a counter. A much narrower theoretical race exists at the microsecond scale for perfectly-simultaneous first requests; nothing resembling "an attacker can double their effective rate limit."
- **OAuth state "predictable" via a hypothetical transient key pattern** — the finding's own phrasing ("if the transient key is derived from a predictable value, e.g. `vibe_oauth_state_$user_id`") is speculative and doesn't match the actual implementation, which derives the transient key from `md5()` of a `wp_generate_password(32, ...)` cryptographically random value, independently double-verified against an HttpOnly cookie — already more robust than the finding's own suggested fix.
- **`escapeHtml()` before markdown rendering being "logically broken"** — factually incorrect about what `escapeHtml()` does. It touches exactly four characters (`&`, `<`, `>`, `"`) via four individually-targeted replacements; it never touches `*`, backtick, or `[` at all, so markdown syntax recognition is completely unaffected. `renderMarkdown()` is deliberately built to consume the escaped output (its blockquote detection matches the escaped `>`, not raw `>`) — this is the standard, correct order, not a mistake.
- **Auto-linking as an "XSS minefield" citing `javascript:` URIs** — the linkify regex (`/(https?:\/\/[^\s<>"&]+)/gi`) requires a literal `https?://` prefix; a `javascript:` URI cannot structurally match this pattern regardless of content.
- **`maxCommentLength` "desynchronization" between client and server** — both the JS-localized hint and the server-side enforcement in `submit_comment()` call the identical `apply_filters('vibe_comments_max_length', 2000)` — there is no drift path; a filter hook changes both identically.
- **`vibe_log()` "leaking structure"** — already fixed (see earlier this session): gated behind a dedicated `VIBE_COMMENTS_DEBUG_TOOLS` opt-in constant, not `WP_DEBUG`. The finding's own suggested fix (gate on `WP_DEBUG` instead) would be a regression from the current, more restrictive gate — `WP_DEBUG` is commonly left on in production for error capture.
- **Google OAuth enable-checkbox logic "ambiguous"** — already fixed in v3.5.4.
- **Missing `ABSPATH` guards** — already fixed (v3.5.0), across all 7 files that were actually missing one.
- **`isAdmin` exposing "privilege information"** — reflects only the single `moderate_comments` capability (not a full capability map), used solely to conditionally show one Pin button. The finding's own suggested fix ("expose a minimal boolean like `canPin`") describes something functionally identical to what's already implemented, just with a different variable name — not acted on, consistent with this session's standing position on cosmetic-only renames carrying real risk for zero functional benefit (see the `toggle_like` renaming discussion, similarly declined).

### Acknowledged as fair architectural observations, not bugs
- **Client-side search requires the currently-loaded page(s) of comments to already be in the DOM.** True by design — comments are paginated (10/page via Load More) specifically so this is bounded, not a full-thread download, but a genuinely huge single post's comment count would still mean scrolling/loading through more pages before later comments become searchable. A server-side search endpoint remains a reasonable future enhancement, not implemented here.
- **Live polling incurs a full WordPress bootstrap per request.** True of any `admin-ajax.php` endpoint — this is a structural property of building on WordPress's own request-handling rather than a bypassable inefficiency in this plugin's code specifically. The polling implementation already does what's reasonable within that constraint (a fast-path COUNT-only query with immediate early-return on no change, see earlier this session).

---

## [3.5.5] — 2026-07-04

**This release fixes a regression introduced in v3.5.4.** The post-visibility check added in that release (`current_user_can('read_post', $post_id)`) rejected every anonymous, logged-out visitor on every post, published or not — meaning "Load Comments" failed for the overwhelming majority of real traffic on any public site, visible as a button stuck on "Loading…" that never resolved.

### Root cause, precisely
`current_user_can('read_post', $post_id)` is a *meta capability* check. Per WordPress core's own `map_meta_cap()`, for a post with a **public** status (`publish` being the standard case), this resolves to checking the *primitive* capability `read` against the current user. `read` is a capability WordPress's role system only grants to authenticated roles — Subscriber and above. A logged-out visitor has no role and no capabilities at all, `read` included, **regardless of whether the post is public**. This check was answering "does this specific WordPress user account hold this capability," not "is this content publicly visible" — those are genuinely different questions, and v3.5.4 used the wrong one to answer the second.

### Fixed
- **`load_comments()` and `load_replies()`** now check the post's status directly against WordPress's own registered public statuses (`get_post_stati(['public' => true])` — `publish` by default, but this also correctly picks up any custom public statuses a site or plugin registers, rather than hardcoding the literal string `'publish'`). `current_user_can('read_post', ...)` is now only consulted as a *fallback*, for posts that are **not** publicly visible — which is exactly the right tool for that narrower question (can this specific logged-in user, e.g. the post's author or an editor, see their own draft or private post), rather than the wrong tool for "can the general public see this at all." Password protection is still enforced via `post_password_required()` regardless of status.
- **[Independent bug, exposed by the above but not caused by it] `initComments()`'s success handler never called its completion callback on a "soft" failure** — i.e. when the server responds normally (HTTP 200, valid JSON) but reports `success: false`, which is a *resolved* promise, not a rejected one, so the function's `.catch()` block (which correctly calls the completion callback) never runs for this case. The dedicated early-return branch handling this case wrote a clear error message into the comment list, then returned without ever calling the callback that hides the "Loading…" trigger button and reveals the container holding that very message. Net effect: the server could respond almost instantly with a clear, correct rejection, and the visitor would still see a permanently stuck "Loading…" button with a perfectly good error message sitting invisible right behind it. This affects *any* reason `load_comments()` might ever return `success: false` — not just the specific cause above — and is now fixed by calling the completion callback in that branch too, matching what the catch block already correctly does for hard failures.

---

## [3.5.4] — 2026-07-03

Two sources this release: a "sort/filter doesn't work" report traced to a real bug, and a separately-submitted audit document with 6 findings, every one independently verified against actual code before being accepted (one of which led to discovering 4 more, related dead-code fields not in the original audit at all).

### Fixed — Reported bug
- **[HIGH] "Newest" and "Oldest" sort modes destroyed reply nesting when any thread was expanded.** Both `applySort()`'s "newest" branch and the shared `restoreChronologicalOrder()` function (used by "oldest" sort AND the pin/unpin snap-back-to-position behavior — three features, one root cause) selected elements via `list.querySelectorAll('li.comment')`, which searches the *entire subtree*, not just top-level comments — matching every reply nested inside any expanded thread's `<ul class="children">` too. Since `list.appendChild(el)` on an element that already exists elsewhere in the DOM *moves* it rather than cloning it, every matched reply was ripped out of its parent thread and flattened into the top-level list. The "liked" sort mode was never affected — it correctly used `list.children` (direct children only) from the start; "newest" was introduced by explicitly mirroring `restoreChronologicalOrder()`'s pattern, propagating the same defect into a second location. Both now use `:scope > li.comment`, scoped to direct top-level children only, matching what "liked" always did correctly.
  - Reproduction: click the sort toggle to cycle to "Newest" (3rd click from initial load) or "Oldest" (1st click) while any comment's replies are expanded. Previously this flattened the whole thread structure instantly and visibly; now it doesn't touch anything below the top level.

### Fixed — Audit findings (all independently verified before acceptance)
- **[CRITICAL] `load_comments()`/`load_replies()` never checked whether the current visitor is actually allowed to see the post at all** — only that it existed and had approved comments. A post moved to draft or private after collecting comments (ordinary editorial action, not an edge case), or password-protected, still served full comment content — author names, text, timestamps — to anyone who called either endpoint with the post_id, completely bypassing whatever visibility WordPress itself enforces on the post. Both now check `current_user_can('read_post', $post_id)` and `post_password_required($post)` before doing anything else. `current_user_can()` was used rather than a hardcoded `post_status !== 'publish'` string comparison because it correctly defers to WordPress's own capability system — private posts, scheduled/future posts, and custom post types with their own visibility rules are all handled correctly, while a post's own author or an editor can still load comments on their own draft, which a bare status check would incorrectly block.
- **[HIGH] `enqueue_assets()` never received the other half of a v3.5.0 fix.** `class-template-loader.php`'s `load_template()` was fixed in v3.5.0 to route to this plugin's template whenever a post has existing comments, even with new commenting closed — so JSON-LD schema output wouldn't describe a discussion the plugin's own UI couldn't display. `enqueue_assets()` (which loads the CSS/JS that actually makes that template functional) still had the old `is_singular() && comments_open()` condition alone, unchanged. Net effect on any closed-comments-with-existing-comments post: the template rendered, but with no styling and a "Load Comments" button that did nothing when clicked — the exact class of bug the v3.5.0 fix was meant to close, just moved one file over. Now matches the template loader's condition exactly.
- **[MEDIUM] `ajax_google_auth()`'s nonce check return value was completely discarded** — `check_ajax_referer('wp_rest', 'nonce', false)` was called on its own line with nothing reading the boolean it returns, so the function proceeded identically regardless of whether the nonce was valid, wrong, or missing entirely. Now wrapped in the same `if (!check_ajax_referer(...))` pattern every other nonce check in the codebase already uses.
- **[LOW] Migration retry-guard operated at coarser granularity than the work it was protecting.** `maybe_upgrade()`'s v1.1→v1.2 step ran three ALTER TABLE statements (add column, drop index, re-add index) behind a single guard checking only whether the column existed. A partial failure — column added successfully, index correction failed — would correctly prevent `update_option()` from advancing the stored version (that part already worked, from the v3.3.1 migration-failure fix), but on the *next* request, the guard would find the column already there and skip the entire block, including the index correction that never actually completed — silently leaving `unique_like` without `guest_token` in it, permanently, with no further retry. The column-existence check and the index-correctness check are now independent guards, so a failure in one doesn't mask the other from being retried.
- **Corrected two stale/inaccurate docblocks**: `class-rest-api.php`'s class-level comment claimed this class registers the Google OAuth callback route — it never has; that route is registered entirely independently in `class-oauth-google.php`. And the README's security table claimed `$wpdb->prepare()` is used "throughout" for SQL injection prevention — two `IN()` clauses use direct interpolation of `absint()`-cast ID arrays instead (safe, and a deliberate choice documented at the code level since v3.2.5, but not accurately described as `prepare()` specifically).
- **Removed 4 dead fields from the localized JS config**, discovered while verifying the OAuth docblock finding above: `googleAuth` (pointed to a REST route, `vibe-comments/v1/google-auth`, that was never registered anywhere — even reachable, this field was never read by any JS; the actual Google auth flow goes through the `ajax_google_auth()` admin-ajax action instead), `restUrl`, `loginUrl`, and `siteName` — all confirmed via full-codebase grep to have zero references anywhere in the JS or templates.

### Documentation
- **Added an operational note to the Google OAuth Setup section** about email-based account matching — standard "Sign in with X" behavior, not a plugin-specific design choice, but worth explicit awareness: a verified Google login matches to an existing WordPress account by email, so a visitor's Google account and a pre-existing WordPress account sharing an email will result in that visitor logging into the existing account's role and capabilities.

---

## [3.5.3] — 2026-07-03

### Fixed
- **Footer buttons overflowing/breaking on comments with all 4 possible actions** (reaction pill, Reply, View/Hide Replies, Pin/Unpin — only occurs on a pinned comment that also has replies). `.vibe-comment-footer` had no `flex-wrap`, defaulting to `nowrap`, which forced all 4 items onto one line; flex then shrank individual buttons to fit, and since none of them had `white-space: nowrap`, the squeezed button's own text wrapped internally (visibly, "Hide replies" broke into "Hide" / "replies" across two lines). Fixed with `flex-wrap: wrap` on the container plus `flex-shrink: 0` and `white-space: nowrap` on every footer item (reaction summary, Reply, View/Hide Replies, Pin/Unpin) — nothing gets squeezed anymore; the row wraps as whole buttons onto a second line instead when it doesn't fit.
- **Pinned badge rendering wider on the post author's own pinned comments than on other users' pinned comments**, despite identical badge markup and CSS. Root cause: `.vibe-comment-meta` is a `flex-direction: column` container with no explicit `align-items`, which defaults to `stretch` — every child (pinned badge, author-name line, timestamp) gets stretched to match the width of its *widest sibling*. On the post author's own comments, the "Author" badge sitting next to their name made that line wider than a regular commenter's plain name, and the pinned badge — a sibling in the same container — got stretched to match, even though "📌 Pinned" itself needs far less space. Regular (non-author) pinned comments never showed this because their author-name line was never wide enough to stretch anything. Fixed with `align-items: flex-start` on the container — every child sizes to its own content instead.

---

## [3.5.2] — 2026-07-02

### Added
- **Nginx Helper FastCGI purge integration** (see v3.5.7 for full feature).

### Fixed
- **GitLab CI protected branch rejection** — removed force-push from CI, manual push only.

---

## [3.5.1] — 2026-07-01

### Fixed
- **Version bump** — corrected from v3.5.0 to v3.5.1 in plugin header.

---

## [3.5.0] — 2026-07-01

### Added
- **Google OAuth (Sign in with Google)** — RS256 JWT signature verification against Google JWKS, HttpOnly SameSite cookie state binding, email-verified enforcement.
- **Guest UUID identity** — `crypto.randomUUID()` stored in localStorage, hashed server-side with AUTH_KEY, eliminates NAT-collision reaction conflicts.
- **Draft auto-save** — localStorage 7-day TTL, Ctrl+Enter submit.
- **Full dark mode via CSS custom properties** — all colors tokenized as `--vibe-*` variables.

### Changed
- **REST API fully deprecated** — all operations now admin-ajax only.
- **Edge cache removed** — responses now `Cache-Control: private, max-age=120` (browser-only) due to personalized `user_reaction` overlay.

### Fixed
- **`load_comments()`/`load_replies()` post visibility** — now properly check public status via `get_post_stati(['public' => true])` and `post_password_required()`.
- **`enqueue_assets()` condition** — matches template loader exactly.

---

## [3.2.3] — 2026-06-30
*(See full history in previous versions)*

---

[Unreleased]: https://github.com/godschi10/vibe-comments/compare/v3.5.7...HEAD
[3.5.7]: https://github.com/godschi10/vibe-comments/compare/v3.5.6...v3.5.7
[3.5.6]: https://github.com/godschi10/vibe-comments/compare/v3.5.5...v3.5.6
[3.5.5]: https://github.com/godschi10/vibe-comments/compare/v3.5.4...v3.5.5
[3.5.4]: https://github.com/godschi10/vibe-comments/compare/v3.5.3...v3.5.4
[3.5.3]: https://github.com/godschi10/vibe-comments/compare/v3.5.2...v3.5.3
[3.5.2]: https://github.com/godschi10/vibe-comments/compare/v3.5.1...v3.5.2
[3.5.1]: https://github.com/godschi10/vibe-comments/compare/v3.5.0...v3.5.1
[3.5.0]: https://github.com/godschi10/vibe-comments/compare/v3.2.3...v3.5.0
