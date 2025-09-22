# BigQuery Environment Variables Configuration Guide

## Overview

This guide documents the environment variable configuration for BigQuery Adminer plugin, including the important fix for authentication errors and standardization to Google Cloud conventions.

## Environment Variables

### Primary Variables

| Variable | Value | Purpose |
|----------|--------|---------|
| `GOOGLE_APPLICATION_CREDENTIALS` | `/etc/google_credentials.json` | Service account key file path |
| `GOOGLE_CLOUD_PROJECT` | `your-project-id` | GCP Project ID (standard Google Cloud variable) |

### Legacy Variables (Deprecated)

| Variable | Status | Replacement |
|----------|---------|-------------|
| `BIGQUERY_PROJECT_ID` | ⚠️ Deprecated | `GOOGLE_CLOUD_PROJECT` |

## Critical Fix: PHP Environment Variable Access

### Problem Identified (September 2025)

**Issue**: "Invalid credentials" error during BigQuery login
**Root Cause**: PHP `$_ENV` array not populated due to `variables_order` setting

### Technical Details

#### PHP Configuration
- **Default `variables_order`**: `GPCS` (GET, POST, COOKIE, SERVER)
- **Missing**: `E` (Environment) - causes `$_ENV` array to be empty
- **Impact**: `$_ENV['GOOGLE_CLOUD_PROJECT']` returns `null` (same issue with old `BIGQUERY_PROJECT_ID`)

#### Solution Applied

**Before (Broken)**:
```php
// index.php (legacy version)
'project_id' => $_ENV['BIGQUERY_PROJECT_ID']  // Returns null
```

**After (Working)**:
```php
// index.php
'project_id' => getenv('GOOGLE_CLOUD_PROJECT')  // Always works
```

### Key Differences

| Method | Dependency | Reliability |
|---------|------------|-------------|
| `$_ENV['VAR']` | `variables_order` setting | ❌ Unreliable in containers |
| `getenv('VAR')` | System environment | ✅ Always works |

## Architecture Design

### Hierarchical Value Resolution

The plugin uses a layered approach to determine the project ID:

```php
private function getProjectId()
{
    return $_GET["server"] ??           // 1. URL parameter (highest priority)
        $_POST["auth"]["server"] ??     // 2. Form input (user override)
        $this->config['project_id'];    // 3. Environment variable (fallback)
}
```

### Configuration Flow

1. **Environment Variable**: `getenv('GOOGLE_CLOUD_PROJECT')`
2. **Index.php**: Reads environment, passes to plugin as `$config['project_id']`
3. **Login Plugin**: Uses hierarchical resolution with config as fallback
4. **BigQuery Driver**: Receives project ID from login plugin

## File Changes History

### September 20, 2025 - Environment Variable Fix

#### Modified Files

**`container/web/index.php`**
```php
// OLD (Broken - Legacy approach)
'project_id' => $_ENV['BIGQUERY_PROJECT_ID']

// NEW (Fixed)
'project_id' => getenv('GOOGLE_CLOUD_PROJECT')
```

**`container/web/compose.yml`**
```yaml
environment:
  - GOOGLE_APPLICATION_CREDENTIALS=/etc/google_credentials.json
  - GOOGLE_CLOUD_PROJECT=adminer-test-472623  # Was: BIGQUERY_PROJECT_ID
```

**`plugins/login-bigquery.php`**
- Removed redundant `getenv()` call (was accessing old `BIGQUERY_PROJECT_ID`)
- Restored clean hierarchical design
- Now properly uses `$this->config['project_id']`

## Validation Results

### Test Environments

| Environment | Project ID | Dataset Count | Status |
|-------------|------------|---------------|---------|
| Initial | `nyle-carmo-analysis` | 20 datasets | ✅ Working |
| Updated | `adminer-test-472623` | 1 dataset | ✅ Working |

### Verification Steps

1. **Environment Variable Loading**: `getenv('GOOGLE_CLOUD_PROJECT')` returns correct value
2. **Login Screen**: Project ID auto-populated in form field
3. **Authentication**: No "Invalid credentials" error
4. **Dataset Access**: BigQuery datasets displayed correctly
5. **Project Switching**: Works with different project IDs

## Google Cloud Standards Compliance

### Why GOOGLE_CLOUD_PROJECT?

1. **Official Standard**: Recommended by Google Cloud documentation
2. **Auto-Detection**: Set automatically in GCP environments (Cloud Run, Compute Engine)
3. **Client Library Support**: BigQueryClient uses as fallback when `projectId` not specified
4. **Consistency**: Matches other Google Cloud client libraries

### BigQueryClient Configuration

Current implementation explicitly sets `projectId`:
```php
$clientConfig = array(
    'projectId' => $config['projectId'],  // Explicit (recommended)
    'location' => $config['location']
);
```

Alternative (relies on environment):
```php
// projectId omitted - will use GOOGLE_CLOUD_PROJECT automatically
$clientConfig = array(
    'location' => $config['location']
);
```

## Troubleshooting

### Common Issues

#### 1. Empty Project ID Field
**Symptom**: Login form shows empty Project ID field
**Cause**: Environment variable not set or not accessible
**Solution**: Verify `GOOGLE_CLOUD_PROJECT` in container:
```bash
docker exec container-name printenv GOOGLE_CLOUD_PROJECT
```

#### 2. "Invalid credentials" Error
**Symptom**: Login fails with credential error
**Root Causes**:
- Missing or incorrect service account file
- Wrong project ID
- Environment variable access issue (use `getenv()` not `$_ENV`)

#### 3. Project ID Not Matching
**Symptom**: Wrong project accessed
**Solution**: Check precedence order:
1. URL parameter `?bigquery=project-id`
2. Form input value
3. Environment variable value

### Validation Commands

```bash
# Check environment variables in container
docker exec adminer-bigquery-test printenv | grep GOOGLE

# Test PHP environment variable access
docker exec adminer-bigquery-test php -r "echo getenv('GOOGLE_CLOUD_PROJECT');"

# Verify service account file
docker exec adminer-bigquery-test cat /etc/google_credentials.json | jq .project_id
```

## Migration Guide

### From BIGQUERY_PROJECT_ID to GOOGLE_CLOUD_PROJECT

#### Step 1: Update Compose Configuration
```yaml
environment:
  # OLD
  - BIGQUERY_PROJECT_ID=your-project-id

  # NEW
  - GOOGLE_CLOUD_PROJECT=your-project-id
```

#### Step 2: Update PHP Code
```php
// OLD
getenv('BIGQUERY_PROJECT_ID')

// NEW
getenv('GOOGLE_CLOUD_PROJECT')
```

#### Step 3: Update Documentation & Tests
- E2E test scripts
- Documentation examples
- Dockerfile ENV statements

## Best Practices

### 1. Environment Variable Access
- ✅ **Use**: `getenv('VARIABLE_NAME')`
- ❌ **Avoid**: `$_ENV['VARIABLE_NAME']` in Docker containers

### 2. Project ID Configuration
- ✅ **Standard**: Use `GOOGLE_CLOUD_PROJECT`
- ✅ **Explicit**: Pass `projectId` to BigQueryClient
- ✅ **Hierarchical**: Allow URL/form override of environment default

### 3. Error Handling
- ✅ **Validation**: Check project ID format and existence
- ✅ **Fallbacks**: Provide clear error messages for missing configuration
- ✅ **Logging**: Log connection attempts and failures safely

## Related Files

### Core Configuration
- `container/web/index.php` - Main environment variable loading
- `container/web/compose.yml` - Docker environment configuration
- `plugins/login-bigquery.php` - Authentication plugin

### Documentation
- `container/docs/bigquery-driver-container-setup-startup-guide.md`
- `container/docs/dood-test-container-operations-guide.md`
- `CLAUDE.md` - Project overview and history

### Testing
- `container/e2e/tests/*.spec.js` - E2E test scripts
- `container/e2e/compose.yml` - E2E environment configuration

---

**Last Updated**: September 20, 2025
**Status**: ✅ Production Ready
**Validation**: Complete with multiple project environments