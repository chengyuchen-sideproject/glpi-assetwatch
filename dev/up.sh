#!/usr/bin/env bash
# Start the development stack and prepare GLPI for Asset Watch testing:
#   - wait for the official image to auto-install GLPI
#   - enable inventory + e-mail notifications (SMTP -> Mailpit)
#   - install and activate the plugin
#   - create a "Server Team" technical group that receives the alerts
#
# Safe to re-run: every step is idempotent.
set -euo pipefail

DEV_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE=(docker compose -f "$DEV_DIR/docker-compose.yml")

log() { printf '[up] %s\n' "$*"; }

console() { "${COMPOSE[@]}" exec -T glpi php bin/console "$@"; }

sql() { "${COMPOSE[@]}" exec -T mariadb mariadb -uglpi -pglpi glpi -e "$1"; }

log "Starting containers"
"${COMPOSE[@]}" up -d

log "Waiting for GLPI auto-install (first start takes about a minute)"
for _ in $(seq 1 120); do
    if "${COMPOSE[@]}" exec -T glpi test -f /var/glpi/config/config_db.php 2>/dev/null \
        && console db:check --quiet >/dev/null 2>&1; then
        break
    fi
    sleep 3
done
console db:check --quiet >/dev/null 2>&1 || { log "GLPI is not ready, check: docker compose -f dev/docker-compose.yml logs glpi"; exit 1; }

log "Configuring GLPI (inventory, notifications, SMTP to Mailpit)"
console config:set url_base http://localhost:8080 >/dev/null
console config:set use_notifications 1 >/dev/null
console config:set notifications_mailing 1 >/dev/null
console config:set smtp_mode 1 >/dev/null
console config:set smtp_host mailpit >/dev/null
console config:set smtp_port 1025 >/dev/null
console config:set smtp_check_certificate 0 >/dev/null
console config:set admin_email glpi@assetwatch.test >/dev/null
console config:set enabled_inventory 1 --context inventory >/dev/null

log "Installing and activating the plugin"
console plugin:install --username=glpi assetwatch || true
console plugin:activate assetwatch || true

log "Creating the 'Server Team' technical group (members: glpi user)"
sql "
INSERT INTO glpi_groups (entities_id, is_recursive, name, completename, level, is_notify, is_assign, is_itemgroup, is_usergroup, is_manager, is_requester, is_watcher, date_creation, date_mod)
SELECT 0, 1, 'Server Team', 'Server Team', 1, 1, 1, 1, 1, 1, 1, 1, NOW(), NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM glpi_groups WHERE name = 'Server Team');
INSERT INTO glpi_groups_users (users_id, groups_id, is_dynamic, is_manager, is_userdelegate)
SELECT u.id, g.id, 0, 0, 0 FROM glpi_users u, glpi_groups g
WHERE u.name = 'glpi' AND g.name = 'Server Team'
  AND NOT EXISTS (SELECT 1 FROM glpi_groups_users gu WHERE gu.users_id = u.id AND gu.groups_id = g.id);
INSERT INTO glpi_useremails (users_id, is_default, is_dynamic, email)
SELECT u.id, 1, 0, 'server-team@assetwatch.test' FROM glpi_users u
WHERE u.name = 'glpi' AND NOT EXISTS (SELECT 1 FROM glpi_useremails e WHERE e.users_id = u.id);
"

log "Ready: GLPI http://localhost:8080 (glpi/glpi) - Mailpit http://localhost:8025"
