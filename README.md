# Unstoppable Site Manager

Manage all your clients' WordPress sites from your own WordPress site: updates, plugins, health, vulnerability checks, one-click sign-in and branded monthly client reports. Self-hosted, free, no outside service.

Pair it with **[Unstoppable Site Connector](https://wordpress.org/plugins/unstoppable-site-connector/)** (free on WordPress.org) on each client site.

## Features

- **Sites at a glance:** a card per site with screenshot, pending updates, WordPress/PHP versions, SSL expiry and last report.
- **Updates:** one item, one site, or everything everywhere. Refresh checks four sites at a time.
- **Plugins:** one list of every plugin on every site. Activate, deactivate, update, delete, or install from WordPress.org, a URL or a ZIP.
- **Vulnerability checks** (optional) with the free [WPVulnerability](https://www.wpvulnerability.net/) database.
- **One-click sign-in:** single-use links that expire after 60 seconds. No passwords are stored.
- **Client reports:** a branded monthly Website Care Report with a health verdict, updates, other changes, work notes and a personal note. Print it, save it as PDF, or email it, by hand or on a schedule.
- **Bulk connect:** moving from MainWP or ManageWP? Make a connector that knows your dashboard, push it to all your sites with that tool's bulk plugin install, and every site connects itself.
- **Security screen:** lock client sites to your dashboard's IP, keep the signing key in `wp-config.php`, choose which administrators may use the dashboard, and see each site's own security settings.

## Install

1. Download the latest ZIP from [Releases](../../releases) and install it on **your own** WordPress site (Plugins > Add New > Upload Plugin).
2. Install Unstoppable Site Connector on each client site.
3. Connect each site: generate a connection key on the client site (Tools > Unstoppable Site Connector) and paste it into Unstoppable Site Manager > Add Site. For many sites, use **Add Site > Connect many sites at once**.

Requires WordPress 6.2+ and PHP 7.4+.

## How the connection works

- The dashboard and each site are paired once with a one-time code. After that, every request is signed with Ed25519, carries a timestamp and a single-use nonce, and expires after 5 minutes.
- No usernames or passwords are stored or sent.
- Each client site keeps its own security settings: allowed dashboard IPs, which actions the dashboard may take, and alert emails. The dashboard can only make these stricter.
- Plugin files are only ever installed from WordPress.org or from your own dashboard.

The full protocol is described in the connector repository.

## Security

Please report security issues privately. See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [license.txt](license.txt).

Made by [Unstoppable Solutions](https://unstoppablesolutions.app).
