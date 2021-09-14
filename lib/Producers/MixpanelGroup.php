<?php
require_once(dirname(__FILE__) . "/MixpanelBaseProducer.php");

/**
 * Provides an API to create/update groups on Mixpanel
 */
class Producers_MixpanelGroup extends Producers_MixpanelBaseProducer {

    /**
     * Internal method to prepare a message given the message data
     * @param $group_key
	 * @param $group_id
     * @param $operation
     * @param $value
     * @param null $ip
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found
     * @return array
     */
    private function _constructPayload($group_key, $group_id, $operation, $value, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $payload = array(
            '$token' => $this->_token,
            '$group_key' => $group_key,
            '$group_id' => $group_id,
            $operation => $value
        );
        if ($ip !== null) $payload['$ip'] = $ip;
        if ($ignore_time === true) $payload['$ignore_time'] = true;
        if ($ignore_alias === true) $payload['$ignore_alias'] = true;
        return $payload;
    }

    /**
     * Set properties on a group record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values, overwriting existing values.
     * @param string|int $group_key the group Key to be used to lookup the group ID
	 * @param string|int $group_id the group ID to be used to identify the group
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found
     */
    public function set($group_key, $group_id, $props, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$set', $props, $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Set properties on a group record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values but WILL NOT overwrite existing values.
     * @param string|int $group_key the group Key to be used to lookup the group ID
	 * @param string|int $group_id the group ID to be used to identify the group
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function setOnce($group_key, $group_id, $props, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$set_once', $props, $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Unset properties on a group record. If the profile does not exist, it creates it with no properties.
     * If it does exist, it unsets these properties. NOTE: In other libraries we use 'unset' which is
     * a reserved word in PHP.
     * @param string|int $group_key the group Key to be used to lookup the group ID
	 * @param string|int $group_id the group ID to be used to identify the group
     * @param array $props associative array of properties to unset on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function unsetProps($group_key, $group_id, $props, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$unset', $props, $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

	/**
	 * Unset properties on a group record. If the profile does not exist, it creates it with no properties.
	 * If it does exist, it unsets these properties. NOTE: In other libraries we use 'unset' which is
	 * a reserved word in PHP.
	 * @param string|int $group_key the group Key to be used to lookup the group ID
	 * @param string|int $group_id the group ID to be used to identify the group
	 * @param array $props associative array of properties to unset on the profile
	 * @param string|null $ip the ip address of the client (used for geo-location)
	 * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
	 * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found
	 */
	public function remove($group_key, $group_id, $props, $ip = null, $ignore_time = false, $ignore_alias = false) {
		$payload = $this->_constructPayload($group_key, $group_id, '$remove', $props, $ip, $ignore_time, $ignore_alias);
		$this->enqueue($payload);
	}

    /**
     * Adds $val to a list located at $prop. If the property does not exist, it will be created. If $val is a string
     * and the list is empty or does not exist, a new list with one value will be created.
     * @param string|int $group_key the group Key to be used to lookup the group ID
	 * @param string|int $group_id the group ID to be used to identify the group
     * @param string $prop the property that holds the list
     * @param string|array $val items to add to the list
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function append($group_key, $group_id, $prop, $val, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $operation = gettype($val) == "array" ? '$union' : '$append';
        $payload = $this->_constructPayload($group_key, $group_id, $operation, array("$prop" => $val), $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Delete this profile from Mixpanel
     * @param string|int $group_key the group Key to be used to lookup the group ID
	 * @param string|int $group_id the group ID to be used to identify the group
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function deleteGroup($group_key, $group_id, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$delete', "", $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Returns the "engage" endpoint
     * @return string
     */
    function _getEndpoint() {
        return $this->_options['group_endpoint'];
    }

}
