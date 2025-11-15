# YOURLS Operations Guide

**Version:** 1.9.2+  
**Audience:** System administrators, DevOps engineers, site operators  
**Last Updated:** November 2025

This guide covers production deployment, monitoring, performance tuning, troubleshooting, and operational best practices for running YOURLS reliably at scale.

---

## Table of Contents

1. [Production Deployment Checklist](#production-deployment-checklist)
2. [Performance Tuning](#performance-tuning)
3. [Monitoring & Observability](#monitoring--observability)
4. [Backup & Disaster Recovery](#backup--disaster-recovery)
5. [Security Hardening](#security-hardening)
6. [Troubleshooting](#troubleshooting)
7. [Capacity Planning](#capacity-planning)
8. [Upgrade Procedures](#upgrade-procedures)

---

## Production Deployment Checklist

###Before Going Live

- [ ] **HTTPS Enabled**: Configure SSL/TLS certificate (Let's Encrypt recommended)
- [ ] **Database Tuning**: Configure MySQL/MariaDB for production load
- [ ] **PHP Configuration**:
  - PHP 8.1+ installed
  - `memory_limit >= 128M`
  - `max_execution_time >= 30`
  - OPcache enabled
- [ ] **Web Server**:
  - mod_rewrite enabled (Apache) or rewrite rules configured (Nginx)
  - Gzip compression enabled
  - Browser caching headers configured
- [ ] **File Permissions**:
  - `user/config.php`: 0640 or more restrictive
  - `user/plugins/`: writable by web server for auto-updates
- [ ] **Monitoring Setup**:
  - Health check endpoint configured
  - Error log monitoring enabled
  - Uptime monitor configured
- [ ] **Backup Strategy**:
  - Automated DB backups configured
  - Backup verification tested
  - Recovery procedure documented
- [ ] **Security**:
  - Strong passwords for all admin users
  - `YOURLS_COOKIEKEY` set to a unique random value
  - Consider IP whitelisting for admin area
  - Review `YOURLS_PRIVATE` and related settings

### Configuration Review

**Required settings:**

```php
// Core config
define( 'YOURLS_SITE', 'https://your-short-domain.com' );  // Use HTTPS!
define( 'YOURLS_PRIVATE', true );  // Require auth for admin
define( 'YOURLS_COOKIEKEY', 'generate-unique-random-string-here' );

// Database
define( 'YOURLS_DB_USER', 'yourls_user' );
define( 'YOURLS_DB_PASS', 'strong-password-here' );
define( 'YOURLS_DB_NAME', 'yourls_db' );
define( 'YOURLS_DB_HOST', 'localhost' );  // or remote host
define( 'YOURLS_DB_PREFIX', 'yourls_' );
```

**Recommended production settings:**

```php
// Performance: Disable stats if not needed
// define( 'YOURLS_NOSTATS', true );

// Observability: Enable structured logging
define( 'YOURLS_DEBUG', true );
define( 'YOURLS_LOG_LEVEL', 'warning' );
define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 100 );  // Log 1%

// Resilience: Input limits
define( 'YOURLS_MAX_KEYWORD_LENGTH', 100 );
define( 'YOURLS_MAX_URL_LENGTH', 2000 );

// Behavior: Unknown keyword handling
define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', 'home' );  // or '404'
```

---

## Performance Tuning

### Database Optimization

**MySQL/MariaDB Configuration** (`my.cnf` or `my.ini`):

```ini
[mysqld]
# InnoDB settings for YOURLS
innodb_buffer_pool_size = 256M  # Adjust based on available RAM
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2  # Better performance, slight risk

# Query cache (MySQL 5.7 and earlier)
query_cache_size = 32M
query_cache_type = 1

# Connection limits
max_connections = 200
```

**Indexes**: YOURLS creates appropriate indexes during installation. Verify:

```sql
SHOW INDEX FROM yourls_url;
SHOW INDEX FROM yourls_log;
```

### PHP Optimization

**OPcache** (`php.ini`):

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
```

**APCu** (optional, for object caching):

```ini
apc.enabled=1
apc.shm_size=64M
```

### Web Server Tuning

**Apache** (`.htaccess` or `httpd.conf`):

```apache
# Enable compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>

# Browser caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType image/* "access plus 1 month"
</IfModule>

# Keep-Alive
KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 5
```

**Nginx** (`nginx.conf`):

```nginx
# Gzip compression
gzip on;
gzip_vary on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

# Browser caching
location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg)$ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}

# Keep-alive
keepalive_timeout 65;
keepalive_requests 100;
```

### High-Load Mode

For traffic spikes or capacity constraints:

```php
// Temporarily disable stats writes while keeping redirects fast
define( 'YOURLS_DEGRADED_STATS', true );
```

Monitor the impact:

```bash
# Check redirect latency
time curl -I https://sho.rt/test123

# Verify mode via health endpoint
curl -s https://sho.rt/health.php | jq '.mode'
```

---

## Monitoring & Observability

### Health Check Integration

**Uptime Monitoring:**

Configure your monitoring service to check:

```
GET https://your-short-domain.com/health.php
```

Alert on:

- HTTP status != 200
- Response `"status" != "ok"`
- Response `"mode" == "degraded"`

**Example (Pingdom-style check):**

```bash
#!/bin/bash
HEALTH_URL="https://sho.rt/health.php"
STATUS=$(curl -s $HEALTH_URL | jq -r '.status')

if [ "$STATUS" != "ok" ]; then
    echo "CRITICAL: YOURLS status is $STATUS"
    exit 2
fi

echo "OK: YOURLS is healthy"
exit 0
```

### Log Monitoring

**Error Log Location:**

- Apache: `/var/log/apache2/error.log` or `/var/log/httpd/error_log`
- Nginx: `/var/log/nginx/error.log`
- PHP-FPM: `/var/log/php-fpm/error.log`

**Grep for YOURLS events:**

```bash
tail -f /var/log/php_errors.log | grep '\[YOURLS\]'
```

**Parse structured logs:**

```bash
# Extract all error-level events
grep '\[YOURLS\] \[error\]' /var/log/php_errors.log

# Count unknown keywords
grep 'unknown_keyword' /var/log/php_errors.log | wc -l

# Extract auth failures with IP addresses
grep 'auth_failure' /var/log/php_errors.log | jq '.ip' | sort | uniq -c
```

### Metrics to Track

**Application metrics:**

- Redirect response time (P50, P95, P99)
- API response time
- Short URL creation rate
- Unknown keyword rate
- Auth failure rate

**Infrastructure metrics:**

- DB connection pool usage
- DB query latency
- PHP-FPM worker saturation
- Disk I/O (especially for logs/stats tables)
- Memory usage

### Status Page

Access the admin status page to get a human-friendly overview:

```
https://your-short-domain.com/admin/status.php
```

Review:

- DB connectivity
- Install/upgrade state
- Runtime flags (safe mode, degraded stats, etc.)

---

## Backup & Disaster Recovery

### What to Back Up

1. **Database**: All tables (url, options, log)
2. **Configuration**: `user/config.php`
3. **Plugins**: `user/plugins/` (custom/third-party only)
4. **Customizations**: Any modified core files (not recommended, but document if done)

### Automated DB Backup

**Using `mysqldump`:**

```bash
#!/bin/bash
# /usr/local/bin/yourls-backup.sh

DB_USER="yourls_user"
DB_PASS="password"
DB_NAME="yourls_db"
BACKUP_DIR="/backups/yourls"
DATE=$(date +%Y%m%d-%H%M%S)

mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > "$BACKUP_DIR/yourls-$DATE.sql.gz"

# Keep only last 30 days
find $BACKUP_DIR -name "yourls-*.sql.gz" -mtime +30 -delete
```

**Cron schedule (daily at 2 AM):**

```cron
0 2 * * * /usr/local/bin/yourls-backup.sh
```

### Backup Verification

**Test restores regularly:**

```bash
# Extract and verify backup
gunzip -c /backups/yourls/yourls-20241115-020000.sql.gz | head -n 50

# Restore to test DB
gunzip -c /backups/yourls/yourls-20241115-020000.sql.gz | mysql -u root -p yourls_test
```

### Disaster Recovery Procedure

1. **Restore Database:**
   ```bash
   gunzip -c latest-backup.sql.gz | mysql -u yourls_user -p yourls_db
   ```

2. **Restore Config:**
   ```bash
   cp config-backup.php user/config.php
   chmod 640 user/config.php
   ```

3. **Verify:**
   - Check health endpoint
   - Test a few short URLs
   - Access admin area
   - Review admin status page

4. **Post-Recovery:**
   - Update DNS if necessary
   - Clear caches (OPcache, browser)
   - Monitor logs for errors

---

## Security Hardening

### Access Control

**Admin Area Protection:**

```php
// Require authentication for admin
define( 'YOURLS_PRIVATE', true );

// Keep API private too
define( 'YOURLS_PRIVATE_API', true );

// Stats can be public if desired
define( 'YOURLS_PRIVATE_INFOS', false );
```

**IP Whitelisting** (Apache `.htaccess`):

```apache
<FilesMatch "^(admin|yourls-api)\.php$">
    Order Deny,Allow
    Deny from all
    Allow from 192.168.1.0/24
    Allow from 10.0.0.0/8
</FilesMatch>
```

### Password Security

**Strong Passwords:**

- Use `https://yourls.org/cookie` to generate `YOURLS_COOKIEKEY`
- Use bcrypt or phpass for user passwords (see https://yourls.org/userpassword)

**Example encrypted password:**

```php
$yourls_user_passwords = [
    'admin' => 'phpass:$P$BkGLEzWJXqA...',  // Encrypted
];
```

### HTTPS Enforcement

**Force HTTPS** (`user/config.php`):

```php
if ( $_SERVER['HTTPS'] != 'on' ) {
    header( 'Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301 );
    exit;
}
```

Or configure at web server level (preferred).

### Input Validation

**Already hardened:**

- Header injection prevention in `yourls_redirect()`
- Unserialization restricted to safe classes
- Input length limits configurable

**Additional protections:**

```php
// Strict keyword length
define( 'YOURLS_MAX_KEYWORD_LENGTH', 50 );

// Strict URL length
define( 'YOURLS_MAX_URL_LENGTH', 1500 );
```

### Rate Limiting

**Built-in flood protection:**

```php
// Limit URL creation to 1 per 10 seconds per IP
define( 'YOURLS_FLOOD_DELAY_SECONDS', 10 );

// Whitelist trusted IPs
define( 'YOURLS_FLOOD_IP_WHITELIST', '192.168.1.100,10.0.0.5' );
```

**For API, consider:**

- Nginx `limit_req` module
- Cloudflare rate limiting
- Plugin-based rate limiting

### Security Headers

**Apache:**

```apache
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

**Nginx:**

```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

---

## Troubleshooting

### Short URLs Return 404

**Causes:**

1. mod_rewrite not enabled
2. `.htaccess` not being read (`AllowOverride None`)
3. Incorrect `.htaccess` configuration

**Solutions:**

1. **Verify mod_rewrite** (Apache):
   ```bash
   apache2ctl -M | grep rewrite
   # or
   httpd -M | grep rewrite
   ```

2. **Check AllowOverride**:
   ```apache
   <Directory /var/www/html/yourls>
       AllowOverride All
   </Directory>
   ```

3. **Verify `.htaccess`**:
   Should match: https://yourls.org/htaccess

### Database Connection Errors

**Symptoms:**

- `health.php` returns `"db": "error"`
- Admin area shows DB connection error

**Checks:**

1. **Verify credentials:**
   ```bash
   mysql -u yourls_user -p -h localhost yourls_db
   ```

2. **Check grants:**
   ```sql
   SHOW GRANTS FOR 'yourls_user'@'localhost';
   ```

3. **Review error log:**
   ```bash
   grep 'db_connection_error' /var/log/php_errors.log
   ```

### Slow Redirects

**Diagnose:**

```bash
# Measure redirect time
time curl -I https://sho.rt/test123

# Check DB query time
mysql -u yourls_user -p yourls_db -e "SELECT keyword, url FROM yourls_url WHERE keyword='test123';"
```

**Solutions:**

1. **Enable degraded stats mode temporarily:**
   ```php
   define( 'YOURLS_DEGRADED_STATS', true );
   ```

2. **Optimize DB:**
   ```sql
   OPTIMIZE TABLE yourls_url;
   OPTIMIZE TABLE yourls_log;
   ```

3. **Increase DB resources** (connection pool, buffer pool size)

### Plugin Causing Crashes

**Symptoms:**

- Fatal errors on admin load
- White screen of death

**Recovery:**

1. **Enable safe mode:**
   ```php
   define( 'YOURLS_SAFE_MODE', true );
   ```

2. **Access admin** and deactivate broken plugin

3. **Disable safe mode** and reload

### High Unknown Keyword Rate

**Diagnose:**

```bash
# Count recent unknown keywords
grep 'unknown_keyword' /var/log/php_errors.log | tail -n 100
```

**Causes:**

- Bots/scrapers probing for keywords
- Typos in shared links
- Deleted short URLs still being accessed

**Solutions:**

1. **Return 404 instead of redirect:**
   ```php
   define( 'YOURLS_UNKNOWN_KEYWORD_BEHAVIOR', '404' );
   ```

2. **Sample logs to reduce noise:**
   ```php
   define( 'YOURLS_LOG_SAMPLE_UNKNOWN_KEYWORD', 100 );  // 1%
   ```

3. **Monitor for patterns** and block abusive IPs

---

## Capacity Planning

### Traffic Estimation

**Metrics to track:**

- Redirects per second (QPS)
- Short URL creations per day
- DB size growth rate
- Log table size

**Example calculations:**

- 1 million redirects/day ≈ 11.5 QPS average, ~50 QPS peak
- Stats logging: ~200 bytes/row → 200 MB/day for 1M redirects

### Scaling Options

**Vertical Scaling:**

- Increase DB server resources (RAM, CPU)
- Upgrade to faster storage (SSD, NVMe)
- Increase PHP-FPM worker count

**Horizontal Scaling:**

- **Read replicas**: Point read-heavy queries to replicas
- **CDN**: Cache redirect responses (short TTL)
- **Load balancer**: Multiple YOURLS instances (stateless)

**Note**: YOURLS core is stateless; sessions use cookies. Easy to load balance.

### When to Use Degraded Stats

- **Peak QPS > 100**: Consider disabling stats logging
- **DB write latency > 50ms**: Enable degraded stats temporarily
- **Planned maintenance**: Pre-emptively reduce write load

---

## Upgrade Procedures

### Pre-Upgrade

1. **Backup database and config**
2. **Review changelog**: https://github.com/YOURLS/YOURLS/releases
3. **Test in staging environment** if available
4. **Check plugin compatibility**

### Upgrade Steps

1. **Backup**:
   ```bash
   mysqldump -u yourls_user -p yourls_db > yourls-pre-upgrade.sql
   cp user/config.php user/config.php.backup
   ```

2. **Download new version**:
   ```bash
   wget https://github.com/YOURLS/YOURLS/archive/1.9.2.tar.gz
   tar -xzf 1.9.2.tar.gz
   ```

3. **Upload files** (overwrite existing, preserving `user/` directory)

4. **Run upgrade**:
   ```
   https://your-short-domain.com/admin/
   ```
   YOURLS will detect upgrade and prompt to run migration.

5. **Verify**:
   - Check health endpoint
   - Test redirects
   - Review admin status page
   - Check error logs

### Post-Upgrade

- Clear OPcache: `service php-fpm reload`
- Clear browser cache
- Monitor logs for errors
- Test critical plugins

### Rollback Procedure

If upgrade fails:

1. **Restore files** from previous version
2. **Restore database**:
   ```bash
   mysql -u yourls_user -p yourls_db < yourls-pre-upgrade.sql
   ```
3. **Clear caches** and verify

---

## Summary

Running YOURLS in production requires:

- **Proactive monitoring**: Health checks, log monitoring, metrics
- **Performance tuning**: DB optimization, caching, web server config
- **Security hardening**: HTTPS, strong auth, input validation, rate limiting
- **Resilience planning**: Backups, DR procedures, degraded modes
- **Capacity awareness**: Traffic estimation, scaling strategies

Use the stability features (logging, safe mode, degraded stats, health endpoint) to maintain visibility and control in production.

For architecture details, see `YOURLS-ARCHITECTURE.md`.  
For stability features, see `YOURLS-STABILITY-FEATURES.md`.

---

**EOF**
