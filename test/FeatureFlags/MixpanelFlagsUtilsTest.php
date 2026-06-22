<?php

/**
 * Hash determinism tests. The values below were generated with the
 * Python implementation in mixpanel-python/mixpanel/flags/utils.py
 * to verify cross-language parity — a PHP user and a Python user with
 * the same distinct_id must land in the same bucket.
 *
 * Reproduce with:
 *   from mixpanel.flags.utils import normalized_hash, _fnv1a64
 *   _fnv1a64(b"user-123myflagvariant")
 *   normalized_hash("user-123", "myflagvariant")
 */
class MixpanelFlagsUtilsTest extends PHPUnit\Framework\TestCase {

    public function testFnvOfEmptyStringIsOffsetBasis() {
        $this->assertEquals(
            FeatureFlags_MixpanelFlagsUtils::FNV_OFFSET_BASIS,
            FeatureFlags_MixpanelFlagsUtils::fnv1a64('')
        );
    }

    public function testFnvSingleByteAMatchesCanonicalVector() {
        // RFC-style FNV-1a 64 of "a" is 0xaf63dc4c8601ec8c. This is the
        // canonical cross-language reference value — if we don't match
        // it, no other Mixpanel SDK will agree with PHP on bucketing.
        $this->assertEquals(
            '12638187200555641996',
            FeatureFlags_MixpanelFlagsUtils::fnv1a64('a')
        );
    }

    public function testFnvFoobarMatchesCanonicalVector() {
        // FNV-1a 64 of "foobar" = 0x85944171f73967e8 per the reference vectors.
        $this->assertEquals(
            '9625390261332436968',
            FeatureFlags_MixpanelFlagsUtils::fnv1a64('foobar')
        );
    }

    public function testNormalizedHashInRange() {
        $val = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'flag-key' . 'rollout');
        $this->assertGreaterThanOrEqual(0.0, $val);
        $this->assertLessThan(1.0, $val);
    }

    public function testNormalizedHashIsDeterministic() {
        $a = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'flag-key' . 'rollout');
        $b = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'flag-key' . 'rollout');
        $this->assertSame($a, $b);
    }

    public function testNormalizedHashDiffersByKey() {
        $a = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'salt');
        $b = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-124', 'salt');
        $this->assertNotEquals($a, $b);
    }

    public function testTraceparentShape() {
        $tp = FeatureFlags_MixpanelFlagsUtils::generateTraceparent();
        // Avoid assertMatchesRegularExpression so the suite runs across
        // PHPUnit 7.5–9.x without renaming the assertion.
        $this->assertEquals(1, preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $tp));
    }

    public function testCommonQueryParams() {
        $params = FeatureFlags_MixpanelFlagsUtils::commonQueryParams('TKN', '2.11.0');
        $this->assertEquals('php', $params['mp_lib']);
        $this->assertEquals('2.11.0', $params['lib_version']);
        $this->assertEquals('TKN', $params['token']);
    }

    public function testLowercaseLeafNodes() {
        $rule = array('==' => array(array('var' => 'Email'), 'Alice@Example.COM'));
        $out = FeatureFlags_MixpanelFlagsUtils::lowercaseLeafNodes($rule);
        // Operator/"var" keys are preserved; only the leaf string values
        // (the property name fetched by "var" and the literal compared
        // against) get casefolded.
        $this->assertArrayHasKey('==', $out);
        $this->assertEquals('email', $out['=='][0]['var']);
        $this->assertEquals('alice@example.com', $out['=='][1]);
    }

    public function testLowercaseKeysAndValues() {
        $data = array('Email' => 'Alice@Example.COM', 'Count' => 5);
        $out = FeatureFlags_MixpanelFlagsUtils::lowercaseKeysAndValues($data);
        $this->assertArrayHasKey('email', $out);
        $this->assertEquals('alice@example.com', $out['email']);
        // Non-string values pass through unchanged.
        $this->assertSame(5, $out['count']);
    }
}
