# Setup

This guide takes a fresh Warp install to a working member area: copying the
example templates, wiring your page URLs, customizing the emails, and turning on
the flows you want. For the meaning of each setting, see the
[configuration reference](configuration.md).

## The shape of Warp

Warp ships two halves:

- **The back end**, which is fixed. Action routes (`warp/auth/*`,
  `warp/passkeys/*`, `warp/sessions/*`), controllers, and services are
  registered by the plugin. You never define these; you post to them.
- **The front end**, which is yours. Warp registers no site template root and
  imposes no page URLs. It ships the screens as copy-in examples under
  `examples/front-end/`, following the same "copy and restyle" model as the rest
  of the CraftPulse security plugins.

So the page a member visits to sign in is a template you own at a URL you choose;
the endpoint that template posts to is Warp's.

## Copying the example templates

Copy the whole `examples/front-end/` bundle into your project's `templates/`
directory. The bundle is:

```
login.twig                 sign in / sign up (one unified email form)
link-sent.twig             "check your email" confirmation (magic-link branch)
otp-verify.twig            enter the emailed one-time code
account/index.twig         signed-in landing, hosts the passkey nudge
account/passkeys.twig      enroll / name / delete passkeys
account/sessions.twig      list and revoke active sessions
_partials/flashes.twig     success and error flash notices
_partials/passkey-nudge.twig   the show-once "add a passkey" nudge
```

Every file carries a header comment explaining what it posts to, which page URLs
are yours, and its accessibility contract. Restyle freely: the templates carry no
framework classes, only low-specificity `warp-` hooks.

### The `warpBase` include prefix

Craft resolves `{% include %}` from the templates **root**, not relative to the
including file. The templates that pull in a partial (`login.twig`,
`link-sent.twig`, `otp-verify.twig`, `account/index.twig`, `account/sessions.twig`)
declare a `warpBase` variable at the top and prefix their includes with it:

```twig
{%- set warpBase = '' -%}
{% include warpBase ~ '_partials/flashes' only %}
```

- Copy the bundle to your templates **root** and leave `warpBase` empty.
- Nest the bundle in a subfolder (say `templates/members/`) and set `warpBase`
  to that path **with a trailing slash**, for example `'members/'`, so the
  partial includes still resolve.

Set it once per page, at the top, where the other page variables live.

### Page routing: your URLs versus Warp's routes

Warp fixes only the action routes. The page URLs a member navigates between are
yours. Each template exposes them as `{% set %}` variables at the top so you edit
them in one place, for example in `login.twig`:

```twig
{%- set linkSentUrl = 'members/link-sent' -%}    {# after a magic-link request #}
{%- set codeEntryUrl = 'members/verify-code' -%} {# after an OTP-code request #}
{%- set afterSignInUrl = '/members' -%}          {# where the emailed link lands #}
```

Point these at the routes you serve the copied templates from (via
`config/routes.php`, section/entry URIs, or template routing, whichever you use).
The action routes the forms post to (`actionUrl('warp/auth/request')` and
friends) are fixed and must not be changed.

Two page URLs carry a specific job:

- **`afterSignInUrl`** is passed as a hashed `redirect` and a `returnUrl`. It is
  where a clicked magic link, a verified code, or a passkey login lands the
  member. Warp validates it as same-site before honouring it, so an open-redirect
  attempt is dropped and the member lands on the site root instead.
- **`link-sent` / `otp-verify`** are the neutral pages the request form redirects
  to. They must read the same whether the address was known, unknown, or garbage:
  the example copy already does this. Do not add "we could not find that account"
  messaging, which would defeat the enumeration safety the endpoint is built for.

One core setting completes the wiring: point Craft's `loginPath` general config
setting at your copied login page (for example `->loginPath('members/login')`).
A failed verification (an expired or reused link, a dead registration link)
redirects there so its "invalid or expired" flash renders on a page that shows
flashes and offers a fresh request form. Without it, failures land on Craft's
default `/login` path; if `loginPath` is disabled entirely (`false` or headless),
they fall back to the site root.

## Customizing the emails

Warp sends through Auth Kit's three editable system messages. Their default copy
ships ready to use; to change it, open the control panel and go to **Settings**,
then **Email**, then **System Messages**:

| Message key | Sent when |
|---|---|
| `auth_kit_magic_link` | a member requests a magic-link sign-in |
| `auth_kit_otp` | a member requests a one-time code |
| `auth_kit_register` | an unknown address requests sign-up (registration open) |

Edit the subject and body per site. Because these belong to Auth Kit, the same
copy is shared by any other Auth Kit consumer on the install; that is by design,
one token store, one set of messages.

## Registration prerequisites

Passwordless registration turns on only when **both** switches are on:

1. **Warp's own `enableRegistration` setting** (on the Warp settings screen,
   default on).
2. **Craft's public-registration switch.** This is the core
   `users.allowPublicRegistration` value in project config, the same switch
   Craft's own front-end registration checks. It is not a `config/general.php`
   setting: set it through the control panel where Craft exposes user settings,
   or in `project.yaml` under `users.allowPublicRegistration`.

When either switch is off, the unified email form silently degrades to
login-only: an unknown address is emailed nothing, and the HTTP response is
unchanged, so the form still never reveals whether registration is open. New
members join the group named by Warp's `registrationGroupUid` setting, or Craft's
default user group when that is unset. They are created active with no password;
verifying the signup link is the proof.

## The passkey nudge

After a member signs in over an email flow (magic link, code, or a fresh signup)
while holding no passkey, Warp flags a one-time nudge inviting them to add one.
The nudge is surfaced by `_partials/passkey-nudge.twig`, which
`account/index.twig` includes on the signed-in landing page.

Reading `craft.warp.showPasskeyNudge` **clears** the flag, so the nudge appears
exactly once per triggering login. Include the partial on the first signed-in
page a member lands on, and only once per request: a second read in the same
request is always false. Turn the nudge off entirely with the
`enablePasskeyNudge` setting.

## The sessions page

`account/sessions.twig` lists the member's active sessions through
`craft.warp.sessions`. Each row is a live Craft session joined to Warp's device
registry for a friendly label; the session making the request wears a "This
device" badge and has no per-row sign-out button (the normal sign-out link ends
it). Other rows offer a per-row "Sign out", and a "Sign out everywhere else"
button clears every session but the current one.

A session created before Warp was installed, or by a path Warp does not capture,
has no registry row and renders as "Unknown device" with no per-row button. It
carries no handle to target individually, but "Sign out everywhere else" still
clears it.

Both revoke endpoints are recent-auth gated: signing sessions out is sensitive,
so a stale session gets a `reauthRequired` response the template catches, showing
a "please sign in again" prompt. Signing in again is the re-authentication;
passwordless members have no password to re-confirm.

## Multi-site notes

Warp builds magic-link, code, and registration verify URLs with Craft's
site-aware URL helpers at issuance time, so a link **lands on the site that
issued it**. A member who requested a link from your Dutch site clicks through to
the Dutch site, not the primary one.

Two consequences for a multi-site member area:

- Serve the copied templates on each site that offers passwordless login, at the
  URLs that site expects. The `afterSignInUrl` and page-routing variables are
  per-template, so a site-specific template can point at site-specific routes.
- The same-site `returnUrl` validation is scoped to the issuing site's base URL,
  so a `returnUrl` pointing at another site in the group is dropped as an
  open-redirect attempt. Keep post-login destinations on the same site as the
  form.

## Privacy disclosure

Warp records sign-in history (IP, coarse location, device label) for account
security, which belongs in your site's privacy policy. The
[privacy guide](privacy.md) has the data inventory, retention windows, the
GDPR lawful-basis notes, and suggested disclosure wording, plus the optional
`anonymizeIp` setting for deployments that must not store full addresses.
</content>
