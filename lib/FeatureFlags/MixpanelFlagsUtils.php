<?php

/**
 * Static helpers shared by the local and remote feature-flag providers:
 * the FNV-1a 64-bit hash used for cohort/variant bucketing, a W3C
 * traceparent generator for distributed tracing, and the common
 * query-string parameters appended to every flags HTTP request.
 */
class FeatureFlags_MixpanelFlagsUtils {

    const EXPOSURE_EVENT = '$experiment_started';

    // FNV-1a 64-bit constants, as decimal strings so bcmath can use them
    // on every PHP build regardless of int width.
    // FNV_OFFSET_BASIS = 0xCBF29CE484222325
    // FNV_PRIME        = 0x100000001B3
    // MASK64           = 2^64
    const FNV_OFFSET_BASIS = '14695981039346656037';
    const FNV_PRIME        = '1099511628211';
    const MASK64           = '18446744073709551616';

    /**
     * Returns the hash of ($key . $salt) normalized to a [0.0, 1.0)
     * float by taking (hash mod 100) / 100. Must match the equivalent
     * function in every other Mixpanel SDK so the same user lands in
     * the same bucket across languages.
     *
     * @param string $key
     * @param string $salt
     * @return float
     */
    public static function normalizedHash($key, $salt) {
        $hashValue = self::fnv1a64($key . $salt);
        $mod = (int) bcmod($hashValue, '100');
        return $mod / 100.0;
    }

    /**
     * FNV-1a 64-bit hash. Returns the digest as a decimal string so
     * callers can safely modulo even on 32-bit PHP builds.
     *
     * @param string $data raw bytes (PHP strings are byte sequences)
     * @return string decimal representation of the 64-bit unsigned digest
     */
    public static function fnv1a64($data) {
        $hash = self::FNV_OFFSET_BASIS;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            // hash ^= byte: XOR with a byte affects only the low 8 bits.
            // Pull out the low byte, XOR, and stitch the value back.
            $lowByte = (int) bcmod($hash, '256');
            $newLowByte = $lowByte ^ $byte;
            $hash = bcadd(bcsub($hash, (string) $lowByte), (string) $newLowByte);

            // hash = (hash * FNV_PRIME) mod 2^64
            $hash = bcmod(bcmul($hash, self::FNV_PRIME), self::MASK64);
        }
        return $hash;
    }

    /**
     * Generate a W3C traceparent header. Format: 00-<32 hex>-<16 hex>-01.
     * The values are random per call; their only purpose is to give the
     * Mixpanel server a correlation id for the request.
     *
     * @return string
     */
    public static function generateTraceparent() {
        $traceId = bin2hex(random_bytes(16));
        $spanId  = bin2hex(random_bytes(8));
        return '00-' . $traceId . '-' . $spanId . '-01';
    }

    /**
     * The mp_lib / lib_version / token tuple that every flags request
     * sends. lib_version is read from a constant on the main Mixpanel
     * class so it tracks the package release.
     *
     * @param string $token
     * @param string $version
     * @return array
     */
    public static function commonQueryParams($token, $version) {
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
     * @param mixed $value
     * @return mixed
     */
    public static function lowercaseLeafNodes($value) {
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
     *
     * @param mixed $value
     * @return mixed
     */
    public static function lowercaseKeysAndValues($value) {
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
