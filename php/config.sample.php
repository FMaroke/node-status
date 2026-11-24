<?php
/**
 * FMCE Node Status
 * Sample configuration (copy to config.php).
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
 * - added RUNTIME_DIR,
 * - one 'collector' block for all data sources (replaces the single cache scripts),
 * - new options for the fee chart and the BTC/USD price chart (CoinMarketCap),
 * - remote disk space via URL, uptime via RPC,
 * - display formats for date, time and numbers (format_*), replaces 'date_format',
 * - background refresh of the page (refresh_interval, refresh_peers_every),
 * - imprint / privacy links and cookie notice (display_imprint, display_privacy, display_cookie_notice),
 * - peer table in pages (peers_per_page),
 * - long peer user agents shortened in the peer table (peer_agent_max_length),
 * - uptime, free disk space and geolocation fetched by the collector (no node_process_name,
 *   node_uptime_url, cache_geo_data any more),
 * - removed unused options (node_port, donation_qr_size, use_bitcoind_ip,
 *   bitcoind_process_name, nocache_whitelist, all *_whitelist / *_touchfile keys),
 * - the donation line of the software in the footer is always shown (display_donation_text removed),
 * - collector jobs of charts and network height follow the display switches,
 * - rank and peer index from btcnodes.io (display_btcnodes_info, collector job 'btcnodes').
 *
 * SPDX-License-Identifier: Apache-2.0
 */

// Absolute path of the web root (the folder with index.php)
define('RUNTIME_DIR', '/path/to/webroot/web');

$config = array(
    // RPC connection to bitcoind
    'rpc_user'                  => 'RPCUser',
    'rpc_pass'                  => 'RPCPass',
    'rpc_host'                  => 'xxx.xxx.xxx.xxx',
    'rpc_port'                  => '8332',
    'rpc_ssl'                   => false,
    'rpc_ssl_ca'                => null,

    // Node address (shown on the page and used for the node location)
    'node_ip'                   => 'xxx.xxx.xxx.xxx',

    // Donations ('donation_address' => 'not_set' hides the donation block)
    'donation_address'          => 'not_set',
    'donation_amount'           => '0.00002',
    'donation_label'            => 'Donating to my bitcoin node.',
    'donation_qr_height'        => 150,
    'donation_qr_width'         => 150,

    // Peers
    'display_peer_info'         => true,
    'display_peer_port'         => true,
    'display_masked_ip'         => true,      // mask the last part of peer IPs (GDPR)
    'hide_dark_peers'           => true,      // hide peers that are not on the public internet: localhost, Tor, I2P, CJDNS
    'ignore_unknown_ping'       => false,
    'peers_to_ignore'           => array(),

    // Cache
    'cache_dir'                 => RUNTIME_DIR . '/data',
    'geo_cache_file'            => RUNTIME_DIR . '/data/nodestat-geolocation.cache',
    'geo_cache_time'            => 604800,    // seconds (7 days) until a peer location is looked up again
    'use_cache'                 => true,      // page cache
    'cache_file'                => RUNTIME_DIR . '/data/nodestat-status.cache',
    'max_cache_time'            => 60,        // seconds: the page is rebuilt at most once per minute
    'refresh_interval'          => 60,        // seconds between background updates of the open page (min. 30, 0 = off)
    'refresh_peers_every'       => 3,         // update the peer table only every n-th time (it is the largest part)
    'peers_per_page'            => 10,        // peers per page in the peer table (visitors can change it; 0 = all)
    'peer_agent_max_length'     => 40,        // max. characters of a peer's user agent in the peer table, full text as tooltip (0 = never shorten)

    // Geolocation
    'geolocate_peer_ip'         => true,
    'display_ip_location'       => true,

    // UI
    'canonical_url'             => '',   // public URL of the page, best ending with '/' ('' = none); the header logo links here, also needed for 'share_image'
    'img_dir'                   => RUNTIME_DIR . '/img',   // must be the img/ folder of the web root (the page links img/...)
    'site_logo_dark'            => 'img/cryptcoin-de-logo_dark.svg',    // '' = no logo
    'site_logo_light'           => 'img/cryptcoin-de-logo_light.svg',
    'share_image'               => 'img/og-image_light.jpg',   // preview image for links in social media/messengers, 1200x630 px ('' = none; dark: img/og-image_dark.jpg)
    'display_ip'                => true,
    'display_free_disk_space'   => true,
    'display_version'           => true,
    'display_protocolversion'   => true,
    'display_github_notice'     => false,
    'display_max_height'        => true,
    'display_btcnodes_info'     => false,     // rank and peer index of this node at btcnodes.io (job 'btcnodes' below; max. 10 requests per day and IP)
    'intro_text'                => 'This Bitcoin node is running 24/7 to help support the Bitcoin network around the globe.', // 'not_set' = no intro
    'title_text'                => 'My Bitcoin Node - Status',
    'display_connection_chart'  => true,
    'display_peer_chart'        => true,
    'display_fee_chart'         => true,
    'display_price_chart'       => true,      // BTC/USD (CoinMarketCap data of the collector)
    'node_links'                => array(
        array(
            'name'  => 'bitcoin.org',
            'image' => 'img/bitcoin_org_dark.svg',
            'link'  => 'https://bitcoin.org/',
        ),
        array(
            'name'  => 'blockchain.com',
            'image' => 'img/blockchain_com_dark.png',
            'link'  => 'https://www.blockchain.com/explorer/assets/btc',
        ),
        array(
            'name'  => 'mempool.space',
            'image' => 'img/mempool_space_dark.png',
            'link'  => 'https://mempool.space/',
        ),
        array(
            'name'  => 'bitnodes.io',
            'image' => 'img/bitnodes_io_dark.png',
            'link'  => 'https://bitnodes.io/',
        ),
        array(
            'name'  => 'btcnodes.io',
            'image' => 'img/btcnodes_dark.png',
            'link'  => 'https://btcnodes.io/',
        ),
        array(
            'name'  => 'bitnod.es',
            'image' => 'img/bitnod_es_dark.png',
            'link'  => 'https://bitnod.es/node.php?xxx.xxx.xxx.xxx',
        ),
        array(
            'name'  => 'CoinMarketCap',
            'image' => 'img/coinmarketcap_dark.png',
            'link'  => 'https://coinmarketcap.com/currencies/bitcoin/',
        ),
        array(
            'name'  => 'DSN Bitcoin Monitoring',
            'image' => 'img/dsn_dark.png',
            'link'  => 'https://www.dsn.kastel.kit.edu/bitcoin/',
        ),
        array(
            'name'  => 'Blockstream',
            'image' => 'img/blockstream_dark.png',
            'link'  => 'https://blockstream.info/',
        ),
    ),

    // Collector (php/collector.php): fetches all data for the page.
    // One cron job every minute, see doc/fmce-node-status.sample.
    // Each job runs when its 'interval' (seconds, minimum 60) has passed.
    // Every value is optional: missing values use the defaults shown here.
    'collector'                 => array(
        'lock_file'     => RUNTIME_DIR . '/data/nodestat-collector.lock',
        'state_file'    => RUNTIME_DIR . '/data/nodestat-collector.state',
        'rpc_timeout'   => 15,     // seconds for the node (all RPC calls run in parallel)
        'http_timeout'  => 10,     // seconds for blockchain.info, CoinMarketCap, ip2location.io and free_disk_space_url
        'stale_factor'  => 3,      // data counts as outdated after 3 x interval
        'jobs'          => array(
            // Snapshots (latest answer only)
            'blockchaininfo' => array('enabled' => true, 'interval' => 60,
                                      'file' => RUNTIME_DIR . '/data/nodestat-blockchaininfo.data'),
            'networkinfo'    => array('enabled' => true, 'interval' => 60,
                                      'file' => RUNTIME_DIR . '/data/nodestat-networkinfo.data'),
            'peerinfo'       => array('enabled' => true, 'interval' => 60,
                                      'file' => RUNTIME_DIR . '/data/nodestat-peerinfo.data'),
            'latestblock'    => array('enabled' => true, 'interval' => 60,           // only if display_max_height
                                      'file' => RUNTIME_DIR . '/data/nodestat-latestblock.data'),
            // Time series for the charts (max_age in seconds, min_data_points before a chart is shown).
            // Each chart job only runs if its chart is shown (display_connection_chart, display_peer_chart,
            // display_fee_chart, display_price_chart).
            'connections'    => array('enabled' => true, 'interval' => 300, 'max_age' => 604800, 'min_data_points' => 5,
                                      'file' => RUNTIME_DIR . '/data/nodestat-connections.data'),
            'peercount'      => array('enabled' => true, 'interval' => 300, 'max_age' => 2592000, 'min_data_points' => 10,
                                      'file' => RUNTIME_DIR . '/data/nodestat-peercount.data',
                                      // Additional client types: label => text in the peer's user agent
                                      'extra_nodes' => array('dsn' => 'dsn', 'Metrika' => 'Metrika')),
            'fee'            => array('enabled' => true, 'interval' => 300, 'max_age' => 604800, 'min_data_points' => 5,
                                      'file' => RUNTIME_DIR . '/data/nodestat-fees.data',
                                      'conf_target'   => 2,                 // confirmation target in blocks
                                      'estimate_mode' => 'conservative'),   // 'conservative' (stable) or 'economical' (reacts faster)
            'uptime'         => array('enabled' => true, 'interval' => 60,           // only if display_node_uptime
                                      'file' => RUNTIME_DIR . '/data/nodestat-uptime.data'),
            'diskspace'      => array('enabled' => true, 'interval' => 300,          // only if display_free_disk_space
                                      'file' => RUNTIME_DIR . '/data/nodestat-diskspace.data'),
            'geolocation'    => array('enabled' => true, 'interval' => 300,          // only if geolocate_peer_ip / display_ip_location
                                      'max_lookups' => 20,                            // new addresses per run (free plan: limited per day)
                                      'apikey'      => ''),                           // optional ip2location.io API key
            'cmc_price'      => array('enabled' => true, 'interval' => 300, 'max_age' => 604800, 'min_data_points' => 5,
                                      'file' => RUNTIME_DIR . '/data/nodestat-cmc_price.data',
                                      'apikey'  => 'keyless',   // or your CoinMarketCap API key
                                      'symbols' => 'BTC,LTC',
                                      'convert' => 'USD,EUR'),
            // Rank and peer index at btcnodes.io (only if display_btcnodes_info). btcnodes.io allows
            // 10 API requests per day and IP address: the collector sends at most 8 in 24 hours
            // (never more often than every 3 hours) and waits after HTTP 429. Webspace shared with
            // other sites shares this limit; a server with its own IP address is recommended.
            'btcnodes'       => array('enabled' => true, 'interval' => 10800,        // seconds, minimum 3 hours
                                      'node_address' => '',   // public IPv4 address and port of this node, e.g. '203.0.113.5:8333' ('' = from getnetworkinfo localaddresses)
                                      'api_url'      => 'https://btcnodes.io/api/v1'),
        ),
    ),

    // Node on a remote server (true) or on this server (false); used for the free disk space
    'node_is_remote'            => true,

    // Uptime of the node daemon (read by the collector via RPC "uptime", local and remote)
    'display_node_uptime'       => true,

    // Free disk space (read by the collector). Remote: text file with the bytes (remote_files/save_diskspace.php),
    // local: mount point (a relative path counts from the web root)
    'free_disk_space_url'       => 'https://your_remote.server/bitcoin_node_freespace.txt',
    'disk_space_mount_point'    => '.',

    // System
    // Display formats (only for the output; stored data is never changed)
    'format_date'               => 'd.m.y',   // PHP date() letters: d.m.y = 15.11.26, Y-m-d = 2026-11-15, m/d/Y = 11/15/2026
    'format_time'               => 'H:i:s',   // 24 hours; 12 hours: 'g:i:s A'
    'format_decimal_point'      => ',',       // international: '.'
    'format_thousands_sep'      => '.',       // international: ',' (or ' ' / '')
    'format_language'           => 'de',      // language of the chart axes (month names, number symbols), e.g. 'en'
    'timezone'                  => 'Europe/Berlin',
    'css_style'                 => 'light',   // default theme: 'light' or 'dark' (visitors can switch)
    'debug'                     => false,     // true: show collector error messages on the page (can contain host names)
    // Legal links in the footer (open in a new tab). Not needed in every country: switch off with false.
    'display_imprint'           => true,
    'imprint_url'               => 'https://example.com/imprint',    // replace with your imprint page
    'display_privacy'           => true,
    'privacy_url'               => 'https://example.com/privacy',    // replace with your privacy policy
    // Small notice (bottom right) about the two technical cookies (dark/light choice, notice seen); hidden after "OK"
    'display_cookie_notice'     => true,
    'admin_email'               => 'email@example.com',
);
