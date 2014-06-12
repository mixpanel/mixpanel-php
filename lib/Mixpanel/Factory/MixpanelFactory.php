<?php

namespace Mixpanel\Factory;
use Mixpanel\Mixpanel as MixpanelClass;
use Mixpanel\Producer\People;
use Mixpanel\Producer\Events;

/**
 * The MixpanelFactory class is used to return an instance of Mixpanel/Mixpanel
 *
 * @author  Matt Lody <mattlody@gmail.com>
 */
class MixpanelFactory {

    /**
     * @var \Mixpanel\Mixpanel
     */
    protected $instance;

    /**
     * @param $token
     * @param array $options
     */
    public function __construct($token, $options = array(), $people = null, $events = null)
    {
        //Set people property
        if(is_null($people)) {
            $people = new People($token, $options);
        } elseif(!($people instanceof People)) {
            throw new InvalidArgumentException('The people object supplied to MixpanelFactory::construct() must be an instance of Mixpanel\Producer\People');
        }

        //Set events property
        if(is_null($events)) {
            $events = new Events($token, $options);
        } elseif(!($events instanceof Events)) {
            throw new InvalidArgumentException('The events object supplied to MixpanelFactory::construct() must be an instance of Mixpanel\Producer\Events');
        }

        $this->instance = new MixpanelClass($token, $options = array(), $people, $events);
    }

    /**
     * @return \Mixpanel\Mixpanel
     */
    public function get()
    {
        return $this->instance;
    }

} 