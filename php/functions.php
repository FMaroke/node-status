<?php
/**
 * FMCE Node Status
 * Helper functions: data retrieval, caching and output helpers.
 *
 * @category File
 * @package  NodeStatus / BitcoinStatus
 * @author   Craig Watson <craig@cwatson.org>
 * @license  https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @link     https://github.com/craigwatson/bitcoind-status
 *
 * Modifications Copyright 2023-2026 FMCE Concept UG
 * @author   FMCE Concept UG
 * @link     https://fmce.de
 * @author   Frank Maroke
 * @link     https://github.com/FMaroke/node-status
 * CoAuthor: Claude / Claude Code - Opus 5.5
 *
 * This file has been modified by FMCE Concept UG:
 * - Compatible with PHP 8.0+, optimised for PHP 8.4 / 8.5,
 * - the page never calls the node: all node data is read from the files of
 *   php/collector.php (readDataFile, getCollectorConfig in data_lib.php),
 * - chart logic for four charts incl. BTC/USD price (buildChartRows,
 *   displayChart), shared with chartdata.php; since release 1.0 all charts are
 *   built from one definition (getChartDefinitions in data_lib.php) and drawn
 *   by one script (js/charts.js),
 * - IPv6 handling and IP masking (GDPR),
 * - geolocation fallback,
 * - local QR code generation (buildQrSvg, generateDonationImage),
 * - output helpers escHtml() and asset(),
 * - chart display logic reworked (displayChart),
 * - free disk space, node uptime and geolocation are fetched by the collector
 *   (php/collector.php); the page only reads their files,
 * - display formats for date, time, numbers and bytes from config.php
 *   (formatNumber, formatDate, formatTime, formatDateTime, formatBytes,
 *   formatDuration, getChartFormat); stored data stays unchanged,
 * - the template formats free disk space and uptime (raw numbers in the data),
 * - background refresh of the page (liveStart, liveEnd, getLiveResponse),
 * - no remote calls during the page build any more (curlRequest, get_web_page,
 *   getFreeDiskSpace, getDaemonProcessUptime removed),
 * - long peer user agents are shortened for the peer table (shortenText),
 * - search engines and link previews: preview image (getShareImage) and
 *   structured data (getStructuredData), version and repository constants,
 * - page cache: configuration hash without credentials (getConfigFingerprint,
 *   SHA-256), written atomically, robust reading of a broken cache file,
 * - peers are checked and normalised (parsePeers, normalizePeer), so broken
 *   peer data can no longer break the page; elapsedTime() takes an int,
 * - user agents (BIP14) readable with separators (formatUserAgent),
 * - fixed donation address of the software (NODE_STATUS_DONATION_ADDRESS),
 * - strict_types, English docblocks and comments for all functions,
 *   maskIpAddress() cleaned up (same result).
 * - maskIpAddress() handles every address format: port only after "]:" or a
 *   single ":", zone id removed, no IP address (host name, *.onion) = "***",
 *   port left out if display_peer_port is off,
 * - time axis of the charts: time of day for short ranges (getChartFormat 'axis_time'),
 * - page cache format version (NODE_STATUS_CACHE_FORMAT): a software update
 *   makes an old page cache invalid,
 * - one parser for peer addresses (parsePeerAddress in data_lib.php) for
 *   masking, display and geolocation; Tor, I2P and CJDNS peers are marked
 *   (formatPeerAddress), get no flag and are never located; the address
 *   column sorts by network, then address (peerAddressSortKey),
 * - 'hide_dark_peers' hides every peer that is not on the public internet:
 *   localhost, Tor, I2P, CJDNS (isDarkPeer); "Version / Client" sorts by
 *   client name, then version numerically (peerAgentSortKey),
 * - rank and peer index of the node at btcnodes.io (readBtcnodesInfo,
 *   formatWithPrevious; data from the collector job "btcnodes").
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

require_once __DIR__ . '/data_lib.php';

$default_app_title = 'Bitcoin Node Status';

// Software version and repositories (meta generator, structured data, footer)
const NODE_STATUS_VERSION       = '1.0.0';
const NODE_STATUS_REPO          = 'https://github.com/FMaroke/node-status';
const NODE_STATUS_ORIGINAL_REPO = 'https://github.com/craigwatson/bitcoind-status';

// Format of the page cache (data/nodestat-status.cache). Raise it by 1 whenever
// getData() or the chart registry (displayChart) store other or more keys, or a
// stored value changes its meaning, so that an old cache is not read after an
// update. Together with NODE_STATUS_VERSION it is part of getConfigFingerprint().
const NODE_STATUS_CACHE_FORMAT = 5;   // 3: peers 'network'/'addr_sort'; 4: 'btcnodes'; 5: btcnodes 'node'

// Donation address of FMCE Concept UG for the software (footer, always shown).
// Published in README.md for verification. Please keep it.
const NODE_STATUS_DONATION_ADDRESS = 'bc1qfmru2sw3kvthjy4gapg7rramzaswzf9celfxnt';

/**
 * getData Collects all data for the page from the collector files, then writes the page cache
 *
 * The page never calls the Bitcoin node itself. Node data comes from the files
 * written by php/collector.php. Missing files are allowed: the matching values
 * are simply not set and the template shows a notice.
 *
 * @param bool $from_cache Whether to use the page cache (nodestat-status.cache)
 *
 * @return array Data for the template
 */
function getData($from_cache = false) {
    global $config;
    global $cache_message;
    global $geo_cache;

    // Use the page cache if it is valid: same configuration and not expired
    $cacheFile = (string) ($config['cache_file'] ?? '');
    if (($from_cache === true) && $cacheFile !== '' && is_file($cacheFile)) {
        $cache = json_decode((string) file_get_contents($cacheFile), true);

        // Only proceed if the cache is an array - invalid otherwise
        if (is_array($cache)) {
            if ((string) ($cache['config_hash'] ?? '') === getConfigFingerprint($config)) {
                if (time() < (int) ($cache['cache_expiry'] ?? 0)) {
                    return $cache;
                }
            } else {
                $cache_message = 'Configuration or software version has changed, cache has been refreshed.';
            }
        }
    }

    $jobs = getCollectorConfig($config)['jobs'];
    $data = array();

    // Blockchain info (blocks, difficulty, ...)
    $chain = readDataFile($jobs['blockchaininfo']['file']);
    if ($chain !== null && is_array($chain['data'])) {
        $data = $chain['data'];
        $data['data_time'] = $chain['time'];
    }
    $data['node_data_missing'] = !isset($data['blocks']);

    // Network info (connections, version)
    $net = readDataFile($jobs['networkinfo']['file']);
    if ($net !== null && is_array($net['data'])) {
        foreach (array('connections', 'subversion', 'protocolversion') as $key) {
            if (isset($net['data'][$key])) {
                $data[$key] = $net['data'][$key];
            }
        }
    }

    // Geolocation cache (written by the collector)
    $geo_cache = readGeoCache($jobs['geolocation']['file'], time());

    // Free disk space (bytes, written by the collector)
    if (($config['display_free_disk_space'] ?? false) === true) {
        $disk = readDataFile($jobs['diskspace']['file']);
        $bytes = is_array($disk['data'] ?? null) ? ($disk['data']['bytes'] ?? null) : null;
        $data['free_disk_space'] = (is_int($bytes) && $bytes > 0) ? $bytes : null;
    }

    if ($config['display_ip'] === true) {
        $data['node_ip'] = $config['node_ip'];
    }

    // Peer info: null = no peer data available (not the same as "no peers connected")
    if ($config['display_peer_info'] === true) {
        $peerFile = readDataFile($jobs['peerinfo']['file']);
        $data['peers'] = ($peerFile !== null && is_array($peerFile['data'])) ? parsePeers($peerFile['data']) : null;
    }

    // Node geolocation
    if (($config['display_ip_location'] === true) && ($config['display_ip'] === true)) {
        $data['ip_location'] = getGeolocation($config['node_ip']);
    }

    // Node uptime (RPC "uptime", written by the collector) plus the time since then
    if (($config['display_node_uptime'] ?? false) === true) {
        $uptime  = readDataFile($jobs['uptime']['file']);
        $seconds = is_array($uptime['data'] ?? null) ? ($uptime['data']['seconds'] ?? null) : null;
        $data['bitcoind_uptime'] = is_int($seconds) ? $seconds + max(0, time() - $uptime['time']) : null;
    }

    // Network height from blockchain.info (stored by the collector)
    if ($config['display_max_height'] === true) {
        $latest = readDataFile($jobs['latestblock']['file']);
        $height = ($latest !== null && is_array($latest['data'])) ? ($latest['data']['height'] ?? null) : null;
        if (is_int($height) && $height > 0) {
            $data['max_height'] = $height;
            if (isset($data['blocks']) && is_numeric($data['blocks'])) {
                // The node can be a block ahead of blockchain.info for a moment: max. 100 %
                $data['node_height_percent'] = min(100, round(($data['blocks'] / $height) * 100, 2));
            }
        }
    }

    // Rank and peer index at btcnodes.io (stored by the collector job "btcnodes")
    if (($config['display_btcnodes_info'] ?? false) === true) {
        $data['btcnodes'] = readBtcnodesInfo($jobs['btcnodes']['file']);
    }

    // Get needed chart data.
    $data['chart_data'] = displayChart();

    // Do not cache a page without node data: it shall show the data as soon as
    // the collector has stored it.
    if ($data['node_data_missing'] === false) {
        writeToCache($data);
    }
    return $data;
}

/**
 * readBtcnodesInfo Reads the rank and the peer index of the node at btcnodes.io
 *
 * The file is written by the collector job "btcnodes". Values with a wrong
 * type become null, so the template can rely on the types.
 *
 * @param string $file Data file of the job
 *
 * @return array|null null if there is no data yet, else ['time' => int, 'listed' => bool,
 *                    'node' => string|null ("<ipv4>-<port>", for the link to the node page),
 *                    'rank' => int|null, 'rank_previous' => int|null,
 *                    'peer_index' => float|null, 'peer_index_previous' => float|null]
 */
function readBtcnodesInfo(string $file) {
    $info = readDataFile($file);
    if ($info === null || !is_array($info['data'])) {
        return null;
    }
    $d   = $info['data'];
    $int = static fn ($v): ?int => (is_int($v) && $v > 0) ? $v : null;
    $num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;

    return array(
        'time'                => (int) $info['time'],
        'listed'              => ($d['listed'] ?? false) === true,
        'node'                => (is_string($d['node'] ?? null) && preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){3}-[0-9]{1,5}$/', $d['node']))
                                 ? $d['node'] : null,
        'rank'                => $int($d['rank'] ?? null),
        'rank_previous'       => $int($d['rank_previous'] ?? null),
        'peer_index'          => $num($d['peer_index'] ?? null),
        'peer_index_previous' => $num($d['peer_index_previous'] ?? null),
    );
}

/**
 * formatWithPrevious Formats a value and, if known, its previous value
 *
 * Example: formatWithPrevious(5147, 5166, 0) = '5,147 (previous: 5,166)'.
 *
 * @param int|float      $value    Current value
 * @param int|float|null $previous Previous different value (null = unknown)
 * @param int            $decimals Number of decimal places
 *
 * @return string Plain text (the template escapes it)
 */
function formatWithPrevious($value, $previous, int $decimals): string {
    $text = formatNumber($value, $decimals);
    return ($previous !== null) ? $text . ' (previous: ' . formatNumber($previous, $decimals) . ')' : $text;
}

/**
 * writeToCache Writes the page data to the page cache (nodestat-status.cache)
 *
 * The file is written atomically (temporary file + rename, see saveJsonAtomic),
 * so a page request never reads a half written cache. A failed write is only
 * logged: the page itself still works without cache.
 *
 * @param array $data Page data from getData()
 *
 * @return void
 */
function writeToCache($data) {
    global $config;

    if (($config['use_cache'] ?? true) !== true) {
        return;
    }

    $data['cache_time']   = time();
    $data['cache_expiry'] = $data['cache_time'] + (int) ($config['max_cache_time'] ?? 60);
    $data['config_hash']  = getConfigFingerprint($config);

    try {
        saveJsonAtomic((string) $config['cache_file'], $data);
    } catch (Throwable $e) {
        error_log('FMCE Node Status: page cache not written: ' . $e->getMessage());
    }
}

/**
 * getConfigFingerprint Returns a hash of the configuration without any secrets
 *
 * The page cache stores this hash to notice configuration changes. Credentials
 * must never be part of it (not even hashed): all keys that start with 'rpc_'
 * or contain 'apikey', 'api_key', 'pass', 'password', 'secret' or 'token' are
 * removed first, also inside nested arrays (e.g. collector.jobs.*.apikey).
 * The software version (NODE_STATUS_VERSION) and the cache format
 * (NODE_STATUS_CACHE_FORMAT) are part of the hash as well, so an update of the
 * software makes an old page cache invalid. Raise NODE_STATUS_CACHE_FORMAT when
 * the content of the page cache changes (see the comment at the constant).
 *
 * @param array $config The complete $config array
 *
 * @return string SHA-256 hash (hex)
 */
function getConfigFingerprint($config) {
    $json = json_encode(
        array(
            'software'     => NODE_STATUS_VERSION,
            'cache_format' => NODE_STATUS_CACHE_FORMAT,
            'config'       => removeSecretKeys((array) $config),
        ),
        JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    return hash('sha256', (string) $json);
}

/**
 * removeSecretKeys Removes all keys with credentials from an array (recursive)
 *
 * @param array $values Array to clean, e.g. the $config array
 *
 * @return array The array without secret keys
 */
function removeSecretKeys($values) {
    $clean = array();
    foreach ($values as $key => $value) {
        $name = strtolower((string) $key);
        if (strpos($name, 'rpc_') === 0 || preg_match('/apikey|api_key|pass|secret|token/', $name)) {
            continue;   // 'pass' also covers 'password'
        }
        $clean[$key] = is_array($value) ? removeSecretKeys($value) : $value;
    }
    return $clean;
}

/**
 * parsePeers Checks, filters and normalises the peer list for the template
 *
 * Entries that are no array or have no address are skipped. All values the
 * template uses get a fixed type (see normalizePeer), so a broken or
 * incomplete data file can never break the page.
 *
 * @param array $peers Peer list from getpeerinfo (collector file)
 *
 * @return array List of normalised peers
 */
function parsePeers($peers) {
    global $config;
    $to_return = array();

    foreach ($peers as $peer) {
        if (!is_array($peer) || !is_string($peer['addr'] ?? null) || $peer['addr'] === '') {
            continue;   // broken entry: no peer data or no address
        }
        $hasPing = array_key_exists('pingtime', $peer);
        $peer    = normalizePeer($peer);

        // Host without port, brackets and zone id (IPv4, IPv6, *.onion, ...)
        $peer_host = parsePeerAddress($peer['addr'])['host'];

        // Continue if peer is 'dark': not connected over the public internet
        // (localhost, Tor, I2P, CJDNS; an inbound Tor peer is 127.0.0.1 with network 'onion')
        if ($config['hide_dark_peers'] === true && isDarkPeer($peer_host, $peer['network'])) {
            continue;
        }

        // Continue if peer is to be ignored
        if (is_array($config['peers_to_ignore']) && in_array($peer_host, $config['peers_to_ignore'], true)) {
            continue;
        }

        // Continue if peer reports no pingtime
        if ($config['ignore_unknown_ping'] === true) {
            if (!$hasPing) {
                continue;
            }
        }

        // Geolocation (flag) only for public IP addresses: not for Tor, I2P, CJDNS
        $peer_ip = peerIpFromAddress($peer['addr'], $peer['network']);
        if ($config['geolocate_peer_ip'] === true && $peer_ip !== '') {
            $peer['geo_data'] = getGeolocation($peer_ip);
        }

        // Address as shown: masked for GDPR / DSGVO if needed, port only if
        // display_peer_port is on, Tor/I2P/CJDNS marked
        // Sort group: Tor/I2P/CJDNS, otherwise the kind of the address (e.g. 'ipv4' also
        // for network 'not_publicly_routable')
        $sortGroup = in_array($peer['network'], array('onion', 'i2p', 'cjdns'), true)
                   ? $peer['network'] : parsePeerAddress($peer['addr'])['kind'];
        $peer['addr']      = formatPeerAddress($peer['addr'], $peer['network'], $config['display_masked_ip'] === true,
                                               $config['display_peer_port'] !== false);
        $peer['addr_sort'] = peerAddressSortKey($peer['addr'], $sortGroup);

        $to_return[] = $peer;

    }
    return $to_return;
}

/**
 * normalizePeer Gives every peer value used by the page a fixed type
 *
 * Numbers that are missing or not numeric become 0. 'pingtime' stays null if
 * it is missing or not numeric, so the page can show "Unavailable" instead of
 * a wrong 0 ms. 'network' is the network of the peer (peerNetwork: the field
 * of Bitcoin Core or derived from the address). Other keys are kept unchanged.
 *
 * @param array $peer One peer from getpeerinfo (must contain a string 'addr')
 *
 * @return array The peer with typed values
 */
function normalizePeer($peer) {
    foreach (array('version', 'conntime', 'lastsend', 'lastrecv', 'bytessent', 'bytesrecv') as $key) {
        $peer[$key] = is_numeric($peer[$key] ?? null) ? (int) $peer[$key] : 0;
    }
    $peer['pingtime'] = is_numeric($peer['pingtime'] ?? null) ? (float) $peer['pingtime'] : null;
    $peer['subver']   = is_scalar($peer['subver'] ?? null) ? (string) $peer['subver'] : '';
    $peer['inbound']  = ($peer['inbound'] ?? false) === true;
    $peer['network']  = peerNetwork($peer);   // 'ipv4', 'ipv6', 'onion', 'i2p', 'cjdns', ...

    return $peer;
}


/**
 * getGeolocation Returns the country of an IP address from the geolocation cache
 *
 * The cache is filled by the collector (job "geolocation"). Addresses that
 * are not looked up yet show no flag until the next collector runs.
 *
 * @param string $ip_address IP address
 *
 * @return array ['country_code' => 'DE' or 'blank', 'country_name' => string]
 */
function getGeolocation($ip_address) {
    global $geo_cache;

    if (is_array($geo_cache) && isset($geo_cache[$ip_address])) {
        return $geo_cache[$ip_address];
    }
    return array('country_code' => 'blank', 'country_name' => 'Unavailable');
}

/**
 * buildQrSvg Builds a QR code as SVG image from a text
 *
 * Only the encoder of phpqrcode is used; the SVG is drawn here, one path
 * segment per run of dark modules in a row.
 *
 * @param string $text Text to encode, e.g. a BIP21 URI
 *
 * @return string SVG document (black on white)
 */
function buildQrSvg($text) {
    $rows  = QRcode::text($text, false, QR_ECLEVEL_M);  // list of strings with '0'/'1'
    $count = count($rows);
    $quiet = 4;                                         // quiet zone as required by the QR specification
    $size  = $count + 2 * $quiet;

    $path = '';
    foreach ($rows as $y => $row) {
        $len = strlen($row);
        $x = 0;
        while ($x < $len) {
            if ($row[$x] === '1') {
                $start = $x;
                while ($x < $len && $row[$x] === '1') {
                    $x++;
                }
                $run   = $x - $start;
                $path .= 'M' . ($start + $quiet) . ' ' . ($y + $quiet) . 'h' . $run . 'v1h-' . $run . 'z';
            } else {
                $x++;
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size
         . '" shape-rendering="crispEdges">'
         . '<rect width="100%" height="100%" fill="#fff"/>'
         . '<path d="' . $path . '" fill="#000"/></svg>';
}

/**
 * generateDonationImage Returns the QR code image of the donation address as HTML
 *
 * The QR code is generated locally (no external service) and stored once as
 * SVG file in 'img_dir'. If the folder is not writable, the SVG is embedded
 * as data URI instead.
 *
 * @return string HTML <img> element (escaped)
 */
function generateDonationImage() {
    global $config;

    // BIP21 URI; RFC 3986 encodes spaces as %20 (not as +)
    $uri = 'bitcoin:' . $config['donation_address'] . '?' . http_build_query([
        'amount' => $config['donation_amount'],
        'label'  => $config['donation_label'],
    ], '', '&', PHP_QUERY_RFC3986);

    $cacheDir = (string) $config['img_dir'];
    $fileName = 'donation-qr-' . md5($uri) . '.svg';
    $filePath = $cacheDir . '/' . $fileName;
    $src      = 'img/' . $fileName;      // fixed URL: 'img_dir' must be the img/ folder of the web root

    if (!is_file($filePath)) {
        $svg = buildQrSvg($uri);

        // "@": a failure is handled below (data URI), the page must not show a warning
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (@file_put_contents($filePath, $svg, LOCK_EX) === false) {
            // Folder not writable: embed the SVG directly
            $src = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
    }

    return "\n" . sprintf(
        '<img class="qr" width="%d" height="%d"' . "\n" . 'src="%s"' . "\n" . 'alt="QR code for the Bitcoin donation address">',
        (int) $config['donation_qr_width'],
        (int) $config['donation_qr_height'],
        htmlspecialchars($src, ENT_QUOTES)
    ) . "\n";
}

/**
 * escHtml Escapes a value for HTML output
 *
 * @param mixed $value Value to escape
 *
 * @return string
 */
function escHtml($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * shortenText Shortens a text to a maximum number of characters and adds "…"
 *
 * Counts characters, not bytes, so umlauts and emojis are never cut in half.
 * Uses mbstring when available, otherwise a UTF-8 regular expression.
 *
 * @param string $text      Text to shorten (not escaped)
 * @param int    $maxLength Maximum number of characters incl. "…" (0 or less = never shorten)
 *
 * @return string The text itself or the shortened text (not escaped)
 */
function shortenText($text, $maxLength) {
    $text      = (string) $text;
    $maxLength = (int) $maxLength;

    if ($maxLength <= 0) {
        return $text;
    }

    if (function_exists('mb_strlen')) {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, max(1, $maxLength - 1), 'UTF-8')) . '…';
    }

    // Fallback without mbstring: split into UTF-8 characters
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false || count($chars) <= $maxLength) {
        return $text;
    }
    return rtrim(implode('', array_slice($chars, 0, max(1, $maxLength - 1)))) . '…';
}

/**
 * formatUserAgent Makes a user agent (BIP14 "subversion") readable
 *
 * Components are separated by " / ", the first ":" of a component (name:version)
 * becomes a space and a comment in brackets gets a space in front:
 * "/Satoshi:29.2.0/Knots:20251010(Running)/" -> "Satoshi 29.2.0 / Knots 20251010 (Running)".
 *
 * @param mixed $subver User agent from getnetworkinfo or getpeerinfo
 *
 * @return string Readable user agent (not escaped), '' if there is none
 */
function formatUserAgent($subver) {
    if (!is_scalar($subver)) {
        return '';
    }

    // Split at "/" outside of brackets: a comment may contain "/" itself
    $split = preg_split('#/(?![^(]*\))#', trim((string) $subver, '/'));
    $parts = array();
    foreach (($split === false) ? array() : $split as $part) {
        $part = preg_replace('/:/', ' ', $part, 1);                    // name:version -> name version
        $part = preg_replace('/\s*\(/', ' (', (string) $part, 1);      // space before the comment
        $part = trim((string) $part);
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    return implode(' / ', $parts);
}

/**
 * asset Adds the file time as version to a CSS/JS path, so browsers load changed files
 *
 * @param string $path Path relative to the web root, e.g. 'css/style.css'
 *
 * @return string Path with ?v=<filemtime>
 */
function asset($path) {
    $file = dirname(__DIR__) . '/' . $path;   // web root = parent of php/
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/**
 * getPublicBaseUrl Returns the public URL of the folder that contains index.php
 *
 * Built from 'canonical_url' in config.php. Examples:
 * 'https://node.example.com' and 'https://node.example.com/' give 'https://node.example.com/',
 * 'https://example.com/node/' gives 'https://example.com/node/'.
 *
 * @return string|null Base URL ending with '/', or null if 'canonical_url' is not a valid http(s) URL
 */
function getPublicBaseUrl() {
    global $config;

    $canonical = (string) ($config['canonical_url'] ?? '');
    if (!preg_match('#^https?://[^/\s]+#i', $canonical)) {
        return null;
    }
    $path = (string) parse_url($canonical, PHP_URL_PATH);
    if ($path === '') {
        return $canonical . '/';
    }
    // Cut off a file name such as index.php, keep the folder
    return preg_replace('#[^/]*$#', '', $canonical);
}

/**
 * getShareImage Returns the preview image for links in social media and messengers
 *
 * Open Graph (og:image) needs an absolute URL. It is built from the public base URL
 * (see getPublicBaseUrl) and 'share_image' (path relative to the web root).
 * Recommended size: 1200 x 630 pixels.
 *
 * @return array|null ['url', 'width', 'height', 'type'], or null if not configured or the file is missing
 */
function getShareImage() {
    global $config;

    $path = ltrim((string) ($config['share_image'] ?? ''), '/');
    $base = getPublicBaseUrl();
    if ($path === '' || $base === null) {
        return null;
    }

    $file = dirname(__DIR__) . '/' . $path;   // web root = parent of php/
    if (!is_file($file)) {
        return null;
    }
    $size = getimagesize($file);
    if ($size === false) {
        return null;
    }

    return array(
        'url'    => $base . implode('/', array_map('rawurlencode', explode('/', $path))),
        'width'  => (int) $size[0],
        'height' => (int) $size[1],
        'type'   => (string) ($size['mime'] ?? ''),
    );
}

/**
 * getStructuredData Builds the structured data (JSON-LD, schema.org) for search engines
 *
 * Describes the page (WebPage) and the software behind it (SoftwareSourceCode),
 * including the original project by Craig Watson. The page operator is not named,
 * because other installations are run by other people.
 * The JSON is safe to print inside <script type="application/ld+json">:
 * JSON_HEX_TAG and JSON_HEX_AMP encode <, > and &, so a title can never close the tag.
 *
 * @param string     $title       Page title
 * @param string     $description Page description (same as the meta description)
 * @param array|null $shareImage  Result of getShareImage()
 *
 * @return string JSON, or '' if it could not be encoded
 */
function getStructuredData($title, $description, $shareImage) {
    global $config;

    $page = array(
        '@type'       => 'WebPage',
        'name'        => (string) $title,
        'description' => (string) $description,
        'inLanguage'  => 'en',
        'isBasedOn'   => array('@id' => NODE_STATUS_REPO),
    );
    $canonical = (string) ($config['canonical_url'] ?? '');
    if (preg_match('#^https?://#i', $canonical)) {
        $page['@id'] = $canonical . '#webpage';
        $page['url'] = $canonical;
    }
    if (is_array($shareImage)) {
        $page['primaryImageOfPage'] = array(
            '@type'  => 'ImageObject',
            'url'    => $shareImage['url'],
            'width'  => $shareImage['width'],
            'height' => $shareImage['height'],
        );
    }

    $software = array(
        '@type'               => 'SoftwareSourceCode',
        '@id'                 => NODE_STATUS_REPO,
        'name'                => 'FMCE Node Status',
        'description'         => 'Open-source status page for Bitcoin nodes, written in PHP without Composer.',
        'codeRepository'      => NODE_STATUS_REPO,
        'programmingLanguage' => 'PHP',
        'license'             => 'https://www.apache.org/licenses/LICENSE-2.0',
        'version'             => NODE_STATUS_VERSION,
        'author'              => array('@type' => 'Organization', 'name' => 'FMCE Concept UG', 'url' => 'https://fmce.de'),
        'isBasedOn'           => array(
            '@type'          => 'SoftwareSourceCode',
            'name'           => 'Bitcoin Daemon Status Page (bitcoind-status)',
            'codeRepository' => NODE_STATUS_ORIGINAL_REPO,
            'author'         => array('@type' => 'Person', 'name' => 'Craig Watson'),
        ),
    );

    $json = json_encode(
        array('@context' => 'https://schema.org', '@graph' => array($page, $software)),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    );

    return ($json === false) ? '' : $json;
}

/* ------------------------------------------------------------------ *
 * Display formats: date, time and numbers are only formatted for the
 * output. All stored values (data files, page cache) stay unformatted.
 * ------------------------------------------------------------------ */

/**
 * getDisplayFormats Returns the display formats from config.php with defaults
 *
 * Defaults (if a key is missing): international notation.
 *
 * @return array ['date', 'time', 'decimal_point', 'thousands_sep', 'language']
 */
function getDisplayFormats() {
    global $config;

    $language = preg_replace('/[^A-Za-z_-]/', '', (string) ($config['format_language'] ?? 'en'));

    return array(
        'date'          => (string) ($config['format_date'] ?? 'Y-m-d'),
        'time'          => (string) ($config['format_time'] ?? 'H:i:s'),
        'decimal_point' => (string) ($config['format_decimal_point'] ?? '.'),
        'thousands_sep' => (string) ($config['format_thousands_sep'] ?? ','),
        'language'      => ($language !== '') ? $language : 'en',
    );
}

/**
 * formatNumber Formats a number with the configured decimal point and thousands separator
 *
 * Example (German): formatNumber(1234567.891, 2) = '1.234.567,89'
 *
 * @param mixed $value    Number (int, float or numeric string)
 * @param int   $decimals Number of decimal places
 *
 * @return string Formatted number, or 'n/a' if $value is not numeric
 */
function formatNumber($value, $decimals = 0) {
    if (!is_numeric($value)) {
        return 'n/a';
    }
    $formats = getDisplayFormats();
    return number_format((float) $value, (int) $decimals, $formats['decimal_point'], $formats['thousands_sep']);
}

/**
 * formatDate Formats a Unix timestamp as date ('format_date' in config.php)
 *
 * @param int $timestamp Unix timestamp
 *
 * @return string
 */
function formatDate($timestamp) {
    return date(getDisplayFormats()['date'], (int) $timestamp);
}

/**
 * formatTime Formats a Unix timestamp as time ('format_time' in config.php)
 *
 * @param int $timestamp Unix timestamp
 *
 * @return string
 */
function formatTime($timestamp) {
    return date(getDisplayFormats()['time'], (int) $timestamp);
}

/**
 * formatDateTime Formats a Unix timestamp as date and time
 *
 * @param int $timestamp Unix timestamp
 *
 * @return string e.g. '15.11.26 16:30:05'
 */
function formatDateTime($timestamp) {
    return formatDate($timestamp) . ' ' . formatTime($timestamp);
}

/**
 * formatBytes Formats a number of bytes with unit (base 1024)
 *
 * @param mixed $bytes Number of bytes
 *
 * @return string e.g. '1,50 GB', or 'n/a' if $bytes is not numeric
 */
function formatBytes($bytes) {
    if (!is_numeric($bytes)) {
        return 'n/a';
    }
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');
    $bytes = max(0.0, (float) $bytes);
    $class = 0;
    while ($bytes >= 1024 && $class < count($units) - 1) {
        $bytes /= 1024;
        $class++;
    }
    return formatNumber($bytes, $class === 0 ? 0 : 2) . ' ' . $units[$class];
}

/**
 * formatDuration Formats a number of seconds as "N days, hh:mm:ss"
 *
 * @param mixed $seconds Duration in seconds
 *
 * @return string e.g. '12 days, 04:05:06', or 'n/a' if $seconds is not numeric
 */
function formatDuration($seconds) {
    if (!is_numeric($seconds)) {
        return 'n/a';
    }
    $seconds = max(0, (int) $seconds);
    $days    = intdiv($seconds, 86400);
    $rest    = $seconds % 86400;

    return formatNumber($days) . ($days === 1 ? ' day, ' : ' days, ')
         . sprintf('%02d:%02d:%02d', intdiv($rest, 3600), intdiv($rest % 3600, 60), $rest % 60);
}

/**
 * phpDateToIcu Converts a PHP date() format into an ICU pattern (used by Google Charts)
 *
 * Only the common letters are converted; other letters are taken literally.
 *
 * @param string $format PHP date() format, e.g. 'd.m.y H:i'
 *
 * @return string ICU pattern, e.g. 'dd.MM.yy HH:mm'
 */
function phpDateToIcu($format) {
    $map = array(
        'd' => 'dd', 'j' => 'd', 'D' => 'EEE', 'l' => 'EEEE',
        'm' => 'MM', 'n' => 'M', 'M' => 'MMM', 'F' => 'MMMM',
        'y' => 'yy', 'Y' => 'yyyy',
        'H' => 'HH', 'G' => 'H', 'h' => 'hh', 'g' => 'h',
        'i' => 'mm', 's' => 'ss', 'A' => 'a', 'a' => 'a', 'T' => 'z',
    );

    $icu = '';
    foreach (str_split((string) $format) as $char) {
        if (isset($map[$char])) {
            $icu .= $map[$char];
        } elseif (ctype_alpha($char)) {
            $icu .= "'" . $char . "'";   // unknown letter: literal text
        } else {
            $icu .= $char;
        }
    }
    return $icu;
}

/**
 * getChartFormat Returns the display formats for the Google Charts
 *
 * 'datetime' is used in the tooltips, 'axis' and 'axis_time' for the labels of
 * the time axis. 'axis' is the configured date without the year, e.g. 'd.m.y'
 * gives 'dd.MM.' and 'Y-m-d' gives 'MM-dd' (charts over a few days or weeks).
 * 'axis_time' is the configured time without seconds, e.g. 'H:i:s' gives
 * 'HH:mm' (charts over less than two days, see js/charts.js).
 *
 * @return array ['language', 'decimal', 'group', 'datetime', 'axis', 'axis_time' (ICU patterns)]
 */
function getChartFormat() {
    $formats = getDisplayFormats();

    // Date without year: remove Y/y, then separators left at the start or the end
    // (a trailing "." stays, as in the German "17.11.")
    $axis = preg_replace('/[Yy]/', '', $formats['date']);
    $axis = rtrim(ltrim((string) $axis, " ./-,"), " /-,");

    // Time without seconds (and fractions): 'H:i:s' -> 'H:i', 'g:i:s A' -> 'g:i A'
    $axisTime = trim((string) preg_replace('/[:.]?[suv]/', '', $formats['time']));

    return array(
        'language'  => $formats['language'],
        'decimal'   => $formats['decimal_point'],
        'group'     => $formats['thousands_sep'],
        'datetime'  => phpDateToIcu($formats['date'] . ' ' . $formats['time']),
        'axis'      => ($axis !== '') ? phpDateToIcu($axis) : phpDateToIcu($formats['date']),
        'axis_time' => ($axisTime !== '') ? phpDateToIcu($axisTime) : 'HH:mm',
    );
}

/* ------------------------------------------------------------------ *
 * Background refresh: the template marks the parts of the page that
 * change (liveStart/liveEnd). index.php?partial=1 sends them as JSON,
 * js/refresh.js replaces them in the open page.
 * ------------------------------------------------------------------ */

/**
 * liveStart Starts a part of the page that is refreshed in the background
 *
 * @return void
 */
function liveStart() {
    ob_start();
}

/**
 * liveEnd Ends a refreshable part: keeps a copy of its HTML and outputs it
 *
 * @param string $name Name of the part (also used in the element id "live-<name>")
 *
 * @return void
 */
function liveEnd($name) {
    global $livePieces;

    $html = (string) ob_get_clean();
    $livePieces[$name] = $html;
    echo $html;
}

/**
 * getRefreshSettings Returns the refresh settings from config.php
 *
 * @return array ['interval' => seconds (0 = off, else min. 30), 'peers_every' => every n-th refresh]
 */
function getRefreshSettings() {
    global $config;

    $interval = (int) ($config['refresh_interval'] ?? 60);
    return array(
        'interval'    => ($interval <= 0) ? 0 : max(30, $interval),
        'peers_every' => max(1, (int) ($config['refresh_peers_every'] ?? 3)),
    );
}

/**
 * getLiveResponse Builds the answer for index.php?partial=1
 *
 * @param array $livePieces   HTML of the refreshable parts (from liveEnd)
 * @param array $activeCharts Charts shown on the page (chart registry)
 * @param int   $version      Time of the last collector run (for information)
 * @param bool  $withPeers    Include the peer table
 *
 * @return array ['version', 'charts' => [id => meta], 'parts' => [name => html]]
 */
function getLiveResponse($livePieces, $activeCharts, $version, $withPeers) {
    $charts = array();
    foreach ($activeCharts as $id => $chart) {
        $charts[$id] = array(
            'stat'    => $chart['stat'],
            'last'    => $chart['last'] ?? '',
            'title'   => $chart['title'] ?? '',
            'aria'    => $chart['aria'] ?? '',
            'updated' => (int) ($chart['updated'] ?? 0),
        );
    }

    $parts = is_array($livePieces) ? $livePieces : array();
    if (!$withPeers) {
        unset($parts['peers']);
    }

    return array('version' => (int) $version, 'charts' => $charts, 'parts' => $parts);
}

/**
 * chartValue Reads one value of a data point; nested keys are separated by "."
 *
 * @param array  $point Data point of a time series
 * @param string $path  Key, e.g. 'feerate' or 'prices.BTC.USD'
 *
 * @return mixed The value, or null if a key is missing
 */
function chartValue($point, $path) {
    $value = $point;
    foreach (explode('.', (string) $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }
    return $value;
}

/**
 * chartText Returns a text setting of a chart: a fixed string or the result of a closure
 *
 * @param mixed $text String or Closure(array $job): string
 * @param array $job  Settings of the collector job of the chart
 *
 * @return string
 */
function chartText($text, $job) {
    return ($text instanceof Closure) ? (string) $text($job) : (string) $text;
}

/**
 * buildChartRows Builds the table for one chart from its collector file
 *
 * First row = column headers, then one row per data point. The first column
 * is the time in milliseconds (JavaScript turns it into a date). Which values
 * are read is set in getChartDefinitions() (data_lib.php). Data points with a
 * missing or wrong value are skipped; with 'series' => 'auto' a missing value
 * counts as 0 (e.g. a client type that appeared later).
 *
 * @param string $stat Chart id from getChartDefinitions(), e.g. 'fee'
 *
 * @return array ['rows' => array, 'count' => int, 'last' => string (formatted latest value),
 *               'updated' => int (time of the newest data point, 0 if none)]
 */
function buildChartRows($stat) {
    global $config;

    $result = array('rows' => array(), 'count' => 0, 'last' => '', 'updated' => 0);
    $charts = getChartDefinitions();
    $jobs   = getCollectorConfig($config)['jobs'];
    if (!isset($charts[$stat], $jobs[$charts[$stat]['job']])) {
        return $result;
    }
    $chart  = $charts[$stat];
    $job    = $jobs[$chart['job']];
    $points = readSeries($job['file']);
    $isInt  = ($chart['value_type'] === 'int');
    $lastValue = null;

    if ($chart['series'] === 'auto') {
        if ($points === array()) {
            $result['rows'][] = array('Date', 'Value');
            return $result;
        }
        // Columns = keys of the newest data point. Older points without a key
        // (e.g. after changing 'extra_nodes') get 0 for it.
        $newest  = end($points);
        $columns = array_values(array_diff(array_keys($newest), array('time')));
        $header  = array('Date');
        $lastValue = 0;
        foreach ($columns as $column) {
            $value      = $isInt ? (int) ($newest[$column] ?? 0) : (float) ($newest[$column] ?? 0);
            $lastValue += $value;
            $label      = (string) ($chart['series_labels'][$column] ?? $column);
            $header[]   = !empty($chart['label_with_count'])
                        ? formatNumber($value, $chart['decimals']) . ' x ' . $label
                        : $label;
        }
        $result['rows'][] = $header;
        foreach ($points as $point) {
            $row = array($point['time'] * 1000);
            foreach ($columns as $column) {
                $row[] = $isInt ? (int) ($point[$column] ?? 0) : (float) ($point[$column] ?? 0);
            }
            $result['rows'][] = $row;
        }
    } else {
        $header = array('Date');
        foreach ($chart['series'] as $series) {
            $header[] = chartText($series['label'], $job);
        }
        $result['rows'][] = $header;

        foreach ($points as $point) {
            $row = array($point['time'] * 1000);
            foreach ($chart['series'] as $series) {
                $value = chartValue($point, $series['value']);
                if ($isInt ? !is_int($value) : !is_numeric($value)) {
                    continue 2;   // incomplete data point: skip it
                }
                $value = $isInt ? $value : (float) $value;
                if ($chart['round'] !== null) {
                    $value = round($value, (int) $chart['round']);
                }
                $row[] = $value;
            }
            $result['rows'][] = $row;
            $values    = array_slice($row, 1);
            $lastValue = ($chart['last'] === 'sum') ? array_sum($values) : $values[0];
        }
    }

    if ($lastValue !== null) {
        $result['last'] = formatNumber($lastValue, $chart['decimals']) . ($chart['unit'] !== '' ? ' ' . $chart['unit'] : '');
    }
    $result['count'] = count($result['rows']) - 1;
    if ($result['count'] > 0) {
        $newestRow         = end($result['rows']);
        $result['updated'] = (int) ($newestRow[0] / 1000);   // time of the newest point (seconds)
    }
    return $result;
}

/**
 * displayChart Decides which charts the page shows
 *
 * A chart is shown if it is switched on in config.php ("display_<id>_chart",
 * missing switch = on), its collector job is enabled and it has at least
 * 'min_data_points' points. Otherwise a short notice explains why the chart is
 * missing. The registry holds everything js/charts.js needs to draw the chart.
 *
 * @return array ['display' => bool, 'chart_registry' => ["<id>_chart" => chart], 'notices' => list of strings]
 */
function displayChart() {
    global $config;

    $jobs   = getCollectorConfig($config)['jobs'];
    $charts = array(
        'display'        => false,
        'chart_registry' => array(),
        'notices'        => array(),
    );

    foreach (getChartDefinitions() as $id => $chart) {
        if (($config['display_' . $id . '_chart'] ?? true) !== true
            || !isset($jobs[$chart['job']]) || !$jobs[$chart['job']]['enabled']) {
            continue;
        }

        $job   = $jobs[$chart['job']];
        $table = buildChartRows($id);
        $min   = (int) ($job['min_data_points'] ?? 2);

        if ($table['count'] >= $min) {
            $title = chartText($chart['title'], $job) . ': ' . $table['last'];
            $charts['chart_registry'][$id . '_chart'] = array(
                'stat'    => $id,
                'title'   => $title,
                'aria'    => 'Chart: ' . $title,
                'last'    => $table['last'],
                'updated' => $table['updated'],
                // Drawing settings for js/charts.js (data-chart attribute)
                'config'  => array(
                    'stat'     => $id,
                    'type'     => $chart['type'],
                    'legend'   => $chart['legend'],
                    'decimals' => (int) $chart['decimals'],
                    'color'    => $chart['color'],
                    'axis'     => in_array($chart['axis'] ?? 'auto', array('date', 'time'), true) ? $chart['axis'] : 'auto',
                ),
            );
            $charts['display'] = true;
        } elseif ($table['count'] === 0) {
            $charts['notices'][] = $chart['name'] . ': no data yet.';
        } else {
            $charts['notices'][] = $chart['name'] . ': collecting data (' . $table['count'] . ' of ' . $min . ' data points).';
        }
    }

    return $charts;
}


/**
 * elapsedTime Returns the time since a timestamp as text, e.g. "5 minutes"
 *
 * Plain text without HTML; the caller escapes it for the output.
 *
 * @param int $ptime Unix timestamp
 *
 * @return string Time ago text ('0 seconds' for timestamps in the future)
 */
function elapsedTime(int $ptime) {
    $etime = time() - $ptime;

    if ($etime < 1) {
        return '0 seconds';
    }

    $a = array( 365 * 24 * 60 * 60  =>  'year',
                 30 * 24 * 60 * 60  =>  'month',
                      24 * 60 * 60  =>  'day',
                           60 * 60  =>  'hour',
                                60  =>  'minute',
                                 1  =>  'second'
                );
    $a_plural = array( 'year'   => 'years',
                       'month'  => 'months',
                       'day'    => 'days',
                       'hour'   => 'hours',
                       'minute' => 'minutes',
                       'second' => 'seconds'
                );

    foreach ($a as $secs => $str) {
        $d = $etime / $secs;
        if ($d >= 1) {
            $r = round($d);
            return $r . ' ' . ($r > 1 ? $a_plural[$str] : $str);
        }
    }
}

/**
 * maskIpAddress Hides the last part of a peer address (GDPR)
 *
 * IPv4 and IPv6: the last group is replaced by "***", the port is kept:
 * "203.0.113.7:8333" -> "203.0.113.***:8333",
 * "[2001:db8::1]:8333" -> "2001:db8::***:8333". Without a port, "n/a" is used
 * as port. A zone id ("%eth0") is removed and never shown.
 * Everything else (host names, *.onion, "localhost", broken values) is hidden
 * completely: "***", plus ":<port>" only if the address has a clear numeric port.
 * The address is split by parsePeerAddress() (data_lib.php).
 *
 * @param string $ip_address Peer address from getpeerinfo, with or without port
 * @param bool   $showPort   Add the port (display_peer_port); false: address only
 *
 * @return string Masked address as plain text (the template escapes it)
 */
function maskIpAddress(string $ip_address, bool $showPort = true): string {
    $parts = parsePeerAddress($ip_address);
    $port  = $parts['port'];

    if ($parts['kind'] === 'ipv4') {
        $separator = '.';
    } elseif ($parts['kind'] === 'ipv6' || $parts['kind'] === 'cjdns') {
        $separator = ':';
    } else {
        return hiddenPeerAddress($port, $showPort);   // no IP address: hide it completely
    }

    // All groups except the last one, then "***" instead of the last group
    $groups = explode($separator, $parts['host']);
    $masked = implode($separator, array_slice($groups, 0, count($groups) - 1)) . $separator . '***';

    return $showPort ? $masked . ':' . ($port ?? 'n/a') : $masked;
}

/**
 * hiddenPeerAddress Returns "***" for a completely hidden peer address
 *
 * @param string|null $port     Port of the address (parsePeerAddress), null = none
 * @param bool        $showPort Add the port (display_peer_port)
 *
 * @return string "***" or "***:<port>" (only a clear numeric port is shown)
 */
function hiddenPeerAddress(?string $port, bool $showPort): string {
    $hasPort = $port !== null && preg_match('/^[0-9]{1,5}$/', $port) === 1;
    return ($showPort && $hasPort) ? '***:' . $port : '***';
}

/**
 * formatPeerAddress Returns the address of a peer as shown in the peer table
 *
 * Masked (display_masked_ip): see maskIpAddress(); Tor and I2P peers are always
 * hidden completely, also an inbound Tor peer with the address 127.0.0.1.
 * Not masked: the full address, without port and brackets if the port is off.
 * Tor, I2P and CJDNS peers are marked: "*** (Tor)", "fc00:...::***:8333 (CJDNS)".
 *
 * @param string $address  Peer address from getpeerinfo
 * @param string $network  Network of the peer (peerNetwork)
 * @param bool   $mask     Mask the address (display_masked_ip)
 * @param bool   $showPort Show the port (display_peer_port)
 *
 * @return string Address as plain text (the template escapes it)
 */
function formatPeerAddress(string $address, string $network, bool $mask, bool $showPort): string {
    $labels = array('onion' => 'Tor', 'i2p' => 'I2P', 'cjdns' => 'CJDNS');
    $parts  = parsePeerAddress($address);

    if ($mask && ($network === 'onion' || $network === 'i2p')) {
        $text = hiddenPeerAddress($parts['port'], $showPort);
    } elseif ($mask) {
        $text = maskIpAddress($address, $showPort);
    } elseif ($showPort) {
        $text = $address;
    } else {
        $text = $parts['host'] . ($parts['zone'] !== '' ? '%' . $parts['zone'] : '');
    }

    return isset($labels[$network]) ? $text . ' (' . $labels[$network] . ')' : $text;
}

/**
 * peerAddressSortKey Returns the sort key of the address column of the peer table
 *
 * Sorted by network first (IPv4, IPv6, CJDNS, Tor, I2P, others), then by the
 * shown address. The key is built only from the shown text, so it never
 * contains a masked part of the address. Numbers of IPv4 addresses are
 * padded ("1.2.3.***" before "10.2.3.***").
 *
 * @param string $shown Address as shown (formatPeerAddress)
 * @param string $group 'onion', 'i2p', 'cjdns' or the kind of the address (parsePeerAddress)
 *
 * @return string Sort key
 */
function peerAddressSortKey(string $shown, string $group): string {
    $order = array('ipv4' => 1, 'ipv6' => 2, 'cjdns' => 3, 'onion' => 4, 'i2p' => 5);
    $key   = strtolower($shown);
    if ($group === 'ipv4') {
        $key = (string) preg_replace_callback('/[0-9]+/', static fn (array $m): string => sprintf('%05d', (int) $m[0]), $key);
    }
    return ($order[$group] ?? 9) . ' ' . $key;
}

/**
 * isDarkPeer Tells whether a peer is not connected over the public internet
 *
 * Used for 'hide_dark_peers': localhost (127.0.0.0/8, ::1, "localhost"),
 * Tor, I2P and CJDNS. Private networks (e.g. 10.0.0.0/8) are not "dark".
 *
 * @param string $host    Host of the address (parsePeerAddress)
 * @param string $network Network of the peer (peerNetwork)
 *
 * @return bool
 */
function isDarkPeer(string $host, string $network): bool {
    if (in_array($network, array('onion', 'i2p', 'cjdns'), true)) {
        return true;
    }
    return $host === '::1' || strtolower($host) === 'localhost' || preg_match('/^127\.[0-9.]+$/', $host) === 1;
}

/**
 * peerAgentSortKey Returns the sort key of the column "Version / Client"
 *
 * Sorted by the user agent (client names in their order, e.g. "Satoshi" then
 * "Knots"), versions numerically (every number padded to 8 digits, so 29.2.0
 * comes before 30.0.0), then by the protocol version. Comments in brackets
 * are ignored. Peers with only a protocol version come after all clients,
 * peers without both ("n/a") at the end.
 *
 * @param string $subver  User agent from getpeerinfo, e.g. "/Satoshi:29.2.0/Knots:20251010/"
 * @param int    $version Protocol version, e.g. 70016 (0 = unknown)
 *
 * @return string Sort key, e.g. "satoshi:00000029.00000002.00000000/ 00070016"
 */
function peerAgentSortKey(string $subver, int $version): string {
    $pad   = static fn (array $m): string => sprintf('%08d', (int) substr($m[0], -8));
    $agent = strtolower(trim((string) preg_replace('/\([^)]*\)/', '', $subver), " /"));
    $agent = (string) preg_replace_callback('/[0-9]+/', $pad, $agent);
    $proto = ($version > 0) ? sprintf('%08d', $version) : '';

    if ($agent !== '') {
        return $agent . ' ' . $proto;
    }
    return ($proto !== '') ? '~0 ' . $proto : '~~';   // "~" sorts behind every letter and digit
}

