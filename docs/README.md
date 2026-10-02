# 文档

仓库级的文档放在这里；**每个插件自己的手册在插件目录里**（`plugins/addons/<插件名>/README.md`）
—— 那一份是跟着插件一起发出去的，装机的人最先看到的就是它。

| 文档 | 给谁看 | 里面有什么 |
|---|---|---|
| [install.md](install.md) | 要装插件的人 | 三种装法、升级、卸载，以及卡住了怎么查（列表里没有它 / 报 SQL 错 / 老站没有计划任务表） |
| [getting-started.md](getting-started.md) | 第一次用公共黑名单的人 | 注册平台 → 拿密钥 → 填进插件 → 多久生效 → 拦错了怎么办 → 我们上报了什么 |
| [../CHANGELOG.md](../CHANGELOG.md) | 想知道改了什么的人 | 每个插件的版本记录 |
| [../plugins/addons/blacklist/README.md](../plugins/addons/blacklist/README.md) | 装了公共黑名单的人 | 装哪儿、每一项配置什么意思、拦截原理、常见问题 |

官网的文档中心 <https://aiapizz.com/docs.php> 和这里内容同源，网页版更好搜。

**加新插件的时候**：在 `plugins/addons/<插件名>/README.md` 写这个插件的手册（装哪儿、怎么配、
原理、常见问题），然后回根目录的 README 插件表里加一行、需要的话在这里加一行。
