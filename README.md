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
- **Session and device management.** Members see their active sessions with a
  friendly device label, sign any one of them out, or sign out everywhere else
  in a single step.
- **Control-panel section.** A settings screen for the passwordless tunables and
  an overview of recent passwordless sign-ins and passkey adoption.
- **Copy-ready front-end templates.** Every screen ships as a restyleable example
  in `examples/front-end/`, carrying no framework classes, only low-specificity
  hooks.

## Requirements

- Craft CMS 5.10.0 or later (the passkey wrappers use core's WebAuthn serializer,
  which is only public as of 5.10.0)
- PHP 8.2 or later
- [`craftpulse/craft-auth-kit`](https://github.com/craftpulse/craft-auth-kit)
  1.1.0 or later, which Composer installs automatically as a dependency

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

1. **Copy the example templates.** Copy `examples/front-end/` into your project's
   `templates/` directory (or a subfolder of it), then restyle. The bundle
   covers login, the "check your email" and code-entry pages, the account
   landing, passkey management, and session management. Each file's header
   explains what it posts to and which page URLs are yours to own.

2. **Point the page URLs at your routes.** Warp fixes only its action routes
   (`warp/auth/*`, `warp/passkeys/*`, `warp/sessions/*`); every page URL is
   yours. Each template exposes its page links as `{% set %}` variables at the
   top (the login page, the code-entry page, the account pages), so you edit them
   in one place. If you nest the bundle in a subfolder, set the `warpBase`
   variable to that path so the partial includes resolve.

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
- [Manual checks](docs/manual-checks.md): the human verification checklist for
  reviewers.

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
