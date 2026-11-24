<?php
/**
 * EasyBitcoin-PHP Enhanced
 *
 * An improved version of the original EasyBitcoin-PHP library
 * Based on EasyBitcoin-PHP by aceat64 (https://github.com/aceat64/EasyBitcoin-PHP)
 *
 * Original Author: aceat64
 * Enhanced by: Community contribution (byshigenobu)(mandias)
 *
 * Changelog:
 * v1.3 (FMCE Concept UG, for FMCE Node Status):
 * - PHP 8.5: no more curl_close() (deprecated), handles are released with unset(),
 * - batch(): real cURL error messages via curl_multi_info_read() (before: "HTTP error: 0"),
 * - RPC error messages are also read from HTTP 4xx/5xx answers
 *   (bitcoind answers RPC errors with HTTP 500 and a JSON body),
 * - redirects disabled (an RPC endpoint never redirects; protects the login),
 * - SSL: certificate verification is on by default, setSSL() got a $verifyPeer switch,
 * - new setTimeouts() and isBatchError(),
 * - batch(): no busy loop if curl_multi_select() returns -1,
 * - docblocks, no closing PHP tag,
 * - invalid UTF-8 in an RPC answer (e.g. a broken peer user agent) is replaced
 *   by U+FFFD (JSON_INVALID_UTF8_SUBSTITUTE) instead of failing the whole call.
 *
 * v1.2:
 * - Added batch/async support using curl_multi (no external dependencies)
 *
 * v1.1:
 * - Fixed null response handling (line 187 error)
 * - Better error handling and status reporting
 * - Enhanced cURL configuration
 * - Improved JSON parsing with error checking
 * - Added timeout and connection handling
 * - Compatible with modern PHP versions
 * - Better SSL support
 *
 * Modifications v1.3 Copyright 2026 FMCE Concept UG (https://fmce.de), Frank Maroke
 * CoAuthor: Claude / Claude Code - Opus 5.5
 * Compatible with PHP 8.0+, optimised for PHP 8.4 / 8.5.
 *
 * @version 1.3 Enhanced
 * @license MIT (same as original, see LICENSE_easybitcoin)
 */

/**
 * Bitcoin Simple JSON-RPC client for bitcoind (single calls and parallel batch calls)
 *
 * Single call:  $bitcoin->getblockchaininfo()          returns the result or false
 * Batch call:   $bitcoin->batch(['a' => ['method' => 'getnetworkinfo'], ...])
 *               returns ['a' => result or ['error' => 'message'], ...]
 */
class Bitcoin {

    /** @var string Username for the RPC connection */
    private $username;

    /** @var string Password for the RPC connection */
    private $password;

    /** @var string Protocol (http or https) */
    private $proto;

    /** @var string Host for the RPC connection */
    private $host;

    /** @var int|string Port for the RPC connection */
    private $port;

    /** @var string|null Full RPC URL (optional, overrides proto/host/port) */
    private $url;

    /** @var int ID for RPC calls */
    private $id;

    /** @var string|null Path to a CA certificate for SSL connections */
    private $certificate = null;

    /** @var bool Verify the SSL certificate of the RPC server */
    private $verifyPeer = true;

    /** @var int Maximum time for one RPC call in seconds */
    private $timeout = 30;

    /** @var int Maximum time to connect in seconds */
    private $connectTimeout = 10;

    /** @var int|null HTTP status of the last single call */
    public $status;

    /** @var string|null Error message of the last single call */
    public $error;

    /** @var array|null Decoded response of the last single call */
    public $response;

    /** @var string|false|null Raw JSON response of the last single call */
    public $raw_response;

    /**
     * __construct Initialises the connection parameters
     *
     * @param string      $username RPC username
     * @param string      $password RPC password
     * @param string      $host     RPC host (default: localhost)
     * @param int|string  $port     RPC port (default: 8332)
     * @param string|null $url      Optional full RPC URL
     *
     * @return void
     */
    public function __construct($username, $password, $host = 'localhost', $port = 8332, $url = null) {
        $this->username = (string) $username;
        $this->password = (string) $password;
        $this->host     = (string) $host;
        $this->port     = $port;
        $this->url      = $url;
        $this->proto    = 'http';
        $this->id       = 0;
    }

    /**
     * setSSL Switches the connection to HTTPS
     *
     * @param string|null $certificate Path to a CA certificate file (optional)
     * @param bool        $verifyPeer  Verify the server certificate (default true);
     *                                 false only for self-signed setups without CA file
     *
     * @return void
     */
    public function setSSL($certificate = null, $verifyPeer = true) {
        $this->proto = 'https';
        if ($certificate) {
            $this->certificate = (string) $certificate;
        }
        $this->verifyPeer = (bool) $verifyPeer;
    }

    /**
     * setTimeouts Sets the cURL timeouts for all following calls
     *
     * @param int $timeout        Maximum time for one call in seconds
     * @param int $connectTimeout Maximum time to connect in seconds
     *
     * @return void
     */
    public function setTimeouts($timeout, $connectTimeout) {
        $this->timeout        = max(1, (int) $timeout);
        $this->connectTimeout = max(1, (int) $connectTimeout);
    }

    /**
     * isBatchError Checks whether one entry returned by batch() is an error
     *
     * @param mixed $result One entry of the batch() result
     *
     * @return bool
     */
    public static function isBatchError($result) {
        return is_array($result) && count($result) === 1 && array_key_exists('error', $result);
    }

    /**
     * getUrl Builds the RPC URL
     *
     * @return string
     */
    private function getUrl() {
        return $this->url ? $this->url : "{$this->proto}://{$this->host}:{$this->port}/";
    }

    /**
     * buildRequest Builds the JSON request body for one RPC call
     *
     * @param string $method RPC method name
     * @param array  $params RPC parameters
     *
     * @return string|false JSON string or false on encoding error
     */
    private function buildRequest($method, array $params) {
        return json_encode(array(
            'method' => $method,
            'params' => array_values($params),
            'id'     => $this->id++,
        ));
    }

    /**
     * getCurlOptions Returns the cURL options for one RPC call
     *
     * @param string $request JSON request string
     *
     * @return array cURL options
     */
    private function getCurlOptions($request) {
        $curl_options = array(
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   // an RPC server never redirects
            CURLOPT_HTTPHEADER     => array('Content-type: application/json'),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $request,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT      => 'EasyBitcoin-PHP Enhanced/1.3',
        );

        // Add SSL options if using HTTPS
        if ($this->proto === 'https') {
            if ($this->certificate !== null) {
                $curl_options[CURLOPT_CAINFO] = $this->certificate;
            }
            $curl_options[CURLOPT_SSL_VERIFYPEER] = $this->verifyPeer;
            $curl_options[CURLOPT_SSL_VERIFYHOST] = $this->verifyPeer ? 2 : 0;
        }

        return $curl_options;
    }

    /**
     * processResponse Decodes the JSON answer and extracts RPC errors
     *
     * @param string $raw_response Raw JSON response
     *
     * @return array Decoded response, or array('error' => message) on error
     */
    private function processResponse($raw_response) {
        // One broken string (e.g. a peer user agent) must not fail the whole answer
        $response = json_decode((string) $raw_response, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'JSON decode error: ' . json_last_error_msg());
        }

        if (!is_array($response)) {
            return array('error' => 'Invalid response format - expected JSON object');
        }

        // RPC error reported by bitcoind
        if (isset($response['error']) && $response['error'] !== null) {
            if (is_array($response['error'])) {
                $error_msg = isset($response['error']['message'])
                    ? $response['error']['message']
                    : 'Unknown RPC error';
            } else {
                $error_msg = (string) $response['error'];
            }
            return array('error' => $error_msg, 'response' => $response);
        }

        return $response;
    }

    /**
     * evaluate Turns HTTP status and body of one call into result or error
     *
     * bitcoind answers RPC errors (unknown method, wrong parameters) with
     * HTTP 404/500 and a JSON body that contains the real message.
     *
     * @param int    $httpCode HTTP status code
     * @param string $content  Response body
     *
     * @return array ['ok' => bool, 'result' => mixed, 'error' => string|null, 'response' => array|null]
     */
    private function evaluate($httpCode, $content) {
        $processed = ($content !== '') ? $this->processResponse($content) : array('error' => 'Empty response');

        if (isset($processed['error'])) {
            $message = $processed['error'];
            if ($httpCode !== 200) {
                // Keep the RPC message if there is one, otherwise show the HTTP status
                $message = ($content !== '' && isset($processed['response']))
                    ? "HTTP error: {$httpCode} ({$message})"
                    : "HTTP error: {$httpCode}";
            }
            return array('ok' => false, 'result' => null, 'error' => $message, 'response' => null);
        }

        if ($httpCode !== 200) {
            return array('ok' => false, 'result' => null, 'error' => "HTTP error: {$httpCode}", 'response' => null);
        }

        return array(
            'ok'       => true,
            'result'   => isset($processed['result']) ? $processed['result'] : null,
            'error'    => null,
            'response' => $processed,
        );
    }

    /**
     * batch Executes several RPC calls in parallel (curl_multi)
     *
     * The keys of $calls are kept, so named keys can be used:
     *   $bitcoin->batch(['net' => ['method' => 'getnetworkinfo'],
     *                    'fee' => ['method' => 'estimatesmartfee', 'params' => [2]]]);
     *
     * @param array $calls List of calls: ['method' => string, 'params' => array (optional)]
     *
     * @return array Result per key: the RPC result, or array('error' => message)
     *               (test with Bitcoin::isBatchError())
     */
    public function batch(array $calls) {
        if (empty($calls)) {
            return array();
        }

        $multiHandle = curl_multi_init();

        $curlHandles = array();
        $results     = array();
        $url         = $this->getUrl();

        // Prepare all requests
        foreach ($calls as $index => $call) {
            if (!is_array($call) || !isset($call['method'])) {
                $results[$index] = array('error' => 'Missing method parameter');
                continue;
            }

            $params  = (isset($call['params']) && is_array($call['params'])) ? $call['params'] : array();
            $request = $this->buildRequest((string) $call['method'], $params);
            if ($request === false) {
                $results[$index] = array('error' => 'JSON encode error: ' . json_last_error_msg());
                continue;
            }

            $curl = curl_init($url);
            if ($curl === false) {
                $results[$index] = array('error' => 'Failed to initialize cURL handle');
                continue;
            }
            curl_setopt_array($curl, $this->getCurlOptions($request));

            if (curl_multi_add_handle($multiHandle, $curl) !== CURLM_OK) {
                unset($curl);
                $results[$index] = array('error' => 'Failed to add handle to multi');
                continue;
            }

            $curlHandles[$index] = $curl;
        }

        // Execute all requests in parallel
        $running = 0;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                // curl_multi_select() returns -1 on some systems: wait a moment instead of spinning
                if (curl_multi_select($multiHandle, 0.5) === -1) {
                    usleep(50000);
                }
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Collect the transfer result (cURL error code) of every handle
        $transferCodes = array();
        while (($info = curl_multi_info_read($multiHandle)) !== false) {
            $transferCodes[spl_object_id($info['handle'])] = $info['result'];
        }

        // Collect results in the order of $calls
        foreach ($calls as $index => $call) {
            if (!isset($curlHandles[$index])) {
                continue; // preparation error, already in $results
            }
            $curl     = $curlHandles[$index];
            $code     = $transferCodes[spl_object_id($curl)] ?? CURLE_OK;
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($code !== CURLE_OK) {
                $results[$index] = array('error' => 'cURL error: ' . curl_strerror($code));
            } else {
                $outcome = $this->evaluate($httpCode, (string) curl_multi_getcontent($curl));
                $results[$index] = $outcome['ok'] ? $outcome['result'] : array('error' => $outcome['error']);
            }

            curl_multi_remove_handle($multiHandle, $curl);
            unset($curlHandles[$index], $curl);   // instead of curl_close() (deprecated as of PHP 8.5)
        }

        curl_multi_close($multiHandle);

        // Same key order as $calls
        $ordered = array();
        foreach ($calls as $index => $call) {
            $ordered[$index] = $results[$index];
        }
        return $ordered;
    }

    /**
     * __call Executes one RPC call, e.g. $bitcoin->getblockchaininfo()
     *
     * On error the method returns false and sets $this->error and $this->status.
     *
     * @param string $method RPC method name
     * @param array  $params RPC method parameters
     *
     * @return mixed Result of the RPC call, or false on error
     */
    public function __call($method, $params) {
        // Reset status variables
        $this->status       = null;
        $this->error        = null;
        $this->response     = null;
        $this->raw_response = null;

        $request = $this->buildRequest($method, $params);
        if ($request === false) {
            $this->error = 'JSON encode error: ' . json_last_error_msg();
            return false;
        }

        $curl = curl_init($this->getUrl());
        if ($curl === false) {
            $this->error = 'Failed to initialize cURL';
            return false;
        }
        curl_setopt_array($curl, $this->getCurlOptions($request));

        $this->raw_response = curl_exec($curl);
        $this->status       = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError          = curl_error($curl);
        unset($curl);   // instead of curl_close() (deprecated as of PHP 8.5)

        if ($this->raw_response === false) {
            $this->error = 'cURL error: ' . $curlError;
            return false;
        }

        $outcome = $this->evaluate($this->status, (string) $this->raw_response);
        if (!$outcome['ok']) {
            $this->error = $outcome['error'];
            return false;
        }

        // Store the full response for compatibility
        $this->response = $outcome['response'];

        return $outcome['result'];
    }
}
