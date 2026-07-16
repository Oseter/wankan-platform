#!/usr/bin/env python3
"""万刊网 · 版本号变更方法（单一真源）。

为什么需要它：
站点用 index.html 里资源 URL 的 `?v=xxxx` 做缓存失效（cache busting），
同时 sw.js 用 `const CACHE = 'wankan-accel-xxxx'` 命名自己的缓存空间。
两者必须同步刷新，否则 Service Worker 会一直喂旧的 index.html，
导致「改了代码但线上没变化」（本项目早期踩过的坑）。

用法：
    python bump_version.py            # 输出 旧 -> 新，并改写 index.html 与 sw.js
部署脚本 deploy.py / _ftp_deploy2.py 会在上传前自动调用本模块，无需手动跑。

版本格式：YYYYMMDD + 一个小写字母（如 20260716a）。
- 同一天再次发布：字母顺延（a->b->...->z->aa）。
- 跨天：重置为当天日期 + 'a'。
"""
import re
import os
import datetime

HERE = os.path.dirname(os.path.abspath(__file__))
VERSION_FILE = os.path.join(HERE, "VERSION")
INDEX = os.path.join(HERE, "index.html")
SW = os.path.join(HERE, "sw.js")


def read_version():
    if os.path.exists(VERSION_FILE):
        v = open(VERSION_FILE, encoding="utf-8").read().strip()
        if re.match(r"^\d{8}[a-z]+$", v):
            return v
    # 兜底：从 index.html 解析
    html = open(INDEX, encoding="utf-8").read()
    m = re.search(r"[?]v=([0-9a-z]+)", html)
    return m.group(1) if m else "20260101a"


def next_version(cur):
    today = datetime.date.today().strftime("%Y%m%d")
    m = re.match(r"^(\d{8})([a-z]+)$", cur)
    if m and m.group(1) == today:
        letters = m.group(2)
        if letters == "z":
            return today + "aa"
        # 顺延一个字母
        return today + chr(ord(letters[-1]) + 1)
    return today + "a"


def bump():
    cur = read_version()
    nxt = next_version(cur)
    if cur == nxt:
        return cur, nxt
    # 1) index.html：所有 ?v= 统一改写
    html = open(INDEX, encoding="utf-8").read()
    html2 = re.sub(r"([?]v=)[0-9a-z]+", r"\g<1>" + nxt, html)
    open(INDEX, "w", encoding="utf-8").write(html2)
    # 2) sw.js：CACHE 常量统一改写
    sw = open(SW, encoding="utf-8").read()
    sw2 = re.sub(r"(const CACHE = 'wankan-accel-)[^']*(';)", r"\g<1>" + nxt + r"\g<2>", sw)
    open(SW, "w", encoding="utf-8").write(sw2)
    # 3) 写回 VERSION 单一真源
    open(VERSION_FILE, "w", encoding="utf-8").write(nxt)
    return cur, nxt


if __name__ == "__main__":
    cur, nxt = bump()
    print(f"version: {cur} -> {nxt}")
