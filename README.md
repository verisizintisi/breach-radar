# Breach Radar (WordPress Plugin)

English | [Türkçe](README.tr.md)

Protect your WordPress users by checking their email addresses against known data breaches via the verisizintisi.com API. Breach Radar shows concise risk summaries in your WordPress admin and helps you act on increases.

- WordPress.org plugin name: Breach Radar via verisizintisi.com
- Text Domain: `breach-radar`
- Minimum WP: 5.6 • Tested up to: 6.8 • Requires PHP: 7.2+
- License: GPL-2.0-or-later

## Features
- Dashboard overview with risk summary and insights
- Manual scans and scheduled daily scans (self-healing if missed)
- Logs with filters (email, found, HTTP status, date range)
- Admin notifications when breach counts increase (configurable threshold)
- Protection badge shortcode + Theme Customizer integration
- i18n: English and Turkish included; Azerbaijani and Russian supported via PO files
- Security-first: capability checks, nonces, sanitized/validated inputs, escaped outputs

## How it works
1. Get an API key from `get.verisizintisi.com/wordpress` and paste it in Settings.
2. Trigger scans manually or enable the daily cron job.
3. The plugin securely sends your site domain and the selected email addresses to the API.
4. The API authenticates, rate-limits, and returns per-email presence and counts. It does not return breach contents.
5. The plugin stores summarized logs locally and presents insights in the dashboard.

## Security and privacy
- No tracking scripts are injected into your site.
- Visitors are not tracked. Scans run only when you initiate them or via your scheduled task.
- Inputs are sanitized/validated; outputs are escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- HTTP host is derived via a safe helper instead of raw `$_SERVER`.
- Review: `https://verisizintisi.com/privacy` • `https://verisizintisi.com/terms`

## Installation
### From WordPress admin
1. Plugins → Add New → Upload Plugin
2. Select the plugin ZIP → Install Now → Activate
3. Go to Breach Radar → Settings and paste your API key

### Manual (from this repository)
- Copy the contents of the `wordpress/` directory into `wp-content/plugins/breach-radar/` on your WordPress site (the folder name can be `breach-radar`).
- Or, zip the `wordpress/` directory and upload it via the “Upload Plugin” flow.

## Configuration
- Get your API key at `get.verisizintisi.com/wordpress`.
- In WordPress: Breach Radar → Settings → paste your API key.
- Optional: configure scan filters (roles, excluded emails), enable daily scan, set increase threshold, select language.

## Usage
### Dashboard
- See Last Scan, Found Today, connectivity status, insights, and a 7-day summary.

### Manual scan
- Breach Radar → Scan → “Scan Users” (nonce-protected).

### Daily scan
- Enabled by default; the plugin self-heals if a scheduled event was missed.
- You can toggle daily scans in Settings.

### Logs
- Breach Radar → Logs: filter by email, found/none, HTTP, dates. Uses prepared SQL with placeholders and short-lived caching for metrics.

### Protection badge
- Shortcode:

```shortcode
[verisizintisi_badge size="medium" theme="light" align="left" lang="auto"]
```

- Theme customizer: Appearance → Customize → Breach Radar Badge
- In PHP templates:

```php
<?php echo do_shortcode('[verisizintisi_badge size="small" theme="dark" align="center"]'); ?>
```

## Internationalization (i18n)
- Text Domain: `breach-radar` (auto-loaded from WordPress.org translations)
- Bundled: English and Turkish. PO fallbacks for `az_AZ` and `ru_RU` under `wordpress/languages/`.
- Plugin UI language can be forced at Settings → Language. Default is Auto (follows site language).

## Screenshots (suggested)
- Dashboard overview and insights
- Logs with filters
- Badge examples

## Changelog
See `wordpress/readme.txt` for the authoritative plugin changelog (mirrored on WordPress.org). Notable recent:
- 1.0.1: Compliance and security improvements (escaping, sanitization, prepared queries, `wp_rand`, `gmdate`, `wp_parse_url`), self-healing daily scans, i18n fixes.

## Development
- Primary plugin entry: `wordpress/verisizintisi-plugin.php`
- Languages: `wordpress/languages/`
- No build step required; the plugin ships as plain PHP + WordPress APIs.

### Coding standards
- Follow WordPress Coding Standards. Use nonces for state-changing actions and `current_user_can('manage_options')` for admin pages.
- Sanitize early, validate always, escape outputs.

## Contributing
Issues and PRs are welcome. Please:
- Keep changes minimal and focused
- Adhere to WordPress coding standards
- Include before/after context and testing notes

## License
GPL-2.0-or-later. See the LICENSE file if provided, or the license header in `verisizintisi-plugin.php`.

## Links
- Website: `https://verisizintisi.com`
- API keys: `https://get.verisizintisi.com/wordpress`
- Verification page format (badge): `https://verisizintisi.com/verify-protection/url/{host[/path]}`
