<?php
/**
 * FMCE Node Status
 * Main template.
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
 * - collector status: notices for missing, outdated or failed data,
 * - BTC/USD price chart, chart notices while data is being collected,
 * - date, time, numbers and bytes formatted with the display formats of
 *   config.php (formatNumber, formatDateTime, formatBytes, formatDuration),
 * - peer table sorts by raw values (sorttable_customkey), independent of the format,
 * - background refresh (js/refresh.js) instead of <meta http-equiv="refresh">:
 *   changing parts are marked with liveStart()/liveEnd(),
 * - imprint and privacy links in the footer, cookie notice (all switchable in config.php),
 * - page cache message only with 'debug' => true, inside the refreshed area,
 * - peer table shown in pages (js/peer-pager.js, 'peers_per_page'),
 * - long peer user agents shortened ('peer_agent_max_length'), full text as tooltip,
 * - search engines and link previews: meta generator, Open Graph preview image,
 *   JSON-LD structured data, "Powered by" line in the footer,
 * - semantic HTML5 markup,
 * - accessibility,
 * - new CSS grid layout,
 * - local QR code generation,
 * - dynamic chart loading from the chart registry,
 * - output escaping and theme-aware node link images,
 * - user agents with separators (formatUserAgent), "n/a" for missing peer times
 *   and versions, sort hint only together with the table,
 * - footer: IP2Location note only with active geolocation, FMCE donation line
 *   always shown (NODE_STATUS_DONATION_ADDRESS),
 * - "node not reachable" only counts node jobs that are enabled,
 * - canonical URL and admin e-mail only if valid, logo without a second
 *   reading of the page title (alt="", link with aria-label), repository URL
 *   from NODE_STATUS_REPO,
 * - all charts drawn by one script (js/charts.js) from the chart registry
 *   (data-chart), aria-label with title and latest value, IP cell without
 *   line break between flag and address,
 * - unknown 'timezone' in config.php: notice only with 'debug' => true,
 * - address column sorts by network, then address (Tor/I2P/CJDNS marked),
 * - rank and peer index at btcnodes.io ('display_btcnodes_info'), link to the
 *   node page (only with 'display_ip'), footer line "Node ranking by btcnodes.io"
 *   only while the collector job is enabled,
 * - every peer table column has a fixed sort type (sorttable_alpha /
 *   sorttable_numeric) and a sort key: client and version, inbound/outbound.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

// ---------- Page logic (before any HTML output) ----------
// Theme: default from config, overridden by the visitor's choice (cookie set by js/node-status.js).
$theme     = (($config['css_style'] ?? 'dark') === 'dark') ? 'dark' : 'light';
if (isset($_COOKIE['theme']) && in_array($_COOKIE['theme'], ['dark', 'light'], true)) {
    $theme = $_COOKIE['theme'];
}
$otherTheme = ($theme === 'dark') ? 'light' : 'dark';

// Header logo: path (relative to the web root) from config 'site_logo_dark' / 'site_logo_light'.
// Falls back to the other variant; no logo is shown if neither is configured or the file is missing.
$logoFile = (string) ($config['site_logo_' . $theme] ?? $config['site_logo_' . $otherTheme] ?? '');
$hasLogo  = $logoFile !== '' && is_file(dirname(__DIR__) . '/' . ltrim($logoFile, '/'));
$logoHref = $config['canonical_url'] ?? '';
$canonicalUrl = (getPublicBaseUrl() !== null) ? (string) $config['canonical_url'] : '';   // only a valid http(s) URL
$adminEmail   = filter_var((string) ($config['admin_email'] ?? ''), FILTER_VALIDATE_EMAIL);   // false if not valid
// Collector status (read on every request, not from the page cache)
$collectorStatus = getCollectorStatus($config, time());
$collectorJobs   = getCollectorConfig($config)['jobs'];
$nodeMissing     = !empty($data['node_data_missing']) || !isset($data['blocks']);
$showDebug       = ($config['debug'] ?? false) === true;

// Notices about missing or outdated data (texts without technical details;
// error messages only with 'debug' => true, they can contain host names)
$dataNotices = [];
// Unknown time zone name in config.php: only for the admin, like the error texts
$timezoneWarning = $showDebug ? getTimezoneWarning($config) : '';
if ($timezoneWarning !== '') {
    $dataNotices[] = 'Configuration: ' . $timezoneWarning . '.';
}
if ($collectorStatus['never_run']) {
    $dataNotices[] = 'No data has been collected yet. The values appear a few minutes after the collector has been started (cron job, see README).';
} else {
    // Node jobs that are switched off (e.g. a chart that is not shown) never report a problem
    $rpcJobs     = ['blockchaininfo', 'networkinfo', 'peerinfo', 'connections', 'peercount', 'fee'];
    $rpcJobs     = array_values(array_filter($rpcJobs, fn($job) => $collectorJobs[$job]['enabled']));
    $failedJobs  = array_column($collectorStatus['problems'], 'job');
    $nodeOffline = $rpcJobs !== [] && !array_diff($rpcJobs, $failedJobs);   // all enabled node jobs have a problem
    if ($nodeOffline) {
        $since = isset($data['data_time']) ? ' The values shown are from ' . formatDateTime((int) $data['data_time']) . '.' : '';
        $dataNotices[] = 'The Bitcoin node is currently not reachable.' . $since;
    }
    foreach ($collectorStatus['problems'] as $problem) {
        if ($nodeOffline && in_array($problem['job'], array_merge($rpcJobs, ['uptime']), true)) {
            continue;   // already covered by the "not reachable" notice
        }
        $age  = $problem['last_success'] ? 'last update ' . elapsedTime($problem['last_success']) . ' ago' : 'no data yet';
        $text = $problem['label'] . ': ' . $age . '.';
        if ($showDebug && $problem['error'] !== '') {
            $text .= ' Error: ' . $problem['error'];
        }
        $dataNotices[] = $text;
    }
    if ($showDebug && $nodeOffline) {
        foreach ($collectorStatus['problems'] as $problem) {
            if ($problem['error'] !== '') {
                $dataNotices[] = $problem['label'] . ' error: ' . $problem['error'];
                break;
            }
        }
    }
}

$pageTitle = $config['title_text'] ?? $default_app_title ?? 'Bitcoin Node Status';
$introText = $config['intro_text'] ?? 'not_set';
$hasIntro  = ($introText !== 'not_set' && $introText !== '');
$metaDescription = $hasIntro ? $introText : 'Live status of a Bitcoin full node.';

// Search engines and link previews (preview image, structured data)
$shareImage     = getShareImage();
$structuredData = getStructuredData($pageTitle, $metaDescription, $shareImage);

$hasDonation = isset($config['donation_address']) && $config['donation_address'] !== 'not_set';

// Background refresh (0 = off)
$refresh = getRefreshSettings();

// Legal links (only valid http(s) URLs) and cookie notice
$legalLinks = [];
if (($config['display_imprint'] ?? true) === true && preg_match('#^https?://#i', (string) ($config['imprint_url'] ?? ''))) {
    $legalLinks[] = ['label' => 'Imprint', 'url' => $config['imprint_url']];
}
if (($config['display_privacy'] ?? true) === true && preg_match('#^https?://#i', (string) ($config['privacy_url'] ?? ''))) {
    $legalLinks[] = ['label' => 'Privacy policy', 'url' => $config['privacy_url']];
}
$privacyUrl       = (($config['display_privacy'] ?? true) === true) ? ($config['privacy_url'] ?? '') : '';
$showCookieNotice = ($config['display_cookie_notice'] ?? true) === true && !isset($_COOKIE['cookie_notice']);

// Charts: drop incomplete registry entries
$active_charts = [];
if (($data['chart_data']['display'] ?? false) === true) {
    $active_charts = array_filter(
        $data['chart_data']['chart_registry'] ?? [],
        fn($c) => is_array($c) && isset($c['stat'], $c['config'], $c['title'], $c['aria'])
    );
}
$chartNotices = (isset($data['chart_data']['notices']) && is_array($data['chart_data']['notices'])) ? $data['chart_data']['notices'] : [];

// Node links: keep valid entries only, pick the image variant matching the theme.
// Image paths (img/...) are relative to the web root, which is the parent
// directory of the html/ folder this template lives in.
$webRoot   = dirname(__DIR__);
$nodeLinks = [];
$rawLinks  = is_array($config['node_links'] ?? null) ? $config['node_links'] : [];
foreach ($rawLinks as $link) {
    if (empty($link['name']) || empty($link['link']) || !preg_match('#^https?://#i', $link['link'])) {
        continue;
    }
    $image = !empty($link['image']) ? $link['image'] : null;
    if ($image !== null) {
        // Swap the _dark/_light suffix of the file name for the active theme.
        $themed = str_replace(['_dark.', '_light.'], '_' . $theme . '.', $image);
        if ($themed !== $image && is_file($webRoot . '/' . ltrim($themed, '/'))) {
            $image = $themed;
        }
    }
    $nodeLinks[] = ['name' => $link['name'], 'link' => $link['link'], 'image' => $image];
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>" data-brand="cryptcoin">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo escHtml($pageTitle); ?></title>
  <meta name="description" content="<?php echo escHtml($metaDescription); ?>">
<?php if ($refresh['interval'] > 0) { ?>
  <!-- Without JavaScript the page reloads itself; with JavaScript js/refresh.js updates it -->
  <noscript><meta http-equiv="refresh" content="<?php echo max(60, $refresh['interval']); ?>"></noscript>
<?php } ?>
  <meta name="theme-color" content="<?php echo $theme === 'dark' ? '#0b1a24' : '#f4f7f9'; ?>">
<?php if ($canonicalUrl !== '') { ?>
  <link rel="canonical" href="<?php echo escHtml($canonicalUrl); ?>">
  <meta property="og:url" content="<?php echo escHtml($canonicalUrl); ?>">
<?php } ?>
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?php echo escHtml($pageTitle); ?>">
  <meta property="og:description" content="<?php echo escHtml($metaDescription); ?>">
<?php if ($shareImage !== null) { ?>
  <!-- Preview image for links in social media and messengers -->
  <meta property="og:image" content="<?php echo escHtml($shareImage['url']); ?>">
  <meta property="og:image:width" content="<?php echo $shareImage['width']; ?>">
  <meta property="og:image:height" content="<?php echo $shareImage['height']; ?>">
<?php if ($shareImage['type'] !== '') { ?>
  <meta property="og:image:type" content="<?php echo escHtml($shareImage['type']); ?>">
<?php } ?>
  <meta property="og:image:alt" content="<?php echo escHtml('Screenshot: ' . $pageTitle); ?>">
  <meta name="twitter:card" content="summary_large_image">
<?php } ?>
  <meta name="generator" content="<?php echo escHtml('FMCE Node Status ' . NODE_STATUS_VERSION); ?>">
<?php if ($structuredData !== '') { ?>
  <!-- Structured data for search engines (schema.org); not executed by the browser -->
  <script type="application/ld+json">
<?php echo $structuredData; ?>

  </script>
<?php } ?>
  <link rel="icon" type="image/png" href="img/favicon.png">

  <link rel="stylesheet" href="<?php echo escHtml(asset('css/style.css')); ?>">
  <link rel="stylesheet" href="<?php echo escHtml(asset('css/flags.css')); ?>">

  <script defer src="<?php echo escHtml(asset('js/sorttable.js')); ?>"></script>
  <script defer src="<?php echo escHtml(asset('js/node-status.js')); ?>"></script>
  <script defer src="<?php echo escHtml(asset('js/peer-pager.js')); ?>"></script>
<?php if ($refresh['interval'] > 0) { ?>
  <script defer src="<?php echo escHtml(asset('js/refresh.js')); ?>"></script>
<?php } ?>
<?php if ($active_charts) { ?>
  <script defer src="<?php echo escHtml(asset('js/google_corechart.js')); ?>"></script>
<?php foreach ($active_charts as $chart) { ?>
  <script defer src="chartdata.php?stat=<?php echo rawurlencode($chart['stat']); ?>"></script>
<?php } ?>
  <script defer src="<?php echo escHtml(asset('js/charts.js')); ?>"></script>
<?php } ?>
</head>
<body>
  <div id="container" class="page" data-refresh="<?php echo (int) $refresh['interval']; ?>" data-refresh-peers="<?php echo (int) $refresh['peers_every']; ?>">

<header class="site-header">
  <div class="header-bar">
<?php if ($hasLogo) { ?>
<?php if (preg_match('#^https?://#i', (string) $logoHref)) { ?>
    <?php /* The title follows as <h1>: the image itself is decorative (alt=""), the link names its target */ ?>
    <a class="site-logo" href="<?php echo escHtml($logoHref); ?>" aria-label="Home"><img src="<?php echo escHtml($logoFile); ?>" alt="" height="48"></a>
<?php } else { ?>
    <span class="site-logo"><img src="<?php echo escHtml($logoFile); ?>" alt="" height="48"></span>
<?php } ?>
<?php } ?>
    <h1><?php echo escHtml($pageTitle); ?></h1>
    <button type="button" id="themeBtn" class="theme-toggle" data-theme-current="<?php echo $theme; ?>" aria-label="Switch to <?php echo $otherTheme; ?> theme" title="Switch to <?php echo $otherTheme; ?> theme"><?php echo ucfirst($otherTheme); ?></button>
  </div>
<?php if ($hasIntro) { ?>
  <p class="intro"><?php echo escHtml($introText); ?></p>
<?php } ?>
</header>

    <main>
      <div class="node-layout">

<aside class="node-side" aria-label="Donation and node links">
<?php if ($hasDonation) { ?>
  <section aria-labelledby="donate-title">
    <h2 id="donate-title" class="visually-hidden">Donate</h2>
    <p class="donation">
      Please support this Bitcoin node by donating to
      <a href="https://mempool.space/address/<?php echo rawurlencode($config['donation_address']); ?>" target="_blank" rel="noopener noreferrer"><?php echo escHtml($config['donation_address']); ?></a>
    </p>
    <?php echo generateDonationImage(); ?>
  </section>
<?php } else { ?>
  <img src="img/logo.png" alt="Bitcoin Logo" class="logo">
<?php } ?>

<?php if ($nodeLinks) { ?>
  <nav class="node-links" aria-labelledby="links-title">
    <h2 id="links-title">Node Links</h2>
    <ul>
<?php foreach ($nodeLinks as $nl) { ?>
      <li><a href="<?php echo escHtml($nl['link']); ?>" target="_blank" rel="noopener noreferrer"><?php
        if ($nl['image'] !== null) { ?><img src="<?php echo escHtml($nl['image']); ?>" alt="<?php echo escHtml($nl['name']); ?>"><?php }
        else { echo escHtml($nl['name']); }
      ?></a></li>
<?php } ?>
    </ul>
  </nav>
<?php } ?>
</aside>

<section class="node-main" aria-labelledby="stats-title">
  <h2 id="stats-title" class="visually-hidden">Node statistics</h2>

  <div id="live-top"><?php liveStart(); ?>
<?php /* Page cache message (e.g. "Configuration or software version has changed"): only for the admin
         ('debug' => true). It is part of the refreshed area, so it disappears
         with the next background refresh. */
if (isset($cache_message) && $showDebug) { ?>
  <p class="cache small"><?php echo escHtml($cache_message); ?></p>
<?php } ?>
<?php if ($dataNotices) { ?>
  <div class="notice-warn" role="status">
<?php foreach ($dataNotices as $notice) { ?>
    <p><?php echo escHtml($notice); ?></p>
<?php } ?>
<?php if ($nodeOffline ?? false) { if ($adminEmail !== false) { ?>
    <p>Please report this to <a href="mailto:<?php echo escHtml($adminEmail); ?>"><?php echo escHtml($adminEmail); ?></a>.</p>
<?php } } ?>
  </div>
<?php } ?>

<?php if ($nodeMissing) { ?>
  <p class="notice-empty">No node data available yet.</p>
<?php } else { ?>

  <dl class="stats">
    <div class="stat"><dt>Number of Connections:</dt><dd><?php echo escHtml(formatNumber($data['connections'] ?? null)); ?></dd></div>
  </dl>

<?php } /* end: node data available */ ?>
  <?php liveEnd('top'); ?></div>

<?php /* Charts according to the configuration (also shown if the node is offline). */
if ($active_charts) { ?>
  <div id="charts" class="charts" data-format="<?php echo escHtml((string) json_encode(getChartFormat())); ?>">
<?php foreach ($active_charts as $key => $chart) { /* js/charts.js draws every element with data-chart */ ?>
    <div id="<?php echo escHtml($key); ?>" class="chart" role="img" aria-label="<?php echo escHtml($chart['aria']); ?>" data-chart="<?php echo escHtml((string) json_encode($chart['config'])); ?>" data-title="<?php echo escHtml($chart['title']); ?>" data-last="<?php echo escHtml($chart['last'] ?? ''); ?>" data-updated="<?php echo (int) ($chart['updated'] ?? 0); ?>"></div>
<?php } ?>
  </div>
<?php } ?>
  <div id="live-chart-notices"><?php liveStart(); ?>
<?php if ($chartNotices) { ?>
  <ul class="chart-notices small">
<?php foreach ($chartNotices as $notice) { ?>
    <li><?php echo escHtml($notice); ?></li>
<?php } ?>
  </ul>
<?php } ?>
  <?php liveEnd('chart-notices'); ?></div>

  <div id="live-stats"><?php liveStart(); ?>
<?php if (!$nodeMissing) { ?>

  <dl class="stats">
    <div class="stat"><dt>Node Height:</dt><dd><?php
      echo escHtml(formatNumber($data['blocks']));
      if (($config['display_max_height'] ?? false) === true && isset($data['node_height_percent'])) {
          echo ' (' . escHtml(formatNumber($data['node_height_percent'], 2)) . ' %)';
      } ?></dd></div>
<?php if (isset($data['max_height']) === true) { ?>
    <div class="stat"><dt>Network Height:</dt><dd><?php echo escHtml(formatNumber($data['max_height'])); ?></dd></div>
<?php } ?>
<?php if (isset($data['difficulty']) && is_numeric($data['difficulty'])) { ?>
    <div class="stat"><dt>Difficulty:</dt><dd><?php echo escHtml(formatNumber($data['difficulty'], 3)); ?></dd></div>
<?php } ?>
<?php if (!empty($config['display_ip'])) { ?>
    <div class="stat"><dt>Node IP Address:</dt><dd><?php echo escHtml($data['node_ip']); ?></dd></div>
<?php } ?>
<?php if (!empty($config['display_version']) && isset($data['subversion'])) { ?>
    <div class="stat"><dt>Node Version:</dt><dd><?php echo escHtml(formatUserAgent($data['subversion'])); ?></dd></div>
<?php } ?>
<?php if (!empty($config['display_protocolversion']) && isset($data['protocolversion'])) { ?>
    <div class="stat"><dt>Protocol Version:</dt><dd><?php echo escHtml($data['protocolversion']); ?></dd></div>
<?php } ?>
<?php if (array_key_exists('bitcoind_uptime', $data)) { /* null = not available */ ?>
    <div class="stat"><dt>Bitcoin Daemon was restarted</dt><dd><?php echo is_int($data['bitcoind_uptime'] ?? null) ? escHtml(formatDuration($data['bitcoind_uptime'])) . ' ago' : 'Currently not available.'; ?></dd></div>
<?php } ?>
<?php if (isset($data['ip_location']) && strcmp($data['ip_location']['country_code'] ?? 'blank', 'blank') != 0) {
    $flagCode = preg_replace('/[^a-z0-9-]/', '', strtolower($data['ip_location']['country_code'])); ?>
    <div class="stat"><dt>Node Location:</dt><dd><img src="img/blank.gif" class="flag flag-<?php echo $flagCode; ?>" alt=""> <?php echo escHtml($data['ip_location']['country_name']); ?></dd></div>
<?php } ?>
<?php if (array_key_exists('btcnodes', $data)) { /* null = no data yet */
    $bn = $data['btcnodes'];
    if ($bn === null || (($bn['listed'] ?? false) && ($bn['rank'] === null || $bn['peer_index'] === null))) {
        $bnRank  = 'Currently not available.';
        $bnIndex = 'Currently not available.';
    } elseif (!$bn['listed']) {
        $bnRank  = 'Not listed at btcnodes.io.';
        $bnIndex = 'Not listed.';
    } else {
        $bnRank  = formatWithPrevious($bn['rank'], $bn['rank_previous'], 0);
        $bnIndex = formatWithPrevious($bn['peer_index'], $bn['peer_index_previous'], 4);
    } ?>
    <div class="stat"><dt>btcnodes.io rank:</dt><dd><?php echo escHtml($bnRank); ?></dd></div>
    <div class="stat"><dt>Peer index:</dt><dd><?php echo escHtml($bnIndex); ?><?php
      if ($bn !== null) {
          // Age of the data and link: node page "https://btcnodes.io/nodes/<ip>-<port>/" (checked by
          // the maintainer, 2026-11-23), but only if the page shows the node IP anyway (display_ip)
          $bnUrl = ($bn['node'] !== null && !empty($config['display_ip'])) ? 'https://btcnodes.io/nodes/' . $bn['node'] . '/' : 'https://btcnodes.io/'; ?><br><span class="small">last update <?php echo escHtml(elapsedTime($bn['time'])); ?> ago, <a href="<?php echo escHtml($bnUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo ($bnUrl === 'https://btcnodes.io/') ? 'btcnodes.io' : 'node page at btcnodes.io'; ?></a></span><?php } ?></dd></div>
<?php } ?>
<?php if (isset($data['free_disk_space'])) { ?>
    <div class="stat"><dt>Free Disk Space for Blockchain:</dt><dd><?php echo is_int($data['free_disk_space'] ?? null) ? escHtml(formatBytes($data['free_disk_space'])) : 'Currently not available.'; ?></dd></div>
<?php } ?>
<?php if (!empty($data['data_time'])) { ?>
    <div class="stat"><dt>Last Updated:</dt><dd><?php
      echo escHtml(formatDateTime((int) $data['data_time']))
         . ' (' . escHtml(elapsedTime((int) $data['data_time'])) . ' ago)'; ?></dd></div>
<?php } ?>
  </dl>
<?php } /* end: if (!$nodeMissing) */ ?>
  <?php liveEnd('stats'); ?></div>
</section>
 </div>
<?php if (($config['display_peer_info'] ?? false) === true && array_key_exists('peers', $data)) { ?>
<section class="peers" aria-labelledby="peers-title">
  <div class="section-head">
    <h2 id="peers-title">Connected Peers</h2>
    <span class="small">[<button type="button" id="peerslink" class="link-button" aria-expanded="true" aria-controls="peerstable">Hide</button>]</span>
  </div>

  <div id="peerstable" data-per-page="<?php echo max(0, (int) ($config['peers_per_page'] ?? 10)); ?>">
    <div id="live-peers"><?php liveStart(); ?>
<?php if (!is_array($data['peers'])) { ?>
    <p class="small">The peer list is currently not available.</p>
<?php } elseif (!$data['peers']) { ?>
    <p class="small">No peers connected.</p>
<?php } else { ?>
    <p class="small sort-hint">Click on a column header to sort the table:</p>
    <div class="table-wrap">
      <table class="sortable">
        <caption class="visually-hidden">Currently connected peers</caption>
        <thead>
          <tr>
<?php /* Every column names its sort type (sorttable_alpha / sorttable_numeric): sorttable.js
         would otherwise guess "numeric" for every column. The cells carry the sort keys. */ ?>
            <th scope="col" class="sorttable_alpha">IP</th>
            <th scope="col" class="sorttable_numeric">Time Connected</th>
            <th scope="col" class="sorttable_alpha">Version / Client</th>
            <th scope="col" class="sorttable_numeric">Last Send</th>
            <th scope="col" class="sorttable_numeric">Last Receive</th>
            <th scope="col" class="sorttable_numeric">Ping (ms)</th>
            <th scope="col" class="sorttable_numeric">Bytes Sent</th>
            <th scope="col" class="sorttable_numeric">Bytes Received</th>
            <th scope="col" class="sorttable_alpha">Direction</th>
          </tr>
        </thead>
        <tbody>
<?php
$now   = time();
$naKey = 99999999999;   // sort key for "n/a": behind every real value (ascending)
foreach ($data['peers'] as $peer) {
    // Flag (only if geo data is available and not "blank")
    $flagHtml = '';
    if (isset($peer['geo_data']) && is_array($peer['geo_data'])
        && strcmp($peer['geo_data']['country_code'] ?? 'blank', 'blank') != 0) {
        $flagCode = preg_replace('/[^a-z0-9-]/', '', strtolower($peer['geo_data']['country_code']));
        $flagName = escHtml($peer['geo_data']['country_name'] ?? '');
        $flagHtml = '<img src="img/blank.gif" class="flag flag-' . $flagCode . '" title="' . $flagName . '" alt="' . $flagName . '"> ';
    }

    // Peer values are normalised by parsePeers(): ints, float|null, string, bool.
    // Version and user agent, e.g. "70016, Satoshi 29.2.0 / Knots 20251010";
    // a missing part (version 0, empty user agent) is left out.
    $version    = ($peer['version'] > 0) ? (string) $peer['version'] : '';
    $agent      = formatUserAgent($peer['subver']);
    // Long user agents (uacomment) are shortened; the full text is shown as tooltip
    $agentShort = shortenText($agent, $config['peer_agent_max_length'] ?? 40);
    $agentFull  = implode(', ', array_filter(array($version, $agent), 'strlen'));
    $agentText  = implode(', ', array_filter(array($version, $agentShort), 'strlen'));
    $agentKey   = peerAgentSortKey($peer['subver'], $peer['version']);   // client, version, protocol; "n/a" last
    // Times: "n/a" if the peer did not report them (0 after normalising)
    $times = array();
    foreach (array('conntime', 'lastsend', 'lastrecv') as $key) {
        $times[$key] = ($peer[$key] > 0)
            ? array('text' => elapsedTime($peer[$key]), 'sort' => $now - $peer[$key])
            : array('text' => 'n/a', 'sort' => $naKey);
    }
    // Displayed values are formatted; sorttable_customkey keeps the raw values for sorting
    $pingMs  = ($peer['pingtime'] !== null) ? $peer['pingtime'] * 1000 : null;
    $ping    = ($pingMs !== null) ? formatNumber($pingMs, 2) : 'Unavailable';
?>
          <tr>
            <td data-label="IP" class="ip" sorttable_customkey="<?php echo escHtml($peer['addr_sort'] ?? ''); ?>"><?php echo $flagHtml . escHtml($peer['addr']); ?></td>
            <td data-label="Time Connected" sorttable_customkey="<?php echo $times['conntime']['sort']; ?>"><?php echo escHtml($times['conntime']['text']); ?></td>
<?php if ($agentFull === '') { ?>
            <td data-label="Version / Client" sorttable_customkey="<?php echo escHtml($agentKey); ?>">n/a</td>
<?php } elseif ($agentShort !== $agent) { ?>
            <td data-label="Version / Client" sorttable_customkey="<?php echo escHtml($agentKey); ?>"><span class="shortened" title="<?php echo escHtml($agentFull); ?>"><?php echo escHtml($agentText); ?></span></td>
<?php } else { ?>
            <td data-label="Version / Client" sorttable_customkey="<?php echo escHtml($agentKey); ?>"><?php echo escHtml($agentFull); ?></td>
<?php } ?>
            <td data-label="Last Send" sorttable_customkey="<?php echo $times['lastsend']['sort']; ?>"><?php echo escHtml($times['lastsend']['text']); ?></td>
            <td data-label="Last Receive" sorttable_customkey="<?php echo $times['lastrecv']['sort']; ?>"><?php echo escHtml($times['lastrecv']['text']); ?></td>
            <td data-label="Ping (ms)" sorttable_customkey="<?php echo ($pingMs !== null) ? round($pingMs, 2) : 999999; ?>"><?php echo escHtml($ping); ?></td>
            <td data-label="Bytes Sent" sorttable_customkey="<?php echo $peer['bytessent']; ?>"><?php echo escHtml(formatBytes($peer['bytessent'])); ?></td>
            <td data-label="Bytes Received" sorttable_customkey="<?php echo $peer['bytesrecv']; ?>"><?php echo escHtml(formatBytes($peer['bytesrecv'])); ?></td>
<?php if ($peer['inbound']) { ?>
            <td data-label="Direction" class="dir dir-in" sorttable_customkey="inbound"><span aria-hidden="true">&#x25BC;</span><span class="visually-hidden">Inbound</span></td>
<?php } else { ?>
            <td data-label="Direction" class="dir dir-out" sorttable_customkey="outbound"><span aria-hidden="true">&#x25B2;</span><span class="visually-hidden">Outbound</span></td>
<?php } ?>
          </tr>
<?php } ?>
        </tbody>
      </table>
    </div>
<?php } ?>
    <?php liveEnd('peers'); ?></div>
    <nav id="peer-pager" class="pager" aria-label="Peer table pages" hidden></nav>
  </div>
</section>
<?php } // end peer info ?>
</main>

<footer id="footer">
  <p>Powered by <a href="<?php echo escHtml(NODE_STATUS_REPO); ?>" target="_blank" rel="noopener noreferrer">FMCE Node Status</a>, an open-source status page for Bitcoin nodes. &copy; 2023–2026 FMCE Concept UG, licensed under the <a href="https://www.apache.org/licenses/LICENSE-2.0" target="_blank" rel="noopener noreferrer">Apache License 2.0</a>.</p>
  <p>Based on <a href="<?php echo escHtml(NODE_STATUS_ORIGINAL_REPO); ?>" target="_blank" rel="noopener noreferrer">Bitcoin Daemon Status Page (bitcoind-status)</a> by Craig Watson (Apache License 2.0), modified by <a href="https://fmce.de" target="_blank" rel="noopener noreferrer">FMCE Concept UG</a>.</p>
  <p>Please send donations for this software to: <a href="https://mempool.space/address/<?php echo rawurlencode(NODE_STATUS_DONATION_ADDRESS); ?>" target="_blank" rel="noopener noreferrer"><?php echo escHtml(NODE_STATUS_DONATION_ADDRESS); ?></a></p>
<?php if ($collectorJobs['geolocation']['enabled']) { /* same switch as the collector job */ ?>
  <p>IP Geolocation by <a href="https://www.ip2location.io" target="_blank" rel="noopener noreferrer">IP2Location.io</a>.</p>
<?php } ?>
<?php if ($collectorJobs['btcnodes']['enabled']) { /* same switch as the collector job */ ?>
  <p>Node ranking by <a href="https://btcnodes.io/" target="_blank" rel="noopener noreferrer">btcnodes.io</a>.</p>
<?php } ?>
<?php if (($config['display_github_notice'] ?? false) === true) { ?>
  <p><a href="<?php echo escHtml(NODE_STATUS_REPO); ?>" target="_blank" rel="noopener noreferrer">Fork me on GitHub</a></p>
<?php } ?>
<?php if ($legalLinks) { ?>
  <p class="legal-links"><?php
    $items = [];
    foreach ($legalLinks as $legal) {
        $items[] = '<a href="' . escHtml($legal['url']) . '" target="_blank" rel="noopener noreferrer">' . escHtml($legal['label']) . '</a>';
    }
    echo implode(' &middot; ', $items); ?></p>
<?php } ?>
</footer>
</div>

<button type="button" id="myBtn" class="back-to-top" aria-label="Go to top" title="Go to top" hidden>&uarr; Top</button>
<?php if ($showCookieNotice) { ?>

<div id="cookieNotice" class="cookie-notice" role="region" aria-label="Cookie notice">
  <p>This site only uses technical cookies: they remember your dark/light choice and that you have seen this notice. No tracking.<?php
  if (preg_match('#^https?://#i', (string) $privacyUrl)) { ?> <a href="<?php echo escHtml($privacyUrl); ?>" target="_blank" rel="noopener noreferrer">Privacy policy</a><?php } ?></p>
  <button type="button" id="cookieOk" class="btn">OK</button>
</div>
<?php } ?>
</body>
</html>
