<?php

/**
 * Test-only subclass that lets us inject flag definitions directly
 * without going over the network. The local provider doesn't expose a
 * "set definitions for test" hook in production code on purpose — that
 * would be a foot-gun — so we reach in via a subclass here.
 */
class _TestableLocalFlags extends FeatureFlags_MixpanelLocalFlags {
    public function setDefinitionsForTest(array $defs) {
        // Reach into private state via reflection. On PHP 8.1+
        // setAccessible() is implicit, but calling it remains harmless
        // (and required on PHP 7.x).
        $reflection = new ReflectionClass('FeatureFlags_MixpanelLocalFlags');
        $defsProp = $reflection->getProperty('_definitions');
        if (PHP_VERSION_ID < 80100) {
            $defsProp->setAccessible(true);
        }
        $defsProp->setValue($this, $defs);
        $readyProp = $reflection->getProperty('_ready');
        if (PHP_VERSION_ID < 80100) {
            $readyProp->setAccessible(true);
        }
        $readyProp->setValue($this, true);
    }
}

class MixpanelLocalFlagsTest extends PHPUnit\Framework\TestCase {

    /** @var array exposure events captured by the spy tracker */
    private $_captured;

    /** @var callable */
    private $_tracker;

    /** @var _TestableLocalFlags */
    private $_provider;

    protected function setUp() : void {
        $this->_captured = array();
        $captured = &$this->_captured;
        $this->_tracker = function ($distinctId, $eventName, $properties) use (&$captured) {
            $captured[] = array($distinctId, $eventName, $properties);
        };
        $this->_provider = new _TestableLocalFlags('token', '2.11.0', $this->_tracker, array(
            'flags' => array('mode' => 'local'),
        ));
    }

    /**
     * Build a minimal flag definition fixture. A single rollout at
     * 100% with no runtime rules, and as many variants as supplied
     * with equal splits summing to 1.0.
     */
    private function makeFlag($key, array $variants, $context = 'distinct_id', $rolloutPct = 1.0, $extra = array()) {
        $variantDefs = array();
        foreach ($variants as $variantKey => $value) {
            $variantDefs[] = array(
                'key' => $variantKey,
                'value' => $value,
                'is_control' => false,
                'split' => 1.0 / count($variants),
            );
        }
        $flag = array(
            'id' => 'fid-' . $key,
            'name' => $key,
            'key' => $key,
            'status' => 'active',
            'project_id' => 1,
            'context' => $context,
            'experiment_id' => 'exp-' . $key,
            'is_experiment_active' => true,
            'ruleset' => array(
                'variants' => $variantDefs,
                'rollout' => array(
                    array('rollout_percentage' => $rolloutPct),
                ),
            ),
        );
        return array_merge($flag, $extra);
    }

    public function testReturnsFallbackAndSetsReasonWhenFlagMissing() {
        $this->_provider->setDefinitionsForTest(array());
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, 'fallback');
        $result = $this->_provider->getVariant('unknown', $fallback, array('distinct_id' => 'u1'));
        $this->assertSame($fallback, $result);
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsBase::REASON_FLAG_NOT_FOUND,
            $this->_provider->lastFailureReason()
        );
    }

    public function testGetVariantBeforeLoadReturnsNotReady() {
        // Brand-new provider — no loadDefinitions / no setDefinitionsForTest.
        $fresh = new FeatureFlags_MixpanelLocalFlags('token', '2.11.0', $this->_tracker, array(
            'flags' => array('mode' => 'local'),
        ));
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, 'fb');
        $result = $fresh->getVariant('any-flag', $fallback, array('distinct_id' => 'u1'));
        $this->assertSame($fallback, $result);
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsBase::REASON_NOT_READY,
            $fresh->lastFailureReason()
        );
    }

    public function testReturnsFallbackAndSetsReasonWhenContextMissing() {
        $this->_provider->setDefinitionsForTest(array(
            'my-flag' => $this->makeFlag('my-flag', array('on' => true)),
        ));
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, 'fallback');
        // No distinct_id in context, but the flag's bucketing key IS distinct_id.
        $result = $this->_provider->getVariant('my-flag', $fallback, array('email' => 'x@y.com'));
        $this->assertSame($fallback, $result);
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsBase::REASON_MISSING_CONTEXT_KEY,
            $this->_provider->lastFailureReason()
        );
    }

    public function testReturnsVariantOnSuccessfulEval() {
        $this->_provider->setDefinitionsForTest(array(
            'my-flag' => $this->makeFlag('my-flag', array('on' => true)),
        ));
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, 'fallback');
        $result = $this->_provider->getVariant('my-flag', $fallback, array('distinct_id' => 'u1'));
        $this->assertEquals('on', $result->variantKey);
        $this->assertSame(true, $result->variantValue);
        $this->assertEquals('exp-my-flag', $result->experimentId);
        $this->assertTrue($result->isExperimentActive);
        $this->assertEquals(FeatureFlags_MixpanelFlagsBase::REASON_OK, $this->_provider->lastFailureReason());
    }

    public function testTracksExposureByDefault() {
        $this->_provider->setDefinitionsForTest(array(
            'my-flag' => $this->makeFlag('my-flag', array('on' => true)),
        ));
        $this->_provider->getVariant(
            'my-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, 'fallback'),
            array('distinct_id' => 'u1')
        );
        $this->assertCount(1, $this->_captured);
        list($distinctId, $eventName, $props) = $this->_captured[0];
        $this->assertEquals('u1', $distinctId);
        $this->assertEquals('$experiment_started', $eventName);
        $this->assertEquals('my-flag', $props['Experiment name']);
        $this->assertEquals('on', $props['Variant name']);
        $this->assertEquals('feature_flag', $props['$experiment_type']);
        $this->assertEquals('local', $props['Flag evaluation mode']);
        $this->assertArrayHasKey('Variant fetch latency (ms)', $props);
    }

    public function testReportExposureFalseSkipsTracking() {
        $this->_provider->setDefinitionsForTest(array(
            'my-flag' => $this->makeFlag('my-flag', array('on' => true)),
        ));
        $this->_provider->getVariant(
            'my-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, 'fallback'),
            array('distinct_id' => 'u1'),
            false
        );
        $this->assertCount(0, $this->_captured);
    }

    public function testReturnsFallbackWhenRolloutIsZero() {
        $this->_provider->setDefinitionsForTest(array(
            'my-flag' => $this->makeFlag('my-flag', array('on' => true), 'distinct_id', 0.0),
        ));
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, 'fallback');
        $result = $this->_provider->getVariant('my-flag', $fallback, array('distinct_id' => 'u1'));
        $this->assertSame($fallback, $result);
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsBase::REASON_NO_ROLLOUT_MATCH,
            $this->_provider->lastFailureReason()
        );
    }

    public function testCustomBucketingKeyWithoutDistinctIdLogsButReturnsValue() {
        // Audit finding #8: when the bucketing key is non-distinct_id
        // and distinct_id is missing, the SDK should not silently drop
        // exposure — it should surface the problem.
        $errors = array();
        $errorCallback = function ($code, $message) use (&$errors) {
            $errors[] = array($code, $message);
        };
        $provider = new _TestableLocalFlags('token', '2.11.0', $this->_tracker, array(
            'error_callback' => $errorCallback,
            'flags' => array('mode' => 'local'),
        ));
        $provider->setDefinitionsForTest(array(
            'device-flag' => $this->makeFlag('device-flag', array('on' => true), 'device_id'),
        ));

        $result = $provider->getVariant(
            'device-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, 'fallback'),
            array('device_id' => 'd1')
        );
        $this->assertEquals('on', $result->variantKey);
        // Exposure NOT fired (no distinct_id), but the error_callback fired.
        $this->assertCount(0, $this->_captured);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('distinct_id', $errors[0][1]);
    }

    public function testNestedNumericValuesPreserveType() {
        // Audit finding #11: nested ints in object-valued variants
        // must round-trip as ints. PHP's json_decode($x, true) does
        // this by default, so the guarantee is mostly about not
        // accidentally casting along the way.
        $this->_provider->setDefinitionsForTest(array(
            'obj-flag' => $this->makeFlag('obj-flag', array(
                'on' => array('threshold' => 42, 'nested' => array('count' => 7)),
            )),
        ));
        $result = $this->_provider->getVariant(
            'obj-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, null),
            array('distinct_id' => 'u1'),
            false
        );
        $this->assertSame(42, $result->variantValue['threshold']);
        $this->assertSame(7, $result->variantValue['nested']['count']);
    }

    public function testRuntimeRuleEmailContainsMatch() {
        $rule = array(
            'in' => array('gmail', array('var' => 'email')),
        );
        $flag = $this->makeFlag('rt-flag', array('on' => true));
        $flag['ruleset']['rollout'][0]['runtime_evaluation_rule'] = $rule;
        $this->_provider->setDefinitionsForTest(array('rt-flag' => $flag));

        $result = $this->_provider->getVariant(
            'rt-flag',
            new FeatureFlags_MixpanelSelectedVariant(null, false),
            array(
                'distinct_id' => 'u1',
                'custom_properties' => array('email' => 'Alice@GMAIL.com'),
            ),
            false
        );
        $this->assertEquals('on', $result->variantKey);
    }

    public function testRuntimeRuleMissingCustomPropertiesIsNotMatch() {
        $rule = array('in' => array('gmail', array('var' => 'email')));
        $flag = $this->makeFlag('rt-flag', array('on' => true));
        $flag['ruleset']['rollout'][0]['runtime_evaluation_rule'] = $rule;
        $this->_provider->setDefinitionsForTest(array('rt-flag' => $flag));

        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, false);
        $result = $this->_provider->getVariant('rt-flag', $fallback, array('distinct_id' => 'u1'), false);
        $this->assertSame($fallback, $result);
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsBase::REASON_NO_ROLLOUT_MATCH,
            $this->_provider->lastFailureReason()
        );
    }

    public function testIsEnabledReturnsTrueOnlyForBooleanTrueVariantValue() {
        $this->_provider->setDefinitionsForTest(array(
            'bool-flag' => $this->makeFlag('bool-flag', array('on' => true)),
            'str-flag'  => $this->makeFlag('str-flag', array('on' => 'yes')),
        ));
        $this->assertTrue($this->_provider->isEnabled('bool-flag', array('distinct_id' => 'u1')));
        $this->assertFalse($this->_provider->isEnabled('str-flag', array('distinct_id' => 'u1')));
        $this->assertFalse($this->_provider->isEnabled('missing-flag', array('distinct_id' => 'u1')));
    }

    public function testGetAllVariantsExcludesFallbacks() {
        $this->_provider->setDefinitionsForTest(array(
            'a' => $this->makeFlag('a', array('on' => 1)),
            'b' => $this->makeFlag('b', array('on' => 2), 'distinct_id', 0.0), // 0% rollout
        ));
        $all = $this->_provider->getAllVariants(array('distinct_id' => 'u1'));
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayNotHasKey('b', $all);
    }
}
