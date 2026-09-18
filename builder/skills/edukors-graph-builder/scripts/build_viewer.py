#!/usr/bin/env python3
"""Embed a course JSON into a standalone copy of the Edukors course viewer.

The result is a single HTML file that opens with the graph already drawn: no
server, no upload step. The viewer serves that one course — it has no way to open
another, so rebuild the map whenever the JSON changes.

Usage:
    python3 build_viewer.py course.json
    python3 build_viewer.py course.json -o my-course-map.html
    python3 build_viewer.py course.json --viewer /path/to/course_viewer.html

By default the template is ../assets/course_viewer.html, next to this script.
No third-party dependencies.
"""

import argparse
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_VIEWER = os.path.join(HERE, "..", "assets", "course_viewer.html")


def js_literal(data):
    """JSON safe to paste inside a <script> block."""
    text = json.dumps(data, ensure_ascii=False)
    # '</script' inside a string would close the block; '<\/' is the same string in JS.
    text = text.replace("</", "<\\/")
    # these are line terminators in JS but legal inside JSON strings
    return text.replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")


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
    return "Course map"


def escape_html(text):
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def main():
    parser = argparse.ArgumentParser(description="Build a standalone course map from a course JSON.")
    parser.add_argument("course", help="path to the course JSON")
    parser.add_argument("-o", "--output", help="output HTML path (default: <course>-map.html)")
    parser.add_argument("--viewer", default=DEFAULT_VIEWER, help="viewer template to use")
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
        with open(args.viewer, encoding="utf-8") as handle:
            html = handle.read()
    except FileNotFoundError:
        print(f"ERROR: viewer template not found: {args.viewer}", file=sys.stderr)
        return 1

    if "</body>" not in html or "function render(" not in html:
        print("ERROR: the template does not look like the course viewer.", file=sys.stderr)
        return 1

    title = title_of(course)
    if "<title>" in html:
        head, rest = html.split("<title>", 1)
        _, tail = rest.split("</title>", 1)
        html = f"{head}<title>{escape_html(title)}</title>{tail}"

    block = (
        "const COURSE = " + js_literal(course) + ";\n"
        "render(COURSE);"
    )

    start = html.find("const DEMO = {")
    end = html.find("render(DEMO);")
    if start != -1 and end > start:
        # the template carries a placeholder course: replace it, so the map holds
        # only the real one
        html = html[:start] + block + html[end + len("render(DEMO);"):]
    else:
        head, sep, tail = html.rpartition("</body>")
        html = head + "<script>\n" + block + "\n</script>\n" + sep + tail

    output = args.output or os.path.splitext(args.course)[0] + "-map.html"
    with open(output, "w", encoding="utf-8") as handle:
        handle.write(html)

    nodes = len(course.get("nodes") or [])
    edges = len(course.get("edges") or [])
    print(f"Wrote {output} — {title}: {nodes} nodes, {edges} edges.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
