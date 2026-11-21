#!/usr/bin/env python3
"""Sinkronkan TASKS.md dan PROGRESS.md dengan status issue & milestone di GitHub.

Pemakaian:  python3 scripts/sync-progress.py
Butuh:      gh CLI yang sudah login (gh auth login)

Hanya bagian di antara marker <!-- auto:... --> yang ditulis ulang.
Bagian lain (log mingguan, catatan) aman diedit manual.
"""
import json
import re
import subprocess
from datetime import date
from pathlib import Path

REPO = "rikiafilafwork/household-finance"
ROOT = Path(__file__).resolve().parent.parent
TASKS = ROOT / "TASKS.md"
PROGRESS = ROOT / "PROGRESS.md"


def gh_json(*args):
    out = subprocess.run(["gh", *args], check=True, capture_output=True, text=True).stdout
    return json.loads(out)


def bar(done, total, width=20):
    filled = round(width * done / total) if total else 0
    return "█" * filled + "░" * (width - filled)


def week_no(title):
    m = re.search(r"Minggu (\d+)", title)
    return int(m.group(1)) if m else 99


def replace_block(text, name, content):
    start, end = f"<!-- auto:{name}:start -->", f"<!-- auto:{name}:end -->"
    pattern = re.compile(re.escape(start) + r".*?" + re.escape(end), re.S)
    block = f"{start}\n{content.rstrip()}\n{end}"
    if pattern.search(text):
        return pattern.sub(lambda _: block, text)
    return text.rstrip() + "\n\n" + block + "\n"


def main():
    milestones = gh_json("api", f"repos/{REPO}/milestones?state=all&per_page=100")
    milestones.sort(key=lambda m: week_no(m["title"]))
    issues = gh_json("issue", "list", "--repo", REPO, "--state", "all", "--limit", "500",
                     "--json", "number,title,state,milestone,labels")
    issues.sort(key=lambda i: i["number"])

    by_ms = {}
    for i in issues:
        key = i["milestone"]["title"] if i["milestone"] else "Tanpa milestone"
        by_ms.setdefault(key, []).append(i)

    today = date.today()
    total = len(issues)
    closed = sum(i["state"] == "CLOSED" for i in issues)

    # ---------- TASKS.md ----------
    lines = []
    for m in milestones:
        items = by_ms.get(m["title"], [])
        done = sum(i["state"] == "CLOSED" for i in items)
        lines.append(f"## {m['title']} ({done}/{len(items)})\n")
        desc = m.get("description") or ""
        if "Done kalau:" in desc:
            lines.append(f"> **Done kalau:** {desc.split('Done kalau:', 1)[1].strip()}\n")
        for i in items:
            mark = "x" if i["state"] == "CLOSED" else " "
            labels = " ".join(f"`{l['name']}`" for l in i["labels"])
            lines.append(f"- [{mark}] #{i['number']} {i['title']} {labels}".rstrip())
        lines.append("")
    if "Tanpa milestone" in by_ms:
        lines.append("## Tanpa milestone\n")
        for i in by_ms["Tanpa milestone"]:
            mark = "x" if i["state"] == "CLOSED" else " "
            lines.append(f"- [{mark}] #{i['number']} {i['title']}")
        lines.append("")
    TASKS.write_text(replace_block(TASKS.read_text(), "tasks", "\n".join(lines)))

    # ---------- PROGRESS.md ----------
    rows = [f"**Total: {closed}/{total} issue selesai ({round(100 * closed / total) if total else 0}%)** "
            f"`{bar(closed, total, 30)}`\n",
            f"_Terakhir disinkronkan: {today.isoformat()}_\n",
            "| Minggu | Target selesai | Progres | % | Status |",
            "| --- | --- | --- | --- | --- |"]
    for m in milestones:
        items = by_ms.get(m["title"], [])
        done = sum(i["state"] == "CLOSED" for i in items)
        n = len(items)
        due = date.fromisoformat(m["due_on"][:10]) if m.get("due_on") else None
        if n and done == n:
            status = "✅ Selesai"
        elif due and today > due:
            status = "🔴 Terlambat"
        elif done:
            status = "🟡 Berjalan"
        else:
            status = "⚪ Belum mulai"
        pct = round(100 * done / n) if n else 0
        rows.append(f"| [{m['title']}]({m['html_url']}) | {due or '-'} | `{bar(done, n, 12)}` {done}/{n} "
                    f"| {pct}% | {status} |")
    PROGRESS.write_text(replace_block(PROGRESS.read_text(), "progress", "\n".join(rows)))
    print(f"Sinkron: {closed}/{total} issue selesai.")


if __name__ == "__main__":
    main()
