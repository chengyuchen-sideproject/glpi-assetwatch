#!/usr/bin/env python3
"""UI smoke test: log into the dev GLPI and open / submit every plugin page.

Run after dev/scenarios.sh (needs at least one alert and one computer).
Standard library only. Exit code 0 when every check passes.

    python dev/ui_smoke.py [--base http://localhost:8080] [--alert-id N] [--computer-id N]
"""

import argparse
import http.cookiejar
import re
import sys
import urllib.parse
import urllib.request

FAILS = []


class Client:
    def __init__(self, base):
        self.base = base.rstrip("/")
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def get(self, path, ajax=False):
        headers = {"X-Requested-With": "XMLHttpRequest"} if ajax else {}
        return self._send(urllib.request.Request(self.base + path, headers=headers))

    def post(self, path, data):
        body = urllib.parse.urlencode(data).encode()
        return self._send(urllib.request.Request(self.base + path, data=body, headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "Referer": self.base + path,
        }))

    def _send(self, request):
        try:
            with self.opener.open(request, timeout=60) as response:
                return response.status, response.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as err:
            return err.code, err.read().decode("utf-8", errors="replace")


def check(name, ok, detail=""):
    print(("  PASS  " if ok else "  FAIL  ") + name + ("" if ok else f" ({detail})"))
    if not ok:
        FAILS.append(name)


def csrf(html):
    m = re.search(r'name="_glpi_csrf_token" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ""


def page_ok(name, status, html, marker):
    problems = []
    if status != 200:
        problems.append(f"HTTP {status}")
    for bad in ("Fatal error", "Uncaught", "Warning:</b>", "Notice:</b>", "Twig\\Error"):
        if bad in html:
            problems.append(bad)
    if marker and marker not in html:
        problems.append(f"missing '{marker}'")
    check(name, not problems, ", ".join(problems))


def card(c, card_id, widget, filters=None):
    params = {
        "action": "get_card", "dashboard": "asset-watch", "card_id": f"plugin_assetwatch_{card_id}",
        # the web UI always sends a color; the list widget fails without one
        "args[widgettype]": widget, "args[limit]": 30, "args[color]": "#ffffff", "force": 1,
    }
    for key, value in (filters or {}).items():
        params[f"args[apply_filters][{key}]"] = value
    return c.get(f"/ajax/dashboard.php?{urllib.parse.urlencode(params)}", ajax=True)


def big_number(html):
    # bigNumber widget: <a class="card big-number ..."> ... <span class="number">N</span>
    m = re.search(r'big-number.*?<span class="number">\s*([^<]+?)\s*</span>', html, re.S)
    return m.group(1).strip() if m else "?"


def n_remove(html):
    return html.count('name="remove[]"')


def row_of(html, marker):
    """The <tr> of the edit table that holds marker."""
    end = html.find(marker)
    start = html.rfind("<tr", 0, end)
    return html[start:html.find("</tr>", end)] if end >= 0 and start >= 0 else ""


def topology_checks(c, room_a, room_b, dc_id, sw1, sw2, ghost, ra1):
    """2.3: floor map, rack labels, network topology (fixture of glpi_actions.php topology-fixture)."""
    plugin = "/plugins/assetwatch/front"

    # Floor map in the inventory page and in the server room tab.
    status, html = c.get(f"{plugin}/audit.php?scope=room:{room_a}")
    page_ok("inventory page with floor map", status, html, f'id="aw-map-{room_a}"')
    check("rack AW-RA1 coloured by assets to check (2 = warn)", 'data-issues="warn" data-units="ok" data-power="ok"' in html, "levels not found")
    check("rack card anchor + label link", f'id="aw-rack-{ra1}"' in html and f"racklabel.php?rack={ra1}" in html, "missing")
    check("inventory links the topology", f"topology.php?scope=room%3A{room_a}" in html, "missing")
    status, html = c.get(f"/front/dcroom.form.php?id={room_a}")
    check("server room form has the floor map tab", "PluginAssetwatchRoomMap$1" in html, "tab missing")
    status, html = c.get(f"/ajax/common.tabs.php?_target=/front/dcroom.form.php&_itemtype=DCRoom&_glpi_tab=PluginAssetwatchRoomMap$1&id={room_a}", ajax=True)
    page_ok("floor map tab of the server room", status, html, f'id="aw-map-{room_a}"')
    check("tab links back to the inventory rack card", f"audit.php?scope=room:{room_a}#aw-rack-{ra1}" in html, "link missing")

    # Rack labels
    status, html = c.get(f"{plugin}/racklabel.php?room={room_a}")
    page_ok("rack labels of room A", status, html, "AW-RA1")
    check("labels: 1 rack QR + 4 asset QR", html.count("<svg") == 5, f"{html.count('<svg')} svg")
    cards = html[html.find('class="list"'):]
    order = [cards.find(n) for n in ("aw-sw01", "aw-ghost", "aw-aud01", "aw-pdu01")]
    check("labels numbered from the top of the rack", all(o >= 0 for o in order) and order == sorted(order), str(order))
    check("2U model shown as U10–11", "U10–11" in html, "not found")
    status, html = c.get(f"{plugin}/racklabel.php?rack=999999")
    page_ok("rack label of an unknown rack", status, html, "No rack to print.")

    # Topology drawing
    status, html = c.get(f"{plugin}/topology.php")
    page_ok("topology page (no room chosen)", status, html, f'value="dc:{dc_id}"')
    status, html = c.get("/front/central.php")
    check("Tools menu links the topology page", "/plugins/assetwatch/front/topology.php" in html, "menu entry missing")
    status, html = c.get(f"{plugin}/topology.php?scope=dc:{dc_id}")
    page_ok("topology page (datacenter)", status, html, "<svg")
    for marker in ("aw-sw01", "aw-sw02", "aw-aud01", "aw-aud02", "aw-aud03", "Gi1/0/10", "Gi1/0/5", "Te1/48", "No connection recorded"):
        check(f"topology shows '{marker}'", marker in html, "not found")
    svg = html[html.find("<svg"):html.find("</svg>")]
    check("drawing: 3 device lines + 1 between switches", svg.count("<path ") == 4, f"{svg.count('<path ')} paths")
    check("drawing: typed connections are dashed", svg.count("aw-e-manual") == 2, f"{svg.count('aw-e-manual')}")
    check("drawing: ghost in the unconnected column", "aw-ghost" in svg, "missing")
    status, html = c.get(f"{plugin}/topology.php?scope=room:{room_b}")
    page_ok("topology of room B shows the switch of room A as outside", status, html, "outside the selection")

    status, csv = c.get(f"{plugin}/topology.php?scope=dc:{dc_id}&export=csv")
    lines = csv.strip().splitlines()
    check("topology CSV: header + 3 devices + 1 switch link + 1 unconnected", status == 200 and csv.startswith("\ufeff") and len(lines) == 6,
          f"HTTP {status}, {len(lines)} lines")

    # Edit connections
    status, html = c.get(f"{plugin}/topology.php?scope=dc:{dc_id}&edit=1")
    page_ok("topology edit page", status, html, f'name="add[Computer:{ghost}][switch]"')
    check("edit page offers the switches", f'<option value="{sw2}">aw-sw02' in html, "option missing")
    check("edit page: 2 typed connections removable", html.count('name="remove[]"') == 2, f"{n_remove(html)}")
    c.post(f"{plugin}/topology.php", {"scope": f"dc:{dc_id}", "save": "1", f"add[Computer:{ghost}][switch]": str(sw2)})
    status, html = c.get(f"{plugin}/topology.php?scope=dc:{dc_id}&edit=1")
    check("POST without CSRF token is refused", html.count('name="remove[]"') == 2, f"{n_remove(html)}")
    status, saved = c.post(f"{plugin}/topology.php", {
        "_glpi_csrf_token": csrf(html), "scope": f"dc:{dc_id}", "save": "1",
        f"add[Computer:{ghost}][switch]": str(sw2), f"add[Computer:{ghost}][switch_port]": "Gi1/0/5",
        f"add[Computer:{ghost}][device_port]": "eth1",
    })
    status, html = c.get(f"{plugin}/topology.php?scope=dc:{dc_id}&edit=1")
    check("typed connection saved", "Connections saved: 1 added, 0 removed." in saved and html.count('name="remove[]"') == 3, "not saved")
    check("same port twice is reported", "Port used twice" in html and "aw-aud03, aw-ghost" in html, "no conflict")
    m = __import__("re").search(r'name="remove\[\]" value="(\d+)"', row_of(html, f'add[Computer:{ghost}][switch]'))
    status, removed = c.post(f"{plugin}/topology.php", {"_glpi_csrf_token": csrf(html), "scope": f"dc:{dc_id}", "save": "1", "remove[]": m.group(1) if m else "0"})
    check("typed connection removed", "0 added, 1 removed." in removed and "Port used twice" not in removed and n_remove(removed) == 2, "not removed")


def audit_checks(c, room_a, room_b, dc_id):
    """2.2: datacenter inventory page, CSV, room networks setting, "Server room" dashboard filter."""
    plugin = "/plugins/assetwatch/front"
    status, html = c.get(f"{plugin}/audit.php")
    page_ok("inventory page (no room chosen)", status, html, f'value="room:{room_a}"')
    check("inventory page offers the whole datacenter", f'value="dc:{dc_id}"' in html, "dc option missing")
    status, html = c.get(f"/front/central.php")
    check("Tools menu links the inventory page", "/plugins/assetwatch/front/audit.php" in html, "menu entry missing")

    status, html = c.get(f"{plugin}/audit.php?scope=dc:{dc_id}")
    page_ok("inventory page (datacenter)", status, html, "aw-ghost")
    for marker in ("AW-RA1", "AW-RB1", "In a rack, never reported", "Duplicate serial number", "Not in a rack",
                   "Probably removed", "Address outside the room networks", "32 GB"):
        check(f"inventory page shows '{marker}'", marker in html, "not found")
    # rack AW-RA1 lists the top of the rack first: switch U42, ghost U20, aud01 U10, PDU U1
    rack_part = html[html.find('ti-server me-1"></i>AW-RA1'):]
    order = [rack_part.find(n) for n in ("aw-sw01", "aw-ghost", "aw-aud01", "aw-pdu01")]
    check("rack listed from the top U down", all(o >= 0 for o in order) and order == sorted(order), str(order))

    status, csv = c.get(f"{plugin}/audit.php?scope=room:{room_a}&export=csv")
    check("CSV export", status == 200 and csv.startswith("﻿") and "aw-ghost" in csv and "AW-RB1" not in csv,
          f"HTTP {status}, {csv[:60]!r}")
    check("CSV has one line per asset of the room (+ header)", len(csv.strip().splitlines()) == 1 + 5,
          f"{len(csv.strip().splitlines())} lines")
    status, html = c.get(f"{plugin}/audit.php?scope=room:999999")
    page_ok("unknown room falls back to the chooser", status, html, "Pick a server room")

    # Room networks + "probably removed" threshold in the configuration page.
    status, html = c.get(f"{plugin}/config.form.php")
    page_ok("configuration page lists the rooms", status, html, f'name="room_subnets[{room_a}]"')
    form = {
        "_glpi_csrf_token": csrf(html), "update": "1",
        "check_noreport": "1", "check_disk": "1", "check_hardware": "1", "check_identity": "1", "check_rack": "1",
        "retire_days": "45",
        f"room_subnets[{room_a}]": "10.1.0.0/16\nbogus",
        f"room_subnets[{room_b}]": "172.16.0.0/12",
    }
    c.post(f"{plugin}/config.form.php", form)
    status, html = c.get(f"{plugin}/config.form.php")
    check("room networks saved, invalid entry reported",
          "10.1.0.0/16" in html and "Ignored (not a network): bogus" in html and 'value="45"' in html, "not found")
    form.update({"_glpi_csrf_token": csrf(html), "retire_days": "30", f"room_subnets[{room_a}]": "10.1.0.0/16"})
    c.post(f"{plugin}/config.form.php", form)

    # Dashboard: the filter renders, and cards follow it.
    status, html = c.get("/ajax/dashboard.php?action=get_filter&filter_id=plugin_assetwatch_dcroom", ajax=True)
    page_ok("'Server room' dashboard filter", status, html, "plugin_assetwatch_dcroom")
    status, html = card(c, "auditissues", "bigNumber")
    page_ok("dashboard card auditissues (bigNumber)", status, html, "Assets to check on site")
    status, html = card(c, "monitored", "bigNumber", {"plugin_assetwatch_dcroom": room_a})
    check("monitored card filtered by room A = 2", big_number(html) == "2", big_number(html))
    status, html = card(c, "auditissues", "bigNumber", {"plugin_assetwatch_dcroom": room_b})
    check("auditissues card filtered by room B = 1", big_number(html) == "1", big_number(html))
    status, html = card(c, "offlinelist", "searchShowList", {"plugin_assetwatch_dcroom": room_a})
    page_ok("offline list card with room filter", status, html, "Offline computers (list)")


def login(c):
    status, html = c.get("/index.php")
    name_field = re.search(r'id="login_name" name="([^"]+)"', html)
    pass_field = re.search(r'id="login_password" name="([^"]+)"', html)
    if not (name_field and pass_field):
        return False
    c.post("/front/login.php", {
        name_field.group(1): "glpi",
        pass_field.group(1): "glpi",
        "noAUTO": "1",
        "redirect": "",
        "_glpi_csrf_token": csrf(html),
        "submit": "",
    })
    status, html = c.get("/front/central.php")
    check("login as glpi", status == 200 and "logout" in html.lower(), f"HTTP {status}")
    return True


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://localhost:8080")
    parser.add_argument("--alert-id", type=int)
    parser.add_argument("--computer-id", type=int)
    parser.add_argument("--profile-id", type=int, default=4, help="Super-Admin profile id")
    parser.add_argument("--audit", nargs=3, type=int, metavar=("ROOM_A", "ROOM_B", "DC"),
                        help="only run the datacenter inventory checks (fixture of glpi_actions.php audit-fixture)")
    parser.add_argument("--topology", nargs=7, type=int, metavar=("ROOM_A", "ROOM_B", "DC", "SW1", "SW2", "GHOST", "RA1"),
                        help="only run the floor map / rack label / topology checks (glpi_actions.php topology-fixture)")
    args = parser.parse_args()

    c = Client(args.base)
    if args.topology:
        if not login(c):
            print("login form not found")
            return 1
        topology_checks(c, *args.topology)
        print(f"\n{len(FAILS)} UI check(s) failed" if FAILS else "\nUI smoke OK")
        return 1 if FAILS else 0
    if args.audit:
        if not login(c):
            print("login form not found")
            return 1
        audit_checks(c, *args.audit)
        print(f"\n{len(FAILS)} UI check(s) failed" if FAILS else "\nUI smoke OK")
        return 1 if FAILS else 0
    if args.alert_id is None or args.computer_id is None:
        parser.error("--alert-id and --computer-id are required (or --audit)")
    status, html = c.get("/index.php")
    name_field = re.search(r'id="login_name" name="([^"]+)"', html)
    pass_field = re.search(r'id="login_password" name="([^"]+)"', html)
    if not (name_field and pass_field):
        print("login form not found")
        return 1
    c.post("/front/login.php", {
        name_field.group(1): "glpi",
        pass_field.group(1): "glpi",
        "noAUTO": "1",
        "redirect": "",
        "_glpi_csrf_token": csrf(html),
        "submit": "",
    })
    status, html = c.get("/front/central.php")
    check("login as glpi", status == 200 and "logout" in html.lower(), f"HTTP {status}")

    plugin = "/plugins/assetwatch/front"
    status, html = c.get(f"{plugin}/alert.php")
    # GLPI 11 renders the list frame with the row count, then loads the rows through AJAX.
    page_ok("alert list page", status, html, 'data-search-itemtype="PluginAssetwatchAlert"')
    count = re.search(r'data-count="(\d+)"', html)
    check("alert list applies the default filter (open alerts only)",
          bool(count) and 0 < int(count.group(1)) <= 2, f"data-count={count.group(1) if count else '?'}")
    status, html = c.get("/ajax/search.php?action=display_results&itemtype=PluginAssetwatchAlert", ajax=True)
    page_ok("alert list rows (AJAX)", status, html, "aw-")

    # Dashboard cards (2.1): every card of the "Asset Watch" dashboard renders through GLPI's AJAX endpoint.
    cards = [
        ("monitored", "bigNumber", "Monitored computers"),
        ("offline", "bigNumber", "Offline computers"),
        ("disklow", "bigNumber", "low disk space"),
        ("openalerts", "bigNumber", "Open alerts"),
        ("health", "donut", "Computer health"),
        ("bytype", "bar", "Open alerts by type"),
        ("changes", "bar", "Change events"),
        # at this step every machine reports again: the list renders, empty
        ("offlinelist", "searchShowList", "Offline computers (list)"),
    ]
    for card, widget, marker in cards:
        query = urllib.parse.urlencode({
            "action": "get_card", "dashboard": "asset-watch", "card_id": f"plugin_assetwatch_{card}",
            # the web UI always sends a color; the list widget fails without one
            "args[widgettype]": widget, "args[limit]": 30, "args[color]": "#ffffff", "force": 1,
        })
        status, html = c.get(f"/ajax/dashboard.php?{query}", ajax=True)
        page_ok(f"dashboard card {card} ({widget})", status, html, marker)

    status, html = c.get(f"{plugin}/alert.form.php?id={args.alert_id}")
    page_ok("alert detail page", status, html, "ti-heartbeat")

    status, html = c.get(f"{plugin}/config.form.php")
    page_ok("configuration page", status, html, "noreport_default_hours")
    token = csrf(html)

    tab = urllib.parse.quote("PluginAssetwatchAlert$1")
    status, html = c.get(f"/ajax/common.tabs.php?_target=/front/computer.form.php&_itemtype=Computer"
                         f"&_glpi_tab={tab}&id={args.computer_id}", ajax=True)
    page_ok("computer 'Asset alerts' tab", status, html, "ti-camera")

    tab = urllib.parse.quote("PluginAssetwatchProfile$1")
    status, html = c.get(f"/ajax/common.tabs.php?_target=/front/profile.form.php&_itemtype=Profile"
                         f"&_glpi_tab={tab}&id={args.profile_id}", ajax=True)
    page_ok("profile rights tab", status, html, "plugin_assetwatch_alert")

    # Save the configuration (values that differ from defaults, then read back).
    status, html = c.post(f"{plugin}/config.form.php", {
        "_glpi_csrf_token": token, "update": "1",
        "check_noreport": "1", "check_disk": "1", "check_hardware": "1", "check_identity": "1", "check_rack": "1",
        "noreport_default_hours": "48", "disk_min_free_percent": "12.5", "disk_min_free_gb": "25",
        "disk_excluded_fs": "tmpfs, devtmpfs", "disk_excluded_mounts": "/boot*",
        "port_excluded_patterns": "lo\ndocker*", "reminder_days": "2", "retention_days": "90",
    })
    status, html = c.get(f"{plugin}/config.form.php")
    check("configuration saved", 'value="48"' in html and 'value="12.5"' in html and "/boot*" in html,
          "values not found after save")
    token = csrf(html)
    # Restore defaults for later runs.
    c.post(f"{plugin}/config.form.php", {
        "_glpi_csrf_token": token, "update": "1",
        "check_noreport": "1", "check_disk": "1", "check_hardware": "1", "check_identity": "1", "check_rack": "1",
        "noreport_default_hours": "36", "disk_min_free_percent": "10", "disk_min_free_gb": "20",
        "disk_excluded_fs": "tmpfs,devtmpfs,overlay,squashfs,iso9660,udf,ramfs,devfs,autofs,nsfs",
        "disk_excluded_mounts": "",
        "port_excluded_patterns": "lo,lo0,docker*,veth*,br-*,virbr*,vnet*,tun*,tap*,cni*,flannel*,cali*,"
                                  "vEthernet*,Loopback*,isatap*,Teredo*,*Pseudo-Interface*",
        "reminder_days": "3", "retention_days": "180",
    })

    # Acknowledge an alert with a comment containing special characters.
    status, html = c.get(f"{plugin}/alert.form.php?id={args.alert_id}")
    if 'name="acknowledge"' in html:
        c.post(f"{plugin}/alert.form.php", {
            "_glpi_csrf_token": csrf(html), "id": str(args.alert_id), "acknowledge": "1",
            "ack_comment": "Disk order <PO-42> & vendor \"ACME\"", "date_ack_until": "",
        })
        status, html = c.get(f"{plugin}/alert.form.php?id={args.alert_id}")
        check("acknowledge stores comment verbatim",
              "Disk order &lt;PO-42&gt; &amp; vendor &quot;ACME&quot;" in html, "comment not shown escaped once")
    else:
        check("acknowledge form present", False, "alert is not open")

    print(f"\n{len(FAILS)} UI check(s) failed" if FAILS else "\nUI smoke OK")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
