<?php
require_once(dirname(__FILE__) . "/MixpanelBaseProducer.php");

/**
 * Provides an API to create/update group profiles on Mixpanel
 */
class Producers_MixpanelGroups extends Producers_MixpanelBaseProducer {

    /**
     * The company bound by forCompany(), added as a top-level $company_id on every message
     * @var string|int|null
     */
    private $_company_id = null;


    /**
     * The producer whose queue a forCompany() view writes to, or null if this producer owns its queue
     * @var Producers_MixpanelGroups|null
     */
    private $_root = null;


    /**
     * Returns a view of this producer that adds a top-level $company_id to every group profile message it sends.
     * Hierarchical groups projects require $company_id on every group profile update, including user profiles. A
     * company's own profile ($group_key $company_id) takes its company from its group id, so it needs no view:
     *
     * $mp->group->set('$company_id', "acme", $company_properties);
     *
     * $acme = $mp->group->forCompany("acme");
     * $acme->set('$user_id', "user_123", $user_properties);
     * $acme->set("department", "finance", $department_properties);
     *
     * The view shares this producer's queue and consumer, so its messages are flushed with this producer's; this
     * producer is unchanged. Available on any project, and harmless on one that does not use hierarchical groups.
     * @param string|int $company_id the id of the top-level company
     * @return Producers_MixpanelGroups
     * @throws InvalidArgumentException if $company_id is empty
     */
    public function forCompany($company_id) {
        if ((!is_string($company_id) && !is_int($company_id)) || $company_id === "") {
            throw new InvalidArgumentException("company_id must be a non-empty string or int");
        }
        $view = clone $this;
        $view->_bind($this->_root === null ? $this : $this->_root, $company_id);
        return $view;
    }


    /**
     * Turns a fresh clone into a view writing to $root's queue
     * @param Producers_MixpanelGroups $root
     * @param string|int $company_id
     */
    private function _bind($root, $company_id) {
        // the clone copied the queue; those messages belong to the producer it was cloned from
        parent::reset();
        $this->_root = $root;
        $this->_company_id = $company_id;
    }


    /**
     * Returns the company bound by forCompany(), or null
     * @return string|int|null
     */
    public function getCompanyId() {
        return $this->_company_id;
    }


    /**
     * Internal method to prepare a message given the message data
     * @param $group_key
     * @param $group_id
     * @param $operation
     * @param $value
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the group Profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @return array
     * @throws InvalidArgumentException for hierarchical groups projects when a company's own profile is sent with a
     * different bound company
     * @throws LogicException for hierarchical groups projects when no company is bound and the profile is not a
     * company's own profile
     */
    private function _constructPayload($group_key, $group_id, $operation, $value, $ignore_time = false) {
        $company_id = $this->_company_id;
        if ($this->_isHierarchical()) {
            $company_id = $this->_hierarchicalCompanyId($group_key, $group_id);
        }
        $payload = array(
            '$token' => $this->_token,
            '$group_key' => $group_key,
            '$group_id' => $group_id,
            '$time' => microtime(true),
            $operation => $value
        );
        if ($ignore_time === true) $payload['$ignore_time'] = true;
        // $company_id must be top-level, not inside the operation payload
        if ($company_id !== null) $payload['$company_id'] = $company_id;
        return $payload;
    }

    /**
     * The company a hierarchical groups update belongs to: the bound company or, for a company's own profile, its
     * group id
     * @param $group_key
     * @param $group_id
     * @return string|int
     * @throws InvalidArgumentException if a company's own profile is sent with a different bound company
     * @throws LogicException if no company is bound and the profile is not a company's own profile
     */
    private function _hierarchicalCompanyId($group_key, $group_id) {
        $company_id = $this->_company_id;
        if ($group_key === '$company_id') {
            if ($company_id === null) {
                if ($group_id !== null && $group_id !== "") $company_id = $group_id;
            } elseif ($company_id !== $group_id) {
                throw new InvalidArgumentException("A company profile's company is its own id, so company profile "
                    . var_export($group_id, true) . " cannot be sent with company " . var_export($company_id, true)
                    . ". Nothing to send.");
            }
        }
        if ($company_id === null) {
            throw new LogicException("Group profile updates for hierarchical groups projects require a company. "
                . "Use forCompany(\$company_id) to send them, e.g. \$mp->forCompany(\$company_id)->group->set(...). "
                . "Nothing to send for group $group_key=$group_id");
        }
        return $company_id;
    }

    /**
     * Set properties on a group profile. If the group profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values, overwriting existing values.
     * @param string|int $group_key the group_key used for groups in Project Settings
     * @param string|int $group_id the group id used for the group profile
     * @param array $props associative array of properties to set on the group profile
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the group profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function set($group_key, $group_id, $props, $ignore_time = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$set', $props, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Set properties on a group profile. If the Group profile does not exist, it creates it with these properties.
     * If it does exist, it sets the properties to these values but WILL NOT overwrite existing values.
     * @param string|int $group_key the group_key used for groups in Project Settings
     * @param string|int $group_id the group id used for the group profile
     * @param array $props associative array of properties to set on the group profile
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the group profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function setOnce($group_key, $group_id, $props, $ignore_time = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$set_once', $props, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Unset properties on a group profile. If the group does not exist, it creates it with no properties.
     * If it does exist, it unsets these properties. NOTE: In other libraries we use 'unset' which is
     * a reserved word in PHP.
     * @param string|int $group_key the group_key used for groups in Project Settings
     * @param string|int $group_id the group id used for the group profile
     * @param array $props associative array of properties to unset on the group profile
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the group profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function remove($group_key, $group_id, $props, $ignore_time = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$remove', $props, $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Adds $val to a list located at $prop. If the property does not exist, it will be created. If $val is a string
     * and the list is empty or does not exist, a new list with one value will be created.
     * @param string|int $group_key the group_key used for groups in Project Settings
     * @param string|int $group_id the group id used for the group profile
     * @param string $prop the property that holds the list
     * @param string|array $val items to add to the list
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the group profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function union($group_key, $group_id, $prop, $val, $ignore_time = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$union', array("$prop" => $val), $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Delete this group profile from Mixpanel
     * @param string|int $group_key the group_key used for groups in Project Settings
     * @param string|int $group_id the group id used for the group profile
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     */
    public function deleteGroup($group_key, $group_id, $ignore_time = false) {
        $payload = $this->_constructPayload($group_key, $group_id, '$delete', "", $ignore_time);
        $this->enqueue($payload);
    }

    /**
     * Add an array representing a message to be sent to Mixpanel to the queue (the bound producer's queue for a
     * forCompany() view).
     * @param array $message
     */
    public function enqueue($message = array()) {
        if ($this->_root !== null) {
            $this->_root->enqueue($message);
        } else {
            parent::enqueue($message);
        }
    }


    /**
     * Flush the queue (the bound producer's queue for a forCompany() view)
     * @param int $desired_batch_size
     * @return bool whether or not the flush was successful
     */
    public function flush($desired_batch_size = 50) {
        if ($this->_root !== null) {
            return $this->_root->flush($desired_batch_size);
        }
        return parent::flush($desired_batch_size);
    }


    /**
     * Empties the queue (the bound producer's queue for a forCompany() view) without persisting any of the messages
     */
    public function reset() {
        if ($this->_root !== null) {
            $this->_root->reset();
        } else {
            parent::reset();
        }
    }


    /**
     * Returns the in-memory queue (the bound producer's queue for a forCompany() view)
     * @return array
     */
    public function getQueue() {
        if ($this->_root !== null) {
            return $this->_root->getQueue();
        }
        return parent::getQueue();
    }


    /**
     * A forCompany() view leaves flushing to the producer that owns the queue, which outlives it
     */
    public function __destruct() {
        if ($this->_root === null) {
            parent::__destruct();
        }
    }


    /**
     * Returns the "groups" endpoint
     * @return string
     */
    function _getEndpoint() {
        return $this->_options['groups_endpoint'];
    }

}
