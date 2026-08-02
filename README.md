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
| `includes/class-mbm-lf-tokens.php` | Signs the short-lived tokens that let WordPress users comment as themselves. |
| `includes/class-mbm-lf-rest.php` | The token endpoint the feedback bar calls. |
| `includes/class-mbm-lf-webhooks.php` | Receives change notifications and registers for them. |
| `includes/class-mbm-lf-threads.php` | Reads comments back from MarkUp.io, cached, grouped by page. |
| `includes/class-mbm-lf-feedback-screen.php` | The Feedback screen and dashboard widget. |
| `includes/class-mbm-lf-admin-bar.php` | The Feedback item in the toolbar. |
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
| `mbm_lf_render_options` | Change the SDK's render options. Comments can be left anywhere by design; `commentableContainer` can fence them into one area, but note it is an allow-list, so the header and footer would stop accepting comments too. |
| `mbm_lf_allowed_origins` | Add origins before running setup. Registrations can't be edited afterwards. |
| `mbm_lf_api_base_url` | Point at a different MarkUp.io environment. |
| `mbm_lf_github_repo` | Change where updates come from. |
| `mbm_lf_token_claims` | Change the claims placed in a visitor's identity token. |
| `mbm_lf_user_role` | Change the MarkUp.io role claimed for a user. |

There is also one action, `mbm_lf_webhook_received`. Its payload is unauthenticated — MarkUp.io
does not sign deliveries — so treat it as a hint that something changed and read the real state
back through the API before acting on it.

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

Several things about MarkUp.io do not match its documentation, and this plugin works around
them. They are written up in **[UPSTREAM.md](UPSTREAM.md)** — what happens, what we do instead,
what to change once it is fixed, and how to check.

Worth re-reading whenever MarkUp.io ships a release. The SDK is still `1.0.0-rc.1`, so some of it
may resolve at 1.0.0 GA.

The browser library is pinned to an exact version with an integrity hash, so if the file ever
changes underneath us the browser refuses to run it rather than executing something unverified.

---

## Licence

GPL-2.0-or-later. By [Mixbus Marketing](https://mixbusmarketing.com/).
