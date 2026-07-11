# Warp

> Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.

Warp turns Craft's front-end member area passwordless. Members sign in with a
magic link, an emailed one-time code, or a passkey — no passwords to set,
forget, or leak. It is a polished product built on
[`craftpulse/craft-auth-kit`](https://github.com/craftpulse/craft-auth-kit),
which carries the security-critical primitives Warp assembles.

**Status: in development.** See [BUILD.md](BUILD.md) for the phased build plan
and [PLAN.md](PLAN.md) for the product rationale.

## Features

- **Magic-link login** — request and verify, enumeration-safe and rate-limited.
- **Email one-time codes** — "email me a code", attempt-capped.
- **Passkeys** — enroll, manage, and sign in with WebAuthn.
- **Passwordless-first registration** — new members join from the same form.
- **Session and device management** — see active sessions, sign out everywhere.
- **Control-panel settings and overview** — recent logins, passkey adoption.
- **Copy-ready front-end templates** — restyleable, no framework lock-in.

## Requirements

- Craft CMS 5.10.0 or later
- PHP 8.2 or later
- [`craftpulse/craft-auth-kit`](https://github.com/craftpulse/craft-auth-kit) 1.1.0 or later

## Installation

Install from the Craft Plugin Store, or with Composer:

```bash
composer require craftpulse/craft-warp
./craft plugin/install warp
```

## License

Warp is commercial software. See [LICENSE.md](LICENSE.md).
