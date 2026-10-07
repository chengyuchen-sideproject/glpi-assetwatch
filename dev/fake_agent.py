#!/usr/bin/env python3
"""Send a fake GLPI Agent inventory (JSON format) to a GLPI 10 test instance.

Development tool only (standard library only). Every option has a sensible
default derived from the machine name, so scenarios only override what they
change between two inventories.

Examples:
    python dev/fake_agent.py web01
    python dev/fake_agent.py web01 --memory 16384              # one 16 GB module removed
    python dev/fake_agent.py web01 --disk /:102400:4096        # / with 4 GB free
    python dev/fake_agent.py win01 --os windows --disk C::51200:2048
    python dev/fake_agent.py web01 --ip 10.0.0.99 --mac 00:11:22:33:44:99
"""

import argparse
import hashlib
import json
import sys
import urllib.error
import urllib.request

DEFAULT_URL = "http://localhost:8080/front/inventory.php"


def stable_hex(name, salt, length):
    return hashlib.sha1(f"{name}:{salt}".encode()).hexdigest()[:length]


def default_mac(name):
    h = stable_hex(name, "mac", 10)
    return "00:" + ":".join(h[i:i + 2] for i in range(0, 10, 2))


def default_ip(name):
    return f"10.20.{int(stable_hex(name, 'ip', 2), 16) % 250 + 1}.{int(stable_hex(name, 'ip2', 2), 16) % 250 + 1}"


def build(args):
    name = args.name
    windows = args.os == "windows"
    modules = []
    remaining = args.memory
    while remaining > 0:
        size = min(16384, remaining)
        modules.append({"capacity": size, "caption": f"DIMM{len(modules)}", "type": "DDR4", "speed": "3200",
                        "numslots": len(modules), "serialnumber": stable_hex(name, f"mem{len(modules)}", 8).upper()})
        remaining -= size

    cpus = [{"name": args.cpu_name, "manufacturer": "Intel", "core": 10, "thread": 20, "speed": 2200}
            for _ in range(args.cpus)]

    storages = []
    for spec in args.drives.split(",") if args.drives else []:
        serial, size = spec.split(":")
        storages.append({"name": f"disk{len(storages)}", "model": args.drive_model, "manufacturer": "Samsung",
                         "serial": serial, "disksize": int(size), "type": "disk", "interface": "SATA"})

    drives = []
    for spec in args.disk or (["C::102400:61440"] if windows else ["/:102400:61440", "/boot:1024:700"]):
        # mount:total_mb:free_mb ; Windows letters contain a colon ("C:") -> "C::total:free"
        mount, total, free = spec.rsplit(":", 2)
        if windows:
            drives.append({"letter": mount, "volumn": mount, "filesystem": "NTFS",
                           "total": int(total), "free": int(free), "systemdrive": mount == "C:"})
        else:
            drives.append({"type": mount, "volumn": f"/dev/{stable_hex(name, mount, 4)}", "filesystem": "ext4",
                           "total": int(total), "free": int(free)})
    if not windows:
        drives.append({"type": "/run", "volumn": "tmpfs", "filesystem": "tmpfs", "total": 1600, "free": 0})

    nic = "Ethernet0" if windows else "eth0"
    networks = [{"description": nic, "mac": args.mac or default_mac(name), "ipaddress": args.ip or default_ip(name),
                 "ipmask": "255.255.255.0", "type": "ethernet", "virtualdev": False, "status": "up"}]
    if not windows:
        networks.append({"description": "docker0", "mac": "02:42:" + ":".join(stable_hex(name, "docker", 8)[i:i + 2] for i in range(0, 8, 2)),
                         "ipaddress": "172.17.0.1", "ipmask": "255.255.0.0", "type": "ethernet",
                         "virtualdev": True, "status": "up"})

    os_block = ({"name": "Microsoft Windows Server 2022 Standard", "full_name": "Microsoft Windows Server 2022 Standard",
                 "version": "21H2", "arch": "64-bit", "kernel_name": "MSWin32", "kernel_version": "10.0.20348"}
                if windows else
                {"name": "Rocky Linux", "full_name": "Rocky Linux release 9.4 (Blue Onyx)", "version": "9.4",
                 "arch": "x86_64", "kernel_name": "linux", "kernel_version": "5.14.0-427.el9.x86_64"})

    return {
        "action": "inventory",
        "deviceid": f"{name}-2026-01-01-00-00-00",
        "itemtype": "Computer",
        "content": {
            "versionclient": "GLPI-Agent_v1.11",
            "hardware": {"name": args.hostname or name, "memory": args.memory, "uuid": stable_hex(name, "uuid", 32),
                         "workgroup": "assetwatch.test"},
            "bios": {"ssn": args.serial or f"SN-{name.upper()}", "smanufacturer": "Dell Inc.",
                     "smodel": "PowerEdge R650", "bversion": "1.10.2"},
            "memories": modules,
            "cpus": cpus,
            "storages": storages,
            "drives": drives,
            "networks": networks,
            "operatingsystem": os_block,
        },
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("name", help="machine name (also seeds default serial / MAC / IP)")
    parser.add_argument("--url", default=DEFAULT_URL)
    parser.add_argument("--os", choices=["linux", "windows"], default="linux")
    parser.add_argument("--hostname", help="reported hostname (default: name)")
    parser.add_argument("--serial", help="BIOS serial number (default: SN-<NAME>)")
    parser.add_argument("--memory", type=int, default=32768, help="total memory in MB, split in 16 GB modules")
    parser.add_argument("--cpus", type=int, default=2)
    parser.add_argument("--cpu-name", default="Intel(R) Xeon(R) Silver 4210 CPU @ 2.20GHz")
    parser.add_argument("--drives", default=None, help="physical drives 'SERIAL:MB,...' (default: two 1 TB SSD)")
    parser.add_argument("--drive-model", default="SSD 870 EVO 1TB")
    parser.add_argument("--disk", action="append", help="partition 'mount:total_mb:free_mb' (repeatable)")
    parser.add_argument("--mac")
    parser.add_argument("--ip")
    parser.add_argument("--dump", action="store_true", help="print the JSON instead of sending it")
    args = parser.parse_args()
    if args.drives is None:
        args.drives = f"{stable_hex(args.name, 'd1', 8).upper()}:953869,{stable_hex(args.name, 'd2', 8).upper()}:953869"

    payload = build(args)
    body = json.dumps(payload).encode()
    if args.dump:
        print(json.dumps(payload, indent=2))
        return 0

    request = urllib.request.Request(args.url, data=body, headers={
        "Content-Type": "application/json",
        "User-Agent": "GLPI-Agent_v1.11",
        "GLPI-Agent-ID": stable_hex(args.name, "agentid", 8) + "-" + stable_hex(args.name, "a2", 4) + "-4"
                         + stable_hex(args.name, "a3", 3) + "-8" + stable_hex(args.name, "a4", 3) + "-"
                         + stable_hex(args.name, "a5", 12),
    })
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            text = response.read().decode(errors="replace")
    except urllib.error.HTTPError as err:
        print(f"{args.name}: HTTP {err.code} {err.read().decode(errors='replace')[:300]}", file=sys.stderr)
        return 1
    except urllib.error.URLError as err:
        print(f"{args.name}: {err.reason}", file=sys.stderr)
        return 1
    print(f"{args.name}: {text.strip()[:200]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
