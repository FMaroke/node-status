<?php
/**
 * FMCE Node Status
 * Chart data endpoint (JavaScript data for the Google Charts).
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
 * - added the fee chart and the BTC/USD price chart,
 * - chart tables are built by buildChartRows() in php/functions.php
 *   (same logic as the page), output as escaped JSON,
 * - peer chart headers show the number of connected nodes per client,
 *   rows with other client types than the newest point no longer break the chart,
 * - invalid requests answer with HTTP 400,
 * - &format=json: plain JSON for the background refresh (js/refresh.js),
 * - X-Robots-Tag: noindex, so search engines do not index the chart data,
 * - PHP errors are never shown (display_errors off unless 'debug' => true),
 * - strict_types; 'stat' is only accepted as a string (no warning for stat[]=x),
 * - generic for every chart of getChartDefinitions(): the data is stored in
 *   window.fmceChartData[<id>] (no variable or script per chart any more).
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

// Output: the chart table as entry of window.fmceChartData, e.g.
// (window.fmceChartData = window.fmceChartData || {})["fee"] = [["Date","Fee (sat/vB, conservative)"],[1731600000000,4.2], ...];
// The first column is the time in milliseconds; js/charts.js turns it into a date.

// PHP errors never appear in the chart data (they would break the JavaScript/JSON);
// they go to the PHP error log. 'debug' => true restores the server setting below.
$serverDisplayErrors = (string) ini_get('display_errors');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// format=json: plain JSON (background refresh), otherwise a JavaScript variable
$asJson = (($_GET['format'] ?? '') === 'json');
header('Content-Type: ' . ($asJson ? 'application/json' : 'application/javascript') . '; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Robots-Tag: noindex');   // data for the charts, not a page for search engines

if (!is_file(__DIR__ . '/php/config.php')) {
    http_response_code(500);
    echo "// configuration missing\n";
    exit;
}

require_once __DIR__ . '/php/config.php';
if (isset($config) && is_array($config) && ($config['debug'] ?? false) === true) {
    ini_set('display_errors', $serverDisplayErrors);
}
require_once __DIR__ . '/php/functions.php';

$stat  = is_string($_GET['stat'] ?? null) ? $_GET['stat'] : '';   // stat[]=x is an array: unknown chart
$known = array_keys(getChartDefinitions());   // chart ids

if (!in_array($stat, $known, true)) {
    http_response_code(400);
    echo "// unknown chart\n";
    exit;
}

try {
    $table = buildChartRows($stat);
    $json  = json_encode(
        $table['rows'],
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    echo $asJson ? $json : '(window.fmceChartData = window.fmceChartData || {})[' . json_encode($stat) . '] = ' . $json . ";\n";
} catch (Throwable $e) {
    // The chart script then finds no data and the chart stays empty.
    http_response_code(500);
    echo "// chart data not available\n";
}
