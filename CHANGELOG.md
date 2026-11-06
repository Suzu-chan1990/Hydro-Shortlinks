# Changelog

All notable changes to Hydro Shortlinks.

## [3.0.2] - Banner and Icon

### New

* **Banner:** The header of every Hydro admin page shows the new Hydro Shortlinks banner (`assets/banner-admin.png`) on a light cyan-to-lavender background, the same layout as Tegatai Secure.
* **Icon and banners:** `assets/icon-256x256.png`, `assets/icon-128x128.png`, `assets/banner-1544x500.png` and `assets/banner-772x250.png` for WordPress.org and GitHub.
* **README:** GitHub README with screenshots, CHANGELOG, CONTRIBUTING, SECURITY and LICENSE.

## [3.0.1] - Plugin Check

### Changed

* All database queries are prepared; table names are passed with the `%i` placeholder. **Requires WordPress 6.2 or newer.**
* Optional filters (link, country, search) are part of one fixed SQL string; unused conditions are removed by the database optimizer, index usage is unchanged.
* Every AJAX handler checks its nonce and the `manage_options` capability itself.
* `$_SERVER`, `$_GET`, `$_POST` and `$_FILES` values are unslashed and sanitized; IDs in the HTML output are escaped.
* The report engine reads clicks in chunks of 20,000 by primary key (no direct mysqli calls). User agents and referrers are classified in PHP (memoized).
* CSV export and import use `SplFileObject`.
* The redirect handler uses `wp_parse_url()`.
* Social cards module: helper functions renamed to `hydro_social_cards_*`.
* Plugin header: license, text domain `hydro-shortlinks`, `Requires at least: 6.2`. Readme header completed.

### Performance

* Aggregating a busy day (~42,000 clicks): 1.4 s → 0.27 s.
* Rebuilding all daily statistics for 400 days / 1.9M clicks: ~6 min → 10 s.

### Fixes

* The CSV import accepted values like `not-a-url` (turned into `http://not-a-url`); they are now counted as invalid.

## [3.0.0] - New Admin and Analytics

### New

* **Admin in the Tegatai design:** dark header with version badge, tab navigation (Dashboard, Links, Analytics, Extensions, Settings), card grid, switches, toast messages.
* **Dashboard:** clicks today vs. yesterday at the same time, KPIs, 30-day trend, top links, live click feed, quick shortlink with QR code, health checks.
* **Links:** search (also by pasted short URL), filters, 20/50/100 per page, bulk delete, create with redirect type / expiry / password / UTM, details drawer with 30-day stats, edit, target check, QR code (PNG/SVG), CSV export and import.
* **Analytics (core):** presets and custom ranges, comparison with the previous period, weekday × hour heatmap, devices, browsers, operating systems, sources, countries with flags, top links, link and country filter, raw click CSV export.
* **Daily statistics table** (`{prefix}hydro_stats_daily`): finished days are aggregated hourly; reports over months read a few thousand rows instead of millions. Statistics survive the click retention.
* **URL index table** (`{prefix}hydro_url_index`): exact, case-sensitive lookup of existing shortlinks as a primary-key query (~500 ms → ~1 ms with 1M links). Backfilled in the background.
* **Click retention:** optional, deletes raw click rows older than N days (minimum 35) in batches of 5,000.
* **Maintenance:** status and manual runs of URL index, daily statistics, geo lookup and retention; table sizes.
* **WP-CLI:** `wp hydro status|index|rollup|geo|prune|create|top`.
* **REST:** `GET /wp-json/hydro/v1/report`.

### Changed

* The modules **Analytics** and **Advanced Analytics** are merged into the core; their files remain as empty placeholders so an upgrade by copying replaces them.
* `hydro_create_or_get_shortlink()` uses the URL index; signature and return value are unchanged.
* Zero downtime: the tables `{prefix}shortlinks` and `{prefix}shortlink_clicks` are never altered. New tables are only created when they do not exist.

### Fixes

* The old lookup compared URLs case-insensitively and could return the shortlink of a different YouTube video whose ID only differed in letter case.
* The settings form on the old links page posted to an action without a handler.
