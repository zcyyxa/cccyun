(function () {
  'use strict';

  function assetPath() {
    var src = '';
    try { src = (document.currentScript && document.currentScript.src) || ''; } catch (e) { src = ''; }

    if (!src) {
      try {
        var list = document.getElementsByTagName('script');
        for (var i = list.length - 1; i >= 0; i--) {
          if (list[i].src && list[i].src.indexOf('addon/blacklist') >= 0) { src = list[i].src; break; }
        }
      } catch (e2) { src = ''; }
    }

    if (!src) return '';
    try { return new URL(src, location.href).pathname || ''; } catch (e3) { return ''; }
  }

  var ASSET = assetPath();
  var ROOT  = '';
  (function () {
    var at = ASSET.indexOf('/addon/blacklist/');
    if (at >= 0) {
      ROOT = ASSET.slice(0, at);
    } else {
      ROOT = '/' + ((location.pathname.split('/')[1] || '').replace(/\/+$/, ''));
      if (ROOT === '/admin') ROOT = '';
    }
  })();

  var BASES = [ROOT + '/admin/addon/blacklist/', ROOT + '/addon/blacklist/'];
  var BASE  = 0;

  function d(tag, cls, kids) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    return app(e, kids);
  }

  function app(e, kids) {
    if (kids === null || kids === undefined || kids === false) return e;

    if (Object.prototype.toString.call(kids) === '[object Array]') {
      for (var i = 0; i < kids.length; i++) app(e, kids[i]);
      return e;
    }
    if (typeof kids === 'object' && kids.nodeType) {
      e.appendChild(kids);
      return e;
    }
    e.appendChild(document.createTextNode(String(kids)));
    return e;
  }

  function cut(s, n) {
    s = String(s === null || s === undefined ? '' : s);
    return s.length > n ? s.slice(0, n) + '…' : s;
  }

  var CSS = [
    '.bl-page{--bl-bd:var(--el-border-color-lighter,#ebeef5);',
    '--bl-bd2:var(--el-border-color-extra-light,#f2f6fc);',
    '--bl-dim:var(--el-text-color-secondary,#909399);',
    '--bl-ink:var(--el-text-color-primary,#303133);',
    '--bl-fill:var(--el-fill-color-light,#f5f7fa);',
    '--bl-pri:var(--el-color-primary,#409eff);',
    'color:var(--bl-ink);font-size:14px;line-height:1.6}',

    '.bl-page .bl-card{background:var(--el-bg-color,#fff);border:1px solid var(--bl-bd);',
    'border-radius:10px;padding:16px 18px;margin-bottom:14px}',
    '.bl-page .bl-card__hd{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}',
    '.bl-page .bl-card__hd h3{margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px}',
    '.bl-page .bl-card__hd h3:before{content:"";width:3px;height:14px;border-radius:2px;background:var(--bl-pri)}',
    '.bl-page .bl-card__hd.bl-click{cursor:pointer;user-select:none}',
    '.bl-page .bl-caret{font-size:12px;color:var(--bl-dim)}',

    '.bl-page .bl-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}',
    '.bl-page .bl-stat{background:var(--bl-fill);border-radius:8px;padding:10px 12px;min-width:0}',
    '.bl-page .bl-stat b{display:block;font-size:20px;font-weight:600;line-height:1.3}',
    '.bl-page .bl-stat i{display:block;font-style:normal;font-size:12px;color:var(--bl-dim);',
    'margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',

    '.bl-page .bl-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}',
    '.bl-page .bl-grid .bl-card{margin-bottom:0}',

    '.bl-page .bl-seg{display:inline-flex;background:var(--bl-fill);border-radius:7px;padding:3px;flex:none}',
    '.bl-page .bl-seg button{border:0;background:transparent;cursor:pointer;font:inherit;font-size:13px;',
    'color:var(--bl-dim);padding:4px 14px;border-radius:5px;transition:background .15s,color .15s}',
    '.bl-page .bl-seg button.on{background:var(--el-bg-color,#fff);color:var(--bl-pri);font-weight:600;',
    'box-shadow:0 1px 3px rgba(0,0,0,.1)}',

    '.bl-page .bl-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}',
    '.bl-page .bl-form>.el-input{flex:1 1 150px;min-width:0}',
    '.bl-page .bl-form>.bl-tip{flex:1 1 auto;font-size:13px;color:var(--bl-dim)}',
    '.bl-page .bl-days{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--bl-dim);flex:none}',
    '.bl-page .bl-days>.el-input{width:64px;flex:none}',

    '.bl-page .bl-rows{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}',
    '.bl-page .bl-row{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--bl-bd2);font-size:13px;min-width:0}',
    '.bl-page .bl-row i{flex:none;width:108px;font-style:normal;color:var(--bl-dim)}',
    '.bl-page .bl-row b{flex:1;min-width:0;font-weight:500;word-break:break-all}',

    '.bl-page .bl-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px;',
    'padding-top:14px;border-top:1px solid var(--bl-bd2)}',
    '.bl-page .bl-foot{margin-top:14px;font-size:12px;color:var(--bl-dim)}',
    '.bl-page .bl-url{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}',
    '.bl-page .bl-url>.el-input{flex:1 1 200px;min-width:0}',

    '@media(max-width:900px){.bl-page .bl-grid{grid-template-columns:1fr}',
    '.bl-page .bl-rows{grid-template-columns:1fr}}',
    '@media(max-width:720px){.bl-page .bl-stats{grid-template-columns:repeat(2,1fr)}}',
  ].join('');

  function injectCss() {
    try {
      if (document.getElementById && document.getElementById('bl-page-css')) return;

      var s = document.createElement('style');
      s.id = 'bl-page-css';
      s.appendChild(document.createTextNode(CSS));

      var head = document.head || document.getElementsByTagName('head')[0] || null;
      if (head) head.appendChild(s);
    } catch (e) { }
  }

  function n(v) {
    v = parseInt(v, 10);
    return isNaN(v) ? 0 : v;
  }

  function ago(sec) {
    sec = parseInt(sec, 10);
    if (isNaN(sec) || sec < 0) return '还没';
    if (sec < 60) return sec + ' 秒前';
    if (sec < 3600) return Math.floor(sec / 60) + ' 分钟前';
    if (sec < 86400) return Math.floor(sec / 3600) + ' 小时前';
    return Math.floor(sec / 86400) + ' 天前';
  }

  function onoff(v, onText) {
    return String(v) === '1' ? (onText || '开') : '关';
  }

  function tagEl(type, text) {
    return d('span', 'el-tag el-tag--' + (type || 'info') + ' el-tag--small', text);
  }

  function alertEl(type, title, desc) {
    var box = d('div', 'el-alert el-alert--' + type + ' is-light');
    var body = d('div', 'el-alert__content');
    body.appendChild(d('span', 'el-alert__title', title));
    if (desc) body.appendChild(d('p', 'el-alert__description mt-2', desc));
    box.appendChild(body);
    return box;
  }

  function buttonEl(text, variant, onClick, opts) {
    opts = opts || {};
    var cls = 'el-button' + (opts.small ? ' el-button--small' : '');
    if (variant) cls += ' el-button--' + variant;
    if (opts.plain) cls += ' is-plain';

    var b = d('button', cls);
    b.type = 'button';
    b.appendChild(d('span', '', text));

    if (opts.disabled) { b.disabled = true; b.className = cls + ' is-disabled'; }
    if (onClick) b.onclick = onClick;
    return b;
  }

  function textInput(value, placeholder, onInput, onEnter, cls) {
    var wrap = d('div', 'el-input el-input--small ' + (cls || ''));
    var box  = d('div', 'el-input__wrapper');
    var inp  = d('input', 'el-input__inner');

    inp.type = 'text';
    inp.value = (value === null || value === undefined) ? '' : String(value);
    if (placeholder) inp.placeholder = placeholder;
    inp.setAttribute('autocomplete', 'off');
    if (onInput) inp.oninput = function () { onInput(inp.value); };
    if (onEnter) inp.onkeyup = function (ev) { if (ev.key === 'Enter') onEnter(); };

    box.appendChild(inp);
    wrap.appendChild(box);
    return wrap;
  }

  function readonlyInput(value, cls) {
    var wrap = textInput(value, '', null, null, cls);
    var inp = wrap.getElementsByTagName('input')[0];
    if (inp) {
      inp.readOnly = true;
      inp.className = 'el-input__inner font-mono break-all';
      inp.onclick = function () { try { inp.select(); } catch (e) { } };
    }
    return wrap;
  }

  function seg(current, onPick) {
    var box = d('div', 'bl-seg');
    [[0, '账号'], [1, 'IP']].forEach(function (o) {
      var b = d('button', current === o[0] ? 'on' : '', o[1]);
      b.type = 'button';
      b.onclick = function () { onPick(o[0]); };
      box.appendChild(b);
    });
    return box;
  }

  function card(title, opts) {
    opts = opts || {};
    var c = d('div', 'art-card bl-card');

    if (!title) return c;

    var hd = d('div', 'bl-card__hd' + (opts.click ? ' bl-click' : ''));
    hd.appendChild(d('h3', '', [title, opts.titleExtra || null]));

    if (opts.right)       hd.appendChild(opts.right);
    else if (opts.caret)  hd.appendChild(d('span', 'bl-caret', state.open ? '收起 ▲' : '展开 ▼'));

    c.appendChild(hd);
    if (opts.click) hd.onclick = opts.click;

    return c;
  }

  function stats(rows) {
    var g = d('div', 'bl-stats');
    rows.forEach(function (r) {
      g.appendChild(d('div', 'bl-stat', [d('b', '', String(r[0])), d('i', '', String(r[1]))]));
    });
    return g;
  }

  function rows(list) {
    var g = d('div', 'bl-rows');
    list.forEach(function (r) {
      g.appendChild(d('div', 'bl-row', [d('i', '', r[0]), d('b', '', r[1])]));
    });
    return g;
  }

  function copyText(text, done, fail) {
    function fallback() {
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        done();
      } catch (e) {
        fail();
      }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else {
      fallback();
    }
  }

  function request(url, payload) {
    var opt = { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (payload !== undefined && payload !== null) {
      opt.method = 'POST';
      opt.headers['Content-Type'] = 'application/json; charset=utf-8';
      opt.body = JSON.stringify(payload);
    }

    return fetch(url, opt).then(function (res) {
      return res.text().then(function (body) {
        var json = null;
        try { json = JSON.parse(body); } catch (e) { json = null; }

        if (!json || typeof json !== 'object') {
          var e1 = new Error('这个地址没返回 JSON（HTTP ' + res.status + '）：' + cut(body, 200));
          e1.http = res.status;
          e1.url  = url;
          throw e1;
        }
        if (n(json.code) !== 200) {
          var e2 = new Error(String(json.msg || ('接口返回 ' + json.code)));
          e2.http = res.status;
          e2.url  = url;
          e2.code = n(json.code);
          throw e2;
        }
        return (json.data === null || json.data === undefined) ? {} : json.data;
      });
    }).catch(function (e) {
      if (!e.url) e.url = url;
      if (typeof e.http !== 'number') e.http = 0;
      throw e;
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

  var state = {
    loading: true,
    err: '',
    flash: null,
    info: null,
    result: null,
    busySync: false,
    busyFix: false,
    busyClear: false,
    busyTest: false,
    testRes: null,
    open: false,
    qType: 0, qContent: '', qBusy: false, qRes: null,
    rType: 0, rContent: '', rDays: '', rBusy: false, rRes: null,
  };

  var container = null;

  function say(text, type) {
    state.flash = { type: type || 'success', text: text };
    render();
  }

  function render() {
    if (!container) return;

    var box;
    try {
      box = buildPage();
    } catch (e) {
      try {
        box = buildCrash(e);
      } catch (e2) {
        box = d('div', 'art-card p-5', [
          alertEl('error', '公共黑名单页面渲染失败', String(e && e.message || e)),
        ]);
      }
    }

    while (container.firstChild) container.removeChild(container.firstChild);
    container.appendChild(box);
  }

  function buildPage() {
    if (state.loading) return buildLoading();

    if (state.err && !state.info) return buildError();

    var box = d('div', '', [
      state.flash ? d('div', 'mb-4', alertEl(state.flash.type, state.flash.text, '')) : null,
      state.err ? d('div', 'mb-4', alertEl('error', state.err, '')) : null,
      cardMain(),
      cardTask(),
      cardQuery(),
      cardReport(),
      cardSet(),
    ]);

    return box;
  }

  function buildError() {
    var c = card('公共黑名单');

    c.appendChild(alertEl('error', '读不到插件状态', String(state.err || '')));
    c.appendChild(d('div', 'bl-foot font-mono break-all', '接口地址：' + BASES[BASE]));
    c.appendChild(d('div', 'mt-3',
      buttonEl('重试', 'primary', function () { onReload(); }, { small: true })));

    return c;
  }

  function downInfo() {
    return (state.info && state.info.down) ? state.info.down : {};
  }

  function statTag() {
    if (!state.info || !state.info.ready) return tagEl('danger', '还没连上平台');
    var dw = downInfo();
    if (!dw.enabled) return tagEl('info', '只上报，不拦截');
    if (dw.fail_at) return tagEl('warning', '上一轮没连上平台');
    return tagEl('success', '运行中');
  }

  function cardMain() {
    var dw   = downInfo();
    var pend = (state.info && state.info.pending) || {};

    var c = card('公共黑名单', {
      titleExtra: statTag(),
      right: buttonEl(state.busySync ? '正在同步…' : '立即同步', 'primary',
        function () { onSync(); }, { disabled: state.busySync }),
    });

    c.appendChild(stats([
      [n(dw.blocking), '本站在拦'],
      [n(dw.total_active), '平台有效'],
      [n(pend.count), '待上报'],
      [ago(dw.last_ago), '上次同步'],
    ]));

    if (state.result) {
      c.appendChild(d('div', 'mt-3', alertEl(state.result.type, state.result.text, '')));
    }

    var auto = '24 小时同步 ' + n(dw.pull) + ' 轮';
    if (n(dw.pull_failed) > 0) auto += '（' + n(dw.pull_failed) + ' 轮没连上平台）';
    if (n(dw.next_in) > 0) auto += '，下一轮最晚 ' + n(dw.next_in) + ' 秒后';

    c.appendChild(d('div', 'bl-foot', auto));

    return c;
  }

  function cardTask() {
    var c    = card('自动同步');
    var info = state.info || {};
    var cron = info.cron || {};
    var task = cron.task || {};

    if (task.found) {
      c.appendChild(rows([
        ['计划任务', [
          d('span', 'mr-2', 'blacklist_sync'),
          task.enabled ? tagEl('success', '已启用') : tagEl('info', '已停用'),
          task.interval_text ? d('span', 'ml-2', task.interval_text) : null,
        ]],
        ['上次执行', task.last_run_time
          ? (task.last_run_time + '（' + (n(task.last_status) === 1 ? '成功' : '失败') + '）')
          : '还没跑过'],
      ]));

      if (task.last_error) {
        c.appendChild(d('div', 'mt-3', alertEl('error', '上次执行报的错', cut(task.last_error, 300))));
      }
    } else {
      c.appendChild(d('div', 'bl-form', [
        d('span', 'bl-tip', '系统计划任务里还没有这条'),
        buttonEl(state.busyFix ? '正在加…' : '加进系统计划任务', 'primary',
          function () { onCronFix(); }, { small: true, disabled: state.busyFix }),
      ]));
    }

    if (String(cron.mode) !== 'swoole') {
      var url = String(info.task_url || '');
      c.appendChild(d('div', 'bl-foot', '宝塔的计划任务，访问这个地址，60 秒一次：'));

      var line = d('div', 'bl-url');
      if (url) {
        line.appendChild(readonlyInput(url, ''));
        line.appendChild(buttonEl('复制', '', function () { onCopy(url); }, { small: true }));
        line.appendChild(buttonEl('换密钥', '', function () { onTaskReset(); }, { small: true }));
      } else {
        line.appendChild(d('span', 'bl-caret', '（读不出来，刷新看看）'));
      }
      c.appendChild(line);
    }

    return c;
  }

  function cardQuery() {
    var c = card('手动查一条');

    c.appendChild(d('div', 'bl-form', [
      seg(state.qType, function (v) { state.qType = v; render(); }),
      textInput(state.qContent, '账号 / IP',
        function (v) { state.qContent = v; },
        function () { onQuery(); }),
      buttonEl(state.qBusy ? '查询中…' : '查询', '', function () { onQuery(); }, { disabled: state.qBusy }),
    ]));

    if (state.qRes) c.appendChild(d('div', 'mt-3', queryLine()));
    return c;
  }

  function queryLine() {
    var r = state.qRes || {};

    if (r.err) return alertEl('error', r.err, '');

    if (r.hit) {
      var tail = '平台累计 ' + n(r.total_days) + ' 天';
      if (r.permanent) tail += '（永久）';
      else if (r.endtime) tail += '，' + r.endtime + ' 到期';
      if (n(r.reports) > 0) tail += '，' + n(r.reports) + ' 家报过';

      return d('div', 'text-sm flex items-center flex-wrap gap-2', [
        tagEl('danger', '命中'),
        d('span', 'text-g-700', tail),
      ]);
    }

    return d('div', 'text-sm flex items-center flex-wrap gap-2', [
      tagEl('success', '没查到'),
      r.skipped ? d('span', 'text-g-600', '在跳过名单里') : null,
    ]);
  }

  function cardReport() {
    var c = card('手动报一条');

    c.appendChild(d('div', 'bl-form', [
      seg(state.rType, function (v) { state.rType = v; render(); }),
      textInput(state.rContent, '账号 / IP',
        function (v) { state.rContent = v; },
        function () { onReport(); }),
      d('span', 'bl-days', [
        '封',
        textInput(state.rDays, '',
          function (v) { state.rDays = v; },
          function () { onReport(); }),
        '天',
      ]),
      buttonEl(state.rBusy ? '上报中…' : '上报', 'primary', function () { onReport(); }, { disabled: state.rBusy }),
    ]));

    if (state.rRes) c.appendChild(d('div', 'mt-3', reportLine()));
    return c;
  }

  function reportLine() {
    var r = state.rRes || {};
    if (r.err) return alertEl('error', r.err, '');

    var tail = '平台累计 ' + n(r.total_days) + ' 天';
    if (r.permanent) tail += '（永久）';
    else if (r.endtime) tail += '，' + r.endtime + ' 到期';

    return d('div', 'text-sm flex items-center flex-wrap gap-2', [
      tagEl('success', '已上报'),
      d('span', 'text-g-700', tail),
    ]);
  }

  function cardSet() {
    var c = card('设置', {
      caret: true,
      click: function () { state.open = !state.open; render(); },
    });

    if (!state.open) return c;

    var i = state.info || {};

    c.appendChild(rows([
      ['平台接口地址', i.api_url || '（没填）'],
      ['平台接口密钥', i.api_key || '（没填）'],
      ['请求超时', n(i.timeout) + ' 秒'],
      ['下发拦截', onoff(i.push_enable, '开')],
      ['付款路径间隔', n(i.pay_interval) + ' 秒'],
      ['普通页面间隔', n(i.pull_interval) + ' 秒'],
      ['下发类型', ({ 0: '账号和 IP', 1: '只要账号', 2: '只要 IP' })[n(i.pull_type)] || '账号和 IP'],
      ['被几家报过才下发', n(i.pull_min_reports) + ' 家'],
      ['投诉处理时上报', onoff(i.complaint_sync)],
      ['同步时匹配投诉', onoff(i.link_complaint)],
      ['每日兜底同步', onoff(i.auto_sync)],
      ['本地上报天数上限', n(i.sync_days) + ' 天'],
      ['同步跳过名单', i.skip_list || '（没设）'],
    ]));

    c.appendChild(d('div', 'bl-acts', [
      buttonEl(state.busyTest ? '测试中…' : '测一下连接', '',
        function () { onTest(); }, { small: true, disabled: state.busyTest }),
      d('span', 'bl-caret', '改这些去「插件管理 - 配置」'),
    ]));

    if (state.testRes) {
      c.appendChild(d('div', 'mt-3', alertEl(state.testRes.ok ? 'success' : 'error', state.testRes.text, '')));
    }

    c.appendChild(d('div', 'bl-acts', [
      buttonEl(state.busyClear ? '清理中…' : '清空平台已下发条目', 'danger',
        function () { onClearPushed(); }, { small: true, plain: true, disabled: state.busyClear }),
      d('span', 'bl-caret', '只删插件写进去的行，你自己拉黑的不动'),
    ]));

    return c;
  }

  function buildCrash(e) {
    var c = card('页面出错了');

    c.appendChild(alertEl('error', '公共黑名单页面出错了', String((e && e.message) || e)));

    c.appendChild(d('div', 'bl-foot font-mono break-all', cut(String((e && e.stack) || ''), 600)));
    c.appendChild(d('div', 'bl-foot font-mono break-all', '接口地址：' + BASES[BASE]));
    c.appendChild(d('div', 'mt-3',
      buttonEl('重新加载', 'primary', function () { onReload(); }, { small: true })));

    return c;
  }

  function buildLoading() {
    var c = card('公共黑名单');
    c.appendChild(d('div', 'bl-foot', '正在读取…'));
    return c;
  }

  function onReload() {
    state.loading = true;
    state.err = '';
    render();
    load();
  }

  function load() {
    state.loading = (state.info === null);
    state.err = '';
    render();

    api('info').then(function (d) {
      state.loading = false;
      state.info = d;

      if (d && d.err) {
        state.err = String(d.err);
      } else if (!state.rDays) {
        state.rDays = String(d.report_days_default || '7');
      }
      render();
    }).catch(function (e) {
      state.loading = false;
      state.err = String((e && e.message) || e) + '（地址：' + BASES[BASE] + '）';
      state.info = null;
      render();
    });
  }

  function onSync() {
    if (state.busySync) return;
    state.busySync = true;
    state.result = null;
    state.flash = null;
    render();

    api('syncAll', {}).then(function (d) {
      state.busySync = false;
      state.result = {
        type: d.warn ? 'warning' : 'success',
        text: String(d.text || '同步完了'),
      };
      if (d.down) { state.info = state.info || {}; state.info.down = d.down; }
      if (d.pending) { state.info = state.info || {}; state.info.pending = d.pending; }
      render();
    }).catch(function (e) {
      state.busySync = false;
      state.result = { type: 'error', text: String((e && e.message) || e) };
      render();
    });
  }

  function onCronFix() {
    if (state.busyFix) return;
    state.busyFix = true;
    render();

    api('cronFix', {}).then(function (d) {
      state.busyFix = false;
      if (state.info) state.info.cron = d.cron || state.info.cron;
      say('已加入系统计划任务', 'success');
    }).catch(function (e) {
      state.busyFix = false;
      say(String((e && e.message) || e), 'error');
    });
  }

  function onTest() {
    if (state.busyTest) return;
    state.busyTest = true;
    state.testRes = null;
    render();

    api('test', {}).then(function (d) {
      state.busyTest = false;
      state.testRes = { ok: true, text: '连接正常（' + n(d.ms) + ' 毫秒）' };
      render();
    }).catch(function (e) {
      state.busyTest = false;
      state.testRes = { ok: false, text: String((e && e.message) || e) };
      render();
    });
  }

  function onQuery() {
    if (state.qBusy) return;

    var content = String(state.qContent || '').replace(/^\s+|\s+$/g, '');
    if (!content) { state.qRes = { err: '先填要查的内容' }; render(); return; }

    state.qBusy = true;
    state.qRes = null;
    render();

    api('check', { type: state.qType, content: content }).then(function (d) {
      state.qBusy = false;
      state.qRes = d;
      render();
    }).catch(function (e) {
      state.qBusy = false;
      state.qRes = { err: String((e && e.message) || e) };
      render();
    });
  }

  function onReport() {
    if (state.rBusy) return;

    var content = String(state.rContent || '').replace(/^\s+|\s+$/g, '');
    if (!content) { state.rRes = { err: '先填要上报的内容' }; render(); return; }

    state.rBusy = true;
    state.rRes = null;
    render();

    api('report', { type: state.rType, content: content, days: n(state.rDays) }).then(function (d) {
      state.rBusy = false;
      state.rRes = d;
      render();
    }).catch(function (e) {
      state.rBusy = false;
      state.rRes = { err: String((e && e.message) || e) };
      render();
    });
  }

  function onCopy(text) {
    copyText(String(text), function () {
      say('已复制', 'success');
    }, function () {
      say('复制不了，手动选一下地址框', 'warning');
    });
  }

  function onTaskReset() {
    if (!window.confirm('换密钥后宝塔里那条地址就失效了，确定换吗？')) return;

    api('taskReset', {}).then(function (d) {
      if (d.task_url && state.info) state.info.task_url = d.task_url;
      say('已换新密钥，记得把宝塔里那条地址改掉', 'success');
    }).catch(function (e) {
      say(String((e && e.message) || e), 'error');
    });
  }

  function onClearPushed() {
    if (state.busyClear) return;
    if (!window.confirm('把插件写进系统黑名单表的行全删掉？你自己拉黑的一个都不动。')) return;

    state.busyClear = true;
    render();

    api('pushClear', {}).then(function (d) {
      state.busyClear = false;
      if (d.down && state.info) state.info.down = d.down;
      say(String(d.text || '清干净了'), 'success');
    }).catch(function (e) {
      state.busyClear = false;
      say(String((e && e.message) || e), 'error');
    });
  }

  function attach(host) {
    try {
      var old = host.querySelector ? host.querySelector('[data-bl-page]') : null;
      if (old && old.parentNode) old.parentNode.removeChild(old);
    } catch (e) { }

    try {
      var list = document.querySelectorAll('[data-bl-page]');
      for (var i = 0; i < list.length; i++) {
        if (list[i].parentNode) list[i].parentNode.removeChild(list[i]);
      }
    } catch (e2) { }

    container = document.createElement('div');
    container.className = 'art-page-view bl-page';
    container.setAttribute('data-bl-page', '1');
    host.appendChild(container);
    attached = true;

    injectCss();
    render();
    load();
  }

  var SELF = null;
  try { SELF = document.currentScript || null; } catch (e) { SELF = null; }

  var attached = false;

  function mount(vm) {
    var tries = 0;

    (function go() {
      var el = vm && vm.$el;
      var host = null;

      try { host = (el && el.parentNode) ? el.parentNode : null; } catch (e) { host = null; }

      if (!host && tries++ < 10) { setTimeout(go, 40); return; }
      if (!host) { host = document.body; }

      try {
        attach(host);
      } catch (e) {
        try {
          var box = document.createElement('div');
          box.className = 'art-page-view bl-page';
          box.appendChild(card('公共黑名单加载失败'));
          box.appendChild(alertEl('error', '页面挂载失败', String((e && e.message) || e)));
          host.appendChild(box);
        } catch (e2) { }
      }
    })();
  }

  function watchdog(tries) {
    if (attached && container && container.parentNode && document.body.contains(container)) return;

    var host = null;
    try { host = (SELF && SELF.parentNode) ? SELF.parentNode : null; } catch (e) { host = null; }

    var up = host;
    for (var i = 0; i < 5 && up; i++) {
      try {
        var cls = String(up.className || '');
        if (/(^|\s)(art-page-view|art-main|layout-content|app-main)(\s|$)/.test(cls)) { host = up; break; }
        if (!up.parentNode || up.parentNode === document.body) break;
        up = up.parentNode;
      } catch (e) { break; }
    }

    if (tries >= 3) {
      try {
        var alt = document.querySelector('.layout-content, .art-main, .app-main, #app');
        if (alt) host = alt;
      } catch (e) { }
    }

    if (host) {
      try { attach(host); return; } catch (e) { }
    }

    if (tries < 6) {
      setTimeout(function () { watchdog((tries || 0) + 1); }, 1500);
    }
  }

  function unmount() {
    try {
      if (container && container.parentNode) container.parentNode.removeChild(container);
    } catch (e) { }
    container = null;
    attached = false;
  }

  window.__ART_PLUGIN_COMPONENT__ = {
    name: 'PublicBlackListPage',
    inheritAttrs: false,
    render: function () { return null; },
    mounted: function () { mount(this); },
    unmounted: function () { unmount(); },
  };

  try {
    setTimeout(function () { watchdog(0); }, 1200);
    setTimeout(function () { watchdog(3); }, 4000);
  } catch (e) { }
})();
