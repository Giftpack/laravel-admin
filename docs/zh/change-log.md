# Change log

## 2.0候選版（未發布）

實測PHP8.5.13、Laravel13.40.0／13.43.0、Symfony7.4、PostgreSQL15.14／MySQL8.4.13。保留UI／builder／55欄位別名／18指令，使用原生schema取代DBAL。必要遷移包含POST＋CSRF登出、簽名context／明確授權、日誌遮蔽、選擇性更新安全資源與快取清除、CSV標準escape與Excel4型別。開發Mockery最低1.6.15。既有安裝同機升級及備份還原均已演練；候選版尚未發布。外部擴充、實際雲端、MongoDB仍未認證。詳見[升級與回復](upgrade.md)。


## v1.2.9、v1.3.3、v1.4.1

- 添加用户设置和修改头像功能
- model-form自定义工具[参考](zh/model-form.md?id=自定义工具)
- 内嵌表单支持[参考](zh/model-form-fields.md?id=embeds)
- 支持自定义导航条（右上角）[参考](https://github.com/z-song/laravel-admin/issues/392)
- 添加脚手架、数据库命令行工具、web artisan帮助工具[参考](zh/helpers.md)
- 支持自定义登陆页面和登陆逻辑[参考](zh/qa.md?id=自定义登陆页面和登陆逻辑)
- 表单支持设置宽度、设置action[参考](zh/model-form.md?id=其它方法)
- 优化表格过滤器
- 修复bug，优化代码和逻辑