<?php

declare(strict_types=1);

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
     * Evaluation mode values for the `mode` config key. These constants
     * give callers an IDE-checkable, grep-able alternative to bare string
     * literals. The raw strings remain valid input — these are exact aliases.
     */
    const MODE_LOCAL  = 'local';
    const MODE_REMOTE = 'remote';

    private FeatureFlags_MixpanelFlagsBase $_provider;

    /** One of the MODE_* constants. */
    private string $_mode;

    public function __construct(string $token, string $version, callable $tracker, array $options) {
        // No extension checks — FNV-1a hashing uses PHP's built-in
        // ext-hash (bundled in core), case folding uses
        // symfony/polyfill-mbstring (composer dep), HTTP uses the
        // existing CurlConsumer which already runtime-checks ext-curl.
        $flagsOpts = isset($options['flags']) && is_array($options['flags']) ? $options['flags'] : array();
        if (isset($flagsOpts['mode'])) {
            $requested = strtolower((string) $flagsOpts['mode']);
            if ($requested !== self::MODE_LOCAL && $requested !== self::MODE_REMOTE) {
                // Fail loudly on typos ('lcoal' -> silently falls through to remote is a debug trap).
                throw new InvalidArgumentException(
                    "Invalid flags 'mode' option: " . var_export($flagsOpts['mode'], true) .
                    ". Expected '" . self::MODE_LOCAL . "' or '" . self::MODE_REMOTE . "'."
                );
            }
            $this->_mode = $requested;
        } else {
            $this->_mode = self::MODE_REMOTE;
        }

        if ($this->_mode === self::MODE_LOCAL) {
            $this->_provider = new FeatureFlags_MixpanelLocalFlags($token, $version, $tracker, $options);
        } else {
            $this->_provider = new FeatureFlags_MixpanelRemoteFlags($token, $version, $tracker, $options);
        }
    }

    public function __destruct() {
        try {
            $this->shutdown();
        } catch (\Throwable $t) {
            // Swallow: destructors run during shutdown/fatal-error paths where
            // throwing could mask the original error or hit a partially-torn-down
            // interpreter state.
        }
    }

    public function getMode(): string {
        return $this->_mode;
    }

    public function getProvider(): FeatureFlags_MixpanelFlagsBase {
        return $this->_provider;
    }

    /**
     * Fetch flag definitions from the server. Local mode only; no-op
     * (returns true) in remote mode.
     */
    public function loadDefinitions(): bool {
        if ($this->_provider instanceof FeatureFlags_MixpanelLocalFlags) {
            return $this->_provider->loadDefinitions();
        }
        return true;
    }

    public function areFlagsReady(): bool {
        if ($this->_provider instanceof FeatureFlags_MixpanelLocalFlags) {
            return $this->_provider->areFlagsReady();
        }
        return true;
    }

    public function lastSyncedAt(): ?int {
        if ($this->_provider instanceof FeatureFlags_MixpanelLocalFlags) {
            return $this->_provider->lastSyncedAt();
        }
        return null;
    }

    public function getVariant(
        string $flagKey,
        FeatureFlags_MixpanelSelectedVariant $fallback,
        array $context,
        bool $reportExposure = true
    ): FeatureFlags_MixpanelSelectedVariant {
        return $this->_provider->getVariant($flagKey, $fallback, $context, $reportExposure);
    }

    public function getVariantValue(string $flagKey, mixed $fallbackValue, array $context): mixed {
        return $this->_provider->getVariantValue($flagKey, $fallbackValue, $context);
    }

    public function isEnabled(string $flagKey, array $context): bool {
        return $this->_provider->isEnabled($flagKey, $context);
    }

    public function getAllVariants(array $context): array {
        return $this->_provider->getAllVariants($context);
    }

    public function trackExposure(
        string $flagKey,
        FeatureFlags_MixpanelSelectedVariant $variant,
        array $context
    ): void {
        $this->_provider->trackExposure($flagKey, $variant, $context);
    }

    public function shutdown(): void {
        $this->_provider->shutdown();
    }
}
