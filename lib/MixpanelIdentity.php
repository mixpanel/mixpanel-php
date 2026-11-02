<?php

/**
 * Identifies who an event or profile update belongs to.
 *
 * Mixpanel resolves an identity from whichever of distinct_id, $user_id, $company_id and $device_id an event
 * carries, so an identity sets any combination of them and leaves the rest unset:
 *
 * new MixpanelIdentity(array('distinct_id' => 'user_123'));                          // a plain distinct id
 * new MixpanelIdentity(array('user_id' => 'user_123', 'device_id' => 'device_abc')); // simplified id merge
 * new MixpanelIdentity(array('user_id' => 'user_123', 'company_id' => 'acme'));      // hierarchical groups
 * new MixpanelIdentity(array('device_id' => 'device_abc'));                         // anonymous, no user yet
 * new MixpanelIdentity(array('company_id' => 'acme'));                              // a company, no user
 *
 * Which combinations are valid depends on the project, so the Mixpanel client rather than this class decides.
 * On a hierarchical groups project the company is part of the user's identity: a $user_id sent without a
 * $company_id resolves to a different user that nothing reconciles later, so the client rejects it. A user who
 * belongs to two companies is two users, by design.
 *
 * Instances are immutable.
 */
class MixpanelIdentity {

    /**
     * Maps each accepted constructor key to the property it is sent as
     * @var array
     */
    private static $_properties = array(
        'distinct_id' => 'distinct_id',
        'user_id'     => '$user_id',
        'company_id'  => '$company_id',
        'device_id'   => '$device_id'
    );

    private $_distinct_id = null;
    private $_user_id = null;
    private $_company_id = null;
    private $_device_id = null;


    /**
     * Creates an identity from any of distinct_id, user_id, company_id and device_id.
     *
     * A null value leaves the key unset. An empty string is a caller error rather than an absent value.
     *
     * distinct_id: on a hierarchical groups project Mixpanel builds the distinct id from the user and company, so a
     * raw distinct id names a different, unrelated user that cannot be merged afterwards. Set one there only when
     * it is one Mixpanel itself built, of the form $company:{company}|$user:{user}.
     * user_id: the user's id. On a hierarchical groups project it only needs to be unique within the company.
     * company_id: the id of the top-level company, on a hierarchical groups project.
     * device_id: the client's device id. Events carrying one are attributed to the device until a later event
     * names the user, at which point that earlier activity follows the user.
     *
     * @param array $ids associative array with any of the keys distinct_id, user_id, company_id, device_id
     * @throws InvalidArgumentException if a key is unknown, a value is empty or not a string or int, or no value is set
     */
    public function __construct($ids = array()) {
        if (!is_array($ids)) {
            throw new InvalidArgumentException("MixpanelIdentity takes an array with any of the keys "
                . implode(", ", array_keys(self::$_properties)));
        }
        foreach ($ids as $key => $value) {
            if (!array_key_exists($key, self::$_properties)) {
                throw new InvalidArgumentException("Unknown MixpanelIdentity key '$key'; expected any of "
                    . implode(", ", array_keys(self::$_properties)));
            }
            if ($value === null) {
                continue;
            }
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException("$key must be a string or an int, " . gettype($value) . " given");
            }
            if ($value === "") {
                throw new InvalidArgumentException("$key must be non-empty");
            }
            $field = "_" . $key;
            $this->$field = $value;
        }
        if ($this->_distinct_id === null && $this->_user_id === null && $this->_company_id === null
                && $this->_device_id === null) {
            throw new InvalidArgumentException("An identity needs at least one of distinct_id, user_id, company_id "
                . "or device_id");
        }
    }


    /**
     * @return string|int|null
     */
    public function getDistinctId() {
        return $this->_distinct_id;
    }


    /**
     * @return string|int|null
     */
    public function getUserId() {
        return $this->_user_id;
    }


    /**
     * @return string|int|null
     */
    public function getCompanyId() {
        return $this->_company_id;
    }


    /**
     * @return string|int|null
     */
    public function getDeviceId() {
        return $this->_device_id;
    }


    /**
     * @return bool true if this identity names a company, which is what sends it down Mixpanel's hierarchical
     * groups resolution
     */
    public function isHierarchical() {
        return $this->_company_id !== null;
    }


    /**
     * Returns the event properties this identity sets (distinct_id, $user_id, $company_id, $device_id), omitting
     * unset keys.
     * @return array
     */
    public function toProperties() {
        $properties = array();
        foreach (self::$_properties as $key => $property) {
            $field = "_" . $key;
            if ($this->$field !== null) {
                $properties[$property] = $this->$field;
            }
        }
        return $properties;
    }


    /**
     * Returns every property an identity can set, whether or not this one sets it.
     * @return array
     */
    public static function propertyNames() {
        return array_values(self::$_properties);
    }


    /**
     * A readable form for error messages, e.g. MixpanelIdentity{user_id=user_123, company_id=acme}
     * @return string
     */
    public function __toString() {
        $parts = array();
        foreach (array_keys(self::$_properties) as $key) {
            $field = "_" . $key;
            if ($this->$field !== null) {
                $parts[] = $key . "=" . $this->$field;
            }
        }
        return "MixpanelIdentity{" . implode(", ", $parts) . "}";
    }
}
