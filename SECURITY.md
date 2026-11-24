# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | yes |
| older (bitcoind-status) | no |

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub: open the **Security** tab of
this repository and click **Report a vulnerability** (private vulnerability reporting).
Do not open a public issue for a security problem.

Please describe the affected version, the steps to reproduce and the possible impact.
We will answer as soon as possible and publish a fix together with an advisory.

## What counts as a security problem

- Access to files in `data/`, `php/`, `doc/`, `html/`, `remote_files/` or `.git/`
  through the web server, with the `.htaccess` files of the release on Apache 2.4.
- Cross-site scripting (XSS) or other injection through node data, peer data, data of
  external services or the configuration.
- Leaks of RPC access data, API keys or other secrets (for example in the page, the
  page cache, `chartdata.php` or error messages without `debug`).
- Ways to run the collector or other PHP files in `php/` through the web server.
- Ways to make the page or the collector send requests to other hosts than configured.

## What does not count

- Configuration mistakes of the operator, for example a public `php/config.php`
  backup, `debug` left on, wrong file permissions or `AllowOverride None`.
- Installations on other web servers than Apache 2.4 (for example nginx): the access
  rules are only provided as `.htaccess` files.
- Information that the page shows on purpose (node address, peer addresses when
  masking is off, values from the node).
- Problems in third-party services (Google Charts, ip2location.io, btcnodes.io,
  CoinMarketCap, blockchain.info).
