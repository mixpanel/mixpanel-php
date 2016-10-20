<?php

use Mixpanel\Mixpanel;

// import the autoloader
require_once __DIR__ . "/../vendor/autoload.php";

// instantiate the Mixpanel class
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");

// track an event
$mp->track("login_clicked");
