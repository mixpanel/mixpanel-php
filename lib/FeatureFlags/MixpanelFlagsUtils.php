<?php

declare(strict_types=1);

/**
 * Static helpers shared by the local and remote feature-flag providers:
 * the FNV-1a 64-bit hash used for cohort/variant bucketing, a W3C
 * traceparent generator for distributed tracing, and the common
 * query-string parameters appended to every flags HTTP request.
 */
class FeatureFlags_MixpanelFlagsUtils {

    const EXPOSURE_EVENT = '$experiment_started';

    /**
     * Returns the hash of ($key . $salt) normalized to a [0.0, 1.0)
     * float by taking (hash mod 100) / 100. Must match the equivalent
     * function in every other Mixpanel SDK so the same user lands in
     * the same bucket across languages.
     *
     * Implementation note: we use PHP's built-in FNV-1a 64 from ext-hash
     * (bundled in core, present on every PHP install) and compute the
     * mod-100 by walking the 8 raw bytes. That avoids needing bcmath
     * or any other big-int facility while staying exact on 32-bit PHP.
     */
    public static function normalizedHash(string $key, string $salt): float {
        $raw = hash('fnv1a64', $key . $salt, true);  // 8 raw bytes, big-endian
        // (uint64 mod 100), byte by byte. Each intermediate
        // (mod*256 + byte) is at most 99*256 + 255 = 25599 — fits in
        // 32-bit signed int on every PHP build, no overflow.
        $mod = 0;
        for ($i = 0; $i < 8; $i++) {
            $mod = ($mod * 256 + ord($raw[$i])) % 100;
        }
        return $mod / 100.0;
    }

    /**
     * Generate a W3C traceparent header. Format: 00-<32 hex>-<16 hex>-01.
     * The values are random per call; their only purpose is to give the
     * Mixpanel server a correlation id for the request.
     */
    public static function generateTraceparent(): string {
        $traceId = bin2hex(random_bytes(16));
        $spanId  = bin2hex(random_bytes(8));
        return '00-' . $traceId . '-' . $spanId . '-01';
    }

    /**
     * The mp_lib / lib_version / token tuple that every flags request
     * sends. lib_version is read from a constant on the main Mixpanel
     * class so it tracks the package release.
     */
    public static function commonQueryParams(string $token, string $version): array {
        return array(
            'mp_lib'      => 'php',
            'lib_version' => $version,
            'token'       => $token,
        );
    }

    /**
     * Recursively casefold (lowercase) only the leaf string nodes of a
     * structure, leaving the operator/keyword keys of a JSON-Logic rule
     * intact. Used on the rule side of runtime evaluation so that
     * comparisons against context values are case-insensitive without
     * mangling operator names like "in" or "==".
     *
     * INVARIANT: this MUST be applied to the rule in lockstep with
     * {@link self::lowercaseKeysAndValues} on the runtime parameters.
     * `lowercaseLeafNodes` lowercases the operand values inside
     * `{"var": "Email"}` to `"email"`; if the parameter keys aren't
     * also lowercased, JSON-Logic's `var` lookup misses and every
     * runtime-rule flag silently falls back. The two functions live
     * together and must stay in sync.
     */
    public static function lowercaseLeafNodes(mixed $value): mixed {
        if (is_string($value)) {
            return mb_strtolower($value, 'UTF-8');
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $sub) {
                $out[$key] = self::lowercaseLeafNodes($sub);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Recursively casefold both keys and string values. Used on the
     * runtime-parameters side (custom_properties) so that user-supplied
     * "Email"/"EMAIL"/"email" all collide with the same rule operand.
     */
    public static function lowercaseKeysAndValues(mixed $value): mixed {
        if (is_string($value)) {
            return mb_strtolower($value, 'UTF-8');
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $sub) {
                $newKey = is_string($key) ? mb_strtolower($key, 'UTF-8') : $key;
                $out[$newKey] = self::lowercaseKeysAndValues($sub);
            }
            return $out;
        }
        return $value;
    }
}
