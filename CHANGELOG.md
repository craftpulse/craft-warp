# Release Notes for Warp

## 5.0.1 - 2026-08-07

> [!WARNING]
> Run `php craft up` after updating. Warp's session registry now lives in Auth Kit's shared `authkit_sessions` table, and a migration copies every existing row across before dropping `warp_sessions`. Code that queried `warp_sessions` directly should read `craftpulse\warp\db\Table::SESSIONS`, which now resolves to the shared table.

- Fixed a bug where `warp/geo/refresh` could never install a database, because the default `geoDatabaseUrl` pointed at a package that does not exist. An install still carrying that URL now resolves to a working MaxMind GeoLite2 city database, and any other URL is used as set.
- The session registry, IP geolocation, and new-location alerting now live in Auth Kit, so a site running Warp alongside another CraftPulse plugin that records sign-ins keeps one registry and sends one new-location alert instead of two. Warp's template variables, endpoints, settings, events, and console commands are unchanged.
- Auth Kit 1.10.0 or later is now required.
- The geolocation database is MaxMind GeoLite2, used under the GeoLite2 End User License Agreement. Sites must credit MaxMind and refresh the database at least every 30 days, replacing the copy they hold. `warp/geo/refresh` overwrites in place, so a monthly scheduled run satisfies both obligations.

## 5.0.0 - 2026-08-05

- Initial stable release.

## 5.0.0-rc.3 - 2026-08-04

> [!WARNING]
> Hand-written code-input markup that relied on `warp-otp.js` supplying its own class names must now name them itself: add `data-warp-otp-boxes`, `data-warp-otp-box`, and `data-warp-otp-enhanced-class`, as documented in the template reference. Markup built by `craft.warp.otpInput()` or `craft.warp.otpForm()` is unaffected.

- Removed the class names `warp-otp.js` fell back to when markup omitted `data-warp-otp-boxes`, `data-warp-otp-box`, or `data-warp-otp-enhanced-class`, so the script now carries no class name of its own and markup that omits an attribute gets the behavior without it: bare elements, and an original input that stays visible beside the boxes.
- Auth Kit 1.8.0 or later is now required.
- The sign-in link, one-time code, and sign-up emails now state exactly how long their credential lasts ("It expires in 15 minutes and can be used only once") instead of saying it expires shortly, following Warp's own `tokenTtl` setting. That copy belongs to Auth Kit's system messages, hence the new version floor; a body you have already rewritten keeps your copy and can quote the expiry with the new `{{ expiresIn }}` variable.
- Added `craft.warp.tokenLifetime`, which returns how long an issued magic link or one-time code stays valid, formatted for reading ("15 minutes", "1 hour"), so a page can state the exact expiry.
- The example "check your email" page now states the exact expiry rather than saying the link expires shortly. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- The passkey nudge now holds for the rest of the session instead of vanishing the first time it is read, so a page reload or a form post that lands the member back on the account page no longer consumes it.
- Added the `warp/nudge/dismiss` endpoint, which clears the passkey nudge for the rest of the session for a member who answers "Not now".
- `craft.warp.showPasskeyNudge` now also returns false as soon as the member holds a passkey, so enrolling one takes the nudge away without waiting for their next sign-in.
- The example passkey nudge's "Not now" control is now a real form posting to `warp/nudge/dismiss`, so the dismissal survives a reload and works with no JavaScript. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- The example code-entry page now sends a visitor with no session-carried address to the sign-in page, instead of rendering an unexplained email field under "we emailed you a short numeric code". `craft.warp.otpForm()` keeps its email-field variant for custom templates. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- The example sign-in and code-entry pages now pass `max-w-none!` to their email input, so it matches the full-width submit button instead of stopping at Warp's 24rem baseline cap. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- Documented that Warp's baseline caps the email inputs at 24rem, that a `w-full`-style width utility does not lift a max-width cap, and that lifting it from inside your own cascade layer takes the framework's important modifier (Tailwind 4's trailing `!`) or an un-layered rule.
- Corrected the claim that a cascade layer of yours declared after `warp` reliably wins: layer order is the final document's declaration order, which a framework injecting its stylesheet at runtime (Tailwind 4's Play CDN) does not guarantee, and in practice `warp` often lands last. Un-layered rules and important declarations are the two things that hold whatever the order.
- Documented every property Warp's baseline sets, per element, so you can tell whether a class of yours competes with Warp or lands on a property Warp leaves alone.
- Added the `craft.warp.passkeyButton()` render builder, which renders the passkey sign-in section: the button that runs the WebAuthn ceremony, the line that replaces it in a browser without passkey support, the live region a failure is announced in, and the CSRF field the ceremony needs. It loads both scripts the ceremony runs on, so a page no longer needs a script tag or an inline handler of its own.
- Added `attrs`, `buttonAttrs`, `cancelledText`, `failedText`, `fallbackAttrs`, `fallbackText`, `label`, `renderCss`, `returnUrl`, and `statusAttrs` options to `craft.warp.passkeyButton()`, so every element it emits and every string it renders is overridable.
- `craft.warp.passkeyButton()` validates its `returnUrl` the way the endpoints validate a posted one, because the client script assigns it to `window.location.href`, and logs a warning and lands on the site root when it is refused.
- Warp's baseline stylesheet now carries a neutral baseline for the passkey section, with the button, the fallback line, and the status message in the `warp` cascade layer and only the rule keeping those elements hidden outside it.
- The example login template now renders its passkey section through `craft.warp.passkeyButton()`, passing its own classes into the per-element options, and carries no inline script. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- `returnUrl` validation is now scoped to the site the request was made against rather than to the install, so a destination on another site of a multi-site install is refused like any other untrusted value and the member lands on the fallback instead. Which site a URL belongs to is resolved the way Craft resolves the site of an incoming request, so two sites sharing a host and differing only by path prefix each own their own URLs.

## 5.0.0-rc.2 - 2026-08-04

- Fixed a bug where the digit boxes on the example code-entry page rendered as invisible white squares, as the bundle's CSS reset zeroes `border-width` on every element, which beats the border Warp's layered baseline gives them, and the template named a border colour without a width. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- The example templates now load Tailwind CSS v4 from its Play CDN instead of the obsolete Tailwind CSS 2 build, and their utility classes have been updated for v4. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- Documented the asset-bundle registration a page needs to load `warp-otp.js` when it carries the segmented code input as hand-written markup, with no render builder on the page to load it.
- Documented that `warp/auth/verify-code` reads a plain, validated `returnUrl` body param and never Craft's hashed `redirect`, so a `redirectInput()` written into a hand-written code form is ignored and the member lands on the site root.
- Documented that a site reset zeroing `border-width`, such as Tailwind's preflight, beats Warp's layered baseline and strips the digit boxes' borders, and that the fix is to give the boxes a border explicitly.

## 5.0.0-rc.1 - 2026-08-03

### Authentication
- Added the `craft.warp.requestForm()` render builder, which renders the unified sign-in and sign-up form: the post to `warp/auth/request` with CSRF, the labelled email input, the channel choice when both login methods are enabled, and the submit button.
- Added `attrs`, `channel`, `channelsAttrs`, `choiceAttrs`, `email`, `emailAttrs`, `emailLabel`, `fieldAttrs`, `labelAttrs`, `legend`, `legendAttrs`, `linkSentUrl`, `magicLinkLabel`, `otpLabel`, `otpVerifyUrl`, `radioAttrs`, `renderCss`, `returnUrl`, `submitAttrs`, and `submitLabel` options to `craft.warp.requestForm()`.
- Added `boxAttrs`, `boxesAttrs`, `changeAttrs`, `changeLabel`, `digitLabel`, `emailAttrs`, `emailLabel`, `enhancedClass`, `fieldAttrs`, `hint`, `hintAttrs`, `inputAttrs`, `label`, `labelAttrs`, `renderCss`, `sentAttrs`, and `sentText` options to `craft.warp.otpForm()`, so every element it emits and every string it renders is now overridable.
- Added `boxAttrs`, `boxesAttrs`, `digitLabel`, `enhancedClass`, and `renderCss` options to `craft.warp.otpInput()`.
- Added a `renderCss` setting, which stops the render builders from registering Warp's baseline stylesheet. Set it in `config/warp.php`, or per render with `renderCss: false`.
- The example login and code-entry templates now render their forms through `craft.warp.requestForm()` and `craft.warp.otpForm()`, passing their own classes into the per-element options. Re-copy the example templates with `php craft warp/example-templates` to pick this up.
- A `class` passed to a builder's `attrs` or `inputAttrs` option is now added to Warp's own class on that element instead of replacing it, and a `data` array is merged key by key. Pass `resetClass: true` in the same array to own an element's `class` outright.
- Warp's baseline stylesheet now keeps its cosmetic rules in a `warp` cascade layer, so an ordinary rule in a site's own stylesheet wins over Warp's without `!important` and regardless of the fact that Craft injects plugin stylesheets last in `<head>`.
- The digit boxes the one-time-code script builds now take their attributes from the `boxAttrs` and `boxesAttrs` options rather than from class names fixed inside the script.
- Warp now logs a warning when a builder is given a `digits` value that disagrees with the resolved `otpDigits` setting, or a `channel` that is not an enabled login method, since the server follows the settings either way.
- The "Registration user group" setting's fallback option is now labelled "Default User Group", and its instructions name the group Craft is configured to fall back to, or say that new registrants join no group at all when Craft has none configured.

### Documentation
- Added an endpoints reference documenting all nine action routes: their parameters, authentication level, response shapes, and rate limits.
- Added the return shapes of `craft.warp.sessions` and `craft.warp.passkeys` to the template reference, including that a session with a `null` `uid` cannot be signed out individually.
- Added the DOM contracts `warp-otp.js` and `warp-request.js` read, so a hand-written template can carry the segmented code input and the channel-aware redirect.
- Fixed the documented claim that both session revoke endpoints are recent-auth gated and answer `reauthRequired`. Neither is, deliberately: the step-up covers passkey management, where the action is destructive, and not session cleanup.
- Fixed the documented claim that `returnUrl` validation is scoped to the issuing site. It is scoped to the install, so a `returnUrl` under any configured site's base URL is honoured.
- Fixed the restyling instructions, which named a `warp-otp__*` glob that missed the code input's own `warp-otp` class.
- Removed the upgrading page.

## 5.0.0-beta.5 - 2026-08-02

> [!WARNING]
> Sites that updated to 5.0.0-beta.3 or 5.0.0-beta.4 were never prompted for their database update, so the permission rename and the Auth Kit module adoption may still be unapplied. Run `php craft up` after deploying this release.

- Fixed a bug where Warp's schema version wasn't raised by the migrations 5.0.0-beta.3 and 5.0.0-beta.4 added, so Craft never reported a pending database update and those migrations stayed unapplied. Sites that already ran `craft up` or `migrate/all` after updating are unaffected, and this release will find nothing left to do on them.
- Fixed a bug where re-running Warp's install migration against a database that still held Warp's tables could accumulate duplicate indexes and foreign keys, as the migration didn't skip the ones already present.
- Fixed a bug where installing Warp on a site that previously ran Auth Kit as a plugin left the stale Auth Kit plugin registration behind, since Craft marks dated migrations as applied without running them on a fresh install and the upgrade migration that clears the registration never got the chance. Auth Kit's tables and the tokens in them are untouched.

## 5.0.0-beta.4 - 2026-08-02

> [!WARNING]
> Auth Kit is no longer a separate plugin. Deploy the update and run your migrations as usual: Warp converts an existing Auth Kit install in place, and there is no step to perform by hand.

- Auth Kit 1.7.0 or later is now required.
- Auth Kit is no longer a plugin to install, and now ships as a library that Composer pulls in with Warp, so it no longer appears in the plugins list, in project config, or in `plugin/install` and `plugin/uninstall`.
- Every passwordless feature behaves exactly as before, and no token, passkey, or setting is affected.
- Warp's migrations convert an existing Auth Kit install in place, moving its migration history to the new track and clearing the obsolete plugin entry out of the plugins table and project config, while leaving its tables untouched.
- On a site running Warden as well, whichever plugin migrates first performs the conversion and the other finds nothing left to do.
- Removed the control panel alert and error-log entry warning that Auth Kit was missing or disabled, as Auth Kit now arrives as a library that can't be disabled or uninstalled on its own.

## 5.0.0-beta.3 - 2026-08-02

- Renamed the `warp:viewOverview` permission to `warp:view-overview`, and the `warp:manageSettings` permission to `warp:manage-settings`. Existing grants are carried over automatically for users, for user groups, and in the project-config group permission lists, but any reference in your own templates or code, such as `currentUser.can('warp:viewOverview')`, needs updating.
- Fixed a bug where login-log and session timestamps were parsed in the install's system time zone rather than read as the UTC values they're stored as, so "when" on the control panel overview and "last active" on the sessions screen were shifted by the full UTC offset on any non-UTC install.
- Fixed an error that occurred when a passkey sign-in through Craft's own endpoint, or a session revocation, ran on a site with the Auth Kit plugin disabled, as the audit recording dereferenced Auth Kit unconditionally.
- Fixed a bug where the overview table's column labels, search placeholder, and empty-state message rendered as raw English strings on non-English control panels, as they weren't registered for JavaScript translation.
- Fixed a bug where fulfilling a registration token also matched an account by username, so a registration link for one mailbox could resolve to, and sign its holder into, the account of another member whose username was set to that address. An account matched by username only is now refused and the attempt is logged.
- Fixed a bug where the example login template assigned an unvalidated `returnUrl` query parameter to `window.location.href` in the passkey script, so the client-side redirect could be pointed at an external URL or a `javascript:` target. Re-copy the example templates with `php craft warp/example-templates` if you built on the bundled `login.twig`.

## 5.0.0-beta.2 - 2026-07-27

- Auth Kit 1.4.0 or later is now required.
- Warp no longer mutates Auth Kit's shared service state, and now passes verify routes, token lifetimes, OTP digits and attempt caps, per-address throttles, and the recent-auth window as per-call options on each issuance or check.
- Every Warp-issued token now carries the `warp` origin, so it can only verify at Warp's own endpoints and Warp's endpoints only honor Warp-issued tokens, isolating Warp's passwordless flows from those of any other Auth Kit consumer on the same site.
- The `passkey.deleted` audit event is now recorded only when a credential was actually removed, rather than on every accepted delete, and deleting an unknown UID still responds identically so no credential-existence oracle is introduced.

## 5.0.0-beta.1 - 2026-07-13

### Authentication
- Added passwordless sign-in for front-end members over two channels, built on Auth Kit's token store: a single-use magic link, and a short, attempt-capped email one-time code.
- Added the `warp/auth/request` endpoint, which is anonymous, per-IP rate-limited, and responds byte-identically whether or not an account matches, so it never reveals which addresses are registered.
- Added the `warp/auth/verify-link` and `warp/auth/verify-code` endpoints, which consume the credential, sign the member in for Craft's configured session duration, and redirect to a same-site `returnUrl`.
- Every sign-in failure collapses to a single generic message, with the cause logged server-side only.
- Added passwordless-first registration from the same email form, where an unknown address is emailed a signup link and verifying it creates an active, password-less account with the email as the username and the configured user group assigned.
- Registration requires both Warp's `enableRegistration` setting and Craft's `users.allowPublicRegistration`, and the form degrades to sign-in only, with no change to its response, when either is off.
- A pending account is activated in place and an active account is signed straight in, while a suspended, locked, or deactivated account fails closed, so a stale token can never revive a blocked account.
- Added front-end passkey management over JSON at `warp/passkeys/creation-options`, `warp/passkeys/verify-creation`, and `warp/passkeys/delete`, letting a signed-in member enroll, name, and delete their own WebAuthn credentials.
- The credential-changing passkey actions are recent-auth gated and answer a `reauthRequired` envelope once the window has lapsed, the passwordless replacement for an elevated session.
- Passkey sign-in runs through Craft's own anonymous endpoints, for which Warp ships the front-end JavaScript and templates.
- Added a show-once passkey enrollment nudge, flagged after an email-flow sign-in by a member holding no passkey and surfaced once through `craft.warp.showPasskeyNudge`.
- Added the `enablePasskeyNudge` setting.
- Every redirect validates its `returnUrl` as same-site, blocking open-redirect attempts.

### User Management
- Added session and device management at `warp/sessions/revoke` and `warp/sessions/revoke-others`, backed by a lean Warp-owned registry that records a hashed session token plus a truncated user agent, IP, and, with a geo database present, city and country on each sign-in.
- The registry joins Craft's live session rows for a friendly device label, and a revocation deletes the authoritative core session row.
- Every session delete is scoped to the requesting member, so a posted UID can never reach another account's session.
- Added a member-facing sessions screen that renders each session as a device card with a device-type icon, a "Current device" pill, and expandable last-active, IP, and location detail.
- Sessions with no registry match render as "Unknown device" and stay revocable through "sign out everywhere else".
- Added a coarse user-agent-to-label helper for the sessions screen, for display only and with no fingerprinting.

### Administration
- Added a control panel overview screen, gated on the `warp:viewOverview` permission, leading with registration, enabled sign-in method, and passkey adoption stat tiles above a paginated, searchable table of recent passwordless sign-ins, newest first and searchable by user email or username.
- Added a tabbed settings section inside Warp's own control panel nav rather than on the global plugin settings screen, with "Login methods", "Tokens & codes", and "Registration" tabs.
- The "Login methods" tab carries two independent lightswitches, the passkey nudge, the new-location alert, and IP anonymization; "Tokens & codes" carries the numeric tunables, each accepting a literal or an environment-variable reference; and "Registration" carries the enable switch and a user group picker.
- The settings section is gated on the `warp:manageSettings` permission, is read-only under `allowAdminChanges: false` with a fail-closed save, and is tracked in project config.
- Added an append-only passwordless login log behind the overview, recorded on every Warp sign-in path (magic link, code, registration) and on Craft's passkey sign-in endpoint, and pruned after 90 days on Craft's garbage-collection pass.

### Development
- Added the `craft.warp` Twig variable, exposing `hasPasskeys`, `passkeys`, `webauthnJsUrl`, `registrationEnabled`, `loginMethods`, `otpDigits`, `requestedEmail`, `sessions`, and `showPasskeyNudge`.
- Added `craft.warp.otpForm({...}).render()`, which outputs the complete code-verify form: a post to `warp/auth/verify-code` with CSRF, the carried email prefill or a visible email input, the segmented code input, a hint, and a submit button.
- Added `craft.warp.otpInput({...}).render()`, which outputs the segmented code input alone, one paste-aware square per digit and sized to the `otpDigits` setting.
- The segmented code input is served by an auto-registered vanilla JavaScript and CSS asset, supports auto-advance, backspace, arrow keys, and paste distribution, and degrades to a plain input with no JavaScript so the form always submits.
- Added the `warp/example-templates` console command, which copies the example member area into the project's `templates/` directory, prompting for a folder name and rewriting the bundle's internal `members/...` references when a different name is chosen.
- Added the `--folder-name` and `--overwrite` options to `warp/example-templates`, for scripted setups.
- Added copy-ready front-end templates in `example-templates/members/`: sign-in and signup, "check your email", code entry, the account landing, passkey management, and session management.
- Each example template is a full page extending the bundle's own `_private/layouts` shell, with a skip link, nav, and flash notices, styled with Tailwind CSS from a CDN.

### Extensibility
- Added audit-event emission through Auth Kit's neutral audit contract (`AuthKit::$plugin->getAudit()->record()`, emitter `warp`), covering `login.magic_link` and `login.otp` on an email-flow sign-in, `login.passkey` on a passkey-route sign-in, `registration.fulfilled` on a signup, `passkey.enrolled` and `passkey.deleted` on credential management, and `session.revoked` with a `scope` of `single` or `others` on a genuine revocation.
- Audit events are recorded on success paths only, and are a no-op when no sink is registered.
- A signup emits `registration.fulfilled` alone, with no `login.*` event recorded alongside it, so one signup is one audit fact.

### System
- Added optional location awareness backed by a city MMDB read from `storage/warp/geo/city.mmdb`, in either the ip-location-db or the MaxMind record shape.
- Added the `warp/geo/refresh` console command, which populates that database from the `geoDatabaseUrl` set in `config/warp.php`.
- A sign-in from a country and city the member has never used before is flagged as `isNewLocation` and, when `notifyOnNewLocation` is on, emailed to the member through the editable `warp_new_location` system message.
- A member's first sign-in is never flagged as a new location, and location awareness degrades silently when no database is present.
- Added the `anonymizeIp` setting, off by default, which zeroes the final octet of an IPv4 address, and keeps only the /48 prefix of an IPv6 address, before a login-log or session-registry row is written.
- The geo lookup runs on the full address first, so city-level location and new-location detection are unaffected by `anonymizeIp`.
- Added `docs/privacy.md`, documenting the data inventory, retention windows, and GDPR lawful-basis notes.

### Accessibility
- The example templates carry an accessibility contract: labelled inputs, live regions, focus-to-error, and no color-only state.
