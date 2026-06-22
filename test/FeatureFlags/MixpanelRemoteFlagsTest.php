<?php

/**
 * Subclass that captures the request the base would have made and
 * returns a pre-seeded JSON response — so we can assert on URL/params
 * without an HTTP server in the loop.
 */
class _TestableRemoteFlags extends FeatureFlags_MixpanelRemoteFlags {
    /** @var array{path:string, query:array} */
    public $lastRequest = null;

    /** @var array|null successful JSON response to return; if null, throws */
    public $nextResponse = null;

    /** @var string|null exception message to throw instead of returning a response */
    public $nextError = null;

    protected function _httpGet($path, array $query = array()) {
        $this->lastRequest = array('path' => $path, 'query' => $query);
        if ($this->nextError !== null) {
            throw new Exception($this->nextError);
        }
        return $this->nextResponse === null ? array() : $this->nextResponse;
    }
}

class MixpanelRemoteFlagsTest extends PHPUnit\Framework\TestCase {

    /** @var array */
    private $_captured;

    /** @var _TestableRemoteFlags */
    private $_provider;

    protected function setUp() : void {
        $this->_captured = array();
        $captured = &$this->_captured;
        $tracker = function ($distinctId, $eventName, $properties) use (&$captured) {
            $captured[] = array($distinctId, $eventName, $properties);
        };
        $this->_provider = new _TestableRemoteFlags('token', '2.11.0', $tracker, array(
            'flags' => array('mode' => 'remote'),
        ));
    }

    public function testGetVariantSendsContextAndFlagKey() {
        $this->_provider->nextResponse = array('flags' => array(
            'my-flag' => array('variant_key' => 'on', 'variant_value' => true),
        ));
        $context = array('distinct_id' => 'u1', 'custom_properties' => array('email' => 'a@b.com'));
        $variant = $this->_provider->getVariant(
            'my-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, false),
            $context
        );

        $this->assertEquals('on', $variant->variantKey);
        $this->assertSame(true, $variant->variantValue);
        $this->assertEquals('/flags', $this->_provider->lastRequest['path']);
        $this->assertEquals('my-flag', $this->_provider->lastRequest['query']['flag_key']);
        $this->assertEquals(json_encode($context), $this->_provider->lastRequest['query']['context']);
    }

    public function testTracksExposureOnSuccess() {
        $this->_provider->nextResponse = array('flags' => array(
            'my-flag' => array('variant_key' => 'on', 'variant_value' => true, 'experiment_id' => 'X'),
        ));
        $this->_provider->getVariant(
            'my-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, false),
            array('distinct_id' => 'u1')
        );
        $this->assertCount(1, $this->_captured);
        list($distinctId, $event, $props) = $this->_captured[0];
        $this->assertEquals('u1', $distinctId);
        $this->assertEquals('$experiment_started', $event);
        $this->assertEquals('remote', $props['Flag evaluation mode']);
        $this->assertEquals('X', $props['$experiment_id']);
    }

    public function testFlagMissingInResponseSetsFlagNotFoundReason() {
        $this->_provider->nextResponse = array('flags' => array(
            'other-flag' => array('variant_key' => 'on', 'variant_value' => true),
        ));
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, 'fb');
        $variant = $this->_provider->getVariant('my-flag', $fallback, array('distinct_id' => 'u1'));
        $this->assertSame($fallback, $variant);
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsBase::REASON_FLAG_NOT_FOUND,
            $this->_provider->lastFailureReason()
        );
    }

    public function testBackendErrorIsSurfacedDistinctlyFromFlagNotFound() {
        // Audit finding #7: a backend error (HTTP 4xx/5xx) must not be
        // indistinguishable from "flag not found" — otherwise a future
        // OF wrapper translates it to FLAG_NOT_FOUND when it should be
        // GENERAL.
        $this->_provider->nextError = 'simulated HTTP 500';
        $errors = array();
        $errorCallback = function ($code, $message) use (&$errors) {
            $errors[] = $message;
        };
        $captured = array();
        $tracker = function ($d, $e, $p) use (&$captured) {
            $captured[] = $p;
        };
        $provider = new _TestableRemoteFlags('token', '2.11.0', $tracker, array(
            'error_callback' => $errorCallback,
            'flags' => array('mode' => 'remote'),
        ));
        $provider->nextError = 'simulated HTTP 500';

        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, 'fb');
        $variant = $provider->getVariant('my-flag', $fallback, array('distinct_id' => 'u1'));
        $this->assertSame($fallback, $variant);
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsBase::REASON_BACKEND_ERROR,
            $provider->lastFailureReason()
        );
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('simulated HTTP 500', $errors[0]);
    }

    public function testReportExposureFalseSkipsTracking() {
        $this->_provider->nextResponse = array('flags' => array(
            'my-flag' => array('variant_key' => 'on', 'variant_value' => true),
        ));
        $this->_provider->getVariant(
            'my-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, false),
            array('distinct_id' => 'u1'),
            false
        );
        $this->assertCount(0, $this->_captured);
    }

    public function testGetAllVariantsOmitsFlagKeyQueryParam() {
        $this->_provider->nextResponse = array('flags' => array(
            'a' => array('variant_key' => 'on', 'variant_value' => 1),
        ));
        $this->_provider->getAllVariants(array('distinct_id' => 'u1'));
        $this->assertArrayNotHasKey('flag_key', $this->_provider->lastRequest['query']);
    }
}
