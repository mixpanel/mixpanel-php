<?php

require_once(dirname(__FILE__) . "/MixpanelIdentity.php");
require_once(dirname(__FILE__) . "/Base/MixpanelBase.php");
require_once(dirname(__FILE__) . "/Producers/MixpanelPeople.php");
require_once(dirname(__FILE__) . "/Producers/MixpanelEvents.php");
require_once(dirname(__FILE__) . "/Producers/MixpanelGroups.php");

/**
 * This is the main class for the Mixpanel PHP Library which provides all of the methods you need to track events,
  * create/update profiles and group profiles.
 *
 * Architecture
 * -------------
 *
 * This library is built such that all messages are buffered in an in-memory "queue"
 * The queue will be automatically flushed at the end of every request. Alternatively, you can call "flush()" manually
 * at any time. Flushed messages will be passed to a Consumer's "persist" method. The library comes with a handful of
 * Consumers. The "CurlConsumer" is used by default which will send the messages to Mixpanel using forked cURL processes.
 * You can implement your own custom Consumer to customize how a message is sent to Mixpanel. This can be useful when
 * you want to put messages onto a distributed queue (such as ActiveMQ or Kestrel) instead of writing to Mixpanel in
 * the user thread.
 *
 * Options
 * -------------
 *
 * <table width="100%" cellpadding="5">
 *  <tr>
 *      <th>Option</th>
 *      <th>Description</th>
 *      <th>Default</th>
 *  </tr>
 *  <tr>
 *      <td>max_queue_size</td>
 *      <td>The maximum number of items to buffer in memory before flushing</td>
 *      <td>1000</td>
 *  </tr>
 *  <tr>
 *      <td>debug</td>
 *      <td>Enable/disable debug mode</td>
 *      <td>false</td>
 *  </tr>
 *  <tr>
 *      <td>consumer</td>
 *      <td>The consumer to use for writing messages</td>
 *      <td>curl</td>
 *  </tr>
 *  <tr>
 *      <td>consumers</td>
 *      <td>An array of custom consumers in the format array(consumer_key => class_name)</td>
 *      <td>null</td>
 *  </tr>
 *  <tr>
 *      <td>host</td>
 *      <td>The host name for api calls (used by some consumers)</td>
 *      <td>api.mixpanel.com</td>
 *  </tr>
 *  <tr>
 *      <td>events_endpoint</td>
 *      <td>The endpoint for tracking events (relative to the host)</td>
 *      <td>/events</td>
 *  </tr>
 *  <tr>
 *      <td>people_endpoint</td>
 *      <td>The endpoint for making people updates (relative to the host)</td>
 *      <td>/engage</td>
 *  </tr>
 *  <tr>
 *      <td>use_ssl</td>
 *      <td>Tell the consumer whether or not to use ssl (when available)</td>
 *      <td>true</td>
 *  </tr>
 *  <tr>
 *      <td>error_callback</td>
 *      <td>The name of a function to be called on consumption failures</td>
 *      <td>null</td>
 *  </tr>
 *  <tr>
 *      <td>connect_timeout</td>
 *      <td>In both the SocketConsumer and CurlConsumer, this is used for the connection timeout (i.e. How long it has take to actually make a connection).
 *      <td>5</td>
 *  </tr>
 *  <tr>
 *      <td>timeout</td>
 *      <td>In the CurlConsumer (non-forked), it is used to determine how long the cURL call has to execute.
 *      <td>30</td>
 *  </tr>
 *  <tr>
 *      <td>hierarchical_groups</td>
 *      <td>Set to true if the project uses hierarchical groups. It enables the checks that keep hierarchical groups data valid; it never changes what a message that is valid either way looks like.</td>
 *      <td>false</td>
 *  </tr>
 * </table>
 *
 * Example: Tracking an Event
 * -------------
 *
 * $mp = Mixpanel::getInstance("MY_TOKEN");
 *
 * $mp->track("My Event");
 *
 * Example: Setting Profile Properties
 * -------------
 *
 * $mp = Mixpanel::getInstance("MY_TOKEN", array("use_ssl" => false));
 *
 * $mp->people->set(12345, array(
 * '$first_name'       => "John",
 * '$last_name'        => "Doe",
 * '$email'            => "john.doe@example.com",
 * '$phone'            => "5555555555",
 * 'Favorite Color'    => "red"
 * ));
 *
 * Example: Hierarchical Groups
 * -------------
 *
 * $mp = Mixpanel::getInstance("MY_TOKEN", array("hierarchical_groups" => true));
 *
 * $identity = new MixpanelIdentity(array("user_id" => "user_123", "company_id" => "acme"));
 * $mp->identify($identity);
 * $mp->track("Signup");
 * $mp->people->set($identity, array("role" => "admin"));
 * $mp->forCompany("acme")->group->set('$company_id', "acme", array("tier" => "enterprise"));
 *
 */
class Mixpanel extends Base_MixpanelBase {


    /**
     * An instance of the MixpanelPeople class (used to create/update profiles)
     * @var Producers_MixpanelPeople
     */
    public $people;


    /**
     * An instance of the MixpanelEvents class
     * @var Producers_MixpanelEvents
     */
    private $_events;

    /**
     * An instance of the MixpanelGroups class (used to create/update group profiles)
     * @var Producers_MixpanelPeople
     */
    public $group;
 


    /**
     * Instances' list of the Mixpanel class (for singleton use, splitted by token)
     * @var Mixpanel[]
     */
    private static $_instances = array();
    

    /**
     * Instantiates a new Mixpanel instance.
     * @param $token
     * @param array $options
     */
    public function __construct($token, $options = array()) {
        parent::__construct($options);
        $this->group = new Producers_MixpanelGroups($token, $options);
        // identity-based user profile updates on a hierarchical groups project go to /groups, on the group queue
        $this->people = new Producers_MixpanelPeople($token, $options, $this->group);
        $this->_events = new Producers_MixpanelEvents($token, $options);
    }


    /**
     * Returns a view of this client whose group producer adds a top-level $company_id to every group profile
     * message. Hierarchical groups projects require $company_id on every group profile update:
     *
     * $acme = $mp->forCompany("acme");
     * $acme->group->set('$company_id', "acme", $company_properties);
     * $acme->group->set("department", "finance", $department_properties);
     *
     * The view shares this client's queues, consumers and identity, so nothing is sent separately; this client is
     * unchanged. Identity-based people updates use the identity's own company, not this one.
     * @param string|int $company_id the id of the top-level company
     * @return Mixpanel
     * @throws InvalidArgumentException if $company_id is empty
     */
    public function forCompany($company_id) {
        $view = clone $this;
        $view->group = $this->group->forCompany($company_id);
        return $view;
    }


    /**
     * Returns a singleton instance of Mixpanel
     * @param $token
     * @param array $options
     * @return Mixpanel
     */
    public static function getInstance($token, $options = array()) {
        if(!isset(self::$_instances[$token])) {
            self::$_instances[$token] = new Mixpanel($token, $options);
        }
        return self::$_instances[$token];
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
    public function flush($desired_batch_size = 50) {
        $this->_events->flush($desired_batch_size);
    }


    /**
     * Empty the events queue
     */
    public function reset() {
        $this->_events->reset();
    }


    /**
     * Identify the user you want to associate to tracked events. The $anon_id must be UUID v4 format and not already merged to an $identified_id.
     * All identify calls with a new and valid $anon_id will trigger a track $identify event, and merge to the $identified_id.
     *
     * Pass a MixpanelIdentity instead of a user id to send its keys (distinct_id, $user_id, $company_id, $device_id)
     * with every later event, replacing any identity set earlier; give it a device_id rather than passing an $anon_id.
     * Identifying with a user id afterwards clears the identity.
     * @param string|int|MixpanelIdentity $user_id
     * @param string|int $anon_id [optional]
     * @throws InvalidArgumentException if the identity does not fit this project
     */
    public function identify($user_id, $anon_id = null) {
        $this->_events->identify($user_id, $anon_id);
    }

    /**
     * Track an event defined by $event associated with metadata defined by $properties
     *
     * When an identity applies, either $identity or one set with identify(MixpanelIdentity), its keys replace any
     * values for the same keys in $properties.
     * @param string $event
     * @param array $properties
     * @param MixpanelIdentity|null $identity [optional] who this event belongs to, overriding identify()
     * @throws InvalidArgumentException if the identity does not fit this project
     */
    public function track($event, $properties = array(), $identity = null) {
        $this->_events->track($event, $properties, $identity);
    }


    /**
     * Returns the identity set by identify(MixpanelIdentity), or null if there is none
     * @return MixpanelIdentity|null
     */
    public function getIdentity() {
        return $this->_events->getIdentity();
    }


    /**
     * Register a property to be sent with every event.
     *
     * If the property has already been registered, it will be
     * overwritten. NOTE: Registered properties are only persisted for the life of the Mixpanel class instance.
     * @param string $property
     * @param mixed $value
     */
    public function register($property, $value) {
        $this->_events->register($property, $value);
    }


    /**
     * Register multiple properties to be sent with every event.
     *
     * If any of the properties have already been registered,
     * they will be overwritten. NOTE: Registered properties are only persisted for the life of the Mixpanel class
     * instance.
     * @param array $props_and_vals
     */
    public function registerAll($props_and_vals = array()) {
        $this->_events->registerAll($props_and_vals);
    }


    /**
     * Register a property to be sent with every event.
     *
     * If the property has already been registered, it will NOT be
     * overwritten. NOTE: Registered properties are only persisted for the life of the Mixpanel class instance.
     * @param $property
     * @param $value
     */
    public function registerOnce($property, $value) {
        $this->_events->registerOnce($property, $value);
    }


    /**
     * Register multiple properties to be sent with every event.
     *
     * If any of the properties have already been registered,
     * they will NOT be overwritten. NOTE: Registered properties are only persisted for the life of the Mixpanel class
     * instance.
     * @param array $props_and_vals
     */
    public function registerAllOnce($props_and_vals = array()) {
        $this->_events->registerAllOnce($props_and_vals);
    }


    /**
     * Un-register an property to be sent with every event.
     * @param string $property
     */
    public function unregister($property) {
        $this->_events->unregister($property);
    }


    /**
     * Un-register a list of properties to be sent with every event.
     * @param array $properties
     */
    public function unregisterAll($properties) {
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
     * An alias to be merged with the distinct_id. Each alias can only map to one distinct_id.
     * This is helpful when you want to associate a generated id (such as a session id) to a user id or username.
     * @param string|int $distinct_id
     * @param string|int $alias
     */
    public function createAlias($distinct_id, $alias) {
        $this->_events->createAlias($distinct_id, $alias);
    }
}
