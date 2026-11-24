<?php
/**
 * FMCE Node Status
 * Shared helpers for the data files in data/ (read, write atomically, time series,
 * chart definitions, collector settings and collector status). Used by the collector,
 * the page and chartdata.php. This file only defines functions.
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
 * CoAuthor: Claude / Claude Code - Opus 5.5
 * Part of FMCE Node Status, a derivative of https://github.com/craigwatson/bitcoind-status
 *
 * Compatible with PHP 8.0+, optimised for PHP 8.4 / 8.5.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

// btcnodes.io allows 10 API requests per day and IP address (then HTTP 429).
// The collector sends at most BTCNODES_MAX_REQUESTS in any 24 hours, also with
// --force or --job=, and never runs the job more often than every 3 hours.
const BTCNODES_MAX_REQUESTS = 8;
const BTCNODES_MIN_INTERVAL = 10800;

/**
 * collectorJobDefaults Returns the default settings of all collector jobs
 *
 * Every value can be overwritten in $config['collector']['jobs'][<job>].
 * 'type' is fixed: 'snapshot' keeps only the latest answer, 'series' appends
 * one data point per run and removes points older than 'max_age', 'cache'
 * (geolocation) keeps one entry per IP address until it expires.
 *
 * @param string $dataDir Directory for the data files (usually $config['cache_dir'])
 *
 * @return array Settings per job name
 */
function collectorJobDefaults(string $dataDir): array {
    $dir = rtrim($dataDir, '/');

    return [
        'blockchaininfo' => [
            'label' => 'Blockchain data', 'type' => 'snapshot', 'enabled' => true,
            'interval' => 60, 'file' => $dir . '/nodestat-blockchaininfo.data',
        ],
        'networkinfo' => [
            'label' => 'Network data', 'type' => 'snapshot', 'enabled' => true,
            'interval' => 60, 'file' => $dir . '/nodestat-networkinfo.data',
        ],
        'peerinfo' => [
            'label' => 'Peer list', 'type' => 'snapshot', 'enabled' => true,
            'interval' => 60, 'file' => $dir . '/nodestat-peerinfo.data',
        ],
        'latestblock' => [
            'label' => 'Network height (blockchain.info)', 'type' => 'snapshot', 'enabled' => true,
            'interval' => 60, 'file' => $dir . '/nodestat-latestblock.data',
        ],
        'connections' => [
            'label' => 'Connection chart', 'type' => 'series', 'enabled' => true,
            'interval' => 300, 'max_age' => 604800, 'min_data_points' => 5,
            'file' => $dir . '/nodestat-connections.data',
        ],
        'peercount' => [
            'label' => 'Peer chart', 'type' => 'series', 'enabled' => true,
            'interval' => 300, 'max_age' => 2592000, 'min_data_points' => 10,
            'file' => $dir . '/nodestat-peercount.data',
            'extra_nodes' => [],
        ],
        'fee' => [
            'label' => 'Fee chart', 'type' => 'series', 'enabled' => true,
            'interval' => 300, 'max_age' => 604800, 'min_data_points' => 5,
            'file' => $dir . '/nodestat-fees.data',
            'conf_target' => 2, 'estimate_mode' => 'conservative',
        ],
        'uptime' => [
            'label' => 'Node uptime', 'type' => 'snapshot', 'enabled' => true,
            'interval' => 60, 'file' => $dir . '/nodestat-uptime.data',
        ],
        'diskspace' => [
            'label' => 'Free disk space', 'type' => 'snapshot', 'enabled' => true,
            'interval' => 300, 'file' => $dir . '/nodestat-diskspace.data',
        ],
        'geolocation' => [
            'label' => 'Peer locations (ip2location.io)', 'type' => 'cache', 'enabled' => true,
            'interval' => 300, 'file' => $dir . '/nodestat-geolocation.cache',
            'max_age' => 604800, 'max_lookups' => 20, 'apikey' => '',
        ],
        'cmc_price' => [
            'label' => 'Price chart (CoinMarketCap)', 'type' => 'series', 'enabled' => true,
            'interval' => 300, 'max_age' => 604800, 'min_data_points' => 5,
            'file' => $dir . '/nodestat-cmc_price.data',
            'apikey' => 'keyless', 'symbols' => 'BTC,LTC', 'convert' => 'USD,EUR',
        ],
        'btcnodes' => [
            'label' => 'Node ranking (btcnodes.io)', 'type' => 'snapshot', 'enabled' => true,
            'interval' => BTCNODES_MIN_INTERVAL, 'file' => $dir . '/nodestat-btcnodes.data',
            'node_address' => '', 'api_url' => 'https://btcnodes.io/api/v1',
        ],
    ];
}

/**
 * getChartDefinitions Returns all charts of the page: the only place where a chart is defined
 *
 * The key is the chart id. The page element is "<id>_chart", the switch in
 * config.php is "display_<id>_chart" (missing = on), chartdata.php?stat=<id>
 * delivers the data. The collector job of a chart runs only while the chart
 * is switched on. js/charts.js draws every chart from these settings, so a new
 * chart needs only an entry here and a collector job that writes a time series
 * ({"request_time": int, "request_data": [{"time": int, "<value>": number}, ...]}).
 *
 * Settings per chart:
 * - order      int     position on the page (small numbers first)
 * - name       string  name in notices ("Fee chart: collecting data ...")
 * - title      string|Closure(array $job): string  title; the page adds ": <latest value>"
 * - job        string  collector job with the data (see collectorJobDefaults)
 * - type       string  line, area, stacked_area, column or stacked_column
 * - legend     string  none, top, bottom or right
 * - series     list of ['label' => string|Closure(array $job): string, 'value' => key in a
 *                      data point, nested keys with "." (e.g. "prices.BTC.USD")],
 *              or 'auto': one series per key of the newest data point (except "time")
 * - series_labels      array  'auto' only: display names for keys (e.g. 'other' => 'Other')
 * - label_with_count   bool   'auto' only: series label "<newest value> x <name>"
 * - value_type string  int or float (points with a missing or wrong value are skipped)
 * - round      int|null  round the stored values to n decimals (null = keep)
 * - decimals   int     decimals in tooltips and in the latest value
 * - unit       string  unit after the latest value ('' = none)
 * - color      string  fixed colour of the first series: CSS variable ("--chart-accent")
 *                      or "#rrggbb"; '' = theme palette (css/style.css, --chart-1 ...)
 * - last       string  latest value: 'first' (first series) or 'sum' (all series)
 * - axis       string  optional, labels of the time axis: 'auto' (default: time of day
 *                      if the data cover less than 2 days, else the date), 'date' or 'time'
 *
 * @return array Chart settings per chart id, sorted by 'order'
 */
function getChartDefinitions(): array {
    $charts = [
        'connection' => [
            'order' => 10, 'name' => 'Connection chart', 'title' => 'Connections', 'job' => 'connections',
            'type' => 'line', 'legend' => 'none', 'value_type' => 'int', 'round' => null,
            'series' => [['label' => 'Connections', 'value' => 'connections']],
            'decimals' => 0, 'unit' => '', 'color' => '', 'last' => 'first',
        ],
        'peer' => [
            'order' => 20, 'name' => 'Peer chart', 'job' => 'peercount',
            'title' => static fn (array $job): string => 'Peers by client (' . (int) round($job['max_age'] / 86400) . ' days)',
            'type' => 'line', 'legend' => 'top', 'value_type' => 'int', 'round' => null,
            'series' => 'auto', 'series_labels' => ['other' => 'Other'], 'label_with_count' => true,
            'decimals' => 0, 'unit' => '', 'color' => '', 'last' => 'sum',
        ],
        'fee' => [
            'order' => 30, 'name' => 'Fee chart', 'job' => 'fee',
            'title' => static fn (array $job): string => 'Estimated fee (' . $job['conf_target']
                     . ' block' . ($job['conf_target'] === 1 ? '' : 's') . ')',
            'type' => 'line', 'legend' => 'none', 'value_type' => 'float', 'round' => null,
            // The estimate mode is shown in the tooltip, so the title stays short
            'series' => [['label' => static fn (array $job): string => 'Fee (sat/vB, ' . $job['estimate_mode'] . ')',
                          'value' => 'feerate']],
            'decimals' => 2, 'unit' => 'sat/vB', 'color' => '', 'last' => 'first',
        ],
        'price' => [
            'order' => 40, 'name' => 'Price chart', 'title' => 'Bitcoin price', 'job' => 'cmc_price',
            'type' => 'line', 'legend' => 'none', 'value_type' => 'float', 'round' => 2,
            'series' => [['label' => 'BTC/USD', 'value' => 'prices.BTC.USD']],
            'decimals' => 2, 'unit' => 'USD', 'color' => '--chart-accent', 'last' => 'first',
        ],
    ];

    uasort($charts, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
    return $charts;
}

/**
 * getCollectorConfig Merges the collector settings from config.php with the defaults
 *
 * Missing keys fall back to the defaults, so an old config.php without a
 * 'collector' block keeps working. Old single keys (cmc_price_apikey,
 * peercount_extra_nodes) are still read if the new block does not set them.
 * A job whose data the page does not show (display switch off) is disabled;
 * 'off_reason' of every job tells why it is off ('' = enabled).
 *
 * @param array $config The complete $config array from config.php
 *
 * @return array ['lock_file', 'state_file', 'rpc_timeout', 'http_timeout', 'stale_factor', 'jobs']
 */
function getCollectorConfig(array $config): array {
    $dataDir = (string) ($config['cache_dir'] ?? (__DIR__ . '/../data'));
    $user    = (isset($config['collector']) && is_array($config['collector'])) ? $config['collector'] : [];

    $settings = [
        'lock_file'    => (string) ($user['lock_file'] ?? rtrim($dataDir, '/') . '/nodestat-collector.lock'),
        'state_file'   => (string) ($user['state_file'] ?? rtrim($dataDir, '/') . '/nodestat-collector.state'),
        'rpc_timeout'  => max(1, (int) ($user['rpc_timeout'] ?? 15)),
        'http_timeout' => max(1, (int) ($user['http_timeout'] ?? 10)),
        'stale_factor' => max(1, (int) ($user['stale_factor'] ?? 3)),
        'jobs'         => [],
    ];

    $userJobs = (isset($user['jobs']) && is_array($user['jobs'])) ? $user['jobs'] : [];

    // Fallbacks for keys of the old single cache scripts
    if (!isset($userJobs['cmc_price']['apikey']) && isset($config['cmc_price_apikey'])) {
        $userJobs['cmc_price']['apikey'] = $config['cmc_price_apikey'];
    }
    if (!isset($userJobs['peercount']['extra_nodes']) && isset($config['peercount_extra_nodes'])) {
        $userJobs['peercount']['extra_nodes'] = $config['peercount_extra_nodes'];
    }
    // Geolocation cache: file and lifetime from the general settings
    if (!isset($userJobs['geolocation']['file']) && !empty($config['geo_cache_file'])) {
        $userJobs['geolocation']['file'] = $config['geo_cache_file'];
    }
    if (!isset($userJobs['geolocation']['max_age']) && isset($config['geo_cache_time'])) {
        $userJobs['geolocation']['max_age'] = $config['geo_cache_time'];
    }

    // Jobs that only make sense if the page shows their data: job => [needed, display switch].
    $neededByPage = [
        'uptime'      => [($config['display_node_uptime'] ?? false) === true, 'display_node_uptime'],
        'diskspace'   => [($config['display_free_disk_space'] ?? false) === true, 'display_free_disk_space'],
        'geolocation' => [($config['geolocate_peer_ip'] ?? false) === true
                          || (($config['display_ip_location'] ?? false) === true && ($config['display_ip'] ?? false) === true),
                          'geolocate_peer_ip / display_ip_location'],
        'latestblock' => [($config['display_max_height'] ?? false) === true, 'display_max_height'],
        'btcnodes'    => [($config['display_btcnodes_info'] ?? false) === true, 'display_btcnodes_info'],
    ];
    // Chart jobs follow the chart switches of getChartDefinitions() ("display_<id>_chart",
    // missing = on, same as displayChart()). A job used by several charts runs if one is shown.
    foreach (getChartDefinitions() as $id => $chart) {
        $switch = 'display_' . $id . '_chart';
        $shown  = ($config[$switch] ?? true) === true;
        if (isset($neededByPage[$chart['job']])) {
            $shown  = $shown || $neededByPage[$chart['job']][0];
            $switch = $neededByPage[$chart['job']][1] . ' / ' . $switch;
        }
        $neededByPage[$chart['job']] = [$shown, $switch];
    }

    foreach (collectorJobDefaults($dataDir) as $name => $defaults) {
        $job = array_replace($defaults, (isset($userJobs[$name]) && is_array($userJobs[$name])) ? $userJobs[$name] : []);

        // Fixed values and safe types
        $job['type']     = $defaults['type'];
        $job['label']    = (string) $job['label'];
        $job['enabled']  = (bool) $job['enabled'];
        $job['interval'] = max(60, (int) $job['interval']);
        $job['file']     = (string) $job['file'];
        // Why a job is off (shown by collector.php --status): '' = enabled
        $job['off_reason'] = $job['enabled'] ? '' : "'enabled' => false";
        if ($job['enabled'] && isset($neededByPage[$name]) && !$neededByPage[$name][0]) {
            $job['enabled']    = false;
            $job['off_reason'] = $neededByPage[$name][1] . ' is off';
        }
        if ($job['type'] === 'cache') {
            $job['max_age']     = max(3600, (int) $job['max_age']);
            $job['max_lookups'] = max(1, (int) $job['max_lookups']);
        }
        if ($name === 'fee') {
            // estimatesmartfee: target 1..1008 blocks, mode conservative (stable) or economical (reacts faster)
            $job['conf_target']   = min(1008, max(1, (int) $job['conf_target']));
            $mode                 = strtolower((string) $job['estimate_mode']);
            $job['estimate_mode'] = in_array($mode, ['conservative', 'economical'], true) ? $mode : 'conservative';
        }
        if ($name === 'btcnodes') {
            // Rate limited API: at most BTCNODES_MAX_REQUESTS in 24 hours (see btcnodesQuota)
            $job['interval']     = max(BTCNODES_MIN_INTERVAL, $job['interval']);
            $job['node_address'] = trim((string) $job['node_address']);
            $job['api_url']      = rtrim(trim((string) $job['api_url']), '/');
        }
        if ($job['type'] === 'series') {
            $job['max_age']         = max($job['interval'], (int) $job['max_age']);
            $job['min_data_points'] = max(2, (int) $job['min_data_points']);
        }
        $settings['jobs'][$name] = $job;
    }

    return $settings;
}

/**
 * btcnodesQuota Returns the request quota of the job "btcnodes" (btcnodes.io)
 *
 * The status file keeps the time of every request of the last 24 hours
 * ('requests') and, after an HTTP 429, the time until no request may be sent
 * ('blocked_until'). Used by the collector before every request and by
 * collector.php --status.
 *
 * @param array $jobState Status of the job from the status file
 * @param int   $now      Current timestamp
 *
 * @return array ['requests' => list of timestamps (last 24 h), 'count' => int,
 *               'blocked_until' => int (0 = not blocked), 'next_allowed' => int (timestamp)]
 */
function btcnodesQuota(array $jobState, int $now): array {
    $requests = [];
    foreach ((is_array($jobState['requests'] ?? null) ? $jobState['requests'] : []) as $time) {
        if (is_int($time) && $time > $now - 86400 && $time <= $now) {
            $requests[] = $time;
        }
    }
    sort($requests);

    $blocked = (int) ($jobState['blocked_until'] ?? 0);
    $blocked = ($blocked > $now) ? $blocked : 0;
    $next    = $now;
    if (count($requests) >= BTCNODES_MAX_REQUESTS) {
        // the oldest request that still counts leaves the 24 hours window
        $next = $requests[count($requests) - BTCNODES_MAX_REQUESTS] + 86400;
    }

    return [
        'requests'      => $requests,
        'count'         => count($requests),
        'blocked_until' => $blocked,
        'next_allowed'  => max($next, $blocked),
    ];
}

/**
 * applyTimezone Sets the time zone from config.php if it is a known time zone name
 *
 * An unknown name (e.g. a typo) is ignored without a notice; the default time
 * zone of the server stays active (date.timezone in php.ini, otherwise UTC).
 * Old names such as "US/Eastern" are accepted as well. getTimezoneWarning()
 * reports an unknown name (collector --status, page with 'debug' => true).
 *
 * @param array $config The complete $config array
 *
 * @return bool true if the time zone of the config was set
 */
function applyTimezone(array $config): bool {
    $timezone = $config['timezone'] ?? '';
    if (!is_string($timezone) || !isKnownTimezone($timezone)) {
        return false;
    }
    return date_default_timezone_set($timezone);
}

/**
 * isKnownTimezone Checks a time zone name (including old names such as "US/Eastern")
 *
 * @param string $timezone Time zone name, e.g. "Europe/Berlin"
 *
 * @return bool
 */
function isKnownTimezone(string $timezone): bool {
    return $timezone !== '' && in_array($timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true);
}

/**
 * getTimezoneWarning Returns a warning if 'timezone' is set in config.php but unknown
 *
 * Call it after applyTimezone(): the text names the time zone that is used
 * instead. A missing or empty 'timezone' gives no warning (server default).
 *
 * @param array $config The complete $config array
 *
 * @return string e.g. "timezone 'Europe/Berln' unknown, using UTC", '' if all is well
 */
function getTimezoneWarning(array $config): string {
    $timezone = $config['timezone'] ?? '';
    if ($timezone === '' || (is_string($timezone) && isKnownTimezone($timezone))) {
        return '';
    }
    $name = is_scalar($timezone) ? (string) $timezone : get_debug_type($timezone);
    return "timezone '" . $name . "' unknown, using " . date_default_timezone_get();
}

/**
 * readDataFile Reads one data file written by the collector
 *
 * @param string $file Path of the data file
 *
 * @return array|null ['time' => int, 'data' => mixed, 'raw' => array] or null if the
 *                    file is missing, empty or not valid JSON
 */
function readDataFile(string $file): ?array {
    if ($file === '' || !is_file($file)) {
        return null;
    }

    // "@": the file can vanish or be unreadable between is_file() and reading; false is handled below
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !array_key_exists('request_data', $decoded)) {
        return null;
    }

    return [
        'time' => (int) ($decoded['request_time'] ?? 0),
        'data' => $decoded['request_data'],
        'raw'  => $decoded,
    ];
}

/**
 * readSeries Reads the data points of a time series file
 *
 * Invalid entries (no array or no integer 'time') are skipped.
 *
 * @param string $file Path of the series file
 *
 * @return array List of data points, oldest first (empty if no data)
 */
function readSeries(string $file): array {
    $content = readDataFile($file);
    if ($content === null || !is_array($content['data'])) {
        return [];
    }

    $points = [];
    foreach ($content['data'] as $entry) {
        if (is_array($entry) && is_int($entry['time'] ?? null)) {
            $points[] = $entry;
        }
    }
    usort($points, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

    return $points;
}

/**
 * purgeSeries Removes data points older than $maxAge seconds and sorts by time
 *
 * @param array $points List of data points with an integer 'time'
 * @param int   $maxAge Retention time in seconds
 * @param int   $now    Current timestamp
 *
 * @return array Remaining data points, oldest first
 */
function purgeSeries(array $points, int $maxAge, int $now): array {
    $limit  = $now - $maxAge;
    $points = array_values(array_filter($points, static fn (array $p): bool => $p['time'] >= $limit));
    usort($points, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

    return $points;
}

/**
 * readGeoCache Reads the geolocation cache (one entry per IP address)
 *
 * Format: { "1.2.3.4": {"country_code": "DE", "country_name": "Germany", "expiry": 1731600000}, ... }
 * Expired and invalid entries are left out.
 *
 * @param string $file Path of the cache file
 * @param int    $now  Current timestamp
 *
 * @return array Valid entries per IP address
 */
function readGeoCache(string $file, int $now): array {
    if ($file === '' || !is_file($file)) {
        return [];
    }
    // "@": the file can vanish or be unreadable between is_file() and reading; '' gives null below
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) {
        return [];
    }

    $cache = [];
    foreach ($decoded as $ip => $entry) {
        if (is_array($entry) && (int) ($entry['expiry'] ?? 0) > $now && isset($entry['country_code'], $entry['country_name'])) {
            $cache[(string) $ip] = $entry;
        }
    }
    return $cache;
}

/**
 * parsePeerAddress Splits a peer address from getpeerinfo into its parts
 *
 * The only place where peer addresses are taken apart (masking, display,
 * geolocation, peer filters). Rules:
 * - a port is only read after "]:" (IPv6 in brackets) or after the only ":"
 *   of an IPv4 address or host name; IPv6 without brackets has no port,
 * - a zone id ("fe80::1%eth0") is split off the host.
 * Kinds: 'ipv4', 'ipv6', 'cjdns' (IPv6 in fc00::/8), 'onion' (*.onion),
 * 'i2p' (*.b32.i2p) and 'other' (host names, broken values).
 *
 * @param string $address Peer address, e.g. "1.2.3.4:8333", "[2001:db8::1]:8333", "abc.onion:8333"
 *
 * @return array ['host' => string (no brackets, no zone id), 'port' => string|null,
 *               'zone' => string ('' = none), 'kind' => string]
 */
function parsePeerAddress(string $address): array {
    $port = null;   // null = no port in the address
    if (preg_match('/^\[([^\]]*)\](?::(.*))?$/s', $address, $matches)) {
        $host = $matches[1];                       // "[address]" or "[address]:port"
        $port = $matches[2] ?? null;
    } elseif (substr_count($address, ':') === 1) {
        [$host, $port] = explode(':', $address);   // IPv4 or host name with port
    } else {
        $host = $address;                          // no port, or IPv6 without brackets
    }

    // Zone id of a link-local IPv6 address ("fe80::1%eth0")
    $zone = '';
    $at   = strpos($host, '%');
    if ($at !== false) {
        $zone = substr($host, $at + 1);
        $host = substr($host, 0, $at);
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $kind = 'ipv4';
    } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $kind = (ord(substr((string) inet_pton($host), 0, 1)) === 0xfc) ? 'cjdns' : 'ipv6';   // CJDNS uses fc00::/8
    } elseif (preg_match('/\.onion$/i', $host)) {
        $kind = 'onion';
    } elseif (preg_match('/\.b32\.i2p$/i', $host)) {
        $kind = 'i2p';
    } else {
        $kind = 'other';
    }

    return ['host' => $host, 'port' => $port, 'zone' => $zone, 'kind' => $kind];
}

/**
 * peerNetwork Returns the network of a peer (Tor, I2P, CJDNS, IPv4, IPv6, ...)
 *
 * Bitcoin Core sends the field 'network' ("ipv4", "ipv6", "onion", "i2p",
 * "cjdns", "not_publicly_routable", ...). Older versions do not have it; then
 * the network is derived from the address (parsePeerAddress). An inbound Tor
 * peer has the address 127.0.0.1 and is only recognised by this field.
 *
 * @param array $peer One peer from getpeerinfo
 *
 * @return string Network name in lower case, e.g. 'onion'
 */
function peerNetwork(array $peer): string {
    $network = $peer['network'] ?? null;
    if (is_string($network) && preg_match('/^[a-z0-9_]{1,30}$/', strtolower($network))) {
        return strtolower($network);
    }
    return parsePeerAddress(is_string($peer['addr'] ?? null) ? $peer['addr'] : '')['kind'];
}

/**
 * peerIpFromAddress Returns the public IP address of a peer for the geolocation
 *
 * Tor, I2P and CJDNS peers are never located. Private, reserved and
 * link-local addresses, host names and broken values give ''.
 *
 * @param string $address Peer address from getpeerinfo
 * @param string $network Network of the peer (peerNetwork), '' = derive from the address
 *
 * @return string Public IPv4 or IPv6 address without port and brackets, '' if there is none
 */
function peerIpFromAddress(string $address, string $network = ''): string {
    $parts = parsePeerAddress($address);
    if (in_array($network, ['onion', 'i2p', 'cjdns'], true) || !in_array($parts['kind'], ['ipv4', 'ipv6'], true)) {
        return '';
    }
    $public = filter_var($parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    return ($public !== false) ? $parts['host'] : '';
}

/**
 * saveJsonAtomic Writes an array as JSON so that readers never see a half written file
 *
 * The data goes to a temporary file in the same directory first, which is then
 * renamed. rename() is atomic on the same file system. Invalid UTF-8 in a value
 * (e.g. a broken peer user agent) is replaced by U+FFFD instead of failing,
 * so one bad value cannot stop a whole job or the page cache.
 *
 * @param string $file    Target file
 * @param array  $payload Data to store
 *
 * @return void
 */
function saveJsonAtomic(string $file, array $payload): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create directory: $dir");
    }

    $json = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    );
    $tmp  = tempnam($dir, '.nodestat_');
    if ($tmp === false) {
        throw new RuntimeException("Cannot create temp file in $dir");
    }

    try {
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            throw new RuntimeException("Incomplete write to $tmp");
        }
        chmod($tmp, 0644);   // the web server user must be able to read the file
        if (!rename($tmp, $file)) {
            throw new RuntimeException("rename() to $file failed");
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);   // "@": only cleanup after an error, the error itself is already thrown
        }
    }
}

/**
 * readCollectorState Reads the status file of the collector
 *
 * @param string $file Path of the status file
 *
 * @return array ['updated' => int, 'jobs' => [name => [...]]] (empty jobs if missing)
 */
function readCollectorState(string $file): array {
    $state = ['updated' => 0, 'jobs' => []];
    if (!is_file($file)) {
        return $state;
    }

    // "@": the file can vanish or be unreadable between is_file() and reading; '' gives null below
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (is_array($decoded)) {
        $state['updated'] = (int) ($decoded['updated'] ?? 0);
        $state['jobs']    = (isset($decoded['jobs']) && is_array($decoded['jobs'])) ? $decoded['jobs'] : [];
    }

    return $state;
}

/**
 * getCollectorStatus Evaluates the collector status for the page
 *
 * A job is 'stale' if it never succeeded or its last success is older than
 * interval x stale_factor. Error texts are only meant for debugging; the page
 * shows them only if $config['debug'] is true (they can contain host names).
 *
 * @param array $config The complete $config array
 * @param int   $now    Current timestamp
 *
 * @return array ['never_run' => bool, 'problems' => list of [label, last_success, error], 'last_run' => int]
 */
function getCollectorStatus(array $config, int $now): array {
    $settings = getCollectorConfig($config);
    $state    = readCollectorState($settings['state_file']);
    $status   = [
        'never_run' => ($state['updated'] === 0),
        'last_run'  => $state['updated'],
        'problems'  => [],
    ];

    foreach ($settings['jobs'] as $name => $job) {
        if (!$job['enabled']) {
            continue;
        }
        $jobState    = (isset($state['jobs'][$name]) && is_array($state['jobs'][$name])) ? $state['jobs'][$name] : [];
        $lastSuccess = (int) ($jobState['last_success'] ?? 0);
        $tooOld      = ($lastSuccess === 0) || ($now - $lastSuccess > $job['interval'] * $settings['stale_factor']);

        if ($tooOld) {
            $status['problems'][] = [
                'job'          => $name,
                'label'        => $job['label'],
                'last_success' => $lastSuccess,
                'error'        => (string) ($jobState['last_error'] ?? ''),
            ];
        }
    }

    return $status;
}
