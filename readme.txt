=== Hydro Shortlinks ===
Contributors: saguya
Tags: shortlink, url shortener, redirect, analytics, qr code
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 3.0.2
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

Countries are resolved with the free batch endpoint of ip-api.com (only shortened IP addresses are sent).

== Changelog ==

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
