<?php

//Use the Mixpanel Factory class
use Mixpanel\Factory\MixpanelFactory;

$factory = new MixpanelFactory("MIXPANEL_PROJECT_TOKEN", array(
    "debug"             => true,
    "max_batch_size"    => 1,
    "consumers"         => array("ob" => '\Namespaced\Consumer\ClassName'),
    "consumer"          => "ob"
));
$mp = $factory->get();

$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event", array("color" => "red"));
