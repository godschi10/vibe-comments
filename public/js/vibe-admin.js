/* Vibe Comments admin JS (v3.17.4).
   The digest-preview handler, moved out of the inline <script> block that
   previously rendered inside the settings page body. */
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('vibe-digest-preview-btn');
    if (!btn) return;

    btn.addEventListener('click', function () {
        var status = document.getElementById('vibe-digest-preview-status');
        btn.disabled = true;
        status.textContent = 'Building\u2026';

        // Localized config wins; data-attributes remain as the fallback for
        // cached admin markup.
        var ajaxUrl = (window.vibeAdmin && vibeAdmin.ajaxUrl) || btn.dataset.ajaxUrl;
        var nonce   = (window.vibeAdmin && vibeAdmin.nonce)   || btn.dataset.nonce;

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'vibe_digest_preview',
                nonce: btn.dataset.nonce
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            if (!res || !res.success) {
                status.textContent = (res && res.data && res.data.message) ? res.data.message : 'Preview failed.';
                return;
            }
            status.textContent = '';
            document.getElementById('vibe-digest-preview-subject').textContent = res.data.subject;
            document.getElementById('vibe-digest-preview-wrap').style.display = 'block';
            document.getElementById('vibe-digest-preview-frame').srcdoc = res.data.html;
        })
        .catch(function (err) {
            btn.disabled = false;
            status.textContent = 'Preview failed: ' + err;
        });
    });
});
