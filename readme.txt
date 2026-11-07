=== Hydro Shortlinks ===
Contributors: saguya
Tags: shortlink, url shortener, redirect, analytics, qr code
Requires at least: 7.1
Tested up to: 7.2
Requires PHP: 8.4
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shortlink manager with dashboard, link management, analytics, QR codes and a module system.

== Description ==

Hydro creates short links on your own domain (https://example.com/abcd), tracks clicks and shows
where they come from. Built for large installations (1M+ links, millions of clicks).

* Dashboard with live clicks, KPIs, trend, top links and health checks.
* Link management: search, filters, bulk delete, redirect type, expiry, password, UTM tags, QR codes, CSV import/export.
* Analytics: period comparison, weekday x hour heatmap, devices, browsers, systems, sources, countries.
* WP-CLI: `wp hydro status|index|rollup|geo|prune|create|top`.

Privacy: IPs are shortened before they are stored (IPv4 /24, IPv6 /64), referrers are stored as domain only,
link passwords are stored as hashes, countries are looked up locally.

== External services ==

Hydro downloads the free "IP to Country Lite" database from DB-IP (https://download.db-ip.com/free/) to look up the
country of a click on your own server. The file is checked daily and downloaded again when the local copy is older
than 30 days, or when you click "Download database" in Settings. No IP addresses or other visitor data are sent.
IP Geolocation by DB-IP (https://db-ip.com), licensed under CC BY 4.0. Terms: https://db-ip.com/tos

== Changelog ==

= 3.1.0 =
* Link passwords are stored as hashes; existing passwords are converted in the background. The CSV export only says whether a link is protected.
* IPv6 is shortened to /64, referrers are stored as domain only; existing click rows are cleaned up in the background.
* Countries come from a local GeoIP database (DB-IP Lite) instead of ip-api.com over HTTP.
* The geo lookup can no longer get stuck on the same rows (clicks without an IP were never resolved).

= 3.0.2 =
* New banner and icon; the admin header shows the plugin banner (same layout as Tegatai Secure).

= 3.0.1 =
* Plugin Check: all database queries prepared (table names via %i), late escaping, nonce checks in every AJAX handler,
  sanitized server variables, no direct mysqli calls, CSV handling via SplFileObject, plugin and readme headers.
* Report engine reads clicks in chunks of 20,000 by primary key instead of an unbuffered mysqli stream.

= 3.0.0 =
* New admin in the Tegatai design: dashboard, links, analytics, extensions, settings.
* Daily statistics table and URL index table (additive, CREATE only – existing tables are never altered).
* Analytics and Advanced Analytics modules merged into the core.
* Click retention, maintenance actions, WP-CLI commands.
