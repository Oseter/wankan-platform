#!/usr/bin/env python3
"""万刊网 部署脚本（开源版，不含任何硬编码密码）。

从环境变量读取 FTP 凭据，将站点文件上传到主机的 Web 根目录。
你的私有凭据脚本请放在 _ftp_deploy2.py（已被 .gitignore 排除）。

用法：
    export FTP_HOST="ftpupload.net"
    export FTP_USER="your_ftp_user"
    export FTP_PASS="your_ftp_pass"
    export FTP_DIR="/htdocs"     # 可选，默认 /htdocs
    python deploy.py
"""
import ftplib
import time
import sys
import os
import bump_version

HOST = os.environ.get("FTP_HOST", "ftpupload.net")
USER = os.environ.get("FTP_USER", "")
PASS = os.environ.get("FTP_PASS", "")
REMOTE_BASE = os.environ.get("FTP_DIR", "/htdocs").rstrip("/")

# 需要上传的文件（相对于本脚本所在目录）
FILES = [
    "api.php",
    "index.html",
    "sw.js",
    "css/style.css",
    "js/app.js",
    "js/md.js",
]

# 上传站点收款码（如果存在，git 仓库中忽略，仅走 FTP）
if os.path.exists("assets/wxqr.png"):
    FILES.append("assets/wxqr.png")

SOCK_TIMEOUT = 120
RETRY = 4


def upload_one(f):
    """对单个文件做「连接→登录→上传→退出」，失败抛错由外层重试。"""
    if not USER or not PASS:
        raise RuntimeError("请先设置环境变量 FTP_USER 与 FTP_PASS")
    ftp = ftplib.FTP()
    ftp.connect(HOST, 21, 30)
    ftp.login(USER, PASS)
    ftp.sock.settimeout(SOCK_TIMEOUT)
    try:
        parts = f.split("/")
        if len(parts) > 1:
            target = REMOTE_BASE + "/" + parts[0]
            try:
                ftp.cwd(target)
            except ftplib.error_perm:
                ftp.mkd(target)
                ftp.cwd(target)
            ftp.storbinary("STOR " + parts[-1], open(f, "rb"))
            ftp.cwd(REMOTE_BASE)
        else:
            ftp.cwd(REMOTE_BASE)
            ftp.storbinary("STOR " + parts[-1], open(f, "rb"))
    finally:
        try:
            ftp.quit()
        except Exception:
            pass


def main():
    # 发布前自动刷新版本号（index.html 的 ?v= 与 sw.js 的 CACHE 同步）
    cur, nxt = bump_version.bump()
    print(f"version: {cur} -> {nxt}")
    ok = 0
    for f in FILES:
        done = False
        for attempt in range(1, RETRY + 1):
            try:
                upload_one(f)
                print(f"uploaded {f}  (try {attempt})")
                done = True
                ok += 1
                break
            except Exception as e:
                print(f"RETRY {f} attempt {attempt}: {type(e).__name__}: {e}")
                time.sleep(2 * attempt)
        if not done:
            print(f"FAILED {f} after {RETRY} tries")
            sys.exit(2)
    print(f"DONE ({ok}/{len(FILES)})")


if __name__ == "__main__":
    main()
