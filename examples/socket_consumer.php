<?php
//Use the Mixpanel Factory class
use Mixpanel\Factory\MixpanelFactory;

// Get a Mixpanel instance
$factory = new MixpanelFactory("MIXPANEL_PROJECT_TOKEN", array(
    "debug"             => true,
    "consumer"          => "socket",
    "use_ssl"           => false
));
$mp = $factory->get();

$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event", array("color" => "red"));
