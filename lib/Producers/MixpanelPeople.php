<?php
require_once(dirname(__FILE__) . "/MixpanelBaseProducer.php");
require_once(dirname(__FILE__) . "/MixpanelGroups.php");

/**
 * Provides an API to create/update profiles on Mixpanel
 *
 * Hierarchical groups
 * -------------
 *
 * On a hierarchical groups project (option hierarchical_groups => true) user profiles are group profiles keyed by
 * $user_id, so they support only what /groups supports: $set, $set_once, $unset, $union, $remove and $delete.
 * increment, append of a single value and trackCharge have no equivalent there and throw BadMethodCallException.
 * clearCharges throws BadMethodCallException too.
 * set, setOnce, remove, deleteUser and append of an array also accept a MixpanelIdentity in place of the distinct id.
 * Nothing is sent to /engage: every update goes to /groups as the $user_id group profile within the user's company,
 * named either by an identity with a user id and a company, or by a distinct id (given directly or as the identity's
 * distinct_id) of the form $company:{company}|$user:{user}. Any other distinct id throws InvalidArgumentException.
 */
class Producers_MixpanelPeople extends Producers_MixpanelBaseProducer {

    /**
     * Operations a hierarchical groups user profile supports; /groups has no $add, $append or $merge
     * @var array
     */
    private static $_group_operations = array('$set', '$set_once', '$unset', '$union', '$remove', '$delete');


    /**
     * The producer identity-based profile updates on a hierarchical groups project are queued on
     * @var Producers_MixpanelGroups|null
     */
    private $_groups = null;


    /**
     * Creates a new people producer.
     * @param $token
     * @param array $options
     * @param Producers_MixpanelGroups|null $groups [optional] the producer to queue /groups profile updates on;
     * one is created on first use when not given
     */
    public function __construct($token, $options = array(), $groups = null) {
        parent::__construct($token, $options);
        $this->_groups = $groups;
    }

    /**
     * Internal method to prepare a message given the message data
     * @param $distinct_id
     * @param $operation
     * @param $value
     * @param null $ip
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found
     * @return array
     */
    private function _constructPayload($distinct_id, $operation, $value, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_requireSupportedOperation($operation);
        $payload = array(
            '$token' => $this->_token,
            '$distinct_id' => $distinct_id,
            '$time' => microtime(true),
            $operation => $value
        );
        if ($ip !== null) $payload['$ip'] = $ip;
        if ($ignore_time === true) $payload['$ignore_time'] = true;
        if ($ignore_alias === true) $payload['$ignore_alias'] = true;
        return $payload;
    }

    /**
     * Set properties on a user record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values, overwriting existing values.
     * @param string|int|MixpanelIdentity $distinct_id the distinct_id or alias of a user, or a MixpanelIdentity
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found
     */
    public function set($distinct_id, $props, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_enqueueProfileUpdate($distinct_id, '$set', $props, $ip, $ignore_time, $ignore_alias);
    }

    /**
     * Set properties on a user record. If the profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values but WILL NOT overwrite existing values.
     * @param string|int|MixpanelIdentity $distinct_id the distinct_id or alias of a user, or a MixpanelIdentity
     * @param array $props associative array of properties to set on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function setOnce($distinct_id, $props, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_enqueueProfileUpdate($distinct_id, '$set_once', $props, $ip, $ignore_time, $ignore_alias);
    }

    /**
     * Unset properties on a user record. If the profile does not exist, it creates it with no properties.
     * If it does exist, it unsets these properties. NOTE: In other libraries we use 'unset' which is
     * a reserved word in PHP.
     * @param string|int|MixpanelIdentity $distinct_id the distinct_id or alias of a user, or a MixpanelIdentity
     * @param array $props associative array of properties to unset on the profile
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function remove($distinct_id, $props, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_enqueueProfileUpdate($distinct_id, '$unset', $props, $ip, $ignore_time, $ignore_alias);
    }

    /**
     * Increments the value of a property on a user record. If the profile does not exist, it creates it and sets the
     * property to the increment value.
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param $prop string the property to increment
     * @param int $val the amount to increment the property by
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function increment($distinct_id, $prop, $val, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_requireDistinctId($distinct_id, "increment");
        $payload = $this->_constructPayload($distinct_id, '$add', array("$prop" => $val), $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Adds $val to a list located at $prop. If the property does not exist, it will be created. If $val is a string
     * and the list is empty or does not exist, a new list with one value will be created. An array of items is sent as
     * $union, which also accepts a MixpanelIdentity.
     * @param string|int|MixpanelIdentity $distinct_id the distinct_id or alias of a user, or a MixpanelIdentity when
     * $val is an array
     * @param string $prop the property that holds the list
     * @param string|array $val items to add to the list
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function append($distinct_id, $prop, $val, $ip = null, $ignore_time = false, $ignore_alias = false) {
        if (gettype($val) == "array") {
            $this->_enqueueProfileUpdate($distinct_id, '$union', array("$prop" => $val), $ip, $ignore_time, $ignore_alias);
            return;
        }
        $this->_requireDistinctId($distinct_id, "append");
        $payload = $this->_constructPayload($distinct_id, '$append', array("$prop" => $val), $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Adds a transaction to the user's profile for revenue tracking
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string $amount the transaction amount e.g. "20.50"
     * @param null $timestamp the timestamp of when the transaction occurred (default to current timestamp)
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function trackCharge($distinct_id, $amount, $timestamp = null, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_requireDistinctId($distinct_id, "trackCharge");
        $timestamp = $timestamp == null ? time() : $timestamp;
        $date_iso = date("c", $timestamp);
        $transaction = array(
            '$time' => $date_iso,
            '$amount' => $amount
        );
        $val = array('$transactions' => $transaction);
        $payload = $this->_constructPayload($distinct_id, '$append', $val, $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Clear all transactions stored on a user's profile
     * @param string|int $distinct_id the distinct_id or alias of a user
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function clearCharges($distinct_id, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_requireDistinctId($distinct_id, "clearCharges");
        if ($this->_isHierarchical()) {
            throw new BadMethodCallException("clearCharges is not supported for hierarchical groups projects, whose "
                . "user profiles are group profiles keyed by \$user_id and have no transactions.");
        }
        $payload = $this->_constructPayload($distinct_id, '$set', array('$transactions' => array()), $ip, $ignore_time, $ignore_alias);
        $this->enqueue($payload);
    }

    /**
     * Delete this profile from Mixpanel
     * @param string|int|MixpanelIdentity $distinct_id the distinct_id or alias of a user, or a MixpanelIdentity
     * @param string|null $ip the ip address of the client (used for geo-location)
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @param boolean $ignore_alias If the $ignore_alias property is true, an alias look up will not be performed after ingestion. Otherwise, a lookup for the distinct ID will be performed, and replaced if a match is found     
     */
    public function deleteUser($distinct_id, $ip = null, $ignore_time = false, $ignore_alias = false) {
        $this->_enqueueProfileUpdate($distinct_id, '$delete', "", $ip, $ignore_time, $ignore_alias);
    }

    /**
     * Queues a profile update addressed by a distinct id or a MixpanelIdentity.
     *
     * Without hierarchical groups, a distinct id (given directly or as an identity's distinct_id) is the ordinary
     * /engage update for that distinct id. On a hierarchical groups project nothing is sent to /engage: the update
     * is sent to /groups as the $user_id group profile within the user's company, taken either from the identity's
     * user_id and company_id or from a distinct id of the form $company:{company}|$user:{user}.
     * @param string|int|MixpanelIdentity $distinct_id
     * @param string $operation
     * @param mixed $value
     * @param string|null $ip
     * @param boolean $ignore_time
     * @param boolean $ignore_alias
     * @throws InvalidArgumentException if the distinct id or identity cannot name a user profile in this project
     * @throws LogicException if a hierarchical groups identity names no company
     */
    private function _enqueueProfileUpdate($distinct_id, $operation, $value, $ip, $ignore_time, $ignore_alias) {
        if ($distinct_id instanceof MixpanelIdentity) {
            $identity = $distinct_id;
            $this->_requireIdentityMatchesProject($identity);
            $distinct_id = $identity->getDistinctId();
        } else {
            $identity = null;
        }

        if ($identity === null || $distinct_id !== null) {
            if (!$this->_isHierarchical()) {
                $this->enqueue($this->_constructPayload($distinct_id, $operation, $value, $ip, $ignore_time,
                    $ignore_alias));
                return;
            }
            list($company_id, $user_id) = $this->_requireCombinedDistinctId($distinct_id);
            $this->_enqueueUserGroupUpdate($user_id, $company_id, $operation, $value, $ip, $ignore_time,
                $ignore_alias);
            return;
        }

        if (!$this->_isHierarchical()) {
            throw new InvalidArgumentException($identity . " cannot identify a profile on this project. User profiles "
                . "are addressed by distinct id unless the project uses hierarchical groups.");
        }
        if ($identity->getUserId() === null) {
            if ($identity->getCompanyId() === null) {
                throw new InvalidArgumentException($identity . " names no user, so it cannot identify a profile. "
                    . "Device ids identify anonymous events, not profiles.");
            }
            throw new InvalidArgumentException("User profile updates require a user id, and " . $identity
                . " has none. Use \$mp->forCompany(\$company_id)->group->set('\$company_id', \$company_id, \$props) "
                . "to update the company's profile.");
        }
        if ($identity->getCompanyId() === null) {
            throw new LogicException("User profile updates for hierarchical groups projects require a company, and "
                . $identity . " has none. Add company_id to the identity.");
        }
        $this->_enqueueUserGroupUpdate($identity->getUserId(), $identity->getCompanyId(), $operation, $value, $ip,
            $ignore_time, $ignore_alias);
    }

    /**
     * Queues a hierarchical groups user profile update on /groups as the $user_id group profile within $company_id.
     * @param string|int $user_id
     * @param string|int $company_id
     * @param string $operation
     * @param mixed $value
     * @param string|null $ip
     * @param boolean $ignore_time
     * @param boolean $ignore_alias
     */
    private function _enqueueUserGroupUpdate($user_id, $company_id, $operation, $value, $ip, $ignore_time,
                                             $ignore_alias) {
        $this->_requireSupportedOperation($operation);
        $payload = array(
            '$token' => $this->_token,
            '$group_key' => '$user_id',
            '$group_id' => $user_id,
            '$time' => microtime(true),
            $operation => $value
        );
        if ($ip !== null) $payload['$ip'] = $ip;
        if ($ignore_time === true) $payload['$ignore_time'] = true;
        if ($ignore_alias === true) $payload['$ignore_alias'] = true;
        // $company_id must be top-level, not inside the operation payload
        $payload['$company_id'] = $company_id;
        $this->getGroupsProducer()->enqueue($payload);
    }

    /**
     * Splits a distinct id of the form $company:{company}|$user:{user} into its company and user. The company is
     * the text before the first "|$user:" and the user is everything after it.
     * @param mixed $distinct_id
     * @return array array($company_id, $user_id), or null unless both parts are present and non-empty
     */
    private static function _parseCombinedDistinctId($distinct_id) {
        $prefix = '$company:';
        $separator = '|$user:';
        if (!is_string($distinct_id) || strpos($distinct_id, $prefix) !== 0) {
            return null;
        }
        $rest = substr($distinct_id, strlen($prefix));
        $at = strpos($rest, $separator);
        if ($at === false) {
            return null;
        }
        $company_id = substr($rest, 0, $at);
        $user_id = (string) substr($rest, $at + strlen($separator));
        if ($company_id === "" || $user_id === "") {
            return null;
        }
        return array($company_id, $user_id);
    }

    /**
     * @param mixed $distinct_id
     * @return array array($company_id, $user_id)
     * @throws InvalidArgumentException unless $distinct_id has the form $company:{company}|$user:{user}
     */
    private function _requireCombinedDistinctId($distinct_id) {
        $parts = self::_parseCombinedDistinctId($distinct_id);
        if ($parts === null) {
            throw new InvalidArgumentException("User profile updates on hierarchical groups projects need a user and "
                . "a company, and distinct id '" . $distinct_id . "' does not name both. Pass "
                . "new MixpanelIdentity(array('user_id' => \$user_id, 'company_id' => \$company_id)) or a distinct id "
                . "of the form \$company:{company}|\$user:{user}.");
        }
        return $parts;
    }

    /**
     * A hierarchical groups user profile is a group profile, so it supports what /groups supports.
     * @param string $operation
     * @throws BadMethodCallException for an operation /groups has no equivalent of, on a hierarchical groups project
     */
    private function _requireSupportedOperation($operation) {
        if ($this->_isHierarchical() && !in_array($operation, self::$_group_operations, true)) {
            throw new BadMethodCallException($operation . " is not supported for hierarchical groups projects, whose "
                . "user profiles are group profiles keyed by \$user_id. Supported operations are "
                . implode(", ", self::$_group_operations) . ", so increment, append (of a single value) and "
                . "trackCharge have no hierarchical groups equivalent.");
        }
    }

    /**
     * @param mixed $distinct_id
     * @param string $method
     * @throws InvalidArgumentException if $distinct_id is a MixpanelIdentity
     */
    private function _requireDistinctId($distinct_id, $method) {
        if ($distinct_id instanceof MixpanelIdentity) {
            throw new InvalidArgumentException($method . " does not accept a MixpanelIdentity; pass a distinct id. "
                . "set, setOnce, remove, deleteUser and append of an array accept an identity.");
        }
    }

    /**
     * Returns the producer identity-based profile updates on a hierarchical groups project are queued on
     * @return Producers_MixpanelGroups
     */
    public function getGroupsProducer() {
        if ($this->_groups === null) {
            $this->_groups = new Producers_MixpanelGroups($this->_token, $this->_options);
        }
        return $this->_groups;
    }

    /**
     * Returns the "engage" endpoint
     * @return string
     */
    function _getEndpoint() {
        return $this->_options['people_endpoint'];
    }

}
