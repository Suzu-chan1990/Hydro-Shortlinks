# Contributing to Hydro Shortlinks

Contributions are welcome.

## How to contribute
1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Submit a pull request

## Guidelines
- Follow WordPress coding standards where practical; the plugin must keep passing the official Plugin Check
- **Never alter the existing tables** (`{prefix}shortlinks`, `{prefix}shortlink_clicks`). New features may only add new tables (zero-downtime rule)
- Keep large installations in mind (1M+ links, millions of clicks): no full-table scans on page views, cache expensive reads
- Keep the redirect path lean – it runs on every request
- Write code comments in English
- Avoid unnecessary dependencies and external requests
- Write clear commit messages

## Reporting issues
Please include:
- WordPress version
- PHP and MySQL/MariaDB version
- Number of links and clicks (roughly)
- Steps to reproduce
- Error messages/log snippets (if any)
