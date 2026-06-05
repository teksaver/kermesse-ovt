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
});
