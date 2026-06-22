# Changelog

## Unreleased

- Added feature flag support (local and remote evaluation) via `$mp->flags`
- Bumped PHP minimum to 7.2 and PHPUnit dev dep to ^7.5 || ^8.5 || ^9.5
- Added `jwadhams/json-logic-php` as a runtime dependency

## [2.11.0](https://github.com/mixpanel/mixpanel-php/tree/2.11.0) (2026-05-13)

- Fix identify regex for $anon_id
- Fix PHP 8.2 deprecation warning

## [2.10.0](https://github.com/mixpanel/mixpanel-php/tree/2.10.0)

- send millisecond precision timestamps

## [2.9.0](https://github.com/mixpanel/mixpanel-php/tree/2.9.0)

- update regex for $anon_id check
- Group Analytics Support
- Fix PHP 8.1 deprecation warning
- PHP 7.4 compatibility

## [2.8.1](https://github.com/mixpanel/mixpanel-php/tree/2.8.1)

- Updated `$anon_id` regex in `identify` method to support all Mixpanel distinct IDs

## [2.8.0](https://github.com/mixpanel/mixpanel-php/tree/2.8.0)

- Added `$anon_id` parameter to `identify` method, and a track call when parameter exists and is in UUID v4 format
- Change parameter names for `createAlias` method to `$distinct_id` and `$alias`
- Prevent unnecessary call to _encode on non-forked CurlConsumer
- make sure 'Connection' exists before accessing it

## [2.7.0](https://github.com/mixpanel/mixpanel-php/tree/2.7.0)

- Dropped test support for EOL PHP version (all < 7.1)
- Added [Parallel cURL implementation](https://github.com/mixpanel/mixpanel-php/commit/6f15000309093b54f7f59f07af297f576fd3a498)
- [Make createAlias adhere to the consumer config](https://github.com/mixpanel/mixpanel-php/commit/1f814c1be704217e4bc8bf570fad844360fa7318)
- Added [option to set $ignore_alias on people updates](https://github.com/mixpanel/mixpanel-php/commit/f2812f4e696ef747b2ab0640f46df97d1bf309c0)
- Fixed [Singleton instance must depend on requested token](https://github.com/mixpanel/mixpanel-php/commit/d50267c48b08eb3c5e1dee2b5dd932cf2b4c3977)
- Fixed license type in composer.json
- Remove testing on HHVM as its no longer support by composer

## [2.6.2](https://github.com/mixpanel/mixpanel-php/tree/2.6.2)

- Added support for $ignore_time
- Cleaned up some comments to be more clear

## [2.6.1](https://github.com/mixpanel/mixpanel-php/tree/2.6.1)

- Fixed bug in SocketConsumer timeout

## [2.6](https://github.com/mixpanel/mixpanel-php/tree/2.6)

- Updated default for `connect_timeout` in SocketConsumer to be 5

## [2.5](https://github.com/mixpanel/mixpanel-php/tree/2.5)

- `timeout` option now refers to `CURLOPT_TIMEOUT` instead of `CURLOPT_CONNECTTIMEOUT` in non-forked cURL calls, it has been removed from the SocketConsumer in favor of a new `connect_timeout` option.
- Added a new `connect_timeout` option for CURLOPT_CONNECTTIMEOUT in non-forked cURL calls (CurlConsumer) and the socket timeout (SocketConsumer)
- Set default timeout (CURLOPT_TIMEOUT) to 30 seconds in non-forked cURL calls
- Set default connection timeoute (CURLOPT_CONNECTTIMEOUT) to 5 seconds in non-forked cURL calls
- We now pass cURL errors from non-forked cURL calls to `_handle_error` with the curl errno and message

## [2.4](https://github.com/mixpanel/mixpanel-php/tree/2.4)

- Fixed a bug where passing the integer 0 for the `ip` parameter would be ignored

## [2.1 - 2.3](https://github.com/mixpanel/mixpanel-php/tree/2.3)

- Broken releases

## [2.0](https://github.com/mixpanel/mixpanel-php/tree/2.0)

- Changed the default consumer to be 'curl' (CurlConsumer)
- Changed the default setting of 'fork' to false in the Curl Consumer. This means that by default, events and profile updates are sent synchronously using the PHP cURL lib when using the Curl Consumer.
- 'createAlias' uses the CurlConsumer with 'fork' explicitly set to false (as we need this to be synchronous) instead of the SocketConsumer.
- Fixed bug where max_queue_size was never read
