# 插件开发指南

写给想给**易支付 PRO** 写扩展插件的人。全篇只讲系统**本来就留好**的那些口子 —— 本仓库的插件
就是这么写的，一个系统文件都没改过。`plugins/addons/blacklist/`（公共黑名单）是最完整的一个例子，
讲到哪儿都可以对着它看。

读这份文档你需要：会 PHP，最好写过 ThinkPHP（框架是 ThinkPHP 6 / 8 那一套的写法）。

---

## 零、先记住三条

**一、不修改系统的任何文件。**

改官方文件有三个后果：站长一对比就发现"这家动过我的代码"，说不清楚；系统一升级这几个文件被覆盖，
插件**静默失效**（不报错，就是不干活了），这类问题最难查；出任何事都算在插件头上。
支付相关的那几个文件还是加密的（swoole_loader），想改也改不了。

找不到口子的时候（比如"付款前拦一下"），换思路：**把插件变成数据，让系统自己的代码去读**。
系统本来就在查 `pre_blacklist` 那张表，那就把要拦的人写进那张表 —— 一行系统代码都没改，
拦人的时机还是系统自己挑的（公共黑名单就是这么干的，见本仓库插件的 README 第五节）。

**二、不抛异常。**

你的 `boot()`、钩子回调、路由中间件全都跑在站长的正常业务链路上：

- 钩子里抛异常 → 打断系统的投诉处理、每日定时任务；
- 路由中间件里抛异常 → **整站 500**，包括付款页，站长会以为是系统坏了。

所以一律 `try { … } catch (\Throwable $e) { }`，宁可少干活，不能把站长的站搞挂。
连中间件的**参数和返回值都别写死类型** —— 万一哪天系统的请求对象换了类，
标了类型就会在"放行那一刻"抛 `TypeError`，把整站连累进去。

**三、一切都要能重复执行。**

安装、卸载、升级会被点很多次（迁移、重装、手滑）。SQL 一律 `CREATE TABLE IF NOT EXISTS`、
`INSERT IGNORE`；卸载之后再装一遍要跟第一次一样干净。

---

## 一、一个插件长什么样

位置固定在站点根目录的 `plugins/addons/<插件名>/`，**目录名就是插件名**，
也是主类的命名空间 `plugins\addons\<插件名>`（系统用 PSR-4 加载：`plugins\` → `plugins/`）。

最小的能跑的插件只要两个文件：`info.json` + `<插件名>Plugin.php`。
完整的样子（本仓库的公共黑名单）：

```
plugins/addons/blacklist/
├── BlacklistPlugin.php      插件主类（继承 app\common\BaseAddon）
├── info.json                插件信息 + 后台配置表单的定义
├── install.sql              安装时建表、登记计划任务
├── update.sql               升级时补东西（能反复跑）
├── uninstall.sql            卸载时收拾干净
├── README.md                给站长看的那一份文档
├── controller/              控制器：后台接口、公共路由
├── cron/                    给系统「计划任务」用的任务类
├── lib/                     业务逻辑（按自己习惯拆）
├── middleware/              路由中间件（要蹭每个请求时才用）
└── static/                  后台页面的脚本
```

主类的**文件名和类名**是系统按规矩推出来的，写错了插件就加载不到：

```php
// 插件名（目录名）blacklist → 类名 ucfirst('blacklist') . 'Plugin' = BlacklistPlugin
// 文件必须是 BlacklistPlugin.php
namespace plugins\addons\blacklist;

use app\common\BaseAddon;

class BlacklistPlugin extends BaseAddon
{
    public static function getName(): string
    {
        return 'blacklist';            // 必须和目录名一模一样
    }
}
```

> 系统的规则是 `'plugins\addons\' . $name . '\' . ucfirst($name) . 'Plugin'`，
> 只把**第一个字母**变大写。所以插件名别用下划线这类花样（`my_shop` 会要求类名是 `My_shopPlugin`，
> 看着别扭又容易错），建议就用小写英文单词。

---

## 二、info.json：插件信息 + 后台配置表单

```json
{
    "name": "blacklist",
    "title": "公共黑名单",
    "author": "披萨插件",
    "link": "",
    "version": "1.4",
    "icon": "",
    "desc": "一两句话，后台插件列表里显示",
    "inputs": { "……字段定义，见下……" },
    "note": "后台配置页顶部那段说明，可以写 <br> 换行"
}
```

| 字段 | 说明 |
|---|---|
| `name` | 插件名，**必须等于目录名** |
| `title` | 中文名，后台列表和菜单上显示 |
| `version` | 版本号。**改了行为就要改它** —— 站长就是靠它知道该不该点「更新」 |
| `author` / `link` | 作者、主页，可留空 |
| `icon` | 图标，可留空 |
| `desc` | 一句话简介 |
| `note` | 配置页顶部的说明文字（可以放 `<br>`） |
| `inputs` | 后台配置表单的字段定义，见下 |
| `require_version` | 可选。系统版本低于这个数就不给装，会提示「请先更新系统」 |

（系统的版本号在 `config('app.version')`，按整数比。用不上就不写这个字段。）

### `inputs`：后台那个配置表单

每一项就是「插件管理 → 配置」里的一个字段，**数组顺序就是表单里的顺序**：

| 键 | 说明 |
|---|---|
| `name` | 字段的显示名（中文，写给站长看的） |
| `type` | `input`（单行文本）或 `select`（下拉） |
| `required` | 必填 |
| `note` | 字段下面的说明 —— **这句话很值钱**：告诉站长"填到哪一层""默认几秒""建议开" |
| `options` | `select` 的选项数组（字符串数组） |
| `value` | 默认值。`select` 的 `value` 是**选项的下标**，从 `"0"` 开始 |

真实的两个例子（来自公共黑名单）：

```json
"api_url": {
    "name": "平台接口地址",
    "type": "input",
    "required": true,
    "note": "填到 api 这一层，例如 https://bl.example.com/api"
},
"pull_type": {
    "name": "下发哪些类型",
    "type": "select",
    "required": true,
    "options": ["账号和 IP 都要", "只要账号", "只要 IP"],
    "value": "0",
    "note": "选「只要账号」时，IP 条目仍然记在本地，只是不写进系统黑名单表"
}
```

---

## 三、插件主类：系统在什么时候调用你

基类是 `app\common\BaseAddon`（**明文，可以直接读**）。按需覆盖下面这些方法，不需要的不写：

| 方法 | 什么时候被调用 | 你在这里干什么 |
|---|---|---|
| `getName()` | 所有时候 | **必须实现**，返回插件名（等于目录名） |
| `boot()` | **每个请求**（系统在 LoadConfig 中间件里启动已启用的插件） | 只**登记**：挂钩子、注册中间件。**千万别在这里查库、发 HTTP** —— 它是全站每个请求的必经之路，多花 50ms 就是全站慢 50ms |
| `install()` / `update()` / `uninstall()` | 后台点安装 / 更新 / 卸载 | 默认实现就是跑同名的 `.sql`，**一般不用覆盖**。要写额外的 PHP（数据迁移、登记计划任务）再覆盖，记得先 `parent::install()` |
| `enable()` / `disable()` | 后台点启用 / 禁用 | 一般不用写 |
| `registerAdminRoutes()` | 后台路由注册时 | 插件自己的后台接口 |
| `registerCommonRoutes()` | 公共路由注册时 | 不需要登录的接口、计划任务地址、路由中间件 |
| `registerUserRoutes()` | 用户端路由注册时 | 面向商户/用户端的接口（很少用） |
| `getAdminMenus()` | 后台菜单渲染时 | 把插件的菜单挂进后台 |
| `getUserMenus()` | 用户端菜单 | 同上 |

### 安装 / 启用 / 卸载，系统背着你做了什么

| 你点的按钮 | 系统干了什么 |
|---|---|
| 安装 | 跑 `install.sql` → 在系统配置里记 `addon_<名字>_installed = 1` |
| 启用 / 禁用 | 在系统配置里记 `addon_<名字> = 1 / 0`。**只有"已安装 + 已启用"的插件会被 boot** |
| 更新 | 跑 `update.sql` |
| 卸载 | 先 `disable()` → 跑 `uninstall.sql` → 把插件配置和上面那两个开关一起删掉（**这个不用你清**） |

还有一件事要知道：**系统是从数据库的插件表里读"有哪些插件"的**（后台「刷新插件列表」会扫盘登记）。
所以新插件的 `install.sql` 里要自己登记一行，否则装完也不会被 boot：

```sql
INSERT IGNORE INTO `pre_plugin` (`id`, `name`, `type`, `title`, `desc`, `version`, `author`, `link`, `icon`, `status`)
VALUES ('addon_blacklist', 'blacklist', 'addons', '公共黑名单', '一句话简介', '1.4', '披萨插件', '', '', 0);
```

### `.sql` 是怎么跑的（这段一定要看）

系统读整个文件 → 把 `pre_` 全部替换成**站点自己的表前缀** → 按分号拆开 → **逐条执行，
每条单独 try/catch，出错静默跳过**。

所以：

- 表名一律写 `pre_`，不要写真实前缀；**只建自己的表**；
- 建表必须 `CREATE TABLE IF NOT EXISTS`，插数据必须 `INSERT IGNORE`（否则第二次安装就报错）；
- 语句写错了**不会报错、也不会挡住后面的语句** —— 反过来也就是说，装完要自己回后台
  （和数据库里）确认一下表和任务真的都建出来了。

---

## 四、系统给插件留的扩展点（一共就这些）

### 一、钩子（hook）

系统主动留的口子，一共四个：

| 钩子 | 系统在哪儿触发 | 传给你的参数 | 返回值怎么用 |
|---|---|---|---|
| `complain_auto_reply` | `app/common/BaseComplain.php` 的自动回复 | `thirdid`（第三方投诉编号）、`status`、`complaint_content` | 返回**字符串**：非空会**顶掉站长配的自动回复文案**。只想借这个时机干活，就老老实实返回 `''` |
| `cron_daily` | `app/cron/Order.php` 的每日维护任务 | `lastday`（昨天日期） | 返回值**没有被消费**（收集起来就完了），要留痕就自己记日志 |
| `check_corp_cert` | `app/common.php` 的企业三要素校验 | `companyName`、`creditNo`、`legalPerson` | 返回 `['code' => 0 通过 / -1 不一致 / -2 解析失败, 'msg' => '说明']`；**第一个带 `code` 的数组生效**，所以不发表意见就返回 `null` |
| `certificate_scan_type` | `app/common.php` 的 `get_cert_scan_type()` | 无 | 返回 `'alipay'` / `'wechat'` / `'phone'` 之一；第一个合法值生效 |

注册：

```php
public function boot(): void
{
    \app\lib\AddonManager::addHook('complain_auto_reply', static function (array $params): string {
        // 干活，但一定接住异常
        try {
            // …
        } catch (\Throwable $e) {
        }
        return '';        // 不改站长的自动回复文案
    }, 10);
}
```

- 第三个参数是优先级，**数字越小越先跑**（默认 10）；
- 同一个钩子上所有插件的回调都会被调用，返回值收集成数组交给系统去挑（挑法就是上面那列）；
- 回调里**不要抛异常**：系统那边是直接 `call_user_func`，异常会一路冒到站长的业务流程里。

### 二、路由（三组，带的门不一样）

| 方法 | 系统在哪儿调用 | 自带哪些校验 |
|---|---|---|
| `registerCommonRoutes()` | `route/app.php`，**没有任何登录校验** | 无。要鉴权就自己带密钥（看公共黑名单的 `controller/Task.php`：地址上带 `?key=`，密钥存在自己表里，能换） |
| `registerAdminRoutes()` | `route/admin.php` 最底下，整个后台路由组里 | `SessionInit` + `RefererCheck` + `AuthAdmin` 三道门 —— **不用自己判登录** |
| `registerUserRoutes()` | `route/user.php` | 用户端那一套 |

路径建议统一成 `addon/<插件名>/...`，好认、也不会跟系统的路由撞：

```php
public function registerAdminRoutes(): void
{
    Route::group('addon/blacklist', function () {
        Route::get('info', '\plugins\addons\blacklist\controller\Admin@info');
        Route::post('sync', '\plugins\addons\blacklist\controller\Admin@sync');
    });
}
```

控制器里返回 JSON 用系统给的 `json_response($code, $msg, $data)`（`code` 用 200 / 400 / 403 这类）。

### 三、路由中间件 = 唯一能"每个请求都摸一下"的地方

`registerCommonRoutes()` 注册的时机在系统 `pipeline('route')` **之前**，所以那一刻加进路由中间件队列的
中间件，会作用在**所有路由**上，而且跑在系统自己的路由中间件和控制器之前 —— 这是插件能
"不改文件、还在付款校验之前动手"的全部秘密。

```php
public function boot(): void
{
    try {
        $mw = app('middleware');
        if ($mw && method_exists($mw, 'route')) {
            $mw->route(\plugins\addons\demo\middleware\Throttle::class);
        }
    } catch (\Throwable $e) {
        // 加不上就算了，绝不能因为这件事让插件起不来
    }
}
```

```php
class Throttle
{
    // 参数和返回值故意不写类型：万一系统的请求对象换了类，
    // 标了类型会在放行那一刻抛 TypeError，把整站带下水
    public function handle($request, \Closure $next)
    {
        try {
            // 该干的活；务必自己节流（存一个"上次跑的时间"），别每个请求都去打外部接口
        } catch (\Throwable $e) {
            // 静默。宁可少干一件事，也不能让站长打不开页面
        }

        return $next($request);      // 无论如何都要放行
    }
}
```

### 四、后台菜单 + 后台页面

```php
public function getAdminMenus(): array
{
    $script = '/addon/demo/asset';

    return [[
        'parent'    => 'TradeManage',        // 挂到哪个系统菜单下面
        'sort'      => 20,                   // 同级排序
        'scriptUrl' => $script,              // 页面脚本（顶层和 meta 里都写，兼容两种取法）
        'menu'      => [
            'path'      => 'demo-page',      // 路由 path（自己起，别和系统撞）
            'name'      => 'DemoPage',       // 路由 name（自己起）
            'component' => '/plugin/index',  // 固定写这个：系统后台的插件加载器
            'meta'      => [
                'title'     => '演示页',
                'keepAlive' => false,
                'roles'     => ['R_SUPER', 'R_ADMIN'],
                'scriptUrl' => $script,
            ],
        ],
    ]];
}
```

- `parent` 的值是**顶级菜单的 name**，都在 `app/data/menu_admin.json` 里：
  `Dashboard`、`TradeManage`、`ProfitSharingManage`、`TransferManage`、`SettleManage`、
  `MerchantManage`、`PayApiManage`、`ApplymentsManage`、`ComplainManage`、`OtherManage`、
  `AppManage`、`System`；填 `''` 会变成顶级菜单（一般别这么干）。
- `meta.roles` 决定谁能看见：`['R_SUPER', 'R_ADMIN']`。

那个脚本由你自己的路由吐出来，**不要往 `public/static` 里写实体文件**（有的服务器没写权限，
升级还会留旧版本残留）。系统的插件加载器会把它当普通 `<script>` 插进页面，
然后渲染脚本挂在 `window.__ART_PLUGIN_COMPONENT__` 上的组件：

```php
class Asset
{
    public function js()
    {
        $file = dirname(__DIR__) . '/static/admin.js';
        $js = is_file($file) ? (string)file_get_contents($file) : 'console.error("脚本丢了");';

        return response($js, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
```

写那个脚本有两**硬规矩**（违反了页面就是一片白，还不容易查）：

1. **必须是普通脚本**：不能有 `import` / `export`；
2. **执行到最后必须把组件挂上去**，中间任何一步抛错都会白屏：

```js
window.__ART_PLUGIN_COMPONENT__ = {
  name: 'DemoPage',
  inheritAttrs: false,               // 免得路由参数被当属性传进来，控制台一直告警
  render: function () { return null; },
  mounted: function () { mount(this); }
};
```

页面本身建议**自己操作 DOM**：拿到容器、拼 HTML、调系统后台现成的接口（或你自己的接口）填数据。
别去赌 `window.Vue`、`window.ElementPlus` 被加载器挂成了什么样 —— 公共黑名单 1.2 那版就是用 Vue 的
`h()` 去拼组件对象，在真机上页面根本打不开，1.3 才改成自己操作 DOM。

### 五、计划任务

写一个类继承 `app\common\AbstractCronTask`（它实现了 `app\common\CronTaskInterface`）：

```php
class DemoTask extends AbstractCronTask
{
    public static function identifier(): string
    {
        return 'demo_sync';        // 任务名，必须和计划任务表里那行一致
    }

    public static function name(): string
    {
        return '演示同步';          // 后台列表里显示的名字
    }

    public function execute(array $params = []): string
    {
        try {
            @set_time_limit(60);   // 给自己一个上限，别把守护进程拖住
        } catch (\Throwable $e) {
        }

        try {
            // 干活
        } catch (\Throwable $e) {
            return '演示同步出错：' . $e->getMessage();     // 返回一句人话，别抛
        }

        return '演示同步：这一轮没什么可做的';
    }
}
```

`parameterDefinitions()` 有默认实现（返回 `[]`），需要给站长填参数时再覆盖。
`execute()` 的返回值会写进后台「立即执行」的结果和守护进程日志里 —— 所以**返回人话，不要抛异常**。

然后往系统的计划任务表里登记一行（装插件时用 `INSERT IGNORE`，见公共黑名单的 `install.sql`）：

```sql
INSERT IGNORE INTO `pre_crontab`
  (`task`, `name`, `description`, `plugin`, `frequency`, `interval`, `enabled`) VALUES
('demo_sync', '演示同步', '一句话说明这条任务干什么', 'demo', 'second', 60, 1);
```

- `frequency` 取值：`second` / `minute` / `hour` / `day` / `once`（系统自带那十条任务的写法可以在
  `app/sql/install.sql` 里看到）；
- `plugin` 写插件名，后台列表里会显示成「demo / demo_sync」；
- 登记之后它就跟系统自带的任务一样：后台「系统管理 → 计划任务」里看得见、能停能启、
  能点「立即执行」单跑一次；Swoole 模式下由系统的守护进程自动跑，站长什么都不用配。

**两条提醒**：

1. `pre_crontab` 这张表是系统更新到 **update5** 之后才有的 —— 老站可能压根没有。所以
   `INSERT IGNORE` 失败是正常的，别把功能全押在它身上；
2. 稳妥的做法是**同时给一条插件自备的访问地址**（`addon/<插件名>/task?key=…`，
   自带的「访问URL」型计划任务的站长在宝塔里挂 60 秒一次），两条路互不干扰。
   这就是公共黑名单里 `controller/Task.php` 的来源。

---

## 五、读插件配置

后台配置表单存下来的值，读的时候**一定要准备默认值**（表单里没填过的键不会出现）：

```php
private static function config(): array
{
    $saved = function_exists('plugin_config_get') ? \plugin_config_get('addons', 'demo') : [];
    if (!is_array($saved)) $saved = [];

    return array_merge([
        'api_url'   => '',
        'api_key'   => '',
        'interval'  => '60',
        'push_open' => '1',
    ], $saved);
}
```

- `plugin_config_get('addons', 插件名)` 拿到的就是你表单里那些键值（系统把它序列化存在配置表里，带缓存）；
- 单值配置用 `config_get('键', 默认值)` / `config_set('键', 值)`；
- **地址、密钥、开关一律不要写死在代码里**，站长要能自己在后台改；
- 开关类字段的值是字符串 `'1'` / `'0'`，判断时用 `=== '1'` 或强转 `(int)`，别用真假值直接判。

---

## 六、建表、升级、卸载

- 表名一律写 `pre_`（系统换成站点自己的前缀），**只建自己的表**；
- `install.sql`：建表 + 登记插件自己 + 登记计划任务（见上面那两段 `INSERT IGNORE`）；
- `update.sql`：**从安装那一版到当前版要补的东西全写在这里**，而且要**能反复执行** ——
  从老版本升上来的站点会把它整个跑一遍，点两次「更新」也不能出事。
  加表、加字段都写成幂等的；**不要在这里做不可逆的事**（删数据、改站长的数据）；
- `uninstall.sql`：**自己的东西都带走** —— 自己的表、自己登记的那行计划任务
  （用 `task` + `plugin` 两个条件一起认，绝不误删系统自带的任务）。
  **站长的数据一条都别动**：你写进系统某张表里的行，SQL 只能按备注去认，认错了就是删站长的数据。
  公共黑名单的做法是**一条都不删**，改成在插件页给站长一个「清空我自己写进去的条目」的按钮 ——
  让站长自己决定。卸载后插件的配置和状态由系统自己清，不用你管。

---

## 七、想参与支付链路？先看这一节

系统的支付流程（收银台、下单、通道提交）**没有给插件留任何钩子**，相关的几个文件也是加密的。
往明文文件里插代码倒是能拦，但那就是"改了站长的平台"，系统一升级就白改，还说不清楚。

**能走的路只有一条，也是本仓库插件走的路**：别去抢那一脚，**把自己变成数据** ——
写进一张系统自己本来就会去读的表，让系统的代码替你做事。比如拦人：

| 系统自己会查黑名单表的地方 | 查什么 |
|---|---|
| `app/common.php` 的 `checkBlockUser()` | 下单时的买家账号 |
| `app/common.php` 微信 / 支付宝授权那段 | 拿到手机号之后查手机号 |
| `app/controller/qrpay/Index.php` | 收银台下单时的下单 IP |
| `app/controller/applet/Cashier.php` | 小程序收银台的下单 IP |

把要拦的人写进 `pre_blacklist`，系统下一步自己就拦了。剩下的只是"数据什么时候到位" ——
配一个路由中间件（每个请求摸一下，付款路径上更勤），就能压到秒级。
好处是：一个文件没改、系统升级不用重新打补丁，而且它写进去的是**数据**，站长在自己后台看得见、删得掉。

---

## 八、发布一个插件

- **打包**：把插件目录整个压成 zip，**顶层就是插件目录名**（`blacklist/…`），
  站长解压后直接传到 `plugins/addons/` 就能用。本仓库的 GitHub Action
  （`.github/workflows/release.yml`）就是按这个形状打的：推一个 `v*` tag，它会读每个插件
  `info.json` 里的版本号，打成 `<插件名>-<版本号>.zip` 挂到 Release；
- **版本号**：改了行为就改 `info.json` 的 `version`，同时把 `update.sql` 写成能重复跑的样子；
- **文档**：一个插件**一份 md**（就是插件目录里的 `README.md`，跟着插件一起发给站长）——
  怎么装、怎么升级、怎么用、出问题先看哪儿、每个版本改了什么，全写在那一个文件里，
  别一个插件散成好几个文档；
- 本仓库的规矩：一个插件一个目录、**零注释出货版**（开发时写满注释，打包前去掉，
  站长机器上不留我们的开发笔记）。

---

## 九、踩过的坑（都是真踩过的）

1. `getName()` 或类名 / 文件名跟目录名对不上 → 插件加载不到（系统是按名字拼类名的）。
2. `boot()` 里查库、发 HTTP → 每个请求都付一遍代价。
3. 钩子里抛异常 → 打断系统的投诉处理 / 每日定时任务，站长看到一串莫名其妙的报错。
4. 中间件里抛异常 → **整站 500**；参数和返回值写死了类型，遇到不匹配也会在放行那一步炸。
5. 后台页面脚本里写了 `import` / `export`，或者去赌 `window.Vue` / `window.ElementPlus` 长什么样
   → 页面一片白。`render(){ return null; }` + 自己操作 DOM 最稳。
6. 表名没写 `pre_`（或写了站点真实前缀）→ 换个站装就挂；`INSERT` 忘了 `IGNORE` → 重复安装报错。
7. `install.sql` 里的错误是**静默**的，装完一定回后台确认表和计划任务都在。
8. 卸载不干净：自己的表没删、登记的计划任务没删（那条任务会每 60 秒失败一次，一直刷日志）。
9. 老站没有 `pre_crontab` 表 → 别把功能全押在系统计划任务上，留一条自备的访问地址。
10. 忘了改 `info.json` 的 `version` → 后台显示的版本号还是旧的，站长不知道要不要点更新。
11. 装完插件，后台菜单里没有它 → 先点一次「刷新插件列表」；
    还不行就看站点是不是用了 classmap 优化的自动加载（`composer dump-autoload -o -a`），
    那种情况下新插件的类要重跑一次 `composer dump-autoload` 才找得到。

---

## 十、动手之前

系统这套插件机制的代码**全是明文**的，写之前先读一遍，比猜快得多：

| 文件 | 里面是什么 |
|---|---|
| `app/common/BaseAddon.php` | 插件基类：boot、路由、菜单、install/update/uninstall 的默认实现 |
| `app/lib/AddonManager.php` | 钩子、路由注册、安装 / 启用 / 卸载的完整流程 |
| `app/common/AbstractCronTask.php`、`app/common/CronTaskInterface.php` | 计划任务的写法 |
| `app/data/menu_admin.json` | 后台菜单的 name 都在这儿（`parent` 填什么看它） |
| `route/app.php`、`route/admin.php`、`route/user.php` | 插件路由是在哪儿、以什么顺序注册的 |
| `app/sql/install.sql` | 系统自带那十条计划任务的写法 |

想看得更全，就把本仓库的 `plugins/addons/blacklist/` 完整读一遍：钩子、后台路由 + 后台页面、
路由中间件、系统计划任务、自备的任务地址、四张自己的表、幂等 SQL、卸载清理，全都用上了，
是这套东西的一个完整样本。
