<?php
/**
 * FMCE Node Status - remote helper configuration (sample)
 * Copy to nodestat_remote_config.php in the same folder on the NODE server.
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

$config = array(
    // Drive / mount point of the blockchain data (e.g. '/' or '/mnt/blockchain')
    'freespace_drive_name'      => '/drivename',

    // Text file with the free bytes. It must be reachable via HTTPS by the status page
    // ('free_disk_space_url' in php/config.php), e.g. in the web folder of a site on the node server.
    'freespace_file_name'       => '/your/path/to/bitcoin_node_freespace.txt',

    // Owner of the text file, "user:group" (e.g. the web user of that site, 'www-data:www-data').
    // Only used if the script runs as root; '' = keep the owner.
    'chown_usergroup'           => 'user:group',
);
