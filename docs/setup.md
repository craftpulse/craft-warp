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
- **The front end**, which is yours. Warp registers no site template root. It
  ships a complete member area as copy-in templates under
  `example-templates/members/`, following the same model as Craft Commerce's
  example templates: full pages extending a bundled layout, styled with
  Tailwind CSS from a CDN, rendering the moment the folder is copied in.

So the pages a member visits are templates you own; the endpoints those
templates post to are Warp's.

## Installing the example templates

The quickest way in is the console command:

```sh
php craft warp/example-templates
```

It prompts for a folder name (default `members`) and copies the bundle into
your `templates/` directory. Choosing a different folder name rewrites the
bundle's internal `members/...` template paths and URLs to match, so the copy
works wherever it lands. An existing folder is only replaced when you pass
`--overwrite`, and `--folder-name=members` skips the prompt for scripted
setups. Copying `example-templates/members/` into `templates/` by hand works
just as well.

The bundle is:

```
index.twig                     redirects to the account landing
login.twig                     sign in / sign up (one unified email form)
link-sent.twig                 "check your email" confirmation (magic-link branch)
otp-verify.twig                enter the emailed one-time code
account/index.twig             signed-in landing, hosts the passkey nudge
account/passkeys.twig          enroll / name / delete passkeys
account/sessions.twig          list and revoke active sessions
account/_includes/passkey-nudge.twig   the show-once "add a passkey" nudge
_private/layouts/index.twig    the shared HTML shell (head, nav, flashes)
_private/layouts/includes/header.twig  the member-area nav
```

Every page extends `members/_private/layouts`, a complete HTML document with a
skip link, a small nav, and the flash notices Warp's controllers set, so every
URL under `/members` is a valid, styled page out of the box. Each file carries
a header comment explaining what it posts to and its accessibility contract.

Styling is Tailwind CSS loaded from a CDN in the layout head, the same
approach Commerce's example templates take. To integrate with your own design,
restyle the pages in place (swap the CDN link for your own build) or replace
the bundled layout: change the one `{% extends %}` line per page to your
site's layout and keep the `{% block main %}` content.

The bundle assumes it lives at `templates/members/`: every template path and
URL in it starts with `members/`. If you rename the folder, update those
references throughout the bundle.

### Page URLs versus Warp's routes

The page URLs are plain template routing: `templates/members/login.twig`
serves `/members/login`, and so on, with no `config/routes.php` entries
needed. Warp fixes only the action routes the forms post to
(`actionUrl('warp/auth/request')` and friends); those must not be changed.

Two behaviors worth knowing:

- **The post-sign-in destination** is `members/account`, passed as a hashed
  `redirect` and a `returnUrl`. It is where a clicked magic link, a verified
  code, or a passkey login lands the member. Warp validates it as same-site
  before honouring it, so an open-redirect attempt is dropped and the member
  lands on the site root instead.
- **`link-sent` / `otp-verify`** are the neutral pages the request form
  redirects to. They must read the same whether the address was known,
  unknown, or garbage: the example copy already does this. Do not add "we
  could not find that account" messaging, which would defeat the enumeration
  safety the endpoint is built for.

### The OTP form builder

The code-entry page renders its whole form through a fluent builder, the same
shape Password Policy ships for its password forms:

```twig
{{ craft.warp.otpForm({
    returnUrl: url('members/account'),
    requestUrl: url('members/login'),
}).render() }}
```

That one call outputs the post to `warp/auth/verify-code` with CSRF, the
session-carried email prefill (or a visible email input on a direct visit),
the segmented code input, its hint, and the submit button. The input renders
as one square per digit, sized to the `otpDigits` setting, with auto-advance,
backspace, arrow keys, and paste distributing a full code across the squares;
with no JavaScript it degrades to a plain input, so the form always submits.

Composing your own form instead? `craft.warp.otpInput().render()` gives you
just the segmented input (options: `digits`, `name`, `id`, `label`,
`autofocus`, `inputAttrs`). Both builders auto-register a small JS and
neutral CSS asset; override the `warp-otp__*` and `warp-otp-form__*` classes
to restyle.

One core setting completes the wiring: point Craft's `loginPath` general config
setting at the copied login page (for example `->loginPath('members/login')`).
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
one token store, one set of messages. The new-location alert
(`warp_new_location`, see the [configuration reference](configuration.md#location-awareness))
is edited in the same place.

These are all standard Craft system messages, so everything that applies to
core's own emails (account activation, password reset) applies here:

- **Copy** is edited per site and language under **Settings** > **Email** >
  **System Messages**, Markdown supported. Each message receives Twig
  variables you can use in the subject and body: the magic-link message gets
  `{{ link }}` and `{{ user }}`, the one-time code gets `{{ code }}` and
  `{{ user }}`, the signup link gets `{{ link }}` and `{{ email }}`, and the
  new-location alert gets `{{ user }}`, `{{ city }}`, `{{ country }}`,
  `{{ location }}`, and `{{ sessionsUrl }}`.
- **Visual styling** comes from Craft's own email template setting
  (**Settings** > **Email** > **HTML Email Template**, project-config
  tracked; requires Craft Pro). Point it at a site Twig template and every
  system email, Warp's included, renders inside your branded HTML wrapper. No
  Warp configuration is involved, and the plain-text alternative Craft
  generates stays intact. Without a custom template (or on Craft Solo),
  emails use Craft's plain default wrapper.

### Styling the emails

The wrapper is an ordinary site Twig template. It receives `body`, the
message's parsed Markdown as ready-to-print HTML, plus the same variables the
message body gets (`user`, `link`, `code`, and so on), in case the wrapper
wants them. A minimal `templates/_emails/wrapper.twig`:

```twig
<!DOCTYPE html>
<html lang="{{ craft.app.language }}">
<head>
    <meta charset="utf-8">
</head>
<body style="margin: 0; padding: 0; background: #f3f4f6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0"
                       style="background: #ffffff; border-radius: 8px; padding: 32px; font-family: sans-serif; color: #111827;">
                    <tr><td>
                        {# Your logo/header here #}
                        {{ body }}
                        {# Your footer here #}
                    </td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

Set **HTML Email Template** to `_emails/wrapper` and send a test from the
same screen (**Settings** > **Email** > **Test**); a passwordless sign-in
request from the front end then arrives styled. See
[Craft's mail documentation](https://craftcms.com/docs/5.x/system/mail.html)
for the full mail-settings reference, including per-environment overrides via
`config/app.php`.

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
The nudge is surfaced by `account/_includes/passkey-nudge.twig`, which
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
