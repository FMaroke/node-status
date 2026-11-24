# Changelog

## 1.0.0 – unreleased

First release of FMCE Node Status, a continuation of bitcoind-status by Craig Watson.

### Added
- Collector (`php/collector.php`): one cron job for all data sources, each with its own
  interval, parallel RPC batch, lock file, atomic writes, status (`--status`).
- Charts for estimated fee and Bitcoin price (BTC/USD); all charts from one definition.
- Background refresh of the page, peer table with pages and keyboard sorting.
- Optional rank and Peer Index from btcnodes.io (rate-limited).
- Dark and light theme, display formats for date, time and numbers, time zone setting.
- Imprint and privacy links, cookie notice, preview image and structured data.

### Changed
- PHP 8.0+ (tested with 8.3 to 8.5), `strict_types`, docblocks.
- New configuration file; data files in `data/` instead of `/tmp`.
- Geolocation via ip2location.io, only for public IP addresses.
- Uptime via RPC; free disk space of a remote node via a small helper script.
- Peer addresses: one parser for IPv4, IPv6, Tor, I2P and CJDNS; masking for all formats;
  `hide_dark_peers` hides all peers that are not on the public internet.

### Removed
- `stats.php`, `peercount.php` and the cache scripts (replaced by the collector).
- Google Analytics, jQuery, bitnodes.io data, unused configuration keys.

### Security
- `.htaccess` for Apache 2.4: blocked folders, hidden files and `.git/`, HTTPS redirect,
  security headers, Content Security Policy without `'unsafe-inline'`.
- The page makes no requests to other services; RPC over TLS checks the certificate by default.
- Page cache fingerprint without access data; escaped output everywhere.
