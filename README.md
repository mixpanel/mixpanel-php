Mixpanel PHP Library [![Build Status](https://travis-ci.org/mixpanel/mixpanel-php.svg)](https://travis-ci.org/mixpanel/mixpanel-php)
============

##### _May 13, 2026_ - [2.11.0](https://github.com/mixpanel/mixpanel-php/releases/tag/2.11.0)

This library provides an API to track events and update profiles on Mixpanel.

Install with Composer
------------
Add mixpanel/mixpanel-php as a dependency and run composer update

```json
"require": {
    ...
    "mixpanel/mixpanel-php" : "2.*"
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

Hierarchical Groups
-------------
For a project that uses hierarchical groups, create the client with `'hierarchical_groups' => true` and identify users with a `MixpanelIdentity`, so events carry `$user_id` and `$company_id` and Mixpanel builds the distinct id from them:

```php
<?php
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN", array("hierarchical_groups" => true));
$user = new MixpanelIdentity(array("user_id" => "user_123", "company_id" => "acme"));

// every later event is sent with $user_id and $company_id
$mp->identify($user);
$mp->track("button clicked");

// or for one event only
$mp->track("button clicked", array("label" => "sign-up"), $user);

// user profiles are group profiles keyed by $user_id within the company, sent to /groups
$mp->people->set($user, array("role" => "admin"));

// group profile updates need the company; forCompany shares the client's queues
$acme = $mp->forCompany("acme");
$acme->group->set('$company_id', "acme", array("tier" => "enterprise"));
$acme->group->set("department", "finance", array("budget" => 100));
```

A `MixpanelIdentity` takes any of `distinct_id`, `user_id`, `company_id` and `device_id`. On a hierarchical groups project, a user id needs a company id (or a device id) and user profiles support only `set`, `setOnce`, `remove`, `deleteUser` and `append` of an array (sent as `$union`); `increment`, `append` of a single value, `trackCharge` and `clearCharges` throw `BadMethodCallException`, as does `createAlias`. User profile updates are never sent to `/engage` on a hierarchical groups project: pass a `MixpanelIdentity` with a user id and a company id, or a distinct id of the form `$company:{company}|$user:{user}` (directly or as the identity's `distinct_id`), which is split into its company and user and sent to `/groups`. Any other distinct id throws `InvalidArgumentException`. Group profile updates without a company throw `LogicException`, and identities that do not fit the project throw `InvalidArgumentException`. Events accept plain and combined distinct ids in either mode, and nothing changes without hierarchical groups.

Production Notes
-------------
By default, data is sent using ssl over cURL. This works fine when you're tracking a small number of events or aren't concerned with the potentially blocking nature of the PHP cURL calls. However, this isn't very efficient when you're sending hundreds of events (such as in batch processing). Our library comes packaged with an easy way to use a persistent socket connection for much more efficient writes. To enable the persistent socket, simply pass `'consumer' => 'socket'` as an entry in the `$options` array when you instantiate the Mixpanel class. Additionally, you can contribute your own persistence implementation by creating a custom Consumer.

Testing
-------------
mixpanel-php uses `phpunit` as the testing framework. Please ensure that `composer` is up to date. 
To run tests, execute `composer run-script unit-tests` on the root directory. 

Documentation
-------------
* <a href="https://mixpanel.com/help/reference/php" target="_blank">Reference Docs</a>
* <a href="http://mixpanel.github.io/mixpanel-php" target="_blank">Full API Reference</a>

For further examples and options checkout out the "examples" folder

Changelog
-------------
Version 2.11.0
* Fix identify regex for $anon_id
* Fix PHP 8.2 deprecation warning

Version 2.10.0
* send millisecond precision timestamps

Version 2.9.0
* update regex for $anon_id check
* Group Analytics Support
* Fix PHP 8.1 deprecation warning
* PHP 7.4 compatibility

Version 2.8.1
* Updated `$anon_id` regex in `identify` method to support all Mixpanel distinct IDs

Version 2.8.0
* Added `$anon_id` parameter to `identify` method, and a track call when parameter exists and is in UUID v4 format
* Change parameter names for `createAlias` method to `$distinct_id` and `$alias`
* Prevent unnecessary call to _encode on non-forked CurlConsumer
* make sure 'Connection' exists before accessing it

Version 2.7.0:
 * Dropped test support for EOL PHP version (all < 7.1)
 * Added <a href="https://github.com/mixpanel/mixpanel-php/commit/6f15000309093b54f7f59f07af297f576fd3a498">Parallel cURL implementation</a>
 * <a href="https://github.com/mixpanel/mixpanel-php/commit/1f814c1be704217e4bc8bf570fad844360fa7318">Make createAlias adhere to the consumer config</a>
 * Added <a href="https://github.com/mixpanel/mixpanel-php/commit/f2812f4e696ef747b2ab0640f46df97d1bf309c0">option to set $ignore_alias on people updates</a>
 * Fixed <a href="https://github.com/mixpanel/mixpanel-php/commit/d50267c48b08eb3c5e1dee2b5dd932cf2b4c3977">Singleton instance must depend on requested token</a>
 * Fixed license type in composer.json
 * Remove testing on HHVM as its no longer support by composer

Version 2.6.2:
 * Added support for $ignore_time
 * Cleaned up some comments to be more clear

Version 2.6.1:
 * Fixed bug in SocketConsumer timeout

Version 2.6:
 * Updated default for `connect_timeout` in SocketConsumer to be 5

Version 2.5:
 * `timeout` option now refers to `CURLOPT_TIMEOUT` instead of `CURLOPT_CONNECTTIMEOUT` in non-forked cURL calls, it has been removed from the SocketConsumer in favor of a new `connect_timeout` option.
 * Added a new `connect_timeout` option for CURLOPT_CONNECTTIMEOUT in non-forked cURL calls (CurlConsumer) and the socket timeout (SocketConsumer)
 * Set default timeout (CURLOPT_TIMEOUT) to 30 seconds in non-forked cURL calls
 * Set default connection timeoute (CURLOPT_CONNECTTIMEOUT) to 5 seconds in non-forked cURL calls
 * We now pass cURL errors from non-forked cURL calls to `_handle_error` with the curl errno and message


Version 2.4:
 * Fixed a bug where passing the integer 0 for the `ip` parameter would be ignored

Version 2.1 - 2.3:
 * Broken releases

Version 2.0:
 * Changed the default consumer to be 'curl' (CurlConsumer)
 * Changed the default setting of 'fork' to false in the Curl Consumer. This means that by default, events and profile updates are sent synchronously using the PHP cURL lib when using the Curl Consumer.
 * 'createAlias' uses the CurlConsumer with 'fork' explicitly set to false (as we need this to be synchronous) instead of the SocketConsumer. 
 * Fixed bug where max_queue_size was never read
 
