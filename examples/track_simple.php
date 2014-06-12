<?php
//Use the Mixpanel Factory class
use Mixpanel\Factory\MixpanelFactory;

// Get a Mixpanel instance
$factory = new MixpanelFactory("MIXPANEL_PROJECT_TOKEN");
$mp = $factory->get();

$mp->track("login_clicked"); // track an event
