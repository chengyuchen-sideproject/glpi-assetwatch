# 資產監看 Asset Watch（GLPI 外掛）

[English](README.md) | 繁體中文

[![CI](https://github.com/chengyuchen-sideproject/glpi-assetwatch/actions/workflows/ci.yml/badge.svg)](https://github.com/chengyuchen-sideproject/glpi-assetwatch/actions/workflows/ci.yml)

GLPI 10 外掛。它會看 **GLPI Agent** 回報的資料，伺服器出狀況時寄信給該機器的技術群組：

| 檢查項目 | 規則（預設值） | 寄信方式 |
|---|---|---|
| **沒回報** | 超過 36 小時沒收到回報（可依電腦類型調整） | 每小時彙整 |
| **硬碟空間不足** | 分割區剩餘空間 < 10% **而且** < 20 GB | 每小時彙整 |
| **硬體變動** | 記憶體總量、實體硬碟（依序號）、CPU 有增減 | 一個事件一封 |
| **身分變動** | 序號、主機名稱、實體網卡的 MAC 或 IP | 一個事件一封 |
| **機櫃位置變更** | 有人在 GLPI 把設備放進、移動或移出機櫃 | 一個事件一封，信中寫明是誰改的 |

「沒回報」和「硬碟」這類狀態型告警，**發生時寄一次**，持續中**每 3 天提醒一次**，**恢復時再寄一次**。
同一輪檢查的所有告警會依技術群組**合併成一封信**。所以交換器故障導致十台伺服器同時斷線時，只會收到一封信，而不是十封。

這個外掛只**讀取** GLPI 的資料，不會連到你的機器，也不會修改任何資產。

## 需求與相容版本

| | 支援範圍 | 實測版本 |
|---|---|---|
| GLPI | 10.0.x（`10.0.0` ~ `10.0.99`） | **10.0.20**（官方 `glpi/glpi` Docker 映像檔） |
| PHP | 7.4 ~ 8.3 | 7.4、8.0、8.1、8.2、8.3（單元測試）；8.3（端到端測試） |
| 資料庫 | GLPI 10 支援的 MariaDB / MySQL | MariaDB 10.11 |
| 資產資料來源 | GLPI 原生 inventory（GLPI Agent） | GLPI Agent JSON 格式 |

1.x 版**不支援** GLPI 11，之後會另出 2.x 版。判斷規則放在 `src/Core`，不依賴 GLPI，移植時可以原封不動沿用。

GLPI 端還需要：

- **自動動作設成 CLI 模式**（官方 Docker 映像檔預設就是）。如果是 GLPI 模式，只有在有人瀏覽 GLPI 時才會執行檢查。
- **已啟用 E-mail 通知**，而且 SMTP 能正常寄信（設定 > 通知）。
- 電腦要設定**技術群組**（「負責硬體的群組」欄位），群組成員要有 E-mail。設定頁會顯示有幾台受監看的電腦還沒設定。

## 安裝

```bash
# 在 GLPI 主機上，進入掛載到 /var/www/glpi/plugins 的目錄
cd glpi_plugins
git clone https://github.com/chengyuchen-sideproject/glpi-assetwatch.git assetwatch
```

資料夾**一定要叫 `assetwatch`**。接著到 GLPI 的 **設定 > 外掛程式 > Asset Watch**，按「安裝」再按「啟用」。也可以用命令列：

```bash
docker exec -u www-data glpi_app php bin/console plugin:install --username=glpi assetwatch
docker exec -u www-data glpi_app php bin/console plugin:activate assetwatch
```

命令列請用 `www-data` 身分執行。用 root 跑的話，會在 `/var/glpi/files/_cache` 留下 root 擁有的快取檔，網頁端之後就無法更新這些檔案。

### 離線安裝（無法連網的 Docker 主機）

這個外掛是純 PHP，不需要 Composer、沒有 `vendor/`，也不從 CDN 載入任何東西；翻譯檔（`.mo`）已經編譯好放在裡面。安裝和執行都不需要連網，只要把檔案帶進去就好。

1. 在能上網的電腦，從 [Releases](https://github.com/chengyuchen-sideproject/glpi-assetwatch/releases) 頁面下載 `assetwatch-<版本>.tar.gz` 和對應的 `.sha256` 檔。也可以從原始碼自己打包：`git archive --format=tar.gz --prefix=assetwatch/ -o assetwatch-1.0.0.tar.gz v1.0.0`。不建議用 GitHub 的「Download ZIP」，解開後資料夾會叫 `glpi-assetwatch-main`，必須自己改名。
2. 把兩個檔案帶到 GLPI 主機上，執行：

   ```bash
   sha256sum -c assetwatch-1.0.0.tar.gz.sha256
   tar -xzf assetwatch-1.0.0.tar.gz -C ./glpi_plugins     # 會建立 glpi_plugins/assetwatch
   chmod -R a+rX ./glpi_plugins/assetwatch                # 讓容器內的 www-data（uid 33）讀得到
   ```

   `./glpi_plugins` 是掛載到 `/var/www/glpi/plugins` 的主機目錄。如果沒有掛載，也可以用 `docker cp assetwatch glpi_app:/var/www/glpi/plugins/` 直接複製進容器，但容器一重建外掛就會消失。
3. 用上面的兩行命令列指令安裝並啟用，也可以在網頁介面操作。
4. 到 **設定 > 通知**，把 SMTP 指向內網的郵件伺服器。

**離線升級**：把新版壓縮檔解開覆蓋 `glpi_plugins/assetwatch`，再到 **設定 > 外掛程式** 按「更新」。資料庫裡的告警歷史會保留。

**GLPI 本身也還沒裝？** 映像檔也要一起帶進去：在能上網的電腦執行 `docker save glpi/glpi:10.0.20 mariadb:10.11 -o glpi-images.tar`，到離線主機執行 `docker load -i glpi-images.tar`。

### 安裝後

1. 到 **設定 > 外掛程式 > Asset Watch** 的設定頁，看「健康檢查」區塊有沒有警告，需要的話調整門檻。
2. 到 **管理 > 設定檔 >（設定檔）> 資產監看** 分頁，把「讀取」和「確認告警」權限開給要看告警的技術人員。Super-Admin 預設擁有全部權限。
3. 確認伺服器都有設定技術群組。

第一次執行時，只會把每台伺服器當下的硬體記成**比對基準**，不發告警；從下一次回報起才開始偵測變動。

## 使用方式

- **工具 > 資產監看**：告警列表，預設只顯示觸發中和已確認的告警。可以像 GLPI 其他列表一樣搜尋和匯出。
- **告警頁**：顯示詳細內容。可以按**確認告警**，並選填備註和「暫停提醒到哪天」。確認後就不再寄提醒信，但問題消失時仍會自動結案並寄出「已恢復」通知。
- **資產上的「資產告警」分頁**：顯示這台的告警歷史和目前的比對基準。電腦有這個分頁；網路設備、機箱、PDU 等可以放進機櫃的設備也有，但只會出現機櫃變更的告警。
- **信件內容與收件人**：到 **設定 > 通知** 調整名稱為 `Asset Watch - ...` 的通知。範本有英文和繁體中文兩版，收件人會依自己在 GLPI 設定的語言收到對應的版本。

### 自動動作

| 名稱 | 頻率 | 工作 |
|---|---|---|
| `AssetwatchChanges` | 5 分鐘 | 把新的回報和比對基準比較（硬體變動、身分變動） |
| `AssetwatchStatus` | 1 小時 | 檢查沒回報和硬碟空間，寄提醒和彙整信 |
| `AssetwatchPurge` | 1 天 | 清除超過 180 天的已結案告警和彙整紀錄 |

GLPI 10.0 沒有「inventory 匯入完成」的 hook，所以硬體和身分變動由 `AssetwatchChanges` 偵測，回報後 5 分鐘內會發出。GLPI 匯入 inventory 時包在資料庫 transaction 裡，不會讀到匯入到一半的資料。

## 外掛會改動 GLPI 的哪些東西（以及如何還原）

| 異動 | 安裝時 | 移除外掛時 |
|---|---|---|
| 資料表 `glpi_plugin_assetwatch_alerts`、`_snapshots`、`_digests` | 建立 | 刪除 |
| 設定值（context 為 `plugin:assetwatch`） | 寫入預設值，不覆蓋已存在的值 | 刪除 |
| 權限 `plugin_assetwatch_alert`、`plugin_assetwatch_config` | 開給能修改 GLPI 設定的設定檔 | 刪除 |
| 3 個自動動作 | 建立 | 刪除 |
| 4 個通知 + 2 個信件範本（`Asset Watch - ...`） | 不存在時才建立（升級時保留你改過的內容） | 刪除，佇列中尚未寄出的信也一併刪除 |
| 列表預設欄位 | 尚未設定時才建立 | 刪除 |

**移除（Uninstall）** 會清掉上表所有東西，外掛的告警歷史也會一併刪除。如果需要保留，請先從列表匯出。外掛不會修改任何 GLPI 核心資料表或資產紀錄。**停用（Disable）** 則會保留所有資料。

## 開發

```bash
bash dev/up.sh          # 啟動 GLPI 10.0.20 + MariaDB 10.11 + Mailpit，並安裝外掛
bash dev/scenarios.sh   # 用模擬的 GLPI Agent 回報跑端到端情境測試
bash dev/scenarios.sh --keep   # 同上，但保留測試告警，方便到介面上查看
php phpunit.phar        # src/Core 的單元測試（PHPUnit 9.6）
python tools/i18n.py extract|compile|check   # 翻譯檔
```

- GLPI：http://localhost:8080（帳密 glpi / glpi）；Mailpit（攔截測試信）：http://localhost:8025
- 送一筆模擬回報：`dev/fake_agent.py web01 --memory 16384 --disk /:102400:4096`
- 目錄結構：`src/Core` 是純判斷規則（有單元測試，不依賴 GLPI）；`inc/` 是和 GLPI 銜接的部分（hook、排程、通知、介面）；`templates/` 是 Twig 範本。
- 執行時沒有任何第三方相依；開發工具只需要 Python 3 標準函式庫。

## 搬遷注意事項

- **搬到另一套 GLPI**：把 `assetwatch` 資料夾複製到目標的 plugins 目錄再安裝即可。告警歷史存在資料庫裡，要跟著資料庫一起搬，複製外掛資料夾不會帶過去。
- **資料夾名稱**必須維持 `assetwatch`，GLPI 會用它推算資料表和類別名稱。
- **換行字元**：repo 用 `.gitattributes` 強制 LF。如果從 Windows 用其他方式複製檔案，請保持 LF。PHP 本身不在意換行，但 Git diff 會出現整檔差異。
- **Docker volume**：正式環境的 compose 中，外掛目錄是掛到 `/var/www/glpi/plugins` 的主機路徑。注意：官方映像檔把 GLPI 的設定、檔案和 log 放在 `/var/glpi`（`GLPI_CONFIG_DIR`、`GLPI_VAR_DIR`、`GLPI_LOG_DIR`），請確認這個路徑有掛到持久化的 volume。
- **時區**：門檻是用 GLPI 的時間計算。容器的 `TIMEZONE` 要和資料庫時區一致，否則「沒回報幾小時」會算偏。
- **GLPI 11**：1.x 版不相容。升級後 GLPI 會自動停用本外掛，但資料會留在資料庫裡；屆時改裝 2.x 版即可。
- **沒有 CLI cron**：還是能運作，但只有在有人瀏覽 GLPI 時才會執行檢查。

## 授權

GPL-3.0-or-later，見 [LICENSE](LICENSE)。
