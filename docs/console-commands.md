# Console commands

Warp ships two console commands: one to install the example member area, one to
install or refresh the optional geo database. Each is shown below in its plain
form and its DDEV form.

## `warp/example-templates`

Copies Warp's example member area into your project's `templates/` directory,
the same convenience Craft Commerce ships as `commerce/example-templates`.

```shell
php craft warp/example-templates
```

```shell
ddev craft warp/example-templates
```

The command prompts for a folder name, defaulting to `members`, and copies the
bundle to `templates/<folder>`. Choosing a different name rewrites the bundle's
internal `members/...` template paths and URLs to match, so the copy works
wherever it lands. It refuses to touch an existing folder unless you pass
`--overwrite`, so a second run cannot silently clobber your edits.

| Option | Description |
|---|---|
| `--folder-name` | Sets the target folder under `templates/` and skips the prompt, for scripted setups. |
| `--overwrite` | Replaces the target folder if it already exists. Required whenever the folder is there. |

Installing under a different name, without the prompt:

```shell
php craft warp/example-templates --folder-name=portal --overwrite
```

```shell
ddev craft warp/example-templates --folder-name=portal --overwrite
```

See the [setup guide](setup.md#installing-the-example-templates) for what the
bundle contains and how to restyle it.

## `warp/geo/refresh`

Downloads a fresh city MMDB from the configured URL and installs it at
`storage/warp/geo/city.mmdb`, replacing any current database.

```shell
php craft warp/geo/refresh
```

```shell
ddev craft warp/geo/refresh
```

The command takes no options. Run it on deploy or on a schedule to keep the
database current. The download URL is the `geoDatabaseUrl` setting, documented
under [the geo database](configuration.md#geo-database-mmdb).

Location awareness is optional. With no database installed, Warp resolves no
location, flags no new locations, and sends no alerts, so a project that does
not want the feature can leave this command unrun.
