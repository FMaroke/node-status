<?php
/**
 * FMCE Node Status - remote helper
 * Writes the free disk space of the node server (bytes) into a text file that
 * the status page reads via HTTPS ('free_disk_space_url' in php/config.php).
 * Runs on the NODE server by cron, command line only.
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

// Usage on the node server (cron, every 5 minutes):
//   */5 * * * *  php /path/to/save_diskspace.php > /dev/null 2>&1
// Settings: nodestat_remote_config.php in the same folder (copy of nodestat_remote_config_sample.php).
// Exit codes: 0 = ok, 1 = disk space could not be read or written, 2 = configuration error.

// ---------- Command line only ----------
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo "Forbidden\n";
    exit;
}

/**
 * fail Prints an error message on STDERR and ends the script
 *
 * @param string $message  Error text
 * @param int    $exitCode Exit code for cron
 *
 * @return void
 */
function fail(string $message, int $exitCode): void {
    fwrite(STDERR, 'save_diskspace: ' . $message . "\n");
    exit($exitCode);
}

/**
 * loadRemoteConfig Reads and checks the settings
 *
 * @param string $file Path of nodestat_remote_config.php
 *
 * @return array ['drive' => string, 'target' => string, 'owner' => string, 'group' => string]
 */
function loadRemoteConfig(string $file): array {
    if (!is_file($file)) {
        fail('configuration not found: ' . $file . ' (copy nodestat_remote_config_sample.php)', 2);
    }
    $config = null;
    require $file;
    if (!is_array($config)) {
        fail('$config not found in ' . $file, 2);
    }

    $drive  = trim((string) ($config['freespace_drive_name'] ?? ''));
    $target = trim((string) ($config['freespace_file_name'] ?? ''));
    if ($drive === '' || $target === '') {
        fail("'freespace_drive_name' and 'freespace_file_name' must be set", 2);
    }
    if (!is_dir(dirname($target))) {
        fail('target folder does not exist: ' . dirname($target), 2);
    }

    // Optional owner of the file, "user:group" (e.g. the web user of the site)
    $owner = '';
    $group = '';
    $usergroup = trim((string) ($config['chown_usergroup'] ?? ''));
    if ($usergroup !== '' && $usergroup !== 'user:group') {
        [$owner, $group] = array_pad(explode(':', $usergroup, 2), 2, '');
    }

    return ['drive' => $drive, 'target' => $target, 'owner' => $owner, 'group' => $group];
}

/**
 * writeAtomic Writes the text file so that the status page never reads a half written or empty file
 *
 * The number goes to a temporary file in the same folder first, which then
 * replaces the old file in one step (rename). If anything fails, the old
 * file stays unchanged.
 *
 * @param string $target  Target file
 * @param string $content File content
 * @param string $owner   Owner of the file ('' = keep)
 * @param string $group   Group of the file ('' = keep)
 *
 * @return void
 */
function writeAtomic(string $target, string $content, string $owner, string $group): void {
    $tmp = tempnam(dirname($target), '.diskspace_');
    if ($tmp === false) {
        throw new RuntimeException('cannot create a temporary file in ' . dirname($target));
    }

    try {
        if (file_put_contents($tmp, $content) !== strlen($content)) {
            throw new RuntimeException('cannot write ' . $tmp);
        }
        chmod($tmp, 0644);   // readable for the web server

        // chown() works only for root; without root rights the current owner stays
        if ($owner !== '' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            if (!chown($tmp, $owner) || ($group !== '' && !chgrp($tmp, $group))) {
                throw new RuntimeException('cannot set owner ' . $owner . ':' . $group);
            }
        }

        if (!rename($tmp, $target)) {
            throw new RuntimeException('cannot replace ' . $target);
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);   // "@": only cleanup after an error, the error itself is already thrown
        }
    }
}

// ---------- Main ----------
// Warnings become exceptions, so every problem ends in the catch block below.
set_error_handler(static function (int $errno, string $errstr): bool {
    if (!(error_reporting() & $errno) || $errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno);
});

$settings = loadRemoteConfig(__DIR__ . '/nodestat_remote_config.php');

try {
    $free = disk_free_space($settings['drive']);
    if ($free === false || $free <= 0) {
        throw new RuntimeException('cannot read the free space of ' . $settings['drive']);
    }
    writeAtomic($settings['target'], (string) (int) $free, $settings['owner'], $settings['group']);
} catch (Throwable $e) {
    fail($e->getMessage(), 1);
}

exit(0);
