---
name: fmce-php-style
description: Coding rules for FMCE Node Status (PHP 8.x, no Composer, simple readable code, file headers, docblocks, easybitcoin batch mode, frontend, security, search engines). Use for every change to PHP, template, JS, CSS or config files in this project.
---

# FMCE Node Status – coding style

FMCE Node Status is published free of charge by FMCE Concept UG and serves as an
advertisement for the company. The code must be robust and current, but stay easy to
install and to read for people who are not experienced PHP developers.

## Project ground rules

- Installation by ZIP file, alternatively `git pull`. No Composer, no frameworks, no
  large libraries. Third-party code only as single files that ship with the project
  (`easybitcoin.php`, `phpqrcode.php`, `sorttable.js`, `google_corechart.js`).
- PHP compatibility: **optimised for PHP 8.4 / 8.5, must run without errors on every
  PHP 8.x version from 8.0 on.** PHP 7 and older are not supported and not considered.
- Requirement for users: a cron job that runs PHP on the command line (PHP CLI). There
  is no web-triggered alternative for the collector.
- Release 1.0 goes public on GitHub.
- **Commits** only when the maintainer asks for them. A task the maintainer has approved
  and that says "commit" counts as such a request. **Never push**, force-push or rewrite
  history unless the maintainer explicitly says so for that push.
- Never open, print or copy `php/config.php` or `remote_files/nodestat_remote_config.php`
  (they contain credentials). Use `php/config.sample.php` as reference and tell the
  maintainer which keys have to be added to the live `config.php`.
- Project-internal working rules (hand-over files, documentation, live configuration)
  are in the local project instructions, not in this skill.

## PHP language level

Allowed (PHP 8.0): `declare(strict_types=1)`, scalar/union/nullable types, `mixed`,
`static` return type, `match`, nullsafe `?->`, named arguments, constructor property
promotion, `str_contains()`, `str_starts_with()`, `str_ends_with()`, `JSON_THROW_ON_ERROR`,
short array syntax `[]`, arrow functions `fn()`.

Not allowed (newer than 8.0, would break 8.0): enums, `readonly` properties/classes,
`never` return type, first-class callable syntax `foo(...)`, `new` in initializers,
pure intersection types, DNF types, `true`/`false`/`null` as standalone types,
constants in traits, typed class constants, `array_is_list()`, `json_validate()`,
`mb_str_pad()`, `str_increment()`, `array_find()`/`array_any()`/`array_all()`,
property hooks, asymmetric visibility, `new Foo()->bar()` without parentheses,
`#[\Override]`, pipe operator `|>`.

Avoid deprecated or soon-deprecated constructs (PHP 8.1–8.5):
- `curl_close()` (deprecated as of 8.5): let the handle go out of scope or `unset($ch)`.
- Implicitly nullable parameters (`Type $x = null`) – write `?Type $x = null`.
- `"${var}"` string interpolation – use `"{$var}"`.
- Passing `null` to non-nullable internal parameters (e.g. `strlen(null)`).
- Dynamic properties on classes – declare every property.
- `(real)`, `(boolean)`, `(integer)`, `(double)` casts – use `(float)`, `(bool)`, `(int)`.
- No closing `?>` at the end of pure PHP files.

## Readability first

- Procedural code with small, well named functions is the default.
- No deep class hierarchies, no abstract factories, no interfaces "for later", no magic.
- If object-oriented code would clearly be better for a feature, **point it out to
  the maintainer with a short reason and wait for the decision** before converting anything.
- One task per function. Prefer early returns over deep nesting.
- Explanatory comments in **English** wherever the reason for the code is not obvious.
  Explanations to the maintainer are in German.
- Indentation 4 spaces, opening brace of functions on the same line (existing style).
- **Line endings LF in every file**, encoding UTF-8 without BOM (`.gitattributes`
  enforces LF). Convert any CRLF file you touch.
- New PHP files start with the file header, then `declare(strict_types=1);`.
  Templates (`html/*.php`) do not use strict_types.

## Docblocks (mandatory for every function, class and method)

```php
/**
 * functionName Does something (one line, starts with the function/class name)
 *
 * Optional longer explanation.
 *
 * @param array  $something Contains the data to do something with
 * @param string $mode      'a' or 'b'
 *
 * @return array (or void, bool, string|null ...)
 */
```

Classes get the same block with the class name and what the class is for; properties
get a one-line `/** @var type Description */`.

## File headers

Every file keeps its header. Two templates exist.

**New own file** (no code from Craig Watson):

```php
/**
 * FMCE Node Status
 * <What this file does, one line>.
 *
 * @category File
 * @package  NodeStatus
 * @author   Frank Maroke
 * @license  https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @link     https://fmce.de
 *
 * Copyright 2023-2026 FMCE Concept UG
 * @author   FMCE Concept UG
 * @link     https://fmce.de
 * @author   Frank Maroke
 * @link     https://github.com/FMaroke/node-status
 * CoAuthor: Claude / Claude Code - <current model, e.g. Opus 5.5>
 * Part of FMCE Node Status, a derivative of https://github.com/craigwatson/bitcoind-status
 *
 * Compatible with PHP 8.0+, optimised for PHP 8.4 / 8.5.
 *
 * SPDX-License-Identifier: Apache-2.0
 */
```

**Modified file from Craig Watson**: keep the original `@author Craig Watson` / `@link`
lines, then "Modifications Copyright 2023-2026 FMCE Concept UG", the FMCE authors, the
`CoAuthor:` line and the list "This file has been modified by FMCE Concept UG:" – add
a bullet for every relevant change you make. The first bullet is the PHP line
("Compatible with PHP 8.0+, optimised for PHP 8.4 / 8.5").

**Third-party files** (`easybitcoin.php` MIT, `phpqrcode.php` LGPL): keep the original
header and licence untouched, add a short "Modified by FMCE Concept UG" block with the
changes and the `CoAuthor:` line.

When you touch a file, make sure its header contains the `CoAuthor:` line.

## Security and robustness

- Escape every output in templates with `escHtml()`, including error texts and values
  from the config. `rawurlencode()` only for single URL parts (query values, path
  segments); a complete URL in `href`/`src` goes through `escHtml()`.
- URLs from the config or from data: accept only `http://` and `https://`.
- Values used in class names or file names (e.g. country codes): reduce to `a-z0-9-`.
- Links with `target="_blank"` get `rel="noopener noreferrer"`.
- JSON inside HTML (`<script type="application/ld+json">`): `json_encode` with
  `JSON_HEX_TAG | JSON_HEX_AMP`.
- Cron/collector scripts are **CLI only**: `if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }`.
- Wrap entry points in `try { … } catch (Throwable $e) { … }`. Error messages must never
  contain passwords, API keys or the RPC login. Technical error details are only shown
  with `'debug' => true` (they can contain host names).
- Every remote call (RPC, HTTP) has a connect timeout and a total timeout and is
  handled with try/catch; a failing source must not stop the others.
- Write data files atomically (temp file + `rename()`), lock with `flock()`, never with
  marker files that can stay behind after a crash.
- TLS certificate verification stays on (`CURLOPT_SSL_VERIFYPEER => true`).
- Never trust data from files or APIs: check types (`is_array`, `is_numeric`, `isset`)
  before using values. The page must render sensibly with missing, empty or broken data.
- Directories that are not meant for visitors (`html/`, `php/`, `data/`, `doc/`,
  `remote_files/`) are closed with `.htaccess` (`Require all denied`, Apache 2.4 syntax).

## Privacy

- Peer IP addresses are shown masked only. Full IPs stay in the data files.
- No visitor data goes to third parties where it can be avoided (QR code is generated
  locally). New external resources need the maintainer's decision, because they must be named
  in the privacy policy.
- Only technical cookies (`theme`, `cookie_notice`). No tracking, no analytics.
- The FMCE donation line in the footer is fixed and always shown. The donation keys in
  the config (`donation_address` …) only control the donation block of the page operator.

## Configuration

- Every new config key is read with a default in the code (`$config['key'] ?? default`),
  so older `config.php` files keep working.
- Add every new key with an English comment to `php/config.sample.php`, in the matching
  section, and tell the maintainer which line has to be added to the live `config.php`.
- Removed keys: delete them from `config.sample.php` and mention them in the report.

## Bitcoin RPC

- Use `php/easybitcoin.php`. Whenever more than one RPC call is needed, use the batch
  mode `$bitcoin->batch([...])` (parallel requests) instead of several single calls.
- The web page never calls the node or any other remote service. It only reads the
  files written by `php/collector.php` (one cron job every minute, intervals per source
  in `$config['collector']`).

## Data files (`data/`)

- JSON. Snapshot files: `{ "request_time": int, "request_data": {...} }`.
  Time series: `{ "request_time": int, "request_data": [ {"time": int, ...}, ... ] }`.
- The collector status file `data/nodestat-collector.state` tells the page when each
  source last succeeded and why it failed.
- Stored data carries no HTML and no display formatting; formatting happens in the
  template (`formatNumber`, `formatDateTime`, `formatBytes`, `formatDuration`).

## Frontend (HTML, CSS, JavaScript)

- The Content Security Policy (root `.htaccess`) allows scripts only from the own
  server and www.gstatic.com (Google Charts). Therefore: **no inline JavaScript**, no
  `onclick`/`onload` attributes, no `javascript:` links. All JS lives in `js/*.js` and is
  loaded with `defer`. Data for scripts goes into `data-*` attributes.
- Plain JavaScript, no jQuery, no frameworks, no build step. Code must run in current
  Chrome, Firefox, Edge and Safari (WebKit, incl. iPhone/iPad).
- Pages work without JavaScript (all peers visible, page reloads via `<noscript>`).
- CSS: colours, spacing and fonts only through the CSS variables of the FMCE design in
  `css/style.css` (e.g. `var(--text)`, `var(--primary)`). Check every change in the dark
  **and** the light theme. No inline `style` attributes in the template.
- Accessibility: one `<h1>`, logical heading order, alt texts for images (empty `alt=""`
  for pure decoration), labels for form controls, visible focus, operable by keyboard,
  text contrast at least 4.5:1 (WCAG AA), no content that only works on hover
  (touch devices).
- New files in CSS/JS are referenced with `asset()` (adds the cache-busting version).

## Search engines

- Only visible content. No hidden text, no keyword lists.
- Structured data (JSON-LD, `getStructuredData()`) describes only what is true for every
  installation: the software, its author FMCE Concept UG and the original project by
  Craig Watson. Never the operator of a page.
- Data endpoints (`index.php?partial=1`, `chartdata.php`) send `X-Robots-Tag: noindex`.
- The "Powered by FMCE Node Status" line stays in the footer (it also carries copyright
  and licence).
- Raise `NODE_STATUS_VERSION` in `php/functions.php` with every release.

## Before you finish

1. `php -l` on every changed PHP file, and check the PHP 8.0 rules above.
2. Test the collector **only in a test copy** of the project with a mock RPC server on
   `127.0.0.1` and test values in its `php/config.php` – never against a real node and
   never with the real `config.php`: `php php/collector.php --force`, then `--status`.
3. Check the page in the test copy with an empty `data/` folder, with too few data
   points, with a broken data file and with the node offline, in dark and light theme.
4. Report every changed file and every config key the maintainer has to add.
