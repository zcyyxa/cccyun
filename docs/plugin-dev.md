# 彩虹易支付 PRO 插件开发指南

这套系统（彩虹易支付 PRO）用 **PHP 8.2 + ThinkPHP 8** 写的，本身带一套插件机制：把一段代码放进站点根目录的
`plugins/addons/` 下面，它就能往系统里加路由、加后台菜单和页面、加定时任务，也能在系统跑某些流程的时候被叫一下。

这份文档把这一整套东西写全：目录怎么摆、`info.json` 每个字段什么意思、系统在哪些地方留了口子、每个口子的
参数和返回值是什么、表怎么建、系统里已经有哪些现成函数可以用、想拦付款或者想接投诉该往哪儿下手。照着写完就能跑，
不用再去翻系统源码。

系统源码是开放的，就在站点根目录的 `app/` 里，改不改、怎么改是站主自己的事。**插件这条路的好处在于**：它能被后台
统一安装、启用、卸载、升级，能把自建的数据表和后台页面一起打包发给别人，也不会在系统更新时被覆盖掉。
本仓库的 [`plugins/addons/blacklist`](../plugins/addons/blacklist) 就是按这份文档写出来的完整例子。

---

## 目录

- [一、系统概览](#一系统概览)
- [二、插件是怎么被加载的](#二插件是怎么被加载的)
- [三、info.json](#三infojson)
- [四、插件主类](#四插件主类)
- [五、扩展点](#五扩展点)
- [六、配置与缓存](#六配置与缓存)
- [七、数据库](#七数据库)
- [八、系统现成函数速查](#八系统现成函数速查)
- [九、业务接入点](#九业务接入点)
- [十、从零写一个插件](#十从零写一个插件)
- [十一、排查表](#十一排查表)

---

## 一、系统概览

### 1.1 运行环境

| 项 | 要求 |
|---|---|
| PHP | **8.2 以上**（代码里用了 `declare(strict_types=1)`、构造器属性提升、`match`、枚举等 8.x 语法） |
| 框架 | ThinkPHP 8（`topthink/framework ^8.0`）+ think-orm（`^3\|^4`） |
| 数据库 | MySQL 5.7 / 8.0 |
| 扩展 | curl、gd、mbstring、openssl、pdo、sockets、**gmp**（有 RSA 签名的通道会用到） |
| 可选 | **swoole**（只有计划任务用「Swoole 守护进程」方式时才需要）；`swoole_loader`（读加密文件用，见 1.4） |

厂商 SDK 已经在 `vendor/` 里：`cccyun/alipay-sdk`、`cccyun/wechatpay-sdk`、`cccyun/qqpay-sdk`、
`lpilp/guomi`（国密）、`phpmailer`。写插件直接用就行，不用自己装。

### 1.2 目录骨架

```
站点根目录/
├── app/                        系统主代码
│   ├── common.php              全局函数（config_get、send_mail、json_response…… 见第八章）
│   ├── common/                 系统各类基类与接口（BaseAddon、BasePayment、BaseComplain……）
│   ├── controller/             admin / user / api / payment / qrpay / applet / transfer 各端控制器
│   ├── cron/                   系统自带的定时任务类
│   ├── lib/                    系统服务类（AddonManager、MsgNotice、Order、Payment、Channel……）
│   ├── logic/ service/         业务逻辑层、服务层
│   ├── middleware/             系统中间件（SessionInit、AuthAdmin、ApiVerify……）
│   ├── command/               think 命令（Cron）
│   └── sql/                    系统建表脚本 install.sql / update*.sql
├── config/                     框架配置，app.php 里的 version 是系统版本号
├── plugins/                    ← 插件都在这儿
│   ├── addons/                 功能扩展插件（本文说的就是这类，当前装着 blacklist）
│   ├── sms/ mail/              短信 / 邮件通道插件（当前装着 aliyun、qcloud、smsbao、thinkapi、smtp……）
│   ├── payment/                支付通道插件（装了才有这个目录）
│   ├── transfer/               代付通道插件
│   ├── profitsharing/          分账插件
│   └── applyments/             进件插件
├── public/index.php            入口
├── route/                      路由：app.php（前台）、admin.php（后台）、user.php（商户端）、api.php
└── vendor/                     Composer 依赖
```

**插件分成几类**，目录彼此并列，各自被系统里不同的地方加载：`addons/` 是功能扩展插件（本文的主角，系统里就叫
「功能扩展类插件」）；其余几类是通道类插件，各自实现一个接口（`BasePayment`、`BaseProfitSharing`、`BaseSms`、
`BaseMail`、`BaseApplyment`……），接口定义都在 `app/common/` 下，签名直接翻就能看到。哪一类目录下没东西，
那个目录就不存在，装一个对应的插件它才会出现。

### 1.3 一次请求怎么走

```
public/index.php                 入口有 PHP 8.2 守卫，且要求装了 swoole_loader，否则跳 /loader_helper.php
  → ThinkPHP 8 启动（config/ 配置 + app/common.php 全局函数 + plugins\ 与 app\ 两个 PSR-4 命名空间）
  → 全局中间件 app/middleware.php（只有一个：LoadConfig）
        LoadConfig 读 .env、把 pre_config 灌进 config('sys.*')、
        第 68 行调用 AddonManager::boot()  ← 插件实例在这一步全部装载、boot() 在这里执行
  → 路由文件被 include（route/*.php 里按入口分组注册）
      route/app.php     前台 / 收银台 / 回调 / cron，第 82 行调用 AddonManager::registerCommonRoutes()
      route/admin.php   后台，后台路径分组内第 452 行调用 AddonManager::registerAdminRoutes()
      route/user.php    商户端，第 261 行调用 AddonManager::registerUserRoutes()
      route/api.php     商户 API
  → 路由匹配
  → 路由级中间件（后台：SessionInit → RefererCheck → AuthAdmin）
  → 控制器 → 逻辑层 → 数据库
```

两个时序上的要点：

- **插件在路由匹配之前就已经 `boot()` 过**（`LoadConfig` 里那一行），所以 `boot()` 里注册钩子和中间件永远来得及；
- **插件的路由注册发生在路由中间件之前**（路由文件是在 `pipeline('route')` 之前被 include 的），
  所以插件路由和系统路由进的是同一张路由表，插件路由落在自己文件末尾注册，不会顶掉前面注册的同名系统路由。

插件挂上去的三个时机，就是上面那三行；插件路由天然落在对应的分组里，所以**自动带上那一组的中间件和路径前缀**，
不用自己写鉴权。

### 1.4 加密边界

`app/` 下部分文件被 `swoole_loader` 加密过（看到文件头是
`<?php extension_loaded('swoole_loader') or die(...)` 就是加密文件），以下是**加密、读不了原文**的：

- `app/controller/admin/*`、`app/controller/user/*`、`app/controller/Install.php`、`app/controller/Cron.php` 全部；
- `app/controller/payment/*` 除 `Qqbot.php`；`app/controller/transfer/*`；
- `app/logic/*` 全部；`app/service/*` 除 `ProfitSharingService.php`；
- `app/lib/Plugin.php`、`app/lib/AppCloud.php`、`app/lib/Template.php`。

判断方法（PowerShell）：

```powershell
Get-Content .\app\lib\Plugin.php -TotalCount 1
```

**剩下的都是明文**，包括插件最相关的这一批：`app/common.php`、`app/common/BaseAddon.php`、
`app/lib/AddonManager.php`、`app/lib/MsgNotice.php`、`app/lib/Order.php`、`app/lib/Payment.php`、
`app/lib/Channel.php`、`app/cron/*`、`app/middleware/*`、`route/*`、`app/sql/*`。

加密带来的实际影响就一条：**付款成功之后的处理（改订单状态、加余额、给商户发通知）在
`PaymentService` / `OrderProcessService` / `OrderNotifyService` 里，读不到。**
想让这些流程带上自己的逻辑，办法是**往它要读的表里写数据**（见 9.1），而不是去改流程。

---

## 二、插件是怎么被加载的

### 2.1 目录与命名

目录名 = 插件名 = 主类名的前缀，三者必须对上：

```
plugins/addons/blacklist/                        目录名 blacklist
├── BlacklistPlugin.php                          主类 plugins\addons\blacklist\BlacklistPlugin
├── info.json                                    插件信息 + 后台配置表单
├── install.sql                                  安装时执行
├── update.sql                                   升级时执行
├── uninstall.sql                                卸载时执行
├── controller/                                  控制器
├── cron/                                        定时任务类
├── lib/                                         业务逻辑
├── middleware/                                  路由中间件
└── static/                                      后台页面用的 JS/CSS
```

类名规则写死在 `AddonManager::loadAddonInstance()`：

```php
$className = 'plugins\addons\\' . $name . '\\' . ucfirst($name) . 'Plugin';
```

`blacklist` → `BlacklistPlugin`，`orderstat` → `OrderstatPlugin`（**只有首字母大写**，不是驼峰）。
`composer.json` 里已经注册了 `plugins\` → `plugins/` 的 PSR-4 映射，所以目录名和命名空间一致就行。

### 2.2 加载条件

一个插件要真正生效，得同时满足：

1. `plugins/addons/<名字>/` 目录存在，且里面**有 `info.json`**（后台的「刷新插件列表」就是扫这个文件的）；
2. `pre_plugin` 表里有这一行，`type = 'addons'`（一般由插件的 `install.sql` 用 `INSERT IGNORE` 写进去）；
3. `pre_config` 里 `addon_<名字>_installed` 等于 `'1'`（后台点「安装」时系统写）；
4. `pre_config` 里 `addon_<名字>` 等于 `'1'`（后台点「启用」时系统写）。

四条都满足，系统每次启动才会实例化它并调用 `boot()`：

```php
// app/lib/AddonManager.php
$rows = Db::name('plugin')->where('type', 'addons')->cache('addons', 0)->column('name');
foreach ($rows as $name) {
    $instance = self::loadAddonInstance($name);
    if (!$instance) continue;
    if (!self::isEnabled($name)) continue;
    self::$instances[$name] = $instance;
    $instance->boot();          // ← 注册钩子、注册中间件都写在这儿
}
```

`AddonManager::boot()` 只在第一个请求里跑一次（进程内静态标记），所以 `boot()` 里注册的东西是「每请求一次」的粒度，
不是常驻内存的粒度。

### 2.3 生命周期

| 动作 | 后台按钮 | 系统做的事 |
|---|---|---|
| 安装 | 安装 | 检查 `info.json` 的 `require_version` 是否高于系统版本 → `install()` → `install.sql` 执行 → 写 `addon_<名字>_installed = 1` → 清缓存 |
| 启用 | 启用 | `enable()` → 写 `addon_<名字> = 1` → 清缓存 |
| 停用 | 停用 | `disable()` → 写 `addon_<名字> = 0` → 清缓存 |
| 更新 | 更新 | `update()` → `update.sql` 执行 → 清缓存 |
| 卸载 | 卸载 | `disable()` → `uninstall()` → `uninstall.sql` 执行 → 删掉 `addon_<名字>`、`addon_<名字>_installed`、`plugin_addons_<名字>` → 清缓存 |

`install()` / `uninstall()` / `update()` 基类已经实现好（就是执行同名 `.sql` + `Cache::clear()`），
要额外做事就覆写，但**记得 `parent::` 先跑**：

```php
public function install(): bool
{
    parent::install();          // 先执行 install.sql
    // 这里可以做额外的事，比如写一条默认配置
    return true;
}
```

---

## 三、info.json

一个 JSON 对象，后台「插件管理」列表和插件配置页都读它。

### 3.1 字段

| 字段 | 类型 | 说明 |
|---|---|---|
| `name` | string | 插件名，**必须和目录名一致** |
| `title` | string | 中文显示名，后台列表和菜单用 |
| `author` | string | 作者 |
| `link` | string | 作者 / 插件主页 |
| `version` | string | 版本号。升级就是覆盖目录后点「更新」，`update.sql` 每次重跑，所以版本号主要给人看 |
| `icon` | string | 图标地址，可留空串 |
| `desc` | string | 一句话说明，后台列表里显示 |
| `require_version` | string | 可选。**最低系统版本**，和 `config/app.php` 里的 `version` 比大小（纯数字比较），低了后台不给装 |
| `inputs` | object | 后台配置表单的定义，外面那层键就是配置键名，见 3.2 |
| `note` | string | 配置页顶部的一段提示文字 |

`require_version` 的检查逻辑：

```php
if ((int)$info['require_version'] > (int)config('app.version')) {
    throw new \Exception('该扩展最低系统版本要求为' . $info['require_version'] . '，请先更新系统至最新版本');
}
```

### 3.2 inputs：后台配置表单

`inputs` 是一个**对象**：外面那层键就是配置键名（插件里用这个名字取值），值描述这一项怎么渲染。
系统只认两种控件 —— `input`（单行文本）和 `select`（下拉）。填好的值存进 `pre_config` 的
`plugin_addons_<插件名>` 这一个键里（值是 PHP 序列化的数组，`cache = 0`）。

| 键 | 说明 |
|---|---|
| `name` | 显示给用户看的**标签文字**（不是键名，键名在外面那层） |
| `type` | `input`（单行文本）或 `select`（下拉） |
| `required` | 是否必填 |
| `note` | 输入框下面的说明文字 |
| `options` | **仅 `select` 用**：选项文字数组 |
| `value` | 默认值；`select` 这里填**选项的下标**（字符串 `"0"`、`"1"`……） |

⚠️ `select` 存下来的是**选项的下标**，不是选项文字 —— 插件里拿到的就是下标，自己映射回含义。

`blacklist` 的 `inputs` 是一份完整样本（两项 input、一项带默认值的 select）：

```json
"inputs": {
    "api_url": {
        "name": "平台接口地址",
        "type": "input",
        "required": true,
        "note": "填到 api 这一层，默认 https://yzf.aiapizz.com/api（结尾带不带斜杠都行）",
        "value": "https://yzf.aiapizz.com/api"
    },
    "api_key": {
        "name": "平台接口密钥",
        "type": "input",
        "required": true,
        "note": "平台「用户中心」里那串 32 位密钥"
    },
    "pull_type": {
        "name": "下发哪些类型",
        "type": "select",
        "required": true,
        "options": ["账号和 IP 都要", "只要账号", "只要 IP"],
        "value": "0",
        "note": "选「只要账号」时，IP 条目仍然记在本地，只是不写进系统黑名单表"
    }
}
```

### 3.3 完整示例

```json
{
    "name": "orderstat",
    "title": "订单统计",
    "author": "披萨插件",
    "link": "https://yzf.aiapizz.com",
    "version": "1.0",
    "icon": "",
    "desc": "把每天的交易汇总成一张表，在后台看趋势。",
    "require_version": "2067",
    "inputs": {
        "keep_days": {
            "name": "统计保留多少天",
            "type": "input",
            "required": false,
            "note": "留空表示一直保留",
            "value": "365"
        },
        "show_mode": {
            "name": "后台页面展示方式",
            "type": "select",
            "required": false,
            "options": ["表格", "只显示总数"],
            "value": "0",
            "note": "选「只显示总数」时页面就一行数字"
        }
    },
    "note": "安装后在「插件管理 → 订单统计 → 配置」里改这几项。"
}
```

---

## 四、插件主类

继承 `app\common\BaseAddon`。基类全文很短，方法如下：

| 方法 | 说明 |
|---|---|
| `abstract static getName(): string` | **必须实现**，返回插件名（和目录名一致） |
| `boot(): void` | 插件启动时调用。注册钩子、注册全局中间件写在这里 |
| `getAddonPath(): string` | 返回插件目录的绝对路径（带结尾分隔符） |
| `registerAdminRoutes(): void` | 注册后台路由 |
| `registerUserRoutes(): void` | 注册商户端路由 |
| `registerCommonRoutes(): void` | 注册前台/公共路由 |
| `getAdminMenus(): array` | 返回要注入后台侧边栏的菜单 |
| `getUserMenus(): array` | 返回要注入商户端菜单的菜单 |
| `install(): bool` | 执行 `install.sql` + 清缓存 |
| `uninstall(): bool` | 执行 `uninstall.sql` + 清缓存 |
| `update(): bool` | 执行 `update.sql` + 清缓存 |
| `enable(): bool` / `disable(): bool` | 基类只 `return true`，要做事就覆写 |
| `executeSqlFile(string $sqlFile): void` | protected，SQL 执行器，见 7.3 |

构造函数已经帮你把两个属性填好了：

```php
public function __construct()
{
    $this->addonName = static::getName();
    $this->addonPath = PLUGIN_ROOT . 'addons' . DIRECTORY_SEPARATOR . $this->addonName . DIRECTORY_SEPARATOR;
}
```

最小可运行的主类就这么多：

```php
<?php

namespace plugins\addons\orderstat;

use app\common\BaseAddon;

class OrderstatPlugin extends BaseAddon
{
    public static function getName(): string
    {
        return 'orderstat';
    }
}
```

---

## 五、扩展点

系统留给插件的口子一共四类：**钩子**、**路由**、**菜单与后台页面**、**计划任务**。下面逐个说。

### 5.1 钩子

钩子就是「系统跑到某一步时，回头问一句插件有没有话要说」。整个系统里明文可查的触发点只有 **4 个**，
全在明文文件里，位置精确到行。

#### 注册与执行

```php
// 注册：一般在主类的 boot() 里
\app\lib\AddonManager::addHook('cron_daily', [$this, 'onDaily'], 10);

// 执行（系统内部调用）
$results = \app\lib\AddonManager::executeHook('cron_daily', ['lastday' => $lastDay]);
```

- 第三个参数是**优先级，数字越小越先执行**，默认 10；
- `executeHook()` 会把所有回调的返回值按执行顺序收集成数组返回，**系统自己决定怎么用这个数组**；
- 回调签名固定为 `function (array $params)` 或 `function (array $params): string`，`$params` 是关联数组。

```php
// app/lib/AddonManager.php
public static function addHook(string $name, callable $callback, int $priority = 10): void
{
    self::$hooks[$name][] = ['callback' => $callback, 'priority' => $priority];
}

public static function executeHook(string $name, array $params = []): array
{
    $results = [];
    if (!isset(self::$hooks[$name])) return $results;
    $hooks = self::$hooks[$name];
    usort($hooks, fn($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($hooks as $hook) {
        $results[] = call_user_func($hook['callback'], $params);
    }
    return $results;
}
```

注册了但系统没触发过的钩子名不会报错，只是永远不执行。

#### 钩子 1：`complain_auto_reply` —— 投诉自动回复内容

| 项 | 内容 |
|---|---|
| 触发点 | `app/common/BaseComplain.php:218`，方法 `autoReply($thirdid, $status, $complaint_content = null)` |
| 时机 | 系统处理投诉、准备自动回复投诉人的那一步 |
| 入参 | `thirdid`（渠道方投诉单号）、`status`、`complaint_content`（投诉内容）、`channel`（当前通道数组）、`complain_handler`（当前投诉处理对象） |
| 返回值 | **第一个返回非空字符串的插件胜出**，那个字符串就成了自动回复的内容；返回 `''` 或其它类型会被忽略 |
| 系统默认 | 没插件返回时用 `config_get('complain_auto_reply_con')` 里配的文案；还是空的就不回复 |

系统侧原文：

```php
protected function autoReply($thirdid, $status, $complaint_content = null)
{
    $content = config_get('complain_auto_reply_con');
    $results = \app\lib\AddonManager::executeHook('complain_auto_reply', [
        'thirdid' => $thirdid,
        'status'  => $status,
        'complaint_content' => $complaint_content,
        'channel' => $this->channel,
        'complain_handler' => $this,
    ]);
    foreach ($results as $result) {
        if (is_string($result) && trim($result) !== '') {
            $content = trim($result);
            break;
        }
    }
    if (empty($content)) return;
    usleep(300000);
    $this->feedbackSubmit($thirdid, '', $content);   // 目前只有支付宝通道实现了反馈
}
```

写法（`blacklist` 就是这么干的）：

```php
// 主类 boot()
\app\lib\AddonManager::addHook('complain_auto_reply', static function (array $params): string {
    return \plugins\addons\orderstat\lib\Reply::compose($params);
}, 10);

// lib/Reply.php
public static function compose(array $params): string
{
    $content = (string)($params['complaint_content'] ?? '');
    // ……按内容决定回什么，拿不准就返回 '' 交给系统
    return '';
}
```

#### 钩子 2：`cron_daily` —— 每日维护

| 项 | 内容 |
|---|---|
| 触发点 | `app/cron/Order.php:93`，系统每日维护任务 `order`（name：系统每日数据维护）里 |
| 时机 | 系统把当天的清理、邀请返现、用户组到期这些都做完**之后**，写 `order_time` 打点**之前** |
| 入参 | `['lastday' => '2026-10-02']`（昨天的日期，`Y-m-d`） |
| 返回值 | 系统**不看返回值**，返回什么都行 |

```php
// app/cron/Order.php
AddonManager::executeHook('cron_daily', ['lastday' => $lastDay]);
config_set('order_time', date('Y-m-d H:i:s'));
return $lastDay . '系统每日数据维护任务执行成功';
```

这个钩子的执行时机在 `order_time` 打点之前，也就是说：**钩子抛异常会让当天维护被判成没跑完**，
下一轮还会重跑。所以回调里的耗时活儿和网络请求自己 `try/catch` 兜住，别让它冒出去。

#### 钩子 3：`check_corp_cert` —— 企业实名核验

| 项 | 内容 |
|---|---|
| 触发点 | `app/common.php:550`，函数 `check_corp_cert($companyName, $creditNo, $legalPerson)` 的第一件事 |
| 入参 | `companyName`（企业名）、`creditNo`（统一社会信用代码）、`legalPerson`（法人） |
| 返回值 | **第一个带 `code` 键的数组胜出**，直接作为核验结果返回给调用方 |

返回结构（照系统自己的写法）：

```php
return ['code' => 0,  'msg' => '一致'];        // code = 0 表示通过
return ['code' => -1, 'msg' => '公司与法人信息不一致'];
return ['code' => -2, 'msg' => '返回结果解析失败'];
```

系统默认走数脉 API（`cert_appcode2` 配置），插件返回了带 `code` 的数组就不走默认那条路了。
没有插件接管时才执行下面这段：

```php
foreach ($results as $result) {
    if (is_array($result) && isset($result['code'])) {
        return $result;
    }
}
```

#### 钩子 4：`certificate_scan_type` —— 实名认证走哪个通道

| 项 | 内容 |
|---|---|
| 触发点 | `app/common.php:601`，函数 `get_cert_scan_type()` 里 |
| 入参 | 空数组 |
| 返回值 | 只认 `'alipay'`、`'wechat'`、`'phone'` 三个字符串，别的一律忽略 |

```php
function get_cert_scan_type(): string
{
    $open = (int) config_get('cert_open');
    if ($open === 2) return 'phone';
    if ($open === 4) return 'wechat';
    if ($open === 6) {
        $results = \app\lib\AddonManager::executeHook('certificate_scan_type', []);
        foreach ($results as $result) {
            if (is_string($result) && in_array($result, ['alipay', 'wechat', 'phone'], true)) {
                return $result;
            }
        }
    }
    return 'alipay';
}
```

注意只有 `cert_open = 6`（后台把认证方式设成「插件决定」）时这个钩子才会被执行。

#### 完整钩子清单

| 钩子名 | 触发文件:行 | 入参 | 返回值语义 |
|---|---|---|---|
| `cron_daily` | `app/cron/Order.php:93` | `['lastday' => 'Y-m-d']` | 忽略 |
| `complain_auto_reply` | `app/common/BaseComplain.php:218` | `thirdid` / `status` / `complaint_content` / `channel` / `complain_handler` | 第一个非空字符串当自动回复内容 |
| `check_corp_cert` | `app/common.php:550` | `companyName` / `creditNo` / `legalPerson` | 第一个带 `code` 的数组即结果，`code = 0` 为通过 |
| `certificate_scan_type` | `app/common.php:601` | 空 | 第一个为 `alipay`/`wechat`/`phone` 的字符串 |

### 5.2 路由

主类里有三个注册方法，分别在三个不同的时机被调用，挂进去的路由自动带上那一组的前缀和中间件。

| 方法 | 调用处 | 最终地址前缀 | 中间件 |
|---|---|---|---|
| `registerCommonRoutes()` | `route/app.php:82` | 无（站点根） | **没有**，要鉴权自己写 |
| `registerAdminRoutes()` | `route/admin.php:452` | `/<后台路径>`（默认 `/admin`，可用 `.env` 的 `admin_path` 改） | `SessionInit` + `RefererCheck` + `AuthAdmin`，自动带上 |
| `registerUserRoutes()` | `route/user.php:261` | `/user` | `user` 这一层分组本身**没挂中间件**（鉴权挂在各个子分组上），所以插件用户路由默认也没有，要自己写 |

系统里可用的路由级中间件（`app/middleware/`，都是明文）：

| 中间件 | 作用 |
|---|---|
| `SessionInit` | 启动 Session（登录态来源） |
| `RefererCheck` / `UserRefererCheck` | 校验 Referer 主机是不是本站，防 CSRF |
| `AuthAdmin` / `AuthUser` | 校验管理员 / 商户登录态 |
| `ViewOutput` | 渲染输出（模板页） |
| `ApiVerify` | 商户 API 鉴权，并对成功响应加 RSA 签名 |

插件路由要鉴权时，最省事的做法是在自己的控制器里判断（后台路由已经带了管理员校验；
公共路由像 `blacklist` 那样自己校验一个密钥，见 5.7 路二）。

注册写法就是 ThinkPHP 8 的 `Route` 门面：

```php
use think\facade\Route;

public function registerAdminRoutes(): void
{
    Route::group('addon/orderstat', function () {
        Route::get('info', '\plugins\addons\orderstat\controller\Admin@info');
        Route::post('save', '\plugins\addons\orderstat\controller\Admin@save');
    });
}
```

控制器要写**完整类名**（`\plugins\addons\orderstat\controller\Admin`），后面接 `@方法名`。
`blacklist` 的注册结果：`/admin/addon/blacklist/info`（后台）、`/addon/blacklist/task`（公共）。
注意后台路径可以被站主改掉，所以**插件自己的前端不要写死 `/admin`**（见 5.5）。

### 5.3 全局路由中间件

想让每个请求都过一遍自己的代码（比如在系统校验之前刷一遍数据），在 `boot()` 里往路由中间件队列加一层：

```php
public function boot(): void
{
    try {
        $mw = app('middleware');
        if ($mw && method_exists($mw, 'route')) {
            $mw->route(\plugins\addons\orderstat\middleware\Refresh::class);
        }
    } catch (\Throwable $e) {
    }
}
```

中间件写法就是 ThinkPHP 的标准写法：

```php
<?php

namespace plugins\addons\orderstat\middleware;

use think\Request;
use think\Response;

class Refresh
{
    public function handle(Request $request, \Closure $next): Response
    {
        // 进入控制器之前
        $response = $next($request);
        // 出结果之后
        return $response;
    }
}
```

`app('middleware')->route()` 注册的中间件对**所有路由**生效，粒度比钩子粗。
**只要下行**（不改响应）的活儿写在 `$next($request)` 前面。

### 5.4 后台菜单

`getAdminMenus()` 返回一个数组，每项一个菜单。系统会把所有插件的菜单合起来按 `sort` 升序排。

| 键 | 说明 |
|---|---|
| `parent` | 父级菜单的 `name`，空串表示顶级菜单 |
| `sort` | 排序值，越小越靠前 |
| `scriptUrl` | 前端脚本地址（可空） |
| `menu` | Vue Router 的菜单结构，见下 |

`menu` 里的键：

| 键 | 说明 |
|---|---|
| `path` | 路由路径，随便起，别和系统已有的重名 |
| `name` | 路由名，全局唯一 |
| `component` | **固定写 `'/plugin/index'`** —— 这是系统内置的插件页面壳子 |
| `meta.title` | 菜单显示的文字 |
| `meta.keepAlive` | 是否缓存页面 |
| `meta.roles` | 能看见这个菜单的角色，一般是 `['R_SUPER', 'R_ADMIN']` |
| `meta.scriptUrl` | 和外面的 `scriptUrl` 一样，壳子靠它加载你的 JS |

系统现有的顶级菜单 `name`（`parent` 填这些）：`Dashboard`、`TradeManage`（交易管理）、
`ProfitSharingManage`、`TransferManage`、`SettleManage`、`MerchantManage`、`PayApiManage`、
`ApplymentsManage`、`ComplainManage`、`OtherManage`、`AppManage`、`System`。
填了系统不认识的 `parent`，菜单不会出现在侧边栏里。

```php
public function getAdminMenus(): array
{
    $script = '/addon/orderstat/asset';

    return [
        [
            'parent'    => 'TradeManage',
            'sort'      => 20,
            'scriptUrl' => $script,
            'menu'      => [
                'path'      => 'orderstat',
                'name'      => 'OrderStat',
                'component' => '/plugin/index',
                'meta'      => [
                    'title'     => '订单统计',
                    'keepAlive' => false,
                    'roles'     => ['R_SUPER', 'R_ADMIN'],
                    'scriptUrl' => $script,
                ],
            ],
        ],
    ];
}
```

### 5.5 后台页面

系统的后台是 **Art Design Pro（Vue 3 + Element Plus + Vite）** 的预构建单页应用，产物在
`public/static/assets/`，入口 `public/admin/index.php`。插件不参与这个构建，**插件页面的全部内容就是一份普通 JS 脚本**。

系统内置了一个加载器组件（路由 `component` 写 `'/plugin/index'`）负责这份脚本，它的行为是：

```js
// 加载器（打包产物里的 views/plugin/index.vue）
window.Vue = Vue;                     // 把 Vue 挂到全局
window.ElementPlus = ElementPlus;     // 把 Element Plus 挂到全局
// 动态插入 <script src = 路由 meta.scriptUrl>
// 读 window.__ART_PLUGIN_COMPONENT__ 作为要渲染的组件
// 渲染时只传 { key: 2 }，不传任何 props
```

由此定下两条：

- **脚本必须自己挂 `window.__ART_PLUGIN_COMPONENT__`**，组件拿不到任何 props，要什么数据自己去接口拿；
- **`window.Vue` 和 `window.ElementPlus` 是完整的命名空间**，可以直接
  `Vue.defineComponent({...})`、`Vue.h('div', ...)`、`ElementPlus.ElButton`，也就是说**也能用 Vue + Element Plus 写**，
  不一定非得纯 DOM。没有 `window.ArtDesignPro` 这个东西。
  下面给的是纯 DOM 的写法（不依赖任何全局，最稳），要用 Vue 就在 `mounted` 里 `Vue.createApp(...).mount(container)`。

#### 三步契约

**第一步：菜单指向脚本。** `scriptUrl` 指向一条**公共路由**（不能用后台路由，脚本加载时还没有登录态上下文），
这个路由返回 JS：

```php
// controller/Asset.php
public function js()
{
    $file = dirname(__DIR__) . '/static/admin.js';

    if (!is_file($file)) {
        $js = 'console.error("[订单统计] 插件脚本文件丢了");';
    } else {
        $js = (string)file_get_contents($file);
    }

    return response($js, 200, [
        'Content-Type'  => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
}
```

**第二步：脚本末尾注册组件。** 系统的 `/plugin/index` 壳子会读这个全局变量：

```js
window.__ART_PLUGIN_COMPONENT__ = {
    name: 'OrderStatPage',
    inheritAttrs: false,
    render: function () { return null; },   // 自己画 DOM，所以 render 返回 null
    mounted: function () { mount(this); },  // this.$el 是壳子给的锚点
    unmounted: function () { unmount(); },
};
```

**第三步：在 `mounted` 里找宿主节点，把内容贴上去。** 壳子给的是一个空锚点，往上找一层带
`art-page-view` / `art-main` / `layout-content` / `app-main` 类名的父节点，就是内容区；
找不到就退到 `document.body`。

```js
var container = null;

function mount(vm) {
    var tries = 0;

    (function go() {
        var el = vm && vm.$el;
        var host = null;

        try { host = (el && el.parentNode) ? el.parentNode : null; } catch (e) { host = null; }

        if (!host && tries++ < 10) { setTimeout(go, 40); return; }
        if (!host) { host = document.body; }

        attach(host);
    })();
}

function attach(host) {
    if (container && container.parentNode) container.parentNode.removeChild(container);

    container = document.createElement('div');
    container.className = 'art-page-view';      // 用系统的类名，间距和背景跟系统一致
    host.appendChild(container);
    render();
}

function unmount() {
    if (container && container.parentNode) container.parentNode.removeChild(container);
    container = null;
}
```

因为后台是单页应用，切菜单不会重新加载脚本，**同一份脚本可能被复用、`mounted` 可能被调多次**，
所以 `attach` 里先清掉旧节点。

#### 脚本怎么找到自己的后台接口地址

后台路径可以被站主改（`env('admin_path', 'admin')`），所以脚本不能写死 `/admin`。
办法是**从自己 `<script src>` 里切出站点根路径**，再拼两条候选地址：

```js
(function () {
  'use strict';

  function assetPath() {
    var src = '';
    try { src = (document.currentScript && document.currentScript.src) || ''; } catch (e) { src = ''; }

    if (!src) {
      try {
        var list = document.getElementsByTagName('script');
        for (var i = list.length - 1; i >= 0; i--) {
          if (list[i].src && list[i].src.indexOf('addon/orderstat') >= 0) { src = list[i].src; break; }
        }
      } catch (e2) { src = ''; }
    }

    if (!src) return '';
    try { return new URL(src, location.href).pathname || ''; } catch (e3) { return ''; }
  }

  var ASSET = assetPath();          // 例如 /addon/orderstat/asset
  var ROOT  = '';
  (function () {
    var at = ASSET.indexOf('/addon/orderstat/');
    if (at >= 0) {
      ROOT = ASSET.slice(0, at);    // 切出站点根路径
    } else {
      ROOT = '/' + ((location.pathname.split('/')[1] || '').replace(/\/+$/, ''));
      if (ROOT === '/admin') ROOT = '';
    }
  })();

  // 后台路径通常是 admin，先试 /admin/addon/...；站主改过就落到第二条
  var BASES = [ROOT + '/admin/addon/orderstat/', ROOT + '/addon/orderstat/'];
  var BASE  = 0;
})();
```

#### 请求与返回约定

- 用原生 `fetch`，**必须带 `credentials: 'same-origin'`**（后台的登录态是 Cookie，不带就 401）；
- 有参数时用 POST + `Content-Type: application/json`，请求体是 JSON；无参数用 GET；
- 响应统一是 `{ code, msg, data }`，`code = 200` 为成功（`json_response()` 生成的，见 8.1）；
- 第一条地址返回 404 / 403 时自动换第二条：

```js
function request(url, payload) {
  var opt = {
    method: payload ? 'POST' : 'GET',
    credentials: 'same-origin',
    headers: payload
      ? { 'Content-Type': 'application/json', 'Accept': 'application/json' }
      : { 'Accept': 'application/json' },
  };
  if (payload) opt.body = JSON.stringify(payload);

  return fetch(url, opt).then(function (r) {
    if (!r.ok) { var e = new Error('HTTP ' + r.status); e.http = r.status; throw e; }
    return r.json();
  }).then(function (j) {
    if (j && j.code !== 200) throw new Error((j && j.msg) || '接口返回失败');
    return (j && j.data) || {};
  });
}

function api(action, payload) {
  return request(BASES[BASE] + action, payload).catch(function (e) {
    if (BASE + 1 < BASES.length && (e.http === 404 || e.http === 403)) {
      BASE++;
      return request(BASES[BASE] + action, payload);
    }
    throw e;
  });
}
```

后台接口一律返回 JSON，用全局函数 `json_response()`：

```php
public function info()
{
    return json_response(200, 'ok', [
        'total' => (int) Db::name('order')->where('status', '>', 0)->count(),
    ]);
}
```

系统后台自己的请求约定：基址是相对的 `./`（所以后台路径怎么配都自动跟着走），带 Cookie，
请求头只加一个 `X-Requested-With: XMLHttpRequest`，**成功码是 `200`**，`401` 未登录、`403` 无权限、`513` 站点未授权。
插件用裸 `fetch` 时**必须自己带 `credentials: 'same-origin'`**（`X-Requested-With` 加上更贴近系统、不加也能用），
`BASES` 的自动回退正是靠 404 / 403 这两个码判断的。

#### 样式

页面的样式**写在脚本里，给容器加一个自己的作用域类名**，颜色全部用 Element Plus 的 CSS 变量，
这样系统切换主题（包括暗色）时页面跟着变：

```js
var CSS = [
  '.os-page{--os-bd:var(--el-border-color-lighter,#ebeef5);',
  '--os-ink:var(--el-text-color-primary,#303133);',
  '--os-fill:var(--el-fill-color-light,#f5f7fa);',
  'color:var(--os-ink);font-size:14px;line-height:1.6}',
  '.os-page .os-card{background:var(--el-bg-color,#fff);border:1px solid var(--os-bd);',
  'border-radius:10px;padding:16px 18px;margin-bottom:14px}',
].join('');

function ensureCss() {
  if (document.getElementById('os-page-css')) return;
  var s = document.createElement('style');
  s.id = 'os-page-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
```

常用变量：`--el-bg-color`、`--el-fill-color-light`、`--el-text-color-primary`、
`--el-text-color-secondary`、`--el-border-color-lighter`、`--el-color-primary`、`--el-color-danger`、
`--el-color-warning`、`--el-color-success`。

### 5.6 用户端菜单与页面

`getUserMenus()` 的格式和 `getAdminMenus()` **完全一样**，把菜单注入商户端。路由写在
`registerUserRoutes()` 里（前缀 `/user`）。

商户端也是一套同构的单页应用（入口 `public/user/index.php`，产物在 `public/static/assets/`），
而且**带的是同一个插件页面加载器**：菜单里一样写 `component: '/plugin/index'` + `meta.scriptUrl`，
脚本一样挂 `window.__ART_PLUGIN_COMPONENT__`，`window.Vue` / `window.ElementPlus` 一样可用。
商户端页面脚本的 `BASES` 把 `admin` 那一段换成 `user`（`ROOT + '/user/addon/<插件名>/'` 与
`ROOT + '/addon/<插件名>/'`）。

商户端现有的顶级菜单 `name`（`parent` 填这些）：`Account`、`Trade`、`Settle`、`Transfer`、`Record`、
`Domain`、`Complain`、`ApplymentsManage`、`ProfitSharing`、`SubChannel`、`Onecode`、`Invite`。

后台这条路已经由 `blacklist` 实测跑通；商户端菜单的合并/排序由加密的 `user\Common::getMenuList()` 处理，
做法照 5.5 先搭一个最小页面，确认锚点类名和菜单真的出现了，再往上加内容。

### 5.7 计划任务

两条路，按需要选。

#### 路一：注册进系统计划任务（推荐）

**第一步**：`install.sql` 往 `pre_crontab` 插一行。

```sql
INSERT IGNORE INTO `pre_crontab` (`task`,`name`,`description`,`plugin`,`frequency`,`interval`,`enabled`)
VALUES ('orderstat_daily','订单统计归档','把昨天的交易汇总进统计表','orderstat','day',1,1);
```

| 列 | 说明 |
|---|---|
| `task` | 任务唯一标识（有唯一索引），插件里 `identifier()` 返回同一个值 |
| `name` | 后台「计划任务」页显示的名字 |
| `description` | 说明 |
| `plugin` | **填插件名**，系统据此去插件的 `cron/` 目录找类；系统自带任务这里为空串 |
| `frequency` | 枚举：`second` / `minute` / `hour` / `day` / `once` |
| `interval` | 间隔数，配合 `frequency`：`second` + `60` 就是每 60 秒 |
| `execute_at` | 仅 `once` 用，执行的绝对时间 |
| `enabled` | 1 启用 / 0 停用 |
| `params` | JSON 字符串，会解码成数组传给 `execute()` |

**第二步**：在插件 `cron/` 下写任务类，继承 `app\common\AbstractCronTask`。
**类名是任务标识的大驼峰写法**：`blacklist_sync` → `BlacklistSync`，`orderstat_daily` → `OrderstatDaily`。
（系统调度时先看 `pre_crontab.plugin` 这一列，非空就去 `plugins/addons/<插件名>/cron/` 找类；
空的去系统的 `app/cron/` 找。）

```php
<?php

namespace plugins\addons\orderstat\cron;

use app\common\AbstractCronTask;
use plugins\addons\orderstat\lib\Stat;

class OrderstatDaily extends AbstractCronTask
{
    public static function identifier(): string
    {
        return 'orderstat_daily';           // 必须和 pre_crontab.task 一致
    }

    public static function name(): string
    {
        return '订单统计归档';
    }

    public function execute(array $params = []): string
    {
        $lastday = date('Y-m-d', strtotime('-1 day'));
        $row = Stat::archive($lastday);

        return '归档 ' . $lastday . '：' . $row['orders'] . ' 笔 / ' . $row['money'] . ' 元';
    }
}
```

接口 `app\common\CronTaskInterface`：

| 方法 | 静态 | 说明 |
|---|---|---|
| `identifier(): string` | 是 | 任务标识 |
| `name(): string` | 是 | 任务名 |
| `parameterDefinitions(): array` | 是 | 参数定义，`AbstractCronTask` 已默认返回 `[]`，要带参数就覆写 |
| `execute(array $params = []): string` | 否 | 干活，**返回值是字符串**，会写进 `last_error` 之外的执行结果里，也在 `php think cron <task>` 时打印出来 |

**调度怎么跑**（`app/command/Cron.php`，明文可读）：

后台「计划任务」的执行方式存在 `config_get('cron_mode')`，两个值：

- **`url`（默认）**：由服务器上的定时器（宝塔「计划任务 → 访问 URL」之类）按点打两个地址来推进，
  系统在 `route/app.php` 里开了这两条路由：

  | 地址 | 跑什么 |
  |---|---|
  | `/cron` | 系统自带任务 |
  | `/cron/plugin` | **`pre_crontab.plugin` 列非空的任务**（也就是插件注册的任务） |

- **`swoole`**：`php think cron`（不带参数）起一个常驻守护进程，**每 60 秒**刷一次 `pre_crontab`
  （`enabled = 1` 的行），到点的任务开协程跑；需要 swoole 扩展；执行方式被改掉、或者系统版本号变了，
  守护进程会自己退出（所以热更新后要重新拉起）。

另外两条：

- **单跑一个任务调试**：`php think cron <任务标识>`，例如 `php think cron orderstat_daily`。
  它按 `pre_crontab.task` 找行、抢锁、执行，输出结果到命令行；
- **并发保护**：`lock_token` / `locked_until` 两列做锁，同一个任务不会同时跑两遍；
  失败信息会写进 `last_status` / `last_error`。

想给任务加参数，覆写 `parameterDefinitions()`，后台「计划任务」页会照着它自动渲染表单，
值以 JSON 存在 `params` 列、运行时解码成数组传进 `execute()`：

```php
public static function parameterDefinitions(): array
{
    return [
        'refresh_minutes' => [
            'label'    => '刷新频率（分钟）',
            'type'     => 'number',
            'required' => true,
            'min'      => 15,
            'default'  => 60,
        ],
    ];
}
```

系统自带的十个任务（`app/cron/`，可以拿来当范本）：`order`（每日维护）、`settle`（结算）、
`notify`（商户通知重试）、`order_query`（订单查询）、`profitsharing`（分账）、`check`（通道检查）、
`complain`（投诉同步）、`applyrefresh`（进件刷新）、`balance_notice`（余额提醒）、`transfer`（代付）。

#### 路二：插件自己暴露一个带密钥的 URL

如果不想让站主去后台配计划任务，可以自己开一条公共路由，让站主把它挂到宝塔的定时器上。
`blacklist` 用的就是这个办法：

```php
// registerCommonRoutes()
Route::get('addon/blacklist/task', '\plugins\addons\blacklist\controller\Task@run');
Route::post('addon/blacklist/task', '\plugins\addons\blacklist\controller\Task@run');
```

控制器里自带一个密钥校验，密钥由插件生成、存在自己的配置里：

```php
public function run()
{
    $key = (string) $this->request->param('key', '');
    if ($key === '' || !hash_equals((string) $this->config['task_key'], $key)) {
        return json_response(403, '密钥不对');
    }
    // ……干活
}
```

两种方式可以同时存在，互不冲突。

---

## 六、配置与缓存

### 6.1 站点配置（`pre_config` 表）

```php
config_get(string $key, mixed $default = null, bool $force = false): mixed
config_set(string $key, mixed $value, bool $cache = true): bool
```

- 键值对存在 `pre_config(k, v, cache)` 表里；`cache = 1` 的键会被一起读进内存缓存 `config('sys.*')`，
  `config_get()` 默认走内存，**第三个参数传 `true` 强制读库**（比如在守护进程里想拿到最新值）；
- 系统安装时预置了一批键：`sitename`、`title`、`cron_mode`、`blackmsg`、`complain_auto_reply_con`、
  `settle_open`、`reg_open`、`test_open`…… 全部在 `app/sql/install.sql` 里；
- 插件**自己的**配置建议用下面那套，别往 `pre_config` 里塞自己的键，卸载时不好清理。

### 6.2 插件配置

```php
plugin_config_get(string $type, string $name): array
plugin_config_set(string $type, string $name, ?array $config): void
```

- `$type` 对功能扩展插件固定是 `'addons'`，`$name` 是插件名；
- 实际存在 `pre_config` 表里，键名是 **`plugin_{type}_{name}`**（例如 `plugin_addons_blacklist`），
  值是 `serialize()` 之后的数组，写入时 `cache = 0`；
- 后台插件配置页显示的表单由 `info.json` 的 `inputs` 决定（见 3.2），提交后系统就是调
  `plugin_config_set('addons', '插件名', $表单数据)` 存进去的；
- 取出来的是**用户填的原始数组**，字段可能缺，实际用的时候拿默认值合并一下：

```php
function config(): array
{
    $saved = plugin_config_get('addons', 'orderstat');   // 用户填的
    $defaults = [
        'keep_days' => '365',
        'show_mode' => '0',
    ];
    return array_merge($defaults, is_array($saved) ? $saved : []);
}
```

`select` 存下来的是下标字符串（`"0"`），用之前别忘了转成含义。

### 6.3 缓存

ThinkPHP 的缓存门面直接可用，系统自带的键名有 `configs`、`addons` 等，插件用自己的前缀避开：

```php
use think\facade\Cache;

Cache::set('orderstat_today', $data, 300);   // 秒
Cache::get('orderstat_today');
Cache::delete('orderstat_today');
Cache::clear();
```

`install()` / `uninstall()` / `update()` 执行完 SQL 之后系统会 `Cache::clear()` 一次，
所以改完表结构不用自己清缓存。

---

## 七、数据库

### 7.1 表前缀

系统所有表带前缀，**SQL 文件里一律写 `pre_`**，安装时系统会替换成站点实际的前缀。
PHP 里**永远不要自己拼前缀**，用 ThinkPHP 的门面，框架会补上：

```php
use think\facade\Db;

Db::name('order')->where('uid', $uid)->select();          // 自动变成 <前缀>order
Db::name('orderstat_daily')->insert([...]);               // 插件自建表同理
Db::table('order')->where(...)->find();                   // 等价写法
Db::execute('UPDATE ... ');                               // 原生 SQL，自己写全表名时要带前缀
```

字段类型约定跟系统保持一致：金额 `decimal(10,2)`、时间 `datetime`、开关 `tinyint(1)`。
系统表是 `utf8`，**插件自建表建议用 `utf8mb4`**，免得存不下 emoji。

### 7.2 常用表速查

| 表 | 干什么 | 关键字段 |
|---|---|---|
| `pre_config` | 站点配置 KV | `k`（主键）、`v`、`cache` |
| `pre_plugin` | 插件登记表。装一个插件写一行 | `id`（如 `addon_blacklist`）、`name`、`type`、`title`、`version`、`status`；唯一键 `(name,type)` |
| `pre_crontab` | 计划任务 | `task`（唯一）、`plugin`、`frequency`、`interval`、`enabled`、`params`、`next_run_time` |
| `pre_order` | 订单主表 | `trade_no`（19 位主键）、`out_trade_no`、`uid`、`type`、`channel`、`money`、`realmoney`、`getmoney`、`status`、`buyer`、`ip`、`mobile`、`notify`、`date`、`addtime`、`domain`、`param`、`ext` |
| `pre_user` | 商户 | `uid`、`gid`、`key`、`money`、`status`、`pay`、`settle`、`transfer`、`msgconfig`、`email`、`phone`、`upid` |
| `pre_blacklist` | 黑名单，付款校验读的就是它 | `type`（0 账号/手机号、1 IP）、`content`（≤50 字符）、`endtime`（NULL 为永久）、`remark`；唯一键 `(content,type)` |
| `pre_complain` | 交易投诉单 | `thirdid`（渠道单号）、`trade_no`、`uid`、`channel`、`title`、`content`、`type`、`status`、`money`、`times`、`addtime` |
| `pre_complaintask` | 投诉同步任务 | `channel`（唯一）、`days`、`frequency`、`nexttime`、`status` |
| `pre_record` | 商户余额变动流水 | `uid`、`action`、`money`、`oldmoney`、`newmoney`、`type`、`trade_no`、`date` |
| `pre_risk` | 风控记录 | `uid`、`type`、`content`、`date`、`status` |
| `pre_settle` / `pre_batch` | 结算单 / 批次 | `uid`、`money`、`realmoney`、`transfer_no`、`status` |
| `pre_transfer` | 代付/转账单 | `biz_no`、`out_biz_no`、`uid`、`money`、`status` |
| `pre_refundorder` | 退款单 | `refund_no`、`trade_no`、`money`、`status` |
| `pre_channel` / `pre_subchannel` / `pre_roll` | 通道 / 子通道 / 轮询组 | `type`、`status`、`rate`、`daytop`、`daystatus` |
| `pre_type` | 支付类型 | `id`、`name`（支付宝/微信/QQ 钱包……） |
| `pre_group` | 商户用户组 | `gid`、`name`、`rate`、`settings`、`config` |
| `pre_log` | 系统日志 | `uid`、`type`、`date`、`ip`、`city`、`data` |
| `pre_attachment` | 上传附件 | `id`、`path`、`type` |
| `pre_adminuser` | 后台管理员 | `id`、`username`、`password` |

订单 `status` 取值（由 `app/lib/Order.php` 的状态迁移条件反推）：
`0` 未支付、`1` 已支付、`2` 已退款、`3` 已冻结。

### 7.3 SQL 文件怎么被执行

`install.sql` / `uninstall.sql` / `update.sql` 都走 `BaseAddon::executeSqlFile()`：

```php
protected function executeSqlFile(string $sqlFile): void
{
    if (!is_file($sqlFile)) return;                       // 文件不存在就直接跳过
    $sql = file_get_contents($sqlFile);
    $prefix = config('database.connections.mysql.prefix', '');
    $sql = str_replace('pre_', $prefix, $sql);            // 前缀替换
    foreach (parseSql($sql) as $statement) {              // 按分号切成一条条
        if (!empty($statement)) {
            try {
                Db::execute($statement);
            } catch (\Throwable $e) {
            }                                                  // 单条失败不中断
        }
    }
}
```

由这几行可以看出写 SQL 时的几个要点：

1. **表名写 `pre_`**，系统会替换；
2. 每条语句**各自独立**、互不依赖失败与否，**每条都要能重复执行**：
   建表用 `CREATE TABLE IF NOT EXISTS`，插入用 `INSERT IGNORE`，删表用 `DROP TABLE IF EXISTS`，
   加列用 `ALTER TABLE ... ADD COLUMN`（这条重复跑会报错但会被 catch 掉，不影响后面的语句）；
3. **报错是静默的**，一条语句写错了不会有人告诉你，装完发现表没建上就是这个原因；
4. 文件不存在不报错，所以 `update.sql` 可以没有。

安装时最少要写的一条：把自己登记进 `pre_plugin`，否则系统根本不会加载：

```sql
INSERT IGNORE INTO `pre_plugin` (`id`,`name`,`type`,`title`,`desc`,`version`,`author`,`link`,`icon`,`status`)
VALUES ('addon_orderstat','orderstat','addons','订单统计','把每天的交易汇总成一张表','1.0','披萨插件','https://yzf.aiapizz.com','',0);
```

卸载时把自己带走（**只删自己建的**）：

```sql
DROP TABLE IF EXISTS `pre_orderstat_daily`;
DELETE FROM `pre_plugin` WHERE `name` = 'orderstat' AND `type` = 'addons';
DELETE FROM `pre_crontab` WHERE `task` = 'orderstat_daily' AND `plugin` = 'orderstat';
```

`update.sql` 是升级时跑的，每次点「更新」都会整跑一遍，所以里面的语句同样要能反复执行。
加了新的建表语句、往表里补了默认数据，都写在这里；**只有文件改动、没有 SQL 变化时，不用点升级**。

### 7.4 插件自己的表

- 表名加插件前缀（`pre_blacklist_*`、`pre_orderstat_*`），避免和系统表、别的插件撞名；
- 需要高频读写的运行态数据（计数器、同步台账）可以自己建 KV 表，不必硬塞进 `pre_config`；
- 系统原有的表**读没问题**；写的话注意 `pre_order` 的状态和金额字段（系统自己的状态机在
  `app/lib/Order.php`，绕开它直接写很容易写出对不上的数据），改商户余额一律走 `changeUserMoney()`。

---

## 八、系统现成函数速查

### 8.1 全局函数（`app/common.php`）

这个文件是明文，所有全局函数都在里面，下面是按用途分的全表（函数名后面是该文件里的行号，方便对照原文）。

**配置**

| 函数 | 行 | 说明 |
|---|---|---|
| `config_get($key, $default = null, $force = false)` | 327 | 读站点配置，`$force = true` 强制读库 |
| `config_set($key, $value, $cache = true)` | 337 | 写站点配置 |
| `plugin_config_get(string $type, string $name): array` | 351 | 读插件配置 |
| `plugin_config_set(string $type, string $name, ?array $config): void` | 367 | 写插件配置 |

**HTTP / 网络**

| 函数 | 行 | 说明 |
|---|---|---|
| `get_curl($url, $post = null, $referer = null, $cookie = null, $header = null, $ua = null, $nobaody = null, $addheader = null, $location = null)` | 9 | cURL 封装，返回响应正文；`$addheader` 传数组形式的自定义头 |
| `real_ip($type = 0)` | 54 | 取客户端真实 IP |
| `get_ip_region($ip)` / `get_ip_city($ip)` | 94 / 127 | IP 归属地 / 城市 |
| `getdomain($url)` / `get_host($url)` / `get_main_host($url)` | 379 / 387 / 392 | 从 URL 里取域名 |
| `is_url($url)` / `is_self_url($url)` | 1092 / 1101 | URL 合法性判断 |
| `get_server_ip()` | 1077 | 服务器 IP |
| `get_cdn_public()` | 1106 | 取静态资源 CDN 前缀配置 |

**响应 / 输出**

| 函数 | 行 | 说明 |
|---|---|---|
| `json_response($code = 200, $msg = 'success', $data = null)` | 1042 | 输出 `{code, msg, data}`；`$data` 为 `null` 时响应里不带 `data` 键 |
| `exception_log(Throwable $e, ?string $action = null): void` | 1129 | 写异常日志（带 `$action` 标记） |
| `showstar($num)` | 1083 | 数字脱敏显示 |

**金额 / 商户**

| 函数 | 行 | 说明 |
|---|---|---|
| `changeUserMoney($uid, $money, $add = true, $type = null, $orderid = null)` | 425 | **改商户余额的唯一正路**：会同时写 `pre_record` 流水；`$type` 是流水里的文字（如「代付退回」），`$orderid` 是关联单号 |
| `changeUserMoney2($uid, $oldmoney, $money, $add = true, $type = null, $orderid = null)` | 456 | 带乐观校验的余额变更 |
| `changeUserGroup($uid, $gid, $endtime = null)` | 471 | 改商户用户组 |
| `checkBlockUser($openid, $trade_no)` | 403 | 黑名单 + 当日限笔数 + 当日限金额校验；命中返回 `['type' => 'error', 'msg' => ...]`，没命中返回 `false`。同时会把 `$openid` 写回订单的 `buyer` 字段。调用点在加密的支付主流程里 |
| `getGroupConfig($gid)` / `mergeGroupConfig($gid)` | 746 / 766 | 取用户组配置（费率、通道权限），后者会把商户的 `channelinfo` 合并进来 |

**消息**

| 函数 | 行 | 说明 |
|---|---|---|
| `send_mail($to, $subject, $body)` | 136 | 发邮件，成功返回 `true`，失败返回**错误信息字符串** |
| `send_sms($phone, $tpl_code, $tpl_param)` | 150 | 发短信，`$tpl_code` 是模板码（`balance` / `complain` / `group`），`$tpl_param` 是模板变量数组；返回同上 |

更完整的通知走 `app\lib\MsgNotice`（见 9.5）。

**加解密 / 随机**

| 函数 | 行 | 说明 |
|---|---|---|
| `authcode($string, $operation = 'DECODE', $key = '', $expiry = 0)` | 233 | 对称加解密（可带过期时间） |
| `random($length, $numeric = 0)` | 274 | 随机字符串 |
| `getMd5Pwd($pwd, $salt = null)` | 290 | 密码散列 |
| `getMillisecond()` | 294 | 毫秒时间戳 |
| `generate_key_pair()` | 999 | 生成 RSA 密钥对 |
| `pemToBase64($data)` / `base64ToPem($data, $type)` | 1022 / 1033 | PEM 与 Base64 互转 |

**字符串 / 杂项**

| 函数 | 行 | 说明 |
|---|---|---|
| `parseSql(string $sql): array` | 978 | 把一段 SQL 按分号切成语句数组（`executeSqlFile` 用它） |
| `getSubstr($str, $leftStr, $rightStr)` | 299 | 取两个标记之间的内容 |
| `filter_utf8mb4($str)` | 319 | 过滤掉 4 字节字符（写入 `utf8` 表之前用） |
| `isNullOrEmpty($str)` | 311 | 空判断 |
| `is_idcard($id)` | 476 | 身份证号校验 |
| `checkmobile()` / `checkwechat()` / `checkalipay()` / `checkmobileqq()` / `checkunionpay()` / `checkdouyin()` | 165–210 | UA 判断当前是什么客户端 |
| `getMobileClientType()` | 217 | 移动端细分类型 |
| `randFloat($min = 0, $max = 1)` | 524 | 随机浮点数 |
| `currency_convert($from, $to, $amount)` | 687 | 汇率换算 |
| `combinepay_submoneys($money)` | 618 | 合并支付拆金额 |
| `checkDomain($domain)` | 611 | 域名格式校验 |
| `checkRefererHost()` | 511 | 来源域校验 |
| `get_cert_scan_type(): string` | 591 | 实名认证走哪个通道（见 5.1 钩子 4） |
| `check_cert($idcard, $name, $phone)` | 529 | 个人实名核验 |
| `check_corp_cert($companyName, $creditNo, $legalPerson)` | 548 | 企业实名核验（见 5.1 钩子 3） |
| `verify_captcha($user_id = 'public')` / `verify_captcha4()` | 706 / 727 | 验证码校验 |
| `getBankCardInfo($cardno)` | 965 | 银行卡信息 |
| `deleteDirectory(string $dirname): void` | 1137 | 递归删目录 |
| `getCertFilePath($filename, $create = false)` | 1119 | 证书文件路径 |

### 8.2 框架助手（ThinkPHP 8 自带）

| 助手 | 说明 |
|---|---|
| `app()` | 容器，`app('middleware')` 取中间件管理器 |
| `config('app.version')` / `Config::set($arr, 'sys')` | 框架配置；站点配置在 `config('sys.*')` 命名空间下 |
| `request()` | 当前请求对象；`request()->domain()`、`request()->root()`、`request()->param()` |
| `json_response()` 之外要自己造响应时用 `json()` / `response($body, $code, $header)` / `view()` |
| `Db::name('x')` / `Db::table('x')` | 查询构造器；`Db::execute()` 跑原生语句 |
| `Cache::get/set/delete/clear()` | 缓存 |
| `url()`, `session()`, `cookie()`, `env()` | 常用门面，够用 |
| `collect($arr)` | 数组转集合 |

### 8.3 系统服务类（`app/lib/`，明文）

| 类 | 说明 |
|---|---|
| `AddonManager` | 插件管理器：钩子、路由、菜单、加载（本文反复用到） |
| `Order` | 订单状态迁移：冻结 / 解冻 / 退款 / 关闭，`status` 取值的唯一明文依据 |
| `Channel` | 通道、子通道、轮询、费率、金额与频率限流、`daystatus` 门禁 |
| `Payment` | 签名（MD5 / RSA）、支付宝直付通结算、微信收付通结算、预授权、红包转账、银行列表 |
| `MsgNotice` | 全渠道消息通知（见 9.5） |
| `Kernel` 之外的 `Context` | 请求级上下文 |
| `Printer` | 易联云类小票打印机 |
| `WxMchRisk` | 微信「商户违规通知」处理，写 `pre_mchrisk` |
| `QqBot` | QQ 机器人 Webhook（验签 + 发 Markdown） |
| `TOTP` | 二次验证（两步验证） |
| `Oauth` / `QQConnect` / `TelegramConnect` | 各快捷登录 |
| `Ip2region` / `GeetestLib` | IP 归属地 / 极验 |
| `exception_log()` | 异常日志（全局函数） |

---

## 九、业务接入点

这一章讲「想干某件事，该往哪儿下手」。

### 9.1 付款前拦人

**这是插件最常见的一个需求，机制只有一个：往 `pre_blacklist` 表里写数据。**

系统的付款校验读的就是这张表，读的地方全在明文里：

| 位置 | 读什么 |
|---|---|
| `app/common.php:406`（`checkBlockUser`） | `type = 0`，买家账号 |
| `app/common.php:830`（微信小程序取手机号后） | `type = 0`，手机号 |
| `app/common.php:864`（支付宝授权后） | `type = 0`，手机号 |
| `app/common.php:911`（支付宝小程序授权后） | `type = 0`，手机号 |
| `app/controller/qrpay/Index.php:198/201` | `type = 1` 查 IP、`type = 0` 查账号 |
| `app/controller/applet/Cashier.php:132` | `type = 1` 查 IP，`type = 0` 查账号 |

命中后统一返回 `config_get('blackmsg', '系统异常无法完成付款')`，付款就断了。

```php
// 拦一个账号（或者手机号、openid）
Db::name('blacklist')->insert([
    'type'    => 0,
    'content' => $content,                 // 注意 varchar(50)
    'addtime' => date('Y-m-d H:i:s'),
    'endtime' => $endtime,                 // null = 永久
    'remark'  => '我的插件#' . $id,          // 打上自己的标记
]);
```

几个实打实的注意点：

- `content` 字段是 **varchar(50)**，超长的值写进去会被截断或者报错，塞之前先 `mb_strlen` 判一下；
- 唯一键是 `(content, type)`，重复插入会失败，所以先 `find()` 查一下或者用 `INSERT IGNORE`；
- **只动自己写的行**：系统的投诉自动拉黑会写 `remark = '投诉自动拉黑'`，站主也会手加永久黑名单，
  删改的时候用 `remark` 把自己的行挑出来，别全表清；
- 过期清理系统自己会做：`app/cron/Order.php:39` 每天删掉 `endtime < NOW()` 的行，所以 `endtime` 传 `null`
  才是永久。

既然读点在加密的支付主流程里，**插件改不了调用点，写数据是唯一的介入方式**；
`blacklist` 插件的做法是「把平台条目下发进这张表 + 挂一层路由中间件在请求早期刷新它」，
需要「拦之前必须是新的」的时候可以照这个思路做。

### 9.2 投诉流程

投诉相关的明文集中在 `app/common/BaseComplain.php`（接口 `ComplainInterface`）：

| 方法 | 说明 |
|---|---|
| `refreshNewInfo($thirdid, $type = null)` | 刷新单条投诉（基类 `return false`，真实现在各通道子类里） |
| `getNegotiationHistory($thirdid)` | 取协商记录 |
| `replySubmit($thirdid, $content, $images = [])` | 回复投诉人 |
| `feedbackSubmit($thirdid, $code, $content, $images = [])` | 反馈处理结果（自动回复走的就是它，目前只有支付宝通道实现） |
| `refundProgressSubmit(...)` / `complete($thirdid)` | 退款审批结果 / 投诉处理完成（微信） |
| `supplementSubmit(...)` / `getImage($media_id)` | 补充凭证 / 拉投诉图片 |
| `autoHandle($trade_no, $status, $complaint_times = 1)` | protected static，投诉自动处理：冻结/解冻订单、自动拉黑、自动退款 |
| `autoReply($thirdid, $status, $complaint_content = null)` | protected，自动回复，**钩子 `complain_auto_reply` 就在这儿触发** |
| `sendEventMessage($id)` / `sendmsg($msgtype, $thirdid)` | 给商户发事件消息 / 多渠道通知 |

插件能碰到的两个点：

1. **改自动回复的内容** —— 钩子 `complain_auto_reply`，返回非空字符串即生效（见 5.1）；
2. **读投诉单** —— `pre_complain` 表，字段 `thirdid`（渠道单号）、`trade_no`、`title`、`content`、
   `type`、`status`、`money`、`times`、`uid`、`channel`、`addtime`。

投诉单的同步由系统计划任务 `complain` 推进，配置存在 `pre_complaintask`。

### 9.3 每日任务

系统每天跑一次维护任务 `order`（`app/cron/Order.php`），依次做这些事：

1. 删除 3 天前的未支付订单（`status = 0`）；
2. 删除 1 天前的注册验证码；
3. **删除已过期的黑名单**（`endtime < NOW()`）；
4. 清理微信客服支付日志；
5. 重置所有通道的每日限额状态（`channel.daystatus = 0`）；
6. 邀请返现（`invite_mode = 1` 时，按昨日订单给上级返钱，走 `changeUserMoney`）；
7. 到期商户用户组重置 + 发通知；
8. **触发 `cron_daily` 钩子**；
9. 写 `order_time` 打点。

想在每天固定时间做事，两条路：

- 挂在 `cron_daily` 钩子上（跟着系统维护任务走，一天一次，时机就是上面第 8 步）；
- 自己注册计划任务（见 5.7），可以精确到秒级频率，也可以指定几点跑。

要「每天只做一次」的活儿，`cron_daily` 已经天然满足；要「每小时」或「每分钟」，用计划任务。

### 9.4 订单

订单表 `pre_order`，主键是 19 位的 `trade_no`，生成规则在明文里能看到：

```php
$trade_no = date("YmdHis") . rand(11111, 99999);    // 14 位时间 + 5 位随机
```

下单的两处明文入口（`app/controller/qrpay/Index.php:279`、`app/controller/applet/Cashier.php:242`）都长这样：

```php
Db::name('order')->insert([
    'trade_no' => $trade_no, 'out_trade_no' => $trade_no, 'uid' => $uid, 'tid' => 3,
    'type' => $typeid, 'channel' => $channelid, 'subchannel' => $subchannelid,
    'addtime' => date('Y-m-d H:i:s'), 'date' => date('Y-m-d'), 'name' => '在线收款',
    'money' => $money, 'realmoney' => $realmoney, 'getmoney' => $getmoney,
    'notify_url' => $return_url, 'return_url' => $return_url,
    'param' => $param ?: null, 'domain' => $domain, 'ip' => $clientip,
    'province' => \app\logic\PaymentLogic::getProvinceCode($clientip),
    'buyer' => $buyer, 'status' => 0,
]);
```

状态迁移的唯一明文依据是 `app/lib/Order.php`（`close` 只允许 `status = 0`，`freeze` 要求 `status = 1`，
`refund` 允许 `1|2|3`）。**插件读订单没问题，写订单要小心** —— 状态和金额字段系统自己有状态机，
绕过去很容易写出对不上的数据。

商户通知的重试在 `app/cron/Notify.php`（明文）：`notify` 计数 + `notifytime` 退避（1 / 9 / 40 分钟，
一小时后置 `-1`），连续失败到阈值会把商户 `pre_user.pay` 置 0 并写一条 `pre_risk`。想做「订单失败重试提醒」
这类功能，可以读这两张表。

### 9.5 消息通知

`app\lib\MsgNotice`（明文，24KB）是系统统一的通知出口。

| 方法 | 说明 |
|---|---|
| `sendAsync($scene, $uid, $param)` | 异步发（先试异步任务，失败转同步）——**推荐** |
| `send($scene, $uid, $param)` | 同步发 |
| `sendAdminMessage($title, $content)` | 发给管理员（走 `msgconfig_risk` 配置的渠道） |
| `send_voice($devid, $type, $money)` | 云喇叭语音播报 |
| `send_wechat_tplmsg($scene, $openid, $param)` | 微信模板消息 |
| `robot_webhook($url, $title, $content, $admin = false)` | 群机器人（钉钉 / 企业微信 / 飞书） |

- 渠道：微信模板消息、邮件、短信、群机器人、Telegram、QQ 机器人，外加云喇叭和打印机；
- 开关：`$uid == 0` 走管理员全局配置（`pre_config` 里的 `msgconfig_*`），`$uid > 0` 走商户自己的
  `pre_user.msgconfig`（JSON）；
- `$scene` 取值：`regaudit`、`apply`、`domain`、`order`、`settle`、`login`、`complain`、`mchrisk`、
  `balance`、`group`、`domain_audit`、`channellimit`。

`$param` 每个 scene 要哪些键没有集中文档，**照抄系统自己的调用点最稳**，比如：

```php
// 用户组到期（app/cron/Order.php:85）
MsgNotice::send('group', $user['uid'], [
    'uid' => $user['uid'], 'group' => $group['name'], 'endtime' => $user['endtime'],
]);

// 投诉（app/common/BaseComplain.php:210）
MsgNotice::sendAsync('complain', $row['uid'], [
    'trade_no' => $row['trade_no'], 'title' => $row['title'], 'content' => $row['content'],
    'type' => $msgtype, 'name' => $row['ordername'], 'money' => $row['money'], 'time' => $row['addtime'],
]);
```

只想给站长发个提醒，用最简单的一层：

```php
\app\lib\MsgNotice::sendAdminMessage('订单统计', '昨天成交 123 笔 / 4567.00 元');
```

### 9.6 结算、代付、分账、进件

这四块都是「通道类插件」的地盘，目录在 `plugins/` 下各自并列的文件夹里，接口定义在 `app/common/`。
如果你要写的是这类插件，直接对着接口实现：

| 类别 | 目录 | 基类 / 接口 | 关键方法 |
|---|---|---|---|
| 支付通道 | `plugins/payment/` | `BasePayment` / `PaymentInterface` | `submit(PaymentContext $ctx): array`，回落用 `processNotify()` / `processReturn()`，代付回调用 `processTransfer()`，分账回调用 `processProfitSharing()` |
| 代付通道 | `plugins/transfer/` | 同支付 | —— |
| 分账 | `plugins/profitsharing/` | `BaseProfitSharing` / `ProfitSharingInterface` | `submit` / `query` / `unfreeze` / `return` / `addReceiver` / `deleteReceiver` |
| 短信 | `plugins/sms/` | `BaseSms` / `SmsInterface` | `send(string $phone, string $tpl_code, array $tpl_param): bool` |
| 邮件 | `plugins/mail/` | `BaseMail` / `MailInterface` | `send(string $to, string $subject, string $body): bool` |
| 进件 | `plugins/applyments/` | `BaseApplyment` / `ApplymentInterface` | `getFormData` / `create` / `query` / `uploadImage` / `uploadFile` / `getBankList` / `getBankBranchList` / `setPayStatus` / `getOperation` |

数据表：`pre_settle` / `pre_batch`（结算）、`pre_transfer`（代付）、`pre_psorder` / `pre_psreceiver`（分账）、
`pre_applychannel` / `pre_applymerchant` / `pre_applytrade` / `pre_applyreport`（进件）。

功能扩展插件（本文这一类）想「蹭」这些流程，可用的手段是读它们的表加上用钩子；通道类插件的代码是人家
自己的目录，改了以后升级会打架。

---

## 十、从零写一个插件

做一个「订单统计」插件 `orderstat`：后台加一个页面看昨天的成交，每天自动归档一次，配置项两个。
用到的知识：`info.json`、主类、路由、菜单、后台 JS、SQL、计划任务、插件配置。

### 10.1 目录

```
plugins/addons/orderstat/
├── OrderstatPlugin.php
├── info.json
├── install.sql
├── update.sql
├── uninstall.sql
├── controller/
│   ├── Admin.php
│   └── Asset.php
├── cron/
│   └── OrderstatDaily.php
├── lib/
│   └── Stat.php
└── static/
    └── admin.js
```

### 10.2 info.json

```json
{
    "name": "orderstat",
    "title": "订单统计",
    "author": "披萨插件",
    "link": "https://yzf.aiapizz.com",
    "version": "1.0",
    "icon": "",
    "desc": "把每天的交易汇总成一张表，在后台看趋势。",
    "require_version": "2067",
    "inputs": {
        "keep_days": {
            "name": "统计保留多少天",
            "type": "input",
            "required": false,
            "note": "留空表示一直保留",
            "value": "365"
        },
        "show_mode": {
            "name": "后台页面展示方式",
            "type": "select",
            "required": false,
            "options": ["表格", "只显示总数"],
            "value": "0",
            "note": "选「只显示总数」时页面就一行数字"
        }
    },
    "note": "安装后到「插件管理 → 订单统计 → 配置」改这两项。"
}
```

### 10.3 install.sql / update.sql / uninstall.sql

```sql
-- install.sql
CREATE TABLE IF NOT EXISTS `pre_orderstat_daily` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `orders` int(11) unsigned NOT NULL DEFAULT 0,
  `money` decimal(10,2) NOT NULL DEFAULT 0.00,
  `addtime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `pre_plugin` (`id`,`name`,`type`,`title`,`desc`,`version`,`author`,`link`,`icon`,`status`)
VALUES ('addon_orderstat','orderstat','addons','订单统计','把每天的交易汇总成一张表','1.0','披萨插件','https://yzf.aiapizz.com','',0);

INSERT IGNORE INTO `pre_crontab` (`task`,`name`,`description`,`plugin`,`frequency`,`interval`,`enabled`)
VALUES ('orderstat_daily','订单统计归档','把昨天的交易汇总进统计表','orderstat','day',1,1);
```

```sql
-- update.sql（每次点「更新」整跑一遍，所以都要能重复执行）
CREATE TABLE IF NOT EXISTS `pre_orderstat_daily` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `orders` int(11) unsigned NOT NULL DEFAULT 0,
  `money` decimal(10,2) NOT NULL DEFAULT 0.00,
  `addtime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

```sql
-- uninstall.sql（只删自己建的）
DROP TABLE IF EXISTS `pre_orderstat_daily`;
DELETE FROM `pre_plugin` WHERE `name` = 'orderstat' AND `type` = 'addons';
DELETE FROM `pre_crontab` WHERE `task` = 'orderstat_daily' AND `plugin` = 'orderstat';
```

### 10.4 主类

```php
<?php

namespace plugins\addons\orderstat;

use app\common\BaseAddon;
use think\facade\Route;

class OrderstatPlugin extends BaseAddon
{
    public static function getName(): string
    {
        return 'orderstat';
    }

    public function registerAdminRoutes(): void
    {
        Route::group('addon/orderstat', function () {
            Route::get('info', '\plugins\addons\orderstat\controller\Admin@info');
            Route::post('archive', '\plugins\addons\orderstat\controller\Admin@archive');
        });
    }

    public function registerCommonRoutes(): void
    {
        Route::get('addon/orderstat/asset', '\plugins\addons\orderstat\controller\Asset@js');
    }

    public function getAdminMenus(): array
    {
        $script = '/addon/orderstat/asset';

        return [
            [
                'parent'    => 'TradeManage',
                'sort'      => 20,
                'scriptUrl' => $script,
                'menu'      => [
                    'path'      => 'orderstat',
                    'name'      => 'OrderStat',
                    'component' => '/plugin/index',
                    'meta'      => [
                        'title'     => '订单统计',
                        'keepAlive' => false,
                        'roles'     => ['R_SUPER', 'R_ADMIN'],
                        'scriptUrl' => $script,
                    ],
                ],
            ],
        ];
    }
}
```

### 10.5 逻辑

```php
<?php

namespace plugins\addons\orderstat\lib;

use think\facade\Db;

class Stat
{
    /** 插件配置：用户填的 + 默认值 */
    public static function config(): array
    {
        $saved = plugin_config_get('addons', 'orderstat');
        $defaults = ['keep_days' => '365', 'show_mode' => '0'];

        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    /** 把某一天的成交汇总进统计表（可重复执行） */
    public static function archive(string $date): array
    {
        $orders = (int) Db::name('order')
            ->where('date', $date)
            ->where('status', '>', 0)
            ->count();

        $money = (string) Db::name('order')
            ->where('date', $date)
            ->where('status', '>', 0)
            ->sum('realmoney');

        $row = [
            'date'    => $date,
            'orders'  => $orders,
            'money'   => $money ?: '0.00',
            'addtime' => date('Y-m-d H:i:s'),
        ];

        $exists = Db::name('orderstat_daily')->where('date', $date)->find();
        if ($exists) {
            Db::name('orderstat_daily')->where('date', $date)->update($row);
        } else {
            Db::name('orderstat_daily')->insert($row);
        }

        return $row;
    }

    /** 最近 N 天的记录 */
    public static function recent(int $limit = 30): array
    {
        return Db::name('orderstat_daily')
            ->order('date', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }
}
```

### 10.6 定时任务

```php
<?php

namespace plugins\addons\orderstat\cron;

use app\common\AbstractCronTask;
use plugins\addons\orderstat\lib\Stat;

class OrderstatDaily extends AbstractCronTask
{
    public static function identifier(): string
    {
        return 'orderstat_daily';
    }

    public static function name(): string
    {
        return '订单统计归档';
    }

    public function execute(array $params = []): string
    {
        $lastday = date('Y-m-d', strtotime('-1 day'));
        $row = Stat::archive($lastday);

        return '归档 ' . $lastday . '：' . $row['orders'] . ' 笔 / ' . $row['money'] . ' 元';
    }
}
```

### 10.7 控制器

```php
<?php

namespace plugins\addons\orderstat\controller;

use plugins\addons\orderstat\lib\Stat;

class Admin
{
    public function info()
    {
        try {
            $cfg = Stat::config();

            return json_response(200, 'ok', [
                'rows'   => Stat::recent(30),
                'config' => $cfg,
            ]);
        } catch (\Throwable $e) {
            return json_response(500, '读取失败：' . $e->getMessage());
        }
    }

    public function archive()
    {
        try {
            $lastday = date('Y-m-d', strtotime('-1 day'));
            $row = Stat::archive($lastday);

            return json_response(200, 'ok', ['row' => $row, 'rows' => Stat::recent(30)]);
        } catch (\Throwable $e) {
            return json_response(500, '归档失败：' . $e->getMessage());
        }
    }
}
```

后台控制器**必须返回 JSON**（`json_response`），前端按 `{code, msg, data}` 解析。
控制器方法里的异常自己兜住再返回，别让框架的错误页把 JSON 顶掉。

### 10.8 脚本路由

```php
<?php

namespace plugins\addons\orderstat\controller;

class Asset
{
    public function js()
    {
        $file = dirname(__DIR__) . '/static/admin.js';

        if (!is_file($file)) {
            $js = 'console.error("[订单统计] 插件脚本文件丢了：plugins/addons/orderstat/static/admin.js");';
        } else {
            $js = (string)file_get_contents($file);
        }

        return response($js, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
```

### 10.9 后台脚本

```js
(function () {
  'use strict';

  function assetPath() {
    var src = '';
    try { src = (document.currentScript && document.currentScript.src) || ''; } catch (e) { src = ''; }
    if (!src) {
      try {
        var list = document.getElementsByTagName('script');
        for (var i = list.length - 1; i >= 0; i--) {
          if (list[i].src && list[i].src.indexOf('addon/orderstat') >= 0) { src = list[i].src; break; }
        }
      } catch (e2) { src = ''; }
    }
    if (!src) return '';
    try { return new URL(src, location.href).pathname || ''; } catch (e3) { return ''; }
  }

  var ASSET = assetPath();
  var ROOT  = '';
  (function () {
    var at = ASSET.indexOf('/addon/orderstat/');
    if (at >= 0) {
      ROOT = ASSET.slice(0, at);
    } else {
      ROOT = '/' + ((location.pathname.split('/')[1] || '').replace(/\/+$/, ''));
      if (ROOT === '/admin') ROOT = '';
    }
  })();

  var BASES = [ROOT + '/admin/addon/orderstat/', ROOT + '/addon/orderstat/'];
  var BASE  = 0;

  var CSS = [
    '.os-page{--os-bd:var(--el-border-color-lighter,#ebeef5);',
    '--os-dim:var(--el-text-color-secondary,#909399);',
    '--os-ink:var(--el-text-color-primary,#303133);',
    '--os-fill:var(--el-fill-color-light,#f5f7fa);',
    'color:var(--os-ink);font-size:14px;line-height:1.6}',
    '.os-page .os-card{background:var(--el-bg-color,#fff);border:1px solid var(--os-bd);',
    'border-radius:10px;padding:16px 18px;margin-bottom:14px}',
    '.os-page .os-card h3{margin:0 0 14px;font-size:14px;font-weight:600}',
    '.os-page table{width:100%;border-collapse:collapse}',
    '.os-page th,.os-page td{padding:8px 10px;border-bottom:1px solid var(--os-bd);text-align:left}',
    '.os-page th{background:var(--os-fill);font-weight:600;font-size:13px}',
    '.os-page .os-btn{display:inline-block;padding:7px 16px;border:0;border-radius:6px;',
    'background:var(--el-color-primary,#409eff);color:#fff;cursor:pointer;font-size:13px}',
    '.os-page .os-dim{color:var(--os-dim)}',
  ].join('');

  function ensureCss() {
    if (document.getElementById('os-page-css')) return;
    var s = document.createElement('style');
    s.id = 'os-page-css';
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.appendChild(document.createTextNode(String(text)));
    return e;
  }

  function request(url, payload) {
    var opt = {
      method: payload ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: payload
        ? { 'Content-Type': 'application/json', 'Accept': 'application/json' }
        : { 'Accept': 'application/json' },
    };
    if (payload) opt.body = JSON.stringify(payload);

    return fetch(url, opt).then(function (r) {
      if (!r.ok) { var e = new Error('HTTP ' + r.status); e.http = r.status; throw e; }
      return r.json();
    }).then(function (j) {
      if (j && j.code !== 200) throw new Error((j && j.msg) || '接口返回失败');
      return (j && j.data) || {};
    });
  }

  function api(action, payload) {
    return request(BASES[BASE] + action, payload).catch(function (e) {
      if (BASE + 1 < BASES.length && (e.http === 404 || e.http === 403)) {
        BASE++;
        return request(BASES[BASE] + action, payload);
      }
      throw e;
    });
  }

  var container = null;
  var state = { loading: true, err: '', rows: [], config: {}, busy: false };

  function render() {
    if (!container) return;
    while (container.firstChild) container.removeChild(container.firstChild);

    var card = el('div', 'os-card');
    var head = el('h3', null, '订单统计');
    card.appendChild(head);

    if (state.loading) {
      card.appendChild(el('div', 'os-dim', '正在读取…'));
      container.appendChild(card);
      return;
    }

    if (state.err) {
      card.appendChild(el('div', null, '读取失败：' + state.err));
      var retry = el('button', 'os-btn', '重试');
      retry.style.marginTop = '12px';
      retry.onclick = function () { load(); };
      card.appendChild(retry);
      container.appendChild(card);
      return;
    }

    if (state.config.show_mode === '1') {
      var total = 0, money = '0.00', i;
      for (i = 0; i < state.rows.length; i++) {
        total += parseInt(state.rows[i].orders, 10) || 0;
        money = (parseFloat(money) + parseFloat(state.rows[i].money || 0)).toFixed(2);
      }
      card.appendChild(el('div', null, '近 ' + state.rows.length + ' 天：' + total + ' 笔 / ' + money + ' 元'));
    } else {
      var table = el('table');
      var thead = el('thead');
      var tr = el('tr');
      tr.appendChild(el('th', null, '日期'));
      tr.appendChild(el('th', null, '笔数'));
      tr.appendChild(el('th', null, '金额'));
      thead.appendChild(tr);
      table.appendChild(thead);

      var tbody = el('tbody');
      for (var k = 0; k < state.rows.length; k++) {
        var row = el('tr');
        row.appendChild(el('td', null, state.rows[k].date));
        row.appendChild(el('td', null, state.rows[k].orders));
        row.appendChild(el('td', null, state.rows[k].money));
        tbody.appendChild(row);
      }
      if (!state.rows.length) {
        var empty = el('tr');
        var td = el('td', 'os-dim', '还没有数据，等每天自动归档，或者点下面的按钮手动跑一次');
        td.colSpan = 3;
        empty.appendChild(td);
        tbody.appendChild(empty);
      }
      table.appendChild(tbody);
      card.appendChild(table);
    }

    var btn = el('button', 'os-btn', state.busy ? '正在归档…' : '手动归档昨天');
    btn.style.marginTop = '14px';
    btn.disabled = state.busy;
    btn.onclick = onArchive;
    card.appendChild(btn);

    container.appendChild(card);
  }

  function load() {
    state.loading = true;
    state.err = '';
    render();

    api('info').then(function (d) {
      state.loading = false;
      state.rows = d.rows || [];
      state.config = d.config || {};
      render();
    }).catch(function (e) {
      state.loading = false;
      state.err = String((e && e.message) || e);
      render();
    });
  }

  function onArchive() {
    if (state.busy) return;
    state.busy = true;
    render();

    api('archive', {}).then(function (d) {
      state.busy = false;
      state.rows = d.rows || state.rows;
      render();
    }).catch(function (e) {
      state.busy = false;
      state.err = String((e && e.message) || e);
      render();
    });
  }

  function attach(host) {
    ensureCss();
    if (container && container.parentNode) container.parentNode.removeChild(container);

    container = el('div', 'art-page-view os-page');
    host.appendChild(container);
    load();
  }

  function unmount() {
    if (container && container.parentNode) container.parentNode.removeChild(container);
    container = null;
  }

  function mount(vm) {
    var tries = 0;

    (function go() {
      var el0 = vm && vm.$el;
      var host = null;

      try { host = (el0 && el0.parentNode) ? el0.parentNode : null; } catch (e) { host = null; }

      if (!host && tries++ < 10) { setTimeout(go, 40); return; }
      if (!host) { host = document.body; }

      try { attach(host); } catch (e) { }
    })();
  }

  window.__ART_PLUGIN_COMPONENT__ = {
    name: 'OrderStatPage',
    inheritAttrs: false,
    render: function () { return null; },
    mounted: function () { mount(this); },
    unmounted: function () { unmount(); },
  };
})();
```

### 10.10 装上试

1. 把 `orderstat/` 整个传到站点根目录的 `plugins/addons/` 下；
2. 后台「插件管理」→「刷新插件列表」→ 找到「订单统计」→「安装」→「启用」；
3. 后台侧边栏「交易管理」下面会多出「订单统计」，点进去；
4. 「配置」里改 `keep_days` / `show_mode`；
5. 到「计划任务」页看 `orderstat_daily` 在不在、下一轮什么时候跑；
   想马上验证，命令行 `php think cron orderstat_daily`，或者点页面上的「手动归档昨天」。

---

## 十一、排查表

按「看到什么」查。

| 症状 | 多半是什么 | 怎么办 |
|---|---|---|
| 后台「刷新插件列表」后看不到插件 | 目录里没有 `info.json`，或者目录名和 `name` 对不上 | 确认 `plugins/addons/<名字>/info.json` 存在且 `name` 与目录名一致 |
| 点「安装」报「该扩展最低系统版本要求为…」 | `info.json` 的 `require_version` 高于 `config/app.php` 的 `version` | 调低 `require_version` 或升级系统 |
| 装完启用，但钩子/路由都不生效 | `pre_plugin` 里没有这一行（`install.sql` 的 `INSERT IGNORE` 没跑到） | 手动查 `SELECT * FROM pre_plugin WHERE name = '插件名'`；没有就补一条，或重装 |
| 后台菜单不出现 | `parent` 填了系统不认识的 `name`；或 `menu.name` 和已有菜单重名 | `parent` 用 `Dashboard`/`TradeManage`/… 这些真实存在的；`name` 换个唯一的 |
| 后台页面一片空白、控制台没报错 | 脚本末尾没挂 `window.__ART_PLUGIN_COMPONENT__`，或者 `render()` 返回了内容而 `mounted` 没做事 | 按 5.5 的三步契约检查；`render` 固定 `return null` |
| 页面报「插件脚本文件丢了」 | `scriptUrl` 指的路由没返回 JS，或者 `static/` 文件没传上去 | 浏览器直接打开 `/addon/<插件名>/asset` 看返回什么 |
| 后台接口 401 / 404 | `fetch` 少了 `credentials: 'same-origin'`；或者站主改过后台路径 | 带上 `credentials`；`BASES` 的第二条兜底（见 5.5） |
| 后台接口返回的是 HTML（错误页）而不是 JSON | 控制器里抛异常了 | 控制器里 `try/catch` 兜住，异常也返回 `json_response(...)` |
| 表没建上，其它都正常 | SQL 执行是**静默的**，有一条语句写错就跳过 | 逐条单独执行 `install.sql` 的语句定位；确认表名写的是 `pre_` |
| 点「更新」没反应 | 没有 `update.sql`，或者里面没有可执行的语句 | 正常现象；纯文件改动不用点升级 |
| 计划任务不跑 | `pre_crontab` 那行 `enabled = 0`；执行方式是 `swoole` 但没起守护进程；或 `plugin` 列没填插件名 | 后台「计划任务」页逐项核对；`swoole` 方式需要 `php think cron` 常驻 |
| `php think cron <task>` 报「计划任务不存在」 | `task` 和 `pre_crontab.task` 不一致 | 两边写成同一个字符串 |
| 钩子没被触发 | 钩子名写错；或者该钩子有条件（如 `certificate_scan_type` 只在 `cert_open = 6` 时执行） | 对照 5.1 的四张表核对钩子名与触发条件 |
| 投诉自动回复没变成自己的文案 | 回调返回了空字符串或非字符串 | 要接管就返回**非空字符串**，不接管就返回 `''` |
| 每天维护任务第二天重跑了 | `cron_daily` 钩子抛了异常，`order_time` 没打上点 | 回调里 `try/catch` 全兜住 |
| 黑名单写进去没拦住 | `type` 写反了（0 是账号/手机号、1 是 IP）；或者 `content` 超 50 字符被截断 | 核对 `type` 与 `content` 长度 |
| 往 `pre_blacklist` 插不进去 | 唯一键 `(content, type)` 撞了 | 先 `find()` 再插，或者用 `INSERT IGNORE` |
| 页面样式跟系统对不上、暗色主题下发白 | 颜色写死了，没用 `--el-*` 变量 | 颜色全走 CSS 变量（见 5.5） |
| 切走再切回来页面重复、越叠越多 | 单页应用复用脚本，`mounted` 被调了多次，没清旧节点 | `attach()` 里先把 `container` 从 DOM 上摘掉 |

**通用排查手段**：`exception_log($e, '标记')` 把异常写进日志；后台「系统 → 系统日志」；
浏览器 F12 的 Network 面板看接口真实返回；`php think cron <task>` 单独跑一次看输出。

---

## 附：一页速查

**加载条件**：目录有 `info.json` + `pre_plugin` 有行 + `addon_<名>_installed = 1` + `addon_<名> = 1`。

**类名**：`plugins\addons\<目录名>\<目录名首字母大写>Plugin`。

**四个钩子**：`cron_daily`（每日维护，返回忽略）、`complain_auto_reply`（返回非空字符串当回复内容）、
`check_corp_cert`（返回带 `code` 的数组）、`certificate_scan_type`（返回 `alipay`/`wechat`/`phone`）。

**三个路由注册点**：`route/app.php:82`（公共）、`route/admin.php:452`（后台，路径前缀可配）、
`route/user.php:261`（商户端）。

**后台页面三件套**：菜单 `component` 固定 `'/plugin/index'` + `scriptUrl` 指向返回 JS 的公共路由 +
脚本末尾挂 `window.__ART_PLUGIN_COMPONENT__`。

**SQL**：表名写 `pre_`；`CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE` / `DROP TABLE IF EXISTS`；
每条语句独立且静默失败。

**两个高频函数**：`json_response($code, $msg, $data)`、`plugin_config_get('addons', '插件名')`。

**想拦付款**：往 `pre_blacklist` 写（`type` 0 账号 / 1 IP，`content ≤ 50`，`endtime` 为 `null` 是永久）。
