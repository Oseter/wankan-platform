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
