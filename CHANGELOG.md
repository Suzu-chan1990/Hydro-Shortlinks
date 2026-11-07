# Changelog

All notable changes to Hydro Shortlinks.

## [3.1.0] - Privacy

### Security

* **Link passwords are hashed:** passwords were stored in plain text in `{prefix}shortlinks.password` and were part of the CSV export. They are now stored with `password_hash()` and checked with `password_verify()`. Existing passwords are converted by a background job right after the update; a password that is used before the job reaches it is converted on that first use. Protected links keep working with the same `?pw=…`.
* **CSV export:** the `password` column is replaced by `password_protected` (`yes` / `no`). The CSV import still accepts plain-text passwords and hashes them.
* **Edit drawer:** the password is no longer sent to the browser. The field stays empty: leave it empty to keep the password, type a new one to change it, or tick "Remove password".

### Privacy

* **IPv6:** shortened to the `/64` network (interface identifier zeroed) instead of replacing only the last block. IPv4 stays at `/24`.
* **Referrer:** only scheme and domain are stored (`https://t.co`). Paths and query strings can contain personal data and were never used by the statistics.
* **Existing data:** a one-time background job (every 5 minutes, in id ranges by primary key) shortens referrers and IPv6 addresses in existing click rows. Statistics stay the same. Progress and a manual run are in Settings → Maintenance.
* **CSV export option** "Shorten IPs further": IPv4 `/16`, IPv6 `/48` (the old pattern produced invalid addresses for compressed IPv6).
* **README:** "Privacy first" now says exactly what is stored per click.

### Geo lookup

* **Local GeoIP database:** countries come from "IP to Country Lite" by DB-IP (CC BY 4.0), read with the bundled MaxMind DB reader (`lib/maxmind-db-reader`, Apache-2.0). IP addresses are no longer sent to ip-api.com over unencrypted HTTP. The database is downloaded on the server into `wp-content/uploads/hydro-geoip/` (kept on plugin updates) and renewed automatically when it is older than 30 days. Settings → Maintenance shows its edition and has a download button. Without the database, no lookups run.
* **The backlog can no longer get stuck:** every IP of a batch gets a value in the same run (the country, or `--` for invalid, private and unknown addresses). Clicks without an IP were skipped before and stayed "without country" forever. Batches are now 1,000 IPs and an hourly run handles up to 20 of them (no rate limit, no `sleep()`).
* **Geo Targeting module:** uses the local database and the shortened IP.


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
