# 万刊网 (Wankan)

一个自托管的「万刊」发布平台 —— 一个人也能办出无数本杂志 / 刊物 / 社区。

单文件 PHP 后端 + 原生 JS 单页前端（SPA）+ Service Worker 离线缓存，零依赖、可跑在任意支持 PHP + SQLite 的空间（如 InfinityFree）。

> 在线演示：https://wankan.kesug.com

## 特性

- **多刊物 / 杂志**：每本刊物有独立身份组、成员、社区与投稿流。
- **两级身份组权限**：平台级（总构建师 → 管理员 → 版主 → 认证/普通用户 → 新人）+ 刊物级（读者 / 编辑 / 审稿等），基于位掩码权限。
- **订阅 & 读者组**：订阅即自动加入该刊「读者」身份组；发刊时通知全部订阅者。
- **社区**：刊物内社区支持 QQ 式申请审核 + Discord 式邀请链接（可设开放 / 次数 / 有效期）。
- **私信 & 关注**：私信前需互相关注；支持用户列表选择器。
- **投稿 / 审稿流**：普通用户投稿走待审，认证用户免审核直接上刊；投稿时通知刊物主与审稿组。
- **运维终端**：平台总构建师专属，受控命令式控制台（sql / ls / cat / write / rm / backup / announce / notify 等）。
- **PWA 离线缓存 + 响应式**：移动端与 PC 端兼容，输入框 / 头像等 UI 已美化。

## 目录结构

```
.
├── index.html        # SPA 入口
├── api.php           # 后端（单文件，PDO + SQLite）
├── sw.js            # Service Worker（离线缓存）
├── css/style.css    # 暗色主题样式
└── js/
    ├── app.js       # 前端主逻辑（路由 / 渲染 / 交互）
    └── md.js        # Markdown 渲染
```

后端首次访问会自动建库、建表并写入平台身份组种子数据。

## 部署

### 方式一：直接上传

把以下文件上传到主机的 Web 根目录（InfinityFree 为 `/htdocs`），然后访问 `index.html`：

```
api.php  index.html  sw.js  css/style.css  js/app.js  js/md.js
```

确保 PHP 开启 `PDO_SQLite` 扩展，且 Web 目录可写（SQLite 数据库文件在其中生成）。

### 方式二：用 deploy.py 脚本

`deploy.py` 从环境变量读取 FTP 凭据，**不硬编码任何密码**：

```bash
export FTP_HOST="ftpupload.net"
export FTP_USER="your_ftp_user"
export FTP_PASS="your_ftp_pass"
export FTP_DIR="/htdocs"        # 可选，默认 /htdocs

python deploy.py
```

> 你自己的私有部署脚本（含真实密码）请放在 `_ftp_deploy2.py`，它已被 `.gitignore` 排除，不会进入仓库。

## 默认账号

首次启动后，平台身份组 `id=0`「平台总构建师」拥有最高控制权。
通过运维终端（需总构建师权限）可执行 `sql` 等命令维护数据与账号。

## 开源协议

[MIT](./LICENSE) © 2026 redstarstorm

## 版本号与缓存刷新（部署必看）

站点用 `index.html` 里资源 URL 的 `?v=xxxx` 做缓存失效（cache busting），同时 `sw.js` 用 `const CACHE = 'wankan-accel-xxxx'` 命名自己的缓存空间。两者**必须同步刷新**，否则 Service Worker 会一直喂旧的 `index.html`，导致「改了代码但线上没变化」。

项目提供**单一真源 + 自动刷新**的方法，杜绝上述事故：

- `VERSION` 文件：版本号的唯一真源（格式 `YYYYMMDD` + 一个小写字母，如 `20260716a`）。
- `bump_version.py`：推算下一版本，同步改写 `index.html` 的 `?v=` 与 `sw.js` 的 `CACHE` 常量，并写回 `VERSION`。
  - 同一天再发版：字母顺延（`a→b→…→z→aa`）。
  - 跨天：重置为当天日期 + `a`。
- **部署脚本会自动 bump**：`deploy.py` 与 `_ftp_deploy2.py` 在上传前都会调用 `bump_version.bump()`，并把 `sw.js` 纳入上传清单，确保 SW 缓存版本跟着升、旧 `index.html` 被清掉。

手动使用：

```bash
python bump_version.py     # 输出 旧 -> 新，并改写 index.html 与 sw.js
```

> 不要手工改 `?v=`。直接跑部署脚本即可，版本号会自己 +1 并自动刷新两端缓存。

> **两类版本号必须区分，切勿混用：**
> - **内部缓存版本号**（`VERSION` 文件 / `index.html` 的 `?v=` / `sw.js` 的 `CACHE`）：格式 `YYYYMMDD+小写字母`（如 `20260717c`），由 `bump_version` 自动维护，仅用于浏览器缓存失效，**访客不可见**。
> - **对外展示版本号**（页脚「版本：」文字）：格式为语义版本 `X.X.X`（如 `1.1.5`），由人工维护，**绝不可**用日期戳填充。改文案/功能后可手动把 `X.X.X` 升一档，但它与内部缓存号互不影响。

## 自愿赞助（仿 B 站充电）

万刊网完全免费、不追踪用户。如果你觉得它有用，欢迎**自愿**支持：

- **导航栏「💖 赞助」按钮**（登录/未登录都可见）→ 打开赞助弹窗，含三栏：
  1. **微信赞赏**：扫微信收款码（运营者把收款码命名为 `assets/wechat-qr.png` 上传到站点根目录即可显示）。
  2. **GitHub Sponsors**：链接到 `https://github.com/sponsors/Oseter`，可每月/一次性赞助，并点亮仓库的 ⭐ Sponsor。
  3. **广告 / 商务合作**：本站接受广告与商务合作，广告位招租中。
- **仓库 Sponsor 按钮**：`.github/FUNDING.yml` 已配置，仓库会显示 ⭐ Sponsor。
- **顶部广告条**：`index.html` 的 `.top-ad` 是一处可投放广告代码的广告位（点击 × 可关闭，记忆在 `localStorage`），运营者把广告代码贴进去即可。

