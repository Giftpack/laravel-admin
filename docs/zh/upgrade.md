# 既有 Laravel12 → 13 升級與回復

此未發布2.0候選已在 PostgreSQL／MySQL 的**同一個可拋棄安裝**完成 PHP8.4.28／Laravel12.25.0 → PHP8.5.13／Laravel13.43.0，再實際還原舊執行期、程式、lock、設定、資源、資料庫及上傳。這不代表任何真實使用者主機已升級，也未授權正式部署。

1. 應用程式負責人盤點 PHP／擴充、Composer manifest／lock／vendor、app/Admin 自訂路由／控制器／bootstrap、設定、已發布視圖／翻譯／資源、資料ID／關聯／權限、上傳／縮圖及 sessions／caches。發布操作人員協調備份資料庫、上傳與所有程式／執行期／設定／資源，確認 SHA-256 與還原權限。暫停寫入、背景工作，等待進行中異動／匯出完成。不可使用測試憑證或金鑰。
2. **使用舊執行期**先執行 `php artisan optimize:clear`。保留應用金鑰、cookie名稱／路徑／網域、guard／模型／連線、磁碟根目錄／URL 與 PHP session 序列化。本次演練選擇升級及回復都明確失效 sessions／caches，使用者重新登入並取得新的簽名 action／步驟 context。remember token 撤銷由主機政策管理；此無 remember token 的演練沒有宣稱自動撤銷功能。不可假設舊登入會跨版本延續。
3. 切換至實測 PHP8.5.13 與所需擴充。未來核准發布後，在 staging 一起解析 `composer require laravel/framework:^13.40 encore/laravel-admin:^2.0 --with-all-dependencies`，審查並部署確切 lock，再執行 `composer install`、`composer check-platform-reqs`、`composer audit --locked`。本機候選使用 path repository 的審查來源。不可略過平台／安全檢查。本候選不需要新的套件資料庫 migration，既有表與ID保留；主機本身另有 migration 須另外審查。
4. **選擇性更新已發布檔案**：以舊版本 SHA-256 判定未改動的套件檔案再替換，加入新路徑（包括 `AdminLTE/plugins/jQuery/jquery.min.js`）。保留自訂檔；與新版有衝突的套件檔必須三方合併並保留安全修補。本演練保留自訂 skin 與 CSS，沒有強制覆蓋。不可將 `admin:publish --force` 當成無人升級程序。自訂視圖、翻譯及設定亦先備份再合併。
5. 自訂登出 header／客戶端必須改成 **POST＋CSRF**，GET／HEAD僅安全導向而不登出，合併時保留自訂標記。若另選 JSON sessions，需合併 MessageBag／array 相容的 alert／toastr 視圖；沒有自動 PHP→JSON 轉換。通用 action／picker／widget 必須附簽名 `_context` 及明確布林授權／模型政策或類別 ability，批次逐筆授權，沒有相容繞過。重新開啟表單／工作流程；相同 multistep 定義的獨立副本使用穩定明確ID，不改動主機其他步驟。
6. 如有使用 minifier，依實際 skin／locale 執行 `php artisan admin:minify` 產生 LTR／RTL，再發布已合併的套件檔，清除 CDN／反向代理／瀏覽器舊資源，重建 config／route／view cache 並重新啟動長期工作程序。URL指紋來自**已發布內容**，不會自動修復舊檔。歷史 `jQuery-2.1.4.min.js` 保留為目前內容的相容別名。使用者不需要 Node。
7. 重新登入，檢查有／無cache的啟動、登入／個人資料／密碼／頭像預覽與移除、CSRF成功／失敗、允許／拒絕權限、CRUD、遮蔽的操作日誌查閱／刪除、上傳、完整下載匯出。對照原始ID／數量／關聯／權限與所有自訂／上傳雜湊；把預期 header 合併單獨列出，確認沒有其他變動後由應用負責人恢復寫入。

必要API遷移：自訂 Excel4 exporter 的屬性必須符合型別（覆寫時 `protected ?string $fileName`）及 `query()` 合約，詳見[匯出文件](model-grid-export.md)。CSV改用空 escape 與標準雙引號，舊反斜線解析器需更新。排序必須由伺服器 `sortable()` 宣告，不接受請求傳入cast SQL。原生schema API取代已移除的DBAL橋接。PostgreSQL schema＋表前綴的歷史 Eloquent find 組合仍不支援，使用測試證據所述明確qualified lookup。樹的parent0為固定根節點哨兵，不能當節點key；直接子節點刪除的更深後代保留合約不變。

預設管理員硬刪除 row action 於commit後清理設定模型已知的原始頭像；自訂縮圖／保留政策與任意主機上傳欄位須使用宣告的控制器／表單路徑或自訂action。retainable合約不變。操作輸入在儲存與舊日誌顯示時遮蔽敏感資料，沒有重寫歷史資料；handler不洩露原始例外內容。

## 回復觸發與負責人

應用負責人遇到啟動／登入失敗、權限漂移、資料／檔案雜湊不符、不完整匯出或效能不符時啟動回復。發布操作人員停止寫入與背景工作，保全切換後新增異動以供調整，清除不相容cache，還原**同一復原時間點**的舊程式／PHP及擴充／manifest／lock／vendor／設定／資源／資料庫／上傳，依政策失效sessions並重新登入。還原DB可能捨棄備份後寫入，應用負責人必須決定如何保全與重放，不能自動遺失。用舊runtime重建cache並重啟workers，核對原ID／權限／關聯／檔案／自訂內容及登入／個人頁／CRUD／CSRF／上傳／匯出後才能開放。不可uninstall、重產admin目錄或重新seed。


請先於應用程式自己的 staging 環境演練升級與回復，協調資料庫與上傳檔案備份並核對還原結果。操作證據應保存在原始碼目錄之外；套件測試不能取代應用程式專屬的復原計畫。本專案不保留此次升級任務的一次性演練環境或任務報告。
