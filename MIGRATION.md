# Migration Guide: From Project Tokens to Service Accounts

This guide will help you migrate from the deprecated token-based authentication to the recommended Service Account authentication.

## Why Migrate?

Service Account authentication provides:
- **Enhanced Security**: Credentials are scoped and can be rotated without affecting your project token
- **Fine-grained Access Control**: Service accounts can be assigned specific permissions
- **Better Audit Trail**: Track which service account performed which actions
- **Future-proof**: Token-based authentication will be removed in a future major version

## Migration Steps

### Step 1: Create a Service Account

1. Log in to your Mixpanel account
2. Navigate to your Project Settings
3. Go to "Service Accounts" section
4. Click "Create Service Account"
5. Note down the following credentials:
   - Project ID
   - Service Account Username
   - Service Account Secret

### Step 2: Update Your Code

#### Before (Deprecated):

```php
<?php
require 'vendor/autoload.php';

// Old way - using project token
$mp = Mixpanel::getInstance("YOUR_PROJECT_TOKEN");

$mp->track("event_name", array("property" => "value"));
```

#### After (Recommended):

```php
<?php
require 'vendor/autoload.php';

// New way - using Service Account credentials
$credentials = new Credentials_ServiceAccountCredentials(
    "YOUR_PROJECT_ID",
    "YOUR_SERVICE_ACCOUNT_USERNAME",
    "YOUR_SERVICE_ACCOUNT_SECRET"
);

$mp = Mixpanel::getInstance($credentials);

$mp->track("event_name", array("property" => "value"));
```

### Step 3: Update Environment Variables

Update your environment configuration to store service account credentials instead of tokens:

#### Before:
```bash
MIXPANEL_TOKEN=your_project_token
```

#### After:
```bash
MIXPANEL_PROJECT_ID=your_project_id
MIXPANEL_SA_USERNAME=your_service_account_username
MIXPANEL_SA_SECRET=your_service_account_secret
```

#### Usage in Code:
```php
<?php
$credentials = new Credentials_ServiceAccountCredentials(
    getenv('MIXPANEL_PROJECT_ID'),
    getenv('MIXPANEL_SA_USERNAME'),
    getenv('MIXPANEL_SA_SECRET')
);

$mp = Mixpanel::getInstance($credentials);
```

### Step 4: Test Your Changes

1. Run your application in a test environment
2. Verify events are being tracked correctly
3. Check that people/profile updates work as expected
4. Ensure group analytics (if used) function properly

### Step 5: Deploy

Once you've verified everything works in your test environment:
1. Deploy the updated code to production
2. Monitor for any authentication errors
3. Verify data is flowing into Mixpanel as expected

## Migration from API Secrets

If you're currently using API secrets (another deprecated method), the migration is similar:

#### Before (Deprecated):
```php
$credentials = new Credentials_APISecretCredentials(
    "YOUR_PROJECT_ID",
    "YOUR_API_SECRET"
);
```

#### After (Recommended):
```php
$credentials = new Credentials_ServiceAccountCredentials(
    "YOUR_PROJECT_ID",
    "YOUR_SERVICE_ACCOUNT_USERNAME",
    "YOUR_SERVICE_ACCOUNT_SECRET"
);
```

## Troubleshooting

### Authentication Errors

If you receive authentication errors:
1. Verify your Service Account credentials are correct
2. Ensure the Service Account has appropriate permissions
3. Check that the Project ID matches your Mixpanel project

### Missing Events

If events aren't appearing in Mixpanel:
1. Enable debug mode to see detailed logs
2. Check error callbacks for any failures
3. Verify network connectivity to Mixpanel API endpoints

```php
$mp = Mixpanel::getInstance($credentials, array(
    'debug' => true,
    'error_callback' => function($code, $msg) {
        error_log("Mixpanel error [$code]: $msg");
    }
));
```

## Timeline

- **Version 2.12.0**: Service Account support added, token-based auth deprecated
- **Future major version**: Token-based authentication will be removed

## Need Help?

- [Mixpanel Documentation](https://docs.mixpanel.com/)
- [Service Accounts Guide](https://docs.mixpanel.com/docs/tracking-methods/choosing-the-right-method)
- [GitHub Issues](https://github.com/mixpanel/mixpanel-php/issues)
