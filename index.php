<?php
/**
 * FMCE Node Status
 * Entry point of the status page.
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
 * - added phpqrcode.php (local donation QR code),
 * - no set_time_limit(0) any more: all remote data is fetched by the collector,
 * - ?clearcache / ?nocache and 'nocache_whitelist' removed: the page only reads
 *   the files of the collector, the page cache just limits how often it is built,
 * - paths relative to this file (__DIR__), safe timezone check (applyTimezone:
 *   unknown names are ignored), HTTP 500 without config,
 * - ?partial=1: JSON with the changed parts of the page for js/refresh.js
 *   (background refresh instead of <meta http-equiv="refresh">),
 *   marked with X-Robots-Tag: noindex, so search engines do not index the JSON,
 * - PHP errors are never shown (display_errors off unless 'debug' => true),
 * - the page is built in a buffer inside try/catch: on an error HTTP 503 with a
 *   short message (JSON {"error":"unavailable"} for ?partial=1), details in the log.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

// PHP errors never appear in the page or in the JSON of ?partial=1 (they would
// break it); they go to the PHP error log. With 'debug' => true the server
// setting for display_errors is restored after config.php has been read.
$serverDisplayErrors = (string) ini_get('display_errors');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!is_file(__DIR__ . '/php/config.php')) {
    http_response_code(500);
    exit('Configuration file not found. To continue, please copy php/config.sample.php to php/config.php.');
}

require_once __DIR__ . '/php/config.php';

$debug = isset($config) && is_array($config) && ($config['debug'] ?? false) === true;
if ($debug) {
    ini_set('display_errors', $serverDisplayErrors);
}

// Background refresh (js/refresh.js): the page is built as usual, but only
// the refreshable parts are sent back as JSON.
$partial    = isset($_GET['partial']);
$livePieces = array();

// The whole page is built into a buffer first: if anything fails, no half page
// is sent, but a short error page (HTTP 503) instead.
$bufferLevel = ob_get_level();
ob_start();

try {
    require_once __DIR__ . '/php/functions.php';
    require_once __DIR__ . '/php/phpqrcode.php';

    applyTimezone($config);   // unknown names are ignored (server default stays)

    // Page cache: the page data is rebuilt at most every 'max_cache_time' seconds.
    $data = getData(($config['use_cache'] ?? true) === true);

    require_once __DIR__ . '/html/template.php';

    if ($partial) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');   // JSON for js/refresh.js, not a page for search engines
        echo json_encode(
            getLiveResponse($livePieces, $active_charts, $collectorStatus['last_run'], isset($_GET['peers'])),
            JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    } else {
        ob_end_flush();
    }
} catch (Throwable $e) {
    // Throw away everything that was built so far (also nested buffers of liveStart)
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    error_log(sprintf('FMCE Node Status: page could not be built: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));

    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    if ($partial) {
        // js/refresh.js keeps the values shown and tries again later
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        $answer = array('error' => 'unavailable');
        if ($debug) {
            $answer['message'] = $e->getMessage();
        }
        echo json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<meta name="robots" content="noindex"><title>Status page unavailable</title></head><body>'
           . '<h1>Status page temporarily unavailable</h1>'
           . '<p>Please try again in a few minutes.</p>';
        if ($debug) {
            echo '<p>Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '</body></html>';
    }
}
