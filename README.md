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
        ├── zs-no-admin-mods.php  ← hardening: admin-mods block + XML-RPC lockdown + auto-update OFF (v1.2.0)
        ├── no-user-enum.php      ← hardening: blocks username enumeration (author archives + REST + sitemap)
        └── auto-update.php       ← self-update from GitHub releases
```

WordPress only scans top-level `*.php` in `mu-plugins/`, so the loader
file at top level `require_once`s the bootstrap inside the directory.

## Modules

| Module              | What it does                                            |
|---------------------|---------------------------------------------------------|
| `no-admin-mods`     | Blocks plugin/theme/core install/update/delete from wp-admin browser. Whitelisted operator emails, MainWP Child, WP-CLI and WP-Cron continue to work. |
| `disable-xmlrpc`    | Disables XML-RPC site-wide (xmlrpc_enabled false + empty methods array + no RSD link + no X-Pingback header). |
| `no-user-enum`      | Closes username enumeration: author archives (`/author/slug/` and `?author=N`) return 404 before the canonical redirect can leak the login slug; the REST `/wp/v2/users` endpoint is stripped for anonymous callers; the core users sitemap provider is dropped. Logged-in builder/editor REST calls are untouched. |
| `auto-update`       | Daily WP_Cron pulls new releases from GitHub. Atomic swap, prereleases ignored. |
| `update-engine`     | **Fleet v2.** Pulls a SIGNED manifest from the control-plane, applies plugin updates with file-level stash/rollback, self-verifies (version + active + HTTP + fingerprint), reports JSON. **Ships inert** — no-op until `ZS_FLEET_UE_CONTROL_URL` + `ZS_FLEET_UE_PUBKEY` are set. Contract: `fleet-toolkit/docs/fleet-v2-architecture.md`. |

## Per-site opt-out

Each module exposes a filter that lets a single site turn it off
without disabling zs-fleet across the fleet. Filter name pattern:

```
zs_fleet_<module-slug>_enabled   (default: true)
```

The check happens inside the module's hooks, so the filter callback
can be added from a regular plugin (e.g. the site's `zs_<slug>`) and
it will fire in time. To disable a module at one site, drop this in
the site's per-site plugin or in `wp-config.php`:

```php
add_filter( 'zs_fleet_disable_xmlrpc_enabled', '__return_false' );
```

Currently exposed flags:

| Module            | Filter                                |
|-------------------|---------------------------------------|
| `disable-xmlrpc`  | `zs_fleet_disable_xmlrpc_enabled`     |
| `auto-update`     | `zs_fleet_auto_update_enabled`        |
| `no-user-enum`    | `zs_fleet_no_user_enum_enabled`       |

When you add a new module, follow the same pattern: gate every hook
on `apply_filters('zs_fleet_<slug>_enabled', true)` returning truthy,
and add a row to the table above.

## Adding a module

1. Drop a `*.php` file in `modules/`. It must NOT carry a plugin header
   — only `zs-fleet.php` does.
2. Tag a release.
3. Pull or extract the new release on each fleet site.

Module load order is alphabetical. Prefix with a number (`10-foo.php`)
if ordering matters.

## Deployment to a site

The loader is self-bootstrapping. First-install reduces to:

1. Drop `deploy/zs-fleet-loader.php` into the site's
   `wp-content/mu-plugins/` directory (panel, SFTP, whatever).
2. Visit the site once.

On the first request after install the loader sees that `zs-fleet/`
is missing, downloads the latest release zip from
`github.com/marcorubiol/zs-core/releases/latest/download/zs-fleet.zip`,
extracts it into `mu-plugins/zs-fleet/`, and is fully active on the
next request. After that, the `auto-update` module inside the
extracted code keeps the site current daily.

Manual bulk install (skip the self-bootstrap, useful when
downloading the zip locally to push via tooling):

```bash
gh release download --repo marcorubiol/zs-fleet --pattern 'zs-fleet.zip'
unzip zs-fleet.zip
# now you have zs-fleet/ + zs-fleet-loader.php side by side
scp -r zs-fleet/                site:/path/to/wp-content/mu-plugins/
scp    zs-fleet-loader.php      site:/path/to/wp-content/mu-plugins/
```

To force an update right now (e.g. just published a fix and don't
want to wait the daily cron) — hit the trigger URL. It requires a
logged-in `manage_options` operator, or a shared secret
(`ZS_FLEET_AU_TRIGGER_SECRET` in `wp-config.php`) passed as
`?zs_fleet_secret=…`:

```bash
curl -s "https://site.example/?zs_fleet_check_now=1&zs_fleet_secret=<secret>"
# version_before: 0.1.5
# version_after:  0.1.6
# updated:        yes
```

For the whole fleet, use the included push script (manifest at
`deploy/fleet.txt`):

```bash
./deploy/fleet-push.sh           # all sites
./deploy/fleet-push.sh paellasencasa.com   # one site
```

The trigger is idempotent: if the site is already on the latest
version the downloaded zip is discarded. An internal 5-minute
transient lock caps how often the real work can run. The swap
itself is signature-gated (see _Release signing_ below), so the
trigger can only ever install a validly-signed release.

## Canary releases

A tag with a hyphen suffix is treated as a prerelease and ignored
by the fleet auto-updater:

```bash
git tag v0.2.0-canary
git push origin v0.2.0-canary   # built, published as PRERELEASE, NOT applied
```

Test in Local. When validated, promote:

```bash
git tag v0.2.0
git push origin v0.2.0          # published as latest, applied within 24h
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

Release checklist (do not skip — header and tag must match):

1. Bump `Version:` in `zs-fleet.php` header AND `ZS_FLEET_VERSION` constant.
2. Bump `Version:` in `deploy/zs-fleet-loader.php` header.
3. Commit with message `release: vX.Y.Z`.
4. Tag `vX.Y.Z` and push tag — the GitHub Action builds the zip.
5. **Sign the release (mandatory once a sig-checking build is live).**
   Download the published `zs-fleet.zip` asset, sign it locally, and
   upload the resulting `.sig` to the SAME release:

   ```bash
   gh release download vX.Y.Z --repo marcorubiol/zs-core --pattern 'zs-fleet.zip'
   ZS_RELEASE_SIGNING_SK="<base64 signing key>" php deploy/sign-release.php zs-fleet.zip
   # Confirm the printed public key equals ZS_FLEET_UE_PUBKEY, then:
   gh release upload vX.Y.Z --repo marcorubiol/zs-core zs-fleet.zip.sig
   ```

## Release signing

Since v0.3.x the fleet self-updater (`modules/auto-update.php`) and the
first-install loader refuse to swap a downloaded zip unless a detached
Ed25519 `.sig` verifies over the exact zip bytes, using the fleet public
key `ZS_FLEET_UE_PUBKEY`. The private signing key lives only in the
control-plane / operator vault and is passed to `deploy/sign-release.php`
via `ZS_RELEASE_SIGNING_SK` — never in CI, never printed.

Fail-closed: a release published WITHOUT a valid `.sig` will not be
applied by the fleet (the self-update stops until a signed release
appears). This is intended — an unsigned or tampered zip is refused
rather than installed. Sign every release from v0.3.x onward.
