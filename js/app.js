/**
 * 万刊网 — 前端 SPA
 * 基于 hash 路由，调用 api.php 后端
 */

const API = 'api.php';
let currentUser = null;
let authToken = null;
let accounts = [];
let addingAccount = false;
let unreadCount = 0;
let msgTimer = null;
let chatConvCache = [];   // 会话列表缓存
let chatPeer = null;        // 当前打开会话的对方用户（id/username/avatar），全局唯一，避免发送后头像丢失
let chatDockOpen = false;
let chatDockTimer = null;
let lastUnread = 0;        // 全局未读轮询基准（切换账号时重置），用于判断"新收到"的私信条数

// ============ 权限位（与后端 PERM_* 保持一致） ============
const PERM = {
  VIEW: 1, POST: 2, COMMENT: 4, CREATE_PUB: 8, MANAGE_PUB: 16,
  MANAGE_ARTICLES: 32, REVIEW: 64, MANAGE_MEMBERS: 128, MANAGE_ROLES: 256,
  MANAGE_USERS: 512, BAN: 1024, PIN: 2048, MANAGE_PLATFORM: 4096, ADMIN: 8192, ALL: 16383
};
function hasPerm(p, f) { return ((p || 0) & f) === f; }
// 权限位清单（用于勾选 UI）
const PERM_LIST = [
  ['VIEW', '浏览', 1], ['POST', '投稿/发帖', 2], ['COMMENT', '评论', 4],
  ['CREATE_PUB', '创建刊物', 8], ['MANAGE_PUB', '管理刊物', 16],
  ['MANAGE_ARTICLES', '管理文章/审稿', 32], ['REVIEW', '审稿', 64],
  ['MANAGE_MEMBERS', '管理成员', 128], ['MANAGE_ROLES', '管理身份组', 256],
  ['MANAGE_USERS', '管理平台用户', 512], ['BAN', '封禁', 1024],
  ['PIN', '置顶', 2048], ['MANAGE_PLATFORM', '平台管理', 4096], ['ADMIN', '管理员', 8192]
];
// 把权限位数值渲染成可勾选的复选框
function permCheckboxes(perms) {
  perms = perms || 0;
  return PERM_LIST.map(([key, label, bit]) => `
    <label class="checkbox-item">
      <input type="checkbox" class="perm-cb" value="${bit}" ${hasPerm(perms, bit) ? 'checked' : ''}>
      <span>${label}</span>
    </label>`).join('');
}
// 校验身份组颜色，仅允许 #hex，防 color 注入 style 属性造成 XSS
function safeColor(c) {
  c = (c || '').trim();
  return /^#[0-9a-fA-F]{3,8}$/.test(c) ? c : '#5865f2';
}
// 渲染身份组彩色徽章
function roleBadges(roles) {
  if (!roles || !roles.length) return '';
  return roles.map(r => `<span class="role-badge" style="background:${safeColor(r.color)}22;color:${safeColor(r.color)};border-color:${safeColor(r.color)}55">${escapeHtml(r.name)}</span>`).join('');
}
// 当前用户是否有平台管理类权限（决定后台入口）
function isPlatformAdmin() {
  if (!currentUser) return false;
  const p = currentUser.platform_perms || 0;
  return hasPerm(p, PERM.MANAGE_ROLES) || hasPerm(p, PERM.MANAGE_USERS) || hasPerm(p, PERM.MANAGE_PLATFORM) || hasPerm(p, PERM.ADMIN);
}

// ============ 多账号本地存储 ============
function loadAccounts() {
  try {
    accounts = JSON.parse(localStorage.getItem('wk_accounts') || '[]');
    const active = localStorage.getItem('wk_active_token');
    const acc = accounts.find(a => a.token === active);
    if (acc) { authToken = acc.token; return; }
    if (accounts.length) authToken = accounts[0].token;
  } catch (e) { accounts = []; }
}
function saveAccounts() {
  localStorage.setItem('wk_accounts', JSON.stringify(accounts));
  localStorage.setItem('wk_active_token', authToken || '');
}
function upsertAccount(token, user) {
  const i = accounts.findIndex(a => a.user.username === user.username);
  if (i >= 0) accounts[i] = { token, user };
  else accounts.push({ token, user });
  authToken = token;
  saveAccounts();
}
function syncCurrent() {
  if (!currentUser) return;
  const i = accounts.findIndex(a => a.user.username === currentUser.username);
  if (i >= 0) { accounts[i].user = Object.assign({}, accounts[i].user, currentUser); saveAccounts(); }
}
function removeActiveAccount() {
  const curName = currentUser && currentUser.username;
  const cur = accounts.find(a => a.user.username === curName);
  const tok = (cur && cur.token) || authToken;
  accounts = accounts.filter(a => a.token !== tok);
  authToken = accounts.length ? accounts[0].token : null;
  saveAccounts();
}

// ============ API 调用 ============
// InfinityFree 免费档会按 IP / 账号限流：被限流时 fetch 直接被掐（"Failed to fetch"）
// 或返回非 JSON 的拦截页。这里做两件事：
//  1) 网络层失败最多重试 1 次（限流期间严禁狂刷，否则会把 IP 焊死在黑名单）；
//  2) 连续失败达阈值 → 打开"熔断"：停止所有轮询与重试，只留一个很慢的探活，
//     让服务端限流/封禁自行冷却，恢复后自动关闭熔断。
const NET_FRIENDLY = '网络繁忙，请稍后重试（若仍失败可刷新页面）';

// 熔断状态（circuit breaker）：被限流时让客户端彻底安静，避免越刷越死
let netFailures = 0;        // 连续网络层失败计数
let circuitOpen = false;     // 熔断是否打开
let circuitTimer = null;     // 探活计时器
let circuitBackoff = 60000; // 探活间隔（毫秒），恢复前指数退避

function showCircuitBanner() {
  let el = document.getElementById('circuit-banner');
  if (!el) {
    el = document.createElement('div');
    el.id = 'circuit-banner';
    el.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:9999;background:#b23333;color:#fff;' +
      'text-align:center;padding:8px 12px;font-size:13px;box-shadow:0 -2px 8px rgba(0,0,0,.3)';
    document.body.appendChild(el);
  }
  el.textContent = '⚠ 检测到服务器限流/连接受限，已暂停自动刷新，正在后台缓慢探活…（你仍可手动操作）';
}
function hideCircuitBanner() {
  const el = document.getElementById('circuit-banner');
  if (el) el.remove();
}
function openCircuit() {
  if (circuitOpen) return;
  circuitOpen = true;
  circuitBackoff = 60000;
  showCircuitBanner();
  // 启动缓慢探活：被限流时只发极少量请求，让封禁冷却
  if (circuitTimer) clearTimeout(circuitTimer);
  circuitTimer = setTimeout(probeServer, circuitBackoff);
}
function closeCircuit() {
  if (!circuitOpen) return;
  circuitOpen = false;
  netFailures = 0;
  if (circuitTimer) { clearTimeout(circuitTimer); circuitTimer = null; }
  circuitBackoff = 60000;
  hideCircuitBanner();
}
async function probeServer() {
  // 用最轻量的公开只读接口探活，且 noRetry 不重试，避免增加负载
  try {
    await api('publications', { noRetry: true });
    closeCircuit();           // 探活成功 → 恢复，前端重新活跃
  } catch (e) {
    // 仍被限流：指数退避，最长 8 分钟探一次（极少负载）
    circuitBackoff = Math.min(circuitBackoff * 2, 8 * 60 * 1000);
    circuitTimer = setTimeout(probeServer, circuitBackoff);
  }
}

async function api(action, options = {}) {
  const q = new URLSearchParams(options.query || {});
  // 安全：token 不再进 URL / Authorization 头，改由浏览器自动携带 HttpOnly+Secure+SameSite 的认证 Cookie
  const url = `${API}?action=${action}&${q.toString()}`;
  const fetchOpts = { method: options.method || 'GET', credentials: 'same-origin', cache: 'no-store' };
  const headers = { 'X-Requested-With': 'XMLHttpRequest' }; // 同源 XHR 自动带此头，配合后端 CSRF 校验
  if (options.body) {
    headers['Content-Type'] = 'application/json';
    fetchOpts.body = JSON.stringify(options.body);
  }
  fetchOpts.headers = headers;

  // 熔断打开时：用户手动操作（登录/发帖）仍尝试一次，但不再内部重试（避免雪崩）；
  // 探活由 probeServer 单独负责，轮询函数见到 circuitOpen 会直接 return。
  const noRetry = options.noRetry || circuitOpen;
  const MAX = noRetry ? 1 : 2;            // 正常时 1 次重试，限流时 0 次重试
  const BACKOFF = [1500, 4000];
  let lastErr = null;
  for (let attempt = 0; attempt < MAX; attempt++) {
    try {
      const res = await fetch(url, fetchOpts);
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        // 非 JSON 响应：若服务器实为 PHP 报错（如 parse error），尽量展示原文
        if (attempt < MAX - 1) { await new Promise(s => setTimeout(s, BACKOFF[attempt])); continue; }
        const raw = (text || '').replace(/<[^>]+>/g, ' ').trim();
        if (raw && raw.length < 800) {
          return Promise.reject(new Error('服务器原始报错: ' + raw.slice(0, 500)));
        }
        onNetworkFailure();
        return Promise.reject(new Error(NET_FRIENDLY));
      }
      if (!res.ok || data.error || data.PHP_FATAL) {
        // 真正的接口错误（401/参数错/PHP致命）→ 立即抛出真实信息，不触发熔断
        const msg = data.PHP_FATAL
          ? `PHP致命错误: ${data.PHP_FATAL} (第${data.line}行, ${data.file})`
          : (data.error || `HTTP ${res.status}`);
        if (!circuitOpen) onNetworkSuccess();
        return Promise.reject(new Error(msg));
      }
      onNetworkSuccess();
      return data;
    } catch (err) {
      // 仅“网络层失败”（fetch reject / TypeError: Failed to fetch）会到这里
      lastErr = err;
      if (attempt < MAX - 1) { await new Promise(s => setTimeout(s, BACKOFF[attempt])); continue; }
      onNetworkFailure();
      return Promise.reject(new Error(NET_FRIENDLY));
    }
  }
  onNetworkFailure();
  return Promise.reject(new Error(NET_FRIENDLY));
}

// 连续网络层失败计数 → 达阈值打开熔断；成功则清零
function onNetworkFailure() {
  netFailures++;
  if (!circuitOpen && netFailures >= 3) openCircuit();
}
function onNetworkSuccess() {
  netFailures = 0;
  if (circuitOpen) closeCircuit();
}

// ============ 路由 ============
function navigate(hash) {
  window.location.hash = hash;
}

function getRoute() {
  const hash = window.location.hash.slice(1) || '/';
  const parts = hash.split('/').filter(Boolean);
  // 解码每段，修复中文用户名/slug 在 hash 中被编码后导致的双重编码问题
  return parts.map(p => { try { return decodeURIComponent(p); } catch (e) { return p; } });
}

async function router() {
  if (msgTimer) { clearInterval(msgTimer); msgTimer = null; }
  if (chatDockTimer) { clearInterval(chatDockTimer); chatDockTimer = null; }
  curPubMods = {};   // 每次路由重置刊物级模块覆盖，非刊物页回退平台级
  const parts = getRoute();
  const main = document.getElementById('app');

  try {
    if (parts.length === 0) {
      await renderHome(main);
    } else if (parts[0] === 'login') {
      await renderLogin(main);
    } else if (parts[0] === 'register') {
      await renderRegister(main);
    } else if (parts[0] === 'create') {
      await renderCreate(main);
    } else if (parts[0] === 'rules') {
      await renderRules(main);
    } else if (parts[0] === 'about') {
      await renderAbout(main);
    } else if (parts[0] === 'invite' && parts[1]) {
      await handleInvite(main, parts[1]);
    } else if (parts[0] === 'admin') {
      await renderAdmin(main);
    } else if (parts[0] === 'federation') {
      await renderFederation(main);
    } else if (parts[0] === 'pub' && parts.length >= 2) {
      const slug = parts[1];
      if (parts.length === 2) {
        await renderPub(main, slug);
      } else if (parts[2] === 'submit') {
        await renderSubmit(main, slug);
      } else if (parts[2] === 'manage') {
        await renderManage(main, slug);
      } else if (parts[2] === 'article' && parts[3]) {
        await renderArticle(main, slug, parts[3]);
      } else if (parts[2] === 'issue' && parts[3]) {
        await renderIssue(main, slug, parts[3]);
      } else if (parts[2] === 'feed') {
        window.location.href = `${API}?action=feed&slug=${slug}`;
      } else if (parts[2] === 'community') {
        await renderPubServer(main, slug);
      } else {
        await renderPub(main, slug);
      }
    } else if (parts[0] === 'verify' && parts[1]) {
      await renderVerify(main, parts[1]);
    } else if (parts[0] === 'profile' && parts[1]) {
      await renderProfile(main, parts[1]);
    } else if (parts[0] === 'messages') {
      await renderMessagesCenter(main, parts[1]);
    } else {
      main.innerHTML = '<div class="empty"><div class="icon">404</div><p>页面不存在</p><p class="mt-2"><a href="#/">返回首页</a></p></div>';
    }
  } catch (err) {
    const isNet = (err.message || '').includes('网络繁忙');
    const msg = escapeHtml(err.message || '加载失败');
    main.innerHTML = `<div class="alert alert-error">${msg}` +
      (isNet ? ` <button class="btn btn-sm btn-primary mt-1" onclick="router()">点击重试</button>` : '') +
      `</div>`;
  }

  window.scrollTo(0, 0);
}

// ============ 初始化 ============
// 模块 / 插件 / 扩展：平台级开关 gModules + 当前刊物级覆盖 curPubMods
let gModules = {};
let curPubMods = {};
function modOn(mkey) {
  if (curPubMods && Object.prototype.hasOwnProperty.call(curPubMods, mkey)) return !!curPubMods[mkey];
  return !!gModules[mkey];
}

async function init() {
  loadAccounts();
  try {
    const data = await api('me');
    if (data.user) {
      currentUser = data.user;
      if (data.token) { authToken = data.token; upsertAccount(data.token, data.user); }
    } else {
      currentUser = null;
      authToken = null; saveAccounts();
    }
  } catch {
    currentUser = null;
    authToken = null; saveAccounts();
  }
  if (currentUser) {
    try { const u = await api('inbox_unread'); unreadCount = (u.total || 0); } catch (e) { unreadCount = 0; }
  } else {
    unreadCount = 0;
  }
  // 加载平台级模块开关
  try { const m = await api('modules'); (m.modules || []).forEach(x => { gModules[x.mkey] = !!x.enabled; }); } catch (e) {}
  renderNavbar();
  // 全局轮询未读数（每 30 秒）：私信未读 + 通知未读 合并；增加时弹新消息提示
  lastUnread = unreadCount;
  setInterval(async () => {
    if (circuitOpen || !currentUser || document.hidden) return;
    try {
      const u = await api('inbox_unread');
      const n = (u.total || 0);
      if (n > lastUnread) chatToast(`💬 收到 ${n - lastUnread} 条新消息`);
      lastUnread = n;
      unreadCount = n;
    } catch (e) {}
    updateChatLauncher();
  }, 30000);
  await router();
}

window.addEventListener('hashchange', async () => {
  renderNavbar();
  await router();
});

// ============ Navbar ============
function renderNavbar() {
  const nav = document.getElementById('navbar');
  if (currentUser) {
    nav.innerHTML = `
      <div class="navbar">
        <div class="navbar-inner">
          <a class="navbar-brand" href="#/"><span class="logo">📚</span> 万刊网</a>
          <div class="navbar-links">
            <a href="#/">首页</a>
            <a href="#/create">创建刊物</a>
            <a href="#/rules">平台规则</a>
            <a href="#/about">关于</a>
            <a href="javascript:void(0)" onclick="openSupport()">💖 赞助</a>
            ${currentUser ? `<a href="#/federation">蜂巢</a>` : ''}
            ${isPlatformAdmin() ? `<a href="#/admin">控制平台</a>` : ''}
            ${modOn('feedback') ? `<a href="javascript:void(0)" onclick="openFeedback()">反馈</a>` : ''}
            ${modOn('dm') ? `<a href="#/messages">消息${unreadCount ? `<span class="nav-badge">${unreadCount}</span>` : ''}</a>` : ''}
            <div class="navbar-user">
              <a href="#/profile/${currentUser.uid || encodeURIComponent(currentUser.username)}">
                ${currentUser.avatar ? `<img class="avatar" src="${currentUser.avatar}" alt="">` : `<div class="avatar">${currentUser.username[0].toUpperCase()}</div>`}
              </a>
              ${roleBadges(currentUser.platform_roles)}
              ${currentUser.verified ? '' : '<span class="badge badge-unverified" title="邮箱未验证">未验证</span>'}
              <button class="btn btn-sm" onclick="switchAccount()">切换账号</button>
              <button class="btn btn-sm" onclick="logout()">退出</button>
            </div>
          </div>
        </div>
      </div>`;
  } else {
    nav.innerHTML = `
      <div class="navbar">
        <div class="navbar-inner">
          <a class="navbar-brand" href="#/"><span class="logo">📚</span> 万刊网</a>
          <div class="navbar-links">
            <a href="#/">首页</a>
            <a href="#/rules">平台规则</a>
            <a href="#/about">关于</a>
            <a href="javascript:void(0)" onclick="openSupport()">💖 赞助</a>
            <a href="#/login">登录</a>
            <a href="#/register"><button class="btn btn-sm btn-primary">注册</button></a>
          </div>
        </div>
      </div>`;
  }
  ensureChatLauncher();
  try { if (localStorage.getItem('ad_dismissed') === '1') { const a = document.getElementById('top-ad'); if (a) a.style.display = 'none'; } } catch (e) {}
}

// 切换/登录/登出后调用：彻底重置聊天与未读状态，避免旧账号数据串到新账号
async function resetSessionState() {
  chatPeer = null;
  if (chatDockTimer) { clearInterval(chatDockTimer); chatDockTimer = null; }
  const dock = document.getElementById('chat-dock');
  if (dock) dock.remove();           // 移除旧 dock，下次打开会以新「我」重建
  chatDockOpen = false;
  unreadCount = 0;
  if (currentUser) {
    try { const u = await api('inbox_unread'); unreadCount = (u.total || 0); } catch (e) { unreadCount = 0; }
  }
  lastUnread = unreadCount;          // 对齐新账号基准，防止新消息 toast 算错
  updateChatLauncher();
}

async function logout() {
  try { await api('logout', { method: 'POST' }); } catch (e) {}
  removeActiveAccount();
  if (authToken) {
    try {
      const d = await api('me');
      currentUser = d.user;
    } catch { currentUser = null; authToken = null; saveAccounts(); }
  } else {
    currentUser = null;
  }
  await resetSessionState();
  renderNavbar();
  await router();
}

// ============ 账号切换器（B站式） ============
function switchAccount() { openAccountSwitcher(); }

function openAccountSwitcher() {
  const ex = document.getElementById('account-overlay');
  if (ex) ex.remove();
  let html = `<div class="modal-overlay" id="account-overlay" onclick="if(event.target===this)closeAccountSwitcher()">
    <div class="account-switcher">
      <div class="as-header">切换账号</div>`;
  if (!accounts.length) {
    html += `<div class="as-empty text-muted">暂无已保存的账号</div>`;
  } else {
    html += accounts.map(a => `
      <div class="account-item ${a.user.username === (currentUser && currentUser.username) ? 'active' : ''}" onclick="switchToAccount('${a.token}')">
        ${a.user.avatar ? `<img class="ai-avatar" src="${a.user.avatar}" alt="">` : `<div class="ai-avatar">${escapeHtml((a.user.username[0] || '?').toUpperCase())}</div>`}
        <div class="ai-info">
          <div class="ai-name">${escapeHtml(a.user.username)} ${a.user.username === (currentUser && currentUser.username) ? '<span class="badge badge-ok">当前</span>' : ''}</div>
          <div class="ai-email text-muted">${escapeHtml(a.user.email || '')}</div>
        </div>
      </div>`).join('');
  }
  html += `
      <button class="btn btn-block" onclick="addAccount()">+ 添加账号</button>
      <button class="btn btn-block btn-ghost" onclick="closeAccountSwitcher()">取消</button>
    </div>
  </div>`;
  document.body.insertAdjacentHTML('beforeend', html);
}

function closeAccountSwitcher() {
  const o = document.getElementById('account-overlay');
  if (o) o.remove();
}

async function switchToAccount(token) {
  const acc = accounts.find(a => a.token === token);
  if (!acc) return;
  if (currentUser && acc.user.username === currentUser.username) { closeAccountSwitcher(); return; }
  closeAccountSwitcher();
  // 一次请求完成切换：服务端同步 Cookie + Session，并直接返回切换后的用户对象
  let me = null;
  try {
    const d = await api('switch_account', { method: 'POST', body: { token } });
    if (d && d.user) me = d.user;
  } catch (e) {
    // token 已失效（如该账号在别处重新登录导致 token 轮换）→ 从列表移除并提示重登
    accounts = accounts.filter(a => a.token !== token);
    saveAccounts();
    toast((e && e.message) || '该账号登录状态已过期，请重新登录', 'error');
    renderNavbar();
    return;
  }
  if (!me) return;
  authToken = token;
  currentUser = me;
  upsertAccount(token, me);   // 内部会把 authToken 设为该 token 并持久化
  // 重置聊天/未读状态并强制重渲染当前页面
  await resetSessionState();
  renderNavbar();
  await router();
}

function addAccount() {
  closeAccountSwitcher();
  addingAccount = true;
  navigate('/login');
}

// ============ 页面：首页 ============
async function renderHome(main) {
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载中...</p></div>';

  const [pubData, stats] = await Promise.all([
    api('publications'),
    api('stats').catch(() => ({ publications: 0, articles: 0, published: 0, users: 0, issues: 0 }))
  ]);

  const pubs = pubData.publications || [];
  let pubCards = pubs.length ? pubs.map(p => `
    <div class="card">
      <div class="card-title"><a href="#/pub/${p.slug}">${escapeHtml(p.name)}</a></div>
      <div class="card-meta">
        <span>主编: ${escapeHtml(p.owner_name)}</span>
        <span>${p.article_count} 篇投稿</span>
        <span>${p.published_count} 篇已发</span>
        <span>${formatDate(p.created_at)} 创建</span>
      </div>
      ${p.description ? `<div class="card-desc">${escapeHtml(p.description)}</div>` : ''}
      ${p.tags ? `<div class="mt-1">${p.tags.split(',').map(t => `<span class="tag">${escapeHtml(t.trim())}</span>`).join(' ')}</div>` : ''}
    </div>`).join('') : `
    <div class="empty" style="grid-column:1/-1">
      <div class="icon">📝</div>
      <p>还没有刊物，成为第一个创建者吧！</p>
      <a href="#/create"><button class="btn btn-primary mt-2">创建刊物</button></a>
    </div>`;

  main.innerHTML = `
    <div class="hero">
      <h1>万刊网</h1>
      <p>独立的刊物发布平台 — 任何人都可以创建刊物，任何人都可以投稿，不依附于任何组织。</p>
      <div class="flex" style="justify-content:center;gap:12px">
        <a href="#/create"><button class="btn btn-primary btn-lg">创建刊物</button></a>
        <a href="#/rules"><button class="btn btn-lg">平台规则</button></a>
      </div>
      <div class="hero-stats">
        <div class="hero-stat"><div class="num">${stats.publications}</div><div class="label">刊物</div></div>
        <div class="hero-stat"><div class="num">${stats.articles}</div><div class="label">投稿</div></div>
        <div class="hero-stat"><div class="num">${stats.published}</div><div class="label">已发表</div></div>
        <div class="hero-stat"><div class="num">${stats.users}</div><div class="label">用户</div></div>
        <div class="hero-stat"><div class="num">${stats.issues}</div><div class="label">期刊</div></div>
      </div>
    </div>
    <div class="container">
      <div class="section">
        <div class="section-header">
          <h2>全部刊物</h2>
          <a href="#/create">+ 创建新刊物</a>
        </div>
        <div class="grid grid-2">${pubCards}</div>
      </div>
      <div class="section">
        <div class="section-header"><h2>平台机制</h2></div>
        <div class="grid grid-2">
          <div class="card">
            <div class="card-title">📝 投稿</div>
            <div class="card-desc">任何注册用户都可以向任意刊物投稿，支持 Markdown 格式编写正文，提交后进入待审状态。</div>
          </div>
          <div class="card">
            <div class="card-title">🔍 审稿</div>
            <div class="card-desc">刊物主编对投稿进行审阅，可标记为：待审、返修、录用、拒稿。审稿意见公开透明。</div>
          </div>
          <div class="card">
            <div class="card-title">📖 发刊</div>
            <div class="card-desc">主编将录用的文章编入期刊发表，每期刊包含多篇已录用的文章，发表后对全站可见。</div>
          </div>
          <div class="card">
            <div class="card-title">📡 订阅</div>
            <div class="card-desc">注册用户可订阅感兴趣的刊物，每个刊物提供 RSS 订阅源，及时获取最新发表的文章。</div>
          </div>
        </div>
      </div>
    </div>`;
}

// ============ 页面：登录 ============
async function renderLogin(main) {
  if (currentUser && !addingAccount) { navigate('/'); return; }
  main.innerHTML = `
    <div class="container" style="max-width:400px">
      <div class="card">
        <h2 style="margin-bottom:16px">登录</h2>
        <form onsubmit="handleLogin(event)">
          <div class="form-group">
            <label>邮箱</label>
            <input type="email" id="login-email" required placeholder="请输入注册邮箱">
          </div>
          <div class="form-group">
            <label>密码</label>
            <input type="password" id="login-password" required>
          </div>
          <div id="login-error"></div>
          <button type="submit" class="btn btn-primary" style="width:100%">登录</button>
        </form>
        <p class="text-center text-muted mt-2">还没有账号？<a href="#/register">注册</a></p>
      </div>
    </div>`;
}

async function handleLogin(e) {
  e.preventDefault();
  const email = document.getElementById('login-email').value;
  const password = document.getElementById('login-password').value;
  try {
    const data = await api('login', { method: 'POST', body: { email, password } });
    currentUser = data.user;
    if (data.token) { authToken = data.token; upsertAccount(data.token, data.user); }
    addingAccount = false;
    await resetSessionState();
    renderNavbar();
    await router();
  } catch (err) {
    document.getElementById('login-error').innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
  }
}

// ============ 页面：注册 ============
async function renderRegister(main) {
  if (currentUser) { navigate('/'); return; }
  main.innerHTML = `
    <div class="container" style="max-width:400px">
      <div class="card">
        <h2 style="margin-bottom:16px">注册</h2>
        <form onsubmit="handleRegister(event)">
          <div class="form-group">
            <label>用户名</label>
            <input type="text" id="reg-username" required minlength="2">
            <div class="form-hint">至少2个字符，登录时使用邮箱</div>
          </div>
          <div class="form-group">
            <label>邮箱 *</label>
            <input type="email" id="reg-email" required placeholder="必填，用于登录">
            <div class="form-hint">邮箱将用于登录，请填写有效邮箱</div>
          </div>
          <div class="form-group">
            <label>密码</label>
            <input type="password" id="reg-password" required minlength="6">
            <div class="form-hint">至少6个字符</div>
          </div>
          <div id="reg-error"></div>
          <button type="submit" class="btn btn-primary" style="width:100%">注册</button>
        </form>
        <p class="text-center text-muted mt-2">已有账号？<a href="#/login">登录</a></p>
      </div>
    </div>`;
}

async function handleRegister(e) {
  e.preventDefault();
  const username = document.getElementById('reg-username').value;
  const password = document.getElementById('reg-password').value;
  const email = document.getElementById('reg-email').value;
  try {
    const data = await api('register', { method: 'POST', body: { username, password, email } });
    currentUser = data.user;
    if (data.token) { authToken = data.token; upsertAccount(data.token, data.user); }
    addingAccount = false;
    await resetSessionState();
    renderNavbar();
    if (data.needsVerify) {
      showVerifyNotice(main, data.verifyUrl);
    } else {
      await router();
    }
  } catch (err) {
    document.getElementById('reg-error').innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
  }
}

// ============ 页面：创建刊物 ============
async function renderCreate(main) {
  if (!currentUser) { navigate('/login'); return; }
  main.innerHTML = `
    <div class="container" style="max-width:600px">
      <h2 style="margin-bottom:16px">创建刊物</h2>
      <div class="card">
        <form onsubmit="handleCreate(event)">
          <div class="form-group">
            <label>刊物名称 *</label>
            <input type="text" id="pub-name" required minlength="2" placeholder="如：文学评论">
          </div>
          <div class="form-group">
            <label>刊物简介</label>
            <textarea id="pub-desc" placeholder="描述刊物的定位、方向和范围..."></textarea>
          </div>
          <div class="form-group">
            <label>标签（逗号分隔）</label>
            <input type="text" id="pub-tags" placeholder="如：文学, 评论, 当代">
          </div>
          <div id="create-error"></div>
          <button type="submit" class="btn btn-primary">创建刊物</button>
        </form>
      </div>
    </div>`;
}

async function handleCreate(e) {
  e.preventDefault();
  const name = document.getElementById('pub-name').value;
  const description = document.getElementById('pub-desc').value;
  const tags = document.getElementById('pub-tags').value;
  try {
    const data = await api('publications', { method: 'POST', body: { name, description, tags } });
    navigate('/pub/' + data.publication.slug);
  } catch (err) {
    document.getElementById('create-error').innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
  }
}

// ============ 页面：刊物主页 ============
async function renderPub(main, slug) {
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载中...</p></div>';
  const data = await api('publication', { query: { slug } });
  const pub = data.publication;
  const articles = data.articles || [];
  const issues = data.issues || [];
  const isOwner = currentUser && currentUser.id == pub.owner_id;
  const myPerms = data.my_perms || 0;
  const myRoles = data.my_roles || [];
  const myNick = data.my_nickname || '';
  const canManage = isOwner || hasPerm(myPerms, PERM.MANAGE_PUB);
  const canMember = canManage || hasPerm(myPerms, PERM.MANAGE_MEMBERS) || hasPerm(myPerms, PERM.MANAGE_ROLES);
  // 读取当前用户是否已订阅该刊物（后端 subscriptions 返回订阅列表，按 slug 判定）
  let subbed = false;
  if (currentUser) {
    try {
      const subsRes = await api('subscriptions', {});
      subbed = (subsRes.subscriptions || []).some(s => s.slug === slug);
    } catch (e) { subbed = false; }
  }
  // 刊物级模块覆盖（控制投稿/RSS/服务器等）
  try { const ms = await api('modules', { query: { slug } }); curPubMods = (ms.pub_settings) || {}; } catch (e) { curPubMods = {}; }

  const published = articles.filter(a => a.status === 'published');
  const pending = articles.filter(a => a.status === 'pending');
  const accepted = articles.filter(a => a.status === 'accepted');
  const revised = articles.filter(a => a.status === 'revise');
  const rejected = articles.filter(a => a.status === 'rejected');

  let issueHtml = issues.length ? issues.map(i => `
    <div class="card">
      <div class="card-title"><a href="#/pub/${slug}/issue/${i.issue_number}">第 ${i.issue_number} 期</a></div>
      ${i.title ? `<div class="card-desc">${escapeHtml(i.title)}</div>` : ''}
      <div class="card-meta"><span>${formatDate(i.published_at)} 发刊</span></div>
    </div>`).join('') : '<p class="text-muted">暂无期刊</p>';

  let articleHtml = published.length ? published.map(a => `
    <div class="card">
      <div class="card-title"><a href="#/pub/${slug}/article/${a.id}">${escapeHtml(a.title)}</a></div>
      <div class="card-meta">
        <span>作者: ${escapeHtml(a.author)}</span>
        <span>${formatDate(a.created_at)}</span>
        ${a.tags ? a.tags.split(',').map(t => `<span class="tag">${escapeHtml(t.trim())}</span>`).join('') : ''}
      </div>
      ${a.abstract ? `<div class="card-desc">${escapeHtml(a.abstract)}</div>` : ''}
    </div>`).join('') : '<p class="text-muted">暂无已发表文章</p>';

  main.innerHTML = `
    ${pub.cover ? `<div class="pub-cover" style="background-image:url('${pub.cover}')"></div>` : ''}
    <div class="container">
      <div class="section">
        <div class="flex-between mb-2">
          <div class="flex gap-1" style="align-items:center">
            ${pub.avatar ? `<img class="pub-avatar-lg" src="${pub.avatar}" alt="">` : ''}
            <h1>${escapeHtml(pub.name)}</h1>
          </div>
          <div class="flex gap-1" style="flex-wrap:wrap">
            ${modOn('rss') ? `<a href="${API}?action=feed&slug=${slug}"><button class="btn btn-sm">RSS</button></a>` : ''}
            ${currentUser ? `<button class="btn btn-sm ${subbed ? '' : 'btn-primary'}" id="sub-btn" data-subbed="${subbed ? '1' : '0'}" onclick="toggleSub('${slug}')">${subbed ? '✓ 已订阅' : '订阅'}</button>` : ''}
            ${currentUser && modOn('submit') ? `<a href="#/pub/${slug}/submit"><button class="btn btn-sm btn-primary">投稿</button></a>` : ''}
            ${canManage ? `<a href="#/pub/${slug}/manage"><button class="btn btn-sm">管理</button></a>` : ''}
            ${currentUser && modOn('server') && (myRoles.length || isOwner) ? `<a href="#/pub/${slug}/community"><button class="btn btn-sm btn-primary">🏘️ 进入社区</button></a>` : ''}
            ${(currentUser && modOn('server') && !(myRoles.length || isOwner)) ? `<button class="btn btn-sm btn-primary" onclick="openCommunityApply('${slug}')">🏘️ 申请加入社区</button>` : ''}
            ${canManage ? `<label class="btn btn-sm">背景图<input type="file" id="pub-cover-input" accept="image/*" hidden onchange="uploadPubCover(event, ${pub.id})"></label>` : ''}
            ${canManage ? `<label class="btn btn-sm">刊物头像<input type="file" id="pub-avatar-input" accept="image/*" hidden onchange="uploadPubAvatar(event, ${pub.id})"></label>` : ''}
          </div>
        </div>
        ${pub.description ? `<p class="text-muted mb-2">${escapeHtml(pub.description)}</p>` : ''}
        <div class="card-meta">
          <span>主编: <a href="#/profile/${pub.owner_uid || encodeURIComponent(pub.owner_name)}">${escapeHtml(pub.owner_name)}</a></span>
          <span>${formatDate(pub.created_at)} 创建</span>
          ${pub.tags ? pub.tags.split(',').map(t => `<span class="tag">${escapeHtml(t.trim())}</span>`).join('') : ''}
        </div>
        ${currentUser && (myRoles.length || canMember) ? `
        <div class="my-roles mt-2">
          <span class="text-muted">我的刊物身份：</span>
          ${myRoles.length ? roleBadges(myRoles) : '<span class="text-muted">访客</span>'}
          <span class="text-muted ml-1">刊物权限ID: ${myPerms}</span>
          ${canMember ? `<a href="#/pub/${slug}/manage" class="ml-1">管理成员与身份组 →</a>` : ''}
        </div>
        ${(isOwner || myRoles.length) ? `
        <div class="my-roles mt-1">
          <span class="text-muted">刊物内昵称：</span>
          <strong>${myNick ? escapeHtml(myNick) : '未设置'}</strong>
          <a href="javascript:void(0)" class="ml-1" onclick="editPubNickname('${slug}')">修改</a>
        </div>` : ''}` : ''}
      </div>

      ${isOwner && (pending.length > 0 || accepted.length > 0) ? `
      <div class="alert alert-warning">
        <strong>主编提醒：</strong>
        ${pending.length} 篇待审、${accepted.length} 篇待发刊
        <a href="#/pub/${slug}/manage">前往管理 →</a>
      </div>` : ''}

      <div class="section">
        <div class="section-header"><h2>期刊</h2></div>
        ${issueHtml}
      </div>

      <div class="section">
        <div class="section-header"><h2>已发表文章</h2></div>
        ${articleHtml}
      </div>
    </div>`;
}

async function toggleSub(slug) {
  const btn = document.getElementById('sub-btn');
  const isSub = btn && btn.dataset.subbed === '1';
  try {
    // 已订阅则取消，未订阅则订阅（后端均为幂等安全操作）
    await api(isSub ? 'unsubscribe' : 'subscribe', { method: 'POST', body: { pubSlug: slug } });
    if (btn) {
      btn.dataset.subbed = isSub ? '0' : '1';
      btn.classList.toggle('btn-primary', isSub); // 取消后变主色「订阅」，订阅后去主色
      btn.textContent = isSub ? '订阅' : '✓ 已订阅';
    }
    toast(isSub ? '已取消订阅' : '订阅成功！');
  } catch (err) {
    toast(err.message || '操作失败', 'error');
  }
}

// ============ 页面：投稿 ============
async function renderSubmit(main, slug) {
  if (!currentUser) { navigate('/login'); return; }
  // 刊物级「投稿」模块关闭则不可投稿
  try { const ms = await api('modules', { query: { slug } }); curPubMods = (ms.pub_settings) || {}; } catch (e) { curPubMods = {}; }
  if (!modOn('submit')) {
    main.innerHTML = `<div class="container"><div class="empty"><div class="icon">🚫</div><p>该刊物已关闭「投稿」功能</p><p class="mt-2"><a href="#/pub/${slug}">返回刊物</a></p></div></div>`;
    return;
  }
  main.innerHTML = `
    <div class="container">
      <h2 style="margin-bottom:16px">投稿</h2>
      <div class="card">
        <form onsubmit="handleSubmit(event, '${slug}')">
          <div class="form-group">
            <label>文章标题 *</label>
            <input type="text" id="sub-title" required minlength="2">
          </div>
          <div class="form-group">
            <label>作者署名</label>
            <input type="text" id="sub-author" value="${escapeHtml(currentUser.username)}">
          </div>
          <div class="form-group">
            <label>联系邮箱</label>
            <input type="email" id="sub-email" value="${escapeHtml(currentUser.email || '')}">
          </div>
          <div class="form-group">
            <label>摘要</label>
            <textarea id="sub-abstract" rows="3" placeholder="简短描述文章内容..."></textarea>
          </div>
          <div class="form-group">
            <label>标签（逗号分隔）</label>
            <input type="text" id="sub-tags" placeholder="如：评论, 当代文学">
          </div>
          <div class="form-group">
            <label>正文（Markdown 格式）*</label>
            <div class="editor-wrap">
              <textarea id="sub-body" required minlength="10" placeholder="在此输入 Markdown 正文..." style="min-height:400px;font-family:'SF Mono',Consolas,monospace;font-size:13px;line-height:1.6" oninput="updatePreview()"></textarea>
              <div class="editor-preview md-body" id="preview"></div>
            </div>
            <div class="form-hint">支持 Markdown 语法：# 标题、**粗体**、*斜体*、> 引用、- 列表、\`代码\`、[链接](url)</div>
          </div>
          <div id="submit-error"></div>
          <button type="submit" class="btn btn-primary">提交投稿</button>
          <a href="#/pub/${slug}"><button type="button" class="btn">取消</button></a>
        </form>
      </div>
    </div>`;
}

function updatePreview() {
  const md = document.getElementById('sub-body').value;
  document.getElementById('preview').innerHTML = Markdown.render(md);
}

async function handleSubmit(e, slug) {
  e.preventDefault();
  try {
    const data = await api('submit', { method: 'POST', body: {
      pubSlug: slug,
      title: document.getElementById('sub-title').value,
      author: document.getElementById('sub-author').value,
      authorEmail: document.getElementById('sub-email').value,
      abstract: document.getElementById('sub-abstract').value,
      tags: document.getElementById('sub-tags').value,
      body: document.getElementById('sub-body').value
    }});
    toast('投稿成功！等待主编审阅。');
    navigate('/pub/' + slug);
  } catch (err) {
    document.getElementById('submit-error').innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
  }
}

// ============ 页面：管理审稿 ============
async function renderManage(main, slug) {
  if (!currentUser) { navigate('/login'); return; }
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载中...</p></div>';
  const [dPub, dRoles, dMods] = await Promise.all([
    api('publication', { query: { slug } }),
    api('pub_roles', { query: { slug } }),
    api('modules', { query: { slug } }).catch(() => ({ modules: [], pub_settings: {} }))
  ]);
  const pub = dPub.publication;
  const articles = dPub.articles || [];
  const roles = dRoles.roles || [];
  const members = dRoles.members || [];
  const myPerms = dRoles.my_perms || 0;
  const canManage = pub.owner_id == currentUser.id || hasPerm(myPerms, PERM.MANAGE_PUB);
  const canMember = canManage || hasPerm(myPerms, PERM.MANAGE_MEMBERS) || hasPerm(myPerms, PERM.MANAGE_ROLES);
  const canReview = canManage || hasPerm(myPerms, PERM.MANAGE_ARTICLES) || hasPerm(myPerms, PERM.REVIEW);
  if (!canMember) {
    main.innerHTML = '<div class="alert alert-error">无权限管理此刊物</div>';
    return;
  }
  const pubId = pub.id;
  curPubSlug = slug;
  // 刊物级模块覆盖
  const pubMods = (dMods.pub_settings) || {};
  curPubMods = pubMods;
  const pubScopedMods = (dMods.modules || []).filter(m => m.scope === 'pub');

  const byStatus = {
    pending: articles.filter(a => a.status === 'pending'),
    revise: articles.filter(a => a.status === 'revise'),
    accepted: articles.filter(a => a.status === 'accepted'),
    rejected: articles.filter(a => a.status === 'rejected'),
    published: articles.filter(a => a.status === 'published')
  };

  function articleCard(a) {
    return `
      <div class="card">
        <div class="card-title">${escapeHtml(a.title)}</div>
        <div class="card-meta">
          <span>作者: ${escapeHtml(a.author)}</span>
          <span>${formatDate(a.created_at)}</span>
          <span class="badge badge-${a.status}">${statusLabel(a.status)}</span>
        </div>
        ${a.abstract ? `<div class="card-desc">${escapeHtml(a.abstract)}</div>` : ''}
        ${a.review_comment ? `<div class="alert alert-info mt-1">审稿意见: ${escapeHtml(a.review_comment)}</div>` : ''}
        <div class="mt-1 flex gap-1">
          <a href="#/pub/${slug}/article/${a.id}"><button class="btn btn-sm">查看全文</button></a>
          ${canReview && (a.status === 'pending' || a.status === 'revise') ? `
            <button class="btn btn-sm" onclick="reviewAction(${a.id}, 'accept', '${slug}')">录用</button>
            <button class="btn btn-sm" onclick="reviewAction(${a.id}, 'revise', '${slug}')">返修</button>
            <button class="btn btn-sm btn-danger" onclick="reviewAction(${a.id}, 'reject', '${slug}')">拒稿</button>
          ` : ''}
        </div>
      </div>`;
  }

  main.innerHTML = `
    <div class="container">
      <div class="flex-between mb-2">
        <div><h1>🛰️ 控制服务器 — ${escapeHtml(pub.name)}</h1><p class="text-muted" style="font-size:13px">自治节点 · 刊物级治理</p></div>
        <a href="#/pub/${slug}"><button class="btn btn-sm">返回刊物</button></a>
      </div>

      ${canManage ? `
      <div class="card mt-2">
        <div class="form-group">
          <label>刊物封面 / 头像</label>
          ${pub.avatar ? `<img class="pub-avatar" src="${pub.avatar}" alt="">` : ''}
          <label class="btn btn-sm">上传封面
            <input type="file" id="pub-avatar-input" accept="image/*" hidden onchange="uploadPubAvatar(event, ${pub.id})">
          </label>
        </div>
        <div class="form-group mt-2">
          <label>刊物背景图</label>
          ${pub.cover ? `<img class="pub-cover-thumb" src="${pub.cover}" alt="">` : ''}
          <label class="btn btn-sm">上传背景图
            <input type="file" id="pub-cover-input" accept="image/*" hidden onchange="uploadPubCover(event, ${pub.id})">
          </label>
        </div>
      </div>` : ''}

      <div class="card mt-2">
        <div class="flex-between">
          <h3>刊物身份组</h3>
          ${canManage ? `<button class="btn btn-sm btn-primary" onclick="openPubRoleModal(${pubId}, null)">+ 新建身份组</button>` : ''}
        </div>
        <div class="role-list mt-1">
          ${roles.map(r => `
            <div class="role-row">
              <span class="role-badge" style="background:${safeColor(r.color)}22;color:${safeColor(r.color)};border-color:${safeColor(r.color)}55">${escapeHtml(r.name)}</span>
              <span class="text-muted">权限ID: ${r.permissions}</span>
              ${canManage ? `<button class="btn btn-sm" onclick="openPubRoleModal(${pubId}, ${r.id})">编辑</button><button class="btn btn-sm btn-danger" onclick="deletePubRole(${pubId}, ${r.id})">删除</button>` : ''}
            </div>`).join('') || '<p class="text-muted">暂无身份组</p>'}
        </div>
      </div>

      ${canManage ? `
      <div class="card mt-2">
        <h3>模块 / 插件 / 扩展（本刊物）</h3>
        <p class="text-muted" style="font-size:13px">为该刊物单独开关功能模块；未单独设置时沿用平台级默认。关闭后该刊物对应入口将被隐藏。</p>
        <div class="mod-list mt-1">
          ${pubScopedMods.length ? pubScopedMods.map(m => {
            const overridden = Object.prototype.hasOwnProperty.call(pubMods, m.mkey);
            const on = overridden ? !!pubMods[m.mkey] : !!gModules[m.mkey];
            return `
            <div class="mod-row">
              <div>
                <div class="mod-name">${escapeHtml(m.name)} <span class="text-muted" style="font-size:12px">${escapeHtml(m.mkey)}</span>${overridden ? ' <span class="badge badge-info">已覆盖</span>' : ''}</div>
                <div class="text-muted" style="font-size:12px">${escapeHtml(m.description || '')}</div>
              </div>
              <button class="btn btn-sm ${on ? '' : 'btn-ghost'}" onclick="togglePubModule('${escapeHtml(m.mkey)}')">${on ? '已启用' : '已关闭'}</button>
            </div>`;
          }).join('') : '<p class="text-muted">暂无刊物级模块</p>'}
        </div>
      </div>` : ''}

      <div class="card mt-2">
        <div class="flex-between"><h3>成员（${members.length}）</h3></div>
        <div class="member-list mt-1">
          ${members.map(m => `
            <div class="member-row">
              ${m.avatar ? `<img class="avatar-sm" src="${m.avatar}" alt="">` : `<div class="avatar-sm">${escapeHtml((m.username[0]||'?').toUpperCase())}</div>`}
              <a href="#/profile/${m.uid || encodeURIComponent(m.username)}">${escapeHtml(m.username)}</a>
              ${m.nickname ? `<span class="pub-nick">昵称: ${escapeHtml(m.nickname)}</span>` : ''}
              <span class="text-muted">UID:${m.uid}</span>
              <span class="role-badges">${((m.role_names||'').split(',').filter(Boolean)).map(n=>`<span class="role-badge" style="border-color:#9996">${escapeHtml(n)}</span>`).join('')}</span>
              ${canMember ? `<button class="btn btn-sm" onclick="openMemberModal(${pubId}, '${escapeHtml(m.username)}')">设置身份组</button><button class="btn btn-sm btn-danger" onclick="removePubMember(${pubId}, '${escapeHtml(m.username)}')">移除</button>` : ''}
            </div>`).join('') || '<p class="text-muted">暂无成员</p>'}
        </div>
        ${canMember ? `
        <form class="mt-2" onsubmit="addPubMember(event, ${pubId}, '${slug}')">
          <div class="form-group"><label>添加成员（输入用户名）</label>
            <div class="flex gap-1"><input type="text" id="new-member-name" placeholder="用户名" required><button type="submit" class="btn btn-sm btn-primary">添加</button></div>
          </div>
          <div class="form-group mt-1"><label>赋予身份组</label>
            <div class="checkbox-list">${roles.map(r=>`<label class="checkbox-item"><input type="checkbox" class="new-member-role" value="${r.id}"><span>${escapeHtml(r.name)}</span></label>`).join('')}</div>
          </div>
        </form>` : ''}
      </div>

      ${byStatus.accepted.length > 0 ? `
      <div class="section">
        <div class="section-header">
          <h2>发刊 — 录用文章 (${byStatus.accepted.length})</h2>
        </div>
        <div class="card">
          <form onsubmit="handlePublish(event, '${slug}')">
            <div class="form-group">
              <label>期刊标题</label>
              <input type="text" id="issue-title" placeholder="如：2026年7月号">
            </div>
            <div class="form-group">
              <label>期刊描述</label>
              <textarea id="issue-desc" rows="2" placeholder="本期内容简介..."></textarea>
            </div>
            <div class="form-group">
              <label>选择文章发刊</label>
              <div class="checkbox-list">
                ${byStatus.accepted.map(a => `
                  <label class="checkbox-item">
                    <input type="checkbox" value="${a.id}" name="pub-article">
                    <span>${escapeHtml(a.title)} — ${escapeHtml(a.author)}</span>
                  </label>`).join('')}
              </div>
            </div>
            <button type="submit" class="btn btn-primary">发刊</button>
          </form>
        </div>
      </div>` : ''}

      <div class="section">
        <div class="section-header"><h2>待审 (${byStatus.pending.length})</h2></div>
        ${byStatus.pending.length ? byStatus.pending.map(articleCard).join('') : '<p class="text-muted">无待审文章</p>'}
      </div>

      <div class="section">
        <div class="section-header"><h2>返修中 (${byStatus.revise.length})</h2></div>
        ${byStatus.revise.length ? byStatus.revise.map(articleCard).join('') : '<p class="text-muted">无返修文章</p>'}
      </div>

      <div class="section">
        <div class="section-header"><h2>已录用 (${byStatus.accepted.length})</h2></div>
        ${byStatus.accepted.length ? byStatus.accepted.map(articleCard).join('') : '<p class="text-muted">无录用文章</p>'}
      </div>

      <div class="section">
        <div class="section-header"><h2>已发刊 (${byStatus.published.length})</h2></div>
        ${byStatus.published.length ? byStatus.published.map(articleCard).join('') : '<p class="text-muted">无已发刊文章</p>'}
      </div>

      <div class="section">
        <div class="section-header"><h2>已拒稿 (${byStatus.rejected.length})</h2></div>
        ${byStatus.rejected.length ? byStatus.rejected.map(articleCard).join('') : '<p class="text-muted">无拒稿文章</p>'}
      </div>
    </div>`;
}

async function reviewAction(articleId, action, slug) {
  const comment = await uiPrompt('', '', { title: `请输入审稿意见（${action === 'accept' ? '录用' : action === 'revise' ? '返修' : '拒稿'}）`, multiline: true });
  if (comment === null) return;
  try {
    await api('review', { method: 'POST', body: { articleId, reviewAction: action, comment: comment || '' } });
    toast(action === 'accept' ? '已录用' : action === 'revise' ? '已标记返修' : '已拒稿');
    navigate('/pub/' + slug + '/manage');
  } catch (err) {
    toast(err.message, 'error');
  }
}

async function handlePublish(e, slug) {
  e.preventDefault();
  const title = document.getElementById('issue-title').value;
  const desc = document.getElementById('issue-desc').value;
  const ids = [...document.querySelectorAll('input[name="pub-article"]:checked')].map(c => parseInt(c.value));
  if (ids.length === 0) { toast('请至少选择一篇文章'); return; }
  try {
    const data = await api('publish', { method: 'POST', body: { pubSlug: slug, issueTitle: title, issueDescription: desc, articleIds: ids } });
    toast(`发刊成功！第 ${data.issueNumber} 期已发布。`);
    navigate('/pub/' + slug);
  } catch (err) {
    toast(err.message, 'error');
  }
}

// ============ 页面：文章详情 ============
async function renderArticle(main, slug, articleId) {
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载中...</p></div>';
  const data = await api('article', { query: { id: articleId } });
  const a = data.article;
  const comments = a.comments || [];

  main.innerHTML = `
    <div class="container">
      <div class="article-header">
        <div class="flex-between">
          <a href="#/pub/${slug}"><button class="btn btn-sm">← 返回刊物</button></a>
          <span class="badge badge-${a.status}">${statusLabel(a.status)}</span>
        </div>
        <h1 class="mt-2">${escapeHtml(a.title)}</h1>
        <div class="article-meta">
          <span>作者: ${escapeHtml(a.author)}</span>
          <span>刊物: <a href="#/pub/${slug}">${escapeHtml(a.pub_name)}</a></span>
          <span>${formatDate(a.created_at)}</span>
          ${a.reviewed_at ? `<span>审阅于 ${formatDate(a.reviewed_at)}</span>` : ''}
          ${a.tags ? a.tags.split(',').map(t => `<span class="tag">${escapeHtml(t.trim())}</span>`).join('') : ''}
        </div>
      </div>

      ${a.abstract ? `<div class="alert alert-info"><strong>摘要：</strong>${escapeHtml(a.abstract)}</div>` : ''}

      <div class="article-body md-body">
        ${Markdown.render(a.body)}
      </div>

      ${a.review_comment ? `
      <div class="section">
        <h3>审稿意见</h3>
        <div class="alert alert-info">
          <strong>${escapeHtml(a.reviewer_name || '审稿人')}:</strong> ${escapeHtml(a.review_comment)}
        </div>
      </div>` : ''}

      <div class="section">
        <h3>评论 (${comments.length})</h3>
        ${comments.length ? comments.map(c => `
          <div class="comment">
            <div class="flex-between">
              <span class="comment-author">${escapeHtml(c.author_name)}</span>
              <span class="comment-time">${formatDate(c.created_at)}</span>
            </div>
            <div class="comment-body">${escapeHtml(c.body)}</div>
          </div>`).join('') : '<p class="text-muted">暂无评论</p>'}

        ${currentUser ? `
        <form onsubmit="handleComment(event, ${articleId})" class="mt-2">
          <div class="form-group">
            <textarea id="comment-body" required minlength="1" placeholder="写下你的评论..." rows="3"></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">发表评论</button>
        </form>` : '<p class="text-muted mt-2"><a href="#/login">登录</a>后可评论</p>'}
      </div>
    </div>`;
}

async function handleComment(e, articleId) {
  e.preventDefault();
  const body = document.getElementById('comment-body').value;
  try {
    await api('comment', { method: 'POST', body: { articleId, body } });
    router(); // refresh
  } catch (err) {
    toast(err.message, 'error');
  }
}

// ============ 页面：期刊目录 ============
async function renderIssue(main, slug, issueNum) {
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载中...</p></div>';
  const data = await api('issue', { query: { slug, num: issueNum } });
  const issue = data.issue;
  const articles = issue.articles || [];

  main.innerHTML = `
    <div class="container">
      <div class="flex-between mb-2">
        <a href="#/pub/${slug}"><button class="btn btn-sm">← 返回刊物</button></a>
      </div>
      <div class="article-header">
        <h1>第 ${issue.issue_number} 期</h1>
        ${issue.title ? `<p class="text-muted">${escapeHtml(issue.title)}</p>` : ''}
        ${issue.description ? `<p class="text-muted mt-1">${escapeHtml(issue.description)}</p>` : ''}
        <div class="article-meta"><span>${formatDate(issue.published_at)} 发刊</span></div>
      </div>
      <div class="section">
        <h2>本期文章 (${articles.length})</h2>
        ${articles.length ? articles.map(a => `
          <div class="card">
            <div class="card-title"><a href="#/pub/${slug}/article/${a.id}">${escapeHtml(a.title)}</a></div>
            <div class="card-meta">
              <span>作者: ${escapeHtml(a.author)}</span>
              ${a.tags ? a.tags.split(',').map(t => `<span class="tag">${escapeHtml(t.trim())}</span>`).join('') : ''}
            </div>
            ${a.abstract ? `<div class="card-desc">${escapeHtml(a.abstract)}</div>` : ''}
          </div>`).join('') : '<p class="text-muted">本期无文章</p>'}
      </div>
    </div>`;
}

// ============ 页面：用户主页 ============
let profileTab = 'pubs';
let profilePubs = [];
let profileArticles = [];

async function renderProfile(main, ident) {
  profileTab = 'pubs';
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载中...</p></div>';
  // ident 为数字则按 uid 查询，否则按用户名（兼容旧的中文链接）
  const isNum = /^\d+$/.test(String(ident));
  const data = await api('profile', { query: isNum ? { uid: ident } : { username: ident } });
  const user = data.user;
  const pubs = data.publications || [];
  const articles = data.articles || [];
  profilePubs = pubs;
  profileArticles = articles;
  const isSelf = currentUser && user && String(currentUser.uid) === String(user.uid);
  const isAdmin = !!(currentUser && hasPerm(currentUser.platform_perms, PERM.MANAGE_USERS));
  const canDelete = isAdmin && user.id !== 1;
  const publishedCount = articles.filter(a => a.status === 'published').length;
  const created = new Date((user.created_at || '').replace(' ', 'T') + '+08:00');
  const joinDays = isNaN(created) ? '—' : Math.max(1, Math.floor((Date.now() - created.getTime()) / 86400000));

  const avatarHtml = user.avatar
    ? `<img class="profile-avatar" src="${user.avatar}" alt="">`
    : `<div class="profile-avatar">${escapeHtml((user.username[0] || '?').toUpperCase())}</div>`;

  main.innerHTML = `
    <div class="profile-page">
      <div class="profile-cover" ${user.cover ? `style="background-image:url('${user.cover}')"` : ''}></div>
      <div class="container profile-body">
        <div class="profile-card card">
          <div class="profile-top">
            <div class="profile-avatar-wrap">${avatarHtml}</div>
            <div class="profile-id">
              <div class="profile-name-row">
                <h1>${escapeHtml(user.username)}</h1>
                ${user.verified ? '<span class="badge badge-ok">已验证</span>' : '<span class="badge badge-unverified">未验证</span>'}
              </div>
              <div class="profile-sub">
                ${user.email ? escapeHtml(user.email) + ' · ' : ''}UID: ${user.uid} · ${formatDate(user.created_at)} 注册 · 已加入 ${joinDays} 天
              </div>
              <div class="profile-sub">${roleBadges(user.platform_roles)}<span class="text-muted ml-1">平台权限ID: ${(user.platform_perms || 0)}</span></div>
            </div>
            <div class="profile-actions">
              ${isSelf ? `<label class="btn btn-sm">上传头像<input type="file" id="avatar-input" accept="image/*" hidden onchange="uploadAvatar(event)"></label>` : ''}
              ${isSelf ? `<label class="btn btn-sm">上传背景<input type="file" id="cover-input" accept="image/*" hidden onchange="uploadUserCover(event)"></label>` : ''}
              ${!isSelf ? `
                <button class="btn btn-sm ${user.is_following ? '' : 'btn-primary'}" onclick="toggleFollow('${escapeHtml(user.username)}', this)">${user.is_following ? '✓ 已关注' : '＋ 关注'}</button>
                ${user.is_following ? `<button class="btn btn-sm btn-primary dm-btn" onclick="navigate('/messages/${encodeURIComponent(user.username)}')">发消息</button>` : '<span class="text-muted" style="font-size:12px">关注后可私信</span>'}
              ` : ''}
              ${isSelf && !user.verified ? `<button class="btn btn-sm" onclick="resendVerify()">重发验证</button>` : ''}
              ${canDelete ? `<button class="btn btn-sm btn-danger" onclick="deleteUser('${escapeHtml(user.username)}')">删除用户</button>` : ''}
            </div>
          </div>
          ${user.bio ? `<p class="profile-bio">${escapeHtml(user.bio)}</p>` : (isSelf ? `<p class="text-muted profile-bio">还没有个人简介，<a href="javascript:void(0)" onclick="editBio()">点击添加</a></p>` : '')}
          ${isSelf ? `<div id="bio-edit" style="display:none" class="mt-2">
              <textarea id="bio-input" class="form-control" maxlength="500" rows="3" placeholder="介绍一下你自己…">${escapeHtml(user.bio || '')}</textarea>
              <div class="mt-1">
                <button class="btn btn-sm btn-primary" onclick="saveBio()">保存</button>
                <button class="btn btn-sm" onclick="cancelBio()">取消</button>
              </div>
            </div>` : ''}
        </div>

        <div class="stat-row">
          <div class="stat-card"><div class="stat-num">${pubs.length}</div><div class="stat-label">创建的刊物</div></div>
          <div class="stat-card"><div class="stat-num">${articles.length}</div><div class="stat-label">投稿文章</div></div>
          <div class="stat-card"><div class="stat-num">${publishedCount}</div><div class="stat-label">已发刊</div></div>
          <div class="stat-card"><div class="stat-num">${joinDays}</div><div class="stat-label">加入天数</div></div>
        </div>

        <div class="tabs">
          <button class="tab ${profileTab === 'pubs' ? 'active' : ''}" onclick="switchProfileTab(event,'pubs')">创建的刊物 (${pubs.length})</button>
          <button class="tab ${profileTab === 'articles' ? 'active' : ''}" onclick="switchProfileTab(event,'articles')">投稿的文章 (${articles.length})</button>
        </div>

        <div id="profile-tab-content">
          ${profileTab === 'pubs' ? renderProfilePubs(pubs) : renderProfileArticles(articles)}
        </div>
      </div>
    </div>`;
}

function renderProfilePubs(pubs) {
  return pubs.length ? pubs.map(p => `
    <div class="card pub-card">
      ${p.avatar ? `<img class="pub-avatar" src="${p.avatar}" alt="">` : ''}
      <div class="card-title"><a href="#/pub/${p.slug}">${escapeHtml(p.name)}</a></div>
      <div class="card-meta"><span>${formatDate(p.created_at)} 创建</span></div>
      ${p.description ? `<div class="card-desc">${escapeHtml(p.description)}</div>` : ''}
    </div>`).join('') : '<p class="text-muted">暂未创建刊物</p>';
}

function renderProfileArticles(articles) {
  return articles.length ? articles.map(a => `
    <div class="card">
      <div class="card-title"><a href="#/pub/${a.pub_slug}/article/${a.id}">${escapeHtml(a.title)}</a></div>
      <div class="card-meta">
        <span>刊物: <a href="#/pub/${a.pub_slug}">${escapeHtml(a.pub_name)}</a></span>
        <span class="badge badge-${a.status}">${statusLabel(a.status)}</span>
        <span>${formatDate(a.created_at)}</span>
      </div>
    </div>`).join('') : '<p class="text-muted">暂无投稿</p>';
}

function switchProfileTab(e, tab) {
  e.preventDefault();
  profileTab = tab;
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  e.currentTarget.classList.add('active');
  const content = document.getElementById('profile-tab-content');
  if (content) content.innerHTML = tab === 'pubs' ? renderProfilePubs(profilePubs) : renderProfileArticles(profileArticles);
}

function editBio() {
  const edit = document.getElementById('bio-edit');
  if (edit) edit.style.display = 'block';
  const bioEl = document.querySelector('.profile-bio');
  if (bioEl) bioEl.style.display = 'none';
  const ta = document.getElementById('bio-input');
  if (ta) ta.focus();
}

function cancelBio() {
  const edit = document.getElementById('bio-edit');
  if (edit) edit.style.display = 'none';
  const bioEl = document.querySelector('.profile-bio');
  if (bioEl) bioEl.style.display = '';
}

async function saveBio() {
  const bio = document.getElementById('bio-input').value;
  try {
    await api('update_profile', { method: 'POST', body: { bio } });
    if (currentUser) currentUser.bio = bio;
    syncCurrent();
    const main = document.getElementById('app');
    renderProfile(main, currentUser.username);
  } catch (err) { toast(err.message, 'error'); }
}

async function uploadAvatar(e) {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = async () => {
    try {
      const data = await api('avatar', { method: 'POST', body: { target: 'user', image: reader.result } });
      currentUser.avatar = data.avatar;
      syncCurrent();
      renderNavbar();
      toast('头像已更新');
      const main = document.getElementById('app');
      if (currentUser) renderProfile(main, currentUser.username);
    } catch (err) { toast(err.message, 'error'); }
  };
  reader.readAsDataURL(file);
}

async function uploadPubAvatar(e, pubId) {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = async () => {
    try {
      await api('avatar', { method: 'POST', body: { target: 'pub', id: pubId, image: reader.result } });
      toast('刊物封面已更新');
      router();
    } catch (err) { toast(err.message, 'error'); }
  };
  reader.readAsDataURL(file);
}

async function uploadUserCover(e) {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = async () => {
    try {
      const r = await api('avatar', { method: 'POST', body: { target: 'user_cover', image: reader.result } });
      if (currentUser && r.url) currentUser.cover = r.url;
      const main = document.getElementById('app');
      if (currentUser) renderProfile(main, currentUser.username);
    } catch (err) { toast(err.message, 'error'); }
  };
  reader.readAsDataURL(file);
}

async function uploadPubCover(e, pubId) {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = async () => {
    try {
      await api('avatar', { method: 'POST', body: { target: 'pub_cover', id: pubId, image: reader.result } });
      toast('刊物背景图已更新');
      router();
    } catch (err) { toast(err.message, 'error'); }
  };
  reader.readAsDataURL(file);
}

// ============ 私信 ============
async function refreshUnread() {
  if (!currentUser) { unreadCount = 0; renderNavbar(); updateChatLauncher(); return; }
  try { const u = await api('inbox_unread'); unreadCount = (u.total || 0); }
  catch (e) { unreadCount = 0; }
  renderNavbar();
  updateChatLauncher();
}

// 头像：始终渲染「头像容器 + 初始字母兜底」，图片加载失败时自动消失露出字母，绝不显示裂图
function avatarHtml(u, cls) {
  if (!u) return '';
  const ini = escapeHtml((u.username || '?')[0].toUpperCase());
  const img = u.avatar
    ? `<img class="${cls}-img" src="${u.avatar}" alt="" onerror="this.remove()" onload="if(this.naturalWidth<=2||this.naturalHeight<=2){this.remove();var p=this.parentElement;p&&p.classList.add('av-placeholder');}">`
    : '';
  return `<div class="${cls}">${img}<span class="${cls}-ini">${ini}</span></div>`;
}
// 气泡：用全局 chatPeer（打开会话时设置），不再依赖函数参数，杜绝「发送后对方头像消失」的坑
function buildBubbles(msgs) {
  if (!msgs.length) return '<div class="empty"><p class="text-muted">还没有消息，发一条开始对话吧</p></div>';
  const peer = chatPeer;
  const myAv = avatarHtml(currentUser, 'msg-av');
  const peerAv = avatarHtml(peer, 'msg-av');
  return msgs.map(m => {
    const me = m.sender_id == currentUser.id;
    let status = '';
    if (me) status = (m.read == 1)
      ? '<span class="msg-status read">已读 ✓</span>'
      : '<span class="msg-status">送达</span>';
    const nameTag = me
      ? '<div class="msg-peer me">我</div>'
      : (peer ? `<div class="msg-peer">${escapeHtml(peer.username)}</div>` : '');
    return `
      <div class="msg ${me ? 'me' : 'them'}">
        ${me ? myAv : peerAv}
        <div class="msg-col">
          ${nameTag}
          <div class="msg-bubble">${escapeHtml(m.body)}</div>
          <div class="msg-meta">${fmtMsgTime(m.created_at)}${status}</div>
        </div>
      </div>`;
  }).join('');
}

function fmtMsgTime(s) {
  if (!s) return '';
  const m = s.match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if (!m) return s;
  const y = +m[1], mo = +m[2], d = +m[3], h = +m[4], mi = +m[5];
  const now = new Date();
  const pad = n => (n < 10 ? '0' : '') + n;
  const hm = pad(h) + ':' + pad(mi);
  if (y === now.getFullYear() && mo === (now.getMonth() + 1) && d === now.getDate()) return hm;
  const yest = new Date(now); yest.setDate(now.getDate() - 1);
  if (y === yest.getFullYear() && mo === (yest.getMonth() + 1) && d === yest.getDate()) return '昨天 ' + hm;
  if (y === now.getFullYear()) return mo + '月' + d + '日';
  return y + '-' + pad(mo) + '-' + pad(d);
}

async function resendVerify() {
  try {
    const data = await api('resend_verify', { method: 'POST' });
    if (data.already) { toast('你的邮箱已验证'); return; }
    showVerifyNotice(document.getElementById('app'), data.verifyUrl);
  } catch (err) { toast(err.message, 'error'); }
}

async function deleteUser(username) {
  if (!await uiConfirm(`确定删除用户「${username}」？其创建的刊物、文章、评论将一并删除，且不可恢复。`, { danger: true, okText: '删除' })) return;
  try {
    await api('delete_user', { method: 'POST', body: { username } });
    toast(`已删除用户 ${username}`);
    navigate('/');
  } catch (err) { toast(err.message, 'error'); }
}

async function renderVerify(main, token) {
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">验证中...</p></div>';
  try {
    const data = await api('verify', { query: { token } });
    main.innerHTML = `<div class="container" style="max-width:420px"><div class="card text-center">
      <div style="font-size:40px">✅</div>
      <h2>邮箱验证成功</h2>
      <p class="text-muted">${escapeHtml(data.username)}，你的邮箱已验证，现在可以正常投稿与发刊。</p>
      <a href="#/"><button class="btn btn-primary">进入首页</button></a>
    </div></div>`;
  } catch (err) {
    main.innerHTML = `<div class="container" style="max-width:420px"><div class="card text-center">
      <div style="font-size:40px">⚠️</div>
      <h2>验证失败</h2>
      <p class="alert alert-error">${escapeHtml(err.message)}</p>
      <a href="#/login"><button class="btn btn-primary">去登录</button></a>
    </div></div>`;
  }
}

function showVerifyNotice(main, url) {
  main.innerHTML = `<div class="container" style="max-width:480px"><div class="card">
    <h2>📧 请验证邮箱</h2>
    <p>我们已尝试向你的邮箱发送验证邮件。由于当前演示主机无法真实发信，请直接点击或复制下方验证链接完成验证：</p>
    <div class="verify-box"><a href="${url}" id="vlink">${escapeHtml(url)}</a></div>
    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('vlink').textContent)">复制链接</button>
    <p class="text-muted mt-2">验证成功后刷新页面即可正常投稿 / 发刊。</p>
  </div></div>`;
}

// ============ 页面：平台规则 ============
async function renderRules(main) {
  main.innerHTML = `
    <div class="container" style="max-width:800px">
      <h1 style="margin-bottom:24px">平台规则</h1>

      <div class="rules-section">
        <h2>📚 总则</h2>
        <p>万刊网是一个独立的刊物发布平台，任何人都可以创建刊物，任何人都可以投稿，不依附于任何组织。</p>
        <ol>
          <li>万刊网不审查、不干预各刊物的编辑方针和审稿标准</li>
          <li>各刊物独立运营，主编对刊物内容负全部责任</li>
          <li>平台保留删除违法内容、封禁恶意用户的权利</li>
          <li>使用本平台即表示同意以下全部规则</li>
        </ol>
      </div>

      <div class="rules-section">
        <h2>📝 投稿规则</h2>
        <h3>谁可以投稿</h3>
        <ul>
          <li>任何注册用户都可以向任意开放投稿的刊物投稿</li>
          <li>无需特殊资格或学术背景，以文章质量为唯一标准</li>
        </ul>
        <h3>投稿格式要求</h3>
        <ul>
          <li><strong>标题</strong>：至少2个字符，简明扼要</li>
          <li><strong>正文</strong>：至少10个字符，支持 Markdown 格式</li>
          <li><strong>摘要</strong>：选填，建议200字以内概括文章内容</li>
          <li><strong>标签</strong>：选填，逗号分隔，便于分类检索</li>
          <li><strong>作者署名</strong>：默认为注册用户名，可修改为笔名</li>
          <li><strong>联系邮箱</strong>：选填，便于主编联系返修事宜</li>
        </ul>
        <h3>投稿内容规范</h3>
        <ul>
          <li>投稿内容须为原创或已获授权，不得侵犯他人著作权</li>
          <li>不得包含违法、色情、暴力、歧视等内容</li>
          <li>不得包含商业广告、垃圾信息或恶意链接</li>
          <li>同一文章请勿一稿多投（同时投给多个刊物）</li>
        </ul>
      </div>

      <div class="rules-section">
        <h2>🔍 审稿机制</h2>
        <h3>审稿流程</h3>
        <ol>
          <li><strong>待审</strong>：投稿提交后自动进入待审状态</li>
          <li><strong>审阅</strong>：刊物主编审阅文章，可选择以下操作：</li>
          <ul>
            <li><span class="badge badge-accepted">录用</span>：文章质量达标，录用于该刊物</li>
            <li><span class="badge badge-revise">返修</span>：文章有潜力但需修改，作者修改后可重新提交</li>
            <li><span class="badge badge-rejected">拒稿</span>：文章不符合刊物要求，不予录用</li>
          </ul>
          <li><strong>审稿意见</strong>：主编审稿时须提供审稿意见，供作者参考</li>
        </ol>
        <h3>审稿原则</h3>
        <ul>
          <li>审稿过程公开透明，审稿意见对所有用户可见</li>
          <li>主编有权根据刊物定位设定录用标准，但不得基于作者身份歧视</li>
          <li>审稿无固定时限，主编应尽快处理待审稿件</li>
          <li>返修后的文章需主编重新审阅决定是否录用</li>
        </ul>
      </div>

      <div class="rules-section">
        <h2>📖 发刊规则</h2>
        <h3>发刊流程</h3>
        <ol>
          <li>主编在管理页面选择已录用的文章编入期刊</li>
          <li>每期刊可包含多篇已录用文章，须至少选择1篇</li>
          <li>发刊后文章状态变为「已发刊」，对全站公开可见</li>
          <li>期刊编号自动递增（第1期、第2期...）</li>
        </ol>
        <h3>发刊注意事项</h3>
        <ul>
          <li>每篇文章只能被编入一期期刊</li>
          <li>已发刊的文章不可撤回（但可由主编删除）</li>
          <li>发刊频率由主编自行决定，无强制要求</li>
        </ul>
      </div>

      <div class="rules-section">
        <h2>📡 订阅与 RSS</h2>
        <ul>
          <li>注册用户可订阅感兴趣的刊物，订阅后可在个人主页查看更新</li>
          <li>每个刊物自动生成 RSS 订阅源，可通过 RSS 阅读器订阅</li>
          <li>RSS 源包含最近50篇已发刊文章</li>
          <li>订阅关系仅平台内部可见，不对外公开</li>
        </ul>
      </div>

      <div class="rules-section">
        <h2>🏢 创刊规则</h2>
        <ul>
          <li>任何注册用户都可以创建刊物，无需审批</li>
          <li>刊名不可重复，系统自动生成唯一标识（slug）</li>
          <li>刊物创建者即为该刊物的主编，拥有审稿和发刊权限</li>
          <li>刊物可设置简介和标签，便于读者发现</li>
          <li>刊物主编可随时删除刊物（删除后所有投稿和期刊一并删除）</li>
        </ul>
      </div>

      <div class="rules-section">
        <h2>⚖️ 版权与免责</h2>
        <ul>
          <li>文章版权归原作者所有，投稿不代表平台获得版权</li>
          <li>刊物主编在审稿时应核实投稿的原创性</li>
          <li>平台不对刊物内容的准确性和合法性负责</li>
          <li>如发现侵权或违法内容，请联系刊物主编或平台处理</li>
        </ul>
      </div>

      <div class="rules-section">
        <h2>🔄 状态流转图</h2>
        <pre><code>投稿 → 待审 ┬→ 录用 → 已发刊
             ├→ 返修 → (重新提交) → 待审
             └→ 拒稿</code></pre>
      </div>
    </div>`;
}

// ============ 页面：关于 ============
async function renderAbout(main) {
  main.innerHTML = `
    <div class="container" style="max-width:700px">
      <h1 style="margin-bottom:24px">关于万刊网</h1>

      <div class="card mb-2">
        <h3>使命</h3>
        <p class="text-muted mt-1">万刊网致力于打造一个去中心化的、独立的刊物发布平台，让每个人都能自由地创建刊物、发表文章，不受任何组织或机构的控制。</p>
      </div>

      <div class="card mb-2">
        <h3>技术架构</h3>
        <p class="text-muted mt-1">万刊网基于 PHP + SQLite 构建，无需复杂的服务器配置，数据持久化存储，全站共享。</p>
        <ul class="mt-1 text-muted">
          <li><strong>后端</strong>：PHP + SQLite（轻量数据库，零配置）</li>
          <li><strong>前端</strong>：纯 HTML/CSS/JavaScript（单页应用）</li>
          <li><strong>认证</strong>：PHP Session + 密码哈希</li>
          <li><strong>存储</strong>：SQLite 文件数据库（data.db）</li>
          <li><strong>订阅</strong>：RSS 2.0 标准格式</li>
        </ul>
      </div>

      <div class="card mb-2">
        <h3>核心功能</h3>
        <ul class="mt-1 text-muted">
          <li>用户注册与登录</li>
          <li>创建刊物（任何人可创刊）</li>
          <li>投稿（Markdown 编辑器 + 实时预览）</li>
          <li>审稿（录用/返修/拒稿 + 审稿意见）</li>
          <li>发刊（将录用文章编入期刊）</li>
          <li>评论（文章下方可评论讨论）</li>
          <li>订阅（订阅刊物 + RSS 订阅源）</li>
          <li>用户主页（展示刊物和投稿历史）</li>
        </ul>
      </div>

      <div class="card mb-2">
        <h3>同步部署</h3>
        <p class="text-muted mt-1">万刊网同时在以下平台运行：</p>
        <ul class="mt-1">
          <li><a href="https://oseter.github.io/wankan/" target="_blank">GitHub Pages 版</a> — 纯静态 + GitHub Issues/Actions</li>
          <li><a href="/">云服务器版</a> — PHP + SQLite（当前版本）</li>
        </ul>
      </div>

      <div class="card">
        <h3>联系我们</h3>
        <p class="text-muted mt-1">万刊网是一个开放平台，欢迎提出建议和反馈。</p>
      </div>
    </div>`;
}

// ============ 工具函数 ============
function statusLabel(status) {
  const labels = {
    pending: '待审',
    revise: '返修',
    accepted: '已录用',
    rejected: '已拒稿',
    published: '已发刊'
  };
  return labels[status] || status;
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// ============ 通用弹窗 ============
let roleModalCtx = null;
function openModal(inner) {
  closeModal();
  const el = document.createElement('div');
  el.className = 'modal-overlay';
  el.id = 'modal-overlay';
  el.setAttribute('onclick', 'if(event.target===this)closeModal()');
  el.innerHTML = `<div class="modal-box">${inner}<div class="mt-2 flex gap-1" style="justify-content:flex-end"><button class="btn btn-ghost" onclick="closeModal()">取消</button></div></div>`;
  document.body.appendChild(el);
}
function closeModal() { const m = document.getElementById('modal-overlay'); if (m) m.remove(); }

// ============ 网站自带弹窗系统（替代原生 alert/confirm/prompt） ============
// 轻提示（成功/信息/错误）——非阻塞
function toast(text, type) {
  let t = document.getElementById('chat-toast');
  if (!t) { t = document.createElement('div'); t.id = 'chat-toast'; document.body.appendChild(t); }
  t.textContent = text == null ? '' : String(text);
  t.className = (type === 'error') ? 'toast-error' : '';
  // 强制回流后加 show，保证动画
  void t.offsetWidth;
  t.classList.add('show');
  if (chatToastTimer) clearTimeout(chatToastTimer);
  chatToastTimer = setTimeout(() => t.classList.remove('show'), type === 'error' ? 3200 : 2600);
}
// 独立的弹窗容器（不复用 openModal 的自带取消按钮）
function _uiOverlay(innerHtml) {
  const old = document.getElementById('ui-dialog'); if (old) old.remove();
  const el = document.createElement('div');
  el.className = 'modal-overlay'; el.id = 'ui-dialog';
  el.innerHTML = `<div class="modal-box" style="width:420px">${innerHtml}</div>`;
  document.body.appendChild(el);
  return el;
}
function _uiClose() { const m = document.getElementById('ui-dialog'); if (m) m.remove(); }
// 提示框：只有「确定」，返回 Promise<true>
function uiAlert(message, opts) {
  opts = opts || {};
  return new Promise((resolve) => {
    const el = _uiOverlay(`
      <h3 style="margin:0 0 12px;font-size:17px">${escapeHtml(opts.title || '提示')}</h3>
      <div style="white-space:pre-wrap;word-break:break-word;line-height:1.65;color:var(--text)">${escapeHtml(message)}</div>
      <div class="mt-2 flex gap-1" style="justify-content:flex-end;margin-top:16px">
        <button class="btn btn-primary" id="ui-ok">${escapeHtml(opts.okText || '确定')}</button>
      </div>`);
    const done = () => { _uiClose(); resolve(true); };
    el.querySelector('#ui-ok').onclick = done;
    el.addEventListener('click', (e) => { if (e.target === el) done(); });
    const ok = el.querySelector('#ui-ok'); if (ok) ok.focus();
  });
}
// 确认框：返回 Promise<boolean>
function uiConfirm(message, opts) {
  opts = opts || {};
  return new Promise((resolve) => {
    const el = _uiOverlay(`
      <h3 style="margin:0 0 12px;font-size:17px">${escapeHtml(opts.title || '请确认')}</h3>
      <div style="white-space:pre-wrap;word-break:break-word;line-height:1.65;color:var(--text)">${escapeHtml(message)}</div>
      <div class="mt-2 flex gap-1" style="justify-content:flex-end;margin-top:16px">
        <button class="btn btn-ghost" id="ui-cancel">${escapeHtml(opts.cancelText || '取消')}</button>
        <button class="btn ${opts.danger ? 'btn-danger' : 'btn-primary'}" id="ui-ok">${escapeHtml(opts.okText || '确定')}</button>
      </div>`);
    const fin = (v) => { _uiClose(); resolve(v); };
    el.querySelector('#ui-ok').onclick = () => fin(true);
    el.querySelector('#ui-cancel').onclick = () => fin(false);
    el.addEventListener('click', (e) => { if (e.target === el) fin(false); });
  });
}
// 输入框：返回 Promise<string|null>（取消返回 null）
function uiPrompt(message, defaultValue, opts) {
  opts = opts || {};
  return new Promise((resolve) => {
    const st = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:14px;margin-top:4px';
    const field = opts.multiline
      ? `<textarea id="ui-input" rows="4" style="${st};resize:vertical">${escapeHtml(defaultValue || '')}</textarea>`
      : `<input id="ui-input" style="${st}" value="${escapeHtml(defaultValue || '')}">`;
    const el = _uiOverlay(`
      <h3 style="margin:0 0 10px;font-size:17px">${escapeHtml(opts.title || '请输入')}</h3>
      ${message ? `<div style="white-space:pre-wrap;line-height:1.6;color:var(--text2);font-size:13px">${escapeHtml(message)}</div>` : ''}
      ${field}
      <div class="mt-2 flex gap-1" style="justify-content:flex-end;margin-top:16px">
        <button class="btn btn-ghost" id="ui-cancel">取消</button>
        <button class="btn btn-primary" id="ui-ok">确定</button>
      </div>`);
    const input = el.querySelector('#ui-input');
    setTimeout(() => { input.focus(); input.select && input.select(); }, 30);
    const fin = (v) => { _uiClose(); resolve(v); };
    el.querySelector('#ui-ok').onclick = () => fin(input.value);
    el.querySelector('#ui-cancel').onclick = () => fin(null);
    el.addEventListener('click', (e) => { if (e.target === el) fin(null); });
    if (!opts.multiline) input.addEventListener('keydown', (e) => { if (e.key === 'Enter') fin(input.value); });
  });
}

// ============ 蜂巢式联邦架构总览（中央平台 + 自治刊物节点，单库逻辑模拟） ============
async function renderFederation(main) {
  if (!currentUser) { navigate('/login'); return; }
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载蜂巢拓扑中...</p></div>';
  let data;
  try { data = await api('federation'); } catch (e) { main.innerHTML = `<div class="alert alert-error">${e.message}</div>`; return; }
  const core = data.core || {};
  const nodes = data.nodes || [];
  const autoClass = (s) => s >= 80 ? 'auto-high' : s >= 50 ? 'auto-mid' : 'auto-low';
  main.innerHTML = `
    <div class="container" style="max-width:1100px">
      <div class="flex-between mb-2">
        <h1>🏯 蜂巢 · 联邦拓扑</h1>
        <a href="#/admin"><button class="btn btn-sm">控制平台</button></a>
      </div>
      <p class="text-muted" style="margin-bottom:18px">万刊网采用蜂巢式联邦架构：<b>中央平台（蜂巢核心）</b>负责账号、全局模块与平台治理；每个<b>刊物即一个自治节点</b>，拥有独立的成员、身份组、社区（频道/聊天/发帖）与模块开关。各节点在统一数据库内逻辑自治，对外表现为一个去中心化联邦。</p>

      <div class="hive-core">
        <div class="hive-core-title">🐝 蜂巢核心（中央平台）</div>
        <div class="hive-core-stats">
          <div class="hcs"><span class="hcs-num">${core.users ?? 0}</span><span class="hcs-lbl">用户</span></div>
          <div class="hcs"><span class="hcs-num">${core.publications ?? 0}</span><span class="hcs-lbl">刊物节点</span></div>
          <div class="hcs"><span class="hcs-num">${core.articles ?? 0}</span><span class="hcs-lbl">文章</span></div>
          <div class="hcs"><span class="hcs-num">${core.memberships ?? 0}</span><span class="hcs-lbl">成员关系</span></div>
          <div class="hcs"><span class="hcs-num">${core.modules ?? 0}</span><span class="hcs-lbl">平台模块</span></div>
        </div>
      </div>

      <h3 class="mt-3 mb-1">自治节点（${nodes.length}）</h3>
      <div class="node-grid">
        ${nodes.length ? nodes.map(n => `
          <div class="node-card">
            <div class="node-head">
              ${n.avatar ? `<img class="node-avatar" src="${n.avatar}" alt="">` : `<div class="node-avatar">${(n.name[0] || '?').toUpperCase()}</div>`}
              <div>
                <div class="node-name">${escapeHtml(n.name)}</div>
                <div class="text-muted" style="font-size:12px">/${escapeHtml(n.slug)} · #${n.id}</div>
              </div>
              ${n.has_server ? '<span class="badge badge-ok">已部署社区</span>' : '<span class="badge badge-unverified">无社区</span>'}
            </div>
            <div class="node-stats">
              <span>成员 ${n.members}</span><span>频道 ${n.channels}</span>
              <span>聊天 ${n.chat}</span><span>帖子 ${n.posts}</span>
              <span>身份组 ${n.roles}</span><span>模块覆盖 ${n.module_overrides}</span>
            </div>
            <div class="node-auto">
              <div class="node-auto-lbl">自治度 <b>${n.autonomy}</b>/100</div>
              <div class="auto-bar"><div class="auto-fill ${autoClass(n.autonomy)}" style="width:${n.autonomy}%"></div></div>
            </div>
            <div class="node-actions">
              <a href="#/pub/${encodeURIComponent(n.slug)}"><button class="btn btn-sm">进入刊物</button></a>
              <a href="#/pub/${encodeURIComponent(n.slug)}/manage"><button class="btn btn-sm btn-primary">控制社区</button></a>
            </div>
          </div>`).join('') : '<p class="text-muted">暂无刊物节点</p>'}
      </div>
    </div>`;
}

// ============ 平台后台（控制平台） ============
async function renderAdmin(main) {
  if (!currentUser) { navigate('/login'); return; }
  if (!isPlatformAdmin()) { main.innerHTML = '<div class="alert alert-error">无权限访问后台</div>'; return; }
  main.innerHTML = '<div class="loading"><div class="spinner"></div><p class="mt-2">加载中...</p></div>';
  let roles = [], users = [], feedback = [], mods = [], ov = null, nodes = [];
  try {
    const [dr, du, df, dm, dov, dfed] = await Promise.all([
      api('platform_roles'), api('users_list'),
      api('feedback_list').catch(() => ({ feedback: [] })),
      api('modules').catch(() => ({ modules: [] })),
      api('admin_overview').catch(() => (null)),
      api('federation').catch(() => ({ nodes: [] }))
    ]);
    roles = dr.roles || []; users = du.users || []; feedback = df.feedback || [];
    mods = (dm.modules || []).filter(m => m.scope === 'platform');
    ov = dov; nodes = (dfed && dfed.nodes) || [];
  } catch (e) { main.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; return; }
  const s = (ov && ov.stats) || {};
  const roleRows = roles.map(r => `
    <div class="role-row">
      <span class="role-badge" style="background:${r.color}22;color:${r.color};border-color:${r.color}55">${escapeHtml(r.name)}</span>
      <span class="text-muted">权限ID: ${r.permissions} · 成员 ${r.member_count}</span>
      <button class="btn btn-sm" onclick="openPlatRoleModal(${r.id})">编辑</button>
      <button class="btn btn-sm btn-danger" onclick="deletePlatRole(${r.id})">删除</button>
    </div>`).join('') || '<p class="text-muted">暂无身份组</p>';
  const userRows = users.map(u => `
    <div class="member-row">
      ${u.avatar ? `<img class="avatar-sm" src="${u.avatar}" alt="">` : `<div class="avatar-sm">${escapeHtml((u.username[0] || '?').toUpperCase())}</div>`}
      <a href="#/profile/${u.uid || encodeURIComponent(u.username)}">${escapeHtml(u.username)}</a>
      <span class="text-muted">UID:${u.uid}</span>
      <span class="role-badges">${(u.roles || []).map(r => `<span class="role-badge" style="background:${r.color}22;color:${r.color};border-color:${r.color}55">${escapeHtml(r.name)}</span>`).join('')}</span>
      <button class="btn btn-sm" onclick="openUserRolesModal('${escapeHtml(u.username)}')">管理身份组</button>
    </div>`).join('');
  const recentArt = ((ov && ov.recent_articles) || []).map(a => `<div class="recent-item"><span class="badge badge-${a.status}">${statusLabel(a.status)}</span> ${escapeHtml(a.title)}</div>`).join('') || '<p class="text-muted">暂无</p>';
  const recentPub = ((ov && ov.recent_pubs) || []).map(p => `<div class="recent-item">📚 ${escapeHtml(p.name)} <span class="text-muted">/${escapeHtml(p.slug)}</span></div>`).join('') || '<p class="text-muted">暂无</p>';
  const recentFb = ((ov && ov.recent_feedback) || []).map(f => `<div class="recent-item"><strong>${escapeHtml(f.username || '匿名')}</strong>: ${escapeHtml((f.body || '').slice(0, 40))}</div>`).join('') || '<p class="text-muted">暂无</p>';
  main.innerHTML = `
    <div class="container">
      <div class="flex-between mb-2">
        <div><h1>🛡️ 控制平台</h1><p class="text-muted" style="font-size:13px">蜂巢核心 · 平台级治理与监控</p></div>
        <button class="btn btn-sm btn-primary" onclick="openPlatRoleModal(null)">+ 新建身份组</button>
      </div>

      <div class="card mt-2">
        <h3>📊 平台概况</h3>
        <div class="stat-grid">
          <div class="stat-card"><div class="stat-num">${s.users ?? 0}</div><div class="stat-lbl">用户（认证 ${s.verified_users ?? 0} · 封禁 ${s.banned_users ?? 0}）</div></div>
          <div class="stat-card"><div class="stat-num">${s.publications ?? 0}</div><div class="stat-lbl">刊物节点</div></div>
          <div class="stat-card"><div class="stat-num">${s.articles ?? 0}</div><div class="stat-lbl">文章（待审 ${s.pending ?? 0} · 已发刊 ${s.published ?? 0}）</div></div>
          <div class="stat-card"><div class="stat-num">${s.feedback ?? 0}</div><div class="stat-lbl">用户反馈</div></div>
          <div class="stat-card"><div class="stat-num">${s.modules_on ?? 0}</div><div class="stat-lbl">启用中模块</div></div>
          <div class="stat-card"><div class="stat-num">${(s.chat ?? 0) + (s.posts ?? 0)}</div><div class="stat-lbl">社区消息（聊天 ${s.chat ?? 0} · 帖子 ${s.posts ?? 0}）</div></div>
        </div>
      </div>

      <div class="card mt-2" id="accel-card">
        <div class="flex-between">
          <h3>🚀 网站加速器</h3>
          <span id="accel-flag" class="badge-prime">检测中…</span>
        </div>
        <p class="text-muted" style="font-size:13px">开启后：公开只读接口（刊物信息/文章/公告/联邦节点等）服务端缓存 15 秒，配合浏览器 Service Worker 秒开静态资源，显著缓解连接不稳定。登录与写操作不受影响（仍 no-store）。</p>
        <div class="flex" style="gap:8px;margin-top:8px">
          <button class="btn btn-sm btn-primary" onclick="setAccel(true)">开启加速器</button>
          <button class="btn btn-sm" onclick="setAccel(false)">关闭</button>
          <button class="btn btn-sm btn-ghost" onclick="clearAccelCache()">清空缓存</button>
        </div>
      </div>

      <div class="card mt-2">
        <h3>📈 近期动态</h3>
        <div class="recent-cols">
          <div><div class="recent-h">最新文章</div>${recentArt}</div>
          <div><div class="recent-h">最新刊物</div>${recentPub}</div>
          <div><div class="recent-h">最新反馈</div>${recentFb}</div>
        </div>
      </div>

      <div class="card mt-2">
        <h3>🖥️ 虚拟主机控制面板（平台指令终端 / 网站后台）</h3>
        <p class="text-muted" style="font-size:13px">这就是整个虚拟主机的控制面板，也是网站后台。白名单指令解释器（非系统 shell，所有操作受站点沙箱限制）。输入 <code>help</code> 查看全部指令。总构建师额外拥有沙箱内「运维 shell」：<code>phpinfo</code> / <code>sql</code> / <code>ls</code> / <code>cat</code> / <code>write</code> / <code>rm</code> / <code>backup</code>（数据库与文件级操控）；支持 ↑/↓ 历史。管理员可做除删整个主机/网站外的任何事。</p>
        <div class="terminal">
          <div class="term-out" id="term-out"><div class="term-line">$ 欢迎使用万刊网平台终端。输入 help 获取指令列表。</div></div>
          <div class="term-input-row">
            <span class="term-prompt">$</span>
            <input class="term-input" id="term-input" placeholder="输入指令，如：stats" autocomplete="off" onkeydown="termKey(event)">
          </div>
        </div>
      </div>

      <div class="card mt-2">
        <h3>🧩 控制社区（刊物节点）</h3>
        <p class="text-muted" style="font-size:13px">每个刊物即一个自治社区节点，点击进入该节点的控制面板。</p>
        <div class="server-list mt-1">
          ${nodes.length ? nodes.map(n => `
            <div class="server-row">
              <div><b>${escapeHtml(n.name)}</b> <span class="text-muted">/${escapeHtml(n.slug)}</span> ${n.has_server ? '<span class="badge badge-ok">已部署</span>' : '<span class="badge badge-unverified">无社区</span>'}</div>
              <a href="#/pub/${encodeURIComponent(n.slug)}/manage"><button class="btn btn-sm btn-primary">控制社区</button></a>
            </div>`).join('') : '<p class="text-muted">暂无节点</p>'}
        </div>
      </div>

      <div class="card mt-2">
        <h3>平台身份组</h3>
        <div class="role-list mt-1">${roleRows}</div>
      </div>
      <div class="card mt-2">
        <h3>用户（${users.length}）</h3>
        <div class="member-list mt-1">${userRows}</div>
      </div>
      <div class="card mt-2">
        <h3>用户反馈（${feedback.length}）</h3>
        <div class="feedback-list mt-1">${feedback.length ? feedback.map(f => `
          <div class="feedback-item">
            <div class="flex-between">
              <span><strong>${escapeHtml(f.username || '匿名')}</strong>${f.contact ? `<span class="text-muted"> · ${escapeHtml(f.contact)}</span>` : ''}</span>
              <span class="text-muted">${formatDate(f.created_at)}</span>
            </div>
            <div class="feedback-content mt-1">${escapeHtml(f.content)}</div>
            <div class="mt-1"><button class="btn btn-sm btn-danger" onclick="deleteFeedback(${f.id})">删除</button></div>
          </div>`).join('') : '<p class="text-muted">暂无反馈</p>'}</div>
      </div>
      <div class="card mt-2">
        <h3>模块 / 插件 / 扩展</h3>
        <p class="text-muted" style="font-size:13px">平台级开关：关闭后全站该功能入口隐藏（刊物可在「管理 → 模块」单独覆盖开启）。</p>
        <div class="mod-list mt-1">
          ${mods.map(m => `
            <div class="mod-row">
              <div>
                <div class="mod-name">${escapeHtml(m.name)} <span class="text-muted" style="font-size:12px">${escapeHtml(m.mkey)}</span></div>
                <div class="text-muted" style="font-size:12px">${escapeHtml(m.description || '')}</div>
              </div>
              <button class="btn btn-sm ${m.enabled ? '' : 'btn-ghost'}" onclick="toggleModule('${escapeHtml(m.mkey)}')">${m.enabled ? '已启用' : '已关闭'}</button>
            </div>`).join('')}
        </div>
      </div>

      <div class="card mt-2">
        <div class="flex-between">
          <h3>🛡️ 平台管理员（身份组成员）</h3>
          <button class="btn btn-sm" onclick="loadAdminAdmins()">刷新</button>
        </div>
        <p class="text-muted" style="font-size:13px">平台管理员是一个身份组，下面是所有成员（判定只看「在不在这个组里」，不绑定任何邮箱）。点「直接使用该账号」可一键切换进该账号。</p>
        <div class="mod-list mt-1" id="admin-list"><div class="text-muted">加载中…</div></div>
        <div class="mt-1 flex" style="gap:6px">
          <input id="add-admin-name" class="input" style="flex:1" placeholder="用户名，加入平台管理员身份组">
          <button class="btn btn-sm" onclick="addToAdminGroup()">加入身份组</button>
        </div>
      </div>
      <div class="card mt-2">
        <h3>🏘️ 社区加入管理</h3>
        <p class="text-muted" style="font-size:13px">QQ 式申请审核，或生成 Discord 式邀请链接（可设开放 / 次数 / 有效期）。</p>
        <div id="community-join"></div>
      </div>

      <div class="card mt-2">
        <div class="flex-between">
          <h3>🕒 操作日志 / 撤回</h3>
          <button class="btn btn-sm" onclick="loadAdminLog()">刷新</button>
        </div>
        <p class="text-muted" style="font-size:13px">平台管理员的一切治理行为都在这里留痕，可一键撤回（撤销封禁/解封、身份组升降、删除用户/刊物/文章/反馈/帖子等）。</p>
        <div class="log-list mt-1" id="admin-log-list"><div class="text-muted">加载中…</div></div>
      </div>
    </div>`;
  loadAdminLog();
  loadAccel();
  loadCommunityJoin(slug);
}

// ============ 社区加入管理（刊物管理面板） ============
function communityInviteLink(token) { return (location.origin || '') + '/#/invite/' + token; }
async function loadCommunityJoin(slug) {
  const box = document.getElementById('community-join');
  if (!box) return;
  box.innerHTML = '<div class="text-muted">加载中…</div>';
  try {
    const [dr, di] = await Promise.all([
      api('community_requests', { query: { slug } }).catch(() => ({ requests: [] })),
      api('community_invites', { query: { slug } }).catch(() => ({ invites: [] }))
    ]);
    const reqs = dr.requests || [];
    const invs = di.invites || [];
    const stBadge = s => s === 'pending' ? '<span class="badge badge-unverified">待审核</span>'
      : s === 'approved' ? '<span class="badge badge-ok">已通过</span>' : '<span class="badge badge-prime">已拒绝</span>';
    const reqHtml = reqs.length ? reqs.map(r => `
      <div class="mod-row">
        <div>
          <div class="mod-name">${escapeHtml(r.username)} <span class="text-muted" style="font-size:12px">uid ${r.uid}</span> ${stBadge(r.status)}</div>
          ${r.message ? `<div class="text-muted" style="font-size:12px">“${escapeHtml(r.message)}”</div>` : ''}
          <div class="text-muted" style="font-size:12px">${formatDate(r.created_at)}</div>
        </div>
        ${r.status === 'pending' ? `<div class="flex" style="gap:6px">
          <button class="btn btn-sm btn-primary" onclick="communityReview(${r.id}, 'approve', '${slug}')">通过</button>
          <button class="btn btn-sm btn-ghost" onclick="communityReview(${r.id}, 'reject', '${slug}')">拒绝</button>
        </div>` : ''}
      </div>`).join('') : '<p class="text-muted">暂无申请</p>';

    const invHtml = `
      <div class="form-group mt-2" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <div><label style="font-size:12px">使用次数上限</label><input type="number" id="inv-max" class="input" style="width:120px" min="0" value="0" placeholder="0=不限"></div>
        <div><label style="font-size:12px">有效期（小时）</label><input type="number" id="inv-exp" class="input" style="width:120px" min="0" value="0" placeholder="0=永久"></div>
        <button class="btn btn-sm btn-primary" onclick="communityCreateInvite('${slug}')">生成邀请链接</button>
      </div>
      <div class="mt-2">${invs.length ? invs.map(v => `
        <div class="mod-row">
          <div style="flex:1;min-width:0">
            <div class="mod-name">${v.active ? '<span class="badge badge-ok">有效</span>' : '<span class="badge badge-unverified">已失效</span>'}
              ${v.max_uses > 0 ? ` ${v.uses}/${v.max_uses} 次` : ` ${v.uses} 次`}</div>
            <div class="text-muted" style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(communityInviteLink(v.token))}</div>
            ${v.expires_at ? `<div class="text-muted" style="font-size:12px">过期：${escapeHtml(v.expires_at)}</div>` : ''}
          </div>
          <div class="flex" style="gap:6px">
            <button class="btn btn-sm" onclick="copyText('${escapeHtml(communityInviteLink(v.token))}')">复制</button>
            <button class="btn btn-sm btn-ghost" onclick="communityRevokeInvite(${v.id}, '${slug}')">吊销</button>
          </div>
        </div>`).join('') : '<p class="text-muted">暂无邀请链接</p>'}</div>`;

    box.innerHTML = `
      <div class="mt-1">
        <div class="text-muted" style="font-size:13px;font-weight:600">📝 加入申请（QQ 式）</div>
        <div class="mt-1">${reqHtml}</div>
      </div>
      <div class="mt-2">
        <div class="text-muted" style="font-size:13px;font-weight:600">🔗 邀请链接（Discord 式）</div>
        ${invHtml}
      </div>`;
  } catch (e) { box.innerHTML = '<p class="text-muted">加载失败：' + escapeHtml(e.message || '') + '</p>'; }
}
async function communityReview(reqId, act, slug) {
  try {
    await api('community_review', { method: 'POST', body: { request_id: reqId, action: act } });
    chatToast(act === 'approve' ? '✓ 已通过申请' : '已拒绝申请');
    loadCommunityJoin(slug);
  } catch (e) { toast(e.message, 'error'); }
}
async function communityCreateInvite(slug) {
  const max = parseInt((document.getElementById('inv-max') || {}).value || '0', 10) || 0;
  const exp = parseInt((document.getElementById('inv-exp') || {}).value || '0', 10) || 0;
  try {
    await api('community_invite_create', { method: 'POST', body: { slug, max_uses: max, expires_hours: exp } });
    chatToast('✓ 邀请链接已生成');
    loadCommunityJoin(slug);
  } catch (e) { toast(e.message, 'error'); }
}
async function communityRevokeInvite(invId, slug) {
  if (!await uiConfirm('确定吊销该邀请链接？', { danger: true, okText: '吊销' })) return;
  try {
    await api('community_invite_revoke', { method: 'POST', body: { invite_id: invId } });
    chatToast('✓ 已吊销');
    loadCommunityJoin(slug);
  } catch (e) { toast(e.message, 'error'); }
}
function copyText(t) {
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t); chatToast('✓ 已复制'); return; }
  } catch (e) {}
  const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); chatToast('✓ 已复制'); } catch (e) { toast('复制失败，请手动复制'); }
  ta.remove();
}

// 网站加速器：拉取开关状态并渲染徽标
async function loadAccel() {
  const flag = document.getElementById('accel-flag');
  if (!flag) return;
  try {
    const d = await api('admin_accel', { method: 'POST', body: { op: 'status' } });
    const on = !!(d && d.on);
    flag.textContent = on ? '已开启 ✅' : '已关闭 ⛔';
    flag.className = on ? 'badge-ok' : 'badge-prime';
  } catch (e) { flag.textContent = '获取失败'; }
}
async function setAccel(on) {
  try {
    const d = await api('admin_accel', { method: 'POST', body: { on } });
    if (d && d.ok) { chatToast('🚀 ' + (d.message || (on ? '加速器已开启' : '加速器已关闭'))); loadAccel(); }
  } catch (e) { toast((e && e.message) || '操作失败'); }
}
async function clearAccelCache() {
  try {
    const d = await api('admin_accel', { method: 'POST', body: { op: 'clear' } });
    if (d && d.ok) chatToast('🧹 ' + (d.message || '已清空缓存'));
  } catch (e) { toast((e && e.message) || '操作失败'); }
}

// 平台操作日志（撤回依据）—— 拉取并在卡片中渲染可撤回项
const ADMIN_ACT_LABEL = { ban: '封禁', unban: '解封', promote: '赋予身份组', demote: '移除身份组', delete_user: '删除用户', delete_pub: '删除刊物', delete_article: '删除文章', delete_feedback: '删除反馈', delete_post: '删除帖子', announce: '发布公告' };
async function loadAdminLog() {
  const box = document.getElementById('admin-log-list');
  if (!box) return;
  try {
    const d = await api('admin_log', { query: { n: 40 } });
    const logs = d.logs || [];
    if (!logs.length) { box.innerHTML = '<p class="text-muted">暂无操作记录</p>'; return; }
    box.innerHTML = logs.map(l => {
      const undone = (l.summary || '').indexOf('［已撤回］') >= 0;
      const label = ADMIN_ACT_LABEL[l.action] || l.action;
      return `<div class="log-row ${undone ? 'log-undone' : ''}">
        <div class="log-main"><span class="log-act">${escapeHtml(label)}</span> <span class="log-sum">${escapeHtml(l.summary || '')}</span></div>
        <div class="log-meta text-muted">#${l.id} · ${escapeHtml(l.created_at || '')}</div>
        ${undone ? '' : `<button class="btn btn-sm btn-ghost" onclick="undoAction(${l.id})">撤回</button>`}
      </div>`;
    }).join('');
  } catch (e) { box.innerHTML = '<p class="text-muted">加载失败：' + escapeHtml(e.message || '') + '</p>'; }
}
async function undoAction(id) {
  if (!await uiConfirm('确定撤回该操作？', { okText: '撤回' })) return;
  try {
    const d = await api('admin_undo', { method: 'POST', body: { id } });
    if (d.ok) { chatToast('✓ ' + (d.message || '已撤回')); loadAdminLog(); }
    else toast((d && d.error) || '撤回失败');
  } catch (e) { toast((e && e.message) || '撤回失败'); }
}

// 平台管理员身份组成员：列出 / 直接使用（切换进）/ 加入 / 移出
async function loadAdminAdmins() {
  const box = document.getElementById('admin-list');
  if (!box) return;
  try {
    const d = await api('admin_list_admins');
    const rows = d.admins || [];
    if (!rows.length) { box.innerHTML = '<p class="text-muted">该身份组暂无成员</p>'; return; }
    box.innerHTML = rows.map(r => `
      <div class="mod-row">
        <div>
          <div class="mod-name">${escapeHtml(r.username)} <span class="text-muted" style="font-size:12px">${escapeHtml(r.email || '')}</span> ${r.is_prime ? '' : (r.verified ? '<span class="badge-ok">已认证</span>' : '')} ${r.is_prime ? '<span class="badge-prime">🛡️ 平台总构建师 · 权限ID=0</span>' : ''}</div>
          <div class="text-muted" style="font-size:12px">uid ${r.uid} · id ${r.id} · ${r.is_prime ? 'prime admin' : '平台管理员'}</div>
        </div>
        <div class="flex" style="gap:6px">
          <button class="btn btn-sm" onclick="impersonateAdmin(${r.id})">直接使用该账号</button>
          ${r.is_prime ? '' : `<button class="btn btn-sm btn-ghost" onclick="removeFromAdminGroup(${r.id})">移出身份组</button>`}
        </div>
      </div>`).join('');
  } catch (e) { box.innerHTML = '<p class="text-muted">加载失败：' + escapeHtml(e.message || '') + '</p>'; }
}
async function impersonateAdmin(userId) {
  if (!await uiConfirm('确定切换进入该账号？你将以其身份操作，此行为会被记入操作日志。', { okText: '切换进入' })) return;
  try {
    const d = await api('admin_impersonate', { method: 'POST', body: { user_id: userId } });
    if (d.ok && d.user) {
      currentUser = d.user;
      authToken = d.token;
      chatToast('✓ 已切换至 ' + d.user.username);
      await resetSessionState();
      renderNavbar();
      if (location.hash.startsWith('#/admin')) router();
    } else toast((d && d.error) || '切换失败');
  } catch (e) { toast((e && e.message) || '切换失败'); }
}
async function addToAdminGroup() {
  const name = (document.getElementById('add-admin-name') || {}).value || '';
  if (!name.trim()) { toast('请输入用户名'); return; }
  try {
    const d = await api('role_assign', { method: 'POST', body: { username: name.trim(), role: '平台管理员', action: 'add' } });
    if (d.ok) { chatToast('✓ 已加入平台管理员身份组'); document.getElementById('add-admin-name').value = ''; loadAdminAdmins(); }
    else toast((d && d.error) || '操作失败');
  } catch (e) { toast((e && e.message) || '操作失败'); }
}
async function removeFromAdminGroup(userId) {
  if (!await uiConfirm('确定将该用户移出平台管理员身份组？', { danger: true, okText: '移出' })) return;
  try {
    // 先取用户名
    const d0 = await api('admin_list_admins');
    const u = (d0.admins || []).find(x => x.id == userId);
    const d = await api('role_assign', { method: 'POST', body: { username: u ? u.username : '', role: '平台管理员', action: 'remove' } });
    if (d.ok) { chatToast('✓ 已移出身份组'); loadAdminAdmins(); }
    else toast((d && d.error) || '操作失败');
  } catch (e) { toast((e && e.message) || '操作失败'); }
}

function openPlatRoleModal(roleId) {
  if (!isPlatformAdmin()) { toast('无权限'); return; }
  api('platform_roles').then(d => {
    const r = (d.roles || []).find(x => x.id == roleId) || null;
    roleModalCtx = { kind: 'plat', id: roleId || null };
    openModal(`
      <h3>${roleId ? '编辑身份组' : '新建平台身份组'}</h3>
      <div class="form-group"><label>名称</label><input type="text" id="role-name" value="${r ? escapeHtml(r.name) : ''}"></div>
      <div class="form-group"><label>颜色</label><input type="color" id="role-color" value="${r ? r.color : '#5865f2'}"></div>
      <div class="form-group"><label>权限（勾选即拥有）</label><div class="checkbox-list" id="role-perms">${permCheckboxes(r ? r.permissions : 0)}</div></div>
      <button class="btn btn-primary" onclick="submitRole()">保存</button>
    `);
  }).catch(e => toast(e.message, 'error'));
}

async function submitRole() {
  const name = document.getElementById('role-name').value.trim();
  const color = document.getElementById('role-color').value;
  const perms = [...document.querySelectorAll('#role-perms .perm-cb:checked')].reduce((s, c) => s + Number(c.value), 0);
  if (!name) { toast('请输入名称'); return; }
  try {
    if (roleModalCtx.kind === 'plat') {
      await api('role_save', { method: 'POST', body: { id: roleModalCtx.id || 0, name, color, permissions: perms } });
    } else {
      await api('pub_role_save', { method: 'POST', body: { pubId: roleModalCtx.pubId, id: roleModalCtx.id || 0, name, color, permissions: perms } });
    }
    closeModal();
    if (roleModalCtx.kind === 'plat') navigate('/admin'); else router();
  } catch (e) { toast(e.message, 'error'); }
}

async function deletePlatRole(roleId) {
  if (!await uiConfirm('确定删除该平台身份组？', { danger: true, okText: '删除' })) return;
  try { await api('role_delete', { method: 'POST', body: { id: roleId } }); navigate('/admin'); } catch (e) { toast(e.message, 'error'); }
}

function openUserRolesModal(username) {
  Promise.all([ api('platform_roles'), api('profile', { query: { username } }) ]).then(([dr, pd]) => {
    const cur = (pd.user.platform_roles || []).map(r => r.id);
    roleModalCtx = { kind: 'plat-user', username };
    openModal(`
      <h3>设置「${escapeHtml(username)}」的平台身份组</h3>
      <div class="checkbox-list" id="user-roles">
        ${dr.roles.map(r => `<label class="checkbox-item"><input type="checkbox" class="ur-role" value="${r.id}" ${cur.includes(r.id) ? 'checked' : ''}><span>${escapeHtml(r.name)}</span></label>`).join('')}
      </div>
      <button class="btn btn-primary" onclick="submitUserRoles('${escapeHtml(username)}')">保存</button>
    `);
  }).catch(e => toast(e.message, 'error'));
}

async function submitUserRoles(username) {
  const all = [...document.querySelectorAll('#user-roles .ur-role')].map(c => ({ id: Number(c.value), on: c.checked }));
  try {
    for (const r of all) { await api('role_assign', { method: 'POST', body: { username, role_id: r.id, assign: r.on } }); }
    closeModal(); renderNavbar(); navigate('/admin');
  } catch (e) { toast(e.message, 'error'); }
}

// ============ 刊物身份组 / 成员 ============
function openPubRoleModal(pubId, roleId) {
  const slug = getRoute()[1];
  api('pub_roles', { query: { slug } }).then(d => {
    const r = (d.roles || []).find(x => x.id == roleId) || null;
    roleModalCtx = { kind: 'pub', pubId, id: roleId || null };
    openModal(`
      <h3>${roleId ? '编辑身份组' : '新建刊物身份组'}</h3>
      <div class="form-group"><label>名称</label><input type="text" id="role-name" value="${r ? escapeHtml(r.name) : ''}"></div>
      <div class="form-group"><label>颜色</label><input type="color" id="role-color" value="${r ? r.color : '#5865f2'}"></div>
      <div class="form-group"><label>权限（勾选即拥有）</label><div class="checkbox-list" id="role-perms">${permCheckboxes(r ? r.permissions : 0)}</div></div>
      <button class="btn btn-primary" onclick="submitRole()">保存</button>
    `);
  }).catch(e => toast(e.message, 'error'));
}

async function deletePubRole(pubId, roleId) {
  if (!await uiConfirm('确定删除该刊物身份组？成员将失去此身份。', { danger: true, okText: '删除' })) return;
  try { await api('pub_role_delete', { method: 'POST', body: { role_id: roleId } }); router(); } catch (e) { toast(e.message, 'error'); }
}

function openMemberModal(pubId, username) {
  const slug = getRoute()[1];
  api('pub_roles', { query: { slug } }).then(d => {
    const m = (d.members || []).find(x => x.username === username);
    const cur = (m && m.role_ids ? m.role_ids.split(',').filter(Boolean).map(Number) : []);
    openModal(`
      <h3>设置「${escapeHtml(username)}」的刊物身份组</h3>
      <div class="checkbox-list" id="member-roles">
        ${d.roles.map(r => `<label class="checkbox-item"><input type="checkbox" class="mem-role" value="${r.id}" ${cur.includes(r.id) ? 'checked' : ''}><span>${escapeHtml(r.name)}</span></label>`).join('')}
      </div>
      <button class="btn btn-primary" onclick="submitMemberRoles(${pubId}, '${escapeHtml(username)}')">保存</button>
    `);
  }).catch(e => toast(e.message, 'error'));
}

async function submitMemberRoles(pubId, username) {
  const slug = getRoute()[1];
  const roleIds = [...document.querySelectorAll('#member-roles .mem-role:checked')].map(c => Number(c.value));
  try { await api('pub_member_roles', { method: 'POST', body: { slug, username, role_ids: roleIds } }); closeModal(); router(); } catch (e) { toast(e.message, 'error'); }
}

async function removePubMember(pubId, username) {
  if (!await uiConfirm('确定将该成员移出刊物？', { danger: true, okText: '移出' })) return;
  const slug = getRoute()[1];
  try { await api('pub_member_remove', { method: 'POST', body: { slug, username } }); router(); } catch (e) { toast(e.message, 'error'); }
}

async function addPubMember(e, pubId, slug) {
  e.preventDefault();
  const name = (document.getElementById('new-member-name').value || '').trim();
  const roleIds = [...document.querySelectorAll('.new-member-role:checked')].map(c => Number(c.value));
  if (!name) { toast('请输入用户名'); return; }
  try { await api('pub_member_add', { method: 'POST', body: { slug, username: name, role_ids: roleIds } }); router(); } catch (err) { toast(err.message, 'error'); }
}

// ============ 消息中心（独立页面：通知 + 私信） ============
// 兼容旧调用：原右下角私信悬浮窗已并入「消息中心」独立页（#/messages）
function ensureChatLauncher() {}
function updateChatLauncher() {}

let centerTimer = null;
let centerTab = 'dm';       // 'dm' 私信 | 'notif' 通知
let centerConvCache = [];
// 注意：会话对方使用顶层已有的全局 chatPeer（buildBubbles 依赖它）

// 会话列表（消息中心与悬浮窗共用，容器 id 由调用方传入）
function paintConvList(elId, convs, onClick) {
  const el = document.getElementById(elId);
  if (!el) return;
  if (!convs.length) {
    el.innerHTML = '<div class="empty"><p class="text-muted">暂无会话</p></div>';
    return;
  }
  el.innerHTML = convs.map(c => {
    const u = c.user;
    const avatar = avatarHtml(u, 'conv-avatar');
    return `
      <div class="conv-item ${c.unread ? 'unread' : ''}" onclick="${onClick}('${encodeURIComponent(u.username)}')">
        <div class="conv-avatar-wrap">${avatar}${c.unread ? '<span class="conv-dot"></span>' : ''}</div>
        <div class="conv-main">
          <div class="conv-top">
            <span class="conv-name">${escapeHtml(u.username)}</span>
            <span class="conv-time">${fmtMsgTime(c.last_time)}</span>
          </div>
          <div class="conv-last">${c.last_is_me ? '<span class="conv-me">我:</span> ' : ''}${escapeHtml(c.last || '')}</div>
        </div>
        ${c.unread ? `<span class="conv-badge">${c.unread}</span>` : ''}
      </div>`;
  }).join('');
}

async function renderMessagesCenter(main, peerName) {
  if (!currentUser) { navigate('/login'); return; }
  main.innerHTML = `
    <div class="container" style="max-width:920px">
      <div class="msg-center">
        <div class="msg-tabs">
          <button class="msg-tab ${centerTab==='dm'?'active':''}" onclick="centerSwitch('dm')">💬 私信</button>
          <button class="msg-tab ${centerTab==='notif'?'active':''}" onclick="centerSwitch('notif')">🔔 通知${unreadCount?`<span class="tab-badge">${unreadCount}</span>`:''}</button>
        </div>
        <div id="center-body" class="msg-center-body"></div>
      </div>
    </div>`;
  if (peerName) centerTab = 'dm';
  if (centerTab === 'dm') centerRenderDM();
  else centerRenderNotif();
  refreshUnread();
}
function centerSwitch(tab) {
  centerTab = tab;
  const main = document.getElementById('app');
  if (main) renderMessagesCenter(main);
}
async function centerRenderDM() {
  const body = document.getElementById('center-body');
  if (!body) return;
  body.innerHTML = `
    <div class="msg-dm">
      <div class="msg-dm-list">
        <div class="chat-list-head">
          <input class="search-box" id="center-search" placeholder="搜索会话…" oninput="centerFilterDM()">
          <button class="btn btn-sm btn-primary" onclick="centerNew()">+ 新建</button>
        </div>
        <div id="center-convs"></div>
      </div>
      <div class="msg-dm-read" id="center-read">
        <div class="chat-empty">选择一个会话开始聊天</div>
      </div>
    </div>`;
  await centerLoadConvs();
}
async function centerLoadConvs() {
  try {
    const data = await api('messages');
    centerConvCache = data.conversations || [];
    paintConvList('center-convs', centerConvCache, 'centerOpenThread');
  } catch (e) {}
}
function centerFilterDM() {
  const q = (document.getElementById('center-search')?.value || '').trim().toLowerCase();
  const list = q ? centerConvCache.filter(c => (c.user.username||'').toLowerCase().includes(q)) : centerConvCache;
  paintConvList('center-convs', list, 'centerOpenThread');
}
async function centerNew() {
  let list = [];
  try {
    const d = await api('follow_list', { query: { type: 'following', limit: 200 } });
    list = d.users || [];
  } catch (e) {}
  openModal(`
    <h3>选择私信对象</h3>
    <p class="text-muted" style="font-size:13px">只能给「已关注」的用户发私信。搜索任意用户后可先关注再私信。</p>
    <input class="search-box" id="picker-search" placeholder="搜索用户…" oninput="centerPickerSearch(this.value)">
    <div id="picker-list" class="picker-list" style="max-height:52vh;overflow:auto"></div>
  `);
  renderPickerList(list);
}
function renderPickerList(users) {
  const el = document.getElementById('picker-list');
  if (!el) return;
  if (!users.length) {
    el.innerHTML = '<div class="empty"><p class="text-muted">还没有关注任何人，搜索并关注后即可私信</p></div>';
    return;
  }
  el.innerHTML = users.map(u => `
    <div class="picker-item">
      ${avatarHtml(u, 'conv-avatar')}
      <div class="picker-info">
        <div class="picker-name">${escapeHtml(u.username)}</div>
        ${u.bio ? `<div class="picker-bio">${escapeHtml(u.bio).slice(0, 40)}</div>` : ''}
      </div>
      ${u.is_following
        ? `<button class="btn btn-sm btn-primary" onclick="centerPickAndMsg('${encodeURIComponent(u.username)}')">发消息</button>`
        : `<button class="btn btn-sm" onclick="centerPickerFollow('${encodeURIComponent(u.username)}', this)">＋ 关注</button>`}
    </div>`).join('');
}
async function centerPickerSearch(q) {
  q = (q || '').trim();
  if (!q) {
    try { const d = await api('follow_list', { query: { type: 'following', limit: 200 } }); renderPickerList(d.users || []); } catch (e) {}
    return;
  }
  try { const d = await api('user_search', { query: { q } }); renderPickerList(d.users || []); } catch (e) {}
}
async function centerPickerFollow(rawName, btn) {
  const username = decodeURIComponent(rawName);
  try {
    const d = await api('follow', { method: 'POST', body: { username } });
    if (d.ok && d.following) {
      btn.outerHTML = `<button class="btn btn-sm btn-primary" onclick="centerPickAndMsg('${encodeURIComponent(username)}')">发消息</button>`;
    } else if (d.ok) {
      btn.textContent = '＋ 关注';
    }
  } catch (e) { toast(e.message, 'error'); }
}
async function centerPickAndMsg(rawName) {
  const username = decodeURIComponent(rawName);
  closeModal();
  centerOpenThread(encodeURIComponent(username));
}
async function toggleFollow(username, btn) {
  try {
    const d = await api('follow', { method: 'POST', body: { username } });
    if (!d.ok) return;
    const following = d.following;
    btn.classList.toggle('btn-primary', !following);
    btn.textContent = following ? '✓ 已关注' : '＋ 关注';
    const actions = btn.closest('.profile-actions');
    if (actions) {
      let msg = actions.querySelector('.dm-btn');
      if (following && !msg) {
        const b = document.createElement('button');
        b.className = 'btn btn-sm btn-primary dm-btn';
        b.textContent = '发消息';
        b.setAttribute('onclick', `navigate('/messages/${encodeURIComponent(username)}')`);
        actions.appendChild(b);
      } else if (!following && msg) {
        msg.remove();
      }
    }
  } catch (e) { toast(e.message, 'error'); }
}
async function centerOpenThread(rawName) {
  const username = decodeURIComponent(rawName);
  const thread = document.getElementById('center-read');
  if (!thread) return;
  let data;
  try { data = await api('messages', { query: { with: username } }); }
  catch (err) { thread.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`; return; }
  chatPeer = data.peer;
  centerRenderThreadView(thread, data.messages || []);
  refreshUnread();
  if (centerTimer) { clearInterval(centerTimer); centerTimer = null; }
  centerTimer = setInterval(async () => {
    if (circuitOpen || document.hidden) return;
    try {
      const d = await api('messages', { query: { with: username } });
      chatPeer = d.peer;
      const b = document.getElementById('center-msg-body');
      if (b) { b.innerHTML = buildBubbles(d.messages || []); b.scrollTop = b.scrollHeight; }
      centerLoadConvs();
      refreshUnread();
    } catch (e) {}
  }, 8000);
}
function centerRenderThreadView(thread, msgs) {
  const p = chatPeer;
  const pAvatar = avatarHtml(p, 'conv-avatar');
  thread.innerHTML = `
    <div class="chat-read-head">
      <button class="btn btn-sm" onclick="centerBackToList()">←</button>
      ${pAvatar}
      <strong>${escapeHtml(p.username)}</strong>
      <button class="btn btn-sm btn-ghost chat-del" onclick="centerDeleteConv('${encodeURIComponent(p.username)}')">删除</button>
    </div>
    <div class="msg-read" id="center-msg-body">${buildBubbles(msgs)}</div>
    <form onsubmit="centerSend(event, '${encodeURIComponent(p.username)}')" class="msg-compose">
      <textarea id="center-msg-input" required minlength="1" placeholder="输入私信内容…" rows="2"></textarea>
      <button type="submit" class="btn btn-primary">发送</button>
    </form>`;
  const mb = document.getElementById('center-msg-body');
  if (mb) mb.scrollTop = mb.scrollHeight;
  const inp = document.getElementById('center-msg-input');
  if (inp) inp.focus();
}
function centerBackToList() {
  const thread = document.getElementById('center-read');
  if (thread) thread.innerHTML = '<div class="chat-empty">选择一个会话开始聊天</div>';
  chatPeer = null;
  if (centerTimer) { clearInterval(centerTimer); centerTimer = null; }
}
async function centerSend(e, rawName) {
  e.preventDefault();
  const username = decodeURIComponent(rawName);
  const input = document.getElementById('center-msg-input');
  const body = input.value;
  if (!body.trim()) return;
  const form = input.closest('form');
  const btn = form ? form.querySelector('button') : null;
  input.disabled = true; if (btn) btn.disabled = true;
  try {
    await api('messages', { method: 'POST', body: { to: username, body } });
    input.value = '';
    const d = await api('messages', { query: { with: username } });
    chatPeer = d.peer;
    const b = document.getElementById('center-msg-body');
    if (b) { b.innerHTML = buildBubbles(d.messages || []); b.scrollTop = b.scrollHeight; }
    centerLoadConvs();
    refreshUnread();
  } catch (err) { toast(err.message, 'error'); }
  finally { input.disabled = false; if (btn) btn.disabled = false; input.focus(); }
}
async function centerDeleteConv(rawName) {
  const username = decodeURIComponent(rawName);
  if (!await uiConfirm(`确定删除与「${username}」的私信会话？此操作不可恢复。`, { danger: true, okText: '删除' })) return;
  try {
    await api('messages', { method: 'DELETE', query: { with: username } });
    centerBackToList();
    centerLoadConvs();
    refreshUnread();
  } catch (err) { toast(err.message, 'error'); }
}
async function centerRenderNotif() {
  const body = document.getElementById('center-body');
  if (!body) return;
  body.innerHTML = `<div id="center-notif" class="notif-list"><div class="loading"><div class="spinner"></div></div></div>`;
  let list;
  try { const d = await api('notifications'); list = d.notifications || []; }
  catch (e) { body.innerHTML = '<div class="alert alert-error">加载失败</div>'; return; }
  const el = document.getElementById('center-notif');
  if (!el) return;
  if (!list.length) { el.innerHTML = '<div class="empty"><p class="text-muted">暂无通知</p></div>'; return; }
  el.innerHTML = list.map(n => {
    const av = n.actor_avatar
      ? `<img class="conv-avatar" src="${n.actor_avatar}" alt="">`
      : `<div class="conv-avatar">${(n.actor_name||'?')[0].toUpperCase()}</div>`;
    return `
      <div class="notif-item ${n.read?'':'unread'}" onclick="centerReadNotif(${n.id}, '${escapeHtml(n.link||'')}')">
        <div class="conv-avatar-wrap">${av}${n.read?'':'<span class="conv-dot"></span>'}</div>
        <div class="notif-main">
          <div class="notif-body">${escapeHtml(n.body)}</div>
          <div class="notif-time">${fmtMsgTime(n.created_at)}</div>
        </div>
      </div>`;
  }).join('') + `<div class="text-center mt-2"><button class="btn btn-sm" onclick="centerReadAll()">全部标为已读</button></div>`;
}
async function centerReadNotif(id, link) {
  try { await api('notifications', { method: 'POST', body: { id } }); } catch (e) {}
  refreshUnread();
  if (link) navigate(link);
  else centerRenderNotif();
}
async function centerReadAll() {
  try { await api('notifications', { method: 'POST', body: {} }); } catch (e) {}
  refreshUnread();
  centerRenderNotif();
}

// 收到新私信时的轻提示（toast）
let chatToastTimer = null;
function chatToast(text) {
  let t = document.getElementById('chat-toast');
  if (!t) { t = document.createElement('div'); t.id = 'chat-toast'; document.body.appendChild(t); }
  t.textContent = text;
  t.classList.add('show');
  if (chatToastTimer) clearTimeout(chatToastTimer);
  chatToastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

// ============ 反馈（意见反馈） ============
function openFeedback() {
  openModal(`
    <h3>📣 意见反馈</h3>
    <p class="text-muted">你的建议会让万刊网变得更好。可留联系方式以便回复。</p>
    <div class="form-group">
      <label>反馈内容 *</label>
      <textarea id="fb-content" rows="5" maxlength="2000" placeholder="想说点什么…（至少5个字）"></textarea>
    </div>
    <div class="form-group">
      <label>联系方式（选填）</label>
      <input type="text" id="fb-contact" placeholder="邮箱 / QQ / 用户名">
    </div>
    <button class="btn btn-primary" onclick="submitFeedback()">提交反馈</button>
  `);
}

function openSupport() {
  openModal(`
    <h3>💖 支持万刊网</h3>
    <p class="text-muted">万刊网完全免费、不追踪用户。如果你觉得它有用，欢迎自愿支持——就像 B 站的「充电」一样～</p>
    <div class="support-grid">
      <div class="support-card">
        <div class="sc-title">① 微信赞赏</div>
        <div class="wx-qr-wrap">
          <img id="wxQrImg" class="wx-qr" alt="微信收款码" src="assets/wxqr.png" onerror="this.onerror=null;this.style.display='none';var t=document.getElementById('wxQrHint');if(t)t.textContent='收款码加载失败，请刷新或稍后再试';">
        </div>
        <p class="sc-hint">打开微信 → 扫一扫即可赞赏</p>
      </div>
      <div class="support-card">
        <div class="sc-title">② GitHub Sponsors</div>
        <p class="sc-hint">如果你也用 GitHub，可以每月或一次性赞助，并点亮仓库的 ⭐ Sponsor。</p>
        <a class="btn btn-primary" href="https://github.com/sponsors/Oseter" target="_blank" rel="noopener">前往 GitHub 赞助 ↗</a>
      </div>
      <div class="support-card">
        <div class="sc-title">③ 广告 / 商务合作</div>
        <p class="sc-hint">本站接受广告与商务合作，广告位招租中。顶部广告条亦可投放，欢迎联系。</p>
      </div>
    </div>
  `);
  applyWxQr();
}

function applyWxQr() {
  // 微信收款码直接以静态图 assets/wxqr.png 提供（新文件名，规避旧文件被 CDN 缓存 403 的问题）。
  // 图片已在 openSupport 的 <img src> 中直接引用，这里仅在缺省时兜底设置一次。
  const img = document.getElementById('wxQrImg');
  if (!img) return;
  if (!img.getAttribute('src')) img.src = 'assets/wxqr.png';
}

function dismissAd() {
  const a = document.getElementById('top-ad');
  if (a) a.style.display = 'none';
  try { localStorage.setItem('ad_dismissed', '1'); } catch (e) {}
}

async function submitFeedback() {
  const content = document.getElementById('fb-content').value.trim();
  const contact = document.getElementById('fb-contact').value.trim();
  if (content.length < 5) { toast('反馈内容至少5个字'); return; }
  try {
    await api('feedback', { method: 'POST', body: { content, contact } });
    closeModal();
    toast('感谢反馈，我们已经收到！');
  } catch (err) { toast(err.message, 'error'); }
}
async function deleteFeedback(id) {
  if (!await uiConfirm('确定删除这条反馈？', { danger: true, okText: '删除' })) return;
  try { await api('feedback_delete', { method: 'POST', body: { id } }); router(); } catch (e) { toast(e.message, 'error'); }
}

async function toggleModule(mkey) {
  if (!await uiConfirm(`确定切换模块「${mkey}」的启用状态？`)) return;
  try {
    const r = await api('module_toggle', { method: 'POST', body: { mkey } });
    gModules[mkey] = !!r.enabled;
    renderNavbar();
    router();
  } catch (e) { toast(e.message, 'error'); }
}

async function togglePubModule(mkey) {
  const slug = curPubSlug || (serverState && serverState.slug) || '';
  if (!slug) return;
  try {
    const r = await api('pub_module_toggle', { method: 'POST', body: { slug, mkey } });
    curPubMods[mkey] = !!r.enabled;
    router();
  } catch (e) { toast(e.message, 'error'); }
}

// ============ 平台指令终端（白名单指令解释器） ============
let termHistory = [];
let termHistIdx = -1;
function termPrint(text) {
  const out = document.getElementById('term-out');
  if (!out) return;
  String(text).split('\n').forEach(l => {
    const d = document.createElement('div');
    d.className = 'term-line';
    d.textContent = l;
    out.appendChild(d);
  });
  out.scrollTop = out.scrollHeight;
}
async function runTermCmd() {
  const inp = document.getElementById('term-input');
  if (!inp) return;
  const cmd = inp.value.trim();
  if (!cmd) return;
  termPrint('$ ' + cmd);
  termHistory.push(cmd); termHistIdx = termHistory.length;
  inp.value = '';
  try {
    const r = await api('admin_cmd', { method: 'POST', body: { cmd } });
    (r.lines || []).forEach(l => termPrint(l));
  } catch (e) { termPrint('错误: ' + e.message); }
}
function termKey(e) {
  const inp = document.getElementById('term-input');
  if (!inp) return;
  if (e.key === 'Enter') { e.preventDefault(); runTermCmd(); }
  else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (termHistIdx > 0) { termHistIdx--; inp.value = termHistory[termHistIdx] || ''; }
  } else if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (termHistIdx < termHistory.length - 1) { termHistIdx++; inp.value = termHistory[termHistIdx] || ''; }
    else { termHistIdx = termHistory.length; inp.value = ''; }
  }
}

// ============ 刊物内昵称（类 QQ 群昵称） ============
async function editPubNickname(slug) {
  if (!currentUser) { navigate('/login'); return; }
  let cur = '';
  try { cur = (await api('publication', { query: { slug } })).my_nickname || ''; } catch (e) {}
  const nn = await uiPrompt('留空则清除，最多20字', cur, { title: '设置刊物内昵称' });
  if (nn === null) return;
  try {
    await api('pub_nickname', { method: 'POST', body: { slug, nickname: nn.trim() } });
    router();
  } catch (err) { toast(err.message, 'error'); }
}

// ============ 社区加入：QQ 式申请 / Discord 式邀请链接 ============
function openCommunityApply(slug) {
  const inner = `
    <h3>🏘️ 申请加入社区</h3>
    <p class="text-muted" style="font-size:13px">向《${escapeHtml(slug)}》社区管理员提交加入申请，审核通过后即成为成员（读者身份组）。</p>
    <textarea id="ca-msg" class="input" rows="4" placeholder="说点什么吧（可选，最多 500 字）" style="width:100%;margin-top:8px"></textarea>
    <div class="mt-2 flex gap-1" style="justify-content:flex-end">
      <button class="btn btn-primary" id="ca-submit">提交申请</button>
    </div>`;
  openModal(inner);
  document.getElementById('ca-submit').onclick = async () => {
    const btn = document.getElementById('ca-submit');
    btn.disabled = true;
    try {
      await api('community_apply', { method: 'POST', body: { slug, message: document.getElementById('ca-msg').value } });
      chatToast('✓ 申请已提交，等待管理员审核');
      closeModal();
      if (location.hash.indexOf('/community') === -1) router();
    } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
  };
}
window.openCommunityApply = openCommunityApply;

async function handleInvite(main, token) {
  if (!currentUser) { navigate('/login'); return; }
  main.innerHTML = `<div class="empty"><div class="spinner"></div><p class="text-muted mt-1">正在通过邀请链接加入社区…</p></div>`;
  try {
    const d = await api('community_invite_use', { query: { token } });
    chatToast(d.already ? '你已是该社区成员' : '✓ 已通过邀请链接加入社区');
    navigate('#/pub/' + encodeURIComponent(d.slug) + '/community');
  } catch (e) {
    main.innerHTML = `<div class="alert alert-error">${escapeHtml(e.message)}<div class="mt-2"><a href="#/" class="btn btn-sm">返回首页</a></div></div>`;
  }
}

// ============ 刊物「服务器」（Discord/Kook 式：频道 + 聊天 + 发帖） ============
let serverState = { slug: null, channels: [], activeId: null, timer: null, canManage: false };
let curPubSlug = '';   // 当前刊物 slug（供 togglePubModule 等使用）

async function renderPubServer(main, slug) {
  if (!currentUser) { navigate('/login'); return; }
  main.innerHTML = `
    <div class="container" style="max-width:1100px">
      <div class="pub-server">
        <div class="ps-side">
          <div class="ps-side-head">
            <a href="#/pub/${slug}" class="ps-back">← 返回刊物</a>
          </div>
          <div id="ps-channels" class="ps-channels"><div class="loading"><div class="spinner"></div></div></div>
        </div>
        <div class="ps-main" id="ps-main">
          <div class="empty"><p class="text-muted">选择一个频道开始</p></div>
        </div>
      </div>
    </div>`;
  serverState = { slug, channels: [], activeId: null, timer: null, canManage: false };
  curPubSlug = slug;
  // 取刊物权限，决定能否管理频道
  try {
    const d = await api('publication', { query: { slug } });
    const pub = d.publication || {};
    serverState.canManage = (currentUser && pub.owner_id == currentUser.id) || hasPerm(d.my_perms || 0, PERM.MANAGE_PUB);
    // 非成员不可进入社区，提示申请加入
    const isMember = (d.my_roles && d.my_roles.length) || pub.owner_id == (currentUser && currentUser.id);
    if (!isMember) {
      main.innerHTML = `<div class="container" style="max-width:760px"><div class="card">
        <h2>🏘️ 《${escapeHtml(pub.name)}》社区</h2>
        <p class="text-muted mt-1">你还没有加入这个社区。提交申请，等待管理员审核通过后即可进入（订阅也会自动成为读者成员）。</p>
        <button class="btn btn-primary mt-2" onclick="openCommunityApply('${slug}')">🏘️ 申请加入社区</button>
      </div></div>`;
      return;
    }
  } catch (e) {}
  await serverLoadChannels(slug);
}

async function serverLoadChannels(slug) {
  const el = document.getElementById('ps-channels');
  if (!el) return;
  let channels;
  try { const d = await api('pub_channels', { query: { slug } }); channels = d.channels || []; }
  catch (e) { el.innerHTML = `<div class="alert alert-error">${e.message}</div>`; return; }
  serverState.channels = channels;
  const groups = {};
  channels.forEach(c => { (groups[c.grp] = groups[c.grp] || []).push(c); });
  let html = '';
  Object.keys(groups).forEach(g => {
    html += `<div class="ps-group"><div class="ps-group-name">${escapeHtml(g)}</div>`;
    html += groups[g].map(c => `
      <div class="ps-channel ${c.id === serverState.activeId ? 'active' : ''} ${c.type === 'post' ? 'is-post' : ''}" onclick="serverOpenChannel(${c.id})">
        <span class="ps-ico">${c.type === 'post' ? '📌' : '💬'}</span>
        <span class="ps-cname">${escapeHtml(c.name)}</span>
        ${serverState.canManage ? `<span class="ps-del" onclick="event.stopPropagation();serverDeleteChannel(${c.id})" title="删除频道">✕</span>` : ''}
      </div>`).join('');
    html += `</div>`;
  });
  if (serverState.canManage) html += `<div class="ps-group"><button class="btn btn-sm btn-block" onclick="serverNewChannel()">+ 新建频道</button></div>`;
  el.innerHTML = html;
  if (!serverState.activeId && channels.length) {
    const first = channels.find(c => c.type === 'chat') || channels[0];
    serverOpenChannel(first.id);
  }
}

async function serverOpenChannel(id) {
  serverState.activeId = id;
  const ch = serverState.channels.find(c => c.id === id);
  if (serverState.timer) { clearInterval(serverState.timer); serverState.timer = null; }
  const side = document.getElementById('ps-channels');
  if (side) serverLoadChannels(serverState.slug); // 重新渲染侧栏高亮
  const main = document.getElementById('ps-main');
  if (!ch || !main) return;
  if (ch.type === 'chat') {
    main.innerHTML = `
      <div class="ps-chat-head"><span class="ps-ico">💬</span> ${escapeHtml(ch.name)} <span class="text-muted" style="font-size:12px">（聊天频道）</span></div>
      <div class="ps-msg" id="ps-msg-body"></div>
      <form onsubmit="serverSend(event, ${ch.id})" class="ps-compose">
        <textarea id="ps-msg-input" required minlength="1" placeholder="在频道里说点什么…" rows="2"></textarea>
        <button type="submit" class="btn btn-primary">发送</button>
      </form>`;
    await serverLoadChat(ch.id);
    serverState.timer = setInterval(async () => {
      if (circuitOpen || document.hidden) return;
      try { await serverLoadChat(ch.id); }
      catch (e) {
        // 连接抖动：暂停轮询，6 秒后自动重连（防爆连接）
        clearInterval(serverState.timer); serverState.timer = null;
        setTimeout(() => { if (serverState.activeId === ch.id && !serverState.timer) serverOpenChannel(ch.id); }, 8000);
      }
    }, 8000);
  } else {
    main.innerHTML = `
      <div class="ps-chat-head"><span class="ps-ico">📌</span> ${escapeHtml(ch.name)} <span class="text-muted" style="font-size:12px">（发帖频道）</span>
        <button class="btn btn-sm btn-primary ml-1" onclick="serverNewPost(${ch.id})">+ 发帖</button></div>
      <div id="ps-posts" class="ps-posts"><div class="loading"><div class="spinner"></div></div></div>`;
    await serverLoadPosts(ch.id);
  }
}

async function serverLoadChat(channelId) {
  const body = document.getElementById('ps-msg-body');
  if (!body) return;
  let msgs;
  try { const d = await api('pub_chat', { query: { channel: channelId } }); msgs = d.messages || []; }
  catch (e) { return; }
  body.innerHTML = msgs.length ? msgs.map(m => {
    const av = m.user_avatar ? `<img class="conv-avatar" src="${m.user_avatar}" alt="" onerror="this.remove()">` : `<div class="conv-avatar">${(m.user_name || '?')[0].toUpperCase()}</div>`;
    return `
      <div class="ps-msg-row">
        ${av}
        <div class="ps-msg-col">
          <div class="ps-msg-name">${escapeHtml(m.user_name || '')}<span class="ps-msg-time">${fmtMsgTime(m.created_at)}</span></div>
          <div class="ps-msg-text">${escapeHtml(m.body)}</div>
        </div>
      </div>`;
  }).join('') : '<div class="empty"><p class="text-muted">还没有消息，来打个招呼吧</p></div>';
  body.scrollTop = body.scrollHeight;
}

async function serverSend(e, channelId) {
  e.preventDefault();
  const input = document.getElementById('ps-msg-input');
  const body = input.value;
  if (!body.trim()) return;
  const form = input.closest('form');
  const btn = form ? form.querySelector('button') : null;
  input.disabled = true; if (btn) btn.disabled = true;
  try {
    await api('pub_chat', { method: 'POST', body: { channel: channelId, body } });
    input.value = '';
    await serverLoadChat(channelId);
  } catch (err) { toast(err.message, 'error'); }
  finally { input.disabled = false; if (btn) btn.disabled = false; input.focus(); }
}

async function serverLoadPosts(channelId) {
  const el = document.getElementById('ps-posts');
  if (!el) return;
  let posts;
  try { const d = await api('pub_posts', { query: { channel: channelId } }); posts = d.posts || []; }
  catch (e) { el.innerHTML = `<div class="alert alert-error">${e.message}</div>`; return; }
  el.innerHTML = posts.length ? posts.map(p => `
    <div class="card">
      <div class="card-title">${escapeHtml(p.title)}</div>
      <div class="card-meta"><span>${escapeHtml(p.user_name || '')}</span><span>${fmtMsgTime(p.created_at)}</span></div>
      <div class="card-desc" style="white-space:pre-wrap">${escapeHtml(p.body)}</div>
    </div>`).join('') : '<div class="empty"><p class="text-muted">还没有帖子，点「发帖」开始</p></div>';
}

function serverNewPost(channelId) {
  openModal(`
    <h3>📌 发帖</h3>
    <div class="form-group"><label>标题 *</label><input type="text" id="sp-title" maxlength="100" placeholder="帖子标题"></div>
    <div class="form-group"><label>正文 *</label><textarea id="sp-body" rows="6" maxlength="8000" placeholder="写点什么…"></textarea></div>
    <button class="btn btn-primary" onclick="serverSubmitPost(${channelId})">发布</button>
  `);
}
async function serverSubmitPost(channelId) {
  const title = document.getElementById('sp-title').value;
  const body = document.getElementById('sp-body').value;
  if (!title.trim() || !body.trim()) { toast('标题和正文都不能为空'); return; }
  try {
    await api('pub_posts', { method: 'POST', body: { channel: channelId, title, body } });
    closeModal();
    await serverLoadPosts(channelId);
  } catch (e) { toast(e.message, 'error'); }
}

function serverNewChannel() {
  const inSt = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:14px;margin-top:4px';
  const el = _uiOverlay(`
    <h3 style="margin:0 0 12px;font-size:17px">新建频道</h3>
    <label style="font-size:13px;color:var(--text2)">频道名称</label>
    <input id="nc-name" style="${inSt}" placeholder="例如：公告、闲聊">
    <label style="font-size:13px;color:var(--text2);display:block;margin-top:12px">频道类型</label>
    <div class="flex gap-1" style="margin-top:6px">
      <label class="btn btn-ghost" style="flex:1;cursor:pointer"><input type="radio" name="nc-type" value="chat" checked style="margin-right:6px">💬 聊天频道</label>
      <label class="btn btn-ghost" style="flex:1;cursor:pointer"><input type="radio" name="nc-type" value="post" style="margin-right:6px">📌 发帖频道</label>
    </div>
    <label style="font-size:13px;color:var(--text2);display:block;margin-top:12px">分组名称</label>
    <input id="nc-grp" style="${inSt}" value="综合">
    <div class="mt-2 flex gap-1" style="justify-content:flex-end;margin-top:16px">
      <button class="btn btn-ghost" id="nc-cancel">取消</button>
      <button class="btn btn-primary" id="nc-ok">创建</button>
    </div>`);
  setTimeout(() => { const n = el.querySelector('#nc-name'); if (n) n.focus(); }, 30);
  el.querySelector('#nc-cancel').onclick = _uiClose;
  el.addEventListener('click', (e) => { if (e.target === el) _uiClose(); });
  el.querySelector('#nc-ok').onclick = async () => {
    const name = el.querySelector('#nc-name').value.trim();
    if (!name) { toast('请输入频道名称', 'error'); return; }
    const type = (el.querySelector('input[name="nc-type"]:checked') || {}).value || 'chat';
    const grp = el.querySelector('#nc-grp').value.trim() || '综合';
    _uiClose();
    try {
      await api('pub_channels', { method: 'POST', body: { slug: serverState.slug, name, type, grp } });
      await serverLoadChannels(serverState.slug);
      toast('频道已创建');
    } catch (e) { toast(e.message, 'error'); }
  };
}

async function serverDeleteChannel(id) {
  const ch = serverState.channels.find(c => c.id === id);
  if (!ch) return;
  if (!await uiConfirm(`删除频道「${ch.name}」？其聊天记录与帖子也会一并删除。`, { danger: true, okText: '删除' })) return;
  try {
    await api('pub_channels', { method: 'DELETE', body: { slug: serverState.slug, channel: id } });
    if (serverState.activeId === id) serverState.activeId = null;
    await serverLoadChannels(serverState.slug);
    const main = document.getElementById('ps-main');
    if (main) main.innerHTML = '<div class="empty"><p class="text-muted">选择一个频道开始</p></div>';
  } catch (e) { toast(e.message, 'error'); }
}

// ============ 启动 ============
init();
