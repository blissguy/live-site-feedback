# Live Site Feedback

Let clients leave comments directly on a WordPress site, pinned to exactly what they're looking
at. Comments are collected in a [MarkUp.io](https://www.markup.io/) workspace.

No more "the button on the third section down looks off" in an email thread.

---

## Installing

1. Download `mbm-live-feedback-x.y.z.zip` from the
   [latest release](https://github.com/blissguy/live-site-feedback/releases/latest).
2. In WordPress go to **Plugins → Add New → Upload Plugin** and pick the zip.
3. Activate it, then go to **Live Feedback** in the admin menu.
4. Paste your MarkUp.io API key, save, then press **Set up automatically**.

That last step registers the site with MarkUp.io, generates its own signing key, and reads back
everything else it needs. Nothing has to be copied between browser tabs.

### Updating

The plugin updates itself. New releases show up on the **Plugins** screen like any other update
— no downloading and re-uploading. There's also a **Check for updates now** button on the
settings screen if you don't want to wait for WordPress's own schedule.

Updates are read from this repository's public releases, so no token or account is involved.

---

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A MarkUp.io account with SDK access
- **A publicly reachable site.** MarkUp.io loads each page itself when setting it up, so a local
  address like `mysite.local` is refused with "URL did not resolve". Connecting the account works
  fine locally; setting up individual pages does not.

---

## How it's put together

| File | Responsibility |
| --- | --- |
| `includes/class-mbm-lf-credentials.php` | Credential storage. A `wp-config.php` constant always beats the database; secrets are encrypted at rest. |
| `includes/class-mbm-lf-options.php` | Plugin preferences, kept separate from credentials so the front end doesn't read the credential store on every request. |
| `includes/class-mbm-lf-api-client.php` | MarkUp.io REST client. Pins the API version and keeps `requestId` on errors. |
| `includes/class-mbm-lf-provisioner.php` | One-click setup: keypair, installation, token settings. |
| `includes/class-mbm-lf-markups.php` | Creates a MarkUp.io entry per page, with locking and backoff. |
| `includes/class-mbm-lf-post-meta.php` | The per-page panel in the editor. |
| `includes/class-mbm-lf-frontend.php` | Decides whether to show the feedback bar, and loads it. |
| `includes/class-mbm-lf-settings.php` | Admin screen. |
| `includes/class-mbm-lf-updater.php` | Updates from GitHub releases. |

### Configuring through `wp-config.php`

Every credential can be set as a constant instead of being stored in the database. A constant
always wins, and the matching field in the admin becomes read-only so it's obvious where the
value came from.

```php
define( 'MBM_LF_API_KEY', 'sk_...' );
define( 'MBM_LF_PUBLIC_KEY', '...' );
define( 'MBM_LF_PRIVATE_KEY_PATH', '/path/to/signing-key.pem' );
define( 'MBM_LF_ISS', 'https://api.markup.io' );
define( 'MBM_LF_AUD', 'https://api.markup.io/v2/auth/exchange' );
```

`MBM_LF_GITHUB_REPO` overrides where updates are fetched from, should the repository ever move.

### Filters

| Filter | Purpose |
| --- | --- |
| `mbm_lf_should_render` | Force the feedback bar on or off for a request. |
| `mbm_lf_user_can_see` | Decide who sees it, beyond the built-in options. |
| `mbm_lf_markup_id` | Change which MarkUp.io entry a request uses. |
| `mbm_lf_render_options` | Change the SDK's render options — most usefully `commentableContainer`, to stop visitors pinning comments onto site furniture. |
| `mbm_lf_allowed_origins` | Add origins before running setup. Registrations can't be edited afterwards. |
| `mbm_lf_api_base_url` | Point at a different MarkUp.io environment. |
| `mbm_lf_github_repo` | Change where updates come from. |

---

## Releasing

Pushing to `main` with a changed version publishes a release automatically. The workflow refuses
to run unless all of these agree:

- `Version:` in `mbm-live-feedback.php`
- `version` in `package.json`
- `Stable tag:` in `readme.txt`
- the newest `= x.y.z =` heading in the `readme.txt` changelog

Versioning is strict semver: a patch is fixes only, a minor is any new functionality.

---

## Notes on the MarkUp.io SDK

A few things worth knowing, none of which match the published documentation:

- The documented CDN URL (`v1.0.0`) **404s**. `1.0.0-rc.1` is the only published build, and the
  install snippet the MarkUp.io dashboard generates points at the dead one.
- `POST /api/v2/auth/exchange` is documented as requiring the secret API key. It does not — and
  sending it makes the call fail with a 401. The browser authenticates with the public key in
  the request body.
- Installations can't be edited. Changing the allowed origins means creating a new one, which
  issues a new public key — hence one installation per environment.
- `GET /api/v2/auth/config` returns undocumented rate limits: 1000 requests and 100 sign-ins per
  minute.

The library is pinned to an exact version with an integrity hash, so if the file ever changes
underneath us the browser refuses to run it rather than executing something unverified.

---

## Licence

GPL-2.0-or-later. By [Mixbus Marketing](https://mixbusmarketing.com/).
