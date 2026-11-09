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
 * An identity with a user id but no company uses the company a client bound with Mixpanel::forCompany() is bound to.
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
     * The company a client bound with Mixpanel::forCompany() is bound to, or null
     * @var string|int|null
     */
    private $_boundCompanyId = null;


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
        if (empty($props)) {
            $this->_validateProfileIdForEmptyUpdate($distinct_id);
            return;
        }
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
        if (empty($props)) {
            $this->_validateProfileIdForEmptyUpdate($distinct_id);
            return;
        }
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
        if (empty($props)) {
            $this->_validateProfileIdForEmptyUpdate($distinct_id);
            return;
        }
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
     * set, setOnce and remove send nothing for empty properties. A MixpanelIdentity, or any id on a hierarchical
     * groups project, is still validated first, so a call that could never be sent throws as it would with
     * properties. A plain distinct id without hierarchical groups returns silently, as it always has.
     * @param string|int|MixpanelIdentity $distinct_id
     * @throws InvalidArgumentException|LogicException as _resolveProfileTarget()
     */
    private function _validateProfileIdForEmptyUpdate($distinct_id) {
        if ($distinct_id instanceof MixpanelIdentity || $this->_isHierarchical()) {
            $this->_resolveProfileTarget($distinct_id);
        }
    }

    /**
     * Queues a profile update addressed by a distinct id or a MixpanelIdentity.
     *
     * Without hierarchical groups, a distinct id (given directly or as an identity's distinct_id) is the ordinary
     * /engage update for that distinct id. On a hierarchical groups project nothing is sent to /engage: the update
     * is sent to /groups as the $user_id group profile within the user's company, taken either from the identity's
     * user_id and company_id or from a distinct id of the form $company:{company}|$user:{user}. An identity with a
     * user id but no company uses the company a client bound with Mixpanel::forCompany() is bound to.
     * @param string|int|MixpanelIdentity $distinct_id
     * @param string $operation
     * @param mixed $value
     * @param string|null $ip
     * @param boolean $ignore_time
     * @param boolean $ignore_alias
     * @throws InvalidArgumentException if the distinct id or identity cannot name a user profile in this project
     * @throws LogicException if a hierarchical groups identity names no company and the client is not bound to one
     */
    private function _enqueueProfileUpdate($distinct_id, $operation, $value, $ip, $ignore_time, $ignore_alias) {
        $target = $this->_resolveProfileTarget($distinct_id);
        if (array_key_exists('distinct_id', $target)) {
            $this->enqueue($this->_constructPayload($target['distinct_id'], $operation, $value, $ip, $ignore_time,
                $ignore_alias));
            return;
        }
        $this->_enqueueUserGroupUpdate($target['user_id'], $target['company_id'], $operation, $value, $ip,
            $ignore_time, $ignore_alias);
    }

    /**
     * Resolves which profile a distinct id or MixpanelIdentity names, without queueing anything.
     * @param string|int|MixpanelIdentity $distinct_id
     * @return array array('distinct_id' => ...) for an /engage update, or array('user_id' => ..., 'company_id' => ...)
     * for a hierarchical groups user profile
     * @throws InvalidArgumentException if the distinct id or identity cannot name a user profile in this project, or
     * names a user from a company other than the bound one
     * @throws LogicException if a hierarchical groups identity names no company and the client is not bound to one
     */
    private function _resolveProfileTarget($distinct_id) {
        if ($distinct_id instanceof MixpanelIdentity) {
            $identity = $distinct_id;
            $this->_requireIdentityMatchesProject($identity);
            $distinct_id = $identity->getDistinctId();
        } else {
            $identity = null;
        }

        if ($identity === null || $distinct_id !== null) {
            if (!$this->_isHierarchical()) {
                return array('distinct_id' => $distinct_id);
            }
            list($company_id, $user_id) = $this->_requireCombinedDistinctId($distinct_id);
            $this->_requireBoundCompany($user_id, $company_id);
            return array('user_id' => $user_id, 'company_id' => $company_id);
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
                . " has none. Use \$mp->forCompany(\$company_id)->company->set(\$props) to update the company's "
                . "profile.");
        }
        // The identity's own company; without one, the company a bound client is bound to
        $company_id = $identity->getCompanyId() !== null ? $identity->getCompanyId() : $this->_boundCompanyId;
        if ($company_id === null) {
            throw new LogicException("User profile updates for hierarchical groups projects require a company, and "
                . $identity . " has none. Add company_id to the identity, or send through \$mp->forCompany(\$company_id).");
        }
        $this->_requireBoundCompany($identity->getUserId(), $company_id);
        return array('user_id' => $identity->getUserId(), 'company_id' => $company_id);
    }

    /**
     * The company is part of the user's identity, so a bound client only checks that it matches. Ids may be strings
     * or integers, so they are compared as strings.
     * @param string|int $user_id
     * @param string|int $company_id
     * @throws InvalidArgumentException if the client is bound to a different company
     */
    private function _requireBoundCompany($user_id, $company_id) {
        if ($this->_boundCompanyId !== null && (string) $company_id !== (string) $this->_boundCompanyId) {
            throw new InvalidArgumentException("This client is bound to company \"" . $this->_boundCompanyId
                . "\", but user \"" . $user_id . "\" belongs to company \"" . $company_id . "\". Update the user "
                . "through a client for that company, or one without forCompany.");
        }
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
     * Returns a people producer that refuses user profile updates for any company other than $company_id. It queues
     * on the same groups producer; this producer is unchanged. Mixpanel::forCompany() uses it on hierarchical groups
     * projects, where every user profile update goes to that groups producer.
     * @internal Use Mixpanel::forCompany().
     * @param string|int $company_id
     * @return Producers_MixpanelPeople
     */
    public function forCompany($company_id) {
        $view = clone $this;
        // the clone copied this producer's own queue; reset() would empty the shared groups queue
        $view->_resetOwnQueue();
        $view->_boundCompanyId = $company_id;
        return $view;
    }

    /**
     * Returns the producer identity-based profile updates on a hierarchical groups project are queued on
     * @internal Used by this producer's identity-based profile updates.
     * @return Producers_MixpanelGroups
     */
    public function getGroupsProducer() {
        if ($this->_groups === null) {
            $this->_groups = new Producers_MixpanelGroups($this->_token, $this->_options);
        }
        return $this->_groups;
    }

    /**
     * Flush the queue. On a hierarchical groups project user profile updates are queued on the groups producer, so
     * this also flushes that queue; messages added with enqueue() stay on this producer's own queue and are sent as
     * given.
     * @param int $desired_batch_size
     * @return bool whether or not the flush was successful
     */
    public function flush($desired_batch_size = 50) {
        if ($this->_isHierarchical()) {
            $succeeded = parent::flush($desired_batch_size);
            return $this->getGroupsProducer()->flush($desired_batch_size) && $succeeded;
        }
        return parent::flush($desired_batch_size);
    }

    /**
     * Empties the queue without persisting any of the messages. On a hierarchical groups project this also empties
     * the groups producer's queue, where user profile updates are queued.
     */
    public function reset() {
        if ($this->_isHierarchical()) {
            $this->getGroupsProducer()->reset();
        }
        parent::reset();
    }

    /**
     * Returns the in-memory queue. On a hierarchical groups project this is any messages added with enqueue()
     * followed by the groups producer's queue, where user profile updates are queued.
     * @return array
     */
    public function getQueue() {
        if ($this->_isHierarchical()) {
            return array_merge(parent::getQueue(), $this->getGroupsProducer()->getQueue());
        }
        return parent::getQueue();
    }

    /**
     * On a hierarchical groups project user profile updates are queued on the groups producer, which flushes them
     * when it is destructed; this producer flushes only messages added with enqueue()
     */
    public function __destruct() {
        if (!$this->_isHierarchical()) {
            parent::__destruct();
            return;
        }
        $attempts = 0;
        while (!parent::flush() && ++$attempts < 10) {
        }
    }

    /**
     * Empties this producer's own queue, never the groups producer's
     */
    private function _resetOwnQueue() {
        parent::reset();
    }

    /**
     * Returns the "engage" endpoint
     * @return string
     */
    function _getEndpoint() {
        return $this->_options['people_endpoint'];
    }

}
