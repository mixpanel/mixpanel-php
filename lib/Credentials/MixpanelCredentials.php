<?php

/**
 * Interface for Mixpanel authentication credentials.
 * All credential types must implement this interface to provide
 * authentication headers for API requests.
 */
interface Credentials_MixpanelCredentials {

    /**
     * Get the authentication headers to be sent with API requests
     * @return array Associative array of header name => header value
     */
    public function getAuthHeaders();

    /**
     * Check if these credentials are deprecated
     * @return bool True if credentials type is deprecated
     */
    public function isDeprecated();

    /**
     * Get the project ID associated with these credentials
     * @return string|null The project ID or null if not applicable
     */
    public function getProjectId();
}
