<?php
require_once("../lib/Mixpanel.php"); // import the Mixpanel class
$mp = Mixpanel::getInstance("1ef7e30d2a58d27f4b90c42e31d6d7ad"); // instantiate the Mixpanel class
$mp->track("login_clicked"); // track an event
