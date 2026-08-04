# Templates

Warp exposes its front-end surface through the single `craft.warp` Twig variable:
data accessors for the state a member area needs, and four render builders for
the forms and the passkey button. A template never reaches a Warp service or
record directly.

Data accessors are read as properties, because Twig resolves the `get` prefix
itself. The render builders are called as functions and terminated with
`.render()`.

Warp also installs [Auth Kit](https://github.com/craftpulse/craft-auth-kit) as a
library, which registers a second handle, `craft.authKit`. Its passkey accessors
are the same ones `craft.warp` re-exposes, so there is no reason to reach for it;
it is documented here only so you know what it is if you find it.

## Data accessors

| Variable | Description |
|---|---|
| `craft.warp.hasPasskeys` | Returns whether the current user has any passkeys enrolled, and `false` for a guest. |
| `craft.warp.passkeys` | Returns the current user's enrolled passkeys, ready for a management list, and an empty array for a guest. See [passkey shape](#the-passkey-shape). |
| `craft.warp.webauthnJsUrl` | Returns the published URL of the reference WebAuthn client script, for the passkey login and enrollment JavaScript. |
| `craft.warp.registrationEnabled` | Returns whether passwordless registration is currently open, meaning Warp's `enableRegistration` setting and Craft's `users.allowPublicRegistration` are both on. |
| `craft.warp.loginMethods` | Returns the enabled login channels as a subset of `magic-link` and `otp`, so a template renders only the channels the site offers. |
| `craft.warp.otpDigits` | Returns the configured one-time-code length, for sizing a custom code input to match the setting instead of hardcoding it. |
| `craft.warp.requestedEmail` | Returns the email address the visitor last requested a credential for, or `null`. It is the visitor's own input echoed back from the session, so it reveals nothing, and the verify endpoint clears it on a successful sign-in. |
| `craft.warp.sessions` | Returns the current user's active sessions, with the current session flagged and each other device labelled, and an empty array for a guest. See [session shape](#the-session-shape). |
| `craft.warp.showPasskeyNudge` | Returns whether to show the one-time passkey-enrollment nudge. Reading it clears the flag, so the nudge surfaces exactly once per triggering sign-in. |

### The session shape

`craft.warp.sessions` returns `SessionInfo` models. Seven properties:

| Property | Description |
|---|---|
| `city` | The coarse city the session signed in from, or `null` with no geo database installed and on any session that predates the registry. |
| `deviceLabel` | A friendly label such as "Chrome on macOS", falling back to "Unknown device". |
| `deviceType` | One of `desktop`, `mobile`, `tablet` or `unknown`, for picking an icon. |
| `ip` | The recorded IP address, or `null` for a session that predates the registry. Coarsened when `anonymizeIp` is on. |
| `isCurrent` | Whether this is the session making the request. Render it as "This device" and offer no sign-out button, since the normal sign-out link ends it. |
| `lastSeen` | A `DateTime` of the session's last activity, or `null`. |
| `uid` | The handle `warp/sessions/revoke` takes. **`null` means the session cannot be signed out individually**, so branch on it: "Sign out everywhere else" still clears those. |

```twig
{% for session in craft.warp.sessions %}
    <li>
        {{ session.deviceLabel }}
        {% if session.city %}({{ session.city }}){% endif %}
        {% if session.isCurrent %}
            <span>{{ 'This device'|t }}</span>
        {% elseif session.uid %}
            {# post session.uid to warp/sessions/revoke #}
        {% endif %}
    </li>
{% endfor %}
```

### The passkey shape

`craft.warp.passkeys` returns an array of arrays with three keys:

| Key | Description |
|---|---|
| `credentialName` | The name the member gave this passkey. |
| `dateLastUsed` | A `DateTime` of its last use, or `null` if it has never been used. |
| `uid` | The handle `warp/passkeys/delete` takes. |

## Render builders

Four builders render Warp's front-end controls: `requestForm()` for the sign-in
and sign-up email form, `otpForm()` for the code-entry form, `otpInput()` for the
segmented code input alone, and `passkeyButton()` for the passkey sign-in section
beside them. The first three cover both steps of the email flow.

All four are fluent: every key in the options array matches a setter that can
also be chained, and an unknown key throws, so a mistyped option fails loudly
instead of being ignored.

**Always terminate a builder with `.render()`.** Printing the builder itself
(`{{ craft.warp.otpForm() }}`) goes through `__toString()`, which Twig escapes,
so the markup appears on the page as visible tags.

### `requestForm()`

The form every visitor starts at. One email field drives both sign-in and
sign-up: Warp resolves the address server-side and either emails an existing
account a sign-in credential or, with registration open, emails an unknown
address a sign-up link. The response is identical either way, so the form never
reveals which addresses are registered, and the page you redirect to must read
the same whether the address was known, unknown or garbage.

```twig
{{ craft.warp.requestForm({
    linkSentUrl: 'members/link-sent',
    otpVerifyUrl: 'members/otp-verify',
    returnUrl: url('members/account'),
}).render() }}
```

That call outputs the post to `warp/auth/request` with CSRF, the labelled email
input, the channel choice when the site offers both a magic link and a one-time
code, and the submit button.

| Option | Description |
|---|---|
| `attrs` | Merges attributes into the `<form>`. |
| `channel` | Forces a single channel, `magic-link` or `otp`, posted as a hidden field instead of offered as a choice. Defaults to every enabled login method. |
| `channelsAttrs` | Merges attributes into the `<fieldset>` around the channel choice. |
| `choiceAttrs` | Merges attributes into each `<label>` wrapping a channel radio. |
| `email` | Prefills the email input. Empty by default; pass `craft.warp.requestedEmail` to remember the address after a failed attempt. |
| `emailAttrs` | Merges attributes into the email `<input>`. |
| `emailLabel` | Sets the email input's label, defaulting to "Email address". |
| `fieldAttrs` | Merges attributes into the wrapper around the email input and its label. |
| `labelAttrs` | Merges attributes into the email `<label>`. |
| `legend` | Sets the `<legend>` above the channel choice, defaulting to "How would you like to sign in?" |
| `legendAttrs` | Merges attributes into that `<legend>`. |
| `linkSentUrl` | Sets the page a magic-link request lands on after posting, your "check your email" page, posted as Craft's hashed `redirect`. |
| `magicLinkLabel` | Sets the magic-link choice's label, defaulting to "Email me a sign-in link". |
| `otpLabel` | Sets the one-time-code choice's label, defaulting to "Email me a sign-in code". |
| `otpVerifyUrl` | Sets the page a one-time-code request lands on after posting, your code-entry page, posted as Craft's hashed `redirect`. |
| `radioAttrs` | Merges attributes into each channel radio. |
| `renderCss` | Set to `false` to leave Warp's stylesheet out of this render. See [turning Warp's CSS off](#turning-warps-css-off). |
| `returnUrl` | Sets where the emailed credential lands the member once they use it, posted as `returnUrl`. Honoured only when it belongs to the site the request was made against, see [return URLs](endpoints.md#return-urls). Defaults to none, which lands on the site root. |
| `submitAttrs` | Merges attributes into the submit button. |
| `submitLabel` | Sets the submit button label, defaulting to the only enabled channel's own label, or "Continue" when the visitor is choosing. |

Set both page URLs when both channels are enabled. With only one set, both
channels use it; with neither, no `redirect` is posted and the visitor stays on
the form with a success flash.

### `otpForm()`

Rendering the whole code-entry form is the normal starting point, and it is the
right choice unless you need to compose your own form around the input.

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
| `attrs` | Merges attributes into the `<form>`. |
| `boxAttrs` | Merges attributes into each digit box, by way of the nested code input. |
| `boxesAttrs` | Merges attributes into the box group, by way of the nested code input. |
| `changeAttrs` | Merges attributes into the "use a different address" link. |
| `changeLabel` | Sets that link's text, defaulting to "Use a different address". |
| `digitLabel` | Sets each digit box's accessible label, defaulting to "Digit {n} of {count}". |
| `digits` | Sets the number of digit boxes, defaulting to the resolved `otpDigits` setting. Leave it alone unless you know why, see [the digits warning](#the-digits-warning). |
| `email` | Sets the address the form submits alongside the code, defaulting to the session-carried address of the just-requested code. When neither is present the form renders a visible email input instead. |
| `emailAttrs` | Merges attributes into that visible email input. |
| `emailLabel` | Sets its label, defaulting to "Email address". |
| `enhancedClass` | Sets the class the script adds to the original input once it is enhanced, defaulting to `warp-otp--enhanced`. |
| `fieldAttrs` | Merges attributes into each field wrapper, the `<div>` around a label and its control. |
| `hint` | Sets the hint below the code input, defaulting to "Enter the {digits}-digit code from your email." `{digits}` is replaced. |
| `hintAttrs` | Merges attributes into that hint. Its `id` is what the input's `aria-describedby` points at. |
| `inputAttrs` | Merges attributes into the code `<input>`. |
| `label` | Sets the code input's label, used for the visible `<label>` and the box group's accessible name, defaulting to "Sign-in code". |
| `labelAttrs` | Merges attributes into each `<label>`. |
| `renderCss` | Set to `false` to leave Warp's stylesheet out of this render. |
| `requestUrl` | Sets the URL of the request form, used by the "use a different address" link. Defaults to Craft's `loginPath`, and the link is omitted when none is available. |
| `returnUrl` | Sets where a verified code lands the member, posted as `returnUrl`. Honoured only when it belongs to the site the request was made against, see [return URLs](endpoints.md#return-urls). Defaults to none, which lands on the site root. |
| `sentAttrs` | Merges attributes into the "your code was sent to" line. |
| `sentText` | Sets that line, defaulting to "Your code was sent to {email}." `{email}` is replaced. |
| `submitAttrs` | Merges attributes into the submit button. |
| `submitLabel` | Sets the submit button label, defaulting to "Sign in". |

### `otpInput()`

Use the input alone when the page composes its own form around it, for example to
add your own fields or your own submit chrome. You are then responsible for the
rest of the [`warp/auth/verify-code` contract](endpoints.md#warpauthverify-code).

```twig
{{ craft.warp.otpInput({
    name: 'code',
    label: 'Sign-in code'|t,
}).render() }}
```

The input renders as one square per digit, sized to the `otpDigits` setting, with
auto-advance, backspace, arrow keys, and paste distributing a full code across the
squares. With no JavaScript it degrades to a plain input, so the form always
submits.

| Option | Description |
|---|---|
| `autofocus` | Sets whether the input autofocuses on page load, defaulting to false. |
| `boxAttrs` | Merges attributes into each digit box the client script creates, defaulting to `{class: 'warp-otp__box'}` plus the type and input mode the widget needs. |
| `boxesAttrs` | Merges attributes into the box group, defaulting to `{class: 'warp-otp__boxes', role: 'group'}`. |
| `digitLabel` | Sets each box's accessible label, defaulting to "Digit {n} of {count}". `{n}` and `{count}` are replaced. |
| `digits` | Sets the number of digit boxes, defaulting to the resolved `otpDigits` setting, so the input tracks the configured code length automatically. |
| `enhancedClass` | Sets the class the script adds to the original input once it is enhanced, defaulting to `warp-otp--enhanced`, which is what hides it. |
| `id` | Sets the input `id`. When omitted, a stable per-render id is generated so a `<label for>` and `aria-describedby` linkage still work. |
| `inputAttrs` | Merges attributes into the `<input>`. |
| `label` | Sets the accessible label the enhanced box group announces, defaulting to "Sign-in code". |
| `name` | Sets the input `name`, defaulting to `code`, which is what `warp/auth/verify-code` reads. |
| `renderCss` | Set to `false` to leave Warp's stylesheet out of this render. |

`{{ input.getId() }}` returns the generated id, so a `<label for>` of your own can
point at it. Read it before `.render()`, since reading is what fixes the id for
the render. Note that `{{ input.id }}` does **not** work: Twig resolves `id` to
the one-argument setter.

The setters can also be chained, which reads better when a value is conditional:

```twig
{% set input = craft.warp.otpInput().name('code') %}
{% if autofocus %}{% set input = input.autofocus(true) %}{% endif %}
{{ input.render() }}
```

### The digits warning

`digits` sizes the input, not the code. The server issues codes of the configured
`otpDigits` length whatever you pass here, so a `digits` that disagrees caps the
input below the length of a valid code and makes signing in impossible. Warp logs
a warning when the two differ. Change the `otpDigits` setting instead, and let
both the builders and the issuer follow it.

### `passkeyButton()`

The passkey sign-in section for a member who already enrolled one. It belongs
beside the email form, never instead of it: the ceremony runs in the browser, so
a member on a device without a passkey still needs the email path.

```twig
{{ craft.warp.passkeyButton({
    returnUrl: url('members/account'),
}).render() }}
```

That call outputs the container the client script binds to, the button that runs
the ceremony, the line that replaces the button in a browser without passkey
support, the live region a failure is announced in, and Craft's own CSRF field for
the two core endpoints the ceremony posts to
(`auth/passkey-request-options` and `users/login-with-passkey`). It also loads
both scripts the ceremony needs, so there is no `<script>` tag to write yourself.

| Option | Description |
|---|---|
| `attrs` | Merges attributes into the container `<div>`. Its `data` attributes are the client script's contract, and a `data` array of yours merges key by key, so it can never take `data-warp-passkey` with it. |
| `buttonAttrs` | Merges attributes into the `<button>`. |
| `cancelledText` | Sets the message announced when the member cancels the browser prompt or lets it time out, defaulting to "Passkey sign-in was cancelled or timed out. Try again, or use your email above instead." |
| `failedText` | Sets the message announced when the ceremony fails for any other reason and carries no message of its own, defaulting to "Passkey sign-in failed. Please try your email instead." |
| `fallbackAttrs` | Merges attributes into the fallback `<p>`. |
| `fallbackText` | Sets the fallback line's text, defaulting to "Passkeys are not available in this browser. Use your email above instead." |
| `label` | Sets the button label, defaulting to "Sign in with a passkey". |
| `renderCss` | Set to `false` to leave Warp's stylesheet out of this render. |
| `returnUrl` | Sets where a completed ceremony lands the member. Validated exactly as a posted `returnUrl` is, see [return URLs](endpoints.md#return-urls), and a refused value is logged and replaced with the site root. Defaults to the site root. |
| `statusAttrs` | Merges attributes into the status `<p>`. It carries `role="alert"` and `tabindex="-1"` so a failure is announced and can take focus, so replace those only with equivalents. |

The heading above the section, and any divider around it, stay yours: they are
page furniture rather than part of the control. The example bundle's login page
shows the shape.

`returnUrl` is validated here and not only at an endpoint, because this one is
assigned to `window.location.href` in the browser rather than posted anywhere. A
value that does not belong to the site the page was served from never reaches the
page, so a `javascript:` URL or another site's host cannot ride in on a
`?returnUrl=` of a visitor's own choosing.

The CSRF field is the one element with no `*Attrs` option, because Craft owns its
markup: with `asyncCsrfInputs` on, Craft renders a placeholder and swaps in the
real field from its own endpoint, which drops anything you had put on it. Warp
emits it through Craft's own helper for exactly that reason, so a statically
cached page still posts a fresh token, and the script reads the field by name when
the button is clicked rather than when the page loads.

## Styling and overriding

Warp ships one small stylesheet and two small scripts, and none of it is a
decision you are stuck with. Nothing is hardcoded that you cannot replace, add
to, or switch off.

### Your classes are added, not swapped in

Every element each builder emits has an `*Attrs` option, and every string it
renders has a copy option. A `class` you pass through an `*Attrs` option
**accumulates onto** Warp's own class rather than replacing it, so both hooks land
on the element:

```twig
{{ craft.warp.otpForm({
    submitAttrs: { class: 'button button--primary' },
}).render() }}
```

```html
<button type="submit" class="warp-otp-form__submit button button--primary">
```

That means a stylesheet of yours can target either name, and Warp's own baseline
still applies to anything you have not styled yet. To own an element's `class`
outright, pass `resetClass: true` in the same array:

```twig
{{ craft.warp.otpForm({
    submitAttrs: { class: 'button button--primary', resetClass: true },
}).render() }}
```

```html
<button type="submit" class="button button--primary">
```

A `data` array merges key by key for the same reason, so adding a data attribute
of your own never takes Warp's `data-warp-otp` with it, which would silently
disable the segmented input. Every other attribute is replaced outright, and
passing `null` or `false` removes it.

### Your CSS wins the cascade

Warp's stylesheet keeps every cosmetic rule inside a `warp` cascade layer.
Un-layered CSS always beats layered CSS, so an ordinary rule in your own
stylesheet wins over Warp's with no `!important` and nothing to out-specify:

```css
/* This wins, wherever it sits in your stylesheet. */
.warp-otp__box { border-radius: 0; }
```

This matters because Craft injects plugin stylesheets last in `<head>`, after your
own, so before the layer a same-specificity rule of yours lost on source order.
One rule stays outside the layer deliberately: the one hiding the original code
input once the script has replaced it with the digit boxes. That is behavior
rather than style, and leaving it layered would show the raw input and the boxes
at once in a browser without cascade-layer support.

If your own stylesheet uses cascade layers, remember that a layer of yours
declared before `warp` loses to it. Declare yours after, or leave the rules that
must win un-layered.

The same rule cuts the other way for a reset. Anything that zeroes `border-width`
across the board, Tailwind's preflight and some normalize builds do, beats Warp's
layered baseline and takes the digit boxes' borders with it, which leaves white
boxes on a white card. Give the boxes a border explicitly rather than relying on
Warp's, as the example bundle now does:

```twig
{{ craft.warp.otpForm({
    boxAttrs: { class: 'border border-gray-300' },
}).render() }}
```

An un-layered reset always wins that fight; a layered one wins whenever its layer
is declared after `warp`. Either way the explicit border is the fix, and it is
worth writing even on a page you believe has no reset.

### Turning Warp's CSS off

To leave the stylesheet out of one render, pass `renderCss: false`:

```twig
{{ craft.warp.otpForm({ renderCss: false }).render() }}
```

To leave it out everywhere, set `renderCss` in `config/warp.php`:

```php
<?php

return [
    // Never register Warp's baseline stylesheet. The markup and the client
    // behavior are unaffected.
    'renderCss' => false,
];
```

With it off, Warp emits no CSS at all and the digit boxes are yours to style from
nothing. Style the `enhancedClass` yourself, or set it to a class of your own that
hides the original input, since that is the one rule the widget depends on.

### The scripts always load

There is no switch for Warp's JavaScript. The scripts are the affordance rather
than decoration: `warp-otp.js` is what turns the code input into per-digit boxes
with paste and arrow-key handling, `warp-request.js` is what keeps the request
form's redirect in step with the selected channel, and `warp-passkey.js` is the
passkey ceremony itself. Switching the first two off would leave a working but
plainer form, which is what a page gets anyway when JavaScript is unavailable, and
switching the third off would leave a button that does nothing.

`passkeyButton()` also loads Auth Kit's own WebAuthn client script, the one
`craft.warp.webauthnJsUrl` names, since that is what runs the ceremony. A page
that wires the ceremony by hand loads it itself, from that variable.

If you want different markup, write your own template against the DOM contract
below rather than turning the script off.

## Writing your own markup

Warp registers no site template root, so every page a member visits is a template
you own. The example bundle is a starting point to edit, not vendor code, and the
builders are a convenience you can decline: a hand-written form works exactly as
well as long as it posts what the endpoint reads.

To roll your own, take the POST contract from the
[endpoints reference](endpoints.md) and, if you want the segmented code input,
satisfy the DOM contract below. Or keep `craft.warp.otpInput()` for that one part
and hand-write everything around it, which is the shortest path.

**Post `returnUrl`, not Craft's `redirect`, to `warp/auth/verify-code`.** That
action reads a plain `returnUrl` body param and validates it against the base URL
of the site the request was made against; it never consumes Craft's hashed
`redirect`, so a `{{ redirectInput('members/account') }}` written out of habit is
ignored and the member lands on the site root instead. The asymmetry is
deliberate:
`warp/auth/request` does use Craft's hashed `redirect`, because that is what the
channel swap rewrites to send a magic-link post and a code post to different
pages, while a verified code goes straight to the destination the credential was
issued for.

### The code input's DOM contract

`warp-otp.js` enhances every `input[data-warp-otp]` on the page. `otpInput()`
emits all of this for you; these are the attributes to write yourself if you are
not using it.

The script normally rides along with a builder: `otpInput()` registers it, and so
does `otpForm()`, which renders that input. A page built entirely by hand, with no
builder on it at all, has nothing to register the bundle, so register it yourself:

```twig
{% do view.registerAssetBundle('craftpulse\\warp\\assetbundles\\warpotp\\WarpOtpAsset') %}
```

Without that line the markup below still posts correctly, but the code input stays
one plain field: nothing is loaded to build the boxes.

| Attribute | Description |
|---|---|
| `data-warp-otp` | Required. The number of digit boxes to build. The script does nothing without it. |
| `data-warp-otp-label` | The accessible name given to the box group. |
| `data-warp-otp-digit-label` | The per-box accessible name. `{n}` and `{count}` are replaced. |
| `data-warp-otp-enhanced-class` | The class added to your input once it is enhanced, which is what hides it. Defaults to `warp-otp--enhanced`. |
| `data-warp-otp-boxes` | A JSON object of attributes to apply to the box group. Defaults to `{"class": "warp-otp__boxes", "role": "group"}`. |
| `data-warp-otp-box` | A JSON object of attributes to apply to each digit box. Defaults to `{"type": "text", "class": "warp-otp__box", "inputmode": "numeric"}`. |
| `aria-describedby` | Copied onto the box group, so a hint of yours describes the boxes too. |
| `maxlength` | Set it to the same number as `data-warp-otp`. |
| `name` | `code`, which is what `warp/auth/verify-code` reads. |
| `autofocus` | Present, the script focuses the first box. |

The script inserts the box group immediately before your input, hides your input
with the enhanced class, drops its `required` (an invalid unfocusable control
would block submission invisibly), and keeps writing the joined value back into
it. So your form posts exactly what an unenhanced page would post, and the two
class names the boxes carry are whatever you named them.

Two attributes it manages itself, because they vary per box: each box's
`aria-label` and the first box's `autocomplete="one-time-code"`.

### The request form's DOM contract

`warp-request.js` only matters when a site offers both channels and gives them
different destination pages. It enhances every `form[data-warp-request]`.

| Attribute | Description |
|---|---|
| `data-warp-request` | Required, on the `<form>`. |
| `input[name="redirect"]` | Craft's hashed redirect, from `{{ redirectInput('members/link-sent') }}`. The script rewrites its value. |
| `data-warp-redirect` | On each channel radio, the hashed redirect for that channel, from `{{ 'members/otp-verify'|hash }}`. |

Radios without `data-warp-redirect` are left alone, so a form that posts one
fixed redirect needs none of this.

### The passkey button's DOM contract

`warp-passkey.js` runs the ceremony from every `[data-warp-passkey]` container on
the page. `passkeyButton()` emits all of this for you; these are the attributes to
write yourself if you are not using it. A page with no builder on it registers
nothing, so register both scripts as well:

```twig
{% do view.registerJsFile(craft.warp.webauthnJsUrl) %}
{% do view.registerAssetBundle('craftpulse\\warp\\assetbundles\\warppasskey\\WarpPasskeyAsset') %}
```

| Attribute | Description |
|---|---|
| `data-warp-passkey` | Required, on the container. The script binds to nothing without it. |
| `data-warp-passkey-button` | Required, on the `<button>` inside the container. Nothing happens without it. |
| `data-warp-passkey-options-url` | The request-options endpoint, from `{{ actionUrl('auth/passkey-request-options') }}`. |
| `data-warp-passkey-login-url` | The login endpoint, from `{{ actionUrl('users/login-with-passkey') }}`. |
| `data-warp-passkey-return-url` | Where a completed ceremony lands the member. Omitted, the page reloads itself. Validate it server-side: it is assigned to `window.location.href`. |
| `data-warp-passkey-csrf-field` | The name of the CSRF field to read, from `{{ craft.app.request.csrfParam }}`. The field itself, `{{ csrfInput() }}`, must sit inside the container. Omitted, no token is sent and the endpoints refuse the request. |
| `data-warp-passkey-cancelled-text` | The message shown when the member cancels the prompt or lets it time out. Omitted, nothing is announced. |
| `data-warp-passkey-failed-text` | The message shown when the ceremony fails for another reason and carries no message of its own. Omitted, nothing is announced. |
| `data-warp-passkey-fallback` | On an element that replaces the button in a browser without passkey support. Omitted, the button is hidden with nothing shown in its place. |
| `data-warp-passkey-status` | On the element a failure is written into. Give it `role="alert"` so it is announced and `tabindex="-1"` so the script can focus it. Omitted, failures are silent. |

The script hides the button and shows the fallback when the browser cannot do
passkeys or Auth Kit's script is missing, disables the button for the duration of
the ceremony, and on failure writes the message into the status element, unhides
it and moves focus to it. The container is bound once, so a second script pass
leaves it alone, and nothing here falls back to a class name or a string of its
own.
