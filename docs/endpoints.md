# Endpoints

Warp registers nine action routes. They are the fixed half of the plugin: you
post to them, you never define them. The
[render builders](templates.md#render-builders) already post to the first three
correctly, so read this page when you are writing your own markup, posting over
`fetch`, or building a front end Warp's builders do not cover.

Every route is reached through `actionUrl()`, never a page URL:

```twig
<form method="post" action="{{ actionUrl('warp/auth/request') }}">
```

Two conventions apply throughout. **CSRF is required** on every POST, so include
`{{ csrfInput() }}`. And **`Accept: application/json` changes the response
shape**: a request that accepts JSON gets JSON, anything else gets a redirect
with a flash notice.

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
reveals which addresses are registered: `{"success": true}` with the message "If
an account matches that address, a sign-in message is on its way.", or a redirect
carrying that same message as a success flash. An unknown address, a suspended
account and a happy path are indistinguishable, and your copy on the page you
redirect to must stay that way too.

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

Success answers `{"success": true, "returnUrl": "..."}` or redirects to the
validated `returnUrl`, falling back to the site root. Failure answers
`{"success": false}` with the message "That code is invalid or has expired.
Please request a new one.", or redirects back to the referring page with that as
a fail flash. The failure is opaque: a wrong code, an unknown address and a
refused login all read the same, and the real cause is logged server-side.

Rate limited to 10 posts per IP per 60 seconds. The per-code attempt cap
(`otpMaxAttempts`) applies on top and burns the code.

### `warp/auth/verify-link`

GET, anonymous. Consumes a magic link. The member reaches it by clicking the link
in their email, so there is nothing to build: the URL is generated at issuance
with its `token` query param already on it.

| Param | Description |
|---|---|
| `token` | The single-use secret from the emailed link. |
| `returnUrl` | Carried through from the original request. |

Signs the member in and redirects to the validated `returnUrl`, or the site root.
A failed, expired or reused link redirects to Craft's `loginPath` with the fail
flash "This sign-in link is invalid or has expired. Please request a new one."

### `warp/auth/verify-registration`

GET, anonymous. Consumes a sign-up link, provisions the account and signs the new
member in. Same params, same shape and same generic failure as
`warp/auth/verify-link`.

## Passkeys

All three passkey routes require a signed-in member, require `Accept:
application/json`, and are behind the recent-auth gate: managing credentials is
sensitive, so a session older than `recentAuthDuration` gets a **403** with
`{"reauthRequired": true}` instead of doing the work. Handle that branch by
sending the member back to the sign-in page, which is the whole re-authentication
for a passwordless member.

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

Answers `{"success": true, "message": "Passkey created."}` or
`{"success": false, "message": "..."}`.

### `warp/passkeys/delete`

POST. Deletes one of the current member's passkeys.

| Param | Description |
|---|---|
| `uid` | Required. The `uid` of the passkey, from `craft.warp.passkeys`. |

Answers `{"success": true, "message": "Passkey deleted."}`. Deletion is scoped to
the signed-in member, so a `uid` belonging to another account cannot be reached.

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

Answers `{"success": true}`, or `{"success": false}` with "That session could not
be found." A plain form post redirects back to the referring page with the
message as a flash. Revocation is scoped to the signed-in member.

### `warp/sessions/revoke-others`

POST. Takes no params. Signs out every session the member holds except the one
making the request, and answers `{"success": true, "count": 3}`.

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
