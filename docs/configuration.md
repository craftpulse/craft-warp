# Configuration

Warp's settings live in the standard plugin settings model and are project-config
tracked, so they sync across environments. Edit them in the control panel under
**Warp** > **Settings**, or manage them in `project.yaml`. At boot, Warp pushes
the relevant values into Auth Kit's services (the token store and the recent-auth
gate), so changing a setting reconfigures the primitives underneath.

## Settings

### Login methods

| Setting | Type | Default |
|---|---|---|
| `loginMethods` | array of strings | `['magic-link', 'otp']` |

The passwordless login channels Warp offers, a subset of `magic-link` and `otp`.
A channel not listed here is refused at issuance even if a request asks for it
directly, so removing one closes it end to end. The set must be non-empty and
contain only those two values, or the settings model fails validation; an empty
set would leave the front end with no usable login path.

### Tokens and codes

| Setting | Type | Default | Range | Env var |
|---|---|---|---|---|
| `tokenTtl` | int or string (seconds) | `900` | 60 to 86400 | yes |
| `otpDigits` | int or string | `6` | 4 to 10 | yes |
| `otpMaxAttempts` | int or string | `5` | 1 to 10 | yes |
| `perEmailLimit` | int or string | `5` | 1 to 100 | yes |
| `perEmailWindow` | int or string (seconds) | `300` | 60 to 86400 | yes |

Each of these six numeric tunables (including `recentAuthDuration` below) accepts
either a literal value or an environment-variable reference such as
`$WARP_TOKEN_TTL`, entered in the control panel through an env-aware field.
Validation resolves the value first and range-checks the result, so a literal and
an env var are held to the same bounds and an env var that resolves to a
non-number (an undefined or misspelled variable) fails with a clear message.
Read the resolved value in code through the typed getters (`getTokenTtl()`,
`getOtpDigits()`, and so on), never the raw property.

- **`tokenTtl`** is how long an issued magic link or one-time code stays valid.
  Pushed onto Auth Kit's token store.
- **`otpDigits`** is the length of an issued numeric code. The
  `craft.warp.otpForm()` and `craft.warp.otpInput()` render builders (and the
  example code-entry template built on them) read it automatically, so the
  segmented input renders exactly one square per configured digit; custom
  markup can read it through `craft.warp.otpDigits`.
- **`otpMaxAttempts`** is how many wrong guesses a single code tolerates before it
  is burned. This is per-code; the controller adds a separate per-IP rate limit on
  the verify endpoint on top of it.
- **`perEmailLimit`** and **`perEmailWindow`** are the per-address issuance
  throttle: at most `perEmailLimit` tokens to one address per `perEmailWindow`
  seconds, across every channel including programmatic issuance. This is Auth
  Kit's address-level throttle; the controllers add per-IP rate limiting
  separately.

### Recent auth

| Setting | Type | Default | Range |
|---|---|---|---|
| `recentAuthDuration` | int or string (seconds) | `300` | 60 to 86400 |

How long a prior sign-in satisfies the recent-auth gate before a sensitive
action (managing passkeys, revoking sessions) requires a step-up. The step-up for
a passwordless member is simply signing in again. Pushed onto Auth Kit's passkey
service, which stamps recent-auth on every login and checks it on the
credential-changing calls.

### Registration

| Setting | Type | Default |
|---|---|---|
| `enableRegistration` | bool | `true` |
| `registrationGroupUid` | string or null | `null` |

- **`enableRegistration`** is Warp's own switch for passwordless registration from
  the unified form. It is a necessary but not sufficient condition: registration
  is open only when this **and** Craft's `users.allowPublicRegistration` are both
  on (see [Registration prerequisites](setup.md#registration-prerequisites)).
  When either is off, an unknown address is emailed nothing and the form's HTTP
  response is unchanged.
- **`registrationGroupUid`** is the UID of the user group new registrants join.
  Stored as a UID rather than a database id so the reference is project-config
  safe and stable across environments. `null` (or a UID whose group has since been
  deleted) falls back to Craft's own default user group. A dangling UID that still
  exists in the setting is rejected at save time by validation.

### Passkeys

| Setting | Type | Default |
|---|---|---|
| `enablePasskeyNudge` | bool | `true` |

Whether to show the one-time "add a passkey" nudge after a member signs in over
an email flow while holding no passkey. Surfaced once per triggering login through
`craft.warp.showPasskeyNudge`, which clears the flag as it reads it. Off means the
nudge is never flagged.

### Location awareness

| Setting | Type | Default |
|---|---|---|
| `notifyOnNewLocation` | bool | `true` |
| `anonymizeIp` | bool | `false` |

- **`notifyOnNewLocation`** is whether to email a member when they sign in from
  a country and city they have never signed in from before. Detection requires
  a geo database (see below): with none installed no location is ever resolved,
  so nothing is flagged and no alert is sent. A member's first-ever sign-in
  never counts as new, because there is no baseline to compare against. The
  alert copy is an editable system message (**Settings** > **System Messages**,
  key `warp_new_location`).
- **`anonymizeIp`** is whether stored IP addresses are anonymized: the final
  octet of an IPv4 address is zeroed (an IPv6 address keeps only its /48
  network prefix) before a login-log or session-registry row is written. The
  geo lookup runs on the full address first, so city-level location and
  new-location detection are unaffected. It applies to new rows only; rows
  already stored keep their captured address until pruned. See the
  [privacy guide](privacy.md) for when to turn it on.

## Geo database (MMDB)

Location awareness is optional and degrades silently. With no database installed,
logins record no city or country, the overview's Location column shows a muted
"Location unknown" badge, no sign-in is ever flagged as a new location, and no
alert email is sent. Installing a database lights all of that up with no further
configuration.

Warp reads a city-level MaxMind-format database (`.mmdb`) from
`storage/warp/geo/city.mmdb`. Both the flat ip-location-db record shape and the
nested MaxMind GeoIP2/GeoLite2 shape are understood, so either can be dropped in.

The supported way to populate it is the console command, which downloads the
database from a configurable URL and installs it atomically:

```sh
php craft warp/geo/refresh
```

Run it on deploy or on a schedule. The download URL is the `geoDatabaseUrl`
setting; it defaults to the openly licensed, keyless ip-location-db city database
and is not surfaced in the control panel. Point it at a different,
licence-appropriate database (for example a MaxMind GeoLite2-City URL with your
account key) by setting it in `config/warp.php`, where it also accepts an
environment-variable reference:

```php
<?php
return [
    'geoDatabaseUrl' => getenv('WARP_GEO_DATABASE_URL'),
];
```

The IP is read only to derive the coarse city and country; it is not persisted by
the geo lookup itself, and the lookup runs entirely against the local file, so no
member IP is ever sent to a third party. This is deliberately coarse location,
never fingerprinting, and the label never influences authorization. For the full
data inventory, retention windows, and lawful-basis notes, see the
[privacy guide](privacy.md).

## Template variables (`craft.warp`)

Warp exposes its whole front-end surface through the single `craft.warp` Twig
variable. Everything the example templates read is listed here; nothing else is
exposed, so a template never reaches a service or a record directly. The
data accessors are read as properties (Craft's magic getter drops the `get`
prefix); the two render builders are called as functions and terminated with
`.render()`.

### Data accessors

| Variable | Returns | What it is |
|---|---|---|
| `craft.warp.hasPasskeys` | bool | Whether the current user has any passkeys enrolled. `false` for a guest. Delegated to Auth Kit. |
| `craft.warp.passkeys` | array | The current user's enrolled passkeys, ready for a management list. Empty for a guest. Delegated to Auth Kit. |
| `craft.warp.webauthnJsUrl` | string | The published URL of the reference WebAuthn client script, for the passkey login and enrollment JS. Delegated to Auth Kit. |
| `craft.warp.registrationEnabled` | bool | Whether passwordless registration is currently open, meaning Warp's `enableRegistration` **and** Craft's `users.allowPublicRegistration` are both on. |
| `craft.warp.loginMethods` | array of strings | The enabled login channels, a subset of `magic-link` and `otp`, so a template renders only the channels the site offers. |
| `craft.warp.otpDigits` | int | The configured one-time-code length, for sizing a custom code input to match the setting instead of hardcoding it. |
| `craft.warp.requestedEmail` | string or null | The email the visitor last requested a credential for, the session-carried prefill the code-entry page reads. It is the visitor's own input echoed back, so it reveals nothing, and the verify endpoint clears it on a successful sign-in. |
| `craft.warp.sessions` | array of `SessionInfo` | The current user's active sessions for the session-management screen, the current session flagged and each other device labelled. Empty for a guest. |
| `craft.warp.showPasskeyNudge` | bool | Whether to show the one-time passkey-enrollment nudge. Reading it **clears** the flag, so the nudge surfaces exactly once per triggering sign-in. |

### Render builders

| Function | Renders |
|---|---|
| `craft.warp.otpForm({...}).render()` | The complete one-time-code verify form: the post to `warp/auth/verify-code` with CSRF, the carried email prefill (or a visible email input when none is held), the segmented code input, a hint, and the submit button. |
| `craft.warp.otpInput({...}).render()` | The segmented one-time-code input alone, for a page composing its own form around it. One paste-aware square per digit, sized to `otpDigits`, degrading to a plain input with no JavaScript. |

Both builders are chainable: every key in the options array matches a setter on
the builder, and any setter may also be called fluently before `.render()`. See
[the OTP form builder](setup.md#the-otp-form-builder) for the option list.

## Values that are not settings

Two retention windows are deliberately fixed constants rather than settings:

- **The login-log retention** is 90 days (`Logins::PRUNE_MAX_AGE_DAYS`). The
  control-panel overview is a recent-activity view, not a compliance archive, so
  rows older than that are pruned on Craft's garbage-collection pass.
- **The session-registry cleanup** rides Craft's own garbage collection: a
  registry row whose core session has vanished is swept up, and logout forgets the
  exact row synchronously. There is no separate Warp retention knob; the registry
  tracks live core sessions only.

## Control-panel permission

Warp registers one permission under a **Warp** heading:

| Permission | Handle | Grants |
|---|---|---|
| View the overview | `warp:viewOverview` | access to the Warp overview screen |

The overview subnav item and the `warp/overview` route are both gated on this
permission, so a control-panel user with the plugin section but not this
permission sees no overview and gets a 403 if they navigate to it directly. The
settings screen is separately gated on the `warp:manageSettings` permission:
who may be on the screen is a permission decision, while `allowAdminChanges`
decides only whether writes are possible. A user holding neither permission
gets no Warp nav item at all. (Admins implicitly hold every permission.)

## Read-only mode (`allowAdminChanges`)

Warp follows Craft's standard production posture. When `allowAdminChanges` is
`false` in `config/general.php`:

- The settings screen **still renders** for anyone holding
  `warp:manageSettings`, so the current configuration stays readable, but every
  field is disabled (the template is passed `readOnly = true`).
- The save action **fails closed**: it re-checks `allowAdminChanges` and throws a
  403 before writing anything, so a crafted POST cannot bypass the read-only
  screen.

The overview screen is read-only regardless and is unaffected. Verify by setting
`CRAFT_ALLOW_ADMIN_CHANGES=false` (or the config value) and visiting
`warp/settings`: the form is visibly disabled and a save is rejected.

## No password flows

Warp is passwordless by design (decision D4). Stated explicitly:

- **Members created through Warp never have a password.** Registration provisions
  an active, password-less account, the same status Craft grants after email
  verification and the precedent SSO sets for password-less active users.
- **Warp never calls password validation.** It does not invoke
  `AuthKit::$plugin->passwords->validate()`, ships no password-set or password
  reset UI, and performs no breach checks. Mailbox possession (a magic link, a
  one-time code, or a signup link) and passkeys are the only proofs it accepts.
- **Password-Policy cooperation is at the Auth Kit contract layer, not in Warp.**
  A site that adds its own password forms elsewhere (Warp does not) can integrate
  a password-strength or breach-check provider through Auth Kit's
  `PasswordValidatorInterface` and its validator registry. That cooperation
  happens entirely between the site's password form and Auth Kit; Warp is not in
  the path and needs no configuration for it.

If you need password-based login for the same members, that is a different product
surface; Warp neither provides nor blocks it, but it will never set a password on
a member's behalf.
