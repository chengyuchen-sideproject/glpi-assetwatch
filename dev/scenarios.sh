#!/usr/bin/env bash
# End-to-end scenarios against the dev stack (run dev/up.sh first).
#
# Uses fake GLPI Agent inventories, runs the plugin's automatic actions and the
# GLPI mail queue, then checks alerts in the database and e-mails in Mailpit.
#
#   bash dev/scenarios.sh            # full run (resets plugin data and Mailpit first)
#   bash dev/scenarios.sh --keep     # skip the uninstall step, leaving alerts to browse in the UI
#
# Exit code 0 only when every check passes.
set -uo pipefail

KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

DEV_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE=(docker compose -f "$DEV_DIR/docker-compose.yml")
PY="${PYTHON:-python}"
# Windows Python defaults to the ANSI code page and writes CRLF; force UTF-8 and
# strip CR so Chinese subjects match and IDs work in URLs (no-op on Linux).
py() { PYTHONUTF8=1 "$PY" "$@" | tr -d '\r'; }
PASS=0
FAIL=0

log() { printf '\n== %s\n' "$*"; }
sql() { "${COMPOSE[@]}" exec -T mariadb mariadb -uglpi -pglpi glpi -N -B -e "$1"; }
cron() { "${COMPOSE[@]}" exec -T glpi php front/cron.php --force "$1" >/dev/null; }
# GLPI stores last_inventory_update with 1-second precision: two inventories of the
# same machine within one second look like "no new inventory", hence the pause.
agent() { sleep 1.1; py "$DEV_DIR/fake_agent.py" "$@" >/dev/null || { echo "agent failed: $*"; FAIL=$((FAIL + 1)); }; }
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

# GLPI 11 stores raw text (GLPI 10 HTML-encoded "&", "<", ">").
ARROW='->'


flush_mail() {
    # The image's own cron worker may be running the mail queue at the same time
    # (a locked task is skipped), so retry until the queue is really empty.
    for _ in $(seq 1 20); do
        cron queuednotification
        [ "$(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE is_deleted=0")" = "0" ] && break
        sleep 3
    done
    sleep 2
    echo "  (queue left: $(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE is_deleted=0"), sent: $(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE is_deleted=1"), in Mailpit: $(mails | py -c "import json,sys; print(json.load(sys.stdin).get('total', '?'))"))"
}

mail_text_count() {
    # mail_text_count <substring of the plain-text body>
    local total=0
    for id in $(mails | py -c "import json,sys; [print(m['ID']) for m in json.load(sys.stdin).get('messages', [])]"); do
        total=$((total + $(curl -s "http://localhost:8025/api/v1/message/$id" | py -c "import json,sys; print(1 if sys.argv[1] in json.load(sys.stdin).get('Text', '') else 0)" "$1")))
    done
    echo "$total"
}

mail_text_count_in() {
    # mail_text_count_in <subject substring> <substring of the plain-text body>
    local total=0
    for id in $(mails | py -c "import json,sys; [print(m['ID']) for m in json.load(sys.stdin).get('messages', []) if sys.argv[1] in m.get('Subject', '')]" "$1"); do
        total=$((total + $(curl -s "http://localhost:8025/api/v1/message/$id" | py -c "import json,sys; print(1 if sys.argv[1] in json.load(sys.stdin).get('Text', '') else 0)" "$2")))
    done
    echo "$total"
}

mail_recipients() {
    # mail_recipients <subject substring> -> sorted unique recipient addresses, comma separated
    mails | py -c "import json,sys; d=json.load(sys.stdin); print(','.join(sorted({t['Address'] for m in d.get('messages', []) if sys.argv[1] in m.get('Subject', '') for t in m.get('To', [])})))" "$1"
}

mail_debug() {
    sql "SELECT id, itemtype, event, mode, sent_try, is_deleted, LEFT(name, 80) FROM glpi_queuednotifications ORDER BY id" | head -20
    mails | py -c "import json,sys; [print('   mail:', m.get('Subject')) for m in json.load(sys.stdin).get('messages', [])]"
    "${COMPOSE[@]}" exec -T glpi sh -c 'tail -n 30 /var/glpi/logs/mail-error.log 2>/dev/null; tail -n 30 /var/glpi/logs/mail.log 2>/dev/null' || true
}

mail_count() {
    # mail_count <subject substring>
    mails | py -c "import json,sys; d=json.load(sys.stdin); print(sum(1 for m in d.get('messages', []) if sys.argv[1] in m.get('Subject', '')))" "$1"
}

log "Reset plugin data, Mailpit and test machines"
sql "DELETE FROM glpi_items_racks WHERE itemtype='Computer' AND items_id IN (SELECT id FROM glpi_computers WHERE name IN ('aw-web01','aw-web02','aw-win01'));"
# Leftover machines from a previous run carry changed IP/MAC; the image's background
# cron could baseline that old state mid-run and raise a spurious identity alert.
glpi_action purge aw-web01 aw-web02 aw-win01 aw-aud01 aw-aud02 aw-aud03 aw-aud04
glpi_action audit-cleanup
sql "DELETE FROM glpi_plugin_assetwatch_alerts; DELETE FROM glpi_plugin_assetwatch_snapshots; DELETE FROM glpi_plugin_assetwatch_digests; DELETE FROM glpi_queuednotifications;"
# Machines of the 2.5 step (left over by an interrupted run).
glpi_action purge aw-pr-ok aw-pr-down aw-pr-dead aw-hl01
curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null

# The checks expect English mails until step 11; remember the glpi user's own
# language choice (made in the UI) and put it back on any exit.
ORIG_LANG=$(sql "SELECT IFNULL(CONCAT(\"'\", language, \"'\"), 'NULL') FROM glpi_users WHERE name='glpi'")
restore_language() { sql "UPDATE glpi_users SET language=$ORIG_LANG WHERE name='glpi';"; }
trap restore_language EXIT
sql "UPDATE glpi_users SET language=NULL WHERE name='glpi';"

log "1. First inventories only record a baseline"
agent aw-web01
agent aw-web02
agent aw-win01 --os windows
# GLPI 11: technical groups are rows of glpi_groups_items (type 2 = tech), several per asset.
# aw-win01 belongs to two teams: its status news must reach both digests.
sql "DELETE gi FROM glpi_groups_items gi JOIN glpi_computers c ON c.id=gi.items_id AND gi.itemtype='Computer' WHERE c.name LIKE 'aw-%';
INSERT INTO glpi_groups_items (groups_id, itemtype, items_id, type)
SELECT g.id, 'Computer', c.id, 2 FROM glpi_groups g, glpi_computers c
WHERE (g.name='Server Team' AND c.name IN ('aw-web01','aw-web02','aw-win01'))
   OR (g.name='Storage Team' AND c.name='aw-win01');"
cron AssetwatchChanges
expect "3 baselines recorded" 3 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_snapshots s JOIN glpi_computers c ON c.id=s.computers_id WHERE c.name LIKE 'aw-%'")"
expect "no alert after baseline" 0 "$(count_alerts '1=1')"

log "2. Same inventory again: still nothing"
agent aw-web01
cron AssetwatchChanges
expect "identical inventory raises nothing" 0 "$(count_alerts '1=1')"

log "3. Hardware change: memory module and one drive removed"
WEB01_D1=$(py "$DEV_DIR/fake_agent.py" aw-web01 --dump | py -c "import json,sys; print(json.load(sys.stdin)['content']['storages'][0]['serial'])")
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
# Run of step 5: one digest per team of aw-win01 (Server + Storage); run of step 6: Server Team.
expect "three digests so far (one per team and run with news)" 3 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_digests")"

log "7. Rack placement through GLPI objects (real hook)"
glpi_action rack-place aw-web01 R01 10 >/dev/null
glpi_action rack-place aw-web01 R02 20 >/dev/null
glpi_action rack-remove aw-web01 >/dev/null
expect "3 rack events (added, moved, removed)" 3 "$(count_alerts "alert_type='rack' AND item_name='aw-web01'")"
expect "move described with old and new rack" 1 "$(count_alerts "alert_type='rack' AND summary LIKE '%R01 / U10 $ARROW R02 / U20%'")"
glpi_action rack-place aw-win01 R04 1 >/dev/null
expect "rack event on a machine of two teams is recorded once" 1 "$(count_alerts "alert_type='rack' AND item_name='aw-win01'")"

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
expect "rack change of aw-win01 reaches both teams" "server-team@assetwatch.test,storage-team@assetwatch.test" "$(mail_recipients 'Rack placement change: aw-win01')"
expect "status digest mails" 3 "$(mail_count '[Asset Watch] Server Team')"
expect "second team gets its own digests (disk low + resolved)" 2 "$(mail_count '[Asset Watch] Storage Team')"
expect "Storage Team digests only list aw-win01" 0 "$(mail_text_count_in 'Storage Team' 'aw-web0')"
[ "$FAIL" -gt 0 ] && mail_debug

log "10. Web UI (pages, tabs, forms)"
agent aw-win01 --os windows --disk C::51200:1024
cron AssetwatchStatus
OPEN_ID=$(sql "SELECT id FROM glpi_plugin_assetwatch_alerts WHERE alert_type='disk_low' AND status=1 ORDER BY id DESC LIMIT 1")
WEB01_ID=$(sql "SELECT id FROM glpi_computers WHERE name='aw-web01'")
PROFILE_ID=$(sql "SELECT id FROM glpi_profiles WHERE name='Super-Admin'")
if py "$DEV_DIR/ui_smoke.py" --alert-id "$OPEN_ID" --computer-id "$WEB01_ID" --profile-id "$PROFILE_ID"; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
fi
expect "acknowledged alert has status 2" 2 "$(sql "SELECT status FROM glpi_plugin_assetwatch_alerts WHERE id=$OPEN_ID")"

log "11. Traditional Chinese recipient gets the zh_TW template and labels"
curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null
sql "UPDATE glpi_users SET language='zh_TW' WHERE name='glpi';"
glpi_action rack-place aw-web02 R03 5 >/dev/null
agent aw-win01 --os windows --disk C::51200:40960
cron AssetwatchStatus
flush_mail
expect "zh_TW rack mail subject" 1 "$(mail_count '[資產監看] 機櫃位置變更：aw-web02')"
expect "zh_TW digest mail subject" 1 "$(mail_count '[資產監看] Server Team')"
expect "resolved notice shows current free space" 1 "$(mail_text_count '[C:] 剩 40.0 GB')"
expect "rack field label translated" 1 "$(mail_text_count '機櫃位置 [新增]：+ R03 / U5')"
for id in $(mails | py -c "import json,sys; [print(m['ID']) for m in json.load(sys.stdin).get('messages', [])]"); do
    curl -s "http://localhost:8025/api/v1/message/$id" | py -c "
import json, sys
m = json.load(sys.stdin)
print('  ---', m.get('Subject'))
print('\n'.join('  | ' + line for line in m.get('Text', '').strip().splitlines()))"
done
restore_language

log "11b. Datacenter inventory: rooms, racks, discrepancies, room networks, dashboard filter"
sql "UPDATE glpi_users SET language=NULL WHERE name='glpi';"
glpi_action audit-cleanup
glpi_action purge aw-aud01 aw-aud02 aw-aud03
agent aw-aud01 --ip 10.1.5.11
agent aw-aud02 --ip 10.1.5.12
agent aw-aud03 --ip 10.1.5.13
# aw-aud03: last report 40 days ago = "probably removed" (default 30 days)
cron AssetwatchChanges   # baselines = hardware summary of the inventory page
sql "UPDATE glpi_computers SET last_inventory_update = NOW() - INTERVAL 40 DAY WHERE name='aw-aud03';"
FIXTURE=$(glpi_action audit-fixture)
ROOM_A=$(echo "$FIXTURE" | py -c "import json,sys; print(json.load(sys.stdin)['rooms']['AW Room A'])")
ROOM_B=$(echo "$FIXTURE" | py -c "import json,sys; print(json.load(sys.stdin)['rooms']['AW Room B'])")
DC_ID=$(sql "SELECT id FROM glpi_datacenters WHERE name='AW DC'")
sql "UPDATE glpi_configs SET value='{\"$ROOM_A\":\"10.1.0.0/16\",\"$ROOM_B\":\"172.16.0.0/12\"}' WHERE context='plugin:assetwatch' AND name='room_subnets';"
AUDIT=$(glpi_action audit-json "dc:$DC_ID")
aj() { echo "$AUDIT" | py -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
expect "6 assets in the datacenter (5 racked + 1 by location)" 6 "$(aj "len(d['rows'])")"
expect "rack order: top U first in AW-RA1" "aw-sw01,aw-ghost,aw-aud01,aw-pdu01" "$(aj "','.join(n for n, r in d['rows'].items() if r['rack'] == 'AW-RA1')")"
expect "statuses" "ok=2 retired=1 no_agent=3" "$(aj "' '.join(f'{k}={v}' for k, v in d['status'].items() if v)")"
expect "manual computer in a rack: never reported" "never_reported,duplicate_serial,no_tech_group" "$(aj "','.join(d['rows']['aw-ghost']['issues'])")"
expect "switch and PDU without agent: no discrepancy" "[] []" "$(aj "str(d['rows']['aw-sw01']['issues']) + ' ' + str(d['rows']['aw-pdu01']['issues'])")"
expect "computer of room A by location, in no rack" "AW Room A unracked" "$(aj "d['rows']['aw-aud02']['room'] + ' ' + d['rows']['aw-aud02']['issues'][0]")"
expect "40 days without report: probably removed" "retired" "$(aj "d['rows']['aw-aud03']['status']")"
expect "room B expects 172.16/12: 10.1.5.13 flagged" "10.1.5.13" "$(aj "','.join(d['rows']['aw-aud03']['ips']) if 'subnet_mismatch' in d['rows']['aw-aud03']['issues'] else 'none'")"
expect "room A networks match: no subnet issue" 0 "$(aj "sum('subnet_mismatch' in r['issues'] for n, r in d['rows'].items() if r['room'] == 'AW Room A')")"
expect "hardware summary from the snapshot" "32 GB" "$(aj "d['rows']['aw-aud01']['spec'][:5]")"
expect "unassigned scope lists computers without rack nor location" 0 "$(glpi_action audit-json unassigned | py -c "import json,sys; d=json.load(sys.stdin); print(sum(n.startswith('aw-aud') for n in d['rows']))")"
card_number() { glpi_action card "$@" | py -c "import json,sys; print(json.load(sys.stdin)['number'])"; }
expect "monitored card, room A filter" 2 "$(card_number monitored "plugin_assetwatch_dcroom=$ROOM_A")"
expect "monitored card, room B filter" 1 "$(card_number monitored "plugin_assetwatch_dcroom=$ROOM_B")"
expect "audit card, room A filter (ghost, aud01, aud02)" 3 "$(card_number auditIssues "plugin_assetwatch_dcroom=$ROOM_A")"
if py "$DEV_DIR/ui_smoke.py" --audit "$ROOM_A" "$ROOM_B" "$DC_ID"; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
fi

log "11c. Floor map, rack labels, network topology (2.3)"
TOPO=$(glpi_action topology-fixture)
tj() { echo "$TOPO" | py -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
SW1=$(tj "d['sw1']"); SW2=$(tj "d['sw2']"); GHOST=$(tj "d['ghost']"); RA1=$(tj "d['ra1']")
MAP=$(glpi_action roommap-json "$ROOM_A")
mj() { echo "$MAP" | py -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
expect "floor map: AW-RA1 at 1,1 with 2 to check, 5/42U (2U model), 500/1000 W" "1,1 2 12 50" "$(mj "'%s,%s %s %s %s' % tuple(d['racks']['AW-RA1'][k] for k in ('x', 'y', 'issues', 'units_pct', 'power_pct'))")"
expect "floor map levels (issues, units, power)" "warn ok ok" "$(mj "' '.join(d['racks']['AW-RA1']['levels'].values())")"
expect "floor map: grid cropped to the racks + 1 cell, unracked counted" "2 1" "$(mj "'%s %s' % (d['cols'], d['unracked'])")"
TJSON=$(glpi_action topology-json "dc:$DC_ID")
tt() { echo "$TJSON" | py -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
expect "topology: GLPI port + typed (with and without port)" "aw-aud01=aw-sw01 Gi1/0/10 auto;aw-aud02=aw-sw01 manual;aw-aud03=aw-sw02 Gi1/0/5 manual" "$(tt "';'.join(k + '=' + ','.join(v) for k, v in sorted(d['devices'].items()))")"
expect "topology: link between switches, both ends" "aw-sw01 Te1/1 - aw-sw02 Te1/48" "$(tt "';'.join(d['peers'])")"
expect "topology: computer without connection" "aw-ghost" "$(tt "','.join(d['unconnected'])")"
expect "topology of room B: switch of room A drawn as outside" "aw-sw02,aw-sw01 1" "$(glpi_action topology-json "room:$ROOM_B" | py -c "import json,sys; d=json.load(sys.stdin); print(','.join(d['switches']), d['counts']['switches'])")"
expect "rack QR code opens the inventory page of the rack" "/plugins/assetwatch/front/audit.php?scope=room%3A$ROOM_A&rack=$RA1" "$(glpi_action rack-path "$RA1")"
if py "$DEV_DIR/ui_smoke.py" --topology "$ROOM_A" "$ROOM_B" "$DC_ID" "$SW1" "$SW2" "$GHOST" "$RA1"; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
fi
expect "typed connection written to the device history (added + removed)" 2 "$(sql "SELECT COUNT(*) FROM glpi_logs WHERE itemtype='Computer' AND items_id=$GHOST AND new_value LIKE 'Network connection %: eth1 → aw-sw02 Gi1/0/5'")"
expect "2 typed connections before the purge" 2 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_uplinks")"
# leave no trace for the later steps
glpi_action audit-cleanup
expect "purging the switches drops the typed connections" 0 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_uplinks")"
glpi_action purge aw-aud01 aw-aud02 aw-aud03
sql "UPDATE glpi_configs SET value='{}' WHERE context='plugin:assetwatch' AND name='room_subnets';"
restore_language

log "11d. Liveness probe, inventory now, reboot, antivirus, agent version, disk trend (2.5)"
cfg() { sql "UPDATE glpi_configs SET value='$2' WHERE context='plugin:assetwatch' AND name='$1';"; }
# The image's own cron worker runs due tasks every minute: push them far away during this step,
# and wait when one is running (a forced run is skipped while the task is locked).
sql "UPDATE glpi_crontasks SET frequency=86400, lastrun=NOW() WHERE name IN ('AssetwatchProbe','AssetwatchStatus','AssetwatchChanges');"
cron_now() {
    for _ in $(seq 1 15); do
        [ "$(sql "SELECT state FROM glpi_crontasks WHERE name='$1'")" = 2 ] || break
        sleep 2
    done
    cron "$1"
}
GLPI_IP=$("${COMPOSE[@]}" exec -T glpi hostname -i | awk '{print $1}' | tr -d '\r')
DB_IP=$("${COMPOSE[@]}" exec -T glpi getent hosts mariadb | awk '{print $1}' | tr -d '\r')
# A fake agent HTTP endpoint in the glpi container (answers /status and /now).
"${COMPOSE[@]}" exec -T glpi sh -c 'pkill -f fake_agent_httpd 2>/dev/null; : > /tmp/aw-fakeagent.log' || true
"${COMPOSE[@]}" exec -d glpi php -S 0.0.0.0:62354 plugins/assetwatch/dev/fake_agent_httpd.php
glpi_action purge aw-pr-ok aw-pr-down aw-pr-dead aw-hl01
agent aw-pr-ok --ip "$GLPI_IP"
agent aw-pr-down --ip "$DB_IP"
agent aw-pr-dead --ip 192.0.2.10
agent aw-hl01 --ip 10.30.0.1 --boot-time "2026-11-01 08:00:00" --disk /data:102400:40960 --antivirus Defender:1:1
# Only the machines of this step are probed (the others have fake addresses).
sql "DELETE a FROM glpi_agents a JOIN glpi_computers c ON c.id=a.items_id AND a.itemtype='Computer' WHERE c.name NOT LIKE 'aw-pr-%' AND c.name <> 'aw-hl01';
INSERT INTO glpi_groups_items (groups_id, itemtype, items_id, type)
SELECT g.id, 'Computer', c.id, 2 FROM glpi_groups g, glpi_computers c WHERE g.name='Server Team' AND c.name IN ('aw-pr-down','aw-pr-dead','aw-hl01');"
cfg check_probe 0
cfg check_reboot 1
cfg check_antivirus 1
cfg check_disk_forecast 1
cfg probe_devices 1
cfg disk_forecast_days 14
cfg agent_min_version ''
cfg reboot_quiet_hours ''
sql "DELETE FROM glpi_plugin_assetwatch_probes; DELETE FROM glpi_plugin_assetwatch_diskhistories WHERE computers_id NOT IN (SELECT id FROM glpi_computers);"
cfg probe_tcp_ports 3306
cfg probe_timeout 2
cfg probe_fail_count 2
# Targets: the 4 machines with an agent, and aw-web02 / aw-win01 (in a rack, no agent left: TCP only).
expect "probe test (dry run): 1 answers, 1 agent down, 4 not answering" '{"total":6,"ok":1,"agent_down":1,"unreachable":4,"failures":{"aw-hl01":"unreachable","aw-pr-dead":"unreachable","aw-pr-down":"agent_down","aw-web02":"unreachable","aw-win01":"unreachable"}}' "$(glpi_action probe-dry)"
expect "dry run saves nothing" 0 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_probes")"
cron_now AssetwatchProbe
expect "probe disabled: nothing done" 0 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_probes")"
cfg check_probe 1
flush_mail >/dev/null
curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null
cron_now AssetwatchProbe
expect "first failure: no alert yet" 0 "$(count_alerts "alert_type IN ('unreachable','agent_down')")"
cron_now AssetwatchProbe
expect "second failure: alerts open (racked machines without agent too)" "aw-hl01=unreachable,aw-pr-dead=unreachable,aw-pr-down=agent_down,aw-web02=unreachable,aw-win01=unreachable" "$(sql "SELECT GROUP_CONCAT(CONCAT(item_name,'=',alert_type) ORDER BY item_name) FROM glpi_plugin_assetwatch_alerts WHERE status=1 AND alert_type IN ('unreachable','agent_down')")"
flush_mail >/dev/null
expect "probe alerts in the Server Team digest" 1 "$(mail_text_count_in 'Server Team' 'aw-pr-down')"
cron_now AssetwatchProbe
expect "third failure: same alerts, no duplicate" 5 "$(count_alerts "status=1 AND alert_type IN ('unreachable','agent_down')")"
expect "digest explains 'agent not answering'" 1 "$(mail_text_count_in 'Server Team' 'Agent not answering')"
# aw-pr-dead comes back (new address that answers)
agent aw-pr-dead --ip "$GLPI_IP"
cron_now AssetwatchProbe
expect "answers again: alert resolved" "3" "$(sql "SELECT status FROM glpi_plugin_assetwatch_alerts WHERE item_name='aw-pr-dead' AND alert_type='unreachable'")"
expect "inventory page shows the probe: aw-pr-down offline" "offline" "$(glpi_action audit-json unassigned | py -c "import json,sys; print(json.load(sys.stdin)['rows']['aw-pr-down']['status'])")"
now_hits() { "${COMPOSE[@]}" exec -T glpi sh -c 'grep -c " /now" /tmp/aw-fakeagent.log || true' | tr -d '\r'; }
NOW_BEFORE=$(now_hits)
expect "inventory now: 2 requested, 1 not answering" '2 0 1' "$(glpi_action request-inventory aw-pr-ok aw-pr-dead aw-pr-down | py -c "import json,sys; d=json.load(sys.stdin); print(d['requested'], d['refused'], d['failed'])")"
expect "agents received /now" 2 "$(( $(now_hits) - NOW_BEFORE ))"
if py "$DEV_DIR/ui_smoke.py" --probe; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
fi
cfg check_probe 0

# Unexpected reboot (boot time changed), quiet hours.
cron_now AssetwatchChanges
agent aw-hl01 --ip 10.30.0.1 --boot-time "2026-11-15 10:30:00" --disk /data:102400:40960 --antivirus Defender:1:1
cron_now AssetwatchChanges
expect "reboot: one-shot alert" "Rebooted at 2026-11-15 10:30:00 (previous boot: 2026-11-01 08:00:00)" "$(sql "SELECT summary FROM glpi_plugin_assetwatch_alerts WHERE item_name='aw-hl01' AND alert_type='reboot'")"
flush_mail >/dev/null
expect "reboot in the Server Team digest" 1 "$(mail_text_count_in 'Server Team' 'Unexpected reboot')"
cfg reboot_quiet_hours '10:00-11:00'
agent aw-hl01 --ip 10.30.0.1 --boot-time "2026-11-15 10:45:00" --disk /data:102400:40960 --antivirus Defender:1:1
cron_now AssetwatchChanges
expect "reboot in quiet hours: no alert" 1 "$(count_alerts "item_name='aw-hl01' AND alert_type='reboot'")"
cfg reboot_quiet_hours ''

# Antivirus disabled, then fixed.
agent aw-hl01 --ip 10.30.0.1 --boot-time "2026-11-15 10:45:00" --disk /data:102400:40960 --antivirus Defender:0:1
cron_now AssetwatchChanges
cron_now AssetwatchStatus
expect "antivirus disabled: alert" "Defender: disabled" "$(sql "SELECT summary FROM glpi_plugin_assetwatch_alerts WHERE item_name='aw-hl01' AND alert_type='antivirus' AND status=1")"
agent aw-hl01 --ip 10.30.0.1 --boot-time "2026-11-15 10:45:00" --disk /data:102400:40960 --antivirus Defender:1:1
cron_now AssetwatchStatus
expect "antivirus enabled again: resolved" 3 "$(sql "SELECT status FROM glpi_plugin_assetwatch_alerts WHERE item_name='aw-hl01' AND alert_type='antivirus'")"

# Agent version below the minimum (agents of this step send 1.11).
cfg agent_min_version 1.12
cron_now AssetwatchStatus
expect "outdated agents (4 machines of this step)" 4 "$(count_alerts "alert_type='agent_outdated' AND status=1")"
expect "outdated agent summary" "GLPI Agent 1.11 < 1.12" "$(sql "SELECT summary FROM glpi_plugin_assetwatch_alerts WHERE item_name='aw-hl01' AND alert_type='agent_outdated'")"
cfg agent_min_version ''

# Disk trend: /data lost 10 GB in 5 days (2 GB/day), 40 GB free, low below 10 GB -> about 15 days.
HL=$(sql "SELECT id FROM glpi_computers WHERE name='aw-hl01'")
expect "disk samples recorded at each inventory" 1 "$(sql "SELECT COUNT(*) > 0 FROM glpi_plugin_assetwatch_diskhistories WHERE computers_id=$HL AND mountpoint='/data'")"
sql "INSERT INTO glpi_plugin_assetwatch_diskhistories (computers_id, mountpoint, total_mb, free_mb, date) VALUES
($HL, '/data', 102400, 51200, NOW() - INTERVAL 5 DAY), ($HL, '/data', 102400, 47104, NOW() - INTERVAL 3 DAY);
UPDATE glpi_plugin_assetwatch_diskhistories SET date=NOW() WHERE computers_id=$HL AND mountpoint='/data' AND date > NOW() - INTERVAL 1 DAY;"
cron_now AssetwatchStatus
expect "15 days is beyond the 14-day horizon: no alert" 0 "$(count_alerts "alert_type='disk_forecast'")"
cfg disk_forecast_days 30
cron_now AssetwatchStatus
expect "disk trend alert" "[/data] low in about 15 days (40.0 GB free, -2.0 GB/day, threshold 10.0 GB)" "$(sql "SELECT summary FROM glpi_plugin_assetwatch_alerts WHERE item_name='aw-hl01' AND alert_type='disk_forecast'")"
cfg disk_forecast_days 14
# leave no trace for the later steps
"${COMPOSE[@]}" exec -T glpi sh -c 'pkill -f fake_agent_httpd 2>/dev/null' || true
glpi_action purge aw-pr-ok aw-pr-down aw-pr-dead aw-hl01
cfg probe_tcp_ports '22,3389,443,80'
cfg probe_timeout 3
expect "purging the machines drops their probe state" 0 "$(sql "SELECT COUNT(*) FROM glpi_plugin_assetwatch_probes p LEFT JOIN glpi_computers c ON c.id=p.items_id WHERE c.id IS NULL")"
sql "DELETE FROM glpi_plugin_assetwatch_probes;
DELETE FROM glpi_plugin_assetwatch_alerts WHERE alert_type IN ('unreachable','agent_down','reboot','antivirus','agent_outdated','disk_forecast');
UPDATE glpi_crontasks SET frequency=300 WHERE name IN ('AssetwatchProbe','AssetwatchChanges');
UPDATE glpi_crontasks SET frequency=3600 WHERE name='AssetwatchStatus';"

log "12. No PHP error mentioning the plugin"
ERRORS=$("${COMPOSE[@]}" exec -T glpi sh -c 'cat /var/glpi/logs/php-errors.log 2>/dev/null | grep -ci assetwatch' || true)
expect "php-errors.log clean" 0 "${ERRORS:-0}"
if [ "${ERRORS:-0}" != "0" ]; then
    "${COMPOSE[@]}" exec -T glpi sh -c 'grep -i -B2 -A8 assetwatch /var/glpi/logs/php-errors.log | tail -60'
fi

if [ "$KEEP" -eq 1 ]; then
    log "13. Skipped (--keep): leaving two active alerts for the default list view"
    # Everything above ends resolved or as a one-off event, which the default
    # list filter (active + acknowledged) hides; re-break two machines.
    agent aw-win01 --os windows --disk C::51200:2048
    sql "UPDATE glpi_computers SET last_inventory_update = NOW() - INTERVAL 40 HOUR WHERE name='aw-web02';"
    cron AssetwatchStatus
    expect "two active alerts to browse" 2 "$(count_alerts "status=1")"
    echo "  See http://localhost:8080/plugins/assetwatch/front/alert.php (resolved/events: clear the status filter)"
    printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
    [ "$FAIL" -eq 0 ]
    exit
fi

log "13. Uninstall removes everything, reinstall restores it"
"${COMPOSE[@]}" exec -T glpi php bin/console plugin:uninstall --no-interaction assetwatch 2>&1 | sed 's/^/  | /'
expect "tables dropped" 0 "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='glpi' AND table_name LIKE 'glpi_plugin_assetwatch%'")"
expect "notifications removed" 0 "$(sql "SELECT COUNT(*) FROM glpi_notifications WHERE itemtype LIKE 'PluginAssetwatch%'")"
expect "templates removed" 0 "$(sql "SELECT COUNT(*) FROM glpi_notificationtemplates WHERE itemtype LIKE 'PluginAssetwatch%'")"
expect "automatic actions removed" 0 "$(sql "SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE 'PluginAssetwatch%'")"
expect "rights removed" 0 "$(sql "SELECT COUNT(*) FROM glpi_profilerights WHERE name LIKE 'plugin_assetwatch%'")"
expect "config removed" 0 "$(sql "SELECT COUNT(*) FROM glpi_configs WHERE context='plugin:assetwatch'")"
expect "dashboard removed" 0 "$(sql "SELECT COUNT(*) FROM glpi_dashboards_dashboards WHERE \`key\`='asset-watch'")"
expect "plugin cards removed from every dashboard" 0 "$(sql "SELECT COUNT(*) FROM glpi_dashboards_items WHERE card_id LIKE 'plugin_assetwatch_%'")"
"${COMPOSE[@]}" exec -T glpi php bin/console plugin:install --username=glpi assetwatch >/dev/null
"${COMPOSE[@]}" exec -T glpi php bin/console plugin:activate assetwatch >/dev/null
expect "reinstall: 4 notifications" 4 "$(sql "SELECT COUNT(*) FROM glpi_notifications WHERE itemtype LIKE 'PluginAssetwatch%'")"
expect "reinstall: 4 automatic actions in CLI mode" 4 "$(sql "SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE 'PluginAssetwatch%' AND mode=2")"
expect "reinstall: dashboard with 9 cards" 9 "$(sql "SELECT COUNT(*) FROM glpi_dashboards_items i JOIN glpi_dashboards_dashboards d ON d.id=i.dashboards_dashboards_id WHERE d.\`key\`='asset-watch'")"
expect "reinstall: dashboard visible to Super-Admin" 1 "$(sql "SELECT COUNT(*) FROM glpi_dashboards_rights r JOIN glpi_dashboards_dashboards d ON d.id=r.dashboards_dashboards_id JOIN glpi_profiles p ON p.id=r.items_id AND r.itemtype='Profile' WHERE d.\`key\`='asset-watch' AND p.name='Super-Admin'")"
expect "reinstall: Super-Admin has full alert rights" 257 "$(sql "SELECT pr.rights FROM glpi_profilerights pr JOIN glpi_profiles p ON p.id=pr.profiles_id WHERE p.name='Super-Admin' AND pr.name='plugin_assetwatch_alert'")"
"${COMPOSE[@]}" exec -T glpi php bin/console plugin:install --username=glpi --force assetwatch >/dev/null
expect "re-running install does not duplicate notifications" 4 "$(sql "SELECT COUNT(*) FROM glpi_notifications WHERE itemtype LIKE 'PluginAssetwatch%'")"
# A forced install leaves the plugin deactivated; re-activate so the stack stays usable
# (and a second run of this script works).
"${COMPOSE[@]}" exec -T glpi php bin/console plugin:activate assetwatch >/dev/null

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
