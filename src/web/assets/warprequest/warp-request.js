/**
 * Warp request form — channel-aware redirect.
 *
 * Keeps a request form's hashed `redirect` field in step with the selected
 * channel, so picking "email me a code" lands on the code-entry page and
 * picking "email me a link" lands on the check-your-email page. Only relevant
 * when a site offers both channels and gives them different pages.
 *
 * The DOM contract, which a hand-written form can satisfy just as well as
 * `craft.warp.requestForm()`:
 *
 *   - the form carries `data-warp-request`
 *   - it holds one `input[name="redirect"]` (Craft's hashed redirect)
 *   - each channel radio carries `data-warp-redirect` with the hashed redirect
 *     for that channel, from `{{ 'members/otp-verify'|hash }}`
 *
 * With the script absent the form still submits and still signs the member in;
 * it just posts the redirect of the channel that was selected on render.
 */
(function () {
    'use strict';

    function bind(form) {
        if (form.dataset.warpRequestBound) {
            return;
        }
        form.dataset.warpRequestBound = '1';

        var redirect = form.querySelector('input[name="redirect"]');
        if (!redirect) {
            return;
        }

        var radios = form.querySelectorAll('input[data-warp-redirect]');

        Array.prototype.forEach.call(radios, function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked && radio.dataset.warpRedirect) {
                    redirect.value = radio.dataset.warpRedirect;
                }
            });
        });
    }

    function init() {
        var forms = document.querySelectorAll('form[data-warp-request]');
        Array.prototype.forEach.call(forms, bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
