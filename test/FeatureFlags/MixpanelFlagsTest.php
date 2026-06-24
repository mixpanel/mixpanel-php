<?php

class MixpanelFlagsTest extends PHPUnit\Framework\TestCase {

    public function testMixpanelInstanceHasNoFlagsByDefault() {
        $mp = new Mixpanel('token-no-flags');
        $this->assertNull($mp->flags);
    }

    public function testMixpanelInstanceConstructsFlagsWhenConfigured() {
        $mp = new Mixpanel('token-with-flags', array(
            'flags' => array('mode' => 'remote'),
        ));
        $this->assertInstanceOf('FeatureFlags_MixpanelFlags', $mp->flags);
        $this->assertEquals('remote', $mp->flags->getMode());
        $this->assertInstanceOf('FeatureFlags_MixpanelRemoteFlags', $mp->flags->getProvider());
    }

    public function testLocalModeProvider() {
        $mp = new Mixpanel('token-local', array(
            'flags' => array('mode' => 'local'),
        ));
        $this->assertEquals('local', $mp->flags->getMode());
        $this->assertInstanceOf('FeatureFlags_MixpanelLocalFlags', $mp->flags->getProvider());
        // Before loadDefinitions runs, the local provider is not ready.
        $this->assertFalse($mp->flags->areFlagsReady());
        $this->assertNull($mp->flags->lastSyncedAt());
    }

    public function testRemoteModeReportsReadyAndNoSync() {
        $mp = new Mixpanel('token-r', array(
            'flags' => array('mode' => 'remote'),
        ));
        // Remote mode has no concept of "definitions ready" — it's
        // always ready to make a call. loadDefinitions is a no-op
        // returning true. lastSyncedAt is null.
        $this->assertTrue($mp->flags->areFlagsReady());
        $this->assertTrue($mp->flags->loadDefinitions());
        $this->assertNull($mp->flags->lastSyncedAt());
    }

    public function testRemoteModeNeedsRefreshAlwaysFalseAndRefreshIsNoOp() {
        // Remote mode has nothing cached client-side, so the
        // staleness/refresh API simply reports "never needs refresh"
        // and refresh() is a no-op returning true. This lets callers
        // write mode-agnostic code that targets either provider.
        $mp = new Mixpanel('token-r2', array(
            'flags' => array('mode' => 'remote', 'refresh_interval_in_seconds' => 60),
        ));
        $this->assertFalse($mp->flags->needsRefresh());
        $this->assertTrue($mp->flags->refresh());
    }

    public function testDefaultModeIsRemote() {
        $mp = new Mixpanel('token-default', array(
            'flags' => array(),
        ));
        $this->assertEquals('remote', $mp->flags->getMode());
    }

    public function testModeConstantsAreStable() {
        // The MODE_* constants are part of the public API; lock their
        // string values so a refactor can't silently change them and
        // break callers that compare against them.
        $this->assertSame('local',  FeatureFlags_MixpanelFlags::MODE_LOCAL);
        $this->assertSame('remote', FeatureFlags_MixpanelFlags::MODE_REMOTE);
    }

    public function testModeAcceptsConstantOrStringLiteral() {
        $viaConstant = new Mixpanel('token-c', array(
            'flags' => array('mode' => FeatureFlags_MixpanelFlags::MODE_LOCAL),
        ));
        $viaLiteral = new Mixpanel('token-l', array(
            'flags' => array('mode' => 'local'),
        ));
        $this->assertEquals('local', $viaConstant->flags->getMode());
        $this->assertEquals('local', $viaLiteral->flags->getMode());
        $this->assertInstanceOf('FeatureFlags_MixpanelLocalFlags', $viaConstant->flags->getProvider());
        $this->assertInstanceOf('FeatureFlags_MixpanelLocalFlags', $viaLiteral->flags->getProvider());
    }

    public function testFlagsApiHostInheritsFromTopLevelHost() {
        // If the caller already pointed the SDK at a regional or mock
        // endpoint via the top-level `host` option, the flags module
        // should pick that up instead of going to api.mixpanel.com.
        $mp = new Mixpanel('token-eu', array(
            'host'  => 'api-eu.mixpanel.com',
            'flags' => array('mode' => 'remote'),
        ));
        $provider = $mp->flags->getProvider();
        $reflection = new ReflectionClass('FeatureFlags_MixpanelFlagsBase');
        $apiHostProp = $reflection->getProperty('_apiHost');
        if (PHP_VERSION_ID < 80100) {
            $apiHostProp->setAccessible(true);
        }
        $this->assertEquals('api-eu.mixpanel.com', $apiHostProp->getValue($provider));
    }

    public function testFlagsApiHostExplicitOverrideWinsOverTopLevelHost() {
        $mp = new Mixpanel('token-mix', array(
            'host'  => 'api-eu.mixpanel.com',
            'flags' => array('mode' => 'remote', 'api_host' => 'localhost:8080'),
        ));
        $provider = $mp->flags->getProvider();
        $reflection = new ReflectionClass('FeatureFlags_MixpanelFlagsBase');
        $apiHostProp = $reflection->getProperty('_apiHost');
        if (PHP_VERSION_ID < 80100) {
            $apiHostProp->setAccessible(true);
        }
        $this->assertEquals('localhost:8080', $apiHostProp->getValue($provider));
    }
}
