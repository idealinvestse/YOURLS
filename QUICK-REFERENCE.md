# YOURLS Quick Reference

**Version:** 1.9.2+  
One-page reference for configuration constants, functions, and hooks.

---

## Configuration Constants

### Core Settings (Required)

```php
define( 'YOURLS_DB_USER', 'db_username' );
define( 'YOURLS_DB_PASS', 'db_password' );
define( 'YOURLS_DB_NAME', 'db_name' );
define( 'YOURLS_DB_HOST', 'localhost' );
define( 'YOURLS_DB_PREFIX', 'yourls_' );
define( 'YOURLS_SITE', 'https://sho.rt' );
define( 'YOURLS_COOKIEKEY', 'random-string' );  // Generate at yourls.org/cookie

$yourls_user_passwords = [
    'username' => 'password',
];
```

### Access Control

```php
define( 'YOURLS_PRIVATE', true );              // Require auth for admin
define( 'YOURLS_PRIVATE_API', true );          // Require auth for API
define( 'YOURLS_PRIVATE_INFOS', false );       // Stats pages public
define( 'YOURLS_UNIQUE_URLS', true );          // One short URL per long URL
```

### Logging & Observability (New)

```php
define( 'YOURLS_DEBUG', true );                           // Enable debug mode
define( 'YOURLS_LOG_LEVEL', 'warning' );                  // 'error'|'warning'|'info'|'debug'
define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 100 );      // Log ~1% of events
define( 'YOURLS_LOG_SAMPLE_AUTH_FAILURE', 10 );          // Log ~10% of events
```

### Resilience & Performance (New)

```php
define( 'YOURLS_DEGRADED_STATS', true );       // Skip stats writes, keep redirects fast
define( 'YOURLS_SAFE_MODE', true );            // Disable all plugins temporarily
define( 'YOURLS_NOSTATS', true );              // Disable all stats logging (existing)
```

### Input Validation (New)

```php
define( 'YOURLS_MAX_KEYWORD_LENGTH', 128 );    // Max keyword length (default: 128)
define( 'YOURLS_MAX_URL_LENGTH', 2048 );       // Max URL length for API (default: 2048)
```

### Unknown Keyword Behavior (New)

```php
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', 'home' );     // 'home'|'404'|'custom'
define( 'YOURLS_UNKNOWN_KEYWORD_URL', 'https://...' );   // If behavior='custom'
```

### URL Shortening

```php
define( 'YOURLS_URL_CONVERT', 36 );            // 36 (lowercase) or 62 (mixed case)
$yourls_reserved_URL = ['admin', 'api'];       // Reserved keywords
```

### Advanced

```php
define( 'YOURLS_LANG', '' );                   // Translation file (e.g., 'fr_FR')
define( 'YOURLS_HOURS_OFFSET', 0 );           // Timezone offset
define( 'YOURLS_FLOOD_DELAY_SECONDS', 10 );   // Anti-flood delay
define( 'YOURLS_FLOOD_IP_WHITELIST', '...' ); // Whitelisted IPs (comma-separated)
```

---

## Common Functions

### Database

```php
$ydb = yourls_get_db();
$value = $ydb->fetchValue( $sql, $binds );
$row = $ydb->fetchOne( $sql, $binds );
$rows = $ydb->fetchAll( $sql, $binds );
$affected = $ydb->fetchAffected( $sql, $binds );
```

### Options

```php
yourls_get_option( $name, $default = false );
yourls_add_option( $name, $value );
yourls_update_option( $name, $value );
yourls_delete_option( $name );
yourls_get_all_options();
```

### Short URLs

```php
yourls_add_new_link( $url, $keyword = '', $title = '' );
yourls_get_keyword_longurl( $keyword );
yourls_update_clicks( $keyword );
yourls_redirect_shorturl( $url, $keyword );
```

### Redirects

```php
yourls_redirect( $location, $code = 301 );
yourls_handle_unknown_keyword( $keyword, $context = 'go' );  // New
```

### Logging (New)

```php
yourls_log( $level, $message, array $context = [] );
// Levels: 'error', 'warning', 'info', 'debug'
```

### Utilities

```php
yourls_is_private();
yourls_is_valid_user();
yourls_is_installed();
yourls_is_admin();
yourls_is_API();
yourls_sanitize_keyword( $keyword );
yourls_sanitize_url( $url );
```

---

## Hook System

### Actions (Side Effects)

```php
// Register
yourls_add_action( 'hook_name', 'callback', $priority = 10 );

// Trigger
yourls_do_action( 'hook_name', $arg1, $arg2 );
```

**Common action hooks:**

- `plugins_loaded` – After all plugins loaded
- `pre_add_new_link` – Before creating short URL
- `redirect_shorturl` – Before redirect
- `redirect_keyword_not_found` – Unknown keyword
- `admin_page_before_table` – In admin UI
- `api` – On every API request

### Filters (Transform Data)

```php
// Register
yourls_add_filter( 'filter_name', 'callback', $priority = 10 );

// Trigger
$value = yourls_apply_filter( 'filter_name', $value, $arg1 );
```

**Common filter hooks:**

- `add_new_link` – Modify link creation args
- `redirect_location` – Modify redirect destination
- `get_option_{name}` – Modify option value
- `api_action_{action}` – Handle custom API action
- `shunt_*` – Bypass core entirely

---

## API Quick Reference

### Endpoint

```
POST/GET https://your-domain.com/yourls-api.php
```

### Authentication

**Username/Password:**
```
?username=user&password=pass
```

**Signature (passwordless):**
```
?signature=abc123  # Generate at yourls.org/passwordlessapi
```

### Actions

**Shorten URL:**
```
?action=shorturl&url=https://example.com&format=json
```

**Expand Short URL:**
```
?action=expand&shorturl=abc&format=json
```

**Get Stats:**
```
?action=stats&filter=top&limit=10&format=json
```

**Get URL Stats:**
```
?action=url-stats&shorturl=abc&format=json
```

**Get DB Stats:**
```
?action=db-stats&format=json
```

**Get Version:**
```
?action=version&format=simple
```

### Formats

- `json` – JSON object
- `jsonp` – JSONP with callback
- `xml` – XML response
- `simple` – Plain text (limited actions)

---

## Health Check & Status

### Health Endpoint (New)

```bash
curl https://your-domain.com/health.php
```

Returns JSON with `status`, `db`, `installed`, `flags`, `mode`.

### Admin Status Page (New)

```
https://your-domain.com/admin/status.php
```

Shows DB, install state, runtime flags.

---

## Troubleshooting

### Short URLs Return 404

1. Verify mod_rewrite enabled
2. Check `.htaccess` AllowOverride
3. Confirm `.htaccess` matches yourls.org/htaccess

### Enable Safe Mode (Plugin Issues)

```php
define( 'YOURLS_SAFE_MODE', true );
```

Access admin, deactivate plugin, remove safe mode flag.

### Check Logs

```bash
tail -f /var/log/php_errors.log | grep '\[YOURLS\]'
```

### Performance: Enable Degraded Stats

```php
define( 'YOURLS_DEGRADED_STATS', true );
```

Redirects work, stats paused.

---

## File Locations

- **Config**: `user/config.php`
- **Plugins**: `user/plugins/{plugin-name}/plugin.php`
- **Languages**: `user/languages/{lang}.mo`
- **Health check**: `health.php`
- **Admin status**: `admin/status.php`
- **Logs**: Check PHP error_log location

---

## Useful Links

- **Official site**: https://yourls.org/
- **Documentation**: https://docs.yourls.org/
- **Plugins**: https://yourls.org/awesome
- **GitHub**: https://github.com/YOURLS/YOURLS
- **Generate cookie key**: https://yourls.org/cookie
- **Passwordless API**: https://yourls.org/passwordlessapi

---

**For detailed guides, see:**

- `YOURLS-STABILITY-FEATURES.md` – New resilience features
- `YOURLS-OPERATIONS-GUIDE.md` – Production deployment
- `YOURLS-ARCHITECTURE.md` – Internal architecture

---

**EOF**
