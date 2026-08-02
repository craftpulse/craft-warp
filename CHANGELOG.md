# Release Notes for Warp

## 5.0.0-beta.3 - 2026-08-02

### Changed
- Warp's two control-panel permission handles are now kebab-case:
  `warp:viewOverview` is now `warp:view-overview`, and `warp:manageSettings` is
  now `warp:manage-settings`. Craft lowercases a permission name both when it
  stores it and when it checks it, so the old names read as `warp:viewoverview`
  and `warp:managesettings` in the database; the kebab names keep their word
  boundaries there and in exports. A migration carries every existing grant over
  automatically, for users, for user groups, and in the project-config group
  permission lists, so nobody loses access. Craft's own permissions are
  untouched. If you reference either handle in your own templates or code (for
  example `currentUser.can('warp:viewOverview')`), update it to the new name.

### Fixed
- Login-log and session timestamps are now read from the database as the UTC
  values they are stored as. They were previously parsed in the install's
  system timezone, so "when" on the control-panel overview and "last active"
  on the sessions screen were shifted by the full UTC offset on any
  non-UTC install.
- A passkey login through core's endpoint, and a session revocation, no longer
  fail when the Auth Kit plugin is disabled. Both flows run entirely on core's
  own machinery, but their audit recording dereferenced Auth Kit
  unconditionally; the recording is now skipped when Auth Kit is unavailable,
  matching the plugin's loud-but-never-fatal posture for a missing Auth Kit.
- The overview table's column labels, search placeholder, and empty-state
  message are now registered for JavaScript translation, so they render
  translated on non-English control panels instead of falling back to the raw
  English strings.

### Security
- Fulfilling a registration token now fails closed unless the matched
  account's email address is the address the token proved. The lookup
  previously also matched by username, and a username is free-form text
  another member can set to someone else's email address, so a registration
  link for one mailbox could resolve to, and sign its holder into, a
  different member's account. An account matched by username only is now
  refused and the attempt is logged.
- The example login template now validates the `returnUrl` query parameter
  before the passkey script assigns it to `window.location.href`, accepting
  only a site-relative path and falling back to the account page otherwise,
  and the value is JavaScript-encoded at the sink. The posted email flows
  were already validated server-side; this closes the client-side redirect
  the passkey branch performed on its own, which could previously be pointed
  at an external URL or a `javascript:` target. Re-copy the example templates
  (`php craft warp/example-templates`) if you built on the bundled
  `login.twig`.

## 5.0.0-beta.2 - 2026-07-27

### Changed
- Requires Auth Kit 1.4.0 or later.
- Warp no longer mutates Auth Kit's shared service state. Verify routes,
  token lifetimes, OTP digits and attempt caps, per-address throttles, and
  the recent-auth window ride each issuance or check as per-call options,
  and every Warp-issued token carries the `warp` origin: it can only verify
  at Warp's own endpoints, and Warp's endpoints only honor Warp-issued
  tokens. On a site running another Auth Kit consumer (e.g. Warden), the two
  products' passwordless flows are now fully isolated.
- The `passkey.deleted` audit event is now recorded only when a credential was
  actually removed. Deleting an unknown UID still responds identically (no
  credential-existence oracle), but no longer fabricates a deletion in the
  audit trail, using Auth Kit 1.3.0's boolean `deletePasskey()` return.

## 5.0.0-beta.1 - 2026-07-13

> Initial beta release.

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
  session token plus a truncated user-agent, IP, and (with a geo database) city
  and country on login, joins core's live session rows for a friendly device
  label, and deletes the authoritative core session row on revoke. Every delete is
  scoped to the requesting member, so a posted uid can never reach another
  account's session. The member-facing screen renders each session as a device
  card with a device-type icon, a "Current device" pill, and expandable
  last-active, IP, and location detail. Sessions with no registry match render as
  "Unknown device" and remain revocable through "sign out everywhere else".
- A control-panel overview screen (gated on the `warp:viewOverview` permission)
  leading with posture stat tiles (registration, enabled login methods, passkey
  adoption) above a paginated, searchable table of recent passwordless sign-ins
  (VueAdminTable in API mode, newest first, searchable by user email or username).
- A tabbed control-panel settings section inside Warp's own nav (not the global
  plugin settings screen): Login methods (two independent lightswitches, the
  passkey nudge, the new-location alert, and IP anonymization), Tokens & codes (the numeric tunables,
  each accepting a literal or an environment-variable reference), and Registration
  (the enable switch and a user-group picker). Deeper field guidance rides in the
  hover info popover Craft renders from an `info` span. Gated on the
  `warp:manageSettings` permission, read-only under `allowAdminChanges: false` with
  a fail-closed save, and project-config tracked.
- Optional location awareness backed by a city MMDB read from
  `storage/warp/geo/city.mmdb` (both the ip-location-db and MaxMind record shapes
  are understood, populated by the `warp/geo/refresh` console command from a
  `geoDatabaseUrl` set in `config/warp.php`). Each login resolves a coarse city and
  country; a sign-in from a country and city the member has never used before is
  flagged (`isNewLocation`) and, when `notifyOnNewLocation` is on, emailed to the
  member through the editable `warp_new_location` system message. A member's
  first-ever login is never new, and everything degrades silently with no
  database present.
- An optional `anonymizeIp` setting (default off) that zeroes the final octet of
  each IPv4 address (an IPv6 address keeps only its /48 prefix) before a
  login-log or session-registry row is written. The geo lookup runs on the full
  address first, so city-level location and new-location detection are
  unaffected. Documented, along with the data inventory, retention windows, and
  GDPR lawful-basis notes, in `docs/privacy.md`.
- A `craft.warp` Twig variable exposing the front-end surface: `hasPasskeys`,
  `passkeys`, `webauthnJsUrl` (delegated to Auth Kit), `registrationEnabled`,
  `loginMethods`, `otpDigits`, `requestedEmail` (the session-carried code-entry
  prefill), `sessions`, and `showPasskeyNudge`.
- Fluent OTP render builders in the Password Policy builder shape:
  `craft.warp.otpForm({...}).render()` outputs the complete code-verify form
  (post to `warp/auth/verify-code` with CSRF, the carried email prefill or a
  visible email input, the segmented code input, hint, and submit), and
  `craft.warp.otpInput({...}).render()` the segmented input alone. The input
  renders one paste-aware square per digit, sized to the `otpDigits` setting
  (auto-advance, backspace, arrow keys, paste distribution), served by an
  auto-registered vanilla JS and neutral CSS asset, and degrades to a plain
  input with no JavaScript so the form always submits.
- A `warp/example-templates` console command (Commerce-style) that copies the
  example member area into the project's `templates/` directory, prompting for
  a folder name and rewriting the bundle's internal `members/...` references
  when a different name is chosen; `--folder-name` and `--overwrite` cover
  scripted setups.
- An append-only passwordless login log behind the overview, recorded on every
  Warp login path (magic link, code, registration) and on core's passkey-login
  endpoint, pruned after 90 days on Craft's garbage-collection pass.
- Audit-event emission through Auth Kit 1.2.0's neutral audit contract
  (`AuthKit::$plugin->getAudit()->record()`, emitter `warp`), success paths only,
  never edition-gated, a no-op with no sink registered: `login.magic_link` /
  `login.otp` on an email-flow login, `login.passkey` on a passkey-route login,
  `registration.fulfilled` on a signup (emitted alone, since it implies the login;
  no `login.*` event is recorded alongside it, so one signup is one audit fact),
  `passkey.enrolled` / `passkey.deleted` on credential management, and
  `session.revoked` with `scope` `single` or `others` on a genuine revocation.
- Copy-ready front-end templates in `example-templates/members/`, following the
  Craft Commerce example-templates model: login/signup, "check your email",
  code entry, the account landing, passkey management, and session management,
  each a full page extending the bundle's own `_private/layouts` HTML shell
  (skip link, nav, flash notices), styled with Tailwind CSS from a CDN, and
  carrying an accessibility contract (labelled inputs, live regions,
  focus-to-error, no color-only state).
- Same-site `returnUrl` validation on every redirect, blocking open-redirect
  attempts, and a coarse user-agent-to-label helper for the sessions screen
  (display only, no fingerprinting).
