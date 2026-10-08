# Asset Watch for GLPI

English | [繁體中文](README.zh-TW.md)

[![CI](https://github.com/chengyuchen-sideproject/glpi-assetwatch/actions/workflows/ci.yml/badge.svg)](https://github.com/chengyuchen-sideproject/glpi-assetwatch/actions/workflows/ci.yml)

A GLPI 10 plugin that watches the inventories sent by **GLPI Agent** and e-mails
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

The plugin only **reads** GLPI data: it never connects to your machines and never
modifies assets.

## Requirements / compatibility

| | Supported | Tested |
|---|---|---|
| GLPI | 10.0.x (`10.0.0` - `10.0.99`) | **10.0.20** (official `glpi/glpi` Docker image) |
| PHP | 7.4 - 8.3 | 7.4, 8.0, 8.1, 8.2, 8.3 (unit tests); 8.3 (end-to-end) |
| Database | MariaDB / MySQL supported by GLPI 10 | MariaDB 10.11 |
| Inventory source | GLPI native inventory (GLPI Agent) | GLPI Agent JSON format |

GLPI 11 is **not** supported by the 1.x series; a 2.x series will be made for it
(the rules live in `src/Core`, which has no GLPI dependency and carries over unchanged).

Also required in GLPI:

- **Automatic actions in CLI mode** (the official Docker image does this).
  In GLPI mode they only run while someone browses GLPI.
- **E-mail notifications enabled** with a working SMTP (Setup > Notifications).
- Computers must have a **technical group** (field "Group in charge of the hardware")
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
   `git archive --format=tar.gz --prefix=assetwatch/ -o assetwatch-1.0.0.tar.gz v1.0.0`).
   Avoid GitHub's "Download ZIP": its folder is named `glpi-assetwatch-main` and must be renamed.
2. Copy both files to the GLPI host, then:

   ```bash
   sha256sum -c assetwatch-1.0.0.tar.gz.sha256
   tar -xzf assetwatch-1.0.0.tar.gz -C ./glpi_plugins     # creates glpi_plugins/assetwatch
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
`docker save glpi/glpi:10.0.20 mariadb:10.11 -o glpi-images.tar` on the connected
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

### Automatic actions

| Name | Frequency | Job |
|---|---|---|
| `AssetwatchChanges` | 5 min | compare new inventories with the baseline (hardware / identity) |
| `AssetwatchStatus` | 1 h | missing inventories, disk space, reminders, digests |
| `AssetwatchPurge` | 1 day | remove closed alerts and digests older than 180 days |

GLPI 10.0 has no "inventory finished" hook, so hardware and identity changes are
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

**Uninstall** (Setup > Plugins) removes everything listed above; the plugin's
alert history is lost (export the list first if needed). No core GLPI table or
asset record is ever modified. **Disable** keeps all data.

## Development

```bash
bash dev/up.sh          # GLPI 10.0.20 + MariaDB 10.11 + Mailpit, plugin installed
bash dev/scenarios.sh   # end-to-end checks with fake GLPI Agent inventories
bash dev/scenarios.sh --keep   # same, but leave test alerts in place to browse in the UI
php phpunit.phar        # unit tests of src/Core (PHPUnit 9.6)
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
- **GLPI 11**: not compatible with 1.x (GLPI will disable it on upgrade, data stays
  in the database); install the 2.x series when available.
- **Without CLI cron**: works, but checks only run when someone browses GLPI.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
