<?php
/**
 * 万刊网 PHP 后端 API
 * 基于 PHP + SQLite，无需任何服务器配置
 * 数据持久化在 data.db 文件中，全站共享
 */

// 调试模式已关闭显示（生产安全）；致命错误仍由下方 shutdown handler 以 JSON 透出（仅 basename，不泄露路径）
ini_set('display_errors', '0');
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_RECOVERABLE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['PHP_FATAL' => $e['message'], 'line' => $e['line'], 'file' => basename($e['file'])]);
    }
});
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_NOTICE);

// 缓存策略由 json_response() 统一决定（见下方）：
// - 登录 / 写操作 / 带身份的接口 → no-store（登录态不被缓存，安全）
// - 公开只读接口（且加速器开启时）→ public 缓存（加速 + 抗连接抖动）
// 这里仅留一个标记头，不做任何强制缓存/禁缓存。
if (!headers_sent()) {
    header('X-Accelerator: wankan');
}

// 会话 Cookie 设为 HttpOnly + Secure(按协议动态) + 同站（防 XSS 读取与 CSRF）
$secure = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '');
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => $secure,
    'cookie_samesite' => 'Lax',
]);

// ============ 数据库初始化 ============
$dbFile = __DIR__ . '/data.db';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// 网站加速器开关（存于 site_meta.accelerator_on）；默认关闭
$ACCEL_ON = false;
try { $r = $db->query("SELECT v FROM site_meta WHERE k='accelerator_on'"); $row = $r->fetch(); $ACCEL_ON = ($row && $row['v'] === '1'); } catch (Exception $e) {}

// 创建表结构
$db->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    email TEXT,
    bio TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now', '+8 hours'))
)");

$db->exec("
CREATE TABLE IF NOT EXISTS publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    description TEXT DEFAULT '',
    tags TEXT DEFAULT '',
    owner_id INTEGER NOT NULL,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (owner_id) REFERENCES users(id)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id INTEGER NOT NULL,
    issue_number INTEGER NOT NULL,
    title TEXT DEFAULT '',
    description TEXT DEFAULT '',
    published_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (publication_id) REFERENCES publications(id),
    UNIQUE(publication_id, issue_number)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    author TEXT NOT NULL,
    author_email TEXT DEFAULT '',
    abstract TEXT DEFAULT '',
    body TEXT NOT NULL,
    tags TEXT DEFAULT '',
    status TEXT DEFAULT 'pending',
    review_comment TEXT DEFAULT '',
    reviewed_by INTEGER,
    reviewed_at TEXT,
    issue_id INTEGER,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (publication_id) REFERENCES publications(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (issue_id) REFERENCES issues(id)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    user_id INTEGER,
    author_name TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    publication_id INTEGER NOT NULL,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    UNIQUE(user_id, publication_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (publication_id) REFERENCES publications(id)
)");

// ============ 私信 ============
$db->exec("
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER NOT NULL,
    receiver_id INTEGER NOT NULL,
    body TEXT NOT NULL,
    read INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (receiver_id) REFERENCES users(id)
)");

// ============ 关注 ============
$db->exec("
CREATE TABLE IF NOT EXISTS follows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL,
    following_id INTEGER NOT NULL,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    UNIQUE(follower_id, following_id),
    FOREIGN KEY (follower_id) REFERENCES users(id),
    FOREIGN KEY (following_id) REFERENCES users(id)
)");

// ============ 反馈 ============
$db->exec("
CREATE TABLE IF NOT EXISTS feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username TEXT DEFAULT '',
    contact TEXT DEFAULT '',
    content TEXT NOT NULL,
    status TEXT DEFAULT 'new',
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

// ============ 平台操作日志（撤回/撤销任何行为的依据） ============
// 设计原则：本表只记录「可被管理员撤回」的治理行为。
// 删除类行为采用「快照还原」：删除前把整行存进 data，撤回时按原 id 重新插入，
// 因此无需给各业务表加 deleted_at 列、也无需改动现有 SELECT 查询。
// 注意：全站严禁提供任何「删除整个虚拟主机 / 删除网站 / 清空数据库 / 删除全部文件」
// 的指令或按钮——管理员权限 = 除毁站外全开。
$db->exec("
CREATE TABLE IF NOT EXISTS admin_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id INTEGER,                 -- 操作者（平台管理员）用户 id
    action TEXT NOT NULL,            -- ban/unban/promote/demote/delete_user/delete_pub/delete_article/delete_post/delete_feedback/delete_comment/announce/...
    target_type TEXT DEFAULT '',      -- user/article/pub/post/feedback/comment/...
    target_id TEXT DEFAULT '',       -- 目标主键（字符串以兼容复合）
    summary TEXT DEFAULT '',         -- 人类可读摘要
    data TEXT DEFAULT '',            -- 撤回所需快照/原值（JSON）
    created_at TEXT DEFAULT (datetime('now', '+8 hours'))
)");

// 网站加速器：公开只读接口的服务端响应缓存
$db->exec("
CREATE TABLE IF NOT EXISTS site_cache (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL,
    expires INTEGER NOT NULL
)");

// ============ 刊物内昵称（类 QQ 群昵称 / Discord 服务器昵称） ============
$db->exec("
CREATE TABLE IF NOT EXISTS pub_nicknames (
    user_id INTEGER NOT NULL,
    publication_id INTEGER NOT NULL,
    nickname TEXT NOT NULL,
    PRIMARY KEY (user_id, publication_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (publication_id) REFERENCES publications(id)
)");

// ============ 通知（消息中心「通知」tab 的数据源） ============
$db->exec("
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,        -- 接收者
    type TEXT NOT NULL,              -- submit/comment/dm/announce/follow/publish
    actor_id INTEGER,                -- 触发者用户 id
    body TEXT NOT NULL,
    link TEXT DEFAULT '',            -- 点击跳转（前端 hash 路由）
    `read` INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
)");

// ============ 权限系统（类 Discord / Kook 身份组 + 权限位） ============
$db->exec("
CREATE TABLE IF NOT EXISTS platform_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    color TEXT DEFAULT '#5865f2',
    permissions INTEGER DEFAULT 0,
    position INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now', '+8 hours'))
)");

$db->exec("
CREATE TABLE IF NOT EXISTS user_platform_roles (
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (role_id) REFERENCES platform_roles(id)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS pub_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    color TEXT DEFAULT '#5865f2',
    permissions INTEGER DEFAULT 0,
    position INTEGER DEFAULT 0,
    FOREIGN KEY (publication_id) REFERENCES publications(id)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS pub_members (
    user_id INTEGER NOT NULL,
    publication_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, publication_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (publication_id) REFERENCES publications(id),
    FOREIGN KEY (role_id) REFERENCES pub_roles(id)
)");

// 社区加入：QQ 式申请（审核）与 Discord 式邀请链接
$db->exec("
CREATE TABLE IF NOT EXISTS community_join_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    message TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',   -- pending / approved / rejected
    reviewed_by INTEGER,
    reviewed_at TEXT,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (publication_id) REFERENCES publications(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS community_invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    created_by INTEGER NOT NULL,
    max_uses INTEGER DEFAULT 0,        -- 0 = 不限次数
    uses INTEGER DEFAULT 0,
    expires_at TEXT,                     -- datetime 或 NULL（永不过期）
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (publication_id) REFERENCES publications(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

// 刊物「服务器」：频道（分组 + 类型 chat/post）、频道聊天、频道帖子
$db->exec("
CREATE TABLE IF NOT EXISTS pub_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id INTEGER NOT NULL,
    grp TEXT DEFAULT '综合',
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'chat',
    position INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (publication_id) REFERENCES publications(id)
)");
$db->exec("
CREATE TABLE IF NOT EXISTS pub_chat (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL,
    publication_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (channel_id) REFERENCES pub_channels(id),
    FOREIGN KEY (publication_id) REFERENCES publications(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");
$db->exec("
CREATE TABLE IF NOT EXISTS pub_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL,
    publication_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now', '+8 hours')),
    FOREIGN KEY (channel_id) REFERENCES pub_channels(id),
    FOREIGN KEY (publication_id) REFERENCES publications(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

// 模块 / 插件 / 扩展：平台级开关 + 刊物级覆盖
$db->exec("
CREATE TABLE IF NOT EXISTS modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mkey TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT DEFAULT '',
    scope TEXT NOT NULL DEFAULT 'pub',
    enabled INTEGER DEFAULT 1,
    position INTEGER DEFAULT 0
)");
$db->exec("
CREATE TABLE IF NOT EXISTS pub_module_settings (
    publication_id INTEGER NOT NULL,
    mkey TEXT NOT NULL,
    enabled INTEGER DEFAULT 1,
    PRIMARY KEY (publication_id, mkey),
    FOREIGN KEY (publication_id) REFERENCES publications(id)
)");
$db->exec("
CREATE TABLE IF NOT EXISTS site_meta (
    k TEXT PRIMARY KEY,
    v TEXT DEFAULT ''
)");

// 登录失败记录（防撞库/暴力破解）：按客户端 IP 累计
$db->exec("
CREATE TABLE IF NOT EXISTS login_fails (
    ip TEXT PRIMARY KEY,
    fails INTEGER DEFAULT 0,
    first_at INTEGER DEFAULT 0,
    last_at INTEGER DEFAULT 0
)");

// 种子默认模块（仅首次）
$modCount = $db->query("SELECT COUNT(*) FROM modules")->fetchColumn();
if (!$modCount) {
    $defaultModules = [
        ['dm', '私信', '用户之间的私信', 'platform', 1, 10],
        ['feedback', '反馈', '意见反馈入口', 'platform', 1, 20],
        ['announce', '公告', '平台公告广播给所有用户', 'platform', 1, 30],
        ['submit', '投稿', '向刊物投稿', 'pub', 1, 10],
        ['comments', '评论', '文章评论', 'pub', 1, 20],
        ['server', '社区', '刊物内聊天与发帖（Discord 式）', 'pub', 1, 30],
        ['rss', 'RSS', '刊物 RSS 订阅源', 'pub', 1, 40],
    ];
    $mstmt = $db->prepare('INSERT INTO modules (mkey, name, description, scope, enabled, position) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($defaultModules as $m) { $mstmt->execute($m); }
}

// 权限位（与前端 PERM 保持一致）
define('PERM_VIEW', 1);
define('PERM_POST', 2);
define('PERM_COMMENT', 4);
define('PERM_CREATE_PUB', 8);
define('PERM_MANAGE_PUB', 16);
define('PERM_MANAGE_ARTICLES', 32);
define('PERM_REVIEW', 64);
define('PERM_MANAGE_MEMBERS', 128);
define('PERM_MANAGE_ROLES', 256);
define('PERM_MANAGE_USERS', 512);
define('PERM_BAN', 1024);
define('PERM_PIN', 2048);
define('PERM_MANAGE_PLATFORM', 4096);
define('PERM_ADMIN', 8192);
define('PERM_ROOT', 1 << 30); // 平台总构建师（prime admin）专属标识位；仅「权限ID=0」身份组持有，代表最高控制权
define('PERM_ALL', PERM_VIEW|PERM_POST|PERM_COMMENT|PERM_CREATE_PUB|PERM_MANAGE_PUB|PERM_MANAGE_ARTICLES|PERM_REVIEW|PERM_MANAGE_MEMBERS|PERM_MANAGE_ROLES|PERM_MANAGE_USERS|PERM_BAN|PERM_PIN|PERM_MANAGE_PLATFORM|PERM_ADMIN);

// 平台默认身份组 [名称, 颜色, 权限位, 排序]
$platformDefaultRoles = [
    ['平台管理员', '#f04747', PERM_ALL, 100],
    ['超级版主',   '#faa61a', PERM_MANAGE_USERS|PERM_MANAGE_ROLES|PERM_MANAGE_PLATFORM|PERM_BAN|PERM_VIEW|PERM_POST|PERM_COMMENT, 90],
    ['版主',       '#faa61a', PERM_MANAGE_USERS|PERM_BAN|PERM_VIEW|PERM_POST|PERM_COMMENT, 80],
    ['认证用户',   '#3ba55d', PERM_VIEW|PERM_POST|PERM_COMMENT|PERM_CREATE_PUB|PERM_PIN, 50],
    ['普通用户',   '#99aab5', PERM_VIEW|PERM_POST|PERM_COMMENT|PERM_CREATE_PUB, 10],
    ['新人',       '#99aab5', PERM_VIEW|PERM_COMMENT, 0],
];

// 种子平台身份组（仅首次）
$prCount = $db->query("SELECT COUNT(*) FROM platform_roles")->fetchColumn();
if ($prCount == 0) {
    $prIds = [];
    foreach ($platformDefaultRoles as $r) {
        $db->prepare("INSERT INTO platform_roles (name, color, permissions, position) VALUES (?, ?, ?, ?)")
            ->execute([$r[0], $r[1], $r[2], $r[3]]);
        $prIds[$r[0]] = $db->lastInsertId();
    }
    $defaultRoleId = $prIds['普通用户'];
    $adminRoleId   = $prIds['平台管理员'];
    $users = $db->query("SELECT id FROM users")->fetchAll();
    foreach ($users as $u) {
        $db->prepare("INSERT OR IGNORE INTO user_platform_roles (user_id, role_id) VALUES (?, ?)")
            ->execute([$u['id'], $defaultRoleId]);
        if ($u['id'] == 1) {
            $db->prepare("INSERT OR IGNORE INTO user_platform_roles (user_id, role_id) VALUES (?, ?)")
                ->execute([$u['id'], $adminRoleId]);
        }
    }
}

// 平台总构建师（prime admin，权限ID=0）：
// 它是整个虚拟主机/网站后台的顶层控制者，高于「平台管理员」。
// 认证方式=登录会话认证（require_login + 持有 PERM_ROOT），与邮箱验证(verified)无关——完全控制者不靠邮箱认证。
// 该身份组在 platform_roles 中以 id=0 存储（“权限ID=0”即指此），仅其持有 PERM_ROOT 位。
$primeRoleId = $db->query("SELECT id FROM platform_roles WHERE id=0")->fetchColumn();
if ($primeRoleId === false) {
    $db->prepare("INSERT INTO platform_roles (id, name, color, permissions, position) VALUES (0, '平台总构建师', '#ffd700', ?, 1000)")
        ->execute([PERM_ALL | PERM_ROOT]);
    $primeRoleId = 0;
}
// 幂等对齐：无论行是否已存在，均纠正名称/颜色/权限位（含旧部署遗留的旧名）
$db->prepare("UPDATE platform_roles SET name='平台总构建师', color='#ffd700', permissions=?, position=1000 WHERE id=0")
    ->execute([PERM_ALL | PERM_ROOT]);
// 幂等对齐：认证用户额外持有 PERM_PIN（可置顶），与普通用户真正区分
$db->prepare("UPDATE platform_roles SET permissions = (permissions | ?) WHERE name='认证用户'")
    ->execute([PERM_PIN]);
// 确保 id=1 为初始完全控制者（认证=登录会话，非邮箱验证）
$primeChk = $db->prepare('SELECT 1 FROM user_platform_roles WHERE user_id=1 AND role_id=0');
$primeChk->execute();
if (!$primeChk->fetch()) {
    $db->prepare('INSERT OR IGNORE INTO user_platform_roles (user_id, role_id) VALUES (1, 0)')->execute();
}

// 平台管理员判定：纯看「平台管理员」身份组成员（user_platform_roles）。
// 不绑定任何邮箱。seed 时已把 id=1 加入该身份组作为初始管理员；
// 之后任何用户被加入该身份组（后台 user promote / 身份组管理）即成为全权管理员。
// 无邮箱硬编码。

// 为已有刊物补建身份组 + 主编成员
$allPubs = $db->query("SELECT id, owner_id FROM publications")->fetchAll();
foreach ($allPubs as $pb) {
    $rc = $db->prepare("SELECT COUNT(*) FROM pub_roles WHERE publication_id = ?");
    $rc->execute([$pb['id']]);
    if ($rc->fetchColumn() == 0) {
        seed_pub_roles($db, $pb['id'], $pb['owner_id']);
    }
}

// ============ 字段迁移（兼容旧库） ============
function add_column($db, $table, $col, $def) {
    try { $db->exec("ALTER TABLE $table ADD COLUMN $col $def"); }
    catch (Exception $e) { /* 字段已存在则忽略 */ }
}
$needsSeedVerify = false;
try { $db->query("SELECT verified FROM users LIMIT 1"); }
catch (Exception $e) { $needsSeedVerify = true; }
add_column($db, 'users', 'verified', "INTEGER DEFAULT 0");
add_column($db, 'users', 'verify_token', "TEXT DEFAULT ''");
add_column($db, 'users', 'avatar', "TEXT DEFAULT ''");
add_column($db, 'users', 'token', "TEXT DEFAULT ''");
add_column($db, 'publications', 'avatar', "TEXT DEFAULT ''");
add_column($db, 'users', 'cover', "TEXT DEFAULT ''");
add_column($db, 'publications', 'cover', "TEXT DEFAULT ''");
add_column($db, 'users', 'uid', "INTEGER DEFAULT 0");
add_column($db, 'users', 'banned', "INTEGER DEFAULT 0");
$needUid = $db->query("SELECT COUNT(*) FROM users WHERE uid IS NULL OR uid = 0")->fetchColumn();
if ($needUid > 0) {
    $db->exec("UPDATE users SET uid = id + 100000 WHERE uid IS NULL OR uid = 0");
}
if ($needsSeedVerify) { $db->exec("UPDATE users SET verified = 1"); }

// ============ 工具函数 ============
function json_response($data, $code = 200) {
    global $db, $action, $ACCEL_ON;
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    // 缓存策略：公开只读接口 + 加速器开启 → 服务端缓存；其余（登录/写/带身份）→ 绝不缓存
    // 注：publication / pub_members 会随响应返回 my_perms/my_roles/my_nickname 等用户态，
    // 若按纯 URL 缓存会跨用户串号，故从可缓存白名单移除（保留真正公开只读接口）。
    $cacheable = ['pub_info','federation','articles','issues','announcements','pub_channels'];
    if ($ACCEL_ON && $_SERVER['REQUEST_METHOD'] === 'GET' && $code === 200 && in_array($action, $cacheable, true)) {
        $ck = 'accel_' . $action . '_' . md5($_SERVER['QUERY_STRING']);
        cache_set($db, $ck, $json, 15);
        header('Cache-Control: public, max-age=15, stale-while-revalidate=30');
        header('X-Accel: MISS');
    } else {
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        header('Pragma: no-cache');
        header('Vary: Cookie');
    }
    echo $json;
    exit;
}

// ============ 网站加速器：缓存 / 站点元信息 助手 ============
function cache_get($db, $k) {
    try {
        $st = $db->prepare('SELECT v FROM site_cache WHERE k=? AND expires>?');
        $st->execute([$k, time()]);
        $r = $st->fetch();
        return $r ? $r['v'] : null;
    } catch (Exception $e) { return null; }
}
function cache_set($db, $k, $v, $ttl) {
    try {
        $db->prepare('INSERT OR REPLACE INTO site_cache (k, v, expires) VALUES (?, ?, ?)')
            ->execute([$k, $v, time() + (int)$ttl]);
    } catch (Exception $e) {}
}
function cache_clear($db) {
    try { $db->exec('DELETE FROM site_cache'); } catch (Exception $e) {}
}
function meta_get($db, $k) {
    try { $st = $db->prepare('SELECT v FROM site_meta WHERE k=?'); $st->execute([$k]); $r = $st->fetch(); return $r ? $r['v'] : ''; }
    catch (Exception $e) { return ''; }
}
function meta_set($db, $k, $v) {
    try { $db->prepare('INSERT OR REPLACE INTO site_meta (k, v) VALUES (?, ?)')->execute([$k, $v]); }
    catch (Exception $e) {}
}

// 把相对路径的头像/封面转成绝对 URL，避免前端用相对路径解析失败导致图片裂开
function site_base() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/';
}
function abs_url($path) {
    if (!$path) return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim(site_base(), '/') . '/' . ltrim($path, '/');
}

function get_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) $data = $_POST;
    return $data ?: [];
}

function get_bearer_token() {
    // 优先使用 HttpOnly+Secure+SameSite 的认证 Cookie（不被 JS 读取、不进 URL/日志）
    if (!empty($_COOKIE['wankan_token'])) return $_COOKIE['wankan_token'];
    // 兼容过渡：旧的 Authorization / POST / GET token 方式仍保留
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    if (!empty($_POST['token'])) return $_POST['token'];
    if (!empty($_GET['token'])) return $_GET['token'];
    return '';
}

// 设置/清除认证 Cookie（HttpOnly + Secure + SameSite=Lax）
function set_auth_cookie($token) {
    $secure = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '');
    setcookie('wankan_token', $token, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
function clear_auth_cookie() {
    $secure = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '');
    setcookie('wankan_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// 取真实客户端 IP（Cloudflare 前置时取 CF-Connecting-IP）
function client_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// CSRF 防护：非 GET 的写操作必须携带同源 Origin 或 X-Requested-With 头
function guard_csrf() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($origin !== '') {
        $o = parse_url($origin, PHP_URL_HOST);
        if ($o === false || $o !== $host) json_response(['error' => '非法请求来源'], 403);
        return;
    }
    if ($xrw === 'xmlhttprequest') return; // 同源 XHR/fetch 自动带此头，跨站表单无法伪造
    json_response(['error' => '请求来源校验失败'], 403);
}

function current_user($db) {
    $token = get_bearer_token();
    if ($token) {
        $stmt = $db->prepare('SELECT id, username, email, bio, verified, avatar, cover, token, created_at, uid FROM users WHERE token = ?');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) { attach_platform($db, $user); return $user; }
    }
    if (!isset($_SESSION['user_id'])) return null;
    $stmt = $db->prepare('SELECT id, username, email, bio, verified, avatar, cover, token, created_at, uid FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) attach_platform($db, $user);
    return $user ?: null;
}

function require_login($db) {
    $user = current_user($db);
    if (!$user) json_response(['error' => '请先登录'], 401);
    return $user;
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    if (empty($text)) $text = 'pub-' . time();
    return $text;
}

// ============ 权限助手 ============
function seed_pub_roles($db, $pubId, $ownerId) {
    $roles = [
        ['主编',   '#f04747', PERM_MANAGE_PUB|PERM_MANAGE_ARTICLES|PERM_REVIEW|PERM_MANAGE_MEMBERS|PERM_MANAGE_ROLES|PERM_POST|PERM_COMMENT|PERM_VIEW, 100],
        ['副主编', '#faa61a', PERM_MANAGE_ARTICLES|PERM_REVIEW|PERM_MANAGE_MEMBERS|PERM_POST|PERM_COMMENT|PERM_VIEW, 90],
        ['编辑',   '#faa61a', PERM_MANAGE_ARTICLES|PERM_REVIEW|PERM_POST|PERM_COMMENT|PERM_VIEW, 80],
        ['作者',   '#3ba55d', PERM_POST|PERM_COMMENT|PERM_VIEW, 50],
        ['读者',   '#99aab5', PERM_VIEW|PERM_COMMENT, 10],
    ];
    $chiefId = null;
    foreach ($roles as $r) {
        $db->prepare("INSERT INTO pub_roles (publication_id, name, color, permissions, position) VALUES (?, ?, ?, ?, ?)")
            ->execute([$pubId, $r[0], $r[1], $r[2], $r[3]]);
        if ($r[0] === '主编') $chiefId = $db->lastInsertId();
    }
    $db->prepare("INSERT OR IGNORE INTO pub_members (user_id, publication_id, role_id) VALUES (?, ?, ?)")
        ->execute([$ownerId, $pubId, $chiefId]);
}

function platform_perms_of($db, $userId) {
    // 权限按位(bit)设计：必须用位或(OR)累加，不能用 SUM(算术和会在重叠位进位导致高位权限丢失)
    $stmt = $db->prepare("SELECT r.permissions FROM user_platform_roles ur JOIN platform_roles r ON ur.role_id=r.id WHERE ur.user_id=?");
    $stmt->execute([$userId]);
    $p = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) { $p |= (int)$v; }
    return $p;
}
function platform_roles_of($db, $userId) {
    $stmt = $db->prepare("SELECT r.id, r.name, r.color FROM user_platform_roles ur JOIN platform_roles r ON ur.role_id=r.id WHERE ur.user_id=? ORDER BY r.position DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
function attach_platform($db, &$user) {
    if (!$user) return;
    $user['platform_perms'] = platform_perms_of($db, $user['id']);
    $user['platform_roles'] = platform_roles_of($db, $user['id']);
}
function add_notification($db, $userId, $type, $actorId, $body, $link='') {
    if (!$userId || $userId == $actorId) return; // 不给自己发通知
    $db->prepare('INSERT INTO notifications (user_id, type, actor_id, body, link) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $type, $actorId, $body, $link]);
}
function pub_perms_of($db, $userId, $pubId) {
    $stmt = $db->prepare("SELECT owner_id FROM publications WHERE id=?");
    $stmt->execute([$pubId]);
    $row = $stmt->fetch();
    if ($row && $row['owner_id'] == $userId) return PERM_ALL;
    // 位或累加（同 platform_perms_of）
    $stmt = $db->prepare("SELECT r.permissions FROM pub_members m JOIN pub_roles r ON m.role_id=r.id WHERE m.user_id=? AND m.publication_id=?");
    $stmt->execute([$userId, $pubId]);
    $p = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) { $p |= (int)$v; }
    return $p;
}
function pub_roles_of($db, $userId, $pubId) {
    $stmt = $db->prepare("SELECT r.id, r.name, r.color FROM pub_members m JOIN pub_roles r ON m.role_id=r.id WHERE m.user_id=? AND m.publication_id=? ORDER BY r.position DESC");
    $stmt->execute([$userId, $pubId]);
    return $stmt->fetchAll();
}
function has_perm($perms, $flag) { return ($perms & $flag) === $flag; }
// 平台总构建师（prime admin，权限ID=0）：持有 PERM_ROOT 位、身份组 id=0。认证=登录会话，与邮箱验证无关。
function is_prime_admin($user) { return $user && has_perm($user['platform_perms'] ?? 0, PERM_ROOT); }

// 平台指令终端：白名单指令解释器（供后台「终端」使用，非系统 shell）
function _cnt($db, $sql, $params = []) { $st = $db->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
// ---- 终端沙箱：文件操作严格限制在站点根目录 __DIR__ 内 ----
function _term_safe_path($rel) {
    $base = realpath(__DIR__);
    if ($base === false) return false;
    $relNorm = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    foreach (explode('/', $relNorm) as $s) { if ($s === '..') return false; }
    $relNorm = ltrim($relNorm, '/');
    $candidate = $base . DIRECTORY_SEPARATOR . $relNorm;
    $real = @realpath($candidate);
    if ($real !== false) {
        if (strncmp($real, $base, strlen($base)) !== 0) return false;
        return $real;
    }
    $parent = @realpath(dirname($candidate));
    if ($parent === false) return false;
    if (strncmp($parent, $base, strlen($base)) !== 0) return false;
    return $candidate;
}
function _term_is_protected($absPath) {
    $base = realpath(__DIR__);
    if (strncmp($absPath, $base, strlen($base)) !== 0) return true;
    $bN = str_replace(DIRECTORY_SEPARATOR, '/', $base);
    $pN = str_replace(DIRECTORY_SEPARATOR, '/', $absPath);
    $rel = ltrim(substr($pN, strlen($bN)), '/');
    $protected = ['api.php','index.html','data.db','sw.js','.htaccess','.user.ini','js/app.js','js/md.js','css/style.css'];
    return in_array($rel, $protected, true);
}
function wankan_admin_cmd($db, $user, $raw) {
    $out = [];
    $parts = preg_split('/\s+/', trim($raw));
    $cmd = strtolower($parts[0] ?? '');
    $args = array_slice($parts, 1);
    switch ($cmd) {
        case 'help':
            $out[] = '万刊网平台指令终端 — 可用指令：';
            $out[] = '  help                      显示本帮助';
            $out[] = '  stats                     平台概况统计';
            $out[] = '  pub list                  列出所有刊物节点';
            $out[] = '  pub info <slug>          查看某刊物节点详情';
            $out[] = '  user list [n]            列出用户（默认20，最大100）';
            $out[] = '  user promote <用户> <身份组>   赋予平台身份组';
            $out[] = '  user demote <用户>        移除其全部平台身份组';
            $out[] = '  module <key> on|off      切换平台模块启用状态';
            $out[] = '  announce <文本>           向全站用户发布公告';
            $out[] = '  notify <身份组名> <文本>   向某平台身份组的全部成员发送通知';
            $out[] = '  ban <用户> / unban <用户> 封禁 / 解封用户';
            $out[] = '  cache clear              更新平台缓存清除标记';
            $out[] = '  accel on|off|clear|status  网站加速器（公开只读接口缓存，抗连接抖动）';
            $out[] = '  log [n]                  查看平台操作日志（撤回依据）';
            $out[] = '  undo <日志id>             撤回一条已记录的操作';
            $out[] = '  user delete <用户>        删除用户（可撤回）';
            $out[] = '  pub delete <slug>         删除刊物节点（可撤回）';
            $out[] = '  article delete <id>        删除文章（可撤回）';
            $out[] = '  feedback delete <id>       删除反馈（可撤回）';
            $out[] = '  post delete <id>          删除服务器帖子（可撤回）';
            $out[] = '  host                      ［仅完全控制者］虚拟主机/系统信息（只读）';
            $out[] = '  （注：无任何「删除主机/网站/数据库」指令）';
            $out[] = '';
            $out[] = '— 沙箱内「运维 shell」指令（限本站点目录，非系统 shell）—';
            $out[] = '  phpinfo                  查看 PHP 运行环境（禁用函数/上传限制等，只读）';
            $out[] = '  sql <语句>               执行任意 SQL（SELECT 返回行 / 写操作返回影响数），仅总构建师';
            $out[] = '  ls [路径]                列出站点目录内容（沙箱）';
            $out[] = '  cat <文件>               读取站点内文本文件（沙箱，大文件截断）';
            $out[] = '  write <文件> <内容>       写入/覆盖站点内文本文件（沙箱，关键文件需总构建师）';
            $out[] = '  rm <文件>                删除站点内文件（沙箱，关键文件受保护）';
            $out[] = '  backup                   备份 SQLite 数据库到 backups/';
            break;
        case 'host':
            // 仅「平台总构建师」(权限ID=0) 可用：只读展示虚拟主机/系统信息（不含任何删主机/删站指令）
            if (!is_prime_admin($user)) { $out[] = '⛔ 该指令仅「平台总构建师」(权限ID=0) 可用'; break; }
            $dbFile = $db->query("SELECT file FROM pragma_database_list WHERE name='main'")->fetchColumn();
            $dbSize = file_exists($dbFile) ? round(filesize($dbFile) / 1024, 1) . ' KB' : 'n/a';
            $out[] = '🖥️ 虚拟主机 / 系统信息（完全控制者视图）';
            $out[] = '  PHP 版本      : ' . phpversion();
            $out[] = '  SQLite 版本   : ' . $db->query('SELECT sqlite_version()')->fetchColumn();
            $out[] = '  SAPI          : ' . php_sapi_name();
            $out[] = '  Server        : ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'n/a');
            $out[] = '  DOCUMENT_ROOT : ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a');
            $out[] = '  数据库文件    : ' . ($dbFile ? $dbFile : 'n/a');
            $out[] = '  数据库大小    : ' . $dbSize;
            $out[] = '  磁盘可用      : ' . (function_exists('disk_free_space') ? round(@disk_free_space('.') / 1048576, 1) . ' MB' : 'n/a');
            $out[] = '  当前用户      : ' . ($user['username'] ?? '?') . ' ［权限ID=0 平台总构建师］';
            break;
        case 'accel':
            $sub = strtolower($args[1] ?? 'status');
            if ($sub === 'on') { meta_set($db, 'accelerator_on', '1'); $out[] = '网站加速器已开启'; }
            else if ($sub === 'off') { meta_set($db, 'accelerator_on', '0'); cache_clear($db); $out[] = '网站加速器已关闭'; }
            else if ($sub === 'clear') { cache_clear($db); $out[] = '已清空加速器缓存'; }
            else if ($sub === 'status') {
                $on = meta_get($db, 'accelerator_on') === '1';
                $cnt = 0;
                try { $cnt = $db->query('SELECT COUNT(*) FROM site_cache WHERE expires>' . time())->fetchColumn(); } catch (Exception $e) {}
                $out[] = '网站加速器: ' . ($on ? '开启中 ✅' : '关闭 ⛔');
                $out[] = '  缓存条目(未过期): ' . $cnt;
                $out[] = '  用法: accel on | accel off | accel clear | accel status';
            } else { $out[] = '用法: accel on|off|clear|status'; }
            break;
        case 'stats':
            $u = _cnt($db, 'SELECT COUNT(*) FROM users');
            $p = _cnt($db, 'SELECT COUNT(*) FROM publications');
            $a = _cnt($db, 'SELECT COUNT(*) FROM articles');
            $pend = _cnt($db, "SELECT COUNT(*) FROM articles WHERE status='pending'");
            $pubd = _cnt($db, "SELECT COUNT(*) FROM articles WHERE status='published'");
            $fb = _cnt($db, 'SELECT COUNT(*) FROM feedback');
            $m = _cnt($db, 'SELECT COUNT(*) FROM modules WHERE enabled=1');
            $out[] = "用户: $u | 刊物节点: $p | 文章: $a（待审 $pend / 已发刊 $pubd）";
            $out[] = "反馈: $fb | 启用中模块: $m";
            break;
        case 'pub':
            $sub = strtolower($args[0] ?? '');
            if ($sub === 'list') {
                $ps = $db->query('SELECT id, name, slug, owner_id FROM publications ORDER BY id ASC')->fetchAll();
                if (!$ps) { $out[] = '（暂无刊物节点）'; break; }
                foreach ($ps as $p) {
                    $mid = $p['id'];
                    $mem = _cnt($db, 'SELECT COUNT(*) FROM pub_members WHERE publication_id=?', [$mid]);
                    $ch = _cnt($db, 'SELECT COUNT(*) FROM pub_channels WHERE publication_id=?', [$mid]);
                    $out[] = "  #{$mid} {$p['name']}  slug={$p['slug']}  成员:$mem 频道:$ch";
                }
            } else if ($sub === 'info') {
                $slug = $args[1] ?? '';
                $st = $db->prepare('SELECT * FROM publications WHERE slug=?'); $st->execute([$slug]); $p = $st->fetch();
                if (!$p) { $out[] = "刊物不存在: $slug"; break; }
                $pid = $p['id'];
                $out[] = "刊物: {$p['name']} (slug={$p['slug']}, id=$pid, owner_id={$p['owner_id']})";
                $out[] = "  成员: " . _cnt($db, 'SELECT COUNT(*) FROM pub_members WHERE publication_id=?', [$pid]);
                $out[] = "  频道: " . _cnt($db, 'SELECT COUNT(*) FROM pub_channels WHERE publication_id=?', [$pid]);
                $out[] = "  聊天: " . _cnt($db, 'SELECT COUNT(*) FROM pub_chat WHERE publication_id=?', [$pid]);
                $out[] = "  帖子: " . _cnt($db, 'SELECT COUNT(*) FROM pub_posts WHERE publication_id=?', [$pid]);
                $out[] = "  身份组: " . _cnt($db, 'SELECT COUNT(*) FROM pub_roles WHERE publication_id=?', [$pid]);
                $out[] = "  模块覆盖: " . _cnt($db, 'SELECT COUNT(*) FROM pub_module_settings WHERE publication_id=?', [$pid]);
            } else if ($sub === 'delete') {
                $slug = $args[1] ?? '';
                $st = $db->prepare('SELECT * FROM publications WHERE slug=?'); $st->execute([$slug]); $p = $st->fetch();
                if (!$p) { $out[] = "刊物不存在: $slug"; break; }
                log_admin_action($db, $user['id'], 'delete_pub', 'pub', $p['id'], "删除刊物 {$p['name']}", ['table' => 'publications', 'row' => $p]);
                $db->prepare('DELETE FROM publications WHERE id=?')->execute([$p['id']]);
                $out[] = "已删除刊物 {$p['name']}（可在操作日志中撤回）";
            } else {
                $out[] = '用法: pub list | pub info <slug> | pub delete <slug>';
            }
            break;
        case 'user':
            $sub = strtolower($args[0] ?? '');
            if ($sub === 'list') {
                $n = (int)($args[1] ?? 20); if ($n < 1 || $n > 100) $n = 20;
                $st = $db->prepare('SELECT id, username, uid, verified, banned FROM users ORDER BY id ASC LIMIT ?');
                $st->execute([$n]);
                $us = $st->fetchAll();
                if (!$us) { $out[] = '（暂无用户）'; break; }
                foreach ($us as $u) {
                    $flag = ($u['banned'] ? ' [封禁]' : '') . ($u['verified'] ? '' : ' [未认证]');
                    $out[] = "  #{$u['id']} {$u['username']} (UID:{$u['uid']}){$flag}";
                }
            } else if ($sub === 'promote') {
                $uname = $args[1] ?? ''; $rname = $args[2] ?? '';
                if (!$uname || !$rname) { $out[] = '用法: user promote <用户> <身份组名>'; break; }
                $st = $db->prepare('SELECT id FROM users WHERE username=?'); $st->execute([$uname]); $tu = $st->fetch();
                if (!$tu) { $out[] = "用户不存在: $uname"; break; }
                $st = $db->prepare('SELECT id, name FROM platform_roles WHERE name=?'); $st->execute([$rname]); $role = $st->fetch();
                if (!$role) { $out[] = "身份组不存在: $rname"; break; }
                $db->prepare('INSERT OR IGNORE INTO user_platform_roles (user_id, role_id) VALUES (?, ?)')->execute([$tu['id'], $role['id']]);
                log_admin_action($db, $user['id'], 'promote', 'user', $tu['id'], "赋予身份组「{$rname}」给 {$uname}", ['uid' => $tu['id'], 'role_id' => $role['id']]);
                $out[] = "已将平台身份组「{$rname}」赋予 {$uname}";
            } else if ($sub === 'demote') {
                $uname = $args[1] ?? '';
                $st = $db->prepare('SELECT id FROM users WHERE username=?'); $st->execute([$uname]); $tu = $st->fetch();
                if (!$tu) { $out[] = "用户不存在: $uname"; break; }
                $rm = $db->prepare('SELECT role_id FROM user_platform_roles WHERE user_id=?'); $rm->execute([$tu['id']]); $rmIds = $rm->fetchAll(PDO::FETCH_COLUMN);
                $db->prepare('DELETE FROM user_platform_roles WHERE user_id=?')->execute([$tu['id']]);
                log_admin_action($db, $user['id'], 'demote', 'user', $tu['id'], "移除 {$uname} 的全部平台身份组", ['uid' => $tu['id'], 'roles' => $rmIds]);
                $out[] = "已移除 {$uname} 的全部平台身份组";
            } else if ($sub === 'delete') {
                $uname = $args[1] ?? '';
                $st = $db->prepare('SELECT * FROM users WHERE username=?'); $st->execute([$uname]); $tu = $st->fetch();
                if (!$tu) { $out[] = "用户不存在: $uname"; break; }
                if ((int)$tu['id'] === (int)$user['id']) { $out[] = '不能删除你自己'; break; }
                if (has_perm($tu['platform_perms'] ?? 0, PERM_ROOT) && !is_prime_admin($user)) { $out[] = '不能删除平台总构建师'; break; }
                log_admin_action($db, $user['id'], 'delete_user', 'user', $tu['id'], "删除用户 {$uname}", ['table' => 'users', 'row' => $tu]);
                $db->prepare('DELETE FROM user_platform_roles WHERE user_id=?')->execute([$tu['id']]);
                $db->prepare('DELETE FROM users WHERE id=?')->execute([$tu['id']]);
                $out[] = "已删除用户 {$uname}（可在操作日志中撤回）";
            } else {
                $out[] = '用法: user list [n] | user promote <用户> <身份组> | user demote <用户>';
            }
            break;
        case 'module':
            $key = $args[1] ?? ''; $state = strtolower($args[2] ?? '');
            if (!$key || !in_array($state, ['on', 'off'])) { $out[] = '用法: module <key> on|off'; break; }
            $st = $db->prepare('SELECT * FROM modules WHERE mkey=?'); $st->execute([$key]); $mod = $st->fetch();
            if (!$mod) { $out[] = "模块不存在: $key"; break; }
            $next = $state === 'on' ? 1 : 0;
            $db->prepare('UPDATE modules SET enabled=? WHERE mkey=?')->execute([$next, $key]);
            $out[] = "模块 {$key} 已" . ($next ? '启用' : '关闭');
            break;
        case 'announce':
            $text = trim(implode(' ', array_slice($args, 0)));
            if (mb_strlen($text) < 2) { $out[] = '公告内容至少2个字符'; break; }
            $stmt = $db->query('SELECT id FROM users');
            $cnt = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                add_notification($db, $uid, 'announce', $user['id'], $text, '#/messages');
                $cnt++;
            }
            $out[] = "已向 $cnt 名用户发布公告";
            log_admin_action($db, $user['id'], 'announce', 'all', 0, "向 $cnt 名用户发布公告", ['text' => $text]);
            break;
        case 'notify':
            $roleName = $args[0] ?? '';
            $text = trim(implode(' ', array_slice($args, 1)));
            if (!$roleName || mb_strlen($text) < 2) { $out[] = '用法: notify <身份组名> <消息>'; break; }
            $rst = $db->prepare('SELECT id FROM platform_roles WHERE name=?'); $rst->execute([$roleName]);
            $role = $rst->fetch();
            if (!$role) { $out[] = "身份组不存在: $roleName"; break; }
            $ust = $db->prepare('SELECT user_id FROM user_platform_roles WHERE role_id=?'); $ust->execute([$role['id']]);
            $cnt = 0;
            foreach ($ust->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                add_notification($db, $uid, 'role', $user['id'], "［{$roleName}］{$text}", '#/messages');
                $cnt++;
            }
            $out[] = "已向身份组「{$roleName}」的 $cnt 名用户发送通知";
            log_admin_action($db, $user['id'], 'notify', 'role', $role['id'], "向身份组 {$roleName} 的 $cnt 名用户发送通知", ['text' => $text]);
            break;
        case 'ban':
        case 'unban':
            $uname = $args[1] ?? '';
            $st = $db->prepare('SELECT id, banned FROM users WHERE username=?'); $st->execute([$uname]); $tu = $st->fetch();
            if (!$tu) { $out[] = "用户不存在: $uname"; break; }
            $val = $cmd === 'ban' ? 1 : 0;
            $old = (int)($tu['banned'] ?? 0);
            $db->prepare('UPDATE users SET banned=? WHERE id=?')->execute([$val, $tu['id']]);
            log_admin_action($db, $user['id'], $cmd, 'user', $tu['id'], ($cmd === 'ban' ? '封禁 ' : '解封 ') . $uname, ['uid' => $tu['id'], 'old' => $old]);
            $out[] = ($cmd === 'ban' ? '已封禁 ' : '已解封 ') . $uname;
            break;
        case 'cache':
            $sub = strtolower($args[1] ?? '');
            if ($sub === 'clear') {
                cache_clear($db);
                $db->prepare('INSERT OR REPLACE INTO site_meta (k, v) VALUES (?, ?)')->execute(['cache_cleared_at', date('Y-m-d H:i:s')]);
                $out[] = '已清空网站加速器缓存';
            } else { $out[] = '用法: cache clear'; }
            break;
        case 'log':
            $n = (int)($args[1] ?? 20); if ($n < 1 || $n > 200) $n = 20;
            $st = $db->prepare('SELECT * FROM admin_log ORDER BY id DESC LIMIT ?'); $st->execute([$n]);
            $rows = $st->fetchAll();
            if (!$rows) { $out[] = '（暂无操作日志）'; break; }
            foreach ($rows as $r) {
                $out[] = "#{$r['id']} [{$r['action']}] {$r['summary']}  ({$r['created_at']})";
            }
            break;
        case 'undo':
            $id = (int)($args[1] ?? 0);
            if (!$id) { $out[] = '用法: undo <日志id>'; break; }
            $res = admin_undo($db, $user, $id);
            $out[] = ($res['ok'] ? ('✓ ' . ($res['message'] ?? '已撤回')) : ('✗ ' . ($res['error'] ?? '撤回失败')));
            break;
        case 'article':
            $sub = strtolower($args[0] ?? '');
            if ($sub === 'delete') {
                $aid = (int)($args[1] ?? 0);
                $st = $db->prepare('SELECT * FROM articles WHERE id=?'); $st->execute([$aid]); $a = $st->fetch();
                if (!$a) { $out[] = "文章不存在: $aid"; break; }
                log_admin_action($db, $user['id'], 'delete_article', 'article', $a['id'], "删除文章 {$a['title']}", ['table' => 'articles', 'row' => $a]);
                $db->prepare('DELETE FROM articles WHERE id=?')->execute([$a['id']]);
                $out[] = "已删除文章 {$a['title']}（可在操作日志中撤回）";
            } else { $out[] = '用法: article delete <id>'; }
            break;
        case 'feedback':
            $sub = strtolower($args[0] ?? '');
            if ($sub === 'delete') {
                $fid = (int)($args[1] ?? 0);
                $st = $db->prepare('SELECT * FROM feedback WHERE id=?'); $st->execute([$fid]); $f = $st->fetch();
                if (!$f) { $out[] = "反馈不存在: $fid"; break; }
                log_admin_action($db, $user['id'], 'delete_feedback', 'feedback', $f['id'], "删除反馈 #{$f['id']}", ['table' => 'feedback', 'row' => $f]);
                $db->prepare('DELETE FROM feedback WHERE id=?')->execute([$f['id']]);
                $out[] = "已删除反馈 #{$f['id']}（可在操作日志中撤回）";
            } else { $out[] = '用法: feedback delete <id>'; }
            break;
        case 'post':
            $sub = strtolower($args[0] ?? '');
            if ($sub === 'delete') {
                $pid = (int)($args[1] ?? 0);
                $st = $db->prepare('SELECT * FROM pub_posts WHERE id=?'); $st->execute([$pid]); $p = $st->fetch();
                if (!$p) { $out[] = "帖子不存在: $pid"; break; }
                log_admin_action($db, $user['id'], 'delete_post', 'post', $p['id'], "删除帖子 #{$p['id']}", ['table' => 'pub_posts', 'row' => $p]);
                $db->prepare('DELETE FROM pub_posts WHERE id=?')->execute([$p['id']]);
                $out[] = "已删除帖子 #{$p['id']}（可在操作日志中撤回）";
            } else { $out[] = '用法: post delete <id>'; }
            break;
        case 'phpinfo':
            $out[] = '🧪 PHP 运行环境（只读）';
            $out[] = '  PHP 版本        : ' . phpversion();
            $out[] = '  SAPI            : ' . php_sapi_name();
            foreach (['disable_functions','disable_classes','allow_url_fopen','open_basedir','doc_root','display_errors','file_uploads','upload_max_filesize','post_max_size','memory_limit','max_execution_time','max_input_time','session.save_path'] as $k) {
                $v = ini_get($k);
                $out[] = '  ' . str_pad($k, 18, ' ') . ': ' . ($v === false ? '(未设置)' : $v);
            }
            break;
        case 'sql':
            if (!is_prime_admin($user)) { $out[] = '⛔ 该指令仅「平台总构建师」(权限ID=0) 可用'; break; }
            $sql = trim(implode(' ', array_slice($parts, 1)));
            if ($sql === '') { $out[] = '用法: sql <SQL 语句>  例: sql SELECT * FROM users LIMIT 5'; break; }
            log_admin_action($db, $user['id'], 'sql', 'db', 0, '执行 SQL: ' . mb_substr($sql, 0, 200), ['sql' => $sql]);
            try {
                $st = $db->query($sql);
                if ($st === false) { $out[] = '⚠️ 该语句可能不被 query() 支持，或语法错误'; break; }
                if ($st->columnCount() > 0) {
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($rows)) { $out[] = '(0 行)'; break; }
                    $cols = array_keys($rows[0]);
                    $out[] = implode(' | ', $cols);
                    $out[] = str_repeat('-', max(8, mb_strlen(implode(' | ', $cols))));
                    $n = 0;
                    foreach ($rows as $row) {
                        if (++$n > 200) { $out[] = '… 仅显示前 200 行'; break; }
                        $out[] = implode(' | ', array_map(function($v){ return $v === null ? 'NULL' : (is_string($v) && mb_strlen($v) > 200 ? mb_substr($v,0,200).'…' : (string)$v); }, $row));
                    }
                    $out[] = '(共 ' . $n . ' 行)';
                } else {
                    $out[] = '✓ 影响行数: ' . $st->rowCount() . '  最后插入ID: ' . $db->lastInsertId();
                }
            } catch (Exception $e) {
                $out[] = '✗ SQL 错误: ' . $e->getMessage();
            }
            break;
        case 'ls':
            $rel = $args[0] ?? '.';
            $p = _term_safe_path($rel);
            if ($p === false) { $out[] = '⛔ 路径不合法或超出站点目录'; break; }
            if (!is_dir($p)) { $out[] = '不是目录: ' . $rel; break; }
            $items = scandir($p);
            foreach ($items as $it) {
                if ($it === '.' || $it === '..') continue;
                $full = $p . DIRECTORY_SEPARATOR . $it;
                $out[] = (is_dir($full) ? 'd  ' : 'f  ') . $it . (is_dir($full) ? '/' : '  (' . round(filesize($full)/1024, 1) . ' KB)');
            }
            if (count($out) === 0) $out[] = '(空目录)';
            break;
        case 'cat':
            $rel = $args[0] ?? '';
            if (!$rel) { $out[] = '用法: cat <文件>'; break; }
            $p = _term_safe_path($rel);
            if ($p === false || !is_file($p)) { $out[] = '⛔ 文件不存在或超出站点目录'; break; }
            if (_term_is_protected($p) && !is_prime_admin($user)) { $out[] = '⛔ 该文件受保护，仅总构建师可读'; break; }
            $sz = filesize($p);
            if ($sz > 200 * 1024) $out[] = '⚠️ 文件较大 (' . round($sz/1024, 1) . ' KB)，仅显示前 4KB';
            $content = @file_get_contents($p, false, null, 0, 4096);
            $out[] = $content === false ? '（读取失败）' : $content;
            break;
        case 'write':
            $rel = $args[0] ?? '';
            $content = implode(' ', array_slice($args, 1));
            if (!$rel) { $out[] = '用法: write <文件> <内容>'; break; }
            $p = _term_safe_path($rel);
            if ($p === false) { $out[] = '⛔ 路径不合法或超出站点目录'; break; }
            if (_term_is_protected($p)) {
                if (!is_prime_admin($user)) { $out[] = '⛔ 该文件受保护，禁止覆盖'; break; }
                $out[] = '⚠️ 正在覆盖受保护文件，已记录审计';
            }
            log_admin_action($db, $user['id'], 'term_write', 'file', 0, '写入文件: ' . $rel, ['path' => $rel, 'len' => strlen($content)]);
            if (@file_put_contents($p, $content) === false) $out[] = '✗ 写入失败（检查目录权限）';
            else $out[] = '✓ 已写入 ' . $rel . ' (' . strlen($content) . ' 字节)';
            break;
        case 'rm':
            $rel = $args[0] ?? '';
            if (!$rel) { $out[] = '用法: rm <文件>'; break; }
            $p = _term_safe_path($rel);
            if ($p === false || !is_file($p)) { $out[] = '⛔ 文件不存在或超出站点目录'; break; }
            if (_term_is_protected($p)) { $out[] = '⛔ 该文件受保护，禁止删除（' . basename($p) . '）'; break; }
            log_admin_action($db, $user['id'], 'term_rm', 'file', 0, '删除文件: ' . $rel, ['path' => $rel]);
            if (@unlink($p)) $out[] = '✓ 已删除 ' . $rel;
            else $out[] = '✗ 删除失败';
            break;
        case 'backup':
            $src = $db->query("SELECT file FROM pragma_database_list WHERE name='main'")->fetchColumn();
            if (!file_exists($src)) { $out[] = '⚠️ 数据库文件不存在'; break; }
            if (!is_dir(__DIR__ . '/backups')) @mkdir(__DIR__ . '/backups', 0755, true);
            $stamp = date('Ymd-His');
            $dst = __DIR__ . '/backups/data-' . $stamp . '.db';
            if (!@copy($src, $dst)) { $out[] = '✗ 备份失败'; break; }
            log_admin_action($db, $user['id'], 'term_backup', 'file', 0, '备份数据库到 ' . basename($dst), null);
            $out[] = '✓ 已备份: backups/' . basename($dst) . ' (' . round(filesize($dst)/1024, 1) . ' KB)';
            $bks = glob(__DIR__ . '/backups/*.db');
            $out[] = '  当前备份数: ' . count($bks);
            break;
        default:
            $out[] = "未知指令: $cmd （输入 help 查看可用指令）";
    }
    return $out;
}

// ============ 平台操作日志 & 撤回（撤销任何治理行为） ============
// 注意：全站严禁任何「删除整个虚拟主机 / 删除网站 / 清空数据库 / 删除全部文件」
// 的指令或按钮。管理员权限 = 除毁站外全开。
function log_admin_action($db, $actor_id, $action, $target_type, $target_id, $summary, $data = '') {
    $db->prepare('INSERT INTO admin_log (actor_id, action, target_type, target_id, summary, data) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int)$actor_id, $action, $target_type, (string)$target_id, $summary, is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)]);
    return $db->lastInsertId();
}

// 通用快照还原：把整行按原 id 重新插入（SQLite 允许显式指定 INTEGER PRIMARY KEY）
function admin_restore_row($db, $table, $row) {
    $cols = array_keys($row);
    $cols_list = '`' . implode('`,`', $cols) . '`';
    $ph = rtrim(str_repeat('?,', count($cols)), ',');
    $db->prepare("INSERT OR IGNORE INTO `$table` ($cols_list) VALUES ($ph)")->execute(array_values($row));
}

// 撤回一条平台操作；仅平台管理员可调用。$user 为当前操作者
function admin_undo($db, $user, $log_id) {
    $st = $db->prepare('SELECT * FROM admin_log WHERE id = ?');
    $st->execute([$log_id]);
    $log = $st->fetch();
    if (!$log) return ['ok' => false, 'error' => '日志不存在'];
    if (mb_strpos($log['summary'], '［已撤回］') !== false) return ['ok' => false, 'error' => '该行为已撤回过'];
    $data = $log['data'] ? json_decode($log['data'], true) : [];
    switch ($log['action']) {
        case 'ban':
        case 'unban':
            $uid = (int)($data['uid'] ?? $log['target_id']);
            $old = (int)($data['old'] ?? 0);
            $db->prepare('UPDATE users SET banned = ? WHERE id = ?')->execute([$old, $uid]);
            $msg = '已撤回「' . ($log['action'] === 'ban' ? '封禁' : '解封') . '」';
            break;
        case 'promote':
            $db->prepare('DELETE FROM user_platform_roles WHERE user_id = ? AND role_id = ?')
                ->execute([(int)$data['uid'], (int)$data['role_id']]);
            $msg = '已撤回「赋予身份组」';
            break;
        case 'demote':
            foreach (($data['roles'] ?? []) as $rid) {
                $db->prepare('INSERT OR IGNORE INTO user_platform_roles (user_id, role_id) VALUES (?, ?)')
                    ->execute([(int)$data['uid'], (int)$rid]);
            }
            $msg = '已撤回「移除全部身份组」';
            break;
        case 'delete_user':
        case 'delete_pub':
        case 'delete_article':
        case 'delete_feedback':
        case 'delete_post':
            $table = $data['table'] ?? '';
            $row = $data['row'] ?? [];
            if (!$table || !$row) return ['ok' => false, 'error' => '缺少恢复快照，无法撤回'];
            admin_restore_row($db, $table, $row);
            $msg = '已撤回删除，数据已恢复';
            break;
        default:
            return ['ok' => false, 'error' => '该行为暂不支持撤回'];
    }
    $db->prepare('UPDATE admin_log SET summary = ? WHERE id = ?')->execute([$log['summary'] . ' ［已撤回］', $log['id']]);
    cache_clear($db);
    return ['ok' => true, 'message' => $msg];
}

// ============ 路由 ============
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 网站加速器：公开只读接口的服务端缓存（命中则直接返回，省去 DB 查询 + 抗连接抖动）
if ($ACCEL_ON && $method === 'GET') {
    $ck = 'accel_' . $action . '_' . md5($_SERVER['QUERY_STRING']);
    $chit = cache_get($db, $ck);
    if ($chit !== null) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=15, stale-while-revalidate=30');
        header('X-Accel: HIT');
        echo $chit;
        exit;
    }
}

// CSRF 防护：所有非 GET 的写操作，必须在同源或带 X-Requested-With 头的情况下才放行
if ($method !== 'GET' && $method !== 'OPTIONS') {
    guard_csrf();
}

try {
    switch ($action) {

        // ---- 认证 ----
        case 'register':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $input = get_input();
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $email = trim($input['email'] ?? '');
            if (mb_strlen($username) < 2) json_response(['error' => '用户名至少2个字符'], 400);
            if (strlen($password) < 6) json_response(['error' => '密码至少6个字符'], 400);
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_response(['error' => '请输入有效的邮箱地址'], 400);
            }
            $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) json_response(['error' => '用户名已存在'], 400);
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) json_response(['error' => '该邮箱已注册'], 400);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $verifyToken = bin2hex(random_bytes(16));
            $authToken = bin2hex(random_bytes(32));
            $uid = (int)$db->query("SELECT COALESCE(MAX(uid),100000) FROM users")->fetchColumn() + 1;
            $stmt = $db->prepare('INSERT INTO users (username, password, email, verified, verify_token, token, uid) VALUES (?, ?, ?, 0, ?, ?, ?)');
            $stmt->execute([$username, $hash, $email, $verifyToken, $authToken, $uid]);
            $newId = $db->lastInsertId();
            // 分配默认平台身份组（普通用户）
            $dr = $db->query("SELECT id FROM platform_roles WHERE name='普通用户'")->fetchColumn();
            if (!$dr) $dr = $db->query("SELECT id FROM platform_roles ORDER BY id LIMIT 1")->fetchColumn();
            $db->prepare("INSERT OR IGNORE INTO user_platform_roles (user_id, role_id) VALUES (?, ?)")->execute([$newId, $dr]);
            set_auth_cookie($authToken);
            $_SESSION['user_id'] = $newId;
            $verifyUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/#/verify/' . $verifyToken;
            @mail($email, '万刊网邮箱验证', "点击以下链接完成邮箱验证：\n" . $verifyUrl);
            $newUser = ['id' => (int)$newId, 'username' => $username, 'email' => $email, 'verified' => 0, 'avatar' => '', 'cover' => '', 'bio' => '', 'uid' => $uid];
            attach_platform($db, $newUser);
            json_response([
                'user' => $newUser,
                'token' => $authToken,
                'needsVerify' => true,
                'verifyUrl' => $verifyUrl
            ]);
            break;

        case 'login':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $input = get_input();
            $email = trim($input['email'] ?? '');
            $password = $input['password'] ?? '';
            if (empty($email)) json_response(['error' => '请输入邮箱'], 400);

            // 防暴力破解：同一 IP 15 分钟内失败 5 次即临时锁定
            $ip = client_ip();
            $lfStmt = $db->prepare('SELECT * FROM login_fails WHERE ip = ?');
            $lfStmt->execute([$ip]);
            $lf = $lfStmt->fetch();
            $now = time();
            if ($lf && $lf['fails'] >= 5 && ($now - (int)$lf['first_at']) < 900) {
                $wait = 900 - ($now - (int)$lf['first_at']);
                json_response(['error' => '尝试次数过多，请 ' . ceil($wait / 60) . ' 分钟后再试'], 429);
            }
            if ($lf && ($now - (int)$lf['first_at']) >= 900) {
                $db->prepare('DELETE FROM login_fails WHERE ip = ?')->execute([$ip]);
                $lf = false;
            }

            // 支持邮箱登录，兼容用户名（管理员旧账号）
            $stmt = $db->prepare('SELECT * FROM users WHERE email = ? OR username = ?');
            $stmt->execute([$email, $email]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password'])) {
                // 记录一次失败
                if ($lf) {
                    $db->prepare('UPDATE login_fails SET fails = fails + 1, last_at = ? WHERE ip = ?')->execute([$now, $ip]);
                } else {
                    $db->prepare('INSERT INTO login_fails (ip, fails, first_at, last_at) VALUES (?, 1, ?, ?)')->execute([$ip, $now, $now]);
                }
                json_response(['error' => '邮箱或密码错误'], 401);
            }
            // 成功：清零失败记录，发放并种下安全 Cookie
            $db->prepare('DELETE FROM login_fails WHERE ip = ?')->execute([$ip]);
            $authToken = bin2hex(random_bytes(32));
            $db->prepare('UPDATE users SET token = ? WHERE id = ?')->execute([$authToken, $user['id']]);
            set_auth_cookie($authToken);
            $_SESSION['user_id'] = $user['id'];
            attach_platform($db, $user);
            json_response([
                'user' => ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email'], 'verified' => (int)$user['verified'], 'avatar' => abs_url($user['avatar']), 'cover' => abs_url($user['cover']), 'bio' => $user['bio'], 'uid' => (int)$user['uid'], 'platform_perms' => $user['platform_perms'], 'platform_roles' => $user['platform_roles']],
                'token' => $authToken
            ]);
            break;

        case 'logout':
            $user = current_user($db);
            if ($user) $db->prepare('UPDATE users SET token = NULL WHERE id = ?')->execute([$user['id']]);
            clear_auth_cookie();
            session_destroy();
            json_response(['ok' => true]);
            break;

        // ---- 多账号切换：用已知的 token 重设认证 Cookie（不重新登录）----
        case 'switch_account':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $input = get_input();
            $tk = trim($input['token'] ?? '');
            if (empty($tk)) json_response(['error' => '缺少 token'], 400);
            $stmt = $db->prepare('SELECT id, username, email, bio, verified, avatar, cover, token, created_at, uid FROM users WHERE token = ?');
            $stmt->execute([$tk]);
            $u = $stmt->fetch();
            if (!$u) json_response(['error' => '登录状态已过期，请重新登录该账号'], 403);
            // 三份状态强制对齐：Cookie + Session 同步指向目标账号，避免 current_user 兜底命中旧 session
            set_auth_cookie($tk);
            $_SESSION['user_id'] = (int)$u['id'];
            attach_platform($db, $u);
            $u['avatar'] = abs_url($u['avatar']);
            $u['cover'] = abs_url($u['cover']);
            // 直接返回切换后的用户对象，前端无需再发第二次 me（少一次可能被 Cloudflare 质询拦截的请求）
            json_response(['ok' => true, 'user' => $u, 'token' => $u['token']]);
            break;

        case 'me':
            $user = current_user($db);
            if ($user) {
                if (empty($user['token'])) {
                    $t = bin2hex(random_bytes(32));
                    $db->prepare('UPDATE users SET token = ? WHERE id = ?')->execute([$t, $user['id']]);
                    $user['token'] = $t;
                }
                json_response(['user' => $user, 'token' => $user['token']]);
            } else {
                json_response(['user' => null]);
            }
            break;

        // ---- 刊物 ----
        case 'publications':
            if ($method === 'GET') {
                $stmt = $db->query("
                    SELECT p.*, u.username as owner_name,
                           (SELECT COUNT(*) FROM articles a WHERE a.publication_id = p.id) as article_count,
                           (SELECT COUNT(*) FROM articles a WHERE a.publication_id = p.id AND a.status = 'published') as published_count
                    FROM publications p JOIN users u ON p.owner_id = u.id
                    ORDER BY p.created_at DESC
                ");
                json_response(['publications' => $stmt->fetchAll()]);
            }
            if ($method === 'POST') {
                $user = require_login($db);
                if (!has_perm($user['platform_perms'], PERM_CREATE_PUB)) json_response(['error' => '无权限创建刊物'], 403);
                $input = get_input();
                $name = trim($input['name'] ?? '');
                $description = trim($input['description'] ?? '');
                $tags = trim($input['tags'] ?? '');
                if (mb_strlen($name) < 2) json_response(['error' => '刊名至少2个字符'], 400);
                $slug = slugify($name);
                // 确保 slug 唯一
                $base = $slug;
                $i = 1;
                while (true) {
                    $stmt = $db->prepare('SELECT id FROM publications WHERE slug = ?');
                    $stmt->execute([$slug]);
                    if (!$stmt->fetch()) break;
                    $slug = $base . '-' . $i++;
                }
                $stmt = $db->prepare('INSERT INTO publications (slug, name, description, tags, owner_id) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$slug, $name, $description, $tags, $user['id']]);
                $newPubId = $db->lastInsertId();
                seed_pub_roles($db, $newPubId, $user['id']);
                json_response(['publication' => ['id' => (int)$newPubId, 'slug' => $slug, 'name' => $name, 'description' => $description, 'tags' => $tags, 'owner_id' => $user['id'], 'owner_name' => $user['username']]]);
            }
            break;

        case 'publication':
            $slug = $_GET['slug'] ?? '';
            $stmt = $db->prepare("
                SELECT p.*, u.username as owner_name, u.uid as owner_uid
                FROM publications p JOIN users u ON p.owner_id = u.id
                WHERE p.slug = ?
            ");
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);

            $cu = current_user($db);
            $myPerms = $cu ? pub_perms_of($db, $cu['id'], $pub['id']) : 0;
            $myRoles = $cu ? pub_roles_of($db, $cu['id'], $pub['id']) : [];
            $myNickname = '';
            if ($cu) {
                $stmt = $db->prepare('SELECT nickname FROM pub_nicknames WHERE user_id = ? AND publication_id = ?');
                $stmt->execute([$cu['id'], $pub['id']]);
                $myNickname = $stmt->fetchColumn();
            }

            if ($method === 'GET') {
                // 获取文章列表
                $stmt = $db->prepare("
                    SELECT a.* FROM articles a
                    WHERE a.publication_id = ?
                    ORDER BY a.created_at DESC
                ");
                $stmt->execute([$pub['id']]);
                $articles = $stmt->fetchAll();

                // 获取期刊列表
                $stmt = $db->prepare("SELECT * FROM issues WHERE publication_id = ? ORDER BY issue_number DESC");
                $stmt->execute([$pub['id']]);
                $issues = $stmt->fetchAll();

                json_response(['publication' => $pub, 'articles' => $articles, 'issues' => $issues, 'my_perms' => $myPerms, 'my_roles' => $myRoles, 'my_nickname' => $myNickname]);
            }

            if ($method === 'DELETE') {
                $user = require_login($db);
                $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
                if (!has_perm($myPerms, PERM_MANAGE_PUB) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
                $db->prepare('DELETE FROM pub_members WHERE publication_id = ?')->execute([$pub['id']]);
                $db->prepare('DELETE FROM pub_roles WHERE publication_id = ?')->execute([$pub['id']]);
                $db->prepare('DELETE FROM articles WHERE publication_id = ?')->execute([$pub['id']]);
                $db->prepare('DELETE FROM issues WHERE publication_id = ?')->execute([$pub['id']]);
                $db->prepare('DELETE FROM subscriptions WHERE publication_id = ?')->execute([$pub['id']]);
                $db->prepare('DELETE FROM publications WHERE id = ?')->execute([$pub['id']]);
                cache_clear($db);
                json_response(['ok' => true]);
            }
            break;

        // ---- 文章 ----
        case 'submit':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_POST)) json_response(['error' => '无权限投稿'], 403);
            $input = get_input();
            $pubSlug = $input['pubSlug'] ?? '';
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug = ?');
            $stmt->execute([$pubSlug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);

            $title = trim($input['title'] ?? '');
            $author = trim($input['author'] ?? $user['username']);
            $authorEmail = trim($input['authorEmail'] ?? $user['email'] ?? '');
            $abstract = trim($input['abstract'] ?? '');
            $body = trim($input['body'] ?? '');
            $tags = trim($input['tags'] ?? '');
            if (mb_strlen($title) < 2) json_response(['error' => '标题至少2个字符'], 400);
            if (mb_strlen($body) < 10) json_response(['error' => '正文至少10个字符'], 400);

            // 认证用户投稿免审核（直接发布），普通用户走 pending 待审
            $certChk = $db->prepare("SELECT 1 FROM user_platform_roles ur JOIN platform_roles r ON ur.role_id=r.id WHERE ur.user_id=? AND r.name='认证用户'");
            $certChk->execute([$user['id']]);
            $isCert = (bool)$certChk->fetch();
            $status = $isCert ? 'published' : 'pending';

            $stmt = $db->prepare('INSERT INTO articles (publication_id, title, author, author_email, abstract, body, tags, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$pub['id'], $title, $author, $authorEmail, $abstract, $body, $tags, $status]);
            $newId = (int)$db->lastInsertId();

            // 通知刊物拥有者：有人投稿/发布
            $verb = $isCert ? '发布了' : '投稿了';
            add_notification($db, $pub['owner_id'], 'submit', $user['id'], "{$user['username']} 向《{$pub['name']}》{$verb}《{$title}》", "#/pub/{$pub['slug']}");

            // 通知该刊物内持有 审稿/编辑/管理刊物 权限的成员（刊物主已通知，跳过）
            $revStmt = $db->prepare("
                SELECT DISTINCT m.user_id
                FROM pub_members m
                JOIN pub_roles r ON m.role_id = r.id
                WHERE m.publication_id = ?
                  AND (r.permissions & ?) != 0
                  AND m.user_id != ?
            ");
            $revPerms = PERM_REVIEW | PERM_MANAGE_ARTICLES | PERM_MANAGE_PUB;
            $revStmt->execute([$pub['id'], $revPerms, $user['id']]);
            foreach ($revStmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                if ($rid == $pub['owner_id']) continue;
                add_notification($db, $rid, 'submit', $user['id'], "《{$pub['name']}》收到新投稿《{$title}》（待审稿）", "#/pub/{$pub['slug']}");
            }
            json_response(['article' => ['id' => $newId, 'title' => $title, 'status' => $status]]);
            break;

        case 'article':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("
                SELECT a.*, p.slug as pub_slug, p.name as pub_name, u.username as reviewer_name
                FROM articles a
                JOIN publications p ON a.publication_id = p.id
                LEFT JOIN users u ON a.reviewed_by = u.id
                WHERE a.id = ?
            ");
            $stmt->execute([$id]);
            $article = $stmt->fetch();
            if (!$article) json_response(['error' => '文章不存在'], 404);

            if ($method === 'GET') {
                // 获取评论
                $stmt = $db->prepare("SELECT * FROM comments WHERE article_id = ? ORDER BY created_at ASC");
                $stmt->execute([$id]);
                $article['comments'] = $stmt->fetchAll();
                json_response(['article' => $article]);
            }
            break;

        case 'review':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $articleId = (int)($input['articleId'] ?? 0);
            $reviewAction = $input['reviewAction'] ?? '';
            $comment = trim($input['comment'] ?? '');

            $stmt = $db->prepare("
                SELECT a.*, p.owner_id FROM articles a
                JOIN publications p ON a.publication_id = p.id
                WHERE a.id = ?
            ");
            $stmt->execute([$articleId]);
            $article = $stmt->fetch();
            if (!$article) json_response(['error' => '文章不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $article['publication_id']);
            if (!has_perm($myPerms, PERM_MANAGE_ARTICLES) && !has_perm($myPerms, PERM_REVIEW) && $article['owner_id'] != $user['id']) json_response(['error' => '无权限审稿'], 403);

            $statusMap = [
                'accept' => 'accepted',
                'revise' => 'revise',
                'reject' => 'rejected'
            ];
            if (!isset($statusMap[$reviewAction])) json_response(['error' => '无效操作'], 400);
            $newStatus = $statusMap[$reviewAction];

            $stmt = $db->prepare("UPDATE articles SET status = ?, review_comment = ?, reviewed_by = ?, reviewed_at = datetime('now', '+8 hours') WHERE id = ?");
            $stmt->execute([$newStatus, $comment, $user['id'], $articleId]);
            json_response(['ok' => true, 'status' => $newStatus]);
            break;

        case 'publish':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $pubSlug = $input['pubSlug'] ?? '';
            $issueTitle = trim($input['issueTitle'] ?? '');
            $issueDescription = trim($input['issueDescription'] ?? '');
            $articleIds = $input['articleIds'] ?? [];

            $stmt = $db->prepare("SELECT * FROM publications WHERE slug = ?");
            $stmt->execute([$pubSlug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_PUB) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限发刊'], 403);
            if (count($articleIds) === 0) json_response(['error' => '请至少选择一篇文章'], 400);

            // 获取下一个期号
            $stmt = $db->prepare("SELECT MAX(issue_number) as max_num FROM issues WHERE publication_id = ?");
            $stmt->execute([$pub['id']]);
            $row = $stmt->fetch();
            $nextNum = ($row['max_num'] ?? 0) + 1;

            // 创建新期刊
            $stmt = $db->prepare('INSERT INTO issues (publication_id, issue_number, title, description) VALUES (?, ?, ?, ?)');
            $stmt->execute([$pub['id'], $nextNum, $issueTitle, $issueDescription]);
            $issueId = $db->lastInsertId();

            // 更新文章状态为已发刊
            $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
            $stmt = $db->prepare("UPDATE articles SET status = 'published', issue_id = ? WHERE id IN ($placeholders) AND publication_id = ?");
            $params = array_merge([$issueId], $articleIds, [$pub['id']]);
            $stmt->execute($params);

            // 发刊后通知该刊物所有订阅者
            $subStmt = $db->prepare('SELECT user_id FROM subscriptions WHERE publication_id = ?');
            $subStmt->execute([$pub['id']]);
            $pubLink = "#/pub/{$pub['slug']}/issue/{$nextNum}";
            foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                add_notification($db, $sid, 'publish', $user['id'], "《{$pub['name']}》发布了第 {$nextNum} 期，快来看看！", $pubLink);
            }

            json_response(['ok' => true, 'issueNumber' => $nextNum, 'issueId' => (int)$issueId]);
            break;

        case 'issues':
            $pubSlug = $_GET['slug'] ?? '';
            $stmt = $db->prepare("
                SELECT i.*, COUNT(a.id) as article_count
                FROM issues i
                LEFT JOIN articles a ON a.issue_id = i.id
                JOIN publications p ON i.publication_id = p.id
                WHERE p.slug = ?
                GROUP BY i.id
                ORDER BY i.issue_number DESC
            ");
            $stmt->execute([$pubSlug]);
            json_response(['issues' => $stmt->fetchAll()]);
            break;

        case 'issue':
            $pubSlug = $_GET['slug'] ?? '';
            $issueNum = (int)($_GET['num'] ?? 0);
            $stmt = $db->prepare("
                SELECT i.* FROM issues i
                JOIN publications p ON i.publication_id = p.id
                WHERE p.slug = ? AND i.issue_number = ?
            ");
            $stmt->execute([$pubSlug, $issueNum]);
            $issue = $stmt->fetch();
            if (!$issue) json_response(['error' => '期刊不存在'], 404);

            $stmt = $db->prepare("
                SELECT a.* FROM articles a
                WHERE a.issue_id = ?
                ORDER BY a.created_at ASC
            ");
            $stmt->execute([$issue['id']]);
            $issue['articles'] = $stmt->fetchAll();
            json_response(['issue' => $issue]);
            break;

        // ---- 评论 ----
        case 'comment':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $articleId = (int)($input['articleId'] ?? 0);
            $body = trim($input['body'] ?? '');
            if (mb_strlen($body) < 1) json_response(['error' => '评论不能为空'], 400);

            $stmt = $db->prepare('INSERT INTO comments (article_id, user_id, author_name, body) VALUES (?, ?, ?, ?)');
            $stmt->execute([$articleId, $user['id'], $user['username'], $body]);
            // 通知该文章所属刊物的拥有者：有人评论
            $cst = $db->prepare("SELECT p.owner_id, p.name as pub_name, a.title FROM articles a JOIN publications p ON a.publication_id=p.id WHERE a.id=?");
            $cst->execute([$articleId]);
            $cRow = $cst->fetch();
            if ($cRow) add_notification($db, $cRow['owner_id'], 'comment', $user['id'], "{$user['username']} 评论了《{$cRow['pub_name']}》的文章《{$cRow['title']}》", "#/article/{$articleId}");
            json_response(['comment' => ['id' => (int)$db->lastInsertId(), 'author_name' => $user['username'], 'body' => $body]]);
            break;

        // ---- 订阅 ----
        case 'subscribe':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $pubSlug = $input['pubSlug'] ?? '';
            $stmt = $db->prepare("SELECT id FROM publications WHERE slug = ?");
            $stmt->execute([$pubSlug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);

            $stmt = $db->prepare('INSERT OR IGNORE INTO subscriptions (user_id, publication_id) VALUES (?, ?)');
            $stmt->execute([$user['id'], $pub['id']]);

            // 订阅者自动成为该刊物「读者」身份组（pub_members）
            $rr = $db->prepare("SELECT id FROM pub_roles WHERE publication_id=? AND name='读者'");
            $rr->execute([$pub['id']]);
            $readerRoleId = $rr->fetchColumn();
            if ($readerRoleId !== false) {
                $db->prepare('INSERT OR IGNORE INTO pub_members (user_id, publication_id, role_id) VALUES (?, ?, ?)')
                    ->execute([$user['id'], $pub['id'], (int)$readerRoleId]);
            }
            json_response(['ok' => true]);
            break;

        case 'unsubscribe':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $pubSlug = $input['pubSlug'] ?? '';
            $stmt = $db->prepare("SELECT id FROM publications WHERE slug = ?");
            $stmt->execute([$pubSlug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);

            $stmt = $db->prepare('DELETE FROM subscriptions WHERE user_id = ? AND publication_id = ?');
            $stmt->execute([$user['id'], $pub['id']]);

            // 取消订阅则退出该刊物「读者」身份组（不影响主编/作者等其他身份组）
            $rr = $db->prepare("SELECT id FROM pub_roles WHERE publication_id=? AND name='读者'");
            $rr->execute([$pub['id']]);
            $readerRoleId = $rr->fetchColumn();
            if ($readerRoleId !== false) {
                $db->prepare('DELETE FROM pub_members WHERE user_id=? AND publication_id=? AND role_id=?')
                    ->execute([$user['id'], $pub['id'], (int)$readerRoleId]);
            }
            json_response(['ok' => true]);
            break;

        case 'subscriptions':
            $user = require_login($db);
            $stmt = $db->prepare("
                SELECT p.* FROM subscriptions s
                JOIN publications p ON s.publication_id = p.id
                WHERE s.user_id = ?
                ORDER BY s.created_at DESC
            ");
            $stmt->execute([$user['id']]);
            json_response(['subscriptions' => $stmt->fetchAll()]);
            break;

        // ---- RSS 订阅源 ----
        case 'feed':
            $pubSlug = $_GET['slug'] ?? '';
            $stmt = $db->prepare("
                SELECT a.*, p.name as pub_name, p.slug as pub_slug
                FROM articles a
                JOIN publications p ON a.publication_id = p.id
                WHERE p.slug = ? AND a.status = 'published'
                ORDER BY a.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$pubSlug]);
            $articles = $stmt->fetchAll();
            $pubName = $articles ? $articles[0]['pub_name'] : $pubSlug;

            header('Content-Type: application/rss+xml; charset=utf-8');
            $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
            $base = rtrim($base, '/');

            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<rss version="2.0"><channel>' . "\n";
            echo '<title>' . htmlspecialchars($pubName) . ' — 万刊网</title>' . "\n";
            echo '<link>' . $base . '/#' . $pubSlug . '</link>' . "\n";
            echo '<description>' . htmlspecialchars($pubName) . ' 最新文章 RSS 订阅</description>' . "\n";
            echo '<language>zh-CN</language>' . "\n";

            foreach ($articles as $a) {
                echo '<item>' . "\n";
                echo '<title>' . htmlspecialchars($a['title']) . '</title>' . "\n";
                echo '<link>' . $base . '/#/pub/' . $a['pub_slug'] . '/article/' . $a['id'] . '</link>' . "\n";
                echo '<description>' . htmlspecialchars($a['abstract'] ?: mb_substr($a['body'], 0, 200)) . '</description>' . "\n";
                echo '<author>' . htmlspecialchars($a['author']) . '</author>' . "\n";
                echo '<pubDate>' . date('r', strtotime($a['created_at'])) . '</pubDate>' . "\n";
                echo '<guid>' . $base . '/#/pub/' . $a['pub_slug'] . '/article/' . $a['id'] . '</guid>' . "\n";
                echo '</item>' . "\n";
            }
            echo '</channel></rss>';
            exit;

        // ---- 用户信息更新 ----
        case 'update_profile':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $bio = trim($input['bio'] ?? '');
            if (mb_strlen($bio) > 500) json_response(['error' => '个人简介不能超过500字'], 400);
            $db->prepare('UPDATE users SET bio = ? WHERE id = ?')->execute([$bio, $user['id']]);
            json_response(['ok' => true, 'bio' => $bio]);
            break;

        // ---- 用户主页 ----
        case 'profile':
            // 优先按 uid（数字）查询，兼容旧的 username 参数（避免网址出现中文）
            $ident = $_GET['uid'] ?? ($_GET['username'] ?? '');
            if ($ident !== '' && ctype_digit((string)$ident)) {
                $stmt = $db->prepare("SELECT id, username, email, bio, verified, avatar, cover, created_at, uid FROM users WHERE uid = ?");
                $stmt->execute([(int)$ident]);
            } else {
                $stmt = $db->prepare("SELECT id, username, email, bio, verified, avatar, cover, created_at, uid FROM users WHERE username = ?");
                $stmt->execute([$ident]);
            }
            $user = $stmt->fetch();
            if (!$user) json_response(['error' => '用户不存在'], 404);
            $user['platform_roles'] = platform_roles_of($db, $user['id']);
            $viewer = current_user($db);
            $isFollowing = false;
            if ($viewer && $viewer['id'] != $user['id']) {
                $fs = $db->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
                $fs->execute([$viewer['id'], $user['id']]);
                $isFollowing = $fs->fetch() ? true : false;
            }
            $user['is_following'] = $isFollowing;

            $stmt = $db->prepare("SELECT * FROM publications WHERE owner_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user['id']]);
            $pubs = $stmt->fetchAll();

            $stmt = $db->prepare("SELECT a.*, p.name as pub_name, p.slug as pub_slug FROM articles a JOIN publications p ON a.publication_id = p.id WHERE a.author_email = ? OR a.author = ? ORDER BY a.created_at DESC");
            $stmt->execute([$user['email'] ?? '', $user['username']]);
            $articles = $stmt->fetchAll();

            json_response(['user' => $user, 'publications' => $pubs, 'articles' => $articles]);
            break;

        // ---- 关注 / 取关（toggle） ----
        case 'follow':
            $user = require_login($db);
            $input = get_input();
            $name = trim($input['username'] ?? '');
            if ($name === '') json_response(['error' => '缺少用户名'], 400);
            $stmt = $db->prepare('SELECT id, username, uid FROM users WHERE username = ?');
            $stmt->execute([$name]);
            $target = $stmt->fetch();
            if (!$target) json_response(['error' => '用户不存在'], 404);
            if ($target['id'] == $user['id']) json_response(['error' => '不能关注自己'], 400);
            $chk = $db->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
            $chk->execute([$user['id'], $target['id']]);
            $following = !$chk->fetch();
            if ($following) {
                $db->prepare('INSERT INTO follows (follower_id, following_id) VALUES (?, ?)')->execute([$user['id'], $target['id']]);
                add_notification($db, $target['id'], 'follow', $user['id'], "{$user['username']} 关注了你", "#/profile/{$target['uid']}");
            } else {
                $db->prepare('DELETE FROM follows WHERE follower_id = ? AND following_id = ?')->execute([$user['id'], $target['id']]);
            }
            $fc = (int)$db->query("SELECT COUNT(*) FROM follows WHERE following_id = {$target['id']}")->fetchColumn();
            json_response(['ok' => true, 'following' => $following, 'follower_count' => $fc]);
            break;

        // ---- 关注列表 / 粉丝列表 ----
        case 'follow_list':
            $viewer = current_user($db);
            $type = trim($_GET['type'] ?? 'following');
            $uname = trim($_GET['user'] ?? '');
            if ($uname) {
                $stmt = $db->prepare('SELECT id FROM users WHERE username = ?'); $stmt->execute([$uname]);
                $ou = $stmt->fetch(); $ouId = $ou ? $ou['id'] : 0;
            } else {
                $ou = $viewer; $ouId = $ou ? $ou['id'] : 0;
            }
            if (!$ouId) json_response(['users' => []]);
            if ($type === 'followers') {
                $stmt = $db->prepare("SELECT u.id, u.username, u.avatar, u.bio, u.uid FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.following_id = ? ORDER BY f.created_at DESC LIMIT 200");
            } else {
                $stmt = $db->prepare("SELECT u.id, u.username, u.avatar, u.bio, u.uid FROM follows f JOIN users u ON u.id = f.following_id WHERE f.follower_id = ? ORDER BY f.created_at DESC LIMIT 200");
            }
            $stmt->execute([$ouId]);
            $rows = $stmt->fetchAll();
            if ($viewer) {
                foreach ($rows as &$r) {
                    $f = $db->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
                    $f->execute([$viewer['id'], $r['id']]);
                    $r['is_following'] = $f->fetch() ? true : false;
                    $r['avatar'] = abs_url($r['avatar']);
                }
            } else {
                foreach ($rows as &$r) { $r['is_following'] = false; $r['avatar'] = abs_url($r['avatar']); }
            }
            json_response(['users' => $rows, 'type' => $type]);
            break;

        // ---- 搜索用户（私信选择器 / 关注用） ----
        case 'user_search':
            $viewer = current_user($db);
            $q = trim($_GET['q'] ?? '');
            if ($q === '') json_response(['users' => []]);
            $stmt = $db->prepare("SELECT id, username, avatar, bio, uid FROM users WHERE username LIKE ? ORDER BY id ASC LIMIT 30");
            $stmt->execute(['%' . $q . '%']);
            $rows = $stmt->fetchAll();
            if ($viewer) {
                foreach ($rows as &$r) {
                    $f = $db->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
                    $f->execute([$viewer['id'], $r['id']]);
                    $r['is_following'] = $f->fetch() ? true : false;
                    $r['avatar'] = abs_url($r['avatar']);
                }
            } else {
                foreach ($rows as &$r) { $r['is_following'] = false; $r['avatar'] = abs_url($r['avatar']); }
            }
            json_response(['users' => $rows]);
            break;

        // ---- 统计 ----
        case 'stats':
            $pubCount = $db->query("SELECT COUNT(*) FROM publications")->fetchColumn();
            $articleCount = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
            $publishedCount = $db->query("SELECT COUNT(*) FROM articles WHERE status = 'published'")->fetchColumn();
            $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $issueCount = $db->query("SELECT COUNT(*) FROM issues")->fetchColumn();
            json_response([
                'publications' => (int)$pubCount,
                'articles' => (int)$articleCount,
                'published' => (int)$publishedCount,
                'users' => (int)$userCount,
                'issues' => (int)$issueCount
            ]);
            break;

        // ---- 邮箱验证 ----
        case 'verify':
            $token = $_GET['token'] ?? ($_POST['token'] ?? '');
            if (empty($token)) json_response(['error' => '缺少验证令牌'], 400);
            $stmt = $db->prepare('SELECT * FROM users WHERE verify_token = ?');
            $stmt->execute([$token]);
            $u = $stmt->fetch();
            if (!$u) json_response(['error' => '验证链接无效或已过期'], 400);
            $db->prepare("UPDATE users SET verified = 1, verify_token = '' WHERE id = ?")->execute([$u['id']]);
            json_response(['ok' => true, 'username' => $u['username']]);
            break;

        case 'resend_verify':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!empty($user['verified'])) json_response(['ok' => true, 'already' => true]);
            $token = bin2hex(random_bytes(16));
            $db->prepare('UPDATE users SET verify_token = ? WHERE id = ?')->execute([$token, $user['id']]);
            $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/#/verify/' . $token;
            @mail($user['email'], '万刊网邮箱验证', "点击以下链接完成邮箱验证：\n" . $link);
            json_response(['ok' => true, 'verifyUrl' => $link]);
            break;

        // ---- 头像 / 背景图上传 ----
        case 'avatar':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $target = $input['target'] ?? 'user';
            $img = $input['image'] ?? '';
            if (!preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,/', $img, $m)) {
                json_response(['error' => '请上传图片文件（png/jpg/gif/webp）'], 400);
            }
            $sizeLimit = 3 * 1024 * 1024; // 头像约 2MB
            if ($target === 'user_cover' || $target === 'pub_cover') $sizeLimit = 6 * 1024 * 1024; // 背景图约 4MB
            if (strlen($img) > $sizeLimit) json_response(['error' => '图片过大，背景图上限约 4MB，头像约 2MB'], 400);
            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];

            // 解析目标表 / 列 / 目录 / 路径
            $table = null; $col = null; $id = null; $dir = null; $path = null;
            if ($target === 'user') {
                $table = 'users'; $col = 'avatar'; $id = $user['id'];
                $dir = __DIR__ . '/uploads/avatars'; $path = "uploads/avatars/{$id}.{$ext}";
            } elseif ($target === 'user_cover') {
                $table = 'users'; $col = 'cover'; $id = $user['id'];
                $dir = __DIR__ . '/uploads/covers'; $path = "uploads/covers/u_{$id}.{$ext}";
            } elseif ($target === 'pub') {
                $pubId = (int)($input['id'] ?? 0);
                $stmt = $db->prepare('SELECT * FROM publications WHERE id = ?');
                $stmt->execute([$pubId]);
                $pub = $stmt->fetch();
                if (!$pub) json_response(['error' => '刊物不存在'], 404);
                $myPerms = pub_perms_of($db, $user['id'], $pubId);
                if (!has_perm($myPerms, PERM_MANAGE_PUB) && $pub['owner_id'] != $user['id']) json_response(['error' => '只有刊物管理者可以设置'], 403);
                $table = 'publications'; $col = 'avatar'; $id = $pubId;
                $dir = __DIR__ . '/uploads/pubs'; $path = "uploads/pubs/{$pubId}.{$ext}";
            } elseif ($target === 'pub_cover') {
                $pubId = (int)($input['id'] ?? 0);
                $stmt = $db->prepare('SELECT * FROM publications WHERE id = ?');
                $stmt->execute([$pubId]);
                $pub = $stmt->fetch();
                if (!$pub) json_response(['error' => '刊物不存在'], 404);
                $myPerms = pub_perms_of($db, $user['id'], $pubId);
                if (!has_perm($myPerms, PERM_MANAGE_PUB) && $pub['owner_id'] != $user['id']) json_response(['error' => '只有刊物管理者可以设置'], 403);
                $table = 'publications'; $col = 'cover'; $id = $pubId;
                $dir = __DIR__ . '/uploads/pubs'; $path = "uploads/pubs/cover_{$pubId}.{$ext}";
            } else {
                json_response(['error' => '无效上传目标'], 400);
            }

            // 删除旧的同名不同扩展名文件，避免残留
            $stmt = $db->prepare("SELECT $col FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            $old = $stmt->fetchColumn();
            if ($old && $old !== $path && file_exists(__DIR__ . '/' . $old)) @unlink(__DIR__ . '/' . $old);

            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $binary = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $img));
            file_put_contents(__DIR__ . '/' . $path, $binary);
            $db->prepare("UPDATE $table SET $col = ? WHERE id = ?")->execute([$path, $id]);
            json_response(['ok' => true, 'url' => $path, 'target' => $target]);
            break;

        // ---- 管理员删除用户 ----
        case 'delete_user':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_USERS)) json_response(['error' => '无权限删除用户'], 403);
            $input = get_input();
            $username = trim($input['username'] ?? '');
            $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $target = $stmt->fetch();
            if (!$target) json_response(['error' => '用户不存在'], 404);
            if ($target['id'] == $user['id']) json_response(['error' => '不能删除自己'], 400);
            if ($target['id'] == 1) json_response(['error' => '不能删除管理员'], 400);
            $stmt = $db->prepare('SELECT id FROM publications WHERE owner_id = ?');
            $stmt->execute([$target['id']]);
            $pubIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($pubIds) {
                $ph = implode(',', array_fill(0, count($pubIds), '?'));
                $db->prepare("DELETE FROM articles WHERE publication_id IN ($ph)")->execute($pubIds);
                $db->prepare("DELETE FROM issues WHERE publication_id IN ($ph)")->execute($pubIds);
                $db->prepare("DELETE FROM subscriptions WHERE publication_id IN ($ph)")->execute($pubIds);
                $db->prepare("DELETE FROM publications WHERE id IN ($ph)")->execute($pubIds);
            }
            $db->prepare('DELETE FROM comments WHERE user_id = ?')->execute([$target['id']]);
            $db->prepare('DELETE FROM subscriptions WHERE user_id = ?')->execute([$target['id']]);
            log_admin_action($db, $user['id'], 'delete_user', 'user', $target['id'], "删除用户 {$username}", ['table' => 'users', 'row' => $target]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$target['id']]);
            json_response(['ok' => true, 'deleted' => $username]);
            break;

        // ---- 私信 ----
        case 'messages':
            if ($method === 'GET') {
                $user = require_login($db);
                $with = trim($_GET['with'] ?? '');
                if ($with) {
                    // 与某用户的会话详情，并按需标记对方来信为已读
                    $stmt = $db->prepare('SELECT id, username, avatar, cover FROM users WHERE username = ?');
                    $stmt->execute([$with]);
                    $peer = $stmt->fetch();
                    if (!$peer) json_response(['error' => '用户不存在'], 404);
                    $peer['avatar'] = abs_url($peer['avatar']);
                    $peer['cover'] = abs_url($peer['cover']);
                    $stmt = $db->prepare("
                        SELECT m.*, s.username as sender_name, s.avatar as sender_avatar
                        FROM messages m
                        JOIN users s ON m.sender_id = s.id
                        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
                        ORDER BY m.created_at ASC
                    ");
                    $stmt->execute([$user['id'], $peer['id'], $peer['id'], $user['id']]);
                    $msgs = $stmt->fetchAll();
                    $db->prepare('UPDATE messages SET read = 1 WHERE receiver_id = ? AND sender_id = ? AND read = 0')
                        ->execute([$user['id'], $peer['id']]);
                    json_response(['peer' => $peer, 'messages' => $msgs]);
                } else {
                    // 会话列表
                    $stmt = $db->prepare("
                        SELECT
                          CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END as other_id,
                          MAX(created_at) as last_time,
                          COUNT(*) as total
                        FROM messages
                        WHERE sender_id = ? OR receiver_id = ?
                        GROUP BY other_id
                        ORDER BY last_time DESC
                    ");
                    $stmt->execute([$user['id'], $user['id'], $user['id']]);
                    $rows = $stmt->fetchAll();
                    $convs = [];
                    foreach ($rows as $row) {
                        $oid = $row['other_id'];
                        $stmt2 = $db->prepare('SELECT id, username, avatar, cover FROM users WHERE id = ?');
                        $stmt2->execute([$oid]);
                        $peer = $stmt2->fetch();
                        if (!$peer) continue;
                        $peer['avatar'] = abs_url($peer['avatar']);
                        $peer['cover'] = abs_url($peer['cover']);
                        $stmt3 = $db->prepare("
                            SELECT body, sender_id, created_at FROM messages
                            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $stmt3->execute([$user['id'], $oid, $oid, $user['id']]);
                        $last = $stmt3->fetch();
                        $stmt4 = $db->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND sender_id = ? AND read = 0');
                        $stmt4->execute([$user['id'], $oid]);
                        $unread = (int)$stmt4->fetchColumn();
                        $convs[] = [
                            'user' => $peer,
                            'last' => $last ? $last['body'] : '',
                            'last_time' => $last ? $last['created_at'] : '',
                            'last_is_me' => $last ? ($last['sender_id'] == $user['id']) : false,
                            'unread' => $unread
                        ];
                    }
                    json_response(['conversations' => $convs]);
                }
            }
            if ($method === 'POST') {
                $user = require_login($db);
                $input = get_input();
                $to = trim($input['to'] ?? '');
                $body = trim($input['body'] ?? '');
                if (mb_strlen($body) < 1) json_response(['error' => '私信内容不能为空'], 400);
                if (mb_strlen($body) > 2000) json_response(['error' => '私信内容过长（上限 2000 字）'], 400);
                $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
                $stmt->execute([$to]);
                $peer = $stmt->fetch();
                if (!$peer) json_response(['error' => '收信用户不存在'], 404);
                if ($peer['id'] == $user['id']) json_response(['error' => '不能给自己发私信'], 400);
                // 关注闸门：必须先关注对方才能私信
                $fs = $db->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
                $fs->execute([$user['id'], $peer['id']]);
                if (!$fs->fetch()) json_response(['error' => "请先关注「{$peer['username']}」后才能私信"], 403);
                $stmt = $db->prepare('INSERT INTO messages (sender_id, receiver_id, body, read) VALUES (?, ?, ?, 0)');
                $stmt->execute([$user['id'], $peer['id'], $body]);
                // 通知接收者：有人发私信
                add_notification($db, $peer['id'], 'dm', $user['id'], "{$user['username']} 给你发了私信", "#/messages");
                json_response(['ok' => true, 'message' => ['id' => (int)$db->lastInsertId(), 'body' => $body, 'created_at' => date('Y-m-d H:i:s')]]);
            }
            if ($method === 'DELETE') {
                $user = require_login($db);
                $with = trim($_GET['with'] ?? '');
                if (empty($with)) json_response(['error' => '缺少对方用户名'], 400);
                $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
                $stmt->execute([$with]);
                $peer = $stmt->fetch();
                if (!$peer) json_response(['error' => '用户不存在'], 404);
                $db->prepare('DELETE FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)')
                    ->execute([$user['id'], $peer['id'], $peer['id'], $user['id']]);
                json_response(['ok' => true]);
            }
            break;

        case 'unread':
            if ($method === 'GET') {
                $user = require_login($db);
                $stmt = $db->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read = 0');
                $stmt->execute([$user['id']]);
                $count = (int)$stmt->fetchColumn();
                json_response(['count' => $count]);
            }
            break;

        // ---- 通知（消息中心「通知」tab） ----
        case 'notifications':
            $user = require_login($db);
            if ($method === 'GET') {
                $stmt = $db->prepare("
                    SELECT n.*, u.username as actor_name, u.avatar as actor_avatar
                    FROM notifications n
                    LEFT JOIN users u ON n.actor_id = u.id
                    WHERE n.user_id = ?
                    ORDER BY n.created_at DESC LIMIT 100
                ");
                $stmt->execute([$user['id']]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$r) {
                    $r['actor_avatar'] = abs_url($r['actor_avatar'] ?? '');
                    $r['read'] = (int)$r['read'];
                }
                json_response(['notifications' => $rows]);
            }
            if ($method === 'POST') {
                // 标记已读：带 id 标记单条，否则标记全部
                $input = get_input();
                $id = (int)($input['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare('UPDATE notifications SET read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
                } else {
                    $db->prepare('UPDATE notifications SET read = 1 WHERE user_id = ?')->execute([$user['id']]);
                }
                json_response(['ok' => true]);
            }
            break;

        // 合并未读（供导航栏红点）：私信未读 + 通知未读
        case 'inbox_unread':
            if ($method === 'GET') {
                $user = require_login($db);
                $dm = (int)$db->query("SELECT COUNT(*) FROM messages WHERE receiver_id = {$user['id']} AND read = 0")->fetchColumn();
                $nt = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$user['id']} AND `read` = 0")->fetchColumn();
                json_response(['dm' => $dm, 'notif' => $nt, 'total' => $dm + $nt]);
            }
            break;

        // 平台公告（管理员广播给所有用户）
        case 'announce':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN)) json_response(['error' => '无权限发布公告'], 403);
            $input = get_input();
            $body = trim($input['body'] ?? '');
            if (mb_strlen($body) < 2) json_response(['error' => '公告内容至少2个字符'], 400);
            $stmt = $db->query('SELECT id FROM users');
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $db->prepare('INSERT INTO notifications (user_id, type, actor_id, body, link) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$uid, 'announce', $user['id'], $body, '#/messages']);
            }
            json_response(['ok' => true, 'sent' => $stmt->rowCount()]);
            break;

        // ---- 刊物「服务器」：频道 / 频道聊天 / 频道帖子 ----

        case 'pub_channels':
            if ($method === 'GET') {
                $slug = trim($_GET['slug'] ?? '');
                if (!$slug) json_response(['error' => '缺少 slug'], 400);
                $stmt = $db->prepare('SELECT * FROM publications WHERE slug = ?');
                $stmt->execute([$slug]);
                $pub = $stmt->fetch();
                if (!$pub) json_response(['error' => '刊物不存在'], 404);
                // 无频道则播种默认频道（大厅/公告/讨论），兼顾新建与存量刊物
                $c = $db->prepare('SELECT COUNT(*) FROM pub_channels WHERE publication_id = ?');
                $c->execute([$pub['id']]);
                if ((int)$c->fetchColumn() === 0) {
                    $db->prepare('INSERT INTO pub_channels (publication_id, grp, name, type, position) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$pub['id'], '综合', '大厅', 'chat', 1]);
                    $db->prepare('INSERT INTO pub_channels (publication_id, grp, name, type, position) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$pub['id'], '综合', '公告', 'post', 2]);
                    $db->prepare('INSERT INTO pub_channels (publication_id, grp, name, type, position) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$pub['id'], '综合', '讨论', 'chat', 3]);
                }
                $stmt = $db->prepare('SELECT * FROM pub_channels WHERE publication_id = ? ORDER BY grp ASC, position ASC, id ASC');
                $stmt->execute([$pub['id']]);
                json_response(['channels' => $stmt->fetchAll()]);
            }
            if ($method === 'POST') {
                $user = require_login($db);
                $input = get_input();
                $slug = trim($input['slug'] ?? '');
                $stmt = $db->prepare('SELECT * FROM publications WHERE slug = ?');
                $stmt->execute([$slug]);
                $pub = $stmt->fetch();
                if (!$pub) json_response(['error' => '刊物不存在'], 404);
                $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
                if (!has_perm($myPerms, PERM_MANAGE_PUB) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限管理频道'], 403);
                $grp = trim($input['grp'] ?? '综合');
                $name = trim($input['name'] ?? '');
                $type = trim($input['type'] ?? 'chat');
                if (mb_strlen($name) < 1 || mb_strlen($name) > 30) json_response(['error' => '频道名 1-30 字'], 400);
                if (!in_array($type, ['chat', 'post'])) $type = 'chat';
                $pos = (int)($input['position'] ?? 0);
                $db->prepare('INSERT INTO pub_channels (publication_id, grp, name, type, position) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$pub['id'], $grp, $name, $type, $pos]);
                $newId = $db->lastInsertId();
                json_response(['channel' => ['id' => (int)$newId, 'publication_id' => (int)$pub['id'], 'grp' => $grp, 'name' => $name, 'type' => $type, 'position' => $pos]]);
            }
            if ($method === 'DELETE') {
                $user = require_login($db);
                $input = get_input();
                $slug = trim($input['slug'] ?? '');
                $channelId = (int)($input['channel'] ?? 0);
                $stmt = $db->prepare('SELECT * FROM publications WHERE slug = ?');
                $stmt->execute([$slug]);
                $pub = $stmt->fetch();
                if (!$pub) json_response(['error' => '刊物不存在'], 404);
                $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
                if (!has_perm($myPerms, PERM_MANAGE_PUB) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限管理频道'], 403);
                $stmt = $db->prepare('SELECT * FROM pub_channels WHERE id = ? AND publication_id = ?');
                $stmt->execute([$channelId, $pub['id']]);
                $ch = $stmt->fetch();
                if (!$ch) json_response(['error' => '频道不存在'], 404);
                $db->prepare('DELETE FROM pub_chat WHERE channel_id = ?')->execute([$channelId]);
                $db->prepare('DELETE FROM pub_posts WHERE channel_id = ?')->execute([$channelId]);
                $db->prepare('DELETE FROM pub_channels WHERE id = ?')->execute([$channelId]);
                json_response(['ok' => true]);
            }
            break;

        case 'pub_chat':
            if ($method === 'GET') {
                $channelId = (int)($_GET['channel'] ?? 0);
                if (!$channelId) json_response(['error' => '缺少 channel'], 400);
                $stmt = $db->prepare("
                    SELECT m.*, u.username as user_name, u.avatar as user_avatar
                    FROM pub_chat m LEFT JOIN users u ON m.user_id = u.id
                    WHERE m.channel_id = ?
                    ORDER BY m.created_at ASC, m.id ASC LIMIT 300
                ");
                $stmt->execute([$channelId]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$r) { $r['user_avatar'] = abs_url($r['user_avatar'] ?? ''); }
                json_response(['messages' => $rows]);
            }
            if ($method === 'POST') {
                $user = require_login($db);
                $input = get_input();
                $channelId = (int)($input['channel'] ?? 0);
                $body = trim($input['body'] ?? '');
                if (!$channelId) json_response(['error' => '缺少 channel'], 400);
                if (mb_strlen($body) < 1 || mb_strlen($body) > 2000) json_response(['error' => '内容 1-2000 字'], 400);
                $stmt = $db->prepare('SELECT * FROM pub_channels WHERE id = ?');
                $stmt->execute([$channelId]);
                $ch = $stmt->fetch();
                if (!$ch) json_response(['error' => '频道不存在'], 404);
                if ($ch['type'] !== 'chat') json_response(['error' => '该频道不是聊天频道'], 400);
                $db->prepare('INSERT INTO pub_chat (channel_id, publication_id, user_id, body) VALUES (?, ?, ?, ?)')
                    ->execute([$channelId, $ch['publication_id'], $user['id'], $body]);
                json_response(['ok' => true]);
            }
            break;

        case 'pub_posts':
            if ($method === 'GET') {
                $channelId = (int)($_GET['channel'] ?? 0);
                if (!$channelId) json_response(['error' => '缺少 channel'], 400);
                $stmt = $db->prepare("
                    SELECT p.*, u.username as user_name, u.avatar as user_avatar
                    FROM pub_posts p LEFT JOIN users u ON p.user_id = u.id
                    WHERE p.channel_id = ?
                    ORDER BY p.created_at DESC, p.id DESC LIMIT 200
                ");
                $stmt->execute([$channelId]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$r) { $r['user_avatar'] = abs_url($r['user_avatar'] ?? ''); }
                json_response(['posts' => $rows]);
            }
            if ($method === 'POST') {
                $user = require_login($db);
                $input = get_input();
                $channelId = (int)($input['channel'] ?? 0);
                $title = trim($input['title'] ?? '');
                $body = trim($input['body'] ?? '');
                if (!$channelId) json_response(['error' => '缺少 channel'], 400);
                if (mb_strlen($title) < 1 || mb_strlen($title) > 100) json_response(['error' => '标题 1-100 字'], 400);
                if (mb_strlen($body) < 1 || mb_strlen($body) > 8000) json_response(['error' => '正文 1-8000 字'], 400);
                $stmt = $db->prepare('SELECT * FROM pub_channels WHERE id = ?');
                $stmt->execute([$channelId]);
                $ch = $stmt->fetch();
                if (!$ch) json_response(['error' => '频道不存在'], 404);
                if ($ch['type'] !== 'post') json_response(['error' => '该频道不是发帖频道'], 400);
                $db->prepare('INSERT INTO pub_posts (channel_id, publication_id, user_id, title, body) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$channelId, $ch['publication_id'], $user['id'], $title, $body]);
                json_response(['ok' => true]);
            }
            break;

        // ---- 模块 / 插件 / 扩展：平台级开关 + 刊物级覆盖 ----
        case 'modules':
            if ($method === 'GET') {
                $stmt = $db->prepare('SELECT * FROM modules ORDER BY scope ASC, position ASC, id ASC');
                $stmt->execute([]);
                $mods = $stmt->fetchAll();
                $pubSettings = [];
                $slug = trim($_GET['slug'] ?? '');
                if ($slug) {
                    $p = $db->prepare('SELECT id FROM publications WHERE slug = ?');
                    $p->execute([$slug]);
                    $pid = $p->fetchColumn();
                    if ($pid) {
                        $ps = $db->prepare('SELECT mkey, enabled FROM pub_module_settings WHERE publication_id = ?');
                        $ps->execute([$pid]);
                        foreach ($ps->fetchAll() as $row) { $pubSettings[$row['mkey']] = (int)$row['enabled']; }
                    }
                }
                json_response(['modules' => $mods, 'pub_settings' => $pubSettings]);
            }
            break;

        case 'module_toggle':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN))
                json_response(['error' => '无权限管理模块'], 403);
            $input = get_input();
            $mkey = trim($input['mkey'] ?? '');
            $m = $db->prepare('SELECT * FROM modules WHERE mkey = ?');
            $m->execute([$mkey]);
            $mod = $m->fetch();
            if (!$mod) json_response(['error' => '模块不存在'], 404);
            $next = $mod['enabled'] ? 0 : 1;
            $db->prepare('UPDATE modules SET enabled = ? WHERE mkey = ?')->execute([$next, $mkey]);
            json_response(['mkey' => $mkey, 'enabled' => $next]);
            break;

        case 'pub_module_toggle':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $slug = trim($input['slug'] ?? '');
            $mkey = trim($input['mkey'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug = ?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_PUB) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限管理该刊物模块'], 403);
            $m = $db->prepare('SELECT * FROM modules WHERE mkey = ?');
            $m->execute([$mkey]);
            if (!$m->fetch()) json_response(['error' => '模块不存在'], 404);
            // 当前覆盖
            $cur = $db->prepare('SELECT enabled FROM pub_module_settings WHERE publication_id = ? AND mkey = ?');
            $cur->execute([$pub['id'], $mkey]);
            $row = $cur->fetch();
            // 基准：平台级该模块是否启用（未设置时按平台级走）
            $plat = $db->prepare('SELECT enabled FROM modules WHERE mkey = ?');
            $plat->execute([$mkey]);
            $platOn = (int)($plat->fetch()['enabled']);
            $next = $row ? ((int)$row['enabled'] ? 0 : 1) : ($platOn ? 0 : 1);
            $db->prepare('INSERT OR REPLACE INTO pub_module_settings (publication_id, mkey, enabled) VALUES (?, ?, ?)')
                ->execute([$pub['id'], $mkey, $next]);
            json_response(['slug' => $slug, 'mkey' => $mkey, 'enabled' => $next]);
            break;

        // ---- 平台身份组 ----
        case 'platform_roles':
            if ($method === 'GET') {
                $stmt = $db->query("SELECT r.*, (SELECT COUNT(*) FROM user_platform_roles ur WHERE ur.role_id=r.id) as member_count FROM platform_roles r ORDER BY r.position DESC");
                json_response(['roles' => $stmt->fetchAll()]);
            }
            break;

        case 'role_save':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_ROLES)) json_response(['error' => '无权限管理身份组'], 403);
            $input = get_input();
            $name = trim($input['name'] ?? '');
            $color = trim($input['color'] ?? '#5865f2');
            $permissions = (int)($input['permissions'] ?? 0);
            $id = (int)($input['id'] ?? 0);
            if (($id === 0 || $name === '平台总构建师') && !is_prime_admin($user)) json_response(['error' => '无权修改系统身份组'], 403);
            if (mb_strlen($name) < 1) json_response(['error' => '请输入身份组名称'], 400);
            if ($id) {
                $db->prepare('UPDATE platform_roles SET name=?, color=?, permissions=? WHERE id=?')->execute([$name, $color, $permissions, $id]);
                json_response(['ok' => true, 'role' => ['id' => $id, 'name' => $name, 'color' => $color, 'permissions' => $permissions]]);
            } else {
                $db->prepare('INSERT INTO platform_roles (name, color, permissions, position) VALUES (?, ?, ?, 0)')->execute([$name, $color, $permissions]);
                json_response(['ok' => true, 'id' => (int)$db->lastInsertId()]);
            }
            break;

        case 'role_delete':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_ROLES)) json_response(['error' => '无权限'], 403);
            $input = get_input();
            $id = (int)($input['id'] ?? 0);
            if ($id === 0 && !is_prime_admin($user)) json_response(['error' => '无权删除系统身份组'], 403);
            $db->prepare('DELETE FROM user_platform_roles WHERE role_id=?')->execute([$id]);
            $db->prepare('DELETE FROM platform_roles WHERE id=?')->execute([$id]);
            json_response(['ok' => true]);
            break;

        case 'admin_role':
        case 'role_assign':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_USERS) && !has_perm($user['platform_perms'], PERM_MANAGE_ROLES)) json_response(['error' => '无权限'], 403);
            $input = get_input();
            $username = trim($input['username'] ?? '');
            // 兼容两种写法：role_id + assign，或 role(身份组名) + action(add/remove)
            $roleId = (int)($input['role_id'] ?? 0);
            $assign = !empty($input['assign']);
            if (!empty($input['role'])) {
                $rn = $db->prepare("SELECT id FROM platform_roles WHERE name=?");
                $rn->execute([$input['role']]); $roleId = (int)$rn->fetchColumn();
                $assign = strtolower($input['action'] ?? 'add') !== 'remove';
            }
            // 系统身份组「平台总构建师」(id=0, 持 PERM_ROOT) 仅限平台总构建师本人操作
            if ($roleId === 0 && !is_prime_admin($user)) json_response(['error' => '无权赋予/移除「平台总构建师」身份组'], 403);
            if (!$roleId) json_response(['error' => '身份组无效'], 400);
            $stmt = $db->prepare('SELECT id FROM users WHERE username=?');
            $stmt->execute([$username]);
            $tu = $stmt->fetch();
            if (!$tu) json_response(['error' => '用户不存在'], 404);
            if ($assign) {
                $db->prepare('INSERT OR IGNORE INTO user_platform_roles (user_id, role_id) VALUES (?, ?)')->execute([$tu['id'], $roleId]);
                log_admin_action($db, $user['id'], 'promote', 'user', $tu['id'], "赋予身份组「{$input['role']}」给 {$username}", null);
            } else {
                $db->prepare('DELETE FROM user_platform_roles WHERE user_id=? AND role_id=?')->execute([$tu['id'], $roleId]);
                log_admin_action($db, $user['id'], 'demote', 'user', $tu['id'], "移除 {$username} 的身份组「{$input['role']}」", null);
            }
            json_response(['ok' => true]);
            break;

        case 'users_list':
            if ($method === 'GET') {
                $user = require_login($db);
                if (!has_perm($user['platform_perms'], PERM_MANAGE_USERS) && !has_perm($user['platform_perms'], PERM_MANAGE_ROLES)) json_response(['error' => '无权限'], 403);
                $stmt = $db->query("SELECT id, username, uid, avatar, verified FROM users ORDER BY id ASC");
                $users = $stmt->fetchAll();
                foreach ($users as &$u) { $u['roles'] = platform_roles_of($db, $u['id']); }
                json_response(['users' => $users]);
            }
            break;

        // ---- 刊物身份组 / 成员 ----
        case 'pub_roles':
            if ($method === 'GET') {
                $slug = $_GET['slug'] ?? '';
                $stmt = $db->prepare("SELECT p.* FROM publications p WHERE p.slug=?");
                $stmt->execute([$slug]);
                $pub = $stmt->fetch();
                if (!$pub) json_response(['error' => '刊物不存在'], 404);
                $stmt = $db->prepare("SELECT * FROM pub_roles WHERE publication_id=? ORDER BY position DESC");
                $stmt->execute([$pub['id']]);
                $roles = $stmt->fetchAll();
                $stmt = $db->prepare("
                    SELECT u.id, u.username, u.uid, u.avatar,
                           GROUP_CONCAT(r.name) as role_names,
                           GROUP_CONCAT(r.id) as role_ids,
                           (SELECT nickname FROM pub_nicknames WHERE user_id=u.id AND publication_id=?) as nickname
                    FROM pub_members m
                    JOIN users u ON m.user_id=u.id
                    JOIN pub_roles r ON m.role_id=r.id
                    WHERE m.publication_id=?
                    GROUP BY u.id
                ");
                $stmt->execute([$pub['id'], $pub['id']]);
                $members = $stmt->fetchAll();
                $cu = current_user($db);
                $myPerms = $cu ? pub_perms_of($db, $cu['id'], $pub['id']) : 0;
                $myRoles = $cu ? pub_roles_of($db, $cu['id'], $pub['id']) : [];
                json_response(['roles' => $roles, 'members' => $members, 'my_perms' => $myPerms, 'my_roles' => $myRoles]);
            }
            break;

        case 'pub_role_save':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $pubId = (int)($input['pubId'] ?? 0);
            $stmt = $db->prepare('SELECT * FROM publications WHERE id=?');
            $stmt->execute([$pubId]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pubId);
            if (!has_perm($myPerms, PERM_MANAGE_ROLES) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限管理身份组'], 403);
            $name = trim($input['name'] ?? '');
            $color = trim($input['color'] ?? '#5865f2');
            $permissions = (int)($input['permissions'] ?? 0);
            $id = (int)($input['id'] ?? 0);
            if (mb_strlen($name) < 1) json_response(['error' => '请输入身份组名称'], 400);
            if ($id) {
                $db->prepare('UPDATE pub_roles SET name=?, color=?, permissions=? WHERE id=?')->execute([$name, $color, $permissions, $id]);
            } else {
                $db->prepare('INSERT INTO pub_roles (publication_id, name, color, permissions, position) VALUES (?, ?, ?, ?, 0)')->execute([$pubId, $name, $color, $permissions]);
            }
            json_response(['ok' => true]);
            break;

        case 'pub_role_delete':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $roleId = (int)($input['role_id'] ?? 0);
            $stmt = $db->prepare('SELECT r.*, p.owner_id FROM pub_roles r JOIN publications p ON r.publication_id=p.id WHERE r.id=?');
            $stmt->execute([$roleId]);
            $role = $stmt->fetch();
            if (!$role) json_response(['error' => '身份组不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $role['publication_id']);
            if (!has_perm($myPerms, PERM_MANAGE_ROLES) && $role['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $db->prepare('DELETE FROM pub_members WHERE role_id=?')->execute([$roleId]);
            $db->prepare('DELETE FROM pub_roles WHERE id=?')->execute([$roleId]);
            json_response(['ok' => true]);
            break;

        case 'pub_member_add':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $slug = trim($input['slug'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限管理成员'], 403);
            $username = trim($input['username'] ?? '');
            $roleIds = $input['role_ids'] ?? [];
            $stmt = $db->prepare('SELECT id FROM users WHERE username=?');
            $stmt->execute([$username]);
            $tu = $stmt->fetch();
            if (!$tu) json_response(['error' => '用户不存在'], 404);
            // 校验 role_ids 确实属于本刊物（防跨刊物越权赋值）
            $validRoles = [];
            $vrStmt = $db->prepare('SELECT id FROM pub_roles WHERE id=? AND publication_id=?');
            foreach ($roleIds as $rid) {
                $vrStmt->execute([(int)$rid, $pub['id']]);
                if ($vrStmt->fetch()) $validRoles[] = (int)$rid;
            }
            foreach ($validRoles as $rid) {
                $db->prepare('INSERT OR IGNORE INTO pub_members (user_id, publication_id, role_id) VALUES (?, ?, ?)')->execute([$tu['id'], $pub['id'], $rid]);
            }
            json_response(['ok' => true, 'added' => count($validRoles)]);
            break;

        case 'pub_member_remove':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $slug = trim($input['slug'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $username = trim($input['username'] ?? '');
            $stmt = $db->prepare('SELECT id FROM users WHERE username=?');
            $stmt->execute([$username]);
            $tu = $stmt->fetch();
            if (!$tu) json_response(['error' => '用户不存在'], 404);
            $db->prepare('DELETE FROM pub_members WHERE user_id=? AND publication_id=?')->execute([$tu['id'], $pub['id']]);
            json_response(['ok' => true]);
            break;

        case 'pub_member_roles':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $slug = trim($input['slug'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $username = trim($input['username'] ?? '');
            $roleIds = $input['role_ids'] ?? [];
            $stmt = $db->prepare('SELECT id FROM users WHERE username=?');
            $stmt->execute([$username]);
            $tu = $stmt->fetch();
            if (!$tu) json_response(['error' => '用户不存在'], 404);
            // 校验 role_ids 确实属于本刊物（防跨刊物越权赋值）
            $validRoles = [];
            $vrStmt = $db->prepare('SELECT id FROM pub_roles WHERE id=? AND publication_id=?');
            foreach ($roleIds as $rid) {
                $vrStmt->execute([(int)$rid, $pub['id']]);
                if ($vrStmt->fetch()) $validRoles[] = (int)$rid;
            }
            $db->prepare('DELETE FROM pub_members WHERE user_id=? AND publication_id=?')->execute([$tu['id'], $pub['id']]);
            foreach ($validRoles as $rid) {
                $db->prepare('INSERT OR IGNORE INTO pub_members (user_id, publication_id, role_id) VALUES (?, ?, ?)')->execute([$tu['id'], $pub['id'], $rid]);
            }
            json_response(['ok' => true, 'roles' => $validRoles]);
            break;

        // ---- 社区加入：QQ 式申请 / Discord 式邀请链接 ----
        case 'community_status':
            $user = require_login($db);
            $slug = trim($_GET['slug'] ?? '');
            $stmt = $db->prepare('SELECT id FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $isMember = $db->prepare('SELECT 1 FROM pub_members WHERE user_id=? AND publication_id=?');
            $isMember->execute([$user['id'], $pub['id']]);
            $pending = $db->prepare("SELECT 1 FROM community_join_requests WHERE publication_id=? AND user_id=? AND status='pending'");
            $pending->execute([$pub['id'], $user['id']]);
            json_response(['member' => (bool)$isMember->fetch(), 'applied' => (bool)$pending->fetch()]);
            break;

        case 'community_apply':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $slug = trim($input['slug'] ?? '');
            $stmt = $db->prepare('SELECT id FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $isMember = $db->prepare('SELECT 1 FROM pub_members WHERE user_id=? AND publication_id=?');
            $isMember->execute([$user['id'], $pub['id']]);
            if ($isMember->fetch()) json_response(['ok' => true, 'already' => true]);
            $pending = $db->prepare("SELECT 1 FROM community_join_requests WHERE publication_id=? AND user_id=? AND status='pending'");
            $pending->execute([$pub['id'], $user['id']]);
            if ($pending->fetch()) json_response(['ok' => true, 'pending' => true]);
            $msg = mb_substr(trim($input['message'] ?? ''), 0, 500);
            $db->prepare('INSERT INTO community_join_requests (publication_id, user_id, message) VALUES (?, ?, ?)')
                ->execute([$pub['id'], $user['id'], $msg]);
            json_response(['ok' => true, 'pending' => true]);
            break;

        case 'community_requests':
            $user = require_login($db);
            $slug = trim($_GET['slug'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $stmt = $db->prepare("
                SELECT r.id, r.status, r.message, r.created_at, r.reviewed_at, u.username, u.uid
                FROM community_join_requests r
                JOIN users u ON u.id = r.user_id
                WHERE r.publication_id = ?
                ORDER BY (r.status='pending') DESC, r.created_at DESC
            ");
            $stmt->execute([$pub['id']]);
            json_response(['requests' => $stmt->fetchAll()]);
            break;

        case 'community_review':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $reqId = (int)($input['request_id'] ?? 0);
            $stmt = $db->prepare('SELECT r.*, p.owner_id FROM community_join_requests r JOIN publications p ON p.id=r.publication_id WHERE r.id=?');
            $stmt->execute([$reqId]);
            $req = $stmt->fetch();
            if (!$req) json_response(['error' => '申请不存在'], 404);
            if ($req['status'] !== 'pending') json_response(['error' => '该申请已处理'], 409);
            $myPerms = pub_perms_of($db, $user['id'], $req['publication_id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $req['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $act = $input['action'] ?? '';
            if ($act === 'approve') {
                $rr = $db->prepare("SELECT id FROM pub_roles WHERE publication_id=? AND name='读者'");
                $rr->execute([$req['publication_id']]);
                $readerRole = $rr->fetchColumn();
                if ($readerRole !== false) {
                    $db->prepare('INSERT OR IGNORE INTO pub_members (user_id, publication_id, role_id) VALUES (?, ?, ?)')
                        ->execute([$req['user_id'], $req['publication_id'], (int)$readerRole]);
                }
                $db->prepare("UPDATE community_join_requests SET status='approved', reviewed_by=?, reviewed_at=datetime('now','+8 hours') WHERE id=?")
                    ->execute([$user['id'], $reqId]);
                json_response(['ok' => true, 'status' => 'approved']);
            } elseif ($act === 'reject') {
                $db->prepare("UPDATE community_join_requests SET status='rejected', reviewed_by=?, reviewed_at=datetime('now','+8 hours') WHERE id=?")
                    ->execute([$user['id'], $reqId]);
                json_response(['ok' => true, 'status' => 'rejected']);
            } else {
                json_response(['error' => '未知操作'], 400);
            }
            break;

        case 'community_invite_create':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $slug = trim($input['slug'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $maxUses = (int)($input['max_uses'] ?? 0);
            $expiresHours = (int)($input['expires_hours'] ?? 0);
            $expiresAt = $expiresHours > 0 ? $db->query("SELECT datetime('now','+8 hours','+{$expiresHours} hours')")->fetchColumn() : null;
            $token = null;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $t = bin2hex(random_bytes(6));
                try {
                    $db->prepare('INSERT INTO community_invites (publication_id, token, created_by, max_uses, expires_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$pub['id'], $t, $user['id'], $maxUses, $expiresAt]);
                    $token = $t;
                    break;
                } catch (Exception $e) { if ($attempt === 4) throw $e; }
            }
            json_response(['ok' => true, 'token' => $token, 'link' => abs_url('/#/invite/' . $token), 'max_uses' => $maxUses, 'expires_at' => $expiresAt]);
            break;

        case 'community_invites':
            $user = require_login($db);
            $slug = trim($_GET['slug'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $pub['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $stmt = $db->prepare("
                SELECT id, token, max_uses, uses, expires_at, created_at,
                       (expires_at IS NULL OR expires_at > datetime('now','+8 hours')) AS not_expired
                FROM community_invites WHERE publication_id=? ORDER BY created_at DESC
            ");
            $stmt->execute([$pub['id']]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['active'] = (bool)$r['not_expired'] && ($r['max_uses'] == 0 || $r['uses'] < $r['max_uses']);
                unset($r['not_expired']);
            }
            json_response(['invites' => $rows]);
            break;

        case 'community_invite_use':
            $user = require_login($db);
            $token = trim($_GET['token'] ?? '');
            $stmt = $db->prepare("SELECT * FROM community_invites WHERE token=? AND (expires_at IS NULL OR expires_at > datetime('now','+8 hours'))");
            $stmt->execute([$token]);
            $inv = $stmt->fetch();
            $pslug = $db->prepare('SELECT slug FROM publications WHERE id=?');
            $pslug->execute([$inv['publication_id']]);
            $slug = $pslug->fetchColumn();
            if (!$inv) {
                $chk = $db->prepare('SELECT 1 FROM community_invites WHERE token=?');
                $chk->execute([$token]);
                if ($chk->fetch()) json_response(['error' => '邀请链接已过期'], 410);
                json_response(['error' => '邀请链接无效'], 404);
            }
            if ($inv['max_uses'] > 0 && $inv['uses'] >= $inv['max_uses']) json_response(['error' => '邀请链接已达到使用上限'], 409);
            $isMember = $db->prepare('SELECT 1 FROM pub_members WHERE user_id=? AND publication_id=?');
            $isMember->execute([$user['id'], $inv['publication_id']]);
            if ($isMember->fetch()) json_response(['ok' => true, 'already' => true, 'publication_id' => $inv['publication_id'], 'slug' => $slug]);
            $rr = $db->prepare("SELECT id FROM pub_roles WHERE publication_id=? AND name='读者'");
            $rr->execute([$inv['publication_id']]);
            $readerRole = $rr->fetchColumn();
            if ($readerRole !== false) {
                $db->prepare('INSERT OR IGNORE INTO pub_members (user_id, publication_id, role_id) VALUES (?, ?, ?)')
                    ->execute([$user['id'], $inv['publication_id'], (int)$readerRole]);
            }
            $db->prepare('UPDATE community_invites SET uses = uses + 1 WHERE id=?')->execute([$inv['id']]);
            json_response(['ok' => true, 'publication_id' => $inv['publication_id'], 'slug' => $slug]);
            break;

        case 'community_invite_revoke':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $invId = (int)($input['invite_id'] ?? 0);
            $stmt = $db->prepare('SELECT i.*, p.owner_id FROM community_invites i JOIN publications p ON p.id=i.publication_id WHERE i.id=?');
            $stmt->execute([$invId]);
            $inv = $stmt->fetch();
            if (!$inv) json_response(['error' => '邀请不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $inv['publication_id']);
            if (!has_perm($myPerms, PERM_MANAGE_MEMBERS) && $inv['owner_id'] != $user['id']) json_response(['error' => '无权限'], 403);
            $db->prepare('DELETE FROM community_invites WHERE id=?')->execute([$invId]);
            json_response(['ok' => true]);
            break;

        // ---- 反馈（意见反馈） ----
        case 'feedback':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $input = get_input();
            $content = trim($input['content'] ?? '');
            if (mb_strlen($content) < 5) json_response(['error' => '反馈内容至少5个字'], 400);
            if (mb_strlen($content) > 2000) json_response(['error' => '反馈内容过长（上限2000字）'], 400);
            $user = null;
            try { $user = current_user($db); } catch (Exception $e) {}
            $contact = trim($input['contact'] ?? '');
            $username = $user ? $user['username'] : '';
            $db->prepare('INSERT INTO feedback (user_id, username, contact, content) VALUES (?, ?, ?, ?)')
                ->execute([$user ? $user['id'] : null, $username, $contact, $content]);
            json_response(['ok' => true]);
            break;

        case 'feedback_list':
            if ($method === 'GET') {
                $user = require_login($db);
                if (!has_perm($user['platform_perms'], PERM_MANAGE_USERS) && !has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM)) json_response(['error' => '无权限'], 403);
                $stmt = $db->query("SELECT * FROM feedback ORDER BY created_at DESC");
                json_response(['feedback' => $stmt->fetchAll()]);
            }
            break;

        case 'feedback_delete':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_USERS) && !has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM)) json_response(['error' => '无权限'], 403);
            $input = get_input();
            $id = (int)($input['id'] ?? 0);
            $db->prepare('DELETE FROM feedback WHERE id=?')->execute([$id]);
            json_response(['ok' => true]);
            break;

        // ---- 刊物内昵称（类 QQ 群昵称） ----
        case 'pub_nickname':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            $input = get_input();
            $slug = trim($input['slug'] ?? '');
            $nickname = trim($input['nickname'] ?? '');
            $stmt = $db->prepare('SELECT * FROM publications WHERE slug=?');
            $stmt->execute([$slug]);
            $pub = $stmt->fetch();
            if (!$pub) json_response(['error' => '刊物不存在'], 404);
            $myPerms = pub_perms_of($db, $user['id'], $pub['id']);
            $isMember = $db->prepare('SELECT 1 FROM pub_members WHERE user_id=? AND publication_id=?');
            $isMember->execute([$user['id'], $pub['id']]);
            $canSet = $isMember->fetch() || $pub['owner_id'] == $user['id'] || has_perm($myPerms, PERM_MANAGE_PUB);
            if (!$canSet) json_response(['error' => '只有该刊物成员可以设置刊物内昵称'], 403);
            if ($nickname === '') {
                $db->prepare('DELETE FROM pub_nicknames WHERE user_id=? AND publication_id=?')->execute([$user['id'], $pub['id']]);
                json_response(['ok' => true, 'nickname' => '']);
            }
            if (mb_strlen($nickname) > 20) json_response(['error' => '昵称不能超过20字'], 400);
            $db->prepare('INSERT OR REPLACE INTO pub_nicknames (user_id, publication_id, nickname) VALUES (?, ?, ?)')
                ->execute([$user['id'], $pub['id'], $nickname]);
            json_response(['ok' => true, 'nickname' => $nickname]);
            break;

        // ---- 蜂巢式联邦架构：中央平台 + 自治刊物节点（单库逻辑模拟） ----
        case 'federation':
            if ($method === 'GET') {
                $core = [];
                $core['users'] = _cnt($db, 'SELECT COUNT(*) FROM users');
                $core['publications'] = _cnt($db, 'SELECT COUNT(*) FROM publications');
                $core['articles'] = _cnt($db, 'SELECT COUNT(*) FROM articles');
                $core['memberships'] = _cnt($db, 'SELECT COUNT(*) FROM pub_members');
                $core['modules'] = _cnt($db, 'SELECT COUNT(*) FROM modules');
                $pubs = $db->query('SELECT id, slug, name, owner_id, avatar, cover FROM publications ORDER BY id ASC')->fetchAll();
                $nodes = [];
                foreach ($pubs as $p) {
                    $pid = (int)$p['id'];
                    $members = _cnt($db, 'SELECT COUNT(*) FROM pub_members WHERE publication_id=?', [$pid]);
                    $channels = _cnt($db, 'SELECT COUNT(*) FROM pub_channels WHERE publication_id=?', [$pid]);
                    $chat = _cnt($db, 'SELECT COUNT(*) FROM pub_chat WHERE publication_id=?', [$pid]);
                    $posts = _cnt($db, 'SELECT COUNT(*) FROM pub_posts WHERE publication_id=?', [$pid]);
                    $roles = _cnt($db, 'SELECT COUNT(*) FROM pub_roles WHERE publication_id=?', [$pid]);
                    $overrides = _cnt($db, 'SELECT COUNT(*) FROM pub_module_settings WHERE publication_id=?', [$pid]);
                    $hasServer = $channels > 0;
                    $score = 0;
                    if ($members > 0) $score += min(25, $members * 5);
                    if ($roles > 0) $score += 20;
                    if ($overrides > 0) $score += 20;
                    if ($hasServer) $score += 20;
                    if (($chat + $posts) > 0) $score += 15;
                    $nodes[] = [
                        'id' => $pid, 'slug' => $p['slug'], 'name' => $p['name'],
                        'avatar' => abs_url($p['avatar']), 'cover' => abs_url($p['cover']), 'owner_id' => (int)$p['owner_id'],
                        'members' => $members, 'channels' => $channels, 'chat' => $chat,
                        'posts' => $posts, 'roles' => $roles, 'module_overrides' => $overrides,
                        'has_server' => $hasServer, 'autonomy' => $score
                    ];
                }
                json_response(['core' => $core, 'nodes' => $nodes]);
            }
            break;

        case 'admin_overview':
            if ($method === 'GET') {
                $user = require_login($db);
                if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN)) json_response(['error' => '无权限'], 403);
                $stats = [];
                $stats['users'] = _cnt($db, 'SELECT COUNT(*) FROM users');
                $stats['verified_users'] = _cnt($db, 'SELECT COUNT(*) FROM users WHERE verified=1');
                $stats['banned_users'] = _cnt($db, 'SELECT COUNT(*) FROM users WHERE banned=1');
                $stats['publications'] = _cnt($db, 'SELECT COUNT(*) FROM publications');
                $stats['articles'] = _cnt($db, 'SELECT COUNT(*) FROM articles');
                $stats['pending'] = _cnt($db, "SELECT COUNT(*) FROM articles WHERE status='pending'");
                $stats['accepted'] = _cnt($db, "SELECT COUNT(*) FROM articles WHERE status='accepted'");
                $stats['published'] = _cnt($db, "SELECT COUNT(*) FROM articles WHERE status='published'");
                $stats['feedback'] = _cnt($db, 'SELECT COUNT(*) FROM feedback');
                $stats['modules_on'] = _cnt($db, 'SELECT COUNT(*) FROM modules WHERE enabled=1');
                $stats['memberships'] = _cnt($db, 'SELECT COUNT(*) FROM pub_members');
                $stats['chat'] = _cnt($db, 'SELECT COUNT(*) FROM pub_chat');
                $stats['posts'] = _cnt($db, 'SELECT COUNT(*) FROM pub_posts');
                $recent_articles = $db->query("SELECT id, title, status, publication_id, created_at FROM articles ORDER BY id DESC LIMIT 5")->fetchAll();
                $recent_pubs = $db->query("SELECT id, name, slug, created_at FROM publications ORDER BY id DESC LIMIT 5")->fetchAll();
                $recent_feedback = $db->query("SELECT id, username, content AS body, created_at FROM feedback ORDER BY id DESC LIMIT 5")->fetchAll();
                json_response(['stats' => $stats, 'recent_articles' => $recent_articles, 'recent_pubs' => $recent_pubs, 'recent_feedback' => $recent_feedback]);
            }
            break;

        case 'admin_cmd':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN)) json_response(['error' => '无权限执行平台指令'], 403);
            $input = get_input();
            $raw = trim($input['cmd'] ?? '');
            if ($raw === '') json_response(['error' => '指令为空'], 400);
            $lines = wankan_admin_cmd($db, $user, $raw);
            json_response(['lines' => $lines]);
            break;

        // ---- 平台操作日志（撤回依据） ----
        case 'admin_log':
            if ($method !== 'GET') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN)) json_response(['error' => '无权限'], 403);
            $n = min(200, max(1, (int)($_GET['n'] ?? 30)));
            $st = $db->prepare('SELECT * FROM admin_log ORDER BY id DESC LIMIT ?');
            $st->execute([$n]);
            json_response(['logs' => $st->fetchAll()]);
            break;

        // ---- 撤回一条平台操作（撤销任何治理行为） ----
        case 'admin_undo':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN)) json_response(['error' => '无权限'], 403);
            $input = get_input();
            $id = (int)($input['id'] ?? 0);
            if (!$id) json_response(['error' => '缺少日志 id'], 400);
            $res = admin_undo($db, $user, $id);
            if (!($res['ok'] ?? false)) json_response(['error' => $res['error'] ?? '撤回失败'], 400);
            json_response(['ok' => true, 'message' => $res['message'] ?? '已撤回']);
            break;

        // ---- 网站加速器开关 / 清空缓存 ----
        case 'admin_accel':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN) && !has_perm($user['platform_perms'], PERM_ROOT)) json_response(['error' => '无权限'], 403);
            $input = get_input();
            $op = $input['op'] ?? '';
            if ($op === 'status') {
                $on = meta_get($db, 'accelerator_on') === '1';
                json_response(['ok' => true, 'on' => $on, 'message' => $on ? '网站加速器已开启' : '网站加速器已关闭']);
            }
            if ($op === 'clear') { cache_clear($db); json_response(['ok' => true, 'message' => '已清空加速器缓存']); }
            $on = !empty($input['on']);
            meta_set($db, 'accelerator_on', $on ? '1' : '0');
            if (!$on) cache_clear($db);
            json_response(['ok' => true, 'on' => $on, 'message' => $on ? '网站加速器已开启' : '网站加速器已关闭']);
            break;

        // ---- 列出「平台管理员」身份组内的所有用户 ----
        case 'admin_list_admins':
            if ($method !== 'GET') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_PLATFORM) && !has_perm($user['platform_perms'], PERM_ADMIN)) json_response(['error' => '无权限'], 403);
            $adminRoleId = $db->query("SELECT id FROM platform_roles WHERE name='平台管理员'")->fetchColumn();
            $map = [];
            if ($adminRoleId) {
                $st = $db->prepare('SELECT u.id, u.username, u.email, u.verified, u.uid, u.avatar, u.created_at FROM users u JOIN user_platform_roles ur ON ur.user_id=u.id WHERE ur.role_id=?');
                $st->execute([$adminRoleId]);
                foreach ($st->fetchAll() as $r) { $r['is_prime'] = false; $map[$r['id']] = $r; }
            }
            // 「平台总构建师」(权限ID=0) 单独并入，标记 is_prime
            $st2 = $db->prepare('SELECT u.id, u.username, u.email, u.verified, u.uid, u.avatar, u.created_at FROM users u JOIN user_platform_roles ur ON ur.user_id=u.id WHERE ur.role_id=0');
            $st2->execute();
            foreach ($st2->fetchAll() as $r) { $r['is_prime'] = true; $map[$r['id']] = $r; }
            $rows = array_values($map);
            usort($rows, function ($a, $b) { return (int)$a['id'] - (int)$b['id']; });
            foreach ($rows as &$r) { $r['avatar'] = abs_url($r['avatar']); }
            json_response(['admins' => $rows]);
            break;

        // ---- 直接使用（切换进）平台管理员身份组内的任一用户 / 任意用户 ----
        case 'admin_impersonate':
            if ($method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $user = require_login($db);
            if (!has_perm($user['platform_perms'], PERM_MANAGE_USERS) && !has_perm($user['platform_perms'], PERM_ADMIN)) json_response(['error' => '无权限'], 403);
            $input = get_input();
            $targetId = (int)($input['user_id'] ?? 0);
            if (!$targetId) json_response(['error' => '缺少目标用户 id'], 400);
            $st = $db->prepare('SELECT id, username, email, bio, verified, avatar, cover, token, created_at, uid FROM users WHERE id=?');
            $st->execute([$targetId]);
            $tu = $st->fetch();
            if (!$tu) json_response(['error' => '目标用户不存在'], 404);
            // 冒充目标若持 PERM_ROOT（平台总构建师），仅限平台总构建师本人
            if (has_perm(platform_perms_of($db, $tu['id']), PERM_ROOT) && !is_prime_admin($user)) json_response(['error' => '无权冒充平台总构建师'], 403);
            // 三份状态强制对齐：Cookie + Session 同步指向目标账号
            set_auth_cookie($tu['token']);
            $_SESSION['user_id'] = (int)$tu['id'];
            attach_platform($db, $tu);
            $tu['avatar'] = abs_url($tu['avatar']);
            $tu['cover'] = abs_url($tu['cover']);
            log_admin_action($db, $user['id'], 'impersonate', 'user', $tu['id'], "以管理员身份切换进入用户 {$tu['username']}", null);
            json_response(['ok' => true, 'user' => $tu, 'token' => $tu['token']]);
            break;

        default:
            json_response(['error' => '未知操作: ' . $action], 400);
    }
} catch (Exception $e) {
    error_log('WK API error: ' . $e->getMessage());
    // 临时调试：泄露真实异常信息（定位后改回通用提示）
    json_response(['error' => '服务器错误：' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
}
