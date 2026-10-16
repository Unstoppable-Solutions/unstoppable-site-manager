=== Unstoppable Site Manager ===
Contributors: paramedic192
Donate link: https://unstoppablesolutions.app/donate
Tags: manage multiple sites, updates, maintenance, client reports, agency
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.15.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage all your clients' WordPress sites from your own WordPress site: updates, plugins, health, one-click sign-in and client reports.

== Description ==

Unstoppable Site Manager turns your own WordPress site into a dashboard for every client site you look after. Install it on your site, install the free [Unstoppable Site Connector](https://wordpress.org/plugins/unstoppable-site-connector/) on each client site, and pair them with a one-time key.

* **Sites at a glance** – a card for every site with pending updates, WordPress and PHP versions, SSL expiry, when it last reported and, if you turn screenshots on, a picture of its home page. Tiles at the top count plugin, theme and WordPress updates across all sites. Star your most important sites to pin them to the top.
* **Updates** – update one plugin on one site, everything on one site, or everything everywhere. Opening a site checks it for updates straight away.
* **Plugins** – one list of every plugin on every site. Activate, deactivate, update or delete across the sites you pick, or install new plugins from the WordPress.org directory, a URL or a ZIP you drop in.
* **Vulnerability checks** (optional) – flags plugins, themes and WordPress versions with known security problems, using the free WPVulnerability database, with severity and the version that fixes each one.
* **One-click sign-in** – open any client's wp-admin already signed in. No passwords are stored; each sign-in link works once and expires after 60 seconds.
* **Client reports** – a monthly Website Care Report for each site, with your logo and brand color: a plain-English health verdict, a health checklist, one tidy line per update, the month's other changes, the other work you did for them and an optional personal note. Print it, save it as PDF, or email it to your client, by hand or automatically each month.
* **Bulk connect** – moving from MainWP or ManageWP? Make a connector that knows your dashboard, push it to all your sites with that tool's bulk plugin install, and every site connects itself. Upload MainWP's site export to see which ones are still missing.
* **Security you can check** – a Security screen with a checklist for your dashboard, one-click locking of every client site to your dashboard's address, and per-site permission switches. Details below.
* **Built not to time out** – bulk jobs run one site at a time from your browser, and a site that fails is reported and skipped. Client sites push their own reports, so your dashboard isn't polling all day.

**How it differs from other site managers**

* **Self-hosted, no outside service.** The dashboard is a plugin on your own WordPress site. Your client list, keys and reports never pass through Unstoppable Solutions or any other company's servers.
* **Complete and free.** Every feature described here is in this plugin. There are no paid extensions, no add-on store and no license key.
* **Client reports included.** Branded monthly Website Care Reports, built from each site's own activity log, ready to print, save as PDF or email on a schedule.
* **Finds updates for paid plugins that hide them.** Update checks run inside each client site's admin area, so plugins that only look for updates there (such as Crocoblock's Jet plugins) are found and updated too.
* **Client sites keep control.** Each site's own security settings can only be made stricter from the dashboard (see Security below).

**Security**

Your dashboard can update and sign in to every connected site, so it is built to be hard to abuse even if something goes wrong:

* **No client passwords are stored.** Each site trusts your dashboard's public key. Every request is signed, expires after 5 minutes and can only be used once.
* **Client sites have the last word.** Each site's connector has its own security settings: allowed dashboard addresses, what the dashboard may do, and alert emails. From the dashboard they can only be made stricter. Loosening them is only possible in that site's own wp-admin. (One-click login and installing plugins from a ZIP each give the dashboard full control of a site, so switch them off where you don't need them.)
* **Lock sites to your dashboard's address.** One click locks every site to the address your dashboard's requests come from, so a stolen key is useless anywhere else. A site that refuses a correctly signed request tells you about it, by email and on your dashboard.
* **Keep the signing key out of the database.** Move it to wp-config.php, so a leaked backup or an SQL injection in another plugin can't be used to control your sites. If the key ever goes missing, the dashboard stops and says so; it never quietly makes a new one.
* **Choose who can use it.** Limit the dashboard to chosen administrator accounts. Others don't see it and can't send anything to your sites.
* **A checklist** for HTTPS, two-factor sign-in and file editing, with clear steps when something needs attention.

Previously released as FM Dashboard. Installing Unstoppable Site Manager on a site that has FM Dashboard carries over every site, key, screenshot and activity entry, so client sites stay connected; you can then deactivate and delete FM Dashboard.

== Installation ==

1. Install and activate Unstoppable Site Manager on your own site.
2. On each client site, install and activate [Unstoppable Site Connector](https://wordpress.org/plugins/unstoppable-site-connector/) (Plugins > Add New, search for it) and go to Tools > Unstoppable Site Connector > Generate connection key.
3. Paste the key into Unstoppable Site Manager > Add Site.

== Frequently Asked Questions ==

= Does it store my clients' passwords? =

No. Your dashboard and each client site are paired with key pairs, and every request is signed. Changing passwords or usernames on a client site doesn't affect the connection.

= Who can use it? =

Administrators of the site it is installed on, or only the administrators you choose under Unstoppable Site Manager > Security. Run it on its own WordPress site rather than your business website, keep plugins there to a minimum, and use two-factor sign-in.

= What happens if my dashboard site gets hacked? =

It is the most important site you run, so protect it like one (the Security screen has a checklist). But it is also built so that a stolen key isn't enough: lock your client sites to the dashboard's address and a request signed with the key from anywhere else is refused and reported. Each client site can also switch off risky actions, such as installing plugins from a ZIP file, and only someone with admin access to that site can switch them back on. If you think the dashboard was compromised, the Security screen walks you through disconnecting your sites and starting over with a new key.

= How do I report a security problem? =

Please email support@unstoppablesolutions.app privately with the details instead of posting publicly. You'll get a reply within two business days.

== External Services ==

This plugin connects to the following services.

**Your client sites (Unstoppable Site Connector).** To show status, run updates, manage plugins and sign you in, the dashboard sends signed requests to each client site you paired, and receives status reports from them. Nothing is sent to Unstoppable Solutions.

**WordPress.com mShots** (optional screenshot service, off by default; turn it on under Settings > Screenshots). Only when you turn it on, the public address of each client site is sent to `https://s0.wp.com/mshots/v1/` to show a thumbnail of its home page (when a site is added and weekly). Provided by Automattic: [Terms of Service](https://wordpress.com/tos/), [Privacy Policy](https://automattic.com/privacy/).

**A screenshot service of your choice** (optional). If you enter your own screenshot API address in Settings, client site addresses are sent to it instead. That service's own terms apply.

**WPVulnerability** (optional, off until you turn on Vulnerability checks in Settings). To flag plugins, themes and WordPress versions with known security problems, their names (slugs) and WordPress version numbers are sent to `https://www.wpvulnerability.net/`. Client site addresses are not sent. Each item is looked up at most every 12 hours. [Website and terms](https://www.wpvulnerability.com/).

**WordPress.org Plugin Directory.** When you search for plugins on Plugins > Add plugin, your search terms are sent to `https://api.wordpress.org/plugins/info/` and plugin icons load from WordPress.org. [Privacy Policy](https://wordpress.org/about/privacy/).

**WordPress.org Plugin Directory downloads.** The Add Site screen's "Download the connector ZIP" button, and the bulk-connect connector, use the current Unstoppable Site Connector release from `https://downloads.wordpress.org/`. The bulk-connect copy is downloaded only when you click its download button.

**Temporary plugin hosting on your own site.** A ZIP you drop on Plugins > Add plugin, or one you install by web address (which this dashboard downloads from that address once), is kept in this plugin's own database table for up to 6 hours at an unguessable address so your client sites can download it, then deleted. No plugin files are written to your uploads folder. Client sites only install plugin files from WordPress.org or from your dashboard.

== Changelog ==

= 0.15.1 =
* A dashboard page left open for a day no longer shows every site as failing: when WordPress's security token expires, the page gets a new one and carries on. If you have been signed out, it says so once instead.

= 0.15.0 =
* Screenshots are now off until you choose a screenshot service in Settings, because the service receives your client sites' addresses.
* Plugin ZIPs held for client sites are now kept in the database instead of the uploads folder, and are deleted after 6 hours. Old copies in uploads/usmd-packages and uploads/usmd-connector are removed.
* The connector ZIP now comes straight from WordPress.org: Add Site links to the WordPress.org download, and the bulk-connect connector is built from it when you download it.
* The menu item now sits below the core WordPress menus.
* Requires WordPress 6.2 or newer.

= 0.14.1 =
* Unstoppable Site Connector is now on WordPress.org: Add Site links to its listing and opens the client site's Add Plugins screen searched for it.

= 0.14.0 =
* Bulk connect (Add Site > Connect many sites at once): make a connector that knows this dashboard, install it on all your sites with MainWP, ManageWP or any bulk tool, and each site connects itself. Time-limited, site-limited and cancellable.
* Check against your MainWP list: upload MainWP's site export to see which sites have not connected yet.

= 0.13.3 =
* Installing a plugin by web address: the dashboard downloads it once and client sites fetch it from the dashboard. FM Dashboard is no longer switched off automatically.

= 0.13.2 =
* Faster refresh: "Refresh all" checks four sites at once (updates run two at a time), vulnerability lookups run side by side with a shorter timeout and back off for an hour when the service fails, and the activity request is skipped when a site has nothing new.

= 0.13.1 =
* "Tested up to" now lives only in readme.txt, as WordPress.org requires.

= 0.13.0 =
* Work notes: record work you did for a client outside of updates (task name, date, description) from the report screen. Tasks appear in a "Work we did for you" section on that month's report and report email.

= 0.12.2 =
* Email this report: the address you type is saved as the client's report email (unless you untick it), and you can switch on the monthly automatic email right there.

= 0.12.1 =
* WordPress.org readiness: Plugin Check fixes (translator comments, prepared SQL, WordPress file functions), a languages folder, and a security contact.

= 0.12.0 =
* New Security screen: a checklist for the dashboard, who may use it, moving the signing key to wp-config.php, and every client site's connector security settings.
* Lock all client sites (or one) to this dashboard's address in one click. Sites that refuse a correctly signed request from elsewhere are flagged on the dashboard.
* Switch off one-click login, updates, plugin changes or installs on any site from its page. Only the site itself can switch them back on.
* The signing key can live in wp-config.php (USMD_SECRET_KEY). A missing key now stops signing with a clear message instead of making a new key.
* Limit the dashboard to chosen administrators (or USMD_ALLOWED_USERS in wp-config.php).
* Client sites' security alerts name the dashboard user who acted.
* Needs Unstoppable Site Connector 0.5.0 on each site for the new site controls.

= 0.11.5 =
* Add Site: removed the WordPress.org link until the connector is listed there.

= 0.11.4 =
* Add Site: clear "Save the connector here for future sites" steps, a reminder to click Save after choosing the file, and a confirmation showing the saved version and date.

= 0.11.3 =
* Add Site: the connector page is now a Download button, and the "keep a copy" box explains it only stores the ZIP on your dashboard.

= 0.11.2 =
* Reports tell you (on screen only) when no logo is saved or the saved logo can't be loaded, and why.

= 0.11.1 =
* Choose the report logo from the Media Library, with a live preview and a warning when an address doesn't load as an image.
* If a report logo can't load, the agency name is shown instead.

= 0.11.0 =
* Add Site keeps a copy of the Unstoppable Site Connector ZIP to download any time: upload it once, and pushing a newer connector to your sites keeps it current.
* The client-site shortcut on Add Site now opens the site's Upload Plugin screen.

= 0.10.2 =
* New "Your email" setting for report test copies, blind copies and the default From and reply address, instead of always using the site's admin email.

= 0.10.1 =
* Report header setting: show your logo and agency name, the logo only, or the name only, on reports and report emails.

= 0.10.0 =
* Email client reports: send any report by email (or a test to yourself), and send last month's report automatically on a chosen day each month.
* Client details on each site: contact name, report email addresses and an automatic-email switch.
* New Reports page: every site's report contact, schedule and last send, with View and Send buttons.

= 0.9.0 =
* Optional vulnerability checks using the free WPVulnerability database: a badge on site cards, a Vulnerable items tile, a Security section on each site page with severity and the version that fixes each issue, and a line in client reports.

= 0.8.1 =
* Works with Unstoppable Site Connector 0.4.1, which finds and installs updates for paid plugins that only check for updates inside wp-admin (such as Crocoblock's Jet plugins).
* A site whose server blocks loopback requests now shows a warning on its page.

= 0.8.0 =
* Redesigned client report: health verdict and checklist, one line per updated item, grouped site changes, a personal note per report, report accent color and contact line in Settings, cleaner printing.
* Admin notices no longer appear inside reports.

= 0.7.0 =
* Renamed from FM Dashboard to Unstoppable Site Manager. Existing sites, keys, screenshots and activity are carried over automatically.
* Works with both Unstoppable Site Connector and the older FM Connector.

== Upgrade Notice ==

= 0.15.0 =
Screenshots are now opt-in (Settings > Screenshots). Requires WordPress 6.2.

= 0.12.0 =
Security screen: lock client sites to your dashboard, keep the key in wp-config.php, and choose who can use the dashboard. Update your sites to Unstoppable Site Connector 0.5.0 too.

= 0.8.0 =
A much better-looking client report, with your brand color and a note to each client.

= 0.7.0 =
FM Dashboard is now Unstoppable Site Manager. Install it, and your sites come across automatically.
