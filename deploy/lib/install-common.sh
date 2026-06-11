#!/usr/bin/env bash
# Functii comune deploy — source din vps-add-project.sh / vps-bootstrap.sh

pick_ocpp_port() {
  local preferred="${1:-9010}"
  if ! command -v ss >/dev/null 2>&1; then
    echo "${preferred}"
    return
  fi

  if ! ss -tln 2>/dev/null | awk '{print $4}' | grep -qE ":${preferred}$"; then
    echo "${preferred}"
    return
  fi

  local port
  for port in 9010 9011 9012 9002 9003 9004 9005; do
    if ! ss -tln 2>/dev/null | awk '{print $4}' | grep -qE ":${port}$"; then
      echo "${port}"
      return
    fi
  done

  echo "Nu am gasit port liber pentru OCPP (incercat 9010-9012, 9002-9005)." >&2
  exit 1
}

detect_php_fpm_socket() {
  local version="${1:-}"
  if [ -n "${version}" ] && [ -S "/run/php/php${version}-fpm.sock" ]; then
    echo "/run/php/php${version}-fpm.sock"
    return
  fi

  local socket
  for socket in /run/php/php8.3-fpm.sock /run/php/php8.2-fpm.sock /run/php/php8.1-fpm.sock; do
    if [ -S "${socket}" ]; then
      echo "${socket}"
      return
    fi
  done

  echo "Nu am gasit socket PHP-FPM (/run/php/php*-fpm.sock)." >&2
  exit 1
}

render_nginx_site() {
  local template="${1}"
  local output="${2}"
  local domain="${3}"
  local app_root="${4}"
  local ocpp_port="${5}"
  local php_socket="${6}"

  sed \
    -e "s|ocpp.volta.md|${domain}|g" \
    -e "s|/var/www/evvolta|${app_root}|g" \
    -e "s|127.0.0.1:9000|127.0.0.1:${ocpp_port}|g" \
    -e "s|unix:/run/php/php8.3-fpm.sock|unix:${php_socket}|g" \
    "${template}" > "${output}"
}

render_systemd_ocpp() {
  local template="${1}"
  local output="${2}"
  local app_root="${3}"
  local ocpp_port="${4}"

  sed \
    -e "s|/var/www/evvolta|${app_root}|g" \
    -e "s|--port=9000|--port=${ocpp_port}|g" \
    "${template}" > "${output}"
}

install_evvolta_cron() {
  local app_root="${1}"
  local marker="# evvolta-scheduler"
  local line="* * * * * cd ${app_root}/backend && php artisan schedule:run >> /dev/null 2>&1 ${marker}"

  local existing
  existing="$(crontab -u www-data -l 2>/dev/null || true)"
  printf '%s\n' "${existing}" | grep -v "${marker}" | { cat; echo "${line}"; } | crontab -u www-data -
}
