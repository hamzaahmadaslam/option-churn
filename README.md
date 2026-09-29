# option-churn

A WordPress development plugin that counts how often each option is rewritten across requests, which plugin,
theme or core function writes it, and how often `update_option()` was called with a value that had not changed.

Large autoloaded options are a known cost, and tools exist to find them. Rewrites are a different one: a plugin that
saves a "last run" timestamp, a counter or a rebuilt cache array on every page view turns a read into an `UPDATE` on
the options table. On an autoloaded option each write also rewrites WordPress's cached copy of all autoloaded
options, and with a persistent object cache that is a large entry replaced on every request. Query Monitor shows the
queries of one request; this plugin adds up writes across many, so a report can say "written on 978 of 1,000
front-end requests, and only `last_sync` changed".

## What it records

For every option written during a request (`update_option()`, `add_option()`, `delete_option()`, transients stored
in the options table, and the network versions on multisite):

| Column         | Meaning                                                                                     |
| -------------- | ------------------------------------------------------------------------------------------- |
| `written_on`   | On how many requests of this type the option was written, out of all recorded requests     |
| `writes`       | Real writes (WordPress changed the row)                                                     |
| `noop_calls`   | `update_option()` calls WordPress skipped because the value was the same                    |
| `bytes_each`   | Average size of the serialized value written                                                |
| `autoload`     | The option's autoload value now: `on`, `off`, `auto`, `auto-on`, `auto-off`, or the older `yes` and `no` |
| `top_caller`   | The plugin, must-use plugin, theme or core function that wrote it most                      |
| `changed_keys` | For arrays and objects: which top-level keys changed, and in what share of writes           |

Request types are `front`, `admin`, `ajax`, `rest`, `cron`, `cli` and `xmlrpc`. The caller is the first call site
outside WordPress core, found with `debug_backtrace()`; a write that core makes on its own is shown as core with the
function that made it. A transient's timeout row is counted with the transient.

It stores running totals only: option names, key names, callers, counts and sizes. **It never stores option
values.** Totals live in three tables (`{prefix}option_churn_requests`, `_writes` and `_keys`), not in options, so the
plugin does not add to what it measures. At shutdown, each request adds its counts to the totals of each option and
caller it touched (a row is created the first time), in a few statements.

## Install

Put the repository in `wp-content/plugins/option-churn` and activate it:

```sh
git clone https://github.com/hamzaahmadaslam/option-churn.git wp-content/plugins/option-churn
wp plugin activate option-churn
```

WordPress 6.6 or later, PHP 7.4 or later. Activation creates the tables.

A normal plugin starts counting when WordPress loads it, so writes made while must-use plugins and plugins that sort
before `option-churn` are loading are missed. To count those too, load it from a must-use plugin,
`wp-content/mu-plugins/option-churn-loader.php`:

```php
<?php
require WP_PLUGIN_DIR . '/option-churn/option-churn.php';
```

and run `wp option-churn install` once to create the tables (activation does not run for must-use plugins).

## Use

Browse the site, run the test suite, or let real traffic reach a staging copy for a while. Then:

```sh
wp option-churn report                 # all request types, 20 rows
wp option-churn report --type=front    # front-end requests only
wp option-churn report --format=json   # everything, for scripts
wp option-churn status                 # recording on or off, requests recorded so far
wp option-churn reset --yes            # start again
```

The same report is under Tools, Option churn, for users who can `manage_options`. The plugin's own WP-CLI commands
are not recorded.

Settings are constants in `wp-config.php`, so changing them does not write an option:

| Constant               | Default | Meaning                                                                  |
| ---------------------- | ------- | ------------------------------------------------------------------------ |
| `OPTION_CHURN_RECORD`  | `true`  | `false` stops recording; the report stays                                |
| `OPTION_CHURN_SAMPLE`  | `1`     | Share of requests recorded, for example `0.1` on a busy staging copy     |
| `OPTION_CHURN_SKIP`    | none    | Request types not recorded, for example `'cli,cron'`                     |

## Example

WordPress 7.1.2 on MariaDB 11.8, with the synthetic must-use plugin in `tests/fixtures/churn-fixture.php` (it saves a
timestamp in `acme_heartbeat` on every request, calls `update_option( 'acme_static', 'same' )`, and sets a transient
in the footer), after 20 front-end page views:

```text
option                      type   written_on                 writes  noop_calls  bytes_each  autoload  top_caller                                  changed_keys
_transient_acme_menu_cache  front  20 of 20 requests (100%)   20      0           38          off       mu-plugin:churn-fixture.php {closure}       built 100%
acme_heartbeat              front  20 of 20 requests (100%)   20      0           61          off       mu-plugin:churn-fixture.php {closure}       last 100%
acme_static                 front  0 of 20 requests (0%)      0       20          0           auto      mu-plugin:churn-fixture.php {closure}
rewrite_rules               front  0 of 20 requests (0%)      0       20          0           on        core WP_Rewrite::refresh_rewrite_rules
```

The last row is WordPress itself: on this fresh install with plain permalinks, `rewrite_rules` is empty, so every
request rebuilds the rules and calls `update_option()` with the same empty value, which WordPress then skips. The
JSON form is in `examples/`.

## What to do with the report

- An option written on most requests with one changing key is usually a timestamp or counter. Store it with
  `wp_cache_set()` in a persistent object cache, write it at most once a minute, or move it out of the options
  table.
- A write on an autoloaded option costs more than one on an option with autoload `off`: consider turning autoload
  off for options that are written often and read rarely.
- Many `noop_calls` from a plugin mean it calls `update_option()` on every request without checking. The write is
  skipped, but the value is still compared, and on an option that is not autoloaded it may be read from the database
  first.
- A transient rebuilt on every request is not caching anything: check its expiry and the condition around
  `set_transient()`.

## What it costs

Each recorded request runs one `INSERT … ON DUPLICATE KEY UPDATE` for the request count, plus one for each option,
operation and caller it wrote, plus one for each changed key, at shutdown. On a request that writes nothing that is a
single statement. Use it on development and staging sites, or on production for a short window with
`OPTION_CHURN_SAMPLE`, and deactivate it when you have the numbers.

It makes no network requests and uses no API key.

## Limits

- It sees writes that go through WordPress's option functions. Direct `$wpdb` queries on the options table are not
  counted.
- Transients stored in a persistent object cache never reach the options table and are not counted.
- The caller is the first call site outside core. A plugin that writes through another plugin's API is attributed
  to the plugin whose code made the call.
- `changed_keys` compares top-level keys only.
- On multisite the totals are kept per site (network options under the site that made the request) in shared
  tables.

## Tests

`php tests/run.php` runs the unit tests (no WordPress). `WP_PATH=/path/to/wordpress bash tests/integration.sh` runs
the integration tests on that install: it activates the plugin, adds a must-use test plugin, requests the front page
through PHP's built-in server, checks the report, and uninstalls the plugin. CI runs both against MySQL 8.4 and
MariaDB 11.8.

## License

MIT. Made by [Hamza Ahmad Aslam](https://hamzaahmadaslam.com), WordPress and web performance engineer.
