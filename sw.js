// 万刊网 网站加速器 · Service Worker
// 作用：静态资源缓存优先（秒开、抗连接不稳定）；接口/首页网络优先、失败回退。
// 关键防坑：绝不缓存、绝不吐出 InfinityFree 的反爬验证页（slowAES / __test），否则会把验证页当首页死循环。
const CACHE = 'wankan-accel-20260717g';
const ASSETS = ['/index.html', '/css/style.css', '/js/app.js', '/js/md.js'];

// 判断响应是否"坏"（反爬验证页 / 错误页 / 非预期类型）：坏的一律不缓存、不使用
function isBadResponse(resp, path) {
  if (!resp || !resp.ok) return true;
  const ct = resp.headers.get('content-type') || '';
  // 对 js/css/图片/字体等资源，若返回 HTML → 必是验证页或错误页，判坏
  if (/\.(js|css|png|jpe?g|svg|ico|woff2?)$/i.test(path) && ct.indexOf('text/html') !== -1) return true;
  return false;
}

// 异步判断"是否真首页"（不是验证页），是才写缓存；读克隆，不阻塞返回
function cacheIfRealHtml(resp) {
  try {
    const cp = resp.clone();
    cp.text().then(txt => {
      if (resp.ok && txt.length > 2000 &&
          txt.indexOf('slowAES') === -1 && txt.indexOf('__test=') === -1) {
        caches.open(CACHE).then(c => c.put('/index.html', new Response(txt, {
          headers: { 'content-type': resp.headers.get('content-type') || 'text/html' }
        })));
      }
    }).catch(() => {});
  } catch (e) {}
}

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c =>
      Promise.all(ASSETS.map(u =>
        fetch(u, { cache: 'reload' }).then(resp => {
          if (!isBadResponse(resp, u)) return c.put(u, resp);   // 只精缓"好"响应
        }).catch(() => {})
      ))
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;               // 写请求一律走网络
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;      // 跨域不处理

  // 接口：网络优先，失败回退已缓存响应
  if (url.pathname.indexOf('/api.php') === 0) {
    e.respondWith(fetch(req).catch(() => caches.match(req)));
    return;
  }

  // 导航 / HTML：网络优先，原样返回（验证页也放行，让浏览器自己跑挑战）；只缓存真首页；离线回退缓存
  const acceptHtml = (req.headers.get('accept') || '').indexOf('text/html') !== -1;
  if (req.mode === 'navigate' || acceptHtml) {
    e.respondWith(
      fetch(req).then(resp => { cacheIfRealHtml(resp); return resp; })
                .catch(() => caches.match('/index.html'))
    );
    return;
  }

  // 静态资源：缓存优先；未命中回源，且只缓存"好"响应（不缓存验证页/错误页）
  e.respondWith(
    caches.match(req).then(r => r || fetch(req).then(resp => {
      if (!isBadResponse(resp, url.pathname)) {
        const cp = resp.clone();
        caches.open(CACHE).then(c => c.put(req, cp));
      }
      return resp;
    }).catch(() => caches.match(req)))
  );
});
