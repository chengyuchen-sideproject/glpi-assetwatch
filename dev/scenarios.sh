#!/usr/bin/env bash
# End-to-end scenarios against the dev stack (run dev/up.sh first).
#
# Uses fake GLPI Agent inventories, runs the plugin's automatic actions and the
# GLPI mail queue, then checks alerts in the database and e-mails in Mailpit.
#
#   bash dev/scenarios.sh            # full run (resets plugin data and Mailpit first)
#
# Exit code 0 only when every check passes.
set -uo pipefail

DEV_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE=(docker compose -f "$DEV_DIR/docker-compose.yml")
PY="${PYTHON:-python}"
PASS=0
FAIL=0

log() { printf '\n== %s\n' "$*"; }
sql() { "${COMPOSE[@]}" exec -T mariadb mariadb -uglpi -pglpi glpi -N -B -e "$1"; }
cron() { "${COMPOSE[@]}" exec -T glpi php front/cron.php --force "$1" >/dev/null; }
# GLPI stores last_inventory_update with 1-second precision: two inventories of the
# same machine within one second look like "no new inventory", hence the pause.
agent() { sleep 1.1; "$PY" "$DEV_DIR/fake_agent.py" "$@" >/dev/null || { echo "agent failed: $*"; FAIL=$((FAIL + 1)); }; }
glpi_action() { "${COMPOSE[@]}" exec -T glpi php plugins/assetwatch/dev/glpi_actions.php "$@"; }
mails() { curl -s "http://localhost:8025/api/v1/messages?limit=200"; }

expect() {
    # expect <description> <expected> <actual>
    if [ "$2" = "$3" ]; then
        printf '  PASS  %s\n' "$1"
        PASS=$((PASS + 1))
    else
        printf '  FAIL  %s (expected %s, got %s)\n' "$1" "$2" "$3"
        FAIL=$((FAIL + 1))
    fi
}

count_alerts() { sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_alerts WHERE $1"; }

# Text columns follow GLPI 10 storage convention: "&", "<", ">" are HTML-encoded.
ARROW='-&#62;'


flush_mail() {
    # The image's own cron worker may be running the mail queue at the same time
    # (a locked task is skipped), so retry until the queue is really empty.
    for _ in $(seq 1 20); do
        cron queuednotification
        [ "$(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE is_deleted=0")" = "0" ] && break
        sleep 3
    done
    sleep 2
    echo "  (queue left: $(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE is_deleted=0"), sent: $(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE is_deleted=1"), in Mailpit: $(mails | "$PY" -c "import json,sys; print(json.load(sys.stdin).get('total', '?'))"))"
}

mail_debug() {
    sql "SELECT id, itemtype, event, mode, sent_try, is_deleted, LEFT(name, 80) FROM glpi_queuednotifications ORDER BY id" | head -20
    mails | "$PY" -c "import json,sys; [print('   mail:', m.get('Subject')) for m in json.load(sys.stdin).get('messages', [])]"
    "${COMPOSE[@]}" exec -T glpi sh -c 'tail -n 30 /var/glpi/logs/mail-error.log 2>/dev/null; tail -n 30 /var/glpi/logs/mail.log 2>/dev/null' || true
}

mail_count() {
    # mail_count <subject substring>
    mails | "$PY" -c "import json,sys; d=json.load(sys.stdin); print(sum(1 for m in d.get('messages', []) if sys.argv[1] in m.get('Subject', '')))" "$1"
}

log "Reset plugin data, Mailpit and test machines"
sql "DELETE FROM glpi_plugin_assetwatch_alerts; DELETE FROM glpi_plugin_assetwatch_snapshots; DELETE FROM glpi_plugin_assetwatch_digests; DELETE FROM glpi_queuednotifications;"
sql "DELETE FROM glpi_items_racks WHERE itemtype='Computer' AND items_id IN (SELECT id FROM glpi_computers WHERE name IN ('aw-web01','aw-web02','aw-win01'));"
curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null

log "1. First inventories only record a baseline"
agent aw-web01
agent aw-web02
agent aw-win01 --os windows
GROUP_ID=$(sql "SELECT id FROM glpi_groups WHERE name='Server Team'")
sql "UPDATE glpi_computers SET groups_id_tech=$GROUP_ID WHERE name IN ('aw-web01','aw-web02','aw-win01');"
cron AssetwatchChanges
expect "3 baselines recorded" 3 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_snapshots s JOIN glpi_computers c ON c.id=s.computers_id WHERE c.name LIKE 'aw-%'")"
expect "no alert after baseline" 0 "$(count_alerts '1=1')"

log "2. Same inventory again: still nothing"
agent aw-web01
cron AssetwatchChanges
expect "identical inventory raises nothing" 0 "$(count_alerts '1=1')"

log "3. Hardware change: memory module and one drive removed"
WEB01_D1=$("$PY" "$DEV_DIR/fake_agent.py" aw-web01 --dump | "$PY" -c "import json,sys; print(json.load(sys.stdin)['content']['storages'][0]['serial'])")
agent aw-web01 --memory 16384 --drives "$WEB01_D1:953869"
cron AssetwatchChanges
expect "hardware alert on aw-web01" 1 "$(count_alerts "alert_type='hardware' AND item_name='aw-web01'")"
expect "memory + drive listed" 1 "$(count_alerts "alert_type='hardware' AND summary LIKE '%memory 32 GB $ARROW 16 GB%' AND summary LIKE '%drive -%'")"

log "4. Identity change: IP and MAC of aw-web02"
agent aw-web02 --ip 10.99.0.2 --mac 00:11:22:33:44:55
cron AssetwatchChanges
expect "identity alert on aw-web02" 1 "$(count_alerts "alert_type='identity' AND item_name='aw-web02'")"
expect "virtual docker0 ignored" 0 "$(count_alerts "summary LIKE '%172.17%'")"

log "5. Low disk on Windows C: (2 GB free of 50 GB)"
agent aw-win01 --os windows --disk C::51200:2048
cron AssetwatchStatus
expect "disk alert on aw-win01 C:" 1 "$(count_alerts "alert_type='disk_low' AND item_name='aw-win01' AND alert_key='C:' AND status=1")"
expect "tmpfs ignored on Linux" 0 "$(count_alerts "alert_type='disk_low' AND alert_key='/run'")"

log "6. Missing inventory: aw-web02 last seen 40 hours ago"
sql "UPDATE glpi_computers SET last_inventory_update = NOW() - INTERVAL 40 HOUR WHERE name='aw-web02';"
cron AssetwatchStatus
expect "no_report alert on aw-web02" 1 "$(count_alerts "alert_type='no_report' AND item_name='aw-web02' AND status=1")"
cron AssetwatchStatus
expect "second run does not duplicate" 1 "$(count_alerts "alert_type='no_report' AND item_name='aw-web02'")"
expect "two digests so far (one per run with news)" 2 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_digests")"

log "7. Rack placement through GLPI objects (real hook)"
glpi_action rack-place aw-web01 R01 10 >/dev/null
glpi_action rack-place aw-web01 R02 20 >/dev/null
glpi_action rack-remove aw-web01 >/dev/null
expect "3 rack events (added, moved, removed)" 3 "$(count_alerts "alert_type='rack' AND item_name='aw-web01'")"
expect "move described with old and new rack" 1 "$(count_alerts "alert_type='rack' AND summary LIKE '%R01 / U10 $ARROW R02 / U20%'")"

log "8. Recovery"
agent aw-win01 --os windows --disk C::51200:40960
agent aw-web02 --ip 10.99.0.2 --mac 00:11:22:33:44:55
cron AssetwatchStatus
expect "disk alert resolved" 1 "$(count_alerts "alert_type='disk_low' AND item_name='aw-win01' AND status=3")"
expect "no_report alert resolved" 1 "$(count_alerts "alert_type='no_report' AND item_name='aw-web02' AND status=3")"

log "9. E-mails delivered to Mailpit"
flush_mail
expect "hardware change mail" 1 "$(mail_count 'Hardware change: aw-web01')"
expect "identity change mail" 1 "$(mail_count 'Identity change: aw-web02')"
expect "rack change mails" 3 "$(mail_count 'Rack placement change: aw-web01')"
expect "status digest mails" 3 "$(mail_count '[Asset Watch] Server Team')"
[ "$FAIL" -gt 0 ] && mail_debug

log "10. Web UI (pages, tabs, forms)"
agent aw-win01 --os windows --disk C::51200:1024
cron AssetwatchStatus
OPEN_ID=$(sql "SELECT id FROM glpi_plugin_assetwatch_alerts WHERE alert_type='disk_low' AND status=1 ORDER BY id DESC LIMIT 1")
WEB01_ID=$(sql "SELECT id FROM glpi_computers WHERE name='aw-web01'")
PROFILE_ID=$(sql "SELECT id FROM glpi_profiles WHERE name='Super-Admin'")
if "$PY" "$DEV_DIR/ui_smoke.py" --alert-id "$OPEN_ID" --computer-id "$WEB01_ID" --profile-id "$PROFILE_ID"; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
fi
expect "acknowledged alert has status 2" 2 "$(sql "SELECT status FROM glpi_plugin_assetwatch_alerts WHERE id=$OPEN_ID")"

log "11. No PHP error mentioning the plugin"
ERRORS=$("${COMPOSE[@]}" exec -T glpi sh -c 'cat /var/glpi/logs/php-errors.log 2>/dev/null | grep -ci assetwatch' || true)
expect "php-errors.log clean" 0 "${ERRORS:-0}"
if [ "${ERRORS:-0}" != "0" ]; then
    "${COMPOSE[@]}" exec -T glpi sh -c 'grep -i -B2 -A8 assetwatch /var/glpi/logs/php-errors.log | tail -60'
fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
