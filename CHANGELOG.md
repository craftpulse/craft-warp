# Release Notes for Warp

## 5.0.0 - Unreleased

> Initial release.

### Added
- Passwordless login for front-end members over two channels, built on Auth
  Kit's token store: a single-use magic link and a short, attempt-capped email
  one-time code. The unified request endpoint (`warp/auth/request`) is anonymous,
  per-IP rate-limited, and enumeration-safe: it responds byte-identically whether
  or not an account matches, so it never reveals which addresses are registered.
- Magic-link and one-time-code verify endpoints (`warp/auth/verify-link`,
  `warp/auth/verify-code`) that consume the credential, log the member in for
  Craft's configured session duration, and redirect to a same-site `returnUrl`.
  Every failure collapses to a single generic message with the cause logged
  server-side only.
- Passwordless-first registration from the same email form. An unknown address is
  emailed a signup link (when registration is open); verifying it creates an
  active, password-less account, sets the username to the email, and assigns the
  configured user group. Registration requires both Warp's `enableRegistration`
  setting and Craft's `users.allowPublicRegistration`; when either is off the form
  degrades to login-only with no change to its response. A pending account is
  activated in place, an active account is signed straight in, and a suspended,
  locked, or deactivated account fails closed, so a stale token can never revive a
  blocked account.
- Front-end passkey management (`warp/passkeys/creation-options`,
  `verify-creation`, `delete`): a logged-in member enrolls, names, and deletes
  their own WebAuthn credentials over JSON. The credential-changing actions are
  recent-auth gated and answer a `reauthRequired` envelope when the window has
  lapsed, the passwordless replacement for an elevated session. Passkey login runs
  through core's own anonymous endpoints, for which Warp ships the front-end JS
  and templates.
- A show-once passkey-enrollment nudge, flagged after an email-flow login by a
  member holding no passkey and surfaced once through
  `craft.warp.showPasskeyNudge`. Toggled by the `enablePasskeyNudge` setting.
- Session and device management (`warp/sessions/revoke`,
  `warp/sessions/revoke-others`): a lean Warp-owned registry captures a hashed
  session token plus a truncated user-agent and IP on login, joins core's live
  session rows for a friendly device label, and deletes the authoritative core
  session row on revoke. Every delete is scoped to the requesting member, so a
  posted uid can never reach another account's session; both actions are
  recent-auth gated. Sessions with no registry match render as "Unknown device"
  and remain revocable through "sign out everywhere else".
- A control-panel overview screen (gated on the `warp:viewOverview` permission)
  summarizing recent passwordless sign-ins, passkey adoption, and the current
  registration and login-method posture.
- A control-panel settings section inside Warp's own nav (not the global plugin
  settings screen), covering login methods, token and code tunables, registration
  with a user-group picker, and the passkey nudge. Admin-gated, read-only under
  `allowAdminChanges: false` with a fail-closed save, and project-config tracked.
- A `craft.warp` Twig variable exposing the front-end surface: `hasPasskeys`,
  `passkeys`, `webauthnJsUrl` (delegated to Auth Kit), `registrationEnabled`,
  `loginMethods`, `otpDigits`, `sessions`, and `showPasskeyNudge`.
- An append-only passwordless login log behind the overview, recorded on every
  Warp login path (magic link, code, registration) and on core's passkey-login
  endpoint, pruned after 90 days on Craft's garbage-collection pass.
- Copy-ready front-end templates in `examples/front-end/`: login/signup,
  "check your email", code entry, the account landing, passkey management, and
  session management, with a `warpBase` include-prefix convention, low-specificity
  `warp-` class hooks, and an accessibility contract (labelled inputs, live
  regions, focus-to-error, no color-only state).
- Same-site `returnUrl` validation on every redirect, blocking open-redirect
  attempts, and a coarse user-agent-to-label helper for the sessions screen
  (display only, no fingerprinting).
</content>
