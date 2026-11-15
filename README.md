# Asset Watch for GLPI

English | [繁體中文](README.zh-TW.md)

[![CI](https://github.com/chengyuchen-sideproject/glpi-assetwatch/actions/workflows/ci.yml/badge.svg)](https://github.com/chengyuchen-sideproject/glpi-assetwatch/actions/workflows/ci.yml)

A GLPI 11 plugin (use the 1.x series for GLPI 10) that watches the inventories sent by **GLPI Agent** and e-mails
the technical group of a server when something is wrong:

| Check | Rule (defaults) | How it is sent |
|---|---|---|
| **No inventory** | nothing received for 36 h (per computer type, adjustable) | hourly digest |
| **Low disk space** | a partition has less than 10 % **and** less than 20 GB free | hourly digest |
| **Hardware change** | total memory, physical drives (by serial), processors: added or removed | one e-mail per event |
| **Identity change** | serial number, host name, MAC or IP of a physical NIC | one e-mail per event |
| **Rack placement change** | someone adds, moves or removes an item in a rack in GLPI | one e-mail per event, with who did it |

Condition alerts (no inventory, disk) are sent **once when they start**, then
**every 3 days** while still active, and once more **when they are resolved**.
All alerts of one hourly run are grouped into **one e-mail per technical group**,
so a network outage of ten servers produces one e-mail, not ten.
With GLPI 11 an asset can have **several technical groups**: each group gets its own
digest (listing only its machines), and one-shot change e-mails go to all of them.

The plugin only **reads** GLPI data: it never connects to your machines and never
modifies assets.

Since 2.2 it also gives a **datacenter inventory** page (each server room rack by rack, with
live status and the discrepancies to check on site) and a **Server room** dashboard filter.

## Requirements / compatibility

| | Supported | Tested |
|---|---|---|
| GLPI | 11.0.x (`11.0.0` - `11.0.99`) | **11.0.13** (official `glpi/glpi` Docker image) |
| PHP | 8.2+ (GLPI 11 requirement) | 8.2, 8.3, 8.4 (unit tests); 8.5.12 (end-to-end, bundled in the image) |
| Database | MariaDB 10.6+ / MySQL 8.0+ (GLPI 11) | MariaDB 10.11 |
| Inventory source | GLPI native inventory (GLPI Agent) | GLPI Agent JSON format |

| Series | GLPI | Branch |
|---|---|---|
| **2.x** | 11.0.x | `main` |
| 1.x | 10.0.x | `1.x` (bug fixes only) |

The rules live in `src/Core`, which has no GLPI dependency and is shared by both series.

Also required in GLPI:

- **Automatic actions in CLI mode** (the official Docker image does this).
  In GLPI mode they only run while someone browses GLPI.
- **E-mail notifications enabled** with a working SMTP (Setup > Notifications).
- Computers must have a **technical group** (field "Group in charge of the hardware", several allowed in GLPI 11)
  whose members have an e-mail address. The configuration page lists how many
  monitored computers have none.

## Installation

```bash
# On the GLPI host, in the directory mounted as /var/www/glpi/plugins
cd glpi_plugins
git clone https://github.com/chengyuchen-sideproject/glpi-assetwatch.git assetwatch
```

The directory **must be named `assetwatch`**. Then in GLPI:
**Setup > Plugins > Asset Watch > Install, then Enable**. Or from the console:

```bash
docker exec -u www-data glpi_app php bin/console plugin:install --username=glpi assetwatch
docker exec -u www-data glpi_app php bin/console plugin:activate assetwatch
```

Run the console as `www-data`: as root it leaves root-owned cache files under
`/var/glpi/files/_cache` that the web server can no longer update.

### Offline installation (air-gapped Docker host)

The plugin is plain PHP with no Composer dependencies, no `vendor/` and no CDN
assets; compiled translations (`.mo`) are included. Nothing needs network access
to install or run it, so it only has to be carried in.

1. On a machine with Internet access, download `assetwatch-<version>.tar.gz` and
   its `.sha256` file from the
   [Releases](https://github.com/chengyuchen-sideproject/glpi-assetwatch/releases) page
   (or build it from a checkout:
   `git archive --format=tar.gz --prefix=assetwatch/ -o assetwatch-2.2.0.tar.gz v2.2.0`).
   Avoid GitHub's "Download ZIP": its folder is named `glpi-assetwatch-main` and must be renamed.
2. Copy both files to the GLPI host, then:

   ```bash
   sha256sum -c assetwatch-2.2.0.tar.gz.sha256
   tar -xzf assetwatch-2.2.0.tar.gz -C ./glpi_plugins     # creates glpi_plugins/assetwatch
   chmod -R a+rX ./glpi_plugins/assetwatch                # readable by www-data (uid 33)
   ```

   `./glpi_plugins` is the host directory mounted as `/var/www/glpi/plugins`. Without
   such a mount, `docker cp assetwatch glpi_app:/var/www/glpi/plugins/` works too, but
   the plugin disappears when the container is recreated.
3. Install and enable with the two console commands above (or in the web UI).
4. Point **Setup > Notifications** at the internal SMTP relay.

**Upgrading offline:** extract the new archive over `glpi_plugins/assetwatch`, then
use the *Update* button in **Setup > Plugins**. Alert history in the database is kept.

**GLPI itself not there yet?** Carry the images too:
`docker save glpi/glpi:11.0.13 mariadb:10.11 -o glpi-images.tar` on the connected
machine, `docker load -i glpi-images.tar` on the offline host.

### After installation

1. **Setup > Plugins > Asset Watch** (configuration page): check the *Health check*
   box, adjust thresholds if needed.
2. **Administration > Profiles > (profile) > Asset Watch**: give *Read* /
   *Acknowledge* to the technicians who should see alerts (Super-Admin has everything).
3. Make sure servers have a technical group.

The first run only records each server's current hardware as the **baseline**;
change alerts start with the next inventory.

## Using it

- **Tools > Asset Watch**: list of alerts (active and acknowledged by default),
  searchable and exportable like any GLPI list.
- **Alert page**: details, and **Acknowledge** with an optional comment and an
  optional "silence until" date. Acknowledged alerts stop sending reminders but
  are still closed (with a "resolved" e-mail) when the problem disappears.
- **Asset alerts tab** on computers (and network equipment, enclosures, PDUs...
  for rack changes): alert history and the current baseline.
- **E-mail contents and recipients**: Setup > Notifications, notifications named
  `Asset Watch - ...`. Templates exist in English and Traditional Chinese; each
  recipient gets their GLPI language.

### Dashboard (since 2.1)

The install creates an **"Asset Watch" dashboard** (**Assets > Dashboard**, selector at the
top left), and the cards below can be added to **any** dashboard: "Edit" at the top right,
"+" on the grid, group "Asset Watch". Cards can be moved, resized, recolored, switched to
another chart type or removed.

| Card | Widgets | Shows | Click opens |
|---|---|---|---|
| Monitored computers | big number | computers reported by GLPI Agent (not deleted) | computer list |
| Offline computers (no inventory) | big number | computers over the "no inventory" threshold now (per type, **computed live**, no need to wait for the hourly check) | no-inventory alerts |
| Computers with low disk space | big number | computers with an open disk alert | disk alerts |
| Open alerts | big number | active + acknowledged | alert list |
| Computer health | summary / pie / donut / bars... | healthy / offline / low disk | matching list |
| Open alerts by type | same | no inventory, disk | matching list |
| Offline computers (list) | search list | which machines, since when, group in charge | - |
| Change events (last 30 days) | bars / line | hardware, identity and rack changes per day; the card "limit" = number of recent days shown | - |
| Assets to check on site (inventory) (2.2) | big number | assets with a discrepancy in the filtered room (or all rooms) | inventory page |

- Numbers follow the active **entities**; users without the "Asset alerts: Read" right do not
  get the cards (the "Asset Watch" dashboard is shared with the profiles having that right).
- The "Asset Watch" dashboard is created only once: your layout changes are never overwritten.
- **Filters** (since 2.2): the cards follow the dashboard filters that apply to computers —
  GLPI's *Location*, *Technician group*, *Status*, *Manufacturer*... and the plugin's
  **Server room** filter (assets in the racks of the room, plus the computers whose location
  is the room's). Add it with the filter icon of the dashboard toolbar; it also works on GLPI's
  own cards of computers, network equipment, PDUs... With a filter set, alert cards only count
  computer alerts.
- **Assets to check on site** (2.2): number of assets with a discrepancy (see *Datacenter
  inventory* below), in the room of the *Server room* filter or in all rooms; click opens the
  inventory page. Existing "Asset Watch" dashboards do not get it automatically: add it with
  "Edit" > "+".

### Datacenter inventory (since 2.2)

**Tools > Datacenter inventory** shows what a server room really holds, for audits and site
visits. Pick a room, a whole datacenter, all rooms, or "computers in no rack and without
location":

- **Rack by rack, top U first**: every asset placed in the racks of the room (computers,
  network equipment, PDUs, enclosures, passive equipment...), with type, serial, model,
  IP addresses, last inventory, technical groups and a hardware summary (memory, CPUs,
  drives, from the plugin baseline).
- A **status** per asset: *Healthy*, *Low disk space*, *Offline* (no-inventory threshold),
  *Probably removed* (no inventory for **30 days**, adjustable), *No agent* (never inventoried;
  normal for PDUs or passive equipment).
- A **"To check on site"** list with the discrepancies:

| Discrepancy | Meaning |
|---|---|
| In a rack, never reported | computer placed in a rack, GLPI Agent never sent its inventory |
| Probably removed | last inventory older than the threshold |
| Not in a rack | reporting computer whose location is the room's (or below), placed in no rack |
| Duplicate serial number | another asset of the same type has the same serial |
| No technical group | nobody receives the alerts of this computer |
| Address outside the room networks | none of its IPs is in the networks set for the room (computers, network equipment) |

- **Export CSV** (UTF-8 with BOM, opens directly in Excel) and **Print** (print-friendly layout).
- A room holds the assets of **its racks** plus the reporting computers in no rack whose
  **location** is the room location or below it (the deepest matching room wins). Set a
  location on each server room (Assets > Datacenters > server room) to use the latter.
- Room networks and the "probably removed" threshold: **Setup > Plugins > Asset Watch**,
  section *Datacenter inventory* (one network per line, e.g. `10.1.0.0/16`; IPv6 works too;
  invalid entries are shown and ignored; empty = not checked).
- Same right as the alert list ("Asset alerts: Read"); only rooms, racks and assets of the
  active entities are shown. Read only, no e-mail.

### Automatic actions

| Name | Frequency | Job |
|---|---|---|
| `AssetwatchChanges` | 5 min | compare new inventories with the baseline (hardware / identity) |
| `AssetwatchStatus` | 1 h | missing inventories, disk space, reminders, digests |
| `AssetwatchPurge` | 1 day | remove closed alerts and digests older than 180 days |

GLPI has no "inventory finished" hook, so hardware and identity changes are
detected by `AssetwatchChanges` within 5 minutes of the inventory. Inventories are
imported in a database transaction, so a half-imported inventory is never compared.

## What the plugin changes in GLPI (and how to undo it)

| Change | Created on install | Removed by Uninstall |
|---|---|---|
| Tables `glpi_plugin_assetwatch_alerts`, `_snapshots`, `_digests` | yes | dropped |
| Config entries (context `plugin:assetwatch`) | defaults, never overwrites existing values | deleted |
| Profile rights `plugin_assetwatch_alert`, `plugin_assetwatch_config` | granted to profiles that can update GLPI config | deleted |
| 3 automatic actions | yes | deleted |
| 4 notifications + 2 templates (`Asset Watch - ...`) | only if missing (your edits are kept on upgrade) | deleted, with queued e-mails |
| Default list columns | only if none defined | deleted |
| "Asset Watch" dashboard (2.1) | only if missing | deleted, Asset Watch cards placed on other dashboards too |

**Uninstall** (Setup > Plugins) removes everything listed above; the plugin's
alert history is lost (export the list first if needed). No core GLPI table or
asset record is ever modified. **Disable** keeps all data.

## Development

```bash
bash dev/up.sh          # GLPI 11.0.13 + MariaDB 10.11 + Mailpit, plugin installed (compose project assetwatch-dev11)
bash dev/scenarios.sh   # end-to-end checks with fake GLPI Agent inventories
bash dev/scenarios.sh --keep   # same, but leave test alerts in place to browse in the UI
php phpunit.phar        # unit tests of src/Core (PHPUnit 9.6)
docker compose -f dev/docker-compose.yml exec -T glpi php plugins/assetwatch/dev/check_signatures.php   # GLPI method overrides still compatible
python tools/i18n.py extract|compile|check   # translations
```

- GLPI: http://localhost:8080 (glpi / glpi), Mailpit (caught e-mails): http://localhost:8025
- `dev/fake_agent.py web01 --memory 16384 --disk /:102400:4096` sends an inventory.
- Layout: `src/Core` = pure rules (unit tested, no GLPI dependency);
  `inc/` = GLPI glue (hooks, cron, notifications, UI); `templates/` = Twig.
- No third-party runtime dependency; the dev tools only need Python 3 standard library.

## Portability / migration notes

- **Moving the plugin to another GLPI**: copy the `assetwatch` directory into the
  target's plugins directory and install. Alert history is in the database: it
  follows a database migration, not a plugin copy.
- **Directory name** must stay `assetwatch` (GLPI derives table and class names from it).
- **Line endings**: the repository enforces LF (`.gitattributes`). If you copy files
  from Windows by other means, keep LF; PHP itself does not mind, Git diffs will.
- **Docker volumes**: in the production compose file, the plugin directory is the
  host path mounted on `/var/www/glpi/plugins`. Note that the official image keeps
  GLPI's config, files and logs under `/var/glpi` (`GLPI_CONFIG_DIR`, `GLPI_VAR_DIR`,
  `GLPI_LOG_DIR`); check that this path is on a persistent volume.
- **Time zone**: thresholds use GLPI's time. Keep the container `TIMEZONE` and the
  database time zone consistent, otherwise "hours without inventory" shifts.
- **Upgrading from 1.x (GLPI 10) to 2.x (GLPI 11)**: upgrade GLPI first, replace
  `glpi_plugins/assetwatch` with 2.x, then click "Update" in **Setup > Plugins** (or
  `plugin:install --force` + `plugin:activate`). The update:
  - decodes the HTML-encoded text written by 1.x (`&#38;`..., the GLPI 10 convention),
    baselines included, so names, models or mount points containing `&` do **not**
    trigger false change alerts after the upgrade (tested); it can be re-run safely;
  - adds the "Item" column to the alert list (GLPI 11 no longer shows it implicitly);
  - keeps open alerts, acknowledgements and history.
- **Known GLPI limitation (not plugin related)**: computer names / serials stored by
  GLPI 10 with `&`, `<` or `>` stay encoded after the GLPI 11 upgrade; the next GLPI
  Agent inventory does not match them and creates a **duplicate computer** (tested;
  `diagnostic:check_html_encoding` does not handle it). Check before upgrading:
  `SELECT id, name, serial FROM glpi_computers WHERE name LIKE '%&#%' OR serial LIKE '%&#%' OR uuid LIKE '%&#%';`
- **Without CLI cron**: works, but checks only run when someone browses GLPI.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
