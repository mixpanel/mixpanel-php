<?php

/*
* This file is part of the Mixpanel package.
*.
*/

if (intval(ini_get('memory_limit')) < 64) {
    ini_set('memory_limit', '64M');
}

ini_set('error_reporting', E_ALL); // or error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->add('Mixpanel\Test', __DIR__);