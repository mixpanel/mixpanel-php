<?php

namespace Mixpanel\Producers;

/**
 * Provides an API to create/update profiles on Mixpanel
 */
class MixpanelPeople extends MixpanelBaseProducer {

    /**
     * Internal method to prepare a message given the message data
     * @param $distinct_id
     * @param $operation
     * @param $value
     * @param string $ip
     * @param bool $ignore_time
     * @return array
     */
    private function _constructPayload($distinct_id, $operation, $value, $ip = null, $ignore_time = false) {
        $payload = array(
            '$token' => $this->_token,
            '$distinct_id' => $distinct_id,
            $operation => $value
        );
        if ($ip !== null) $payload['$ip'] = $ip;
        if ($ignore_time === true) $payload['$ignore_time'] = true;
        return $payload;
    }

    /**
     * Set properties on a user record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values, overwriting existing values.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function set($distinct_id, $props, $ip = null, $ignore_time = false) {
        $payload = $this->_constructPayload($distinct_id, '$set', $props, $ip, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Set properties on a user record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values but WILL NOT overwrite existing values.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function setOnce($distinct_id, $props, $ip = null, $ignore_time = false) {
        $payload = $this->_constructPayload($distinct_id, '$set_once', $props, $ip, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Unset properties on a user record. If the profile does not exist, it creates it with no properties.
     * If it does exist, it unsets these properties. NOTE: In other libraries we use 'unset' which is
     * a reserved word in PHP.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param array $props associative array of properties to unset on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function remove($distinct_id, $props, $ip = null, $ignore_time = false) {
        $payload = $this->_constructPayload($distinct_id, '$unset', $props, $ip, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Increments the value of a property on a user record. If the profile does not exist, it creates it and sets the
     * property to the increment value.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param $prop string the property to increment
     * @param int $val the amount to increment the property by
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function increment($distinct_id, $prop, $val, $ip = null, $ignore_time = false) {
        $payload = $this->_constructPayload($distinct_id, '$add', array("$prop" => $val), $ip, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Adds $val to a list located at $prop. If the property does not exist, it will be created. If $val is a string
     * and the list is empty or does not exist, a new list with one value will be created.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string $prop the property that holds the list
     * @param string|array $val items to add to the list
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function append($distinct_id, $prop, $val, $ip = null, $ignore_time = false) {
        $operation = gettype($val) == "array" ? '$union' : '$append';
        $payload = $this->_constructPayload($distinct_id, $operation, array("$prop" => $val), $ip, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Adds a transaction to the user's profile for revenue tracking
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string $amount the transaction amount e.g. "20.50"
     * @param null $timestamp the timestamp of when the transaction occurred (default to current timestamp)
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function trackCharge($distinct_id, $amount, $timestamp = null, $ip = null, $ignore_time = false) {
        $timestamp = $timestamp == null ? time() : $timestamp;
        $date_iso = date("c", $timestamp);
        $transaction = array(
            '$time' => $date_iso,
            '$amount' => $amount
        );
        $val = array('$transactions' => $transaction);
        $payload = $this->_constructPayload($distinct_id, '$append', $val, $ip, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Clear all transactions stored on a user's profile
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function clearCharges($distinct_id, $ip = null, $ignore_time = false) {
        $payload = $this->_constructPayload($distinct_id, '$set', array('$transactions' => array()), $ip, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Delete this profile from Mixpanel
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function deleteUser($distinct_id, $ip = null, $ignore_time = false) {
        $payload = $this->_constructPayload($distinct_id, '$delete', "", $ip, $ignore_time);
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
