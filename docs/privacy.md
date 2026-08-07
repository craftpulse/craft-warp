# Privacy and GDPR

Warp stores a small amount of personal data (IP addresses, coarse locations,
device labels) for exactly one purpose: account security. This page inventories
what is stored, for how long, on what lawful basis, and what you as the
installer should add to your own privacy documentation.

## What Warp stores

| Store | Contents | Retention |
|---|---|---|
| Login log (`warp_logins`) | user id, sign-in method, truncated user-agent, IP address, coarse city and ISO country (only when a geo database is installed), new-location flag, timestamp | Pruned after 90 days on Craft's garbage-collection pass. The window is a [fixed constant](configuration.md#fixed-values), deliberately not a setting. |
| Session registry (`authkit_sessions`) | user id, a sha256 hash of the Craft session token (never the token itself), truncated user-agent, IP address, coarse city and country | Lives exactly as long as the core Craft session it describes: logout deletes the row synchronously, and garbage collection sweeps any row whose core session has died. There is no independent retention. |
| Location history (`authkit_locations`) | user id, ISO country, city, and when the member was last alerted about that place | One row per place, not per sign-in, so the table grows with how much a member travels rather than how often they sign in. No independent retention. |

The last two tables belong to the shared `craft-auth-kit` package rather than to
Warp, which is what keeps two CraftPulse security plugins on one install from
each holding their own copy of this data and each emailing the member about the
same trip. Uninstalling Warp therefore leaves them in place for whichever other
plugin still uses them; the login log goes with Warp.

Every table listed references the user with an `ON DELETE CASCADE` foreign key,
so deleting a user erases every row about them in the same operation. That
covers an Article 17 erasure request with no extra step, and an Article 15
access request can be answered from the three tables filtered by the user's id.

## Purpose and lawful basis

The data serves network and information security: recognizing the devices a
member signs in from, alerting them to a sign-in from a location they have
never used, and letting them audit and revoke their own active sessions.

GDPR Recital 49 recognizes processing personal data "to the extent strictly
necessary and proportionate for the purposes of ensuring network and
information security" as a legitimate interest of the controller, making
Article 6(1)(f) the applicable lawful basis. No consent flow is required for
this processing, but it must be disclosed (see below).

The new-location alert email is a security notice tied to that same purpose.
It is operational mail, not marketing, and needs no marketing consent.

## Data minimisation, by design

- **The login log prunes itself.** Rows older than 90 days are deleted on
  Craft's garbage-collection schedule. The overview is a recent-activity view,
  not a compliance archive, and the retention cannot be extended.
- **Session rows die with the session.** The registry only ever describes live
  sessions; nothing about a signed-out device is retained.
- **Location history is places, not visits.** One row per country and city a
  member has signed in from, holding no timestamps of individual sign-ins and
  no addresses.
- **Location is coarse and derived locally.** The geo lookup runs against a
  local MMDB file and yields a city name and a two-letter country code only.
  No coordinates are stored, and no member IP is ever sent to a third party at
  lookup time. (Refreshing the database downloads a file from the configured
  URL; no member data travels with that request.) See
  [the geo database](configuration.md#geo-database-mmdb) for how the file is
  installed and refreshed, and for the attribution the default database's
  licence obliges you to display.
- **Device labels are not fingerprints.** The user-agent is truncated and
  reduced to a coarse "Chrome on macOS" label for display. Two different
  phones can share a label; the label never influences authorization.
- **Session tokens are stored as hashes.** A leak of the registry yields no
  usable session token.
- **Optional IP anonymization.** With the
  [`anonymizeIp` setting](configuration.md#anonymize-ip-addresses-anonymizeip)
  on (it is off by default), a stored IPv4 address has its final octet zeroed
  and an IPv6 address keeps only its /48 network prefix, so a row covers a
  whole network rather than one connection. It applies to new rows only, and
  location detection is unaffected.

## What you should put in your privacy policy

Warp is a processor building block; you remain the controller. Disclose the
processing in your privacy policy, along these lines:

- When a member signs in, the site records the sign-in method, the IP address
  (optionally anonymized), an approximate city and country derived from it,
  and a coarse device description, for account-security purposes (recognizing
  devices and alerting the member to unusual sign-ins).
- Sign-in history is kept for up to 90 days; the list of active devices is
  kept only while those sessions remain signed in; the list of places the
  member has signed in from is kept for as long as the account exists, and
  records places rather than visits.
- The lawful basis is legitimate interest in network and information security
  (GDPR Recital 49); members may be emailed a security notice when their
  account is accessed from a new location.

If your deployment must not store full IP addresses at all, enable
`anonymizeIp` and say so in the disclosure.
