// 万刊网 网站加速器 · Service Worker
// 作用：静态资源缓存优先（秒开、抗连接不稳定）；接口网络优先、失败回退（抗 Cloudflare 抖动）
const CACHE = 'wankan-accel-20260716h';
const ASSETS = ['/', '/index.html', '/css/style.css', '/js/app.js', '/js/md.js'];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(ASSETS).catch(() => {})).then(() => self.skipWaiting())
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
  if (req.method !== 'GET') return;            // 写请求一律走网络
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;   // 跨域不处理

  if (url.pathname.indexOf('/api.php') === 0) {
    // 接口：网络优先，失败回退到已缓存的响应（抗 Cloudflare 挑战 / 连接抖动）
    e.respondWith(
      fetch(req).catch(() => caches.match(req).then(r => r || fetch(req)))
    );
    return;
  }

  // 静态资源：缓存优先，未命中则回源并写入缓存（秒开）
  e.respondWith(
    caches.match(req).then(r => r || fetch(req).then(resp => {
      const cp = resp.clone();
      caches.open(CACHE).then(c => c.put(req, cp));
      return resp;
    }).catch(() => caches.match('/index.html')))
  );
});
