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

// Recommended: Use Service Account credentials for secure server-to-server integration
$credentials = new Credentials_ServiceAccountCredentials(
    "YOUR_PROJECT_ID",
    "YOUR_SERVICE_ACCOUNT_USERNAME", 
    "YOUR_SERVICE_ACCOUNT_SECRET"
);
$mp = Mixpanel::getInstance($credentials);

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

**Legacy Authentication (Deprecated):**
```php
<?php
// DEPRECATED: Using project token directly is deprecated
// This method will be removed in a future version
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");
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

// Recommended: Use Service Account credentials
$credentials = new Credentials_ServiceAccountCredentials(
    "YOUR_PROJECT_ID",
    "YOUR_SERVICE_ACCOUNT_USERNAME",
    "YOUR_SERVICE_ACCOUNT_SECRET"
);
$mp = Mixpanel::getInstance($credentials);

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

Authentication
-------------
### Service Accounts (Recommended)

Service Account authentication is the recommended method for server-to-server integration. It provides enhanced security and fine-grained access control.

```php
$credentials = new Credentials_ServiceAccountCredentials(
    "YOUR_PROJECT_ID",
    "YOUR_SERVICE_ACCOUNT_USERNAME",
    "YOUR_SERVICE_ACCOUNT_SECRET"
);
$mp = Mixpanel::getInstance($credentials);
```

To create service account credentials:
1. Go to your Mixpanel project settings
2. Navigate to "Service Accounts"
3. Create a new service account and note the credentials

### API Secrets (Deprecated)

**⚠️ DEPRECATED:** API secret authentication is deprecated and will be removed in a future version. Please migrate to Service Accounts.

```php
// Not recommended - deprecated
$credentials = new Credentials_APISecretCredentials(
    "YOUR_PROJECT_ID",
    "YOUR_API_SECRET"
);
$mp = Mixpanel::getInstance($credentials);
```

### Project Tokens (Deprecated)

**⚠️ DEPRECATED:** Using project tokens directly is deprecated and will be removed in a future version.

```php
// Not recommended - deprecated
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");
```

For more information on authentication methods, see: https://docs.mixpanel.com/docs/tracking-methods/choosing-the-right-method

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
Version 2.12.0 (Unreleased)
* **FEATURE**: Add Service Account authentication support (recommended for server-to-server integration)
* **FEATURE**: Add APISecretCredentials class for API secret authentication (marked as deprecated)
* **DEPRECATION**: Using project token strings directly in Mixpanel constructor is now deprecated
* **DEPRECATION**: API secret authentication is deprecated in favor of Service Accounts
* Add authentication headers support to CurlConsumer and SocketConsumer
* Add comprehensive test coverage for credential authentication
* Update documentation with Service Account examples

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
 
