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

| Setting | Type | Default | Range |
|---|---|---|---|
| `tokenTtl` | int (seconds) | `900` | 60 to 86400 |
| `otpDigits` | int | `6` | 4 to 10 |
| `otpMaxAttempts` | int | `5` | 1 to 10 |
| `perEmailLimit` | int | `5` | 1 to 100 |
| `perEmailWindow` | int (seconds) | `300` | 1 to 100 for the limit, 60 to 86400 for the window |

- **`tokenTtl`** is how long an issued magic link or one-time code stays valid.
  Pushed onto Auth Kit's token store.
- **`otpDigits`** is the length of an issued numeric code. The example code-entry
  template reads this through `craft.warp.otpDigits`, so its input length and copy
  track the setting automatically.
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
| `recentAuthDuration` | int (seconds) | `300` | 60 to 86400 |

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
</content>
