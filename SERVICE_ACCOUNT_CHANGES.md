# Service Account Support Implementation

This document summarizes the changes made to add Service Account authentication support and deprecate API keys/secrets, similar to [mixpanel-python PR #175](https://github.com/mixpanel/mixpanel-python/pull/175).

## Overview

Service Account authentication has been added as the recommended method for server-to-server integration with Mixpanel. Legacy authentication methods (project tokens and API secrets) are now deprecated.

## New Files Created

### Credentials Classes
- **lib/Credentials/MixpanelCredentials.php** - Interface defining credential requirements
- **lib/Credentials/ServiceAccountCredentials.php** - Service Account implementation (recommended)
- **lib/Credentials/APISecretCredentials.php** - API Secret implementation (deprecated)

### Documentation
- **MIGRATION.md** - Comprehensive migration guide from tokens to Service Accounts
- **examples/service_account_example.php** - Example showing Service Account usage

### Tests
- **test/Credentials/ServiceAccountCredentialsTest.php** - Tests for Service Account credentials
- **test/Credentials/APISecretCredentialsTest.php** - Tests for API Secret credentials
- **test/MixpanelCredentialsTest.php** - Integration tests for Mixpanel class with credentials

## Modified Files

### Core Library

#### lib/Mixpanel.php
- Added imports for credential classes
- Updated constructor to accept `Credentials_MixpanelCredentials` or legacy token string
- Added deprecation warnings for token-based authentication
- Updated `getInstance()` to support credentials with proper singleton keying
- Maintained backward compatibility with existing token-based code

#### lib/Producers/MixpanelBaseProducer.php
- Added `$_credentials` property to store credential objects
- Updated constructor to extract and store credentials from options
- Added `getCredentials()` method for accessing credentials
- Enhanced debug logging to show credential-based authentication

#### lib/ConsumerStrategies/CurlConsumer.php
- Added import for MixpanelCredentials interface
- Added `_getAuthHeaders()` method to build authentication headers from credentials
- Updated `_execute()` to include auth headers in cURL requests
- Updated `_buildExecCommand()` to include auth headers in forked cURL commands

#### lib/ConsumerStrategies/SocketConsumer.php
- Added import for MixpanelCredentials interface
- Updated `persist()` method to include authentication headers in socket requests
- Authentication headers are added to HTTP request before content-length

### Documentation

#### README.md
- Added "Authentication" section explaining all authentication methods
- Updated all code examples to show Service Account usage as primary method
- Added deprecation warnings for legacy authentication methods
- Updated changelog with version 2.12.0 changes
- Added links to migration documentation

## Key Features

### Service Account Credentials
```php
$credentials = new Credentials_ServiceAccountCredentials(
    "PROJECT_ID",
    "USERNAME",
    "SECRET"
);
$mp = Mixpanel::getInstance($credentials);
```

**Benefits:**
- Enhanced security with scoped credentials
- Fine-grained access control
- Better audit trails
- Recommended for all new integrations

### API Secret Credentials (Deprecated)
```php
$credentials = new Credentials_APISecretCredentials(
    "PROJECT_ID",
    "API_SECRET"
);
$mp = Mixpanel::getInstance($credentials);
```

**Status:** Deprecated - logs warning on instantiation

### Project Token (Deprecated)
```php
$mp = Mixpanel::getInstance("PROJECT_TOKEN");
```

**Status:** Deprecated - logs warning on instantiation

## Deprecation Strategy

Following the Python library approach:

1. **Warnings**: All deprecated methods log clear deprecation warnings with migration instructions
2. **Backward Compatibility**: Existing code continues to work without changes
3. **Documentation**: Clear migration path documented in README and MIGRATION.md
4. **Timeline**: Deprecated methods will be removed in a future major version

## Authentication Header Format

### Service Account
```
Authorization: Basic {base64(username:secret)}
```

### API Secret
```
Authorization: Basic {base64(api_secret:)}
```

Note: API secrets use a trailing colon after the secret value.

## Testing

Comprehensive test coverage includes:

1. **Credential Classes**
   - Constructor validation
   - Authentication header generation
   - Deprecation status checks
   - Error handling

2. **Integration Tests**
   - Mixpanel class with different credential types
   - Singleton behavior with credentials
   - Credential passing to producers
   - Backward compatibility with tokens

3. **Consumer Tests** (existing tests should still pass)
   - CurlConsumer with credentials
   - SocketConsumer with credentials

## Breaking Changes

**None** - This is a fully backward-compatible change. All existing code will continue to work with deprecation warnings.

## Migration Path

Users should migrate in this order:
1. Create Service Account in Mixpanel UI
2. Update code to use `Credentials_ServiceAccountCredentials`
3. Test in staging/development
4. Deploy to production
5. Remove old token-based code

See MIGRATION.md for detailed instructions.

## Future Work

In a future major version (3.0.0):
- Remove support for token-based authentication
- Remove `APISecretCredentials` class
- Make `MixpanelCredentials` object required (not optional)
- Update examples and documentation to only show Service Account usage

## References

- Python implementation: https://github.com/mixpanel/mixpanel-python/pull/175
- Mixpanel documentation: https://docs.mixpanel.com/docs/tracking-methods/choosing-the-right-method
