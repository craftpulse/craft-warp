# Endpoints

Warp registers ten action routes. They are the fixed half of the plugin: you
post to them, you never define them. The
[render builders](templates.md#render-builders) already post to the first three
correctly, so read this page when you are writing your own markup, posting over
`fetch`, or building a front end Warp's builders do not cover.

Every route is reached through `actionUrl()`, never a page URL:

```twig
<form method="post" action="{{ actionUrl('warp/auth/request') }}">
```

Three conventions apply throughout. **CSRF is required** on every POST, so
include `{{ csrfInput() }}`. **`Accept: application/json` changes the response
shape**: a request that accepts JSON gets JSON, anything else gets a redirect
with a flash notice. And **the HTTP status carries the outcome**, following
Craft's own controller conventions: success is a 200 whose body is
`{"message": "..."}` plus any route-specific keys, failure is a 400 with
`{"message": "..."}`, and exceeding a per-IP rate limit is a 429. Read the
status code, not a body flag; no route except
[`warp/nudge/dismiss`](#warpnudgedismiss) sends a `success` key.

## Sign-in and sign-up

### `warp/auth/request`

POST, anonymous. Requests a sign-in credential for an email address, or a sign-up
link when registration is open. This is the form every visitor starts at, and
`craft.warp.requestForm()` renders it.

| Param | Description |
|---|---|
| `email` | The address to send to. Required in practice: an empty address matches no account and sends nothing. |
| `channel` | `magic-link` or `otp`. Defaults to the first enabled login method. A channel the site has disabled is refused. |
| `returnUrl` | Where the emailed credential lands the member once they use it. Validated before it is honoured, see [return URLs](#return-urls). |
| `redirect` | Craft's own hashed redirect, from `{{ redirectInput('members/otp-verify') }}`, deciding where the browser goes after posting. |

The response is deliberately identical in every branch, so the endpoint never
reveals which addresses are registered:
`{"message": "If an account matches that address, a sign-in message is on its way."}`,
or a redirect carrying that same message as a success flash. An unknown address,
a suspended account and a happy path are indistinguishable, and your copy on the
page you redirect to must stay that way too.

Rate limited to 5 posts per IP per 60 seconds, on top of the per-address
`perEmailLimit` throttle.

### `warp/auth/verify-code`

POST, anonymous. Consumes a one-time code and signs the member in.
`craft.warp.otpForm()` renders this form.

| Param | Description |
|---|---|
| `email` | The address the code was issued to. |
| `code` | The code the member typed. |
| `returnUrl` | Where a verified code lands the member. Validated, see [return URLs](#return-urls). |

This action reads `returnUrl` and **not** Craft's hashed `redirect`. A
`{{ redirectInput('members/account') }}` in a hand-written code form is ignored,
and the member lands on the site root instead. The asymmetry with
`warp/auth/request`, which does honour Craft's `redirect`, is deliberate: the
request form's redirect is what the channel swap rewrites to route a magic-link
post and a code post to different pages, while a verified code goes straight to
the destination the credential was issued for.

Success answers `{"returnUrl": "...", "message": "Signed in."}` or redirects to
the validated `returnUrl`, falling back to the site root. Failure answers a 400
with `{"message": "That code is invalid or has expired. Please request a new one."}`,
or redirects back to the referring page with that as a fail flash. The failure
is opaque: a wrong code, an unknown address and a refused login all read the
same, and the real cause is logged server-side.

Rate limited to 10 posts per IP per 60 seconds. The per-code attempt cap
(`otpMaxAttempts`) applies on top and burns the code.

### `warp/auth/verify-link`

GET, anonymous. Consumes a magic link. The normal path is the member clicking
the link in their email, which already carries the right query param. If you
are building this URL yourself, for a test or a custom verify route, the
param is `mlToken`, not `token`: Craft's web application reserves `token` for
its own routed tokens and answers a 400 to any request naming it with a value
Craft did not issue. A hand-built URL using `token` never reaches this
controller at all, so you get Craft's rejection instead of Warp's "invalid or
expired" flash.

| Param | Description |
|---|---|
| `mlToken` | The single-use secret from the emailed link. Not `token`, see above. |
| `returnUrl` | Carried through from the original request. |

Signs the member in and redirects to the validated `returnUrl`, or the site root.
A failed, expired or reused link redirects to Craft's `loginPath` with the fail
flash "This sign-in link is invalid or has expired. Please request a new one."

### `warp/auth/verify-registration`

GET, anonymous. Consumes a sign-up link, provisions the account and signs the new
member in. Same params, including `mlToken` rather than `token`, same shape and
same generic failure as `warp/auth/verify-link`.

## Passkeys

All three passkey routes require a signed-in member, require `Accept:
application/json`, and are behind the recent-auth gate: managing credentials is
sensitive, so a session older than `recentAuthDuration` gets a **403** with
`{"reauthRequired": true, "message": "Please sign in again to manage your passkeys."}`
instead of doing the work. Handle that branch by sending the member back to the
sign-in page, which is the whole re-authentication for a passwordless member.

The browser-side ceremony is Auth Kit's reference client, published at
`craft.warp.webauthnJsUrl`. The example `account/passkeys.twig` page shows the
full exchange.

### `warp/passkeys/creation-options`

POST. Takes no params. Answers `{"options": {...}}`, the WebAuthn creation
options to hand to `navigator.credentials.create()`.

### `warp/passkeys/verify-creation`

POST. Verifies the browser's response and stores the credential.

| Param | Description |
|---|---|
| `credentials` | Required. The JSON-encoded credential the browser produced. |
| `credentialName` | The member's name for this passkey. An empty name is refused with "Please name this passkey." |

Answers `{"message": "Passkey created."}`, or a 400 with
`{"message": "Please name this passkey."}` for a missing name and
`{"message": "Passkey creation failed."}` when the browser's response does not
verify.

### `warp/passkeys/delete`

POST. Deletes one of the current member's passkeys.

| Param | Description |
|---|---|
| `uid` | Required. The `uid` of the passkey, from `craft.warp.passkeys`. |

Answers `{"message": "Passkey deleted."}` whether or not the `uid` matched one
of the member's passkeys. Deletion is scoped to the signed-in member and an
unknown `uid` is a silent no-op, so another account's passkeys can neither be
deleted nor probed for.

## Sessions

Both session routes require a signed-in member and are **not** recent-auth gated.
Signing a device out is defensive and reversible, so a member who spots something
suspicious can act immediately however old their own session is. Credential
management keeps the step-up; cleanup does not.

### `warp/sessions/revoke`

POST. Signs out one of the current member's sessions.

| Param | Description |
|---|---|
| `uid` | Required. The `uid` of the session, from `craft.warp.sessions`. A session whose `uid` is `null` cannot be targeted individually. |

Answers `{"message": "Signed out of that session."}`, or a 400 with
`{"message": "That session could not be found."}`. A plain form post redirects
back to the referring page with the message as a flash. Revocation is scoped to
the signed-in member.

### `warp/sessions/revoke-others`

POST. Takes no params. Signs out every session the member holds except the one
making the request, and answers
`{"count": 3, "message": "Signed out of your other sessions."}`, where `count`
is how many sessions were ended.

## The passkey nudge

### `warp/nudge/dismiss`

POST. Takes no params. Requires a signed-in member and is **not** recent-auth
gated: declining a suggestion is not a credential change, so it must never demand
a fresh sign-in.

Clears the passkey-enrollment nudge for the rest of the session, so
`craft.warp.showPasskeyNudge` reads false until the member's next passwordless
sign-in. Answers `{"success": true}`; a plain form post redirects back to the
posted `redirect` (or the referring page) and sets no flash, since the nudge
disappearing is the feedback. It is safe to post when no nudge is flagged.

## Return URLs

`returnUrl` decides where a member lands after a credential is used, so Warp
validates it before honouring it and drops anything it does not trust, falling
back to the site root.

Accepted: site-relative paths such as `/members/account`, and absolute URLs that
sit under the base URL of the site the request was made against. Rejected:
protocol-relative URLs, backslash tricks, control characters, and any host that
is not that site. Prefix tricks are covered too, so a site at
`https://example.test` does not accept `https://example.test.attacker.test`.

Validation is scoped to **one site**, not to the install. On a multi-site install
a `returnUrl` pointing at another site is refused exactly like any other untrusted
value, and the member lands on the fallback instead: sessions are per cookie
domain, so a member sent to a site on another domain would have arrived signed
out anyway.

Which site a URL belongs to is resolved the way Craft resolves the site of an
incoming request: every site's base URL is matched and the longest match wins. So
two sites sharing a host and differing only by path prefix each own their own
URLs. With `https://example.test/` and `https://example.test/fr/` configured, a
request served by the first accepts `/members/account` and refuses
`/fr/members/account`, and a request served by the second does the opposite. A
base URL set through an environment variable or an alias is compared in its
resolved form, so `$PRIMARY_SITE_URL` and `@web` behave like the URL they expand
to.

`returnUrl` is separate from Craft's own `redirect` param. `redirect` is hashed
and decides where the browser goes when the form posts; `returnUrl` is plain and
decides where the emailed credential lands. A request form can post both.
`warp/auth/request` is the only route that reads `redirect`, so
[`warp/auth/verify-code`](#warpauthverify-code) needs `returnUrl` and ignores
anything `redirectInput()` writes.
