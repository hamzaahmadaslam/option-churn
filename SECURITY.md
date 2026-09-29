# Security policy

## Reporting a vulnerability

Please report security problems privately through GitHub: open the repository's **Security** tab and choose
**Report a vulnerability**. Do not open a public issue for a security problem.

You will get a reply within seven days. Fixes are released as a new version with a note in the changelog.

## What this project does with your data

- It stores running totals in three tables of the site's own database: option names, request types, the calling
  plugin, theme or core function with its file and line, byte counts, and the names of top-level keys that changed.
  It never stores option values.
- The report is shown to users with the `manage_options` capability and in WP-CLI. Deleting the plugin drops the
  tables.
- It uses no API key and makes no network requests: no telemetry, no update checks.

## Supported versions

Only the latest release receives fixes.
