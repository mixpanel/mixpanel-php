<?php

use Mixpanel\Mixpanel;

$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN"); // instantiate the Mixpanel class
$mp->track("login_clicked"); // track an event
