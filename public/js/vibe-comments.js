/**
 * Vibe Comments - Frontend JavaScript
 * Zero dependencies. Handles likes, threaded replies, form submission, Google OAuth.
 * Includes live polling and AJAX pagination to bypass aggressive page caching.
 */
(function() {
    'use strict';

    const config = window.vibeComments || {};

    /**
     * The four supported reactions, in display order.
     * These must match the PHP whitelist (Vibe_Comments_Database::REACTION_TYPES).
     */
    const REACTION_DEFS = [
        { type: 'like',  emoji: '👍', label: 'Like'  },
        { type: 'heart', emoji: '❤️', label: 'Love'  },
        { type: 'fire',  emoji: '🔥', label: 'Fire'  },
        { type: 'laugh', emoji: '😂', label: 'Haha'  },
    ];

    /**
     * Build the reaction component HTML for a comment.
     *
     * Structure:
     *   .vibe-reactions
     *     .vibe-reaction-summary   ← always visible: shows top emoji + total count
     *     .vibe-reaction-picker    ← hidden; expands when summary is tapped
     *
     * After choosing a reaction the picker closes and the summary updates.
     * No absolute positioning — fully inline, no overflow concerns.
     */
    // Sort reactions by count descending, drop zeros.
    // Highest-count reaction is always first in the summary display.
    function getSortedReactions(reactions) {
        return REACTION_DEFS
            .map(function(def) {
                return { def: def, count: parseInt((reactions || {})[def.type] || 0, 10) };
            })
            .filter(function(r) { return r.count > 0; })
            .sort(function(a, b) { return b.count - a.count; });
    }

    // Build the innerHTML for the summary button.
    // Shows each non-zero reaction as emoji+count pair, sorted desc.
    // User's own reaction pair gets .vibe-rx-mine for a blue count accent.
    // Summary is ALWAYS: stacked emoji bubbles + one aggregate total.
    // Per-type counts only appear inside the picker — never in this button.
    function buildSummaryInner(sorted, userReaction) {
        if (sorted.length === 0) {
            return '<span class="vibe-rx-icon">🙂</span>' +
                   '<span class="vibe-rx-label">React</span>';
        }
        var total   = sorted.reduce(function(t, r) { return t + r.count; }, 0);
        var bubbles = sorted.slice(0, 3).map(function(r) {
            return '<span class="vibe-rx-bubble">' + r.def.emoji + '</span>';
        }).join('');
        return '<span class="vibe-rx-stack">' + bubbles + '</span>' +
               '<span class="vibe-rx-total">' + total + '</span>';
    }

    /**
     * Build the reaction component HTML — returns TWO separate strings.
     *
     *   summaryHtml → .vibe-reactions wrapper + compact summary button
     *                 goes INSIDE the footer flex row
     *   pickerHtml  → .vibe-reaction-picker with 4 emoji option buttons
     *                 goes OUTSIDE the footer, between content and footer
     *
     * Keeping picker outside the footer eliminates the layout-blowout bug
     * where the picker was a flex sibling of Reply and Pin on mobile.
     */
    function buildReactionBar(cid, reactions, userReaction) {
        var sorted = getSortedReactions(reactions);
        var total  = sorted.reduce(function(t, r) { return t + r.count; }, 0);

        var summaryHtml =
            '<div class="vibe-reactions"' +
            ' data-comment-id="' + cid + '"' +
            ' data-total-reactions="' + total + '">' +
                '<button type="button"' +
                ' class="vibe-reaction-summary' + (userReaction ? ' vibe-has-reaction' : '') + '"' +
                ' data-comment-id="' + cid + '"' +
                ' aria-label="React to this comment"' +
                ' aria-expanded="false">' +
                buildSummaryInner(sorted, userReaction) +
                '</button>' +
            '</div>';

        var pickerBtns = REACTION_DEFS.map(function(def) {
            var count    = parseInt((reactions || {})[def.type] || 0, 10);
            var isActive = userReaction === def.type;
            return '<button type="button"' +
                   ' class="vibe-reaction-option' + (isActive ? ' vibe-active-reaction' : '') + '"' +
                   ' data-comment-id="' + cid + '"' +
                   ' data-type="' + def.type + '"' +
                   ' title="' + escapeHtml(def.label) + '"' +
                   ' aria-label="' + escapeHtml(def.label) + ' — ' + count + '">' +
                   '<span class="vibe-rx-picker-emoji">' + def.emoji + '</span>' +
                   '<span class="vibe-rx-picker-n">' + (count || '') + '</span>' +
                   '</button>';
        }).join('');

        var pickerHtml =
            '<div class="vibe-reaction-picker"' +
            ' data-comment-id="' + cid + '" hidden>' +
            pickerBtns +
            '</div>';

        return { summaryHtml: summaryHtml, pickerHtml: pickerHtml };
    }
    let originalFormParent = null;
    let lastCheckTime = Math.floor(Date.now() / 1000);
    let lastPollTime  = 0;
    let pollInterval  = null;
    let knownCommentIds = new Set();
    let currentPage = 1;
    let hasMorePages = false; // Set by initComments() after first fetch
    let isLoadingMore = false;
    // Tracks the active sort mode so loadMoreComments() knows whether newly
    // fetched comments need to be re-sorted into the current view or can be
    // appended as-is. See initSortToggle() (sets this on every click) and
    // loadMoreComments() (reads it after appending a new page).
    let currentSortMode = 'newest'; // matches load_comments()'s server-side default order

    document.addEventListener('DOMContentLoaded', function() {
        // Display OAuth redirect-back errors (L4 fix — oauth_error() redirects
        // here with ?vibe_auth_error=message instead of calling wp_die()).
        var urlParams = new URLSearchParams(window.location.search);
        var authError = urlParams.get('vibe_auth_error');
        if (authError) {
            showError(decodeURIComponent(authError));
            // Remove the query param from the URL without a page reload.
            var cleanUrl = window.location.pathname +
                window.location.search.replace(/([?&])vibe_auth_error=[^&]*/g, '').replace(/^&/, '?');
            history.replaceState(null, '', cleanUrl || window.location.pathname);
        }
        // Count is rendered statically by PHP from the vibe_comment_count_{id}
        // option (may be stale by up to one comment if a new one landed since
        // the page was last cached). fetchCommentCount() below patches the
        // heading to the live value, decoupled from the page cache entirely —
        // see get_comment_count() in class-ajax-handler.php (v3.3.3) for why
        // this replaced a full-page purge on every comment.
        fetchCommentCount();
        initCommentsTrigger();
        if (!config.isLoggedIn) { refreshNonce(); }
        initReactions();
        initReplies();
        initViewReplies();
        initPinComment();
        initRelativeTime();

        // Retry button inside error list items.
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('vibe-retry-btn')) location.reload();
        });
        initForm();
        initGoogleAuth();
        initGuestToggle();
        // initLivePolling() intentionally NOT here — it starts inside
        // initCommentsTrigger's onLoaded callback so polling only fires
        // after the user explicitly loads comments. See B1 fix.
        initLoadMore();
        restoreGuestIdentity();
        initGuestAutoSave();
        initCharCounter();
    });

    /**
     * Single source of truth for "N Comments" heading text — localized via
     * config.oneCommentText / config.manyCommentsTemplate (populated from
     * PHP's __() in vibe-comments.php), matching templates/comments.php's
     * own 3-way branch exactly (0/1/many) so server and client never disagree.
     *
     * count === 0 returns '' (empty) rather than a "No comments yet" string —
     * setting an empty heading auto-hides it via the
     * `.vibe-comments-title:empty { display:none }` CSS rule, AND avoids
     * duplicating the empty-state block's own "Be the first to share your
     * thoughts" messaging that already appears directly below the heading
     * once Load Comments is clicked on a 0-comment post.
     *
     * Before this existed, FOUR separate call sites (fetchCommentCount,
     * initComments, incrementCommentHeading, plus the PHP template) each
     * reimplemented this pluralization independently — two of them
     * (initComments, incrementCommentHeading) hardcoded English text that
     * silently overwrote whatever this function or PHP had already
     * correctly localized, the moment a user clicked Load Comments or
     * posted a new comment. Consolidated to this one function so there is
     * exactly one place this logic can drift out of sync with the server.
     *
     * @param  {number} count
     * @return {string}
     */
    function commentCountText(count) {
        if (count === 0) return '';
        if (count === 1) return config.oneCommentText || '1 Comment';
        var tpl = config.manyCommentsTemplate || '%s Comments';
        return tpl.replace('%s', count.toLocaleString());
    }

    /**
     * Decoupled comment-count refresh (v3.3.3).
     *
     * Fires on every page load, independent of the "Load Comments" click.
     * Fetches the live count from get_comment_count() — a tiny, cache-backed
     * read (see its PHP docblock for the full scalability design) — and
     * patches the heading text if it differs from what PHP baked into the
     * cached page.
     *
     * Setting .textContent on the heading also auto-reveals it via the
     * `.vibe-comments-title:empty { display:none }` CSS rule — no separate
     * visibility toggle needed for the very-first-comment case (0 → 1).
     */
    function fetchCommentCount() {
        var heading = document.getElementById('vibe-comments-title');
        if (!heading || !config.postId) return;

        var url = config.ajaxUrl + '?action=vibe_get_comment_count&post_id=' + config.postId;

        fetchWithTimeout(url, {}, 8000)
        .then(function(res) { return res.json(); })
        .then(function(result) {
            if (!result.success || typeof result.data.count !== 'number') return;
            var text = commentCountText(result.data.count);
            if (heading.textContent !== text) {
                heading.textContent = text;
            }
        })
        .catch(function(err) {
            // Failure here is genuinely low-stakes for a normal visitor — the
            // PHP-rendered count stays visible, just possibly stale by one
            // comment, so this stays silent by default. Gated behind
            // config.debug so a developer who deliberately turned on
            // VIBE_COMMENTS_DEBUG_TOOLS can actually see it happened, rather
            // than this being unconditionally and permanently invisible.
            if (config.debug) { console.warn('Vibe Comments: fetchCommentCount failed', err); }
        });
    }

    /**
     * Render basic Markdown to safe HTML.
     * Supports: **bold**, *italic*, `code`, > blockquote, bare URLs.
     * HTML-escapes input first so no XSS is possible from comment content.
     */
    function renderMarkdown(text) {
        if (!text) return '';

        // 1. Escape HTML to prevent XSS (> becomes &gt; etc.)
        text = escapeHtml(text);

        // 2. Block-level: blockquotes — lines starting with &gt;
        var lines   = text.split('\n');
        var out     = [];
        var inBQ    = false;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (/^&gt;\s?/.test(line)) {
                if (!inBQ) { out.push('<blockquote>'); inBQ = true; }
                out.push(line.replace(/^&gt;\s?/, '') + '\n');
            } else {
                if (inBQ) { out.push('</blockquote>'); inBQ = false; }
                out.push(line + '\n');
            }
        }
        if (inBQ) out.push('</blockquote>');
        text = out.join('');

        // 3. Inline: code first (prevents bold/italic inside code spans)
        text = text.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        text = text.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

        // 4. Paragraph breaks — outside blockquote tags
        text = text.replace(/\n\n/g, '</p><p>');
        text = text.replace(/\n/g, '<br>');

        // 5. Wrap and fix blockquote nesting inside <p>
        text = '<p>' + text + '</p>';
        text = text.replace(/<p>(<blockquote>)/g, '$1');
        text = text.replace(/(<\/blockquote>)<\/p>/g, '$1');
        text = text.replace(/<p>\s*<\/p>/g, '');

        return text;
    }

    /** Convert bare https?:// URLs in processed HTML to clickable links. XSS-safe.
     *
     * Runs AFTER escapeHtml(), so & in URLs becomes &amp;. Removing & from the
     * exclusion set lets the regex capture full query strings — the browser correctly
     * decodes &amp; in href attributes as &, producing the correct URL. Link text
     * will display &amp; literally, which is a minor cosmetic issue but the link works.
     */
    function linkify(html) {
        return html.replace(
            /(https?:\/\/[^\s<>"]+)/gi,
            '<a href="$1" target="_blank" rel="noopener noreferrer ugc">$1</a>'
        );
    }

    /**
     * fetch() wrapper with AbortController timeout.
     * Prevents zombie requests hanging indefinitely on slow/dead servers.
     */
    function fetchWithTimeout(url, options, ms) {
        var ctrl = new AbortController();
        var tid  = setTimeout(function() { ctrl.abort(); }, ms || 15000);
        var opts = Object.assign({}, options || {}, { signal: ctrl.signal });
        return fetch(url, opts).finally(function() { clearTimeout(tid); });
    }

    /**
     * LIVE POLLING: Check for new comments and updated likes
     */
    function initLivePolling() {
        if (!config.postId) return;

        pollInterval = setInterval(function() {
            checkNewComments();
            syncReactions();
            lastPollTime = Date.now();
        }, 30000);

        // Throttle visibility-triggered polls — don't fire if polled within last 30s.
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && (Date.now() - lastPollTime) >= 30000) {
                checkNewComments();
                syncReactions();
                lastPollTime = Date.now();
            }
        });
    }

    function checkNewComments() {
        // Collect all visible comment IDs so the server returns fresh
        // reaction counts for them in the same request — no separate
        // syncReactions() call needed on each poll interval.
        var bars = document.querySelectorAll('.vibe-reactions[data-comment-id]');
        var url  = config.ajaxUrl + '?action=vibe_load_comments&post_id=' + config.postId
                 + '&since=' + lastCheckTime + '&per_page=20&_=' + Date.now();
        if (!config.isLoggedIn) { url += '&vibe_guest_id=' + encodeURIComponent(getGuestId()); }
        bars.forEach(function(bar) {
            var id = parseInt(bar.dataset.commentId, 10);
            if (id) url += '&comment_ids[]=' + id;
        });

        fetchWithTimeout(url, {}, 10000)
        .then(function(res) { return res.json(); })
        .then(function(result) {
            if (!result.success || !result.data) return;
            var data = result.data;

            // Process new comments (existing behaviour).
            if (data.comments && data.comments.length > 0) {
                var incoming = [];
                data.comments.forEach(function(comment) {
                    if (!knownCommentIds.has(comment.id)) {
                        incoming.push(comment);
                        knownCommentIds.add(comment.id);
                    }
                });
                if (incoming.length > 0) showNewCommentsBanner(incoming);
            }

            // Refresh reaction counts for all visible comments.
            // getUserReactionFromDOM() preserves whatever the user has selected —
            // the poll never overwrites their active state or closes an open picker.
            if (data.reaction_counts) {
                Object.keys(data.reaction_counts).forEach(function(id) {
                    updateReactionDisplay(
                        id,
                        data.reaction_counts[id],
                        getUserReactionFromDOM(id),
                        false  // never close picker on polling update
                    );
                });
            }

            lastCheckTime = data.timestamp || Math.floor(Date.now() / 1000);
        })
        .catch(function(err) {
            console.error('Live poll failed:', err);
        });
    }

    function showNewCommentsBanner(comments) {
        var existing = document.getElementById('vibe-new-banner');
        if (existing) {
            // Accumulate — update the count and append to pending buffer
            var pending = existing._pendingComments || [];
            existing._pendingComments = pending.concat(comments);
            var total = existing._pendingComments.length;
            var label = existing.querySelector('.vibe-banner-label');
            if (label) label.textContent = '\u2191 ' + total + ' new comment' + (total !== 1 ? 's' : '') + ' — click to load';
            return;
        }

        var list = document.querySelector('.vibe-comment-list');
        if (!list) return;

        var banner = document.createElement('div');
        banner.id = 'vibe-new-banner';
        banner.className = 'vibe-new-banner';
        // A2 fix: without these, a screen reader user gets no notification at
        // all that new comments arrived — the banner is purely a visual DOM
        // insertion with nothing announcing it. role="status" + aria-live
        // means assistive tech announces the label text the moment it's set,
        // matching how sighted users notice the banner appearing.
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');
        banner._pendingComments = comments;

        var label = document.createElement('span');
        label.className = 'vibe-banner-label';
        var n = comments.length;
        label.textContent = '\u2191 ' + n + ' new comment' + (n !== 1 ? 's' : '') + ' — click to load';
        banner.appendChild(label);

        banner.addEventListener('click', function() {
            var pending = banner._pendingComments || [];
            pending.forEach(function(c) {
                appendComment(c, c.parent, false);
            });
            banner.remove();
            hoistPinnedComments();
        });

        list.parentNode.insertBefore(banner, list);
    }

    /**
     * After rendering the initial comment list, sync reaction state from the server.
     * One POST fetches all counts + the current user's reactions in two DB queries.
     */
    function syncReactions() {
        var bars = document.querySelectorAll('.vibe-reactions[data-comment-id]');
        var commentIds = [];
        bars.forEach(function(bar) {
            var id = parseInt(bar.dataset.commentId, 10);
            if (id) commentIds.push(id);
        });
        if (commentIds.length === 0) return;

        var params = new URLSearchParams({ action: 'vibe_sync_likes', nonce: config.nonce });
        // Send vibe_guest_id so guest reaction state is correct even when a
        // non-logged-in user somehow triggers this path. Ignored server-side
        // for logged-in users (user_id > 0 takes precedence).
        var gid = getGuestId();
        if (gid) params.append('vibe_guest_id', gid);
        commentIds.forEach(function(id) { params.append('comment_ids[]', id); });

        fetchWithTimeout(config.ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    params,
        }, 10000)
        .then(function(res) { return res.json(); })
        .then(function(result) {
            if (result.success && result.data && result.data.likes) {
                Object.keys(result.data.likes).forEach(function(id) {
                    var d = result.data.likes[id];
                    updateReactionDisplay(id, d.reactions, d.user_reaction);
                });
            }
        })
        .catch(function(err) { console.error('Reaction sync failed:', err); });
    }

    /**
     * Read the user's currently active reaction for a comment from the DOM.
     * Used by the polling cycle to preserve user state when refreshing counts.
     */
    function getUserReactionFromDOM(commentId) {
        var article = document.getElementById('div-comment-' + commentId);
        if (!article) return null;
        var active = article.querySelector('.vibe-reaction-option.vibe-active-reaction');
        return active ? (active.dataset.type || null) : null;
    }

    function updateReactionDisplay(commentId, reactions, userReaction, closePicker) {
        // closePicker defaults to true. Pass false from polling updates so
        // an open picker isn't slammed shut mid-user-interaction.
        if (closePicker === undefined) closePicker = true;
        var article = document.getElementById('div-comment-' + commentId);
        if (!article) return;

        var bar     = article.querySelector('.vibe-reactions');
        var summary = article.querySelector('.vibe-reaction-summary');
        var picker  = article.querySelector('.vibe-reaction-picker');

        var sorted = getSortedReactions(reactions);
        var total  = sorted.reduce(function(t, r) { return t + r.count; }, 0);

        if (bar) bar.dataset.totalReactions = total;

        // ── Summary — always stacked bubbles + total, never scatters ─────
        // Rebuilding innerHTML is atomic; avoids any flash of stale state.
        if (summary) {
            var wasReacted = summary.classList.contains('vibe-has-reaction');
            summary.innerHTML = buildSummaryInner(sorted, userReaction);
            summary.classList.toggle('vibe-has-reaction', !!userReaction);
            summary.setAttribute('aria-expanded', 'false');

            if (userReaction && !wasReacted) {
                summary.classList.add('vibe-reaction-pulse');
                setTimeout(function() { summary.classList.remove('vibe-reaction-pulse'); }, 450);
            }
        }

        // ── Picker — sync each option's count + active state, then close ─
        // The per-type counts live HERE, below each emoji, not in the summary.
        if (picker) {
            REACTION_DEFS.forEach(function(def) {
                var opt = picker.querySelector('.vibe-reaction-option[data-type="' + def.type + '"]');
                if (!opt) return;
                var count = parseInt((reactions || {})[def.type] || 0, 10);
                opt.classList.toggle('vibe-active-reaction', userReaction === def.type);
                opt.setAttribute('aria-label', escapeHtml(def.label) + ' \u2014 ' + count);
                var nEl = opt.querySelector('.vibe-rx-picker-n');
                if (nEl) nEl.textContent = count || '';
            });
            if (closePicker) picker.hidden = true;
        }
    }

    /**
     * AJAX LOAD MORE: Pagination without page reload
     */
    function initLoadMore() {
        const btn = document.getElementById('vibe-load-more');
        if (!btn) return;

        btn.addEventListener('click', function() {
            if (isLoadingMore || !hasMorePages) return;
            loadMoreComments();
        });
    }

    function loadMoreComments() {
        isLoadingMore = true;
        var btn = document.getElementById('vibe-load-more');
        if (btn) { btn.disabled = true; btn.textContent = 'Loading...'; }

        var nextPage = currentPage + 1;

        var url = config.ajaxUrl + '?action=vibe_load_comments&post_id=' + config.postId
                + '&page=' + nextPage + '&per_page=10&_=' + Date.now();
        if (!config.isLoggedIn) { url += '&vibe_guest_id=' + encodeURIComponent(getGuestId()); }

        fetchWithTimeout(url, {}, 15000)
        .then(function(res) { return res.json(); })
        .then(function(result) {
            if (result.success && result.data && result.data.comments) {
                currentPage = nextPage;
                var comments = result.data.comments;
                var firstNewLi = null;
                comments.forEach(function(comment) {
                    if (!knownCommentIds.has(comment.id)) {
                        var li = appendCommentWithChildren(comment);
                        if (li && !firstNewLi) firstNewLi = li;
                    }
                });

                if (currentSortMode !== 'newest') {
                    // Fix: without this, a newly-fetched page (always arrives in
                    // the server's default newest-first order — load_comments()
                    // has no sort param) would land at the bottom of an
                    // already-resorted list, breaking whatever order the user
                    // had chosen. Re-sorting the WHOLE list (existing + newly
                    // appended) fixes this. Skips the scroll-to-newest behavior
                    // below since "first new comment" isn't a coherent concept
                    // once everything gets redistributed into oldest/liked
                    // order — the new items could end up scattered anywhere.
                    applySort(currentSortMode);
                } else if (firstNewLi) {
                    // B1 fix: scroll ONCE to the first new comment after the whole
                    // batch has rendered. The previous version called scrollIntoView()
                    // inside the loop — once per comment — so a 10-comment batch fired
                    // 10 competing smooth-scroll animations that visibly jittered the
                    // page instead of landing cleanly on the new content.
                    firstNewLi.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                hasMorePages = !!result.data.has_more;
                if (!hasMorePages && btn) { btn.style.display = 'none'; }
            }
        })
        .catch(function(err) {
            console.error('Load more failed:', err);
        })
        .finally(function() {
            isLoadingMore = false;
            if (btn) { btn.disabled = false; btn.textContent = 'Load More Comments'; }
        });
    }

    function appendCommentWithChildren(comment, parentList) {
        const list = parentList || document.querySelector('.vibe-comment-list');
        if (!list) return null;

        // Use buildCommentTree so all depths are rendered correctly (the manual
        // version only handled 2 levels and silently dropped depth-3 children).
        const li = buildCommentTree(comment);
        if (!li) return null;

        list.appendChild(li);

        // Track all IDs in the appended subtree via querySelectorAll — handles
        // any depth without explicit per-level loops.
        li.querySelectorAll('.vibe-comment-body').forEach(function(el) {
            var id = parseInt(el.id.replace('div-comment-', ''), 10);
            if (id) knownCommentIds.add(id);
        });

        // Scrolling intentionally removed from here — caller decides when/whether
        // to scroll (e.g. once after a whole batch, not once per item). See B1 fix.
        return li;
    }

    function createCommentElement(comment) {
        const li  = document.createElement('li');
        const cid = parseInt(comment.id, 10); // defensive int coercion — server guarantees intval()

        li.id = 'comment-' + cid;
        li.className = 'comment' + (comment.is_pinned ? ' vibe-comment-pinned' : '');

        const rxBar     = buildReactionBar(cid, comment.reactions, comment.user_reaction || null);
        const replyHtml = '<button type="button" class="comment-reply-link vibe-reply-trigger" data-comment-id="' + cid + '">Reply</button>';

        // v3.4.0: top-level comments arrive with children always empty and a
        // reply_count instead — replies are fetched on demand when this is
        // clicked (see initViewReplies()). A comment already carrying actual
        // children (only true for content returned BY vibe_load_replies
        // itself, which fully expands its subtree in one response) never
        // shows this button — there's nothing left to fetch for it.
        const hasReplyCount = typeof comment.reply_count === 'number' && comment.reply_count > 0;
        const alreadyExpanded = comment.children && comment.children.length > 0;
        const viewRepliesHtml = (hasReplyCount && !alreadyExpanded)
            ? '<button type="button" class="vibe-view-replies-btn" data-comment-id="' + cid + '" data-post-id="' + (config.postId || '') + '" data-state="collapsed">'
                + 'View ' + comment.reply_count + (comment.reply_count === 1 ? ' reply' : ' replies')
              + '</button>'
            : '';

        const pinHtml = config.isAdmin
            ? '<button type="button" class="vibe-pin-btn" data-comment-id="' + cid + '" data-pinned="' + (comment.is_pinned ? '1' : '0') + '">' + (comment.is_pinned ? 'Unpin' : 'Pin') + '</button>'
            : '';

        const authorBadge = comment.is_author ? ' <span class="vibe-author-badge">Author</span>'            : '';
        const pinnedBadge = comment.is_pinned ? '<span class="vibe-pinned-badge">&#128204; Pinned</span>' : '';
        const dateIso     = comment.date_gmt  ? comment.date_gmt.replace(' ', 'T') + 'Z'                   : '';

        li.innerHTML =
            '<article class="vibe-comment-body" id="div-comment-' + cid + '">' +
                '<header class="vibe-comment-header">' +
                    '<div class="vibe-comment-avatar">' +
                        '<img src="' + escapeHtml(comment.avatar) + '" alt="" width="48" height="48" style="border-radius:50%">' +
                    '</div>' +
                    '<div class="vibe-comment-meta">' +
                        pinnedBadge +
                        '<cite class="vibe-comment-author">' + escapeHtml(comment.author) + authorBadge + '</cite>' +
                        '<time class="vibe-comment-time" datetime="' + escapeHtml(dateIso) + '" title="' + escapeHtml(comment.date || '') + '">' + escapeHtml(comment.date) + '</time>' +
                    '</div>' +
                '</header>' +
                '<div class="vibe-comment-content">' + linkify(renderMarkdown(comment.content)) + '</div>' +
                // Picker lives here — outside the footer flex row — so it can never
                // push Reply or Pin off screen on mobile when it opens.
                rxBar.pickerHtml +
                '<footer class="vibe-comment-footer">' +
                    rxBar.summaryHtml +
                    replyHtml +
                    viewRepliesHtml +
                    pinHtml +
                '</footer>' +
            '</article>';

        // Collapse long comments
        if (comment.content && comment.content.length > 300) {
            const contentEl = li.querySelector('.vibe-comment-content');
            if (contentEl) {
                contentEl.classList.add('vibe-content-collapsed');
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'vibe-read-more';
                toggle.textContent = 'Read more';
                toggle.addEventListener('click', function() {
                    const collapsed = contentEl.classList.toggle('vibe-content-collapsed');
                    toggle.textContent = collapsed ? 'Read more' : 'Show less';
                });
                contentEl.insertAdjacentElement('afterend', toggle);
            }
        }

        return li;
    }

    function initReactions() {
        document.addEventListener('click', function(e) {

            // ── Summary button: toggle picker ────────────────────────────
            var summary = e.target.closest('.vibe-reaction-summary');
            if (summary) {
                e.preventDefault();
                var article = summary.closest('article.vibe-comment-body');
                var picker  = article && article.querySelector('.vibe-reaction-picker');
                if (!picker) return;

                var willOpen = picker.hidden;

                // Close every other open picker before potentially opening this one.
                document.querySelectorAll('.vibe-reaction-picker:not([hidden])').forEach(function(p) {
                    p.hidden = true;
                    var a  = p.closest('article.vibe-comment-body');
                    var sm = a && a.querySelector('.vibe-reaction-summary');
                    if (sm) sm.setAttribute('aria-expanded', 'false');
                });

                if (willOpen) {
                    picker.hidden = false;
                    summary.setAttribute('aria-expanded', 'true');
                }
                return;
            }

            // ── Reaction option: send reaction + close picker ────────────
            var option = e.target.closest('.vibe-reaction-option');
            if (option) {
                e.preventDefault();
                var commentId    = option.dataset.commentId;
                var reactionType = option.dataset.type;
                if (!commentId || !reactionType || option.disabled) return;

                option.disabled = true;

                fetchWithTimeout(config.ajaxUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams(Object.assign({
                        action:        'vibe_toggle_like',
                        nonce:         config.nonce,
                        comment_id:    parseInt(commentId, 10),
                        reaction_type: reactionType,
                    }, config.isLoggedIn ? {} : { vibe_guest_id: getGuestId() })),
                }, 10000)
                .then(function(res) { return res.json(); })
                .then(function(result) {
                    if (result.success && result.data) {
                        updateReactionDisplay(commentId, result.data.reactions, result.data.user_reaction);
                    } else {
                        var msg = (result.data && result.data.message) || 'Failed to react.';
                        showError(msg);
                        var a = option.closest('article.vibe-comment-body');
                        var p = a && a.querySelector('.vibe-reaction-picker');
                        if (p) p.hidden = true;
                    }
                })
                .catch(function(err) {
                    console.error('Reaction failed:', err);
                    var a = option.closest('article.vibe-comment-body');
                    var p = a && a.querySelector('.vibe-reaction-picker');
                    if (p) p.hidden = true;
                })
                .finally(function() { option.disabled = false; });
                return;
            }

            // ── Tap anywhere outside a picker or summary: close all ───────
            // Checking two targets (not just one .vibe-reactions container) because
            // the picker now lives outside .vibe-reactions — they are siblings.
            if (!e.target.closest('.vibe-reaction-picker') &&
                !e.target.closest('.vibe-reaction-summary')) {
                document.querySelectorAll('.vibe-reaction-picker:not([hidden])').forEach(function(p) {
                    p.hidden = true;
                    var a  = p.closest('article.vibe-comment-body');
                    var sm = a && a.querySelector('.vibe-reaction-summary');
                    if (sm) sm.setAttribute('aria-expanded', 'false');
                });
            }
        });
    }

    /**
     * Inline reply handling
     */
    function initReplies() {
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.vibe-reply-trigger');
            if (!link) return;
            e.preventDefault();

            const commentId = link.dataset.commentId;
            if (!commentId) return;

            moveFormToComment(commentId);
        });
    }

    function moveFormToComment(commentId) {
        const form = document.getElementById('vibe-comment-form');
        const parentInput = document.getElementById('vibe-comment-parent');
        const cancelBtn = document.getElementById('vibe-cancel-reply');
        const targetComment = document.getElementById('comment-' + commentId);
        const guestFields = document.getElementById('vibe-guest-fields');
        const guestToggle = document.getElementById('vibe-guest-toggle');
        const wrapper = document.getElementById('vibe-form-wrapper');

        if (!form || !parentInput || !cancelBtn || !targetComment || !wrapper) return;

        if (!originalFormParent) {
            originalFormParent = wrapper.parentElement;
        }

        parentInput.value = commentId;
        cancelBtn.style.display = 'inline-flex';

        if (!config.isLoggedIn && guestFields) {
            guestFields.style.display = 'grid';
            if (guestToggle) guestToggle.textContent = 'Hide Guest Form';
        }

        let replyContainer = targetComment.querySelector('.vibe-reply-container');
        if (!replyContainer) {
            replyContainer = document.createElement('div');
            replyContainer.className = 'vibe-reply-container';
            const body = targetComment.querySelector('.vibe-comment-body');
            if (body) body.appendChild(replyContainer);
        }

        replyContainer.appendChild(wrapper);

        const textarea = document.getElementById('vibe-comment-content');
        if (textarea) {
            textarea.focus();
            setTimeout(function() {
                textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
        }
    }

    function resetFormPosition() {
        const form = document.getElementById('vibe-comment-form');
        const parentInput = document.getElementById('vibe-comment-parent');
        const cancelBtn = document.getElementById('vibe-cancel-reply');
        const guestFields = document.getElementById('vibe-guest-fields');
        const guestToggle = document.getElementById('vibe-guest-toggle');
        const wrapper = document.getElementById('vibe-form-wrapper');

        if (!form || !wrapper) return;

        parentInput.value = '0';
        cancelBtn.style.display = 'none';

        if (guestFields && !config.isLoggedIn) {
            guestFields.style.display = 'none';
            if (guestToggle) guestToggle.textContent = 'Comment as Guest';
        }

        if (originalFormParent) {
            originalFormParent.appendChild(wrapper);
        } else {
            const section = document.getElementById('vibe-comments');
            if (section) section.appendChild(wrapper);
        }
    }

    document.addEventListener('click', function(e) {
        if (e.target.closest('#vibe-cancel-reply')) {
            resetFormPosition();
        }
    });

    /**
     * Draft auto-save
     * Persists textarea content to localStorage keyed by post ID.
     * Restores on page load; clears on successful submit or manual discard.
     * Returns the storage key so the submit handler can clear it on success.
     */
    function initDraftSave() {
        const form     = document.getElementById('vibe-comment-form');
        const textarea = form && form.querySelector('textarea[name="comment"]');
        if (!form || !textarea || !config.postId) return null;

        const DRAFT_KEY = 'vibe_draft_' + config.postId;
        const MAX_AGE   = 7 * 24 * 60 * 60 * 1000; // 7 days in ms

        // Restore on page load
        try {
            const raw = localStorage.getItem(DRAFT_KEY);
            if (raw) {
                const draft = JSON.parse(raw);
                const age   = Date.now() - (draft.ts || 0);
                if (draft.content && draft.content.trim() && age < MAX_AGE) {
                    textarea.value = draft.content;
                    textarea.dispatchEvent(new Event('input')); // sync char counter
                    showDraftBadge(form, DRAFT_KEY);
                } else {
                    localStorage.removeItem(DRAFT_KEY);
                }
            }
        } catch (e) {}

        // Debounced save on input
        let saveTimer; // reassigned on every keystroke for debounce
        textarea.addEventListener('input', function () {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(function () {
                try {
                    if (textarea.value.trim()) {
                        localStorage.setItem(DRAFT_KEY, JSON.stringify({
                            content: textarea.value,
                            ts:      Date.now(),
                        }));
                    } else {
                        localStorage.removeItem(DRAFT_KEY);
                        const badge = form.querySelector('.vibe-draft-badge');
                        if (badge) badge.remove();
                    }
                } catch (e) {}
            }, 800);
        });

        return DRAFT_KEY;
    }

    function showDraftBadge(form, draftKey) {
        var existing = form.querySelector('.vibe-draft-badge');
        if (existing) existing.remove();

        var badge = document.createElement('div');
        badge.className = 'vibe-draft-badge';

        var label = document.createTextNode('Draft restored\u2009\u2014\u2009');
        var btn   = document.createElement('button');
        btn.type        = 'button';
        btn.className   = 'vibe-draft-clear';
        btn.textContent = 'discard';

        btn.addEventListener('click', function () {
            try { localStorage.removeItem(draftKey); } catch (e) {}
            var t = form.querySelector('textarea[name="comment"]');
            if (t) {
                t.value = '';
                t.dispatchEvent(new Event('input'));
            }
            badge.remove();
        });

        badge.appendChild(label);
        badge.appendChild(btn);

        // Insert after .vibe-char-counter if present, else after textarea
        var anchor = form.querySelector('.vibe-char-counter') || form.querySelector('textarea[name="comment"]');
        if (anchor) anchor.insertAdjacentElement('afterend', badge);
    }

    function initForm() {
        const form = document.getElementById('vibe-comment-form');
        if (!form) return;

        const draftKey = initDraftSave();

        // Ctrl+Enter / Cmd+Enter submits the form.
        const textarea = form.querySelector('textarea[name="comment"]');
        if (textarea) {
            textarea.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const submitBtn = document.getElementById('vibe-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('vibe-loading');
            }

            const formData = new FormData(form);
            const data = {
                post_id: parseInt(formData.get('comment_post_ID'), 10),
                content: (formData.get('comment') || '').trim(),
                parent: parseInt(formData.get('comment_parent') || 0, 10),
                author: (formData.get('author') || '').trim(),
                email: (formData.get('email') || '').trim(),
            };

            if (!data.content) {
                showError('Please write a comment.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('vibe-loading');
                }
                return;
            }

            if (!config.isLoggedIn) {
                if (!data.author || !data.email) {
                    showError('Please enter your name and email to comment.');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('vibe-loading');
                    }
                    return;
                }
                if (!isValidEmail(data.email)) {
                    showError('Please enter a valid email address.');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('vibe-loading');
                    }
                    return;
                }
            }

            fetchWithTimeout(config.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(Object.assign({
                    action: 'vibe_submit_comment',
                    nonce: config.nonce,
                }, data, config.isLoggedIn ? {} : { vibe_guest_id: getGuestId() })),
            }, 15000)
            .then(function(res) {
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return res.text().then(function(text) {
                        console.error('Server returned non-JSON:', text.substring(0, 500));
                        // Detect WordPress fatal error HTML
                        if (text.indexOf('critical error') !== -1 || text.indexOf('wp-die-message') !== -1) {
                            throw new Error('A server fatal error occurred. Check error logs or contact support.');
                        }
                        throw new Error('Server returned an invalid response. Check browser console for details.');
                    });
                }
                return res.json();
            })
            .then(function(result) {
                if (result.success && result.data) {
                    if (result.data.comment) {
                        var newLi = appendComment(result.data.comment, data.parent);
                        knownCommentIds.add(result.data.comment.id);
                        // Keep heading count in sync.
                        incrementCommentHeading();
                        // A3 fix: move focus to the just-posted comment. Without
                        // this, a keyboard/screen-reader user has no confirmation
                        // their comment actually landed, or where — focus was
                        // simply left on the now-cleared, now-empty textarea.
                        // tabindex="-1" makes the <li> programmatically focusable
                        // without adding a permanent Tab stop; removed again on
                        // blur so it doesn't linger in the tab order afterward.
                        if (newLi) {
                            newLi.setAttribute('tabindex', '-1');
                            newLi.focus({ preventScroll: true }); // appendComment already scrolled
                            newLi.addEventListener('blur', function() {
                                newLi.removeAttribute('tabindex');
                            }, { once: true });
                        }
                    }
                    // Clear saved draft — comment is now posted.
                    try { if (draftKey) localStorage.removeItem(draftKey); } catch (e) {}
                    var draftBadge = form.querySelector('.vibe-draft-badge');
                    if (draftBadge) draftBadge.remove();

                    form.reset();
                    resetFormPosition();
                    saveGuestIdentity();

                    // Warm, personalised feedback — improves perceived quality.
                    var name = data.author || (config.isLoggedIn ? '' : '');
                    if (result.data.awaiting_moderation) {
                        showSuccess(name
                            ? 'Thanks ' + escapeHtml(name) + '! Your comment is pending review.'
                            : 'Thanks! Your comment is pending review.');
                    } else {
                        showSuccess(name
                            ? 'Thanks ' + escapeHtml(name) + '! Your comment is now live.'
                            : 'Your comment is live!');
                    }
                } else {
                    var msg = (result.data && result.data.message) || result.message || 'Failed to post comment.';
                    showError(msg);
                }
            })
            .catch(function(err) {
                console.error('Comment submission failed:', err);
                showError(err.message || 'Something went wrong. Please try again.');
            })
            .finally(function() {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('vibe-loading');
                }
            });
        });
    }

    /**
     * Insert a comment into the DOM. Two call sites: the submit-success
     * handler (a real new comment/reply just posted by this user) and the
     * "click banner to load" path for comments that arrived via live polling.
     *
     * v3.4.0 changes, both required by the newest-first default order:
     *
     *   Top-level (parentId === 0): PREPENDED, not appended. The default
     *   load order is now DESC (newest first) — appending to the bottom
     *   would place a brand-new comment visually among the OLDEST comments
     *   on the page, which is backwards.
     *
     *   Reply (parentId > 0): if the parent thread's replies are still
     *   collapsed (no <ul class="children"> rendered yet, or it exists but
     *   is hidden), this reply would silently vanish into a location the
     *   user can't see — including their own just-submitted reply, which is
     *   the one thing they most want to see confirmed. Handles all three
     *   parent states: no button, collapsed, or already expanded.
     */
    function appendComment(comment, parentId, scroll) {
        if (scroll === undefined) { scroll = true; }

        if (parentId === 0) {
            const list = document.querySelector('.vibe-comment-list');
            if (!list) return null;
            const li = createCommentElement(comment);
            list.prepend(li);
            // A brand-new top-level comment always sits above any pinned
            // comments visually if we don't re-hoist — pinned must stay on top.
            hoistPinnedComments();
            if (scroll) li.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return li;
        }

        const parentLi = document.getElementById('comment-' + parentId);
        if (!parentLi) return null; // parent not currently rendered (e.g. on another page) — nothing to attach to

        const li = createCommentElement(comment);
        let childUl = parentLi.querySelector(':scope > ul.children');

        if (!childUl) {
            // Thread was fully collapsed (no ul.children built yet). Create it
            // and put the new reply there directly — this is the case that
            // matters most: the user just clicked Reply and submitted, they
            // must see their own reply land, not have it disappear behind a
            // button that still says the stale pre-submission count.
            childUl = document.createElement('ul');
            childUl.className = 'children';
            parentLi.appendChild(childUl);
        } else if (childUl.style.display === 'none') {
            // Thread exists but is currently hidden — reveal it so the new
            // reply the user just posted is immediately visible.
            childUl.style.display = '';
        }

        childUl.appendChild(li);

        // Update or remove the View/Hide Replies button to match new reality.
        const viewBtn = parentLi.querySelector(':scope > article .vibe-view-replies-btn');
        if (viewBtn) {
            viewBtn.textContent = 'Hide replies';
        }

        if (scroll) li.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return li;
    }

    function escapeHtml(text) {
        // Regex-based equivalent of the previous DOM-element approach (set
        // textContent, read innerHTML back) — same four characters escaped,
        // in the same order (& first is mandatory, or you'd double-escape
        // the & this function just inserted for < > "). Avoids creating a
        // new <div> on every single call; this runs once per rendered field
        // (author, content, date, etc.) per comment, on every render.
        // Coerces non-string input the same way `div.textContent = text`
        // implicitly did, so null/undefined/numbers behave identically to before.
        text = (text === null || text === undefined) ? '' : String(text);
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function showError(message) {
        document.querySelectorAll('.vibe-message').forEach(function(el) { el.remove(); });

        const div = document.createElement('div');
        div.className = 'vibe-message vibe-message-error';
        div.textContent = message; // textContent safely escapes HTML

        const form = document.getElementById('vibe-comment-form');
        if (form) form.insertBefore(div, form.firstChild);

        setTimeout(function() { div.remove(); }, 5000);
    }

    function showSuccess(message) {
        document.querySelectorAll('.vibe-message').forEach(function(el) { el.remove(); });

        const div = document.createElement('div');
        div.className = 'vibe-message vibe-message-success';
        div.textContent = message;

        const form = document.getElementById('vibe-comment-form');
        if (form) form.insertBefore(div, form.firstChild);

        setTimeout(function() { div.remove(); }, 5000);
    }

    /**
     * Comments load on demand — show trigger button, fetch on click.
     */
    function initCommentsTrigger() {
        var triggerWrap = document.getElementById('vibe-comments-trigger');
        var btn         = document.getElementById('vibe-load-comments-btn');
        var container   = document.getElementById('vibe-comments-container');

        if (!btn || !container) return;

        // B4 fix: { once: true } auto-detaches the listener after first firing —
        // cleaner than a manual `loaded` boolean flag that left a dead listener
        // checking a flag on every subsequent click forever.
        btn.addEventListener('click', function() {
            btn.disabled    = true;
            btn.textContent = 'Loading\u2026';

            initComments(function onLoaded() {
                if (triggerWrap) triggerWrap.style.display = 'none';
                container.style.display = 'block';
                // B1 fix: only start polling after comments are actually loaded.
                // Previously initLivePolling() ran on DOMContentLoaded, firing an
                // HTTP request every 30 seconds from every visitor regardless of
                // whether they had clicked Load Comments. On a 50k/month post that
                // is 50k+ wasted AJAX requests from readers who never engaged.
                initLivePolling();
            });
        }, { once: true });
    }

    /**
     * Lazy load: fetch page 1 from admin-ajax and render into the empty list.
     * Accepts an optional callback fired after render completes.
     */
    function initComments(onComplete) {
        var list     = document.getElementById('vibe-comment-list');
        var titleEl  = document.getElementById('vibe-comments-title');
        var moreWrap = document.getElementById('vibe-load-more-wrap');

        if (!list || !config.postId) return;

        var url = config.ajaxUrl + '?action=vibe_load_comments&post_id=' + config.postId
                + '&page=1&per_page=10&_=' + Date.now();
        if (!config.isLoggedIn) { url += '&vibe_guest_id=' + encodeURIComponent(getGuestId()); }

        fetchWithTimeout(url, {}, 15000)
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(result) {
            list.innerHTML = '';

            if (!result.success || !result.data) {
                list.innerHTML = '<li class="vibe-error">Could not load comments. <button type="button" class="vibe-retry-btn">Try again</button></li>';
                return;
            }

            var data     = result.data;
            var comments = data.comments || [];
            var total    = parseInt(data.total_count || data.total, 10) || 0;
            var hasMore  = !!data.has_more;

            // Overwrite heading with the live accurate count from the server response.
            // PHP already rendered the wp_options count, but this corrects any edge
            // case where the option was slightly behind (e.g. plugin just installed).
            // Uses the same shared commentCountText() as fetchCommentCount() and
            // incrementCommentHeading() — previously this block hardcoded English
            // text directly, silently overwriting whatever fetchCommentCount() had
            // already correctly localized the moment a user clicked Load Comments.
            if (titleEl) {
                titleEl.textContent = commentCountText(total);
                // Visibility is handled entirely by the .vibe-comments-title:empty
                // CSS rule (no text content = hidden) — nothing in this codebase
                // sets display:none as an inline style on this element, so the
                // display-restoration check that used to live here was dead code.
            }

            if (comments.length === 0) {
                list.innerHTML =
                    '<li class="vibe-empty-state">' +
                        '<p class="vibe-empty-title">No comments yet</p>' +
                        '<p class="vibe-empty-sub">Be the first to share your thoughts \u2728</p>' +
                    '</li>';
            } else {
                var fragment = document.createDocumentFragment();
                comments.forEach(function(comment) {
                    var li = buildCommentTree(comment);
                    if (li) fragment.appendChild(li);
                });
                list.appendChild(fragment);

                list.querySelectorAll('.vibe-comment-body').forEach(function(el) {
                    var id = parseInt(el.id.replace('div-comment-', ''), 10);
                    if (id) knownCommentIds.add(id);
                });

                // Show sort toolbar once there are comments to sort.
                var toolbar = document.getElementById('vibe-comments-toolbar');
                if (toolbar) toolbar.style.display = 'flex';
                initSortToggle();
                initSearch();       // insert search beside sort select now toolbar is visible
                hoistPinnedComments(); // pinned comments to top — survives page refresh
            }

            hasMorePages = hasMore;
            if (moreWrap) {
                moreWrap.style.display = hasMore ? 'block' : 'none';
            }

            // Sync user-specific reaction state (which reactions the current user made).
            // Guest users' reactions are already embedded in comment.user_reaction from
            // the server response and don't need a separate sync call — their guest token
            // rotates daily so yesterday's reactions aren't tracked anyway.
            // Skipping this for guests saves one HTTP request for the majority of visitors.
            if (config.isLoggedIn) {
                syncReactions();
            }

            if (typeof onComplete === 'function') onComplete();
        })
        .catch(function(err) {
            console.error('Failed to load comments:', err);
            if (list) {
                list.innerHTML = '<li class="vibe-error">Could not load comments. <button type="button" class="vibe-retry-btn">Try again</button></li>';
            }
            if (typeof onComplete === 'function') onComplete();
        });
    }

    /**
     * Build a comment <li> with nested children. No scroll — for initial render.
     */
    function buildCommentTree(comment) {
        var li = createCommentElement(comment);
        if (!li) return null;

        if (comment.children && comment.children.length > 0) {
            var childUl = document.createElement('ul');
            childUl.className = 'children';
            comment.children.forEach(function(child) {
                var childLi = buildCommentTree(child);
                if (childLi) childUl.appendChild(childLi);
            });
            li.appendChild(childUl);
        }
        return li;
    }

    /**
     * On-demand reply loading (v3.4.0) — "View N replies" / "Hide replies" toggle.
     *
     * First click fetches the full nested subtree for that comment in one
     * request (see load_replies() in class-ajax-handler.php) and renders it
     * via the same buildCommentTree() the file already uses elsewhere —
     * replies arrive in the identical nested-children JSON shape
     * format_comment_tree() always produces, so no new rendering logic is
     * needed for the reply markup itself.
     *
     * Subsequent clicks toggle visibility only (existence of the already-built
     * <ul class="children"> is the state signal — no separate flag to drift
     * out of sync with reality). No re-fetch for the rest of the page view.
     */
    function initViewReplies() {
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.vibe-view-replies-btn');
            if (!btn) return;

            var li = btn.closest('li.comment');
            if (!li) return;

            var existingUl = li.querySelector(':scope > ul.children');

            if (existingUl) {
                var willShow = existingUl.style.display === 'none';
                existingUl.style.display = willShow ? '' : 'none';
                btn.textContent = willShow ? 'Hide replies' : (btn.dataset.collapsedLabel || 'View replies');
                return;
            }

            var commentId = btn.dataset.commentId;
            var postId    = btn.dataset.postId || config.postId;
            btn.dataset.collapsedLabel = btn.textContent; // remember "View N replies" for later
            btn.disabled    = true;
            btn.textContent = 'Loading\u2026';

            var url = config.ajaxUrl + '?action=vibe_load_replies&post_id=' + postId + '&comment_id=' + commentId;
            if (!config.isLoggedIn) { url += '&vibe_guest_id=' + encodeURIComponent(getGuestId()); }

            fetchWithTimeout(url, {}, 10000)
            .then(function(res) { return res.json(); })
            .then(function(result) {
                btn.disabled = false;
                if (!result.success || !result.data || !Array.isArray(result.data.replies) || result.data.replies.length === 0) {
                    btn.textContent = btn.dataset.collapsedLabel || 'View replies';
                    return;
                }

                var childUl = document.createElement('ul');
                childUl.className = 'children';
                result.data.replies.forEach(function(reply) {
                    var replyLi = buildCommentTree(reply);
                    if (replyLi) childUl.appendChild(replyLi);
                });
                li.appendChild(childUl);

                btn.textContent = 'Hide replies';
            })
            .catch(function(err) {
                console.error('Failed to load replies:', err);
                btn.disabled    = false;
                btn.textContent = btn.dataset.collapsedLabel || 'View replies';
            });
        });
    }

    /**
     * Silently fetch a fresh nonce to replace the one baked into the page cache.
     * WP nonces last 24h but a cached page can serve a nonce that is already 12h old.
     * Fires in parallel with initComments — no blocking.
     */
    function refreshNonce() {
        fetchWithTimeout(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'vibe_refresh_nonce' }),
        }, 8000)
        .then(function(res) { return res.json(); })
        .then(function(result) {
            if (result.success && result.data && result.data.nonce) {
                config.nonce = result.data.nonce;
            }
        })
        .catch(function() { /* cached nonce still valid for 24h — silently continue */ });
    }


    function initGoogleAuth() {
        const btn = document.getElementById('vibe-google-login');
        if (!btn) return;

        // Bail early if Google login is disabled in plugin settings.
        // The button is also conditionally rendered server-side, but this
        // guards against stale cached pages serving the button when it's off.
        if (!config.googleEnabled) {
            btn.style.display = 'none';
            return;
        }

        btn.addEventListener('click', function() {
            btn.disabled    = true;
            var originalText = btn.textContent;
            btn.textContent = 'Connecting\u2026';

            fetchWithTimeout(config.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action:     'vibe_google_auth',
                    nonce:      config.nonce,
                    return_url: window.location.href, // reliable regardless of Referrer-Policy
                }),
            }, 10000)
            .then(function(res) { return res.json(); })
            .then(function(result) {
                if (result.success && result.data && result.data.auth_url) {
                    window.location.href = result.data.auth_url;
                    // No need to re-enable btn — we're navigating away.
                } else {
                    showError('Google authentication is not configured.');
                    btn.disabled    = false;
                    btn.textContent = originalText;
                }
            })
            .catch(function(err) {
                console.error('Google auth failed:', err);
                showError('Failed to initiate Google login.');
                btn.disabled    = false;
                btn.textContent = originalText;
            });
        });
    }

    /**
     * Guest identity persistence via localStorage.
     * ─────────────────────────────────────────────
     * Three independent pieces of guest state are stored:
     *
     *   vibe_guest_name   — display name, pre-filled in the comment form.
     *   vibe_guest_email  — email, pre-filled in the comment form.
     *   vibe_gid          — stable random UUID used as the reaction identity
     *                       (H1 fix). Sent to the server as vibe_guest_id and
     *                       hashed with AUTH_KEY to produce the guest_token
     *                       stored in the DB. Eliminates the NAT-collision
     *                       problem where all users behind the same IP shared
     *                       an identity and could toggle off each other's reactions.
     */

    /**
     * Get (or generate) a stable random UUID for this browser.
     *
     * Prefers crypto.randomUUID() (Chrome 92+, Firefox 95+, Safari 15.4+).
     * Falls back to a manual crypto.getRandomValues() construction for older
     * browsers, and to Math.random() as a last resort (extremely old browsers
     * only — entropy is lower but still functionally unique).
     *
     * Returns an empty string if localStorage is unavailable (private browsing,
     * storage-full errors). The server falls back to IP-based guest token in
     * that case, preserving the pre-H1 behavior.
     *
     * @return {string}  UUID v4 string (36 chars) or '' on failure.
     */
    function getGuestId() {
        var STORAGE_KEY = 'vibe_gid';
        try {
            var existing = localStorage.getItem(STORAGE_KEY);
            if (existing && existing.length >= 32) return existing;

            var uuid;
            if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
                // Preferred: cryptographically secure, built-in UUID v4.
                uuid = crypto.randomUUID();
            } else if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
                // Fallback: build UUID v4 manually from 16 random bytes.
                var bytes = new Uint8Array(16);
                crypto.getRandomValues(bytes);
                // Set version (4) and variant (RFC 4122) bits.
                bytes[6] = (bytes[6] & 0x0f) | 0x40;
                bytes[8] = (bytes[8] & 0x3f) | 0x80;
                var hex = Array.from(bytes).map(function(b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
                uuid = hex.slice(0,8) + '-' + hex.slice(8,12) + '-' + hex.slice(12,16) + '-' +
                       hex.slice(16,20) + '-' + hex.slice(20);
            } else {
                // Last resort: Math.random() (low entropy, but practically unique
                // enough for this purpose on very old browsers).
                uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    var r = (Math.random() * 16) | 0;
                    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
                });
            }
            localStorage.setItem(STORAGE_KEY, uuid);
            return uuid;
        } catch (e) {
            // localStorage unavailable (private browsing, quota exceeded, etc.).
            // Return '' — server will fall back to IP-based guest token.
            return '';
        }
    }

    function saveGuestIdentity() {
        var author = document.getElementById('vibe-author');
        var email  = document.getElementById('vibe-email');
        try {
            if (author && author.value.trim()) {
                localStorage.setItem('vibe_guest_name', author.value.trim());
            }
            if (email && email.value.trim()) {
                localStorage.setItem('vibe_guest_email', email.value.trim());
            }
        } catch (e) { /* localStorage unavailable (private browsing, storage full) */ }
    }

    function restoreGuestIdentity() {
        var author = document.getElementById('vibe-author');
        var email  = document.getElementById('vibe-email');
        var name   = '';
        try {
            name           = localStorage.getItem('vibe_guest_name')  || '';
            var savedEmail = localStorage.getItem('vibe_guest_email') || '';
            if (author && !author.value && name)       author.value = name;
            if (email  && !email.value  && savedEmail) email.value  = savedEmail;
        } catch (e) { return; }

        // Only show the recall notice if we actually pre-filled the name.
        if (!name || !author || author.value !== name) return;
        // Don't add if it already exists (form may be moved around by initReplyHandling).
        if (document.getElementById('vibe-guest-recall')) return;

        var fields = document.getElementById('vibe-guest-fields');
        if (!fields) return;

        var notice = document.createElement('p');
        notice.id        = 'vibe-guest-recall';
        notice.className = 'vibe-guest-recall';
        notice.innerHTML = 'Commenting as <strong>' + escapeHtml(name) + '</strong>. ' +
                           '<button type="button" class="vibe-recall-clear">Not you?</button>';

        notice.querySelector('.vibe-recall-clear').addEventListener('click', function() {
            try {
                localStorage.removeItem('vibe_guest_name');
                localStorage.removeItem('vibe_guest_email');
            } catch (e) {}
            if (author) author.value = '';
            if (email)  email.value  = '';
            notice.remove();
        });

        // Insert immediately before the guest fields grid.
        fields.insertAdjacentElement('beforebegin', notice);
    }

    function initGuestAutoSave() {
        var author = document.getElementById('vibe-author');
        var email  = document.getElementById('vibe-email');
        if (author) { author.addEventListener('blur', saveGuestIdentity); }
        if (email)  { email.addEventListener('blur',  saveGuestIdentity); }
    }

    /**
     * Guest form toggle
     */
    function initGuestToggle() {
        const toggle = document.getElementById('vibe-guest-toggle');
        const fields = document.getElementById('vibe-guest-fields');
        if (!toggle || !fields) return;

        toggle.addEventListener('click', function() {
            const isHidden = fields.style.display === 'none' || !fields.style.display;
            fields.style.display = isHidden ? 'grid' : 'none';
            this.textContent = isHidden ? 'Hide Guest Form' : 'Comment as Guest';
        });
    }

    /**
     * Character counter — warns at 90% and blocks at maxCommentLength.
     * maxlength attribute is also set so the browser enforces it natively.
     */
    function initCharCounter() {
        var textarea = document.getElementById('vibe-comment-content');
        var countEl  = document.getElementById('vibe-char-count');
        var maxEl    = document.getElementById('vibe-char-max');
        var maxChars = parseInt(config.maxCommentLength || 2000, 10);

        if (!textarea || !countEl) return;

        if (maxEl) maxEl.textContent = maxChars.toLocaleString();
        textarea.setAttribute('maxlength', maxChars);

        var counter = countEl.closest ? countEl.closest('.vibe-char-counter') : null;

        textarea.addEventListener('input', function() {
            var len = this.value.length;
            countEl.textContent = len.toLocaleString();
            if (counter) {
                counter.classList.toggle('vibe-char-warn', len >= maxChars * 0.9 && len < maxChars);
                counter.classList.toggle('vibe-char-over', len >= maxChars);
            }
        });
    }

    /**
     * Sort toggle — reverses top-level comment order in the DOM.
     * Zero server calls. Nested replies stay under their parent.
     */
    /**
     * Apply a sort mode to the current top-level comment list. Always reads
     * live DOM state (list.children / the datetime attribute each comment
     * already carries) rather than a point-in-time snapshot — this is what
     * makes it safe to call again after loadMoreComments() appends a new
     * page: there's no stale captured array that doesn't know about the
     * newly added elements, unlike the original implementation, which
     * snapshotted "load order" once and reversed that fixed array for
     * "oldest" mode — a snapshot that couldn't account for comments
     * appended later via Load More.
     */
    function applySort(modeId) {
        const list = document.getElementById('vibe-comment-list');
        if (!list) return;

        if (modeId === 'oldest') {
            // Reuses the same chronological sort already proven correct for
            // the unpin-snaps-back-into-place behavior below.
            restoreChronologicalOrder(list);
        } else if (modeId === 'liked') {
            Array.from(list.children)
                .sort(function(a, b) {
                    const aL = parseInt(((a.querySelector('.vibe-reactions') || {}).dataset || {}).totalReactions || '0', 10);
                    const bL = parseInt(((b.querySelector('.vibe-reactions') || {}).dataset || {}).totalReactions || '0', 10);
                    return bL - aL;
                })
                .forEach(function(el) { list.appendChild(el); });
        } else {
            // newest — same datetime comparison as restoreChronologicalOrder(),
            // just descending instead of ascending.
            Array.from(list.querySelectorAll('li.comment'))
                .sort(function(a, b) {
                    const aEl = a.querySelector('time.vibe-comment-time[datetime]');
                    const bEl = b.querySelector('time.vibe-comment-time[datetime]');
                    const aDate = aEl ? aEl.getAttribute('datetime') : '';
                    const bDate = bEl ? bEl.getAttribute('datetime') : '';
                    if (aDate > bDate) return -1;
                    if (aDate < bDate) return  1;
                    const aId = parseInt((a.id || '').replace('comment-', ''), 10) || 0;
                    const bId = parseInt((b.id || '').replace('comment-', ''), 10) || 0;
                    return bId - aId;
                })
                .forEach(function(el) { list.appendChild(el); });
        }
        // Pinned comments must always stay at the top regardless of sort mode.
        hoistPinnedComments();
    }

    function initSortToggle() {
        const btn = document.getElementById('vibe-sort-toggle');
        const list = document.getElementById('vibe-comment-list');
        if (!btn || !list) return;

        // v3.4.0: load_comments() default order flipped to DESC (newest first,
        // see class-ajax-handler.php). "Newest" is therefore mode index 0.
        const modes = [
            { id: 'newest', label: '\u2193', title: 'Newest first' },
            { id: 'oldest', label: '\u2191', title: 'Oldest first' },
            { id: 'liked',  label: '\u2665', title: 'Most liked' },
        ];
        let idx = 0;  // current mode index — reassigned on each click

        btn.addEventListener('click', function() {
            idx = (idx + 1) % modes.length;
            const mode  = modes[idx];
            const label = btn.querySelector('.vibe-sort-label');
            if (label) label.textContent = mode.label;
            btn.title = mode.title;
            btn.setAttribute('data-mode', mode.id);
            applySort(mode.id);
            // Fix for Load More + Sort interaction: loadMoreComments() fetches
            // the next page in the SERVER's default order (always newest-first,
            // no sort param sent) and appends it — without tracking which sort
            // mode is currently active, a newly-appended page would land in
            // server order at the end of an already-resorted list, breaking
            // the visual sort the user just chose. See loadMoreComments().
            currentSortMode = mode.id;
        });
    }

    /** Search/filter visible comments by text. Zero server requests. */
    function initSearch() {
        const list    = document.getElementById('vibe-comment-list');
        const toolbar = document.getElementById('vibe-comments-toolbar');
        if (!list || !toolbar) return;

        const wrap   = document.createElement('div');
        wrap.className = 'vibe-search-wrap';

        const input  = document.createElement('input');
        input.type = 'search';
        input.className   = 'vibe-search-input';
        input.placeholder = 'Search comments\u2026';
        input.setAttribute('aria-label', 'Search comments');

        const status = document.createElement('span');
        status.className = 'vibe-search-status';
        status.setAttribute('aria-live', 'polite');

        wrap.appendChild(input);
        wrap.appendChild(status);
        toolbar.appendChild(wrap); // sits beside the sort select on the same row

        let timer; // reassigned on every input event for debounce
        input.addEventListener('input', function() {
            clearTimeout(timer);
            timer = setTimeout(function() {
                const q = input.value.trim().toLowerCase();
                let count = 0; // incremented in the loop below
                list.querySelectorAll('li.comment').forEach(function(li) {
                    // B6 fix: search was matching li.textContent, which includes
                    // "Reply", "Pin", reaction counts, and timestamps — so searching
                    // "Reply" matched every single comment. Now scoped to just the
                    // comment body and author name, which is what "search" should mean.
                    const content  = li.querySelector('.vibe-comment-content');
                    const author   = li.querySelector('.vibe-comment-author');
                    const haystack = (content ? content.textContent : '') + ' ' + (author ? author.textContent : '');
                    const match    = !q || haystack.toLowerCase().includes(q);
                    li.style.display = match ? '' : 'none';
                    if (match) count++;
                });
                status.textContent = q ? count + ' found' : '';
            }, 200);
        });
    }

    /**
     * Move pinned comments to the top of the list.
     * Called after initial load and after banner flush so position
     * survives page refresh — the is_pinned field comes from the server.
     */
    function hoistPinnedComments() {
        var list = document.getElementById('vibe-comment-list');
        if (!list) return;
        // querySelectorAll returns in DOM order; prepend reverses naturally
        var pinned = Array.from(list.querySelectorAll('li.comment.vibe-comment-pinned'));
        pinned.reverse().forEach(function(el) {
            list.prepend(el);
        });
    }

    /**
     * Re-sort all top-level comment <li> elements into chronological order
     * using the ISO datetime value stored on each comment's <time> element.
     * Falls back to numeric comment ID when datetimes are equal or absent.
     * Called immediately after unpinning so the comment snaps back to its
     * rightful position without requiring a page refresh.
     */
    function restoreChronologicalOrder(list) {
        var items = Array.from(list.querySelectorAll('li.comment'));
        items.sort(function(a, b) {
            var aEl = a.querySelector('time.vibe-comment-time[datetime]');
            var bEl = b.querySelector('time.vibe-comment-time[datetime]');
            var aDate = aEl ? aEl.getAttribute('datetime') : '';
            var bDate = bEl ? bEl.getAttribute('datetime') : '';
            if (aDate < bDate) return -1;
            if (aDate > bDate) return  1;
            // Equal datetimes: fall back to comment ID (ascending = chronological)
            var aId = parseInt((a.id || '').replace('comment-', ''), 10) || 0;
            var bId = parseInt((b.id || '').replace('comment-', ''), 10) || 0;
            return aId - bId;
        });
        items.forEach(function(item) { list.appendChild(item); });
    }

    /** Admin-only: pin/unpin a comment to the top of the list. */
    function initPinComment() {
        if (!config.isAdmin) return;
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.vibe-pin-btn');
            if (!btn) return;
            var id     = btn.dataset.commentId;
            var pinned = btn.dataset.pinned === '1';
            btn.disabled = true;
            fetchWithTimeout(config.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({
                    action:     'vibe_pin_comment',
                    nonce:      config.nonce,
                    comment_id: id,
                    pin:        pinned ? '0' : '1',
                }),
            }, 8000)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                if (!res.success) return;
                var nowPinned = res.data.pinned;
                btn.dataset.pinned = nowPinned ? '1' : '0';
                btn.textContent    = nowPinned ? 'Unpin' : 'Pin';

                var li = btn.closest('li.comment');
                if (!li) return;
                li.classList.toggle('vibe-comment-pinned', nowPinned);

                var meta  = li.querySelector('.vibe-comment-meta');
                var badge = li.querySelector('.vibe-pinned-badge');
                if (nowPinned && !badge && meta) {
                    var b = document.createElement('span');
                    b.className   = 'vibe-pinned-badge';
                    b.textContent = '\uD83D\uDCCC Pinned';
                    meta.prepend(b);
                } else if (!nowPinned && badge) {
                    badge.remove();
                }

                var cmtList = document.getElementById('vibe-comment-list');
                if (!cmtList) return;

                if (nowPinned) {
                    // Pin: move to top immediately.
                    cmtList.prepend(li);
                } else {
                    // Unpin: restore full chronological order, then re-hoist
                    // any comments that are still pinned. The unpinned comment
                    // snaps back to its date-based position with no page refresh.
                    restoreChronologicalOrder(cmtList);
                    hoistPinnedComments();
                }
            })
            .catch(function(err) {
                console.error('Pin toggle failed:', err);
                btn.disabled = false;
            });
        });
    }
    function initRelativeTime() {
        setInterval(function() {
            document.querySelectorAll('time.vibe-comment-time[datetime]').forEach(function(el) {
                var dt = el.getAttribute('datetime');
                if (dt) el.textContent = timeAgo(new Date(dt));
            });
        }, 60000);
    }

    function timeAgo(date) {
        var diff = Math.floor((Date.now() - date.getTime()) / 1000);
        if (diff <    60) return 'just now';
        if (diff <  3600) return Math.floor(diff / 60)   + ' min ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
        var days = Math.floor(diff / 86400);
        if (days  <   7)  return days  + ' day'  + (days  > 1 ? 's' : '') + ' ago';
        var weeks = Math.floor(days / 7);
        if (weeks <   5)  return weeks + ' week' + (weeks > 1 ? 's' : '') + ' ago';
        var months = Math.floor(days / 30);
        if (months < 12)  return months + ' month' + (months > 1 ? 's' : '') + ' ago';
        return Math.floor(days / 365) + ' yr ago';
    }

    /** Bump the "N Comments" heading by 1 after a successful submission. */
    function incrementCommentHeading() {
        var titleEl = document.getElementById('vibe-comments-title');
        if (!titleEl) return;

        // Parse current count. If heading was empty (0 comments, CSS hidden),
        // parseInt returns NaN — treat as 0 so first post yields "1 Comment".
        var current = parseInt(titleEl.textContent, 10);
        if (isNaN(current)) current = 0;

        var next = current + 1;
        // Shared with fetchCommentCount() and initComments() — see
        // commentCountText()'s docblock for why this was consolidated.
        titleEl.textContent = commentCountText(next);
        // Heading may have been empty/hidden via CSS :empty — text content
        // now makes it non-empty so the :empty rule no longer applies.
    }

})();
