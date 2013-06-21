Mixpanel PHP Library
============
This library provides an API to track events and update profiles on Mixpanel. By default, events are written using ssl over a persistent socket connection. You can contribute your own persistence implementation by creating a Consumer.

Install with Composer
------------
Add mixpanel/mixpanel-php as a dependency and run composer update

```json
"require": {
    ...
    "mixpanel/mixpanel-php" : "1.*"
    ...
}
```

Now you can start tracking events and people:

```php
<?php
// import dependencies
require 'vendor/autoload.php';

// get the Mixpanel class instance, replace with your project token
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");

// track an event
$mp->track("button clicked", array("label" => "sign-up")); 

// create/update a profile for user id 12345
$mp->people->set(12345, array(
    '$first_name'       => "John",
    '$last_name'        => "Doe",
    '$email'            => "john.doe@example.com",
    '$phone'            => "5555555555",
    "Favorite Color"    => "red"
));
```


Install Manually
------------
 1. <a href="https://github.com/mixpanel/mixpanel-php/archive/master.zip">Download the Mixpanel PHP Library</a>
 2.  Extract the zip file to a directory called "mixpanel-php" in your project root
 3.  Now you can start tracking events and people:

```php
<?php
// import Mixpanel
require 'mixpanel-php/lib/Mixpanel.php';

// get the Mixpanel class instance, replace with your project token
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");

// track an event
$mp->track("button clicked", array("label" => "sign-up")); // track an event

// create/update a profile for user id 12345
$mp->people->set(12345, array(
    '$first_name'       => "John",
    '$last_name'        => "Doe",
    '$email'            => "john.doe@example.com",
    '$phone'            => "5555555555",
    "Favorite Color"    => "red"
));
```

Documentation
-------------
* <a href="https://mixpanel.com/help/reference/php" target="_blank">Reference Docs</a>
* <a href="http://mixpanel.github.io/mixpanel-php" target="_blank">Full API Reference</a>

For further examples and options checkout out the "examples" folder

