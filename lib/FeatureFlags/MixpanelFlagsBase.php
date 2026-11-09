<?php

declare(strict_types=1);

require_once(dirname(__FILE__) . "/../Base/MixpanelBase.php");
require_once(dirname(__FILE__) . "/MixpanelFlagsUtils.php");
require_once(dirname(__FILE__) . "/MixpanelSelectedVariant.php");

/**
 * Shared HTTP / exposure-tracking plumbing for the local and remote
 * feature-flag providers. When a getVariant call falls through to the
 * caller's fallback, the reason is attached to the returned
 * SelectedVariant via its `fallbackReason` field (see
 * FeatureFlags_MixpanelSelectedVariant::REASON_*) — addressing audit
 * finding #1 (every other Mixpanel SDK collapses three distinct
 * failure modes into "flag not found").
 */
abstract class FeatureFlags_MixpanelFlagsBase extends Base_MixpanelBase {

    protected string $_token;

    protected string $_version;

    /** @var callable a closure that calls $mp->track($eventName, $properties + ['distinct_id' => $distinctId]) */
    // Note: 'callable' is not a valid PHP property type. Kept untyped with phpdoc.
    protected $_tracker;

    protected string $_apiHost;

    /** Seconds. */
    protected int $_requestTimeout;

    public function __construct(string $token, string $version, callable $tracker, array $options) {
        parent::__construct($options);
        $this->_token = $token;
        $this->_version = $version;
        $this->_tracker = $tracker;

        $flagsOpts = isset($options['flags']) && is_array($options['flags']) ? $options['flags'] : array();
        // Precedence: flags.api_host (explicit override) > top-level
        // `host` (shared with the event/people consumers) > default.
        // This means EU/India endpoints or local mocks only need to be
        // configured once at the SDK level.
        if (isset($flagsOpts['api_host'])) {
            $this->_apiHost = (string) $flagsOpts['api_host'];
        } elseif (isset($options['host'])) {
            $this->_apiHost = (string) $options['host'];
        } else {
            $this->_apiHost = 'api.mixpanel.com';
        }
        $this->_requestTimeout = isset($flagsOpts['request_timeout_in_seconds']) ? (int) $flagsOpts['request_timeout_in_seconds'] : 10;
    }

    /** Release any held resources. Subclasses override to close cURL handles. */
    public function shutdown(): void {
        // default: nothing held
    }

    /**
     * Perform an authenticated GET against the flags API and return the
     * decoded JSON body. Throws on HTTP error or transport failure —
     * the remote provider catches this and surfaces the failure to the
     * caller (audit finding #7: don't silently swallow backend errors).
     *
     * @param string $path e.g. "/flags" or "/flags/definitions"
     * @param array $query  query params (merged with token / mp_lib / lib_version)
     * @return array  decoded JSON
     * @throws Exception on HTTP non-2xx or cURL transport error
     */
    protected function _httpGet(string $path, array $query = array()): array {
        // Match the guard AbstractConsumer already applies. On minimal
        // PHP builds without ext-curl (some Alpine images, custom
        // builds) curl_init would fatal with an unresolved function
        // and give no hint that curl is what's missing.
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'Mixpanel feature flags require the PHP curl extension (ext-curl), which is not loaded.'
            );
        }

        $params = array_merge(
            FeatureFlags_MixpanelFlagsUtils::commonQueryParams($this->_token, $this->_version),
            $query
        );
        $url = 'https://' . $this->_apiHost . $path . '?' . http_build_query($params);

        $headers = array(
            // GET requests have no body — describe what we accept, not what we're sending.
            'Accept: application/json',
            'X-Scheme: https',
            'X-Forwarded-Proto: https',
            'traceparent: ' . FeatureFlags_MixpanelFlagsUtils::generateTraceparent(),
            // Basic Auth with token as username, empty password.
            'Authorization: Basic ' . base64_encode($this->_token . ':'),
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Single timeout budget for the whole call, matching the other
        // server SDKs (httpx in Python, Net::HTTP in Ruby, http.Client
        // in Go all use one timeout covering connect + read).
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->_requestTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->_requestTimeout);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Don't call curl_close($ch): PHP 8.0+ closes on unset and 8.5
        // marks the explicit call as deprecated.
        unset($ch);

        if ($errno !== 0) {
            // Carry the cURL errno as the exception code so it reaches
            // the user's error_callback intact.
            throw new Exception('Mixpanel flags HTTP transport error (' . $errno . '): ' . $errmsg, $errno);
        }
        if ($status < 200 || $status >= 300) {
            throw new Exception(
                'Mixpanel flags HTTP ' . $status . ': ' . substr((string) $body, 0, 500),
                (int) $status
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new Exception('Mixpanel flags response was not valid JSON: ' . substr((string) $body, 0, 200));
        }
        return $decoded;
    }

    /**
     * Build the standard $experiment_started property set.
     *
     * Local mode supplies $latencyMs (derived from microtime around the
     * in-process eval); remote mode supplies $startTime / $endTime
     * (microtime floats around the HTTP call) and we derive latency
     * here and emit ISO-8601 "Variant fetch start time" / "complete
     * time" strings to match the Python, Ruby, Go, Java, Node, and
     * Browser SDKs in remote mode.
     *
     * @param string $flagKey
     * @param FeatureFlags_MixpanelSelectedVariant $variant
     * @param string $evaluationMode 'local' or 'remote'
     * @param float|null $latencyMs   when supplied directly (local mode)
     * @param float|null $startTime   microtime(true) before the HTTP call (remote mode)
     * @param float|null $endTime     microtime(true) after the HTTP call (remote mode)
     * @return array
     */
    protected function _buildExposureProperties(
        string $flagKey,
        FeatureFlags_MixpanelSelectedVariant $variant,
        string $evaluationMode,
        ?float $latencyMs = null,
        ?float $startTime = null,
        ?float $endTime = null
    ): array {
        $properties = array(
            'Experiment name'        => $flagKey,
            'Variant name'           => $variant->variantKey,
            '$experiment_type'       => 'feature_flag',
            'Flag evaluation mode'   => $evaluationMode,
            '$experiment_id'         => $variant->experimentId,
            '$is_experiment_active'  => $variant->isExperimentActive,
            '$is_qa_tester'          => $variant->isQaTester,
        );
        if ($startTime !== null && $endTime !== null) {
            $properties['Variant fetch start time']    = self::_formatIsoMicrotime($startTime);
            $properties['Variant fetch complete time'] = self::_formatIsoMicrotime($endTime);
            if ($latencyMs === null) {
                $latencyMs = ($endTime - $startTime) * 1000.0;
            }
        }
        if ($latencyMs !== null) {
            $properties['Variant fetch latency (ms)'] = $latencyMs;
        }
        return $properties;
    }

    /**
     * Format a microtime(true) float as a local-time ISO-8601 string
     * with microsecond precision, matching Python's
     * `datetime.now().isoformat()` output shape so cross-SDK analytics
     * keyed on these properties parse consistently.
     */
    private static function _formatIsoMicrotime(float $microtime): string {
        $seconds = (int) floor($microtime);
        $micros  = (int) round(($microtime - $seconds) * 1000000);
        if ($micros >= 1000000) {
            // round-up edge case at the second boundary
            $seconds += 1;
            $micros   = 0;
        }
        return date('Y-m-d\TH:i:s', $seconds) . '.' . sprintf('%06d', $micros);
    }

    /**
     * Dispatch the exposure event. Routes through the configured
     * tracker (normally Mixpanel::track), which means the event lands
     * in the same buffered queue as every other event — flushed at
     * end of request. That sidesteps audit finding #3 (synchronous
     * exposure blocks eval) on PHP without us needing a separate
     * async path.
     *
     * @param string $flagKey
     * @param FeatureFlags_MixpanelSelectedVariant $variant
     * @param array $context
     * @param string $evaluationMode
     * @param float|null $latencyMs
     */
    protected function _trackExposure(
        string $flagKey,
        FeatureFlags_MixpanelSelectedVariant $variant,
        array $context,
        string $evaluationMode,
        ?float $latencyMs = null,
        ?float $startTime = null,
        ?float $endTime = null
    ): void {
        if (!isset($context['distinct_id']) || $context['distinct_id'] === '' || $context['distinct_id'] === null) {
            // Don't drop silently — surface to the error_callback so the
            // caller learns why their exposure analytics are empty
            // (audit finding #8).
            $this->_handleError(
                'mixpanel-flags',
                "Cannot track exposure for flag '{$flagKey}': distinct_id missing from context"
            );
            return;
        }
        $distinctId = $context['distinct_id'];
        $properties = $this->_buildExposureProperties(
            $flagKey, $variant, $evaluationMode, $latencyMs, $startTime, $endTime
        );

        try {
            call_user_func($this->_tracker, $distinctId, FeatureFlags_MixpanelFlagsUtils::EXPOSURE_EVENT, $properties);
        } catch (Exception $e) {
            $this->_handleError($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Forward an error to the user-supplied error_callback if one was
     * configured (matches the existing AbstractConsumer convention).
     *
     * `$code` is a union because callers pass HTTP status codes (int),
     * literal string codes ('mixpanel-flags'), Throwable::getCode()
     * (which is int on Exception but string on PDOException), or 0/null
     * placeholders.
     */
    protected function _handleError(int|string|null $code, string $message): void {
        if (isset($this->_options['error_callback']) && is_callable($this->_options['error_callback'])) {
            call_user_func($this->_options['error_callback'], $code, $message);
        } elseif ($this->_debug()) {
            $this->_log('[flags] ' . $message);
        }
    }

    abstract public function getVariant(
        string $flagKey,
        FeatureFlags_MixpanelSelectedVariant $fallback,
        array $context,
        bool $reportExposure = true
    ): FeatureFlags_MixpanelSelectedVariant;

    public function getVariantValue(string $flagKey, mixed $fallbackValue, array $context): mixed {
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, $fallbackValue);
        $variant = $this->getVariant($flagKey, $fallback, $context);
        return $variant->variantValue;
    }

    /**
     * Returns true only when the variant value is the strict boolean
     * `true`. Non-boolean truthy values (`1`, `"true"`, `"on"`, ...)
     * intentionally return false — they signal a type mismatch on a
     * flag that was expected to be a Mixpanel Feature Gate, and we
     * fail closed rather than accept an ambiguous "on" signal.
     * Matches the strict semantics of isEnabled in the Node, Ruby,
     * Python, and Go SDKs.
     */
    public function isEnabled(string $flagKey, array $context): bool {
        return $this->getVariantValue($flagKey, false, $context) === true;
    }

    /**
     * Manually track exposure for a previously evaluated variant. Used
     * with getAllVariants() so callers can record exposure only for the
     * flags they actually consume.
     */
    public function trackExposure(
        string $flagKey,
        FeatureFlags_MixpanelSelectedVariant $variant,
        array $context
    ): void {
        $mode = $this->_evaluationMode();
        $this->_trackExposure($flagKey, $variant, $context, $mode);
    }

    abstract protected function _evaluationMode(): string;
}
