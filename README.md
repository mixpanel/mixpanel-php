mixpanel-php
============
To get started with composer, specify mixpanel/mixpanel-php as a dependency and run composer update

```json
"require": {
    "php": ">=5.0",
    "mixpanel/mixpanel-php" : "*"
}
```

Now you can start tracking events and people:

```php
<?php
// import the Mixpanel class (if not using autoloading)
require_once("/path/to/vendor/mixpanel/mixpanel-php/lib/Mixpanel.php");

// get the Mixpanel class instance, replace with your project token
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");

// track an event
$mp->track("button clicked", array("label" => "sign-up"); // track an event

// create/update a profile for user id 12345
$mp->people->set(12345, array(
    '$first_name'       => "John",
    '$last_name'        => "Doe",
    '$email'            => "john.doe@example.com",
    '$phone'            => "5555555555",
    "Favorite Color"    => "red"
));
```

For further examples and options checkout out the "examples" folder

