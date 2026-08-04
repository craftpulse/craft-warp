/**
 * Warp passkey sign-in button.
 *
 * Runs Auth Kit's WebAuthn login ceremony from every `[data-warp-passkey]`
 * container on the page: the button starts it, the status paragraph announces a
 * failure and takes focus, and the fallback paragraph replaces the button
 * outright in a browser that cannot do passkeys at all. On success the browser
 * goes to the container's return URL, which the server has already validated.
 *
 * The markup this reads is not fixed here. `craft.warp.passkeyButton()` resolves
 * every element's attributes and every string server-side and hands them over as
 * the data attributes below, so a site owns the class names and the copy:
 *
 *   - the container carries `data-warp-passkey`, the options and login endpoint
 *     URLs, the return URL, the CSRF field name, and the two failure messages
 *   - it holds `[data-warp-passkey-button]`, and optionally
 *     `[data-warp-passkey-fallback]` and `[data-warp-passkey-status]`
 *   - it holds Craft's own CSRF field, read by name at click time so an
 *     asynchronously filled one (`asyncCsrfInputs`) is picked up too
 *
 * Nothing here falls back to a class name or a string of its own: markup that
 * omits an attribute gets the behavior without it.
 */
(function () {
    'use strict';

    function csrfToken(container) {
        var field = container.getAttribute('data-warp-passkey-csrf-field');
        if (!field) {
            return null;
        }
        var inputs = container.querySelectorAll('input[type="hidden"]');
        for (var i = 0; i < inputs.length; i += 1) {
            if (inputs[i].name === field) {
                return inputs[i].value;
            }
        }
        return null;
    }

    function fail(button, status, message) {
        button.disabled = false;
        if (!status || !message) {
            return;
        }
        status.textContent = message;
        status.hidden = false;
        status.focus();
    }

    function bind(container) {
        if (container.dataset.warpPasskeyBound) {
            return;
        }
        container.dataset.warpPasskeyBound = '1';

        var button = container.querySelector('[data-warp-passkey-button]');
        if (!button) {
            return;
        }

        var fallback = container.querySelector('[data-warp-passkey-fallback]');
        var status = container.querySelector('[data-warp-passkey-status]');

        // Auth Kit's client script owns the ceremony, so a page without it has
        // no passkey affordance to offer, exactly like a browser without
        // WebAuthn.
        var supported = !!window.AuthKit
            && typeof window.AuthKit.loginWithPasskey === 'function'
            && window.AuthKit.supportsWebAuthn();

        if (!supported) {
            button.hidden = true;
            if (fallback) {
                fallback.hidden = false;
            }
            return;
        }

        button.addEventListener('click', function () {
            if (status) {
                status.hidden = true;
            }
            button.disabled = true;

            window.AuthKit.loginWithPasskey({
                optionsUrl: container.getAttribute('data-warp-passkey-options-url'),
                loginUrl: container.getAttribute('data-warp-passkey-login-url'),
                csrfToken: csrfToken(container)
            }).then(function () {
                var returnUrl = container.getAttribute('data-warp-passkey-return-url');
                window.location.href = returnUrl || window.location.href;
            }).catch(function (error) {
                // A cancelled or timed-out browser ceremony throws a DOMException
                // whose message is written for developers, not members.
                var ceremonyFailed = error && (error.name === 'NotAllowedError' || error.name === 'AbortError');
                var message = ceremonyFailed
                    ? container.getAttribute('data-warp-passkey-cancelled-text')
                    : ((error && error.message) || container.getAttribute('data-warp-passkey-failed-text'));

                fail(button, status, message);
            });
        });
    }

    function init() {
        var containers = document.querySelectorAll('[data-warp-passkey]');
        Array.prototype.forEach.call(containers, bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
