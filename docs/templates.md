# Templates

Warp exposes its whole front-end surface through the single `craft.warp` Twig
variable. Everything the example templates read is listed here, and nothing else
is exposed, so a template never reaches a service or a record directly.

Data accessors are read as properties, because Craft's magic getter drops the
`get` prefix. The two render builders are called as functions and terminated
with `.render()`.

## Data accessors

| Variable | Description |
|---|---|
| `craft.warp.hasPasskeys` | Returns whether the current user has any passkeys enrolled, and `false` for a guest. |
| `craft.warp.passkeys` | Returns the current user's enrolled passkeys, ready for a management list, and an empty array for a guest. |
| `craft.warp.webauthnJsUrl` | Returns the published URL of the reference WebAuthn client script, for the passkey login and enrollment JavaScript. |
| `craft.warp.registrationEnabled` | Returns whether passwordless registration is currently open, meaning Warp's `enableRegistration` setting and Craft's `users.allowPublicRegistration` are both on. |
| `craft.warp.loginMethods` | Returns the enabled login channels as a subset of `magic-link` and `otp`, so a template renders only the channels the site offers. |
| `craft.warp.otpDigits` | Returns the configured one-time-code length, for sizing a custom code input to match the setting instead of hardcoding it. |
| `craft.warp.requestedEmail` | Returns the email address the visitor last requested a credential for, or `null`. It is the visitor's own input echoed back from the session, so it reveals nothing, and the verify endpoint clears it on a successful sign-in. |
| `craft.warp.sessions` | Returns the current user's active sessions as `SessionInfo` models, with the current session flagged and each other device labelled, and an empty array for a guest. |
| `craft.warp.showPasskeyNudge` | Returns whether to show the one-time passkey-enrollment nudge. Reading it clears the flag, so the nudge surfaces exactly once per triggering sign-in. |

## Render builders

Two builders render the one-time-code UI for you. Both are fluent: every key in
the options array matches a setter that can also be chained before `.render()`,
and an unknown key throws, so a mistyped option fails loudly instead of being
ignored.

Always terminate a builder with `.render()`. Printing the builder itself
(`{{ craft.warp.otpForm() }}`) goes through `__toString()`, which Twig escapes,
so the markup appears on the page as visible tags.

Both builders register a small JavaScript and a visually neutral stylesheet the
first time they render on a page. Restyle them by overriding the
`warp-otp-form__*` and `warp-otp__*` classes; there is no configuration to
change first.

### `otpForm()`

Rendering the whole code-entry form is the normal starting point. It is what the
example `otp-verify.twig` page does, and it is the right choice unless you need
to compose your own form around the input.

```twig
{{ craft.warp.otpForm({
    returnUrl: url('members/account'),
    requestUrl: url('members/login'),
}).render() }}
```

That one call outputs the post to `warp/auth/verify-code` with CSRF, the
session-carried email prefill (or a visible email input on a direct visit), the
segmented code input, its hint, and the submit button.

| Option | Description |
|---|---|
| `attrs` | Merges extra attributes into the `<form>` element. |
| `digits` | Sets the number of digit boxes, defaulting to the resolved `otpDigits` setting. |
| `email` | Sets the address the form submits alongside the code, defaulting to the session-carried address of the just-requested code. When neither is present the form renders a visible email input instead. |
| `requestUrl` | Sets the URL of the request form, used by the "Use a different address" link next to the prefilled address. Defaults to Craft's `loginPath`, and the link is omitted when none is available. |
| `returnUrl` | Sets where a verified code lands the member, posted as `returnUrl` and validated as same-site before it is honoured. Defaults to none, which lands on the site root. |
| `submitLabel` | Sets the submit button label, defaulting to "Sign in". |

### `otpInput()`

Use the input alone when the page composes its own form around it, for example
to add your own fields or your own submit chrome.

```twig
{{ craft.warp.otpInput({
    name: 'code',
    label: 'Sign-in code'|t,
}).render() }}
```

The input renders as one square per digit, sized to the `otpDigits` setting,
with auto-advance, backspace, arrow keys, and paste distributing a full code
across the squares. With no JavaScript it degrades to a plain input, so the form
always submits.

| Option | Description |
|---|---|
| `autofocus` | Sets whether the input autofocuses on page load, defaulting to false. |
| `digits` | Sets the number of digit boxes, defaulting to the resolved `otpDigits` setting, so the input tracks the configured code length automatically. |
| `id` | Sets the input `id` attribute. When omitted, a stable per-render id is generated so a `<label for>` and `aria-describedby` linkage still work. |
| `inputAttrs` | Merges extra attributes into the `<input>` element, for example an `aria-describedby` pointing at your own hint. |
| `label` | Sets the accessible label the enhanced box group announces, defaulting to "Sign-in code". |
| `name` | Sets the input `name` attribute, defaulting to `code`, which is what `warp/auth/verify-code` reads. |

The setters can also be chained, which reads better when a value is conditional:

```twig
{% set input = craft.warp.otpInput().name('code') %}
{% if autofocus %}{% set input = input.autofocus(true) %}{% endif %}
{{ input.render() }}
```
