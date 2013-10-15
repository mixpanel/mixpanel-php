<?php
require_once(dirname(__FILE__) . "/MixpanelBaseProducer.php");
require_once(dirname(__FILE__) . "/MixpanelPeople.php");
require_once(dirname(__FILE__) . "/../ConsumerStrategies/CurlConsumer.php");

/**
 * Provides an API to track events on Mixpanel
 */
class Producers_MixpanelEvents extends Producers_MixpanelBaseProducer {

    /**
     * An array of properties to attach to every tracked event
     * @var array
     */
    private $_super_properties = array("mp_lib" => "php");


    /**
     * Track an event defined by $event associated with metadata defined by $properties
     * @param string $event
     * @param array $properties
     */
    public function track($event, $properties = array()) {

        // if no token is passed in, use current token
        if (!array_key_exists("token", $properties)) $properties['token'] = $this->_token;

        // if no time is passed in, use the current time
        if (!array_key_exists('time', $properties)) $properties['time'] = time();

        $params['event'] = $event;
        $params['properties'] = array_merge($this->_super_properties, $properties);

        $this->enqueue($params);
    }


    /**
     * Register a property to be sent with every event. If the property has already been registered, it will be
     * overwritten.
     * @param string $property
     * @param mixed $value
     */
    public function register($property, $value) {
        $this->_super_properties[$property] = $value;
    }


    /**
     * Register multiple properties to be sent with every event. If any of the properties have already been registered,
     * they will be overwritten.
     * @param array $props_and_vals
     */
    public function registerAll($props_and_vals = array()) {
        foreach($props_and_vals as $property => $value) {
            $this->register($property, $value);
        }
    }


    /**
     * Register a property to be sent with every event. If the property has already been registered, it will NOT be
     * overwritten.
     * @param $property
     * @param $value
     */
    public function registerOnce($property, $value) {
        if (!isset($this->_super_properties[$property])) {
            $this->register($property, $value);
        }
    }


    /**
     * Register multiple properties to be sent with every event. If any of the properties have already been registered,
     * they will NOT be overwritten.
     * @param array $props_and_vals
     */
    public function registerAllOnce($props_and_vals = array()) {
        foreach($props_and_vals as $property => $value) {
            if (!isset($this->_super_properties[$property])) {
                $this->register($property, $value);
            }
        }
    }


    /**
     * Un-register an property to be sent with every event.
     * @param string $property
     */
    public function unregister($property) {
        unset($this->_super_properties[$property]);
    }


    /**
     * Un-register a list of properties to be sent with every event.
     * @param array $properties
     */
    public function unregisterAll($properties) {
        foreach($properties as $property) {
            $this->unregister($property);
        }
    }


    /**
     * Get a property that is set to be sent with every event
     * @param string $property
     * @return mixed
     */
    public function getProperty($property) {
        return $this->_super_properties[$property];
    }


    /**
     * Identify the user you want to associate to tracked events
     * @param string|int $user_id
     */
    public function identify($user_id) {
        $this->register("distinct_id", $user_id);
    }


    /**
     * Alias an existing id with a different unique id. This is helpful when you want to associate a generated id to
     * a username or e-mail address.
     *
     * Because aliasing can be extremely vulnerable to race conditions and ordering issues, we'll make a synchronous
     * call directly to Mixpanel when this method is called. If it fails we'll throw an Exception as subsequent
     * events are likely to be incorrectly tracked.
     * @param string|int $original_id
     * @param string|int $new_id
     * @return array $msg
     * @throws Exception
     */
    public function createAlias($original_id, $new_id) {
        $msg = array(
            "event"         => '$create_alias',
            "properties"    =>  array("distinct_id" => $original_id, "alias" => $new_id, "token" => $this->_token)
        );

        $options = array_merge($this->_options, array("endpoint" => $this->_getEndpoint(), "fork" => false));
        $curlConsumer = new ConsumerStrategies_CurlConsumer($options);
        $success = $curlConsumer->persist(array($msg));
        if (!$success) {
            error_log("Creating Mixpanel Alias (original id: $original_id, new id: $new_id) failed");
            throw new Exception("Tried to create an alias but the call was not successful");
        } else {
            return $msg;
        }
    }


    /**
     * Returns the "events" endpoint
     * @return string
     */
    function _getEndpoint() {
        return $this->_options['events_endpoint'];
    }
}