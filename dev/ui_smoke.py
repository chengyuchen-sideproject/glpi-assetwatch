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


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://localhost:8080")
    parser.add_argument("--alert-id", type=int, required=True)
    parser.add_argument("--computer-id", type=int, required=True)
    parser.add_argument("--profile-id", type=int, default=4, help="Super-Admin profile id")
    args = parser.parse_args()

    c = Client(args.base)
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
    page_ok("alert list page", status, html, "aw-")

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
