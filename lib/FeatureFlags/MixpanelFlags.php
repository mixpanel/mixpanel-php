<?php

require_once(dirname(__FILE__) . "/MixpanelLocalFlags.php");
require_once(dirname(__FILE__) . "/MixpanelRemoteFlags.php");

/**
 * Public entry point reached via $mp->flags. Decides between the local
 * and remote provider based on the `mode` option, owns the underlying
 * provider's lifecycle, and forwards every public method to it.
 *
 * Usage:
 *
 *   $mp = Mixpanel::getInstance('TOKEN', array(
 *       'flags' => array('mode' => FeatureFlags_MixpanelFlags::MODE_REMOTE),
 *   ));
 *   $enabled = $mp->flags->isEnabled('my-flag', array(
 *       'distinct_id' => 'user-123',
 *   ));
 */
class FeatureFlags_MixpanelFlags {

    /**
     * Evaluation mode values for the `mode` config key. PHP 7.x has no
     * native enums, but these constants give callers an IDE-checkable,
     * grep-able alternative to bare string literals. The raw strings
     * remain valid input — these are exact aliases.
     */
    const MODE_LOCAL  = 'local';
    const MODE_REMOTE = 'remote';

    /** @var FeatureFlags_MixpanelFlagsBase */
    private $_provider;

    /** @var string one of the MODE_* constants */
    private $_mode;

    public function __construct($token, $version, $tracker, array $options) {
        // Check required extensions only when flags are actually
        // enabled — pre-flags the SDK degraded gracefully on hosts
        // without bcmath/curl/mbstring, and we want to preserve that
        // for tracking-only callers.
        $missing = array();
        foreach (array('bcmath', 'curl', 'mbstring') as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        if (!empty($missing)) {
            throw new Exception(
                'The Mixpanel feature flags module requires the following PHP extension(s): '
                . implode(', ', $missing)
            );
        }

        $flagsOpts = isset($options['flags']) && is_array($options['flags']) ? $options['flags'] : array();
        $this->_mode = isset($flagsOpts['mode']) ? strtolower((string) $flagsOpts['mode']) : self::MODE_REMOTE;

        if ($this->_mode === self::MODE_LOCAL) {
            $this->_provider = new FeatureFlags_MixpanelLocalFlags($token, $version, $tracker, $options);
        } else {
            $this->_provider = new FeatureFlags_MixpanelRemoteFlags($token, $version, $tracker, $options);
        }
    }

    public function __destruct() {
        $this->shutdown();
    }

    /** @return string 'local' or 'remote' */
    public function getMode() {
        return $this->_mode;
    }

    /** @return FeatureFlags_MixpanelFlagsBase */
    public function getProvider() {
        return $this->_provider;
    }

    /**
     * Fetch flag definitions from the server. Local mode only; no-op
     * (returns true) in remote mode.
     *
     * @return bool true on success
     */
    public function loadDefinitions() {
        if ($this->_provider instanceof FeatureFlags_MixpanelLocalFlags) {
            return $this->_provider->loadDefinitions();
        }
        return true;
    }

    /** @return bool */
    public function areFlagsReady() {
        if ($this->_provider instanceof FeatureFlags_MixpanelLocalFlags) {
            return $this->_provider->areFlagsReady();
        }
        return true;
    }

    /** @return int|null */
    public function lastSyncedAt() {
        if ($this->_provider instanceof FeatureFlags_MixpanelLocalFlags) {
            return $this->_provider->lastSyncedAt();
        }
        return null;
    }

    /** @return string one of the FeatureFlags_MixpanelFlagsBase::REASON_* constants */
    public function lastFailureReason() {
        return $this->_provider->lastFailureReason();
    }

    public function getVariant($flagKey, FeatureFlags_MixpanelSelectedVariant $fallback, array $context, $reportExposure = true) {
        return $this->_provider->getVariant($flagKey, $fallback, $context, $reportExposure);
    }

    public function getVariantValue($flagKey, $fallbackValue, array $context) {
        return $this->_provider->getVariantValue($flagKey, $fallbackValue, $context);
    }

    public function isEnabled($flagKey, array $context) {
        return $this->_provider->isEnabled($flagKey, $context);
    }

    public function getAllVariants(array $context) {
        return $this->_provider->getAllVariants($context);
    }

    public function trackExposure($flagKey, FeatureFlags_MixpanelSelectedVariant $variant, array $context) {
        $this->_provider->trackExposure($flagKey, $variant, $context);
    }

    public function shutdown() {
        if ($this->_provider !== null) {
            $this->_provider->shutdown();
        }
    }
}
