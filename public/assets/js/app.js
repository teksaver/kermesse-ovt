// Reserved for progressive enhancements. Critical flows must work without JavaScript.

document.addEventListener('DOMContentLoaded', function () {
    // Strong-confirm inputs: enable the submit button only when the user types the exact word.
    document.querySelectorAll('[data-confirm-word]').forEach(function (input) {
        var targetId = input.getAttribute('data-target-btn');
        var expected = input.getAttribute('data-confirm-word');
        var btn      = targetId ? document.getElementById(targetId) : null;

        if (! btn || ! expected) return;

        var syncButtonState = function () {
            btn.disabled = (input.value !== expected);
        };

        syncButtonState();
        input.addEventListener('input', syncButtonState);
    });

    // Copy-to-clipboard: progressive enhancement. The button stays hidden until
    // JS runs, so without JS the user copies the visible link manually. Copying
    // is never the only way to share the link.
    document.querySelectorAll('[data-copy-button]').forEach(function (btn) {
        var targetId = btn.getAttribute('data-copy-target');
        var input    = targetId ? document.getElementById(targetId) : null;

        if (! input) return;

        btn.hidden = false;

        var feedback   = document.querySelector('[data-copy-feedback]');
        var successMsg = btn.getAttribute('data-copy-success') || 'Lien copié.';

        var announce = function () {
            if (feedback) {
                feedback.hidden      = false;
                feedback.textContent = successMsg;
            }
        };

        btn.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(announce, function () {
                    input.select();
                });
                return;
            }

            // Older browsers: fall back to execCommand, then to manual selection.
            input.select();
            try {
                if (document.execCommand('copy')) {
                    announce();
                }
            } catch (e) {
                // Clipboard unavailable: the selected, visible link can be copied manually.
            }
        });
    });
});
