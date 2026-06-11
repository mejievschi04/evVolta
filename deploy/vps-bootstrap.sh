#!/usr/bin/env bash
set -euo pipefail

# VPS gol (prima instalare). Pe un VPS cu proiecte existente foloseste vps-add-project.sh

DOMAIN="${DOMAIN:-ocpp.volta.md}"
DB_PASSWORD="${DB_PASSWORD:-change_me}"
REPO_URL="${REPO_URL:-git@github.com:your-org/evVolta.git}"
APP_ROOT="${APP_ROOT:-/var/www/evvolta}"
INSTALL_SYSTEM_PACKAGES="${INSTALL_SYSTEM_PACKAGES:-1}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "${INSTALL_SYSTEM_PACKAGES}" = "1" ]; then
  echo "==> Pachete sistem (VPS gol)"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y nginx postgresql postgresql-contrib php8.3-fpm php8.3-cli \
    php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd \
    php8.3-bcmath php8.3-intl git curl unzip certbot python3-certbot-nginx
  if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
  fi
  command -v composer >/dev/null 2>&1 || {
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  }
fi

export DOMAIN DB_PASSWORD REPO_URL APP_ROOT
bash "${SCRIPT_DIR}/vps-add-project.sh"
