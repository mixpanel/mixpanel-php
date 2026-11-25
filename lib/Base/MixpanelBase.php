<?php
require_once(dirname(__FILE__) . "/../MixpanelIdentity.php");

/**
 * This a Base class which all Mixpanel classes extend from to provide some very basic
 * debugging and logging functionality. It also serves to persist $_options across the library.
 *
 */
class Base_MixpanelBase {


    /**
     * Default options that can be overridden via the $options constructor arg
     * @var array
     */
    private $_defaults = array(
        "max_batch_size"    => 50, // the max batch size Mixpanel will accept is 50,
        "max_queue_size"    => 1000, // the max num of items to hold in memory before flushing
        "debug"             => false, // enable/disable debug mode
        "consumer"          => "curl", // which consumer to use
        "host"              => "api.mixpanel.com", // the host name for api calls
        "events_endpoint"   => "/track", // host relative endpoint for events
        "people_endpoint"   => "/engage", // host relative endpoint for people updates
        "groups_endpoint"   => "/groups", // host relative endpoint for groups updates
        "use_ssl"           => true, // use ssl when available
        "error_callback"    => null // callback to use on consumption failures
    );


    /**
     * An array of options to be used by the Mixpanel library.
     * @var array
     */
    protected $_options = array();


    /**
     * Construct a new MixpanelBase object and merge custom options with defaults
     * @param array $options
     */
    public function __construct($options = array()) {
        $options = array_merge($this->_defaults, $options);
        // A value such as 1 or "true" would otherwise quietly leave hierarchical groups off
        if (isset($options["hierarchical_groups"]) && !is_bool($options["hierarchical_groups"])) {
            throw new InvalidArgumentException("hierarchical_groups must be true or false, "
                . gettype($options["hierarchical_groups"]) . " given");
        }
        $this->_options = $options;
    }


    /**
     * Log a message to PHP's error log
     * @param $msg
     */
    protected function _log($msg) {
        $arr = debug_backtrace();
        $class = $arr[0]['class'];
        $line = $arr[0]['line'];
        error_log ( "[ $class - line $line ] : " . $msg );
    }


    /**
     * Returns true if in debug mode, false if in production mode
     * @return bool
     */
    protected function _debug() {
        return isset($this->_options["debug"]) && $this->_options["debug"] == true;
    }


    /**
     * Returns true if this client was created for a hierarchical groups project. This option, set when the client
     * is created, is the only switch for hierarchical groups behaviour: it is never inferred from the data.
     * @return bool
     */
    protected function _isHierarchical() {
        return isset($this->_options["hierarchical_groups"]) && $this->_options["hierarchical_groups"] === true;
    }


    /**
     * Hierarchical groups is a project setting, so a hierarchical identity is only valid on a client created for a
     * hierarchical groups project. Ingestion accepts the keys on any project but ignores them, so a mismatch would
     * be silently mis-attributed.
     * On a hierarchical groups project the identity's user_id and company_id must not contain "|", which
     * Mixpanel cannot store or split out of a combined distinct id.
     * @param MixpanelIdentity $identity
     * @throws InvalidArgumentException if the identity does not fit this project
     */
    protected function _requireIdentityMatchesProject($identity) {
        if (!($identity instanceof MixpanelIdentity)) {
            throw new InvalidArgumentException("identity must be a MixpanelIdentity, "
                . (is_object($identity) ? get_class($identity) : gettype($identity)) . " given");
        }
        if ($identity->isHierarchical() && !$this->_isHierarchical()) {
            throw new InvalidArgumentException($identity . " requires a hierarchical groups client: "
                . "new Mixpanel(\$token, array('hierarchical_groups' => true))");
        }
        if ($this->_isHierarchical()) {
            self::_requireNoSeparator('user_id', $identity->getUserId());
            self::_requireNoSeparator('company_id', $identity->getCompanyId());
        }
    }


    /**
     * Hierarchical groups user and company ids must not contain "|": Mixpanel drops such a profile update, and a
     * combined distinct id ($company:{company}|$user:{user}) built from one cannot be split.
     * @param string $name what the message calls the id, e.g. 'company_id'
     * @param mixed $value the id, or null if absent
     * @throws InvalidArgumentException if $value is a string containing "|"
     */
    protected static function _requireNoSeparator($name, $value) {
        if (is_string($value) && strpos($value, "|") !== false) {
            throw new InvalidArgumentException($name . " " . $value . " contains \"|\", which hierarchical groups "
                . "projects do not allow in user or company ids. Nothing sent");
        }
    }


    /**
     * Splits a distinct id of the form $company:{company}|$user:{user} into its company and user. The company is
     * the text before the first "|$user:" and the user is everything after it.
     * @param mixed $distinct_id
     * @return array array($company_id, $user_id), or null unless $distinct_id starts with $company: and
     * contains |$user:
     * @throws InvalidArgumentException if the company or the user is empty or only whitespace, or contains "|"
     */
    protected static function _parseCombinedDistinctId($distinct_id) {
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
        if ($company_id === "" || $user_id === "" || MixpanelIdentity::isBlank($company_id)
                || MixpanelIdentity::isBlank($user_id)) {
            throw new InvalidArgumentException("distinct_id " . $distinct_id . " names a company or user that is "
                . "empty or only whitespace. Nothing sent");
        }
        self::_requireNoSeparator('company_id', $company_id);
        self::_requireNoSeparator('user_id', $user_id);
        return array($company_id, $user_id);
    }


    /**
     * Refuses a combined distinct id ($company:{company}|$user:{user}) whose company or user differs from the
     * value given for it. Ids are compared as strings; a value that is null or absent is not compared.
     * @param mixed $distinct_id
     * @param string $company_name what the message calls the company value, e.g. '$company_id'
     * @param mixed $company_value
     * @param string $user_name what the message calls the user value, e.g. '$user_id'
     * @param mixed $user_value
     * @throws InvalidArgumentException if the distinct id names a company or user that is empty or only whitespace,
     * or one that differs from the given value
     */
    protected static function _requireCombinedDistinctIdAgrees($distinct_id, $company_name, $company_value,
                                                               $user_name, $user_value) {
        $parts = self::_parseCombinedDistinctId($distinct_id);
        if ($parts === null) {
            return;
        }
        list($company_id, $user_id) = $parts;
        $checks = array(array($company_name, 'company', $company_id, $company_value),
            array($user_name, 'user', $user_id, $user_value));
        foreach ($checks as $check) {
            list($field, $name, $expected, $value) = $check;
            if ($value === null) {
                continue;
            }
            $actual = is_scalar($value) ? (string) $value : json_encode($value);
            if (!is_scalar($value) || $actual !== $expected) {
                throw new InvalidArgumentException("distinct_id " . $distinct_id . " names " . $name . " "
                    . $expected . ", but " . $field . " is " . $actual . ". Nothing sent");
            }
        }
    }


    /**
     * On a hierarchical groups project, refuses an identity whose distinct_id is combined
     * ($company:{company}|$user:{user}) but names a company or user that is empty or only whitespace, or one that
     * differs from the identity's own company_id or user_id. Events resolve such an identity by user_id and
     * company_id while profile updates would follow the distinct_id, so neither is chosen.
     * @param MixpanelIdentity $identity
     * @throws InvalidArgumentException if the combined distinct_id has an empty or whitespace-only part, or
     * disagrees with the identity's company_id or user_id
     */
    protected function _requireIdentityDistinctIdAgrees($identity) {
        if ($this->_isHierarchical()) {
            self::_requireCombinedDistinctIdAgrees($identity->getDistinctId(), 'company_id',
                $identity->getCompanyId(), 'user_id', $identity->getUserId());
        }
    }

}
