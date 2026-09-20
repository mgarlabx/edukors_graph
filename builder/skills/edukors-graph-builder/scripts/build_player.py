#!/usr/bin/env python3
"""Embed a course JSON into a standalone copy of the Edukors course player.

The result is a single HTML file that opens on the course itself: the welcome
screen, then one step at a time, with the graph deciding what comes next. No
server, no upload step. The player serves that one course — it has no way to open
another, so rebuild it whenever the JSON changes.

Steps written by the AI (dynamic-md, dynamic-html) and the grading of essay
nodes ask the model of the host the file runs in: the `sample` capability of an
Artifact that declares it (e.g. published from Claude Code), or the AI bridge of
a claude.ai chat artifact. Opened anywhere else, those steps show a note saying
so; every other node type works offline.

The nodes the AI decides with (choice, score, noul) never ask a model here. They
are invisible to a student, so the file this script writes marks itself as the
author's copy and shows a panel instead: the question as written, the options as
declared, and the author picks. That is what lets every branch of an adaptive
course be walked by opening one file, with no server and no key.

Usage:
    python3 build_player.py course.json
    python3 build_player.py course.json -o my-course-player.html
    python3 build_player.py course.json --player /path/to/course_player.html

By default the template is ../assets/course_player.html, next to this script.
No third-party dependencies.
"""

import argparse
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_PLAYER = os.path.join(HERE, "..", "assets", "course_player.html")
BOOT_OPEN = '<script type="application/json" id="edukors-player-boot">'
BOOT_CLOSE = "</script>"


def boot_json(course):
    """The course, safe to sit inside a <script type="application/json"> block.

    `preview` is what tells the player this copy belongs to the author. Only this
    script writes it: the server's own builds leave it out, so a student never
    gets the author's panel on a choice, score or noul node. Those nodes decide
    where the course goes, and on the author's machine there is no model to ask,
    so the panel asks the author instead -- which is also the only way to walk
    every branch of an adaptive course without a server.
    """
    text = json.dumps({"course": course, "preview": True}, ensure_ascii=False)
    # only '</script' could close the block early; \u003c is the same character
    return text.replace("<", "\\u003c")


def title_of(course):
    info = course.get("info", {}) or {}
    source = info.get("source-language")
    entries = info.get("title") or []
    if isinstance(entries, list):
        for entry in entries:
            if isinstance(entry, dict) and entry.get("lang") == source:
                return entry.get("text") or ""
        for entry in entries:
            if isinstance(entry, dict) and entry.get("text"):
                return entry["text"]
    return "Course"


def escape_html(text):
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def main():
    parser = argparse.ArgumentParser(description="Build a standalone course player from a course JSON.")
    parser.add_argument("course", help="path to the course JSON")
    parser.add_argument("-o", "--output", help="output HTML path (default: <course>-player.html)")
    parser.add_argument("--player", default=DEFAULT_PLAYER, help="player template to use")
    args = parser.parse_args()

    try:
        with open(args.course, encoding="utf-8") as handle:
            course = json.load(handle)
    except FileNotFoundError:
        print(f"ERROR: file not found: {args.course}", file=sys.stderr)
        return 1
    except json.JSONDecodeError as exc:
        print(f"ERROR: invalid JSON at line {exc.lineno}, column {exc.colno}: {exc.msg}", file=sys.stderr)
        return 1

    if not isinstance(course, dict) or "info" not in course or "nodes" not in course:
        print("ERROR: this file has no 'info' and 'nodes' — it is not a course.", file=sys.stderr)
        return 1

    try:
        with open(args.player, encoding="utf-8") as handle:
            html = handle.read()
    except FileNotFoundError:
        print(f"ERROR: player template not found: {args.player}", file=sys.stderr)
        return 1

    start = html.find(BOOT_OPEN)
    end = html.find(BOOT_CLOSE, start)
    if start == -1 or end == -1:
        print("ERROR: the template does not look like the course player.", file=sys.stderr)
        return 1

    # the template carries a placeholder course: replace it, so the player holds
    # only the real one
    html = html[: start + len(BOOT_OPEN)] + boot_json(course) + html[end:]

    title = title_of(course)
    if "<title>" in html:
        head, rest = html.split("<title>", 1)
        _, tail = rest.split("</title>", 1)
        html = f"{head}<title>{escape_html(title)}</title>{tail}"

    language = (course.get("info", {}) or {}).get("source-language")
    if language and '<html lang="' in html:
        head, rest = html.split('<html lang="', 1)
        _, tail = rest.split('"', 1)
        html = f'{head}<html lang="{escape_html(language)}"{tail}'

    output = args.output or os.path.splitext(args.course)[0] + "-player.html"
    with open(output, "w", encoding="utf-8") as handle:
        handle.write(html)

    nodes = len(course.get("nodes") or [])
    edges = len(course.get("edges") or [])
    print(f"Wrote {output} — {title}: {nodes} nodes, {edges} edges.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
