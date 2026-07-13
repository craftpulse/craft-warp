# Warp

Passwordless front-end auth for Craft CMS 5 members. Magic links, email one-time
codes, and passkeys, plus passwordless-first registration and session management,
assembled into a copy-and-restyle front-end surface.

Warp turns Craft's front-end member area passwordless. Members sign in with a
magic link, an emailed one-time code, or a passkey, with no passwords to set,
forget, or leak. It is a polished product built on
[`craftpulse/craft-auth-kit`](https://github.com/craftpulse/craft-auth-kit),
which carries the security-critical primitives (the hashed single-use token
store, the passkey wrappers, the recent-auth gate) that Warp assembles into
routes, controllers, templates, and a control-panel section.

## What Warp does

- **Magic-link login.** A single-use, enumeration-safe, rate-limited link emailed
  to an existing member, clicked to sign in.
- **Email one-time codes.** A short numeric code, attempt-capped and superseded
  on re-issue, for members who would rather type a code than click a link.
- **Passkeys.** Enroll, name, and delete WebAuthn credentials from a front-end
  account screen, and sign in with a passkey through core's own anonymous
  endpoints. A show-once nudge invites a first-time email login to add one.
- **Passwordless-first registration.** New members join from the same email
  form that signs existing members in. The unknown address is emailed a signup
  link; verifying it creates an active, password-less account in a configured
  group. The HTTP response is identical to the login branch, so the form never
  reveals which addresses are registered.
- **Session and device management.** Members see their active sessions as device
  cards with a device-type icon, a friendly label, and expandable last-active, IP,
  and location detail; they can sign any one of them out, or sign out everywhere
  else in a single step.
- **Location awareness (optional).** With a city MMDB installed, sign-ins record a
  coarse city and country, the control panel badges a sign-in from a location the
  member has never used before, and Warp can email the member a new-location
  alert. Everything degrades silently with no database present.
- **Control-panel section.** A tabbed settings screen for the passwordless
  tunables (with environment-variable support and hover info popovers) and an
  overview with posture stat tiles above a paginated, searchable sign-ins table.
- **Copy-ready front-end templates.** A complete member area ships in
  `example-templates/members/`, following the same model as Craft Commerce's
  example templates: full pages extending a bundled layout, styled with
  Tailwind CSS from a CDN, working the moment the folder is copied in.

## Requirements

- Craft CMS 5.10.0 or later (the passkey wrappers use core's WebAuthn serializer,
  which is only public as of 5.10.0)
- PHP 8.2 or later
- [`craftpulse/craft-auth-kit`](https://github.com/craftpulse/craft-auth-kit)
  1.2.0 or later, which Composer installs automatically as a dependency

## Installation

Install from the Craft Plugin Store, or with Composer:

```sh
composer require craftpulse/craft-warp
./craft plugin/install warp
```

Auth Kit is pulled in as a Composer dependency and installed alongside Warp. You
do not install or configure it separately: Warp pushes its settings into Auth
Kit's services at boot.

## Quick start

Warp ships the back end (routes, controllers, services) and a set of copy-in
front-end templates. Wiring up a member area is three steps:

1. **Copy the example templates.** Copy `example-templates/members/` into your
   project as `templates/members/`. The bundle covers login, the "check your
   email" and code-entry pages, the account landing, passkey management, and
   session management, all extending its own layout
   (`members/_private/layouts`), so every URL under `/members` renders a
   complete, styled page immediately. Each file's header explains what it
   posts to.

2. **Point Craft's login path at it.** Set the `loginPath` general config
   setting to `members/login` so guests and failed verifications land on the
   copied login page. To integrate with your own design, restyle the pages in
   place or swap the one `{% extends %}` line per page to your site's layout;
   the bundle assumes it lives at `templates/members/`, so update its
   `members/...` references if you rename the folder.

3. **Enable the settings you want.** In the control panel, open **Warp** to reach
   the settings screen. Choose the login methods (magic link, one-time code, or
   both), set the token lifetime and code length, and turn registration on or
   off. Registration additionally requires Craft's own public-registration
   switch (see the setup guide).

The default `auth_kit_magic_link`, `auth_kit_otp`, and `auth_kit_register` system
messages ship ready to use. Customize their copy under
**Settings** > **Email** > **System Messages** in the control panel.

## Documentation

- [Setup guide](docs/setup.md): copying the templates, page routing, customizing
  the system messages, registration prerequisites, the passkey nudge, sessions,
  and multi-site notes.
- [Configuration reference](docs/configuration.md): every setting with its
  default and effect, the control-panel permission, read-only behavior, and the
  "no password flows" contract.
- [Privacy and GDPR](docs/privacy.md): what Warp stores and for how long, the
  lawful basis, the `anonymizeIp` option, and what to add to your privacy
  policy.
- [Manual checks](docs/manual-checks.md): the human verification checklist for
  reviewers.

## Privacy

Warp stores IP addresses, coarse locations, and device labels for account
security only: the login log prunes itself after 90 days, session rows die with
their sessions, the geo lookup runs locally and derives a coarse city only, and
an optional `anonymizeIp` setting zeroes the end of each address before it is
stored. The lawful basis under the GDPR is legitimate interest in network and
information security (Recital 49); mention the processing in your site's
privacy policy. The [privacy guide](docs/privacy.md) has the full inventory and
suggested disclosure wording.

## No passwords

Warp is passwordless by design. It never sets, stores, validates, or asks for a
member password: mailbox possession (a magic link, a code, or a signup link) and
passkeys are the only proofs it accepts. Members created through registration are
active with no password. If your site adds its own password forms elsewhere, they
can cooperate with a password-policy provider through Auth Kit's
`PasswordValidatorInterface` contract; Warp itself stays out of it. See the
[configuration reference](docs/configuration.md#no-password-flows) for the full
statement.

## License

Warp is commercial software. See [LICENSE.md](LICENSE.md).
</content>
</invoke>
