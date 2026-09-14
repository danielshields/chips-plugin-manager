# CHIPS Plugin Manager

Installs and updates the CHIPS WordPress utility plugins on client sites,
straight from their public GitHub repositories.

Once it's active, the plugins it manages behave like any other plugin: updates
show up on **Plugins**, on **Dashboard → Updates**, and in `wp plugin update`.
The dedicated screen at **Plugins → CHIPS Plugins** adds install/activate
buttons and a manual "check GitHub now".

## Adding a plugin

Edit [`plugins.json`](plugins.json) in this repo and commit to `main`. Client
sites re-read it within six hours — no need to update this plugin on each site.

```json
{
  "slug": "chips-wp_rte-input",
  "repo": "danielshields/chips-wp_rte-input",
  "main_file": "chips-rte-input.php",
  "branch": "main",
  "name": "CHIPS – Text Input with Format",
  "description": "…",
  "icon": "icon-256x256.png",
  "requires_plugins": ["advanced-custom-fields-pro"]
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `repo` | yes | `owner/name`. Must be public. |
| `slug` | no | The folder the plugin installs into. Defaults to the repo name. **Set this when a site already has the plugin under a different folder name** — changing it later orphans the existing install. |
| `main_file` | no | Primary PHP file *inside* that folder. Defaults to `{repo-name}.php`. Set it whenever the file isn't named after the repo — `chips-wp_rte-input` holds `chips-rte-input.php`, not `chips-wp_rte-input.php`. |
| `branch` | no | Defaults to `main`. |
| `icon` | no | Path in the repo to an image, shown on the updates screen. |
| `requires_plugins` | no | Folder names of plugins that should be active. Shown as a note; not enforced. |

Getting `slug` or `main_file` wrong is the main way this goes sideways: the
plugin's identity to WordPress is `{slug}/{main_file}`, and if that doesn't
match what's on the site, the update lands as a *second* plugin instead.

## How updating works

Versions come from the `Version:` header of `main_file` on the tracked branch,
read over `raw.githubusercontent.com` (no API rate limit, ~8 KB per check via a
Range request). Anything higher than the installed version is offered as an
update; the download is the branch archive.

**Every push to `main` that bumps the version header becomes an update offer on
every client site**, within the six-hour cache. There's no staging step, so
treat a version bump as the publish action — commit freely, bump deliberately.

Results are cached for six hours (30 minutes after a failed lookup). The
"Check GitHub for updates" button clears all of it.

### Two things it handles that a naive updater doesn't

- **Folder renaming.** A GitHub archive unpacks to `{repo}-{branch}`, e.g.
  `chips-wp_rte-input-main`. Installed as-is, WordPress treats that as a
  different plugin from `chips-wp_rte-input` — you get a duplicate and the
  original stays at the old version. `upgrader_source_selection` renames the
  unpacked folder to the declared slug first. The CHIPS Plugins screen also
  flags stray `-main` folders left behind by a previous updater.
- **Filter ordering.** ACF and WP Migrate DB Pro both rebuild the
  `site_transient_update_plugins` object at the default priority, discarding
  entries added before them. Updates are injected at `PHP_INT_MAX` so they
  survive.

Updates use `bulk_upgrade()` rather than `upgrade()`, because the single-plugin
path deactivates the plugin before upgrading and never turns it back on.

## Requirements and limits

- Public repositories only — no token is stored on client sites.
- Tested on single-site WordPress. Multisite isn't tested; the version cache
  index uses `update_option`, which is per-site.
- Version comparison is `version_compare()`, so headers should be numeric and
  increasing. `1.00` works but `1.0.0` is friendlier.

## Overriding per site

```php
// Track a branch on one site only.
add_filter( 'chips_pm_manifest', function ( $plugins ) {
    if ( isset( $plugins['chips-duplicator'] ) ) {
        $plugins['chips-duplicator']['branch'] = 'staging';
    }
    return $plugins;
} );

// Point at a different manifest entirely.
add_filter( 'chips_pm_manifest_url', fn() => 'https://example.com/plugins.json' );
```

## Local development

`./sync-to-sandbox.sh` copies this plugin into `~/Websites/afam-sandbox`
(real files, not a symlink, so self-update behaves as it will in production).
