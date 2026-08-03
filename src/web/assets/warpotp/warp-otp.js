/**
 * Warp segmented one-time-code input.
 *
 * Enhances every `input[data-warp-otp]` into one square per digit: typing
 * auto-advances, Backspace steps back, arrow keys navigate, and pasting a
 * full code anywhere distributes it across the boxes. The original input is
 * hidden but keeps carrying the submitted value, so the form posts exactly
 * what an unenhanced page would; with no JavaScript the plain input stays.
 *
 * The markup this creates is not fixed here. `craft.warp.otpInput()` resolves
 * the group's and the boxes' attributes server-side (`boxesAttrs`, `boxAttrs`)
 * and hands them over as `data-warp-otp-boxes` and `data-warp-otp-box` JSON, so
 * a site owns those class names. The literals below are only reached by markup
 * that carries `data-warp-otp` without them, i.e. hand-written rather than
 * built by the render builder.
 */
(function () {
    'use strict';

    function parseAttrs(raw) {
        if (!raw) {
            return null;
        }
        try {
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function applyAttrs(element, attrs) {
        Object.keys(attrs).forEach(function (name) {
            element.setAttribute(name, attrs[name]);
        });
    }

    function enhance(source) {
        if (source.dataset.warpOtpEnhanced) {
            return;
        }
        source.dataset.warpOtpEnhanced = '1';

        var digits = parseInt(source.getAttribute('data-warp-otp'), 10);
        if (!digits || digits < 1) {
            return;
        }

        var digitLabel = source.getAttribute('data-warp-otp-digit-label') || 'Digit {n} of {count}';
        var enhancedClass = source.getAttribute('data-warp-otp-enhanced-class') || 'warp-otp--enhanced';
        var groupAttrs = parseAttrs(source.getAttribute('data-warp-otp-boxes')) || {
            'class': 'warp-otp__boxes',
            role: 'group'
        };
        var boxAttrs = parseAttrs(source.getAttribute('data-warp-otp-box')) || {
            type: 'text',
            'class': 'warp-otp__box',
            inputmode: 'numeric'
        };

        var group = document.createElement('div');
        applyAttrs(group, groupAttrs);
        group.setAttribute('aria-label', source.getAttribute('data-warp-otp-label') || '');

        var describedBy = source.getAttribute('aria-describedby');
        if (describedBy) {
            group.setAttribute('aria-describedby', describedBy);
        }

        var boxes = [];

        for (var i = 0; i < digits; i += 1) {
            var box = document.createElement('input');
            applyAttrs(box, boxAttrs);
            box.autocomplete = i === 0 ? 'one-time-code' : 'off';
            // Room for a platform autofill dropping the whole code into one
            // box; the input handler redistributes anything longer than one.
            box.maxLength = digits;
            box.setAttribute('aria-label', digitLabel.replace('{n}', String(i + 1)).replace('{count}', String(digits)));
            boxes.push(box);
            group.appendChild(box);
        }

        source.insertAdjacentElement('beforebegin', group);

        // The original input keeps carrying the submitted value, hidden via
        // CSS (a display:none control still submits). Required must come off:
        // an invalid unfocusable control would block submission invisibly.
        source.classList.add(enhancedClass);
        source.setAttribute('aria-hidden', 'true');
        source.tabIndex = -1;
        source.required = false;

        function sync() {
            source.value = boxes.map(function (b) { return b.value; }).join('');
        }

        function distribute(text, from) {
            var chars = (text || '').replace(/\D/g, '').split('');
            if (!chars.length) {
                return;
            }
            // A full code always fills from the first box, wherever it landed.
            var start = chars.length >= digits ? 0 : from;
            for (var i = 0; i < chars.length && start + i < digits; i += 1) {
                boxes[start + i].value = chars[i];
            }
            sync();
            boxes[Math.min(start + chars.length, digits - 1)].focus();
        }

        boxes.forEach(function (box, index) {
            box.addEventListener('input', function () {
                var value = box.value.replace(/\D/g, '');
                if (value.length > 1) {
                    box.value = '';
                    distribute(value, index);
                    return;
                }
                box.value = value;
                sync();
                if (value && index < digits - 1) {
                    boxes[index + 1].focus();
                }
            });

            box.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && !box.value && index > 0) {
                    boxes[index - 1].value = '';
                    boxes[index - 1].focus();
                    sync();
                    event.preventDefault();
                } else if (event.key === 'ArrowLeft' && index > 0) {
                    boxes[index - 1].focus();
                    event.preventDefault();
                } else if (event.key === 'ArrowRight' && index < digits - 1) {
                    boxes[index + 1].focus();
                    event.preventDefault();
                }
            });

            box.addEventListener('paste', function (event) {
                event.preventDefault();
                var clipboard = event.clipboardData || window.clipboardData;
                distribute(clipboard ? clipboard.getData('text') : '', index);
            });

            box.addEventListener('focus', function () {
                box.select();
            });
        });

        if (source.hasAttribute('autofocus')) {
            boxes[0].focus();
        }
    }

    function init() {
        var inputs = document.querySelectorAll('input[data-warp-otp]');
        Array.prototype.forEach.call(inputs, enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
