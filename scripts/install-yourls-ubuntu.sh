#!/usr/bin/env bash
set -euo pipefail
umask 027

# Helpful error trace
trap 'echo -e "\033[1;31m[x]\033[0m Error on line $LINENO running: $BASH_COMMAND" >&2' ERR

# YOURLS unattended installer for modern Ubuntu (22.04/24.04)
# - Installs Nginx, MariaDB, PHP-FPM and required PHP extensions
# - Deploys YOURLS to /var/www/yourls (configurable)
# - Creates DB, user, and YOURLS config.php
# - Configures an Nginx server block with PHP-FPM and YOURLS routing
# - Optional Let's Encrypt TLS
#
# Usage (run as root or with sudo):
#   sudo bash install-yourls-ubuntu.sh \
#     --domain example.com \
#     --db-name yourls \
#     --db-user yourls \
#     --db-pass 'StrongPasswordHere' \
#     --admin-user admin \
#     --admin-pass 'StrongAdminPass' \
#     [--install-dir /var/www/yourls] \
#     [--yourls-version 1.9.2] \
#     [--letsencrypt --email you@example.com] \
#     [--source-path /path/to/local/YOURLS] \
#     [--self-signed-fallback]
#
# After completion: browse to http(s)://example.com/admin/ to finish setup.

# Defaults
DOMAIN=""
DB_NAME="yourls"
DB_USER="yourls"
DB_PASS=""
ADMIN_USER="admin"
ADMIN_PASS=""
INSTALL_DIR="/var/www/yourls"
YOURLS_VERSION="1.9.2"
ENABLE_LETSENCRYPT="false"
LETSENCRYPT_EMAIL=""
SOURCE_PATH=""         # Optional local source (rsync) instead of downloading
SELF_SIGNED_FALLBACK="false"  # If LE fails, create a self-signed cert and 443 server

log() { echo -e "\033[1;32m[+]\033[0m $*"; }
warn() { echo -e "\033[1;33m[!]\033[0m $*"; }
die()  { echo -e "\033[1;31m[x]\033[0m $*"; exit 1; }

need_root() {
  if [[ $(id -u) -ne 0 ]]; then
    die "Please run as root (use sudo)."
  fi
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --domain) DOMAIN="$2"; shift 2;;
      --db-name) DB_NAME="$2"; shift 2;;
      --db-user) DB_USER="$2"; shift 2;;
      --db-pass) DB_PASS="$2"; shift 2;;
      --admin-user) ADMIN_USER="$2"; shift 2;;
      --admin-pass) ADMIN_PASS="$2"; shift 2;;
      --install-dir) INSTALL_DIR="$2"; shift 2;;
      --yourls-version) YOURLS_VERSION="$2"; shift 2;;
      --letsencrypt) ENABLE_LETSENCRYPT="true"; shift;;
      --email) LETSENCRYPT_EMAIL="$2"; shift 2;;
      --source-path) SOURCE_PATH="$2"; shift 2;;
      --self-signed-fallback) SELF_SIGNED_FALLBACK="true"; shift;;
      -h|--help) usage; exit 0;;
      *) die "Unknown argument: $1";;
    esac
  done

  [[ -z "$DOMAIN" ]] && die "--domain is required"
  [[ -z "$DB_PASS" ]] && die "--db-pass is required"
  [[ -z "$ADMIN_PASS" ]] && die "--admin-pass is required"
  if [[ "$ENABLE_LETSENCRYPT" == "true" && -z "$LETSENCRYPT_EMAIL" ]]; then
    die "--email is required when using --letsencrypt"
  fi

  # Basic validation to avoid SQL injection or invalid identifiers
  [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "--db-name must match ^[A-Za-z0-9_]+$"
  [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "--db-user must match ^[A-Za-z0-9_]+$"

  # Normalize and validate domain (basic FQDN)
  DOMAIN=$(echo "$DOMAIN" | tr 'A-Z' 'a-z')
  if ! [[ "$DOMAIN" =~ ^([a-z0-9-]+\.)+[a-z]{2,}$ ]]; then
    die "--domain must be a valid hostname (eg: short.example.com)"
  fi

  # Normalize YOURLS version (strip optional leading 'v')
  YOURLS_VERSION=${YOURLS_VERSION#v}
}

usage() {
  sed -n '1,80p' "$0"
}

backup_file() {
  # backup_file <path>
  local p="$1" ts
  ts=$(date +%Y%m%d-%H%M%S)
  if [[ -f "$p" ]]; then
    cp -a "$p" "${p}.bak.${ts}"
  fi
}

service_enable_now() {
  # service_enable_now <unit>
  local unit="$1"
  systemctl enable --now "$unit" 2>/dev/null || true
}

start_db_services() {
  # Enable and start MariaDB/MySQL if present
  if systemctl list-unit-files | grep -q '^mariadb\.service'; then
    service_enable_now mariadb
  elif systemctl list-unit-files | grep -q '^mysql\.service'; then
    service_enable_now mysql
  fi
}

mysql_ready() {
  # Wait up to ~30s for DB to be ready
  for i in $(seq 1 30); do
    if mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  warn "MySQL/MariaDB service not ready after 30s; continuing"
}

install_packages() {
  log "Updating apt and installing required packages"
  DEBIAN_FRONTEND=noninteractive apt-get update -y
  DEBIAN_FRONTEND=noninteractive apt-get install -y \
    nginx mariadb-server \
    php-fpm php-mysql php-curl php-gd php-mbstring php-xml php-zip php-cli \
    unzip curl ca-certificates rsync openssl lsb-release software-properties-common
}

setup_database() {
  log "Creating MariaDB database and user"
  # Escape single quotes in password for SQL string literal
  local DB_PASS_ESC
  DB_PASS_ESC=$(printf '%s' "$DB_PASS" | sed "s/'/''/g")
  local DB_NAME_SAFE
  DB_NAME_SAFE="$DB_NAME"  # validated by regex above
  mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME_SAFE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_ESC';
GRANT ALL PRIVILEGES ON \`$DB_NAME_SAFE\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
}

test_db_connection() {
  log "Verifying DB credentials for user '$DB_USER'"
  if ! mysql -u"$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
    die "Cannot connect as '$DB_USER' to DB '$DB_NAME'. Check credentials or MySQL auth plugin."
  fi
}

fetch_yourls() {
  mkdir -p "$INSTALL_DIR"
  if [[ -n "$SOURCE_PATH" ]]; then
    log "Deploying YOURLS from local source: $SOURCE_PATH"
    [[ -d "$SOURCE_PATH" ]] || die "--source-path '$SOURCE_PATH' not a directory"
    rsync -a "$SOURCE_PATH"/ "$INSTALL_DIR"/
  else
    log "Fetching YOURLS v$YOURLS_VERSION"
    cd /tmp
    rm -f yourls.zip || true
    set +e
    curl -fsSL -o yourls.zip "https://github.com/YOURLS/YOURLS/archive/refs/tags/$YOURLS_VERSION.zip"
    local dl_status=$?
    if [[ $dl_status -eq 0 ]]; then
      unzip -q yourls.zip && rsync -a "YOURLS-$YOURLS_VERSION"/ "$INSTALL_DIR"/
      dl_status=$?
    fi
    if [[ $dl_status -ne 0 ]]; then
      warn "Download failed, attempting git clone"
      apt-get install -y git
      git clone --depth=1 --branch "$YOURLS_VERSION" https://github.com/YOURLS/YOURLS.git /tmp/yourls.git || die "git clone failed"
      rsync -a /tmp/yourls.git/ "$INSTALL_DIR"/
      rm -rf /tmp/yourls.git || true
    fi
    set -e
  fi
  chown -R www-data:www-data "$INSTALL_DIR"
}

random_cookie_key() {
  # 64 hex chars
  openssl rand -hex 32
}

# Escape data for PHP single-quoted strings: backslashes then single quotes
escape_php_squote() {
  sed -e "s/\\\\/\\\\\\\\/g" -e "s/'/\\\\'/g"
}

write_config_php() {
  log "Writing YOURLS user/config.php"
  local scheme="${SCHEME:-http}"
  local site_url="${scheme}://$DOMAIN"
  local cookie_key
  cookie_key=$(random_cookie_key)

  # Escape for PHP single-quoted strings: escape backslash and single quote
  local DB_USER_PHP DB_PASS_PHP DB_NAME_PHP ADMIN_USER_PHP ADMIN_PASS_PHP SITE_URL_PHP COOKIE_PHP
  DB_USER_PHP=$(printf '%s' "$DB_USER" | escape_php_squote)
  DB_PASS_PHP=$(printf '%s' "$DB_PASS" | escape_php_squote)
  DB_NAME_PHP=$(printf '%s' "$DB_NAME" | escape_php_squote)
  ADMIN_USER_PHP=$(printf '%s' "$ADMIN_USER" | escape_php_squote)
  ADMIN_PASS_PHP=$(printf '%s' "$ADMIN_PASS" | escape_php_squote)
  SITE_URL_PHP=$(printf '%s' "$site_url" | escape_php_squote)
  COOKIE_PHP=$(printf '%s' "$cookie_key" | escape_php_squote)

  mkdir -p "$INSTALL_DIR/user"
  backup_file "$INSTALL_DIR/user/config.php"
  cat > "$INSTALL_DIR/user/config.php" <<PHP
<?php
// Auto-generated by install-yourls-ubuntu.sh
// See user/config-sample.php for documentation

define( 'YOURLS_DB_USER', '$DB_USER_PHP' );
define( 'YOURLS_DB_PASS', '$DB_PASS_PHP' );
define( 'YOURLS_DB_NAME', '$DB_NAME_PHP' );
define( 'YOURLS_DB_HOST', 'localhost' );
define( 'YOURLS_DB_PREFIX', 'yourls_' );

define( 'YOURLS_SITE', '$SITE_URL_PHP' );
define( 'YOURLS_LANG', '' );

define( 'YOURLS_UNIQUE_URLS', true );

define( 'YOURLS_PRIVATE', true );

define( 'YOURLS_COOKIEKEY', '$COOKIE_PHP' );

\$yourls_user_passwords = [
  '$ADMIN_USER_PHP' => '$ADMIN_PASS_PHP',
];

define( 'YOURLS_URL_CONVERT', 36 );

define( 'YOURLS_DEBUG', false );

\$yourls_reserved_URL = [ '' ];
PHP
  chmod 640 "$INSTALL_DIR/user/config.php" || true
  chmod 750 "$INSTALL_DIR/user" || true
  chown -R www-data:www-data "$INSTALL_DIR/user"
}

php_fpm_socket() {
  # Find a php-fpm socket path
  local sock
  sock=$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' 2>/dev/null | head -n1 || true)
  if [[ -n "$sock" ]]; then
    echo "unix:$sock"
  else
    # Fallback to TCP if no socket found
    echo "127.0.0.1:9000"
  fi
}

configure_nginx() {
  log "Configuring Nginx server block for $DOMAIN"
  local sock
  sock=$(php_fpm_socket)

  # Ensure Nginx site directories exist
  mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled

  # Backup existing config if present
  backup_file "/etc/nginx/sites-available/yourls-$DOMAIN"

  cat > "/etc/nginx/sites-available/yourls-$DOMAIN" <<NGINX
server {
  listen 80;
  listen [::]:80;
  server_name $DOMAIN;
  root $INSTALL_DIR;
  index index.php index.html;

  access_log /var/log/nginx/yourls_${DOMAIN}_access.log;
  error_log  /var/log/nginx/yourls_${DOMAIN}_error.log;

  location / {
    try_files \$uri \$uri/ /yourls-loader.php?\$args;
  }

  location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass $sock;
  }

  location ~ /\.ht { deny all; }
}
NGINX

  ln -sfn "/etc/nginx/sites-available/yourls-$DOMAIN" "/etc/nginx/sites-enabled/yourls-$DOMAIN"
  if [[ -f /etc/nginx/sites-enabled/default ]]; then
    rm -f /etc/nginx/sites-enabled/default
  fi

  nginx -t
  systemctl enable --now nginx
  systemctl reload nginx
}

obtain_cert() {
  if [[ "$ENABLE_LETSENCRYPT" != "true" ]]; then
    return
  fi
  log "Obtaining Let's Encrypt certificate via certbot"
  # Basic DNS check to reduce immediate failures
  if ! getent hosts "$DOMAIN" >/dev/null; then
    warn "Domain $DOMAIN does not appear to resolve; skipping certbot"
    [[ "$SELF_SIGNED_FALLBACK" == "true" ]] || return
  fi
  DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx
  if ! certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" --redirect; then
    warn "Certbot failed"
    if [[ "$SELF_SIGNED_FALLBACK" == "true" ]]; then
      log "Falling back to self-signed TLS"
      mkdir -p /etc/ssl/private /etc/ssl/certs
      openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "/etc/ssl/private/${DOMAIN}.key" \
        -out "/etc/ssl/certs/${DOMAIN}.crt" \
        -subj "/CN=${DOMAIN}" >/dev/null 2>&1
      local sock
      sock=$(php_fpm_socket)
      backup_file "/etc/nginx/sites-available/yourls-${DOMAIN}-ssl"
      cat > "/etc/nginx/sites-available/yourls-${DOMAIN}-ssl" <<NGINX
server {
  listen 443 ssl;
  listen [::]:443 ssl;
  server_name $DOMAIN;
  root $INSTALL_DIR;
  index index.php index.html;

  ssl_certificate     /etc/ssl/certs/${DOMAIN}.crt;
  ssl_certificate_key /etc/ssl/private/${DOMAIN}.key;

  access_log /var/log/nginx/yourls_${DOMAIN}_ssl_access.log;
  error_log  /var/log/nginx/yourls_${DOMAIN}_ssl_error.log;

  location / {
    try_files \$uri \$uri/ /yourls-loader.php?\$args;
  }

  location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass $sock;
  }

  location ~ /\.ht { deny all; }
}
NGINX
      ln -sfn "/etc/nginx/sites-available/yourls-${DOMAIN}-ssl" "/etc/nginx/sites-enabled/yourls-${DOMAIN}-ssl"
      # Replace HTTP site with redirect to HTTPS when self-signed fallback is used
      backup_file "/etc/nginx/sites-available/yourls-${DOMAIN}-http-redirect"
      cat > "/etc/nginx/sites-available/yourls-${DOMAIN}-http-redirect" <<NGINX
server {
  listen 80;
  listen [::]:80;
  server_name $DOMAIN;
  return 301 https://$host$request_uri;
}
NGINX
      ln -sfn "/etc/nginx/sites-available/yourls-${DOMAIN}-http-redirect" "/etc/nginx/sites-enabled/yourls-${DOMAIN}-http-redirect"
      rm -f "/etc/nginx/sites-enabled/yourls-$DOMAIN"
      nginx -t && systemctl reload nginx || warn "Nginx reload failed after self-signed config"
    else
      warn "Continuing without TLS"
    fi
  fi
}

# Determine if Nginx has an SSL server for the domain
tls_active_for_domain() {
  if grep -qE 'listen[[:space:]]+443' "/etc/nginx/sites-available/yourls-$DOMAIN" 2>/dev/null; then
    return 0
  fi
  if [[ -f "/etc/nginx/sites-available/yourls-${DOMAIN}-ssl" ]] && grep -qE 'listen[[:space:]]+443' "/etc/nginx/sites-available/yourls-${DOMAIN}-ssl" 2>/dev/null; then
    return 0
  fi
  return 1
}

restart_services() {
  log "Restarting PHP-FPM and Nginx"
  systemctl restart nginx || true
  # Restart any php-fpm service found
  systemctl list-units --type=service | awk '/php.*fpm/ {print $1}' | xargs -r -I{} systemctl restart {}
}

ensure_dns() {
  if getent hosts "$DOMAIN" >/dev/null; then
    log "DNS for $DOMAIN resolves"
  else
    warn "DNS for $DOMAIN does not resolve yet"
  fi
}

configure_firewall() {
  if command -v ufw >/dev/null 2>&1; then
    if ufw status | grep -q "Status: active"; then
      log "Opening firewall for Nginx Full"
      ufw allow 'Nginx Full' || true
    fi
  fi
}

main() {
  need_root
  parse_args "$@"
  install_packages
  start_db_services
  mysql_ready
  setup_database
  test_db_connection
  fetch_yourls
  configure_nginx
  ensure_dns
  configure_firewall
  obtain_cert
  # Decide actual scheme based on Nginx TLS configuration
  local proto="http"
  if tls_active_for_domain; then proto="https"; fi
  SCHEME="$proto"
  write_config_php
  restart_services

  log "Installation complete. Visit: ${proto}://$DOMAIN/admin/"
}

main "$@"
