<?php
/**
 * FMCE Node Status
 * Collector: fetches all data for the status page and stores it in data/.
 * Replaces the former cache_*.php scripts. Command line (cron) only.
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

// Usage (one cron job, every minute; each data source has its own interval in the config):
//   * * * * *  php /path/to/webroot/php/collector.php > /dev/null 2>&1
//
// Options:
//   (none)          run all jobs that are due
//   --force         run all enabled jobs now (lists the disabled ones as SKIP)
//   --job=fee,...   run only these jobs now, also if they are disabled
//   --status        show the status of all jobs (no fetching)
//                   and a warning if 'timezone' in config.php is unknown
//   --view=fee      show the data file of one job
//   --clear=fee     delete the data file of one job
//   --help          show this help
//
// A job is disabled by 'enabled' => false or when the page does not show its
// data (e.g. 'display_fee_chart' => false switches off the job "fee").
//
// Flow of one run: lock -> find due jobs -> one parallel RPC batch for all node
// data -> HTTP sources -> every job saves its own file (atomic) -> status file.
// Every job runs in its own try/catch: a failing source never stops the others,
// and on failure the old data file stays unchanged.
//
// The job "btcnodes" (rank at btcnodes.io, 10 API requests per day and IP) sends
// at most 8 requests in 24 hours, also with --force and --job=; after HTTP 429 none
// until the time of Retry-After. Such a job prints "WAIT" instead of a request.
//
// Exit codes: 0 = ok, 1 = at least one job failed, 2 = configuration or usage error.

// ---------- CLI only: no access through the web server ----------
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Forbidden\n";
    exit;
}

require_once __DIR__ . '/data_lib.php';

// Jobs that need the Bitcoin node, and the RPC call each of them needs.
// Several jobs may share one call (the call is made only once per run).
const COLLECTOR_RPC_JOBS = [
    'blockchaininfo' => 'getblockchaininfo',
    'networkinfo'    => 'getnetworkinfo',
    'connections'    => 'getnetworkinfo',
    'peerinfo'       => 'getpeerinfo',
    'peercount'      => 'getpeerinfo',
    'fee'            => 'estimatesmartfee',
    'uptime'         => 'uptime',
];

// Default peer client types for the peer chart: label => text in the "subver" of the peer
const COLLECTOR_PEER_TYPES = [
    'Core'         => 'Satoshi',
    'Core (Knots)' => 'Knots',
    'BitCoinJ'     => 'BitcoinJ',
    'btcd'         => 'btcd',
];

const COLLECTOR_USER_AGENT = 'FMCE-Node-Status/1.0 (+https://github.com/FMaroke/node-status)';

/**
 * CollectorJobDeferred A job that must not run now (e.g. the request limit of an API is reached)
 *
 * This is no error: the collector prints "WAIT" with the reason, the exit code
 * stays 0 and the data file stays unchanged.
 */
class CollectorJobDeferred extends RuntimeException {
}

/* ------------------------------------------------------------------ *
 * Output and error handling
 * ------------------------------------------------------------------ */

/**
 * out Prints one line on the console
 *
 * @param string $message Text to print
 *
 * @return void
 */
function out(string $message): void {
    echo $message, "\n";
}

// Warnings and notices become exceptions, so they are caught by the try/catch of
// the job. Deprecations are ignored on purpose: they must never stop data collection.
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno) || $errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Fatal errors (memory, time limit) cannot be caught: at least report them.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        out(sprintf('FATAL: %s in %s:%d', $e['message'], basename($e['file']), $e['line']));
    }
});

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */

/**
 * parseOptions Reads the command line options
 *
 * @param array $argv Command line arguments
 *
 * @return array ['mode' => run|status|view|clear|help, 'force' => bool, 'jobs' => list, 'target' => string]
 */
function parseOptions(array $argv): array {
    $options = ['mode' => 'run', 'force' => false, 'jobs' => [], 'target' => ''];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--force') {
            $options['force'] = true;
        } elseif ($arg === '--status') {
            $options['mode'] = 'status';
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['mode'] = 'help';
        } elseif (strpos($arg, '--job=') === 0) {
            $options['jobs']  = array_filter(array_map('trim', explode(',', substr($arg, 6))));
            $options['force'] = true;
        } elseif (strpos($arg, '--view=') === 0) {
            $options['mode']   = 'view';
            $options['target'] = trim(substr($arg, 7));
        } elseif (strpos($arg, '--clear=') === 0) {
            $options['mode']   = 'clear';
            $options['target'] = trim(substr($arg, 8));
        } else {
            throw new InvalidArgumentException("Unknown option: $arg (see --help)");
        }
    }

    return $options;
}

/**
 * isDue Checks whether a job has to run now
 *
 * A job is due when its last successful run is at least 'interval' seconds ago
 * (5 seconds tolerance for the start time of cron). A failed job is therefore
 * retried with the next cron run. Exception: "btcnodes" counts from the last
 * request (btcnodes.io allows only a few requests per day).
 *
 * @param array  $job      Job settings
 * @param array  $jobState Status of the job from the status file
 * @param int    $now      Current timestamp
 * @param string $name     Job name
 *
 * @return bool
 */
function isDue(array $job, array $jobState, int $now, string $name = ''): bool {
    $lastRun = (int) ($jobState['last_success'] ?? 0);
    if ($name === 'btcnodes') {
        // Rate limited API: a failed request is not repeated before the next interval
        $lastRun = max($lastRun, (int) ($jobState['last_request'] ?? 0));
    }
    return ($now - $lastRun) >= ($job['interval'] - 5);
}

/**
 * httpGetJson Fetches a URL and decodes the JSON answer
 *
 * Network errors, HTTP 429 and HTTP 5xx are retried (pause 2 s); all other
 * HTTP errors fail at once. The TLS certificate is always verified.
 *
 * @param string $url      URL to fetch
 * @param array  $headers  Additional HTTP headers
 * @param int    $timeout  Total timeout per attempt in seconds
 * @param int    $maxTries Number of attempts
 *
 * @return array ['http_code' => int, 'data' => array]
 */
function httpGetJson(string $url, array $headers, int $timeout, int $maxTries = 2): array {
    $lastError = 'unknown error';

    for ($try = 1; $try <= $maxTries; $try++) {
        if ($try > 1) {
            sleep(2);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_ENCODING       => '',        // gzip/deflate automatically
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => COLLECTOR_USER_AGENT,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);   // instead of curl_close() (deprecated as of PHP 8.5)

        if (!is_string($body)) {
            $lastError = 'cURL error: ' . $curlErr;
            continue;
        }
        if ($httpCode === 429 || $httpCode >= 500) {
            $lastError = "HTTP $httpCode";
            continue;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON answer (HTTP $httpCode)", 0, $e);
        }
        if (!is_array($data)) {
            throw new RuntimeException("Unexpected answer (HTTP $httpCode)");
        }

        return ['http_code' => $httpCode, 'data' => $data];
    }

    throw new RuntimeException("Request failed after $maxTries attempts ($lastError)");
}

/**
 * httpGetText Fetches a small text file (e.g. a number written by a script on the node server)
 *
 * @param string $url     URL of the text file (http or https)
 * @param int    $timeout Total timeout in seconds
 *
 * @return string Content without surrounding white space
 */
function httpGetText(string $url, int $timeout): string {
    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('No valid URL configured');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init() failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,       // the certificate of the server is checked
        CURLOPT_USERAGENT      => COLLECTOR_USER_AGENT,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    unset($ch);   // instead of curl_close() (deprecated as of PHP 8.5)

    if (!is_string($body)) {
        throw new RuntimeException('cURL error: ' . $curlErr);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("HTTP $httpCode");
    }
    return trim($body);
}

/**
 * httpRequest Sends one GET request and returns status, body and headers
 *
 * Exactly one attempt (no retry), so a rate limited API is asked only once.
 * Network errors and timeouts throw a RuntimeException; every HTTP status is
 * returned to the caller.
 *
 * @param string $url     URL to fetch (http or https)
 * @param array  $headers Additional HTTP headers
 * @param int    $timeout Total timeout in seconds
 *
 * @return array ['http_code' => int, 'body' => string, 'headers' => [lower case name => value]]
 */
function httpRequest(string $url, array $headers, int $timeout): array {
    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('No valid URL configured');
    }

    $responseHeaders = [];
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init() failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => COLLECTOR_USER_AGENT,
        // Collect the response headers (e.g. Retry-After); a redirect would add a second block
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    unset($ch);   // instead of curl_close() (deprecated as of PHP 8.5)

    if (!is_string($body)) {
        throw new RuntimeException('cURL error: ' . $curlErr);
    }
    return ['http_code' => $httpCode, 'body' => $body, 'headers' => $responseHeaders];
}

/**
 * parseRetryAfter Returns until when a server wants no further request (HTTP header Retry-After)
 *
 * The header holds seconds ("3600") or an HTTP date ("Wed, 21 Oct 2026 07:28:00 GMT").
 * A missing or unreadable value means 24 hours.
 *
 * @param string $value Value of the header ('' if missing)
 * @param int    $now   Current timestamp
 *
 * @return int Timestamp, at least one minute from now
 */
function parseRetryAfter(string $value, int $now): int {
    $value = trim($value);
    $until = $now + 86400;
    if ($value !== '' && ctype_digit($value)) {
        $until = $now + (int) $value;
    } elseif ($value !== '') {
        $time  = strtotime($value);
        $until = ($time !== false) ? $time : $until;
    }
    return max($now + 60, $until);
}

/**
 * loadSeriesForAppend Reads an existing time series before a new point is added
 *
 * A broken file is renamed to *.corrupt-<date> and a new series is started.
 *
 * @param string $file     Path of the series file
 * @param array  $warnings Collected warnings (passed by reference)
 *
 * @return array List of data points
 */
function loadSeriesForAppend(string $file, array &$warnings): array {
    if (is_file($file) && readDataFile($file) === null && filesize($file) > 0) {
        $backup = $file . '.corrupt-' . date('Ymd-His');
        rename($file, $backup);
        $warnings[] = 'Data file was not valid JSON, saved as ' . basename($backup);
        return [];
    }
    return readSeries($file);
}

/**
 * saveSeries Appends one data point, removes old points and saves the series
 *
 * @param array $job   Job settings ('file', 'max_age')
 * @param array $point New data point (must contain 'time')
 * @param array $extra Additional top-level fields for the file (optional)
 *
 * @return string Short result text for the console
 */
function saveSeries(array $job, array $point, array $extra = []): string {
    $warnings = [];
    $points   = loadSeriesForAppend($job['file'], $warnings);
    $points[] = $point;
    $points   = purgeSeries($points, $job['max_age'], $point['time']);

    saveJsonAtomic($job['file'], array_merge(
        ['request_time' => $point['time']],
        $extra,
        ['request_data' => $points]
    ));

    $text = count($points) . ' data points';
    return $warnings ? $text . ' (' . implode('; ', $warnings) . ')' : $text;
}

/**
 * saveSnapshot Saves the latest answer of a source
 *
 * @param array $job  Job settings ('file')
 * @param mixed $data Data to store
 * @param int   $now  Current timestamp
 *
 * @return string Short result text for the console
 */
function saveSnapshot(array $job, $data, int $now): string {
    saveJsonAtomic($job['file'], ['request_time' => $now, 'request_data' => $data]);
    return 'saved';
}

/* ------------------------------------------------------------------ *
 * Jobs: each one checks its input and throws an exception on bad data
 * ------------------------------------------------------------------ */

/**
 * fetchRpcResults Executes all needed RPC calls in one parallel batch
 *
 * @param array $config   The complete $config array (RPC login)
 * @param array $settings Collector settings
 * @param array $jobNames Names of the jobs that run now
 *
 * @return array Result per RPC method (result, or ['error' => message])
 */
function fetchRpcResults(array $config, array $settings, array $jobNames): array {
    $calls = [];
    foreach ($jobNames as $name) {
        if (!isset(COLLECTOR_RPC_JOBS[$name])) {
            continue;
        }
        $method = COLLECTOR_RPC_JOBS[$name];
        $params = [];
        if ($method === 'estimatesmartfee') {
            $fee    = $settings['jobs']['fee'];
            $params = [$fee['conf_target'], strtoupper($fee['estimate_mode'])];
        }
        $calls[$method] = ['method' => $method, 'params' => $params];
    }
    if ($calls === []) {
        return [];
    }

    foreach (['rpc_user', 'rpc_pass', 'rpc_host', 'rpc_port'] as $key) {
        if (!isset($config[$key])) {
            throw new RuntimeException("Missing config value: $key");
        }
    }

    require_once __DIR__ . '/easybitcoin.php';
    $bitcoin = new Bitcoin($config['rpc_user'], $config['rpc_pass'], $config['rpc_host'], $config['rpc_port']);
    if (($config['rpc_ssl'] ?? false) === true) {
        $bitcoin->setSSL($config['rpc_ssl_ca'] ?? null);
    }
    $bitcoin->setTimeouts($settings['rpc_timeout'], min(5, $settings['rpc_timeout']));

    return $bitcoin->batch($calls);
}

/**
 * rpcResult Returns the RPC result for a job or throws the RPC error
 *
 * @param array  $rpcResults Results of fetchRpcResults()
 * @param string $jobName    Job name
 *
 * @return mixed The RPC result
 */
function rpcResult(array $rpcResults, string $jobName) {
    $method = COLLECTOR_RPC_JOBS[$jobName];
    if (!array_key_exists($method, $rpcResults)) {
        throw new RuntimeException("No RPC result for $method");
    }
    $result = $rpcResults[$method];
    if (Bitcoin::isBatchError($result)) {
        throw new RuntimeException("RPC $method: " . $result['error']);
    }
    return $result;
}

/**
 * jobBlockchainInfo Stores getblockchaininfo
 *
 * @param array $job    Job settings
 * @param mixed $result RPC result
 * @param int   $now    Current timestamp
 *
 * @return string Result text
 */
function jobBlockchainInfo(array $job, $result, int $now): string {
    if (!is_array($result) || !is_int($result['blocks'] ?? null)) {
        throw new RuntimeException('getblockchaininfo: unexpected answer');
    }
    return saveSnapshot($job, $result, $now);
}

/**
 * jobNetworkInfo Stores getnetworkinfo
 *
 * @param array $job    Job settings
 * @param mixed $result RPC result
 * @param int   $now    Current timestamp
 *
 * @return string Result text
 */
function jobNetworkInfo(array $job, $result, int $now): string {
    if (!is_array($result) || !is_int($result['connections'] ?? null)) {
        throw new RuntimeException('getnetworkinfo: unexpected answer');
    }
    return saveSnapshot($job, $result, $now);
}

/**
 * jobPeerInfo Stores getpeerinfo (list of connected peers)
 *
 * @param array $job    Job settings
 * @param mixed $result RPC result
 * @param int   $now    Current timestamp
 *
 * @return string Result text
 */
function jobPeerInfo(array $job, $result, int $now): string {
    if (!is_array($result)) {
        throw new RuntimeException('getpeerinfo: unexpected answer');
    }
    return saveSnapshot($job, array_values($result), $now) . ' (' . count($result) . ' peers)';
}

/**
 * jobConnections Adds the number of connections to the connection series
 *
 * @param array $job    Job settings
 * @param mixed $result Result of getnetworkinfo
 * @param int   $now    Current timestamp
 *
 * @return string Result text
 */
function jobConnections(array $job, $result, int $now): string {
    if (!is_array($result) || !is_int($result['connections'] ?? null)) {
        throw new RuntimeException('getnetworkinfo: no connection count');
    }
    return saveSeries($job, ['time' => $now, 'connections' => $result['connections']]);
}

/**
 * jobPeerCount Counts the peers per client type and adds them to the peer series
 *
 * The last matching type wins, so "Knots" (subver also contains "Satoshi")
 * is counted as Knots. Peers without a match are counted as 'other'.
 *
 * @param array $job    Job settings ('extra_nodes' => [label => text])
 * @param mixed $result Result of getpeerinfo
 * @param int   $now    Current timestamp
 *
 * @return string Result text
 */
function jobPeerCount(array $job, $result, int $now): string {
    if (!is_array($result)) {
        throw new RuntimeException('getpeerinfo: unexpected answer');
    }

    $types = COLLECTOR_PEER_TYPES;
    if (is_array($job['extra_nodes'])) {
        foreach ($job['extra_nodes'] as $label => $needle) {
            $types[(string) $label] = (string) $needle;
        }
    }

    $point = ['time' => $now];
    foreach ($types as $label => $needle) {
        $point[$label] = 0;
    }
    $point['other'] = 0;

    foreach ($result as $peer) {
        $subver = strtolower((string) (is_array($peer) ? ($peer['subver'] ?? '') : ''));
        $type   = 'other';
        foreach ($types as $label => $needle) {
            if ($needle !== '' && strpos($subver, strtolower($needle)) !== false) {
                $type = $label;
            }
        }
        $point[$type]++;
    }

    return saveSeries($job, $point);
}

/**
 * jobFee Adds the estimated fee (sat/vB) to the fee series
 *
 * estimatesmartfee returns the fee rate in BTC/kvB: x 100,000 = sat/vB.
 * A fresh node without enough data returns "errors" instead of "feerate".
 * Target and mode (conservative/economical) come from the job settings.
 *
 * @param array $job    Job settings
 * @param mixed $result Result of estimatesmartfee
 * @param int   $now    Current timestamp
 *
 * @return string Result text
 */
function jobFee(array $job, $result, int $now): string {
    if (!is_array($result)) {
        throw new RuntimeException('estimatesmartfee: unexpected answer');
    }
    if (!is_numeric($result['feerate'] ?? null)) {
        $reason = (isset($result['errors']) && is_array($result['errors'])) ? implode('; ', $result['errors']) : 'no feerate';
        throw new RuntimeException('estimatesmartfee: ' . $reason);
    }

    $feerate = round((float) $result['feerate'] * 100000, 2);
    return saveSeries($job, ['time' => $now, 'feerate' => $feerate]);
}

/**
 * jobLatestBlock Stores the latest block height of the network (blockchain.info)
 *
 * Only height, hash and time are stored (the full answer contains all
 * transaction indexes of the block and is not needed).
 *
 * @param array $job      Job settings
 * @param array $settings Collector settings (timeout)
 * @param int   $now      Current timestamp
 *
 * @return string Result text
 */
function jobLatestBlock(array $job, array $settings, int $now): string {
    $answer = httpGetJson('https://blockchain.info/latestblock', [], $settings['http_timeout']);
    $data   = $answer['data'];

    if ($answer['http_code'] !== 200 || !is_int($data['height'] ?? null)) {
        throw new RuntimeException('blockchain.info: unexpected answer (HTTP ' . $answer['http_code'] . ')');
    }

    return saveSnapshot($job, [
        'height' => $data['height'],
        'hash'   => (string) ($data['hash'] ?? ''),
        'time'   => (int) ($data['time'] ?? 0),
    ], $now) . ' (height ' . $data['height'] . ')';
}

/**
 * jobCmcPrice Adds the current CoinMarketCap prices to the price series
 *
 * File format (unchanged): request_time, api_timestamp, mode, prices (latest),
 * request_data [ {time, prices: {BTC: {USD: .., EUR: ..}, ...}}, ... ].
 * Without API key the keyless public endpoint is used. The key never appears
 * in any message.
 *
 * @param array $job      Job settings ('apikey', 'symbols', 'convert')
 * @param array $settings Collector settings (timeout)
 * @param int   $now      Current timestamp
 *
 * @return string Result text
 */
function jobCmcPrice(array $job, array $settings, int $now): string {
    $apiKey  = trim((string) $job['apikey']);
    $useKey  = ($apiKey !== '' && strcasecmp($apiKey, 'keyless') !== 0);
    $baseUrl = $useKey ? 'https://pro-api.coinmarketcap.com' : 'https://pro-api.coinmarketcap.com/public-api';
    $url     = $baseUrl . '/v2/simple/price?' . http_build_query([
        'symbol'  => (string) $job['symbols'],
        'convert' => (string) $job['convert'],
    ]);

    $answer   = httpGetJson($url, $useKey ? ['X-CMC_PRO_API_KEY: ' . $apiKey] : [], $settings['http_timeout']);
    $response = $answer['data'];

    $errorCode = (int) ($response['status']['error_code'] ?? 0);   // delivered as string "0"
    if ($answer['http_code'] !== 200 || $errorCode !== 0) {
        $msg = (string) ($response['status']['error_message'] ?? 'unknown error');
        throw new RuntimeException("CoinMarketCap: HTTP {$answer['http_code']}, code $errorCode: $msg");
    }
    if (!isset($response['data']) || !is_array($response['data']) || $response['data'] === []) {
        throw new RuntimeException('CoinMarketCap: answer contains no data');
    }

    $prices = [];
    foreach ($response['data'] as $coin) {
        if (!is_array($coin) || !isset($coin['symbol']) || !is_array($coin['quotes'] ?? null)) {
            throw new RuntimeException('CoinMarketCap: unexpected data structure');
        }
        foreach ($coin['quotes'] as $quote) {
            if (!isset($quote['symbol']) || !is_numeric($quote['price'] ?? null)) {
                throw new RuntimeException("CoinMarketCap: invalid price for {$coin['symbol']}");
            }
            $prices[(string) $coin['symbol']][(string) $quote['symbol']] = (float) $quote['price'];
        }
    }

    return saveSeries($job, ['time' => $now, 'prices' => $prices], [
        'api_timestamp' => $response['status']['timestamp'] ?? null,
        'mode'          => $useKey ? 'apikey' : 'keyless',
        'prices'        => $prices,
    ]);
}

/**
 * jobUptime Stores how long bitcoind has been running (RPC "uptime", seconds)
 *
 * Works the same for a local and a remote node, no extra script is needed.
 *
 * @param array $job    Job settings
 * @param mixed $result Result of the RPC call "uptime"
 * @param int   $now    Current timestamp
 *
 * @return string Result text
 */
function jobUptime(array $job, $result, int $now): string {
    if (!is_int($result) || $result < 0) {
        throw new RuntimeException('uptime: unexpected answer');
    }
    return saveSnapshot($job, ['seconds' => $result], $now) . " ({$result} s)";
}

/**
 * jobDiskspace Stores the free disk space of the node in bytes
 *
 * Remote node ('node_is_remote' => true): number from the text file at
 * 'free_disk_space_url' (written by remote_files/save_diskspace.php).
 * Local node: disk_free_space() of 'disk_space_mount_point' (a relative path
 * counts from the web root).
 *
 * @param array $job      Job settings
 * @param array $config   The complete $config array
 * @param array $settings Collector settings (timeout)
 * @param int   $now      Current timestamp
 *
 * @return string Result text
 */
function jobDiskspace(array $job, array $config, array $settings, int $now): string {
    if (($config['node_is_remote'] ?? true) === true) {
        $text = httpGetText((string) ($config['free_disk_space_url'] ?? ''), $settings['http_timeout']);
        if (!ctype_digit($text)) {
            throw new RuntimeException('free disk space: the file contains no number');
        }
        $bytes = (int) $text;
    } else {
        $path = (string) ($config['disk_space_mount_point'] ?? '.');
        if ($path === '' || $path[0] !== '/') {
            $path = dirname(__DIR__) . '/' . $path;   // relative to the web root, not to the cron directory
        }
        // "@": a missing path gives a warning and false; false is reported as job error below
        $free = @disk_free_space($path);
        if ($free === false) {
            throw new RuntimeException('free disk space: cannot read ' . $path);
        }
        $bytes = (int) $free;
    }

    if ($bytes <= 0) {
        throw new RuntimeException('free disk space: no valid value');
    }
    return saveSnapshot($job, ['bytes' => $bytes], $now) . ' (' . round($bytes / 1073741824, 1) . ' GiB)';
}

/**
 * jobGeolocation Looks up the country of new peer IP addresses (ip2location.io)
 *
 * Peers are located only with a public IPv4/IPv6 address (peerIpFromAddress):
 * Tor, I2P and CJDNS peers are never sent to the geolocation service.
 * Only addresses that are not in the cache yet are looked up, at most
 * 'max_lookups' per run (free plan without key: limited queries per day).
 * A private or reserved node_ip is stored without a lookup. Entries expire
 * after 'max_age' seconds and are then looked up again.
 *
 * @param array $job      Job settings ('file', 'max_age', 'max_lookups', 'apikey')
 * @param array $config   The complete $config array ('node_ip', display switches)
 * @param array $settings Collector settings (jobs, timeout)
 * @param int   $now      Current timestamp
 *
 * @return string Result text
 */
function jobGeolocation(array $job, array $config, array $settings, int $now): string {
    $cache = readGeoCache($job['file'], $now);

    // Addresses to locate: the node itself and all connected peers
    $addresses = [];
    if (($config['display_ip_location'] ?? false) === true && !empty($config['node_ip'])) {
        $addresses[] = (string) $config['node_ip'];
    }
    if (($config['geolocate_peer_ip'] ?? false) === true) {
        $peers = readDataFile($settings['jobs']['peerinfo']['file']);
        foreach ((is_array($peers['data'] ?? null) ? $peers['data'] : []) as $peer) {
            // Only public IPv4/IPv6 addresses: never Tor, I2P, CJDNS or private networks
            if (is_array($peer) && is_string($peer['addr'] ?? null)) {
                $ip = peerIpFromAddress($peer['addr'], peerNetwork($peer));
                if ($ip !== '') {
                    $addresses[] = $ip;
                }
            }
        }
    }

    $apiKey  = trim((string) $job['apikey']);
    $new     = 0;
    $failed  = 0;
    $lastErr = '';
    foreach (array_unique($addresses) as $ip) {
        if (isset($cache[$ip]) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            continue;   // known, or no IP address (e.g. *.onion)
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $cache[$ip] = ['country_code' => 'blank', 'country_name' => 'Private network', 'expiry' => $now + $job['max_age']];
            continue;
        }
        if ($new + $failed >= $job['max_lookups']) {
            break;      // the rest follows in the next runs
        }

        $query = ['ip' => $ip];
        if ($apiKey !== '') {
            $query['key'] = $apiKey;
        }
        try {
            $answer = httpGetJson('https://api.ip2location.io/?' . http_build_query($query), [], $settings['http_timeout'], 1);
            $code   = $answer['data']['country_code'] ?? null;
            if ($answer['http_code'] !== 200 || !is_string($code) || !preg_match('/^([A-Z]{2}|-)$/', $code)) {
                throw new RuntimeException('unexpected answer (HTTP ' . $answer['http_code'] . ')');
            }
            $cache[$ip] = [
                'country_code' => ($code === '-') ? 'blank' : $code,
                'country_name' => ($code === '-') ? 'Unavailable' : (string) ($answer['data']['country_name'] ?? $code),
                'expiry'       => $now + $job['max_age'],
            ];
            $new++;
        } catch (Throwable $e) {
            $failed++;
            $lastErr = $e->getMessage();
            if (strpos($lastErr, 'HTTP 429') !== false) {
                break;  // daily limit reached: try again later
            }
        }
    }

    saveJsonAtomic($job['file'], $cache);

    if ($failed > 0 && $new === 0) {
        throw new RuntimeException("ip2location.io: $failed lookups failed ($lastErr)");
    }
    return count($cache) . " addresses cached, $new new" . ($failed ? ", $failed failed" : '');
}

/**
 * btcnodesNode Returns the node of the job "btcnodes" in the form used by btcnodes.io ("1.2.3.4-8333")
 *
 * The address comes from 'node_address' ("1.2.3.4:8333"). If it is empty, the
 * first public IPv4 address in 'localaddresses' of getnetworkinfo is used (the
 * node only knows it with listen=1 and discover or externalip). Only IPv4 is
 * supported: the btcnodes.io path for IPv6 and Tor addresses is not verified.
 *
 * @param array $job      Job settings ('node_address')
 * @param array $settings Collector settings (file of the job "networkinfo")
 *
 * @return string Node for the URL, e.g. "203.0.113.5-8333"
 */
function btcnodesNode(array $job, array $settings): string {
    $address = $job['node_address'];
    if ($address === '') {
        $net = readDataFile($settings['jobs']['networkinfo']['file']);
        $local = (is_array($net['data'] ?? null) && is_array($net['data']['localaddresses'] ?? null)) ? $net['data']['localaddresses'] : [];
        foreach ($local as $entry) {
            $ip = is_array($entry) ? (string) ($entry['address'] ?? '') : '';
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                $address = $ip . ':' . (int) ($entry['port'] ?? 8333);
                break;
            }
        }
        if ($address === '') {
            throw new RuntimeException("no node address: set 'node_address' (e.g. '203.0.113.5:8333') in the job settings");
        }
    }

    $parts = parsePeerAddress($address);
    if ($parts['kind'] !== 'ipv4') {
        throw new RuntimeException('node_address: only IPv4 addresses are supported (IPv6 and Tor are not verified with btcnodes.io)');
    }
    $port = $parts['port'] ?? '8333';
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('node_address: invalid port');
    }
    return $parts['host'] . '-' . (int) $port;
}

/**
 * jobBtcnodes Stores the rank and the peer index of the node at btcnodes.io
 *
 * One request: GET <api_url>/nodes/leaderboard/<address>-<port>/. btcnodes.io
 * allows 10 requests per day and IP address, so the collector sends at most
 * BTCNODES_MAX_REQUESTS in 24 hours (btcnodesQuota), also with --force and
 * --job=, and after HTTP 429 none until the time of Retry-After (24 h if missing).
 * The time of every request is kept in the status file ($jobState).
 * HTTP 404 (node not listed) keeps the old values and stores 'listed' => false.
 * Other errors leave the data file unchanged. For rank and peer index the
 * previous different value is kept ('rank_previous', 'peer_index_previous').
 * 'node' ("<ip>-<port>") is stored for the link to the node page on the status page.
 *
 * @param array $job      Job settings ('api_url', 'node_address', 'file')
 * @param array $settings Collector settings (http_timeout, jobs)
 * @param array $jobState Status of the job; changed: 'requests', 'last_request', 'blocked_until'
 * @param int   $now      Current timestamp
 *
 * @return string Result text
 */
function jobBtcnodes(array $job, array $settings, array &$jobState, int $now): string {
    if (!preg_match('#^https?://#i', $job['api_url'])) {
        throw new RuntimeException('api_url is not a valid http(s) URL');
    }
    $node = btcnodesNode($job, $settings);   // configuration errors never use a request

    $quota = btcnodesQuota($jobState, $now);
    $jobState['requests']      = $quota['requests'];
    $jobState['blocked_until'] = $quota['blocked_until'];
    if ($quota['blocked_until'] > 0) {
        throw new CollectorJobDeferred('btcnodes.io answered HTTP 429, no request before ' . date('Y-m-d H:i:s', $quota['blocked_until']));
    }
    if ($quota['count'] >= BTCNODES_MAX_REQUESTS) {
        throw new CollectorJobDeferred(sprintf('request limit reached (%d in 24 h), next request possible at %s',
            BTCNODES_MAX_REQUESTS, date('Y-m-d H:i:s', $quota['next_allowed'])));
    }

    // Count the request before it is sent: a timeout may still have reached the server
    $jobState['requests'][]   = $now;
    $jobState['last_request'] = $now;
    $answer = httpRequest($job['api_url'] . '/nodes/leaderboard/' . rawurlencode($node) . '/', [], $settings['http_timeout']);
    $code   = $answer['http_code'];

    if ($code === 429) {
        $jobState['blocked_until'] = parseRetryAfter($answer['headers']['retry-after'] ?? '', $now);
        throw new RuntimeException('HTTP 429 (request limit), no request before ' . date('Y-m-d H:i:s', $jobState['blocked_until']));
    }

    $old     = readDataFile($job['file']);
    $oldData = ($old !== null && is_array($old['data'])) ? $old['data'] : [];
    $data    = null;
    if ($code === 200) {
        try {
            $data = json_decode($answer['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('invalid JSON answer (HTTP 200)', 0, $e);
        }
        if (!is_array($data)) {
            throw new RuntimeException('unexpected answer (HTTP 200)');
        }
    }

    // Node not listed: HTTP 404, or an answer without rank but with "Not found."
    $notFound = is_array($data) && !isset($data['rank']) && is_string($data['detail'] ?? null)
                && stripos($data['detail'], 'not found') !== false;
    if ($code === 404 || $notFound) {
        $oldData['listed'] = false;   // old values stay for the "previous" values
        $oldData['node']   = $node;   // for the link to the node page at btcnodes.io
        saveJsonAtomic($job['file'], ['request_time' => $now, 'request_data' => $oldData]);
        return 'node ' . $node . ' is not listed at btcnodes.io';
    }
    if ($code !== 200) {
        throw new RuntimeException("HTTP $code");
    }

    $rank = $data['rank'] ?? null;
    $pix  = $data['peer_index'] ?? null;   // a string in the answer, e.g. "7.2184"
    if (!is_int($rank) || $rank < 1 || !is_numeric($pix)) {
        throw new RuntimeException('unexpected answer: rank or peer_index missing');
    }
    $pix = round((float) $pix, 4);

    // Previous different value (like "Last: ..." on the old page)
    $oldRank = $oldData['rank'] ?? null;
    $oldPix  = $oldData['peer_index'] ?? null;
    $rankPrevious = (is_int($oldRank) && $oldRank !== $rank) ? $oldRank : ($oldData['rank_previous'] ?? null);
    $pixPrevious  = (is_numeric($oldPix) && (float) $oldPix !== $pix) ? (float) $oldPix : ($oldData['peer_index_previous'] ?? null);

    saveJsonAtomic($job['file'], ['request_time' => $now, 'request_data' => [
        'listed'              => true,
        'node'                => $node,   // "<ip>-<port>": page https://btcnodes.io/nodes/<node>/
        'rank'                => $rank,
        'peer_index'          => $pix,
        'rank_previous'       => is_int($rankPrevious) ? $rankPrevious : null,
        'peer_index_previous' => is_numeric($pixPrevious) ? (float) $pixPrevious : null,
        'height'              => is_int($data['height'] ?? null) ? $data['height'] : null,
        'connected_since'     => is_int($data['connected_since'] ?? null) ? $data['connected_since'] : null,
        'user_agent'          => is_string($data['user_agent'] ?? null) ? $data['user_agent'] : '',
    ]]);
    return "rank $rank, peer index $pix (" . ($quota['count'] + 1) . '/' . BTCNODES_MAX_REQUESTS . ' requests in 24 h)';
}

/**
 * runJob Runs one job and returns its result text
 *
 * @param string $name       Job name
 * @param array  $config     The complete $config array
 * @param array  $settings   Collector settings
 * @param array  $rpcResults Results of the RPC batch
 * @param int    $now        Current timestamp
 * @param array  $jobState   Status of the job (a rate limited job keeps its requests there)
 *
 * @return string Result text
 */
function runJob(string $name, array $config, array $settings, array $rpcResults, int $now, array &$jobState): string {
    $job = $settings['jobs'][$name];

    switch ($name) {
        case 'blockchaininfo':
            return jobBlockchainInfo($job, rpcResult($rpcResults, $name), $now);
        case 'networkinfo':
            return jobNetworkInfo($job, rpcResult($rpcResults, $name), $now);
        case 'peerinfo':
            return jobPeerInfo($job, rpcResult($rpcResults, $name), $now);
        case 'connections':
            return jobConnections($job, rpcResult($rpcResults, $name), $now);
        case 'peercount':
            return jobPeerCount($job, rpcResult($rpcResults, $name), $now);
        case 'fee':
            return jobFee($job, rpcResult($rpcResults, $name), $now);
        case 'latestblock':
            return jobLatestBlock($job, $settings, $now);
        case 'cmc_price':
            return jobCmcPrice($job, $settings, $now);
        case 'uptime':
            return jobUptime($job, rpcResult($rpcResults, $name), $now);
        case 'diskspace':
            return jobDiskspace($job, $config, $settings, $now);
        case 'geolocation':
            return jobGeolocation($job, $config, $settings, $now);
        case 'btcnodes':
            return jobBtcnodes($job, $settings, $jobState, $now);
    }

    throw new RuntimeException("Unknown job: $name");
}

/* ------------------------------------------------------------------ *
 * Commands
 * ------------------------------------------------------------------ */

/**
 * showHelp Prints the usage
 *
 * @param array $settings Collector settings (job names)
 *
 * @return void
 */
function showHelp(array $settings): void {
    out('FMCE Node Status collector');
    out('Usage: php collector.php [--force] [--job=a,b] [--status] [--view=job] [--clear=job]');
    out('Jobs:  ' . implode(', ', array_keys($settings['jobs'])));
}

/**
 * showStatus Prints the status of all jobs
 *
 * @param array $settings Collector settings
 * @param int   $now      Current timestamp
 *
 * @return void
 */
function showStatus(array $settings, int $now): void {
    $state = readCollectorState($settings['state_file']);
    out('Last run: ' . ($state['updated'] ? date('Y-m-d H:i:s', $state['updated']) : 'never'));

    foreach ($settings['jobs'] as $name => $job) {
        $js      = (isset($state['jobs'][$name]) && is_array($state['jobs'][$name])) ? $state['jobs'][$name] : [];
        $success = (int) ($js['last_success'] ?? 0);
        out(rtrim(sprintf(
            '%-15s %-8s every %4ds  last success: %-19s  %s',
            $name,
            $job['enabled'] ? 'enabled' : 'disabled',
            $job['interval'],
            $success ? date('Y-m-d H:i:s', $success) . ' (' . ($now - $success) . 's ago)' : 'never',
            ($job['enabled'] ? '' : '(' . $job['off_reason'] . ') ') . (empty($js['last_error']) ? '' : 'ERROR: ' . $js['last_error'])
        )));
        if ($name === 'btcnodes') {
            $quota   = btcnodesQuota($js, $now);
            $last    = (int) ($js['last_request'] ?? 0);
            $nextRun = max($quota['next_allowed'], $last + $job['interval']);
            out(sprintf('%15s last request: %s, requests in 24 h: %d/%d, blocked until: %s, next run: %s%s', '',
                $last ? date('Y-m-d H:i:s', $last) : 'never', $quota['count'], BTCNODES_MAX_REQUESTS,
                $quota['blocked_until'] ? date('Y-m-d H:i:s', $quota['blocked_until']) : '-',
                $job['enabled'] ? date('Y-m-d H:i:s', max($now, $nextRun)) : 'disabled',
                empty($js['deferred']) ? '' : ' (WAIT: ' . $js['deferred'] . ')'));
        }
    }
}

/**
 * collectorMain Runs the collector and returns the exit code
 *
 * @param array $argv Command line arguments
 *
 * @return int Exit code (0 ok, 1 job failed, 2 configuration/usage error)
 */
function collectorMain(array $argv): int {
    $lockHandle = null;

    try {
        if (!is_file(__DIR__ . '/config.php')) {
            throw new RuntimeException('config.php not found (copy config.sample.php to config.php)');
        }
        require __DIR__ . '/config.php';
        if (!isset($config) || !is_array($config)) {
            throw new RuntimeException('$config not found in config.php');
        }
        applyTimezone($config);   // unknown names are ignored (server default stays)

        $settings = getCollectorConfig($config);
        $options  = parseOptions($argv);
        $now      = time();

        if ($options['mode'] === 'help') {
            showHelp($settings);
            return 0;
        }
        if ($options['mode'] === 'status') {
            $timezoneWarning = getTimezoneWarning($config);
            if ($timezoneWarning !== '') {
                out($timezoneWarning);
            }
            showStatus($settings, $now);
            return 0;
        }
        if ($options['mode'] === 'view') {
            if (!isset($settings['jobs'][$options['target']])) {
                throw new InvalidArgumentException("Unknown job: {$options['target']}");
            }
            $content = readDataFile($settings['jobs'][$options['target']]['file']);
            out($content === null ? 'No data.' : print_r($content['raw'], true));
            return 0;
        }
        foreach ($options['jobs'] as $name) {
            if (!isset($settings['jobs'][$name])) {
                throw new InvalidArgumentException("Unknown job: $name");
            }
        }

        // Lock via flock(): released by the operating system even if the script
        // crashes or is killed, so there are no stale locks.
        $lockHandle = fopen($settings['lock_file'], 'c');
        if ($lockHandle === false) {
            throw new RuntimeException('Cannot open lock file: ' . $settings['lock_file']);
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            out('Another collector run is still active - stop.');
            return 0;
        }

        if ($options['mode'] === 'clear') {
            if (!isset($settings['jobs'][$options['target']])) {
                throw new InvalidArgumentException("Unknown job: {$options['target']}");
            }
            $file = $settings['jobs'][$options['target']]['file'];
            out(is_file($file) && unlink($file) ? 'Deleted: ' . basename($file) : 'No data file.');
            return 0;
        }

        // Which jobs run now?
        $state   = readCollectorState($settings['state_file']);
        $toRun   = [];
        foreach ($settings['jobs'] as $name => $job) {
            if ($options['jobs'] !== [] && !in_array($name, $options['jobs'], true)) {
                continue;
            }
            if (!$job['enabled'] && $options['jobs'] === []) {
                // --force runs enabled jobs only; --job=<name> also runs a disabled job
                if ($options['force']) {
                    out(sprintf('%-15s SKIP   disabled (%s), run it with --job=%s', $name, $job['off_reason'], $name));
                }
                continue;
            }
            $jobState = (isset($state['jobs'][$name]) && is_array($state['jobs'][$name])) ? $state['jobs'][$name] : [];
            if ($options['force'] || isDue($job, $jobState, $now, $name)) {
                $toRun[] = $name;
            }
        }
        if ($toRun === []) {
            out('Nothing to do.');
            return 0;
        }

        // One parallel RPC batch for all node jobs. If it fails completely
        // (e.g. node not reachable), every node job reports this error.
        $rpcResults = [];
        $rpcError   = null;
        try {
            $rpcResults = fetchRpcResults($config, $settings, $toRun);
        } catch (Throwable $e) {
            $rpcError = $e->getMessage();
        }

        $exitCode = 0;
        foreach ($toRun as $name) {
            $start    = microtime(true);
            $jobState = (isset($state['jobs'][$name]) && is_array($state['jobs'][$name])) ? $state['jobs'][$name] : [];
            $jobState['last_attempt'] = $now;

            try {
                if ($rpcError !== null && isset(COLLECTOR_RPC_JOBS[$name])) {
                    throw new RuntimeException($rpcError);
                }
                $jobState['deferred'] = null;
                $text = runJob($name, $config, $settings, $rpcResults, $now, $jobState);
                $jobState['last_success'] = $now;
                $jobState['last_error']   = null;
                $jobState['error_since']  = null;
                out(sprintf('%-15s OK     %s', $name, $text));
            } catch (CollectorJobDeferred $e) {
                // Not allowed to run now (e.g. request limit): no error, data file unchanged
                $jobState['deferred'] = $e->getMessage();
                out(sprintf('%-15s WAIT   %s', $name, $e->getMessage()));
            } catch (Throwable $e) {
                $jobState['last_error']  = $e->getMessage();
                $jobState['error_since'] = $jobState['error_since'] ?? $now;
                $exitCode = 1;
                out(sprintf('%-15s ERROR  %s', $name, $e->getMessage()));
            }

            $jobState['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
            $state['jobs'][$name]    = $jobState;
        }

        $state['updated'] = $now;
        saveJsonAtomic($settings['state_file'], $state);

        return $exitCode;
    } catch (Throwable $e) {
        // Configuration or usage error. Never print the RPC login or API key.
        out(sprintf('Error: %s (%s:%d)', $e->getMessage(), basename($e->getFile()), $e->getLine()));
        return 2;
    } finally {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

exit(collectorMain($argv ?? []));
