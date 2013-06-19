<?php
require_once(dirname(__FILE__) . "/MixpanelBase.php");
require_once(dirname(__FILE__) . "/MixpanelPeopleProducer.php");
require_once(dirname(__FILE__) . "/MixpanelEventsProducer.php");

class Mixpanel extends MixpanelBase {

    /**
     * @var Mixpanel an instance of the Mixpanel class (for singleton use)
     */
    private static $_instance;


    /**
     * @var MixpanelPeopleProducer
     */
    public $people;


    /**
     * @var MixpanelEventsProducer
     */
    public $_events;


    /**
     * @param $token
     * @param array $options
     */
    public function __construct($token, $options) {
        parent::__construct($options);
        $this->people = new MixpanelPeopleProducer($token, $options);
        $this->_events = new MixpanelEventsProducer($token, $options);
    }


    /**
     * @param $token
     * @param array $options
     * @return Mixpanel
     */
    public static function getInstance($token, $options = array()) {
        if(!isset(self::$_instance)) {
            self::$_instance = new Mixpanel($token, $options);
        }
        return self::$_instance;
    }


    /**
     * Add an array representing a message to be sent to Mixpanel to the in-memory queue.
     * @param array $message
     */
    public function enqueue($message = array()) {
        $this->_events->enqueue($message);
    }


    /**
     * Add an array representing a list of messages to be sent to Mixpanel to a queue.
     * @param array $messages
     */
    public function enqueueAll($messages = array()) {
        $this->_events->enqueueAll($messages);
    }


    /**
     * Flush the events queue
     * @param int $desired_batch_size
     */
    public function flush($desired_batch_size = 50)
    {
        $this->_events->flush($desired_batch_size);
    }


    /**
     * Empty the events queue
     */
    public function reset()
    {
        $this->_events->reset();
    }


    /**
     * Track an event defined by $event associated with metadata defined by $properties
     * @param string $event
     * @param array $properties
     */
    public function track($event, $properties = array())
    {
        $this->_events->track($event, $properties);
    }


    /**
     * Register a property to be sent with every event. If the property has already been registered, it will be
     * overwritten.
     * @param string $property
     * @param mixed $value
     */
    public function register($property, $value)
    {
        $this->_events->register($property, $value);
    }


    /**
     * Register multiple properties to be sent with every event. If any of the properties have already been registered,
     * they will be overwritten.
     * @param array $props_and_vals
     */
    public function registerAll($props_and_vals = array())
    {
        $this->_events->registerAll($props_and_vals);
    }


    /**
     * Register a property to be sent with every event. If the property has already been registered, it will NOT be
     * overwritten.
     * @param $property
     * @param $value
     */
    public function registerOnce($property, $value)
    {
        $this->_events->registerOnce($property, $value);
    }


    /**
     * Register multiple properties to be sent with every event. If any of the properties have already been registered,
     * they will NOT be overwritten.
     * @param array $props_and_vals
     */
    public function registerAllOnce($props_and_vals = array())
    {
        $this->_events->registerAllOnce($props_and_vals);
    }


    /**
     * Un-register an property to be sent with every event.
     * @param string $property
     */
    public function unregister($property)
    {
        $this->_events->unregister($property);
    }


    /**
     * Un-register a list of properties to be sent with every event.
     * @param array $properties
     */
    public function unregisterAll($properties)
    {
        $this->_events->unregisterAll($properties);
    }


    /**
     * Get a property that is set to be sent with every event
     * @param string $property
     * @return mixed
     */
    public function getProperty($property)
    {
        return $this->_events->getProperty($property);
    }


    /**
     * Alias an existing id with a different unique id. This is helpful when you want to associate a generated id to
     * a username or e-mail address.
     * @param string|int $original_id
     * @param string|int $new_id
     */
    public function createAlias($original_id, $new_id)
    {
        $this->_events->createAlias($original_id, $new_id);
    }
}