# Upgrading

Warp upgrades the ordinary way: update the package and run your migrations.

```shell
composer update craftpulse/craft-warp
php craft up
```

```shell
ddev composer update craftpulse/craft-warp
ddev craft up
```

The notes below cover releases that changed something you might otherwise
notice. Everything else is in the [changelog](../CHANGELOG.md).

## Upgrading from 5.0.0-beta.3 or earlier

Auth Kit used to be a separate plugin. As of 5.0.0-beta.4 it ships as a library
that Composer pulls in with Warp, so it no longer appears in your plugins list,
in your project config, or in `plugin/install` and `plugin/uninstall`.

There is no step to perform by hand. Warp's own migration converts the existing
Auth Kit install in place: it moves the migration history onto the new track and
clears the obsolete plugin entry out of the plugins table and the project
config, leaving Auth Kit's tables alone. Every token, passkey, and setting
survives, and every passwordless feature behaves as before.

If you run Warden on the same site, whichever plugin migrates first does the
conversion and the other finds nothing left to do.

## Upgrading from 5.0.0-beta.2 or earlier

Warp's permission handles moved from camelCase to kebab-case:
`warp:manageSettings` became `warp:manage-settings`, and `warp:viewOverview`
became `warp:view-overview`.

The migration carries existing grants over, for individual users, for user
groups, and in project config, so nobody loses access and there is nothing to
re-grant. If you check a Warp permission in your own code or templates, update
the handle you pass to `can()`.
