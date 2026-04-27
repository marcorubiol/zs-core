# zs-fleet

Modular MU-plugin for the Zerø Sense agency fleet. Bundles fleet-wide
hardening and policy modules. Each file in `modules/` is auto-loaded.

## Why MU-plugin

Must-Use plugins are auto-loaded by WordPress with no activation step
and cannot be deactivated from the admin UI. That is exactly what we
want for fleet-wide hardening: a client admin (or anyone with admin
caps) cannot turn it off accidentally or otherwise.

## Architecture

```
wp-content/mu-plugins/
├── zs-fleet-loader.php     ← from deploy/zs-fleet-loader.php
└── zs-fleet/               ← contents of this repo
    ├── zs-fleet.php        ← bootstrap (header + glob('modules/*.php'))
    └── modules/
        ├── no-admin-mods.php
        └── disable-xmlrpc.php
```

WordPress only scans top-level `*.php` in `mu-plugins/`, so the loader
file at top level `require_once`s the bootstrap inside the directory.

## Modules

| Module              | What it does                                            |
|---------------------|---------------------------------------------------------|
| `no-admin-mods`     | Blocks plugin/theme/core install/update/delete from wp-admin browser. Whitelisted operator emails, MainWP Child, WP-CLI and WP-Cron continue to work. |
| `disable-xmlrpc`    | Disables XML-RPC site-wide (xmlrpc_enabled false + empty methods array + no RSD link + no X-Pingback header). |

## Adding a module

1. Drop a `*.php` file in `modules/`. It must NOT carry a plugin header
   — only `zs-fleet.php` does.
2. Tag a release.
3. Pull or extract the new release on each fleet site.

Module load order is alphabetical. Prefix with a number (`10-foo.php`)
if ordering matters.

## Deployment to a site

First-time install:

```bash
# 1. Get a release zip (or git clone)
gh release download v0.1.0 --repo marcorubiol/zs-fleet

# 2. Upload to mu-plugins/
scp deploy/zs-fleet-loader.php  site:/path/to/wp-content/mu-plugins/
scp -r zs-fleet/                site:/path/to/wp-content/mu-plugins/
```

Update:

```bash
# Replace the directory in place; loader stays.
ssh site 'rm -rf /path/to/wp-content/mu-plugins/zs-fleet'
scp -r zs-fleet/ site:/path/to/wp-content/mu-plugins/
```

## Removing from a site

```bash
ssh site 'rm /path/to/wp-content/mu-plugins/zs-fleet-loader.php
          rm -rf /path/to/wp-content/mu-plugins/zs-fleet'
```

Filters stop firing immediately on the next request. No database state
to clean.

## Versioning

`v0.x` while structure stabilises. `v1.0.0` once fleet rollout is
complete and the module set is settled. Bumps follow semver:

- **Major** — breaking layout change (loader path, directory rename).
- **Minor** — new module added.
- **Patch** — fix or behaviour tweak inside an existing module.
