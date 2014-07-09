<?php

//Use the Mixpanel Factory class
use Mixpanel\Factory\MixpanelFactory;

// Make calls using the PHP cURL extension not using SSL
// Warning: This will block until the requests are complete.
$factory = new MixpanelFactory("MIXPANEL_PROJECT_TOKEN", array(
    "debug"             => true,
    "consumer"          => "curl",
    "fork"              => false,
    "use_ssl"           => false
));

$mp = $factory->get();

$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event", array("color" => "red"));
