<?php
/**
 * Portions of this class were borrowed from
 * https://github.com/segmentio/analytics-php/blob/master/lib/Analytics/Consumer/Socket.php.
 * Thanks for the work!
 *
 * WWWWWW||WWWWWW
 * W W W||W W W
 * ||
 * ( OO )__________
 * /  |           \
 * /o o|    MIT     \
 * \___/||_||__||_|| *
 * || ||  || ||
 * _||_|| _||_||
 * (__|__|(__|__|
 * (The MIT License)
 *
 * Copyright (c) 2013 Segment.io Inc. friends@segment.io
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the 'Software'), to deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
 * Software.
 *
 * THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS
 * OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
 * OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
require_once(dirname(__FILE__) . "/AbstractConsumer.php");

class ConsumerStrategies_SocketConsumer extends ConsumerStrategies_AbstractConsumer {

    /**
     * @var string the host to connect to (e.g. api.mixpanel.com)
     */
    private $_host;


    /**
     * @var string the host-relative endpoint to write to (e.g. /engage)
     */
    private $_endpoint;


    /**
     * @var int timeout the socket connection timeout in seconds
     */
    private $_timeout;


    /**
     * @var string the protocol to use for the socket connection
     */
    private $_protocol;


    /**
     * @var bool flag to denote if the socket has failed
     */
    private $_socket_failed;


    public function __construct($options = array()) {
        parent::__construct($options);


        $this->_host = $options['host'];
        $this->_endpoint = $options['endpoint'];
        $this->_timeout = array_key_exists('timeout', $options) ? $options['timeout'] : 1;
        $this->_debug = array_key_exists('debug', $options) ? ($options['debug'] == true) : false;

        if (array_key_exists('use_ssl', $options) && $options['use_ssl'] == true) {
            $this->_protocol = "ssl";
            $this->_port = 443;
        } else {
            $this->_protocol = "tcp";
            $this->_port = 80;
        }
    }


    /**
     * Write using a persistent socket connection.
     * @param array $batch
     * @return bool
     */
    public function persist($batch) {

        $data = "data=".$this->_encode($batch);
        $socket = $this->createSocket();

        if (!$socket) {
            return false;
        }

        $body = "";
        $body.= "POST ".$this->_endpoint." HTTP/1.1\r\n";
        $body.= "Host: " . $this->_host . "\r\n";
        $body.= "Content-Type: application/x-www-form-urlencoded\r\n";
        $body.= "Accept: application/json\r\n";
        $body.= "Content-length: " . strlen($data) . "\r\n";
        $body.= "\r\n";
        $body.= $data;

        return $this->write($socket, $body);
    }


    /**
     * Create a new persistent socket
     * @return bool|resource
     */
    private function createSocket() {

        if ($this->_socket_failed) {
            return false;
        }

        try {
            $socket = pfsockopen($this->_protocol . "://" . $this->_host, $this->_port, $err_no, $err_msg, $this->_timeout);

            if ($this->_debug()) {
                $this->_log("Opening socket connection to " . $this->_protocol . "://" . $this->_host . ":" . $this->_port);
            }

            if ($err_no != 0) {
                $this->_handleError($err_no, $err_msg);
                $this->_socket_failed = true;
                return false;
            }

            return $socket;

        } catch (Exception $e) {
            $this->_handleError($e->getCode(), $e->getMessage());
            $this->_socket_failed = true;
            return false;
        }
    }


    /**
     * Write $data through the given $socket
     * @param $socket
     * @param $data
     * @param bool $retry
     * @return bool
     */
    private function write($socket, $data, $retry = true) {

        $bytes_sent = 0;
        $bytes_total = strlen($data);
        $socket_closed = false;

        // try to write the data
        while (!$socket_closed && $bytes_sent < $bytes_total) {
            try {
                $bytes = fwrite($socket, $data);
            } catch (Exception $e) {
                $this->_handleError($e->getCode(), $e->getMessage());
                $socket_closed = true;
            }
            if (isset($bytes) && $bytes) {
                $bytes_sent += $bytes;
            } else {
                $socket_closed = true;
            }
        }

        // try opening the socket again if it's closed
        if ($socket_closed) {
            fclose($socket);

            if ($retry) {
                $socket = $this->createSocket();
                if ($socket) return $this->write($socket, $data, false);
            }
            return false;
        }

        $success = true;

        // only wait for the response in debug mode
        if ($this->_debug()) {
            $res = $this->handleResponse(fread($socket, 2048));
            if ($res["status"] != "200") {
                $this->_handleError($res["status"], $res["body"]);
                $success = false;
            }
        }

        return $success;
    }


    /**
     * Parse the response from a socket write (only used for debugging)
     * @param $response
     * @return array
     */
    private function handleResponse($response) {
        $lines = explode("\n", $response);
        $line_one_exploded = explode(" ", $lines[0]);
        $status = $line_one_exploded[1];
        $body = $lines[count($lines) - 1];
        $ret = array(
            "status"  => $status,
            "body" => $body
        );
        return $ret;
    }


}