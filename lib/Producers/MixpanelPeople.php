<?php
require_once(dirname(__FILE__) . "/MixpanelBaseProducer.php");

/**
 * Provides an API to create/update profiles on Mixpanel
 */
class Producers_MixpanelPeople extends Producers_MixpanelBaseProducer {

    /**
     * Internal method to prepare a message given the message data
     * @param $distinct_id
     * @param $operation
     * @param $value
     * @param null $ip
     * @return array
     */
    private function _constructPayload($distinct_id, $operation, $value, $ip = null) {
        $payload = array(
            '$token' => $this->_token,
            '$distinct_id' => $distinct_id,
            $operation => $value
        );
        if ($ip != null) $payload['$ip'] = $ip;
        return $payload;
    }

    /**
     * Set properties on a user record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values, overwriting existing values.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     */
    public function set($distinct_id, $props, $ip = null) {
        $payload = $this->_constructPayload($distinct_id, '$set', $props, $ip);
        $this->enqueue($payload);
    }

    /**
     * Set properties on a user record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values but WILL NOT overwrite existing values.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     */
    public function setOnce($distinct_id, $props, $ip = null) {
        $payload = $this->_constructPayload($distinct_id, '$set_once', $props, $ip);
        $this->enqueue($payload);
    }

    /**
     * Increments the value of a property on a user record. If the profile does not exist, it creates it and sets the
     * property to the increment value.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param $prop string the property to increment
     * @param int $val the amount to increment the property by
     * @param string|null $ip the ip address of the client (used for geo-location)
     */
    public function increment($distinct_id, $prop, $val, $ip = null) {
        $payload = $this->_constructPayload($distinct_id, '$add', array("$prop" => $val), $ip);
        $this->enqueue($payload);
    }

    /**
     * Adds $val to a list located at $prop. If the property does not exist, it will be created. If $val is a string
     * and the list is empty or does not exist, a new list with one value will be created.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string $prop the property that holds the list
     * @param string|array $val items to add to the list
     * @param string|null $ip the ip address of the client (used for geo-location)
     */
    public function append($distinct_id, $prop, $val, $ip = null) {
        $operation = gettype($val) == "array" ? '$union' : '$append';
        $payload = $this->_constructPayload($distinct_id, $operation, array("$prop" => $val), $ip);
        $this->enqueue($payload);
    }

    /**
     * Adds a transaction to the user's profile for revenue tracking
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string $amount the transaction amount e.g. "20.50"
     * @param null $timestamp the timestamp of when the transaction occurred (default to current timestamp)
     * @param string|null $ip the ip address of the client (used for geo-location)
     */
    public function trackCharge($distinct_id, $amount, $timestamp = null, $ip = null) {
        $timestamp = $timestamp == null ? time() : $timestamp;
        $date_iso = date("c", $timestamp);
        $transaction = array(
            '$time' => $date_iso,
            '$amount' => $amount
        );
        $val = array('$transactions' => $transaction);
        $payload = $this->_constructPayload($distinct_id, '$append', $val, $ip);
        $this->enqueue($payload);
    }

    /**
     * Clear all transactions stored on a user's profile
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string|null $ip the ip address of the client (used for geo-location)
     */
    public function clearCharges($distinct_id, $ip = null) {
        $payload = $this->_constructPayload($distinct_id, '$set', array('$transactions' => array()), $ip);
        $this->enqueue($payload);
    }

    /**
     * Delete this profile from Mixpanel
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string|null $ip the ip address of the client (used for geo-location)
     */
    public function deleteUser($distinct_id, $ip = null) {
        $payload = $this->_constructPayload($distinct_id, '$delete', "", $ip);
        $this->enqueue($payload);
    }

    /**
     * Returns the "engage" endpoint
     * @return string
     */
    function _getEndpoint() {
        return $this->_options['people_endpoint'];
    }

}
