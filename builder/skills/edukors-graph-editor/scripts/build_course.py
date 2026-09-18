#!/usr/bin/env python3
"""Assemble an exploded Edukors course folder into _output/.

    python3 build_course.py "<Course Title>"

Reads info/, nodes/ and edges/, writes _output/<slug>-course.json, then runs
the edukors-graph-builder validator and viewer/player builders on it.
"""
import argparse
import subprocess
import sys
from pathlib import Path

import content_md
from _layout import (DOC_BODY, LOCALIZED_BODY, OUTPUT, PLAIN_BODY, SCHEMA, SOLO_BODY,
                     course_slug, languages, node_dirs, read_body, read_json,
                     reading_order, solo_body_files, type_for_id, write_json)

BUILDER_CANDIDATES = [
    Path(__file__).resolve().parents[2] / "edukors-graph-builder" / "scripts",
    Path(__file__).resolve().parents[3] / "skill" / "edukors-graph-builder" / "scripts",
]


def find_builder():
    for candidate in BUILDER_CANDIDATES:
        if (candidate / "validate_course.py").is_file():
            return candidate
    synced = Path.home() / ".claude" / "skills" / "synced"
    for candidate in sorted(synced.glob("*/edukors-graph-builder/scripts")):
        if (candidate / "validate_course.py").is_file():
            return candidate
    return None


def collect_localized(node_dir, node_id, field, filename, langs, errors):
    """Read content/<lang>/<filename> for every language, declared order first."""
    content_dir = node_dir / "content"
    present = [d.name for d in content_dir.iterdir() if d.is_dir()] if content_dir.is_dir() else []
    ordered = [lang for lang in langs if lang in present]
    ordered += sorted(lang for lang in present if lang not in langs)
    for lang in present:
        if lang not in langs:
            errors.append("%s: content/%s/ is not a language of this course"
                          % (node_id, lang))
    if not ordered:
        errors.append("%s: no content/<lang>/%s found" % (node_id, filename))
    else:
        for lang in langs:
            if lang not in ordered:
                errors.append("%s: missing content/%s/%s" % (node_id, lang, filename))

    entries = []
    for lang in ordered:
        path = content_dir / lang / filename
        if not path.is_file():
            errors.append("%s: missing %s" % (node_id, path.relative_to(node_dir.parent.parent)))
            continue
        entries.append({"lang": lang, "text": read_body(path)})
    return entries


def collect_document(node_dir, node_id, node_type, langs, errors):
    """Read content/<lang>/<document> of every language into the content of a node.

    The file of the first language carries the structure; the others must agree
    with it item by item, so that translating can never reshape an activity.
    """
    document = content_md.DOCUMENTS[node_type]
    filename, item = document["file"], document["item"]
    per_lang = {}
    for lang in langs:
        path = node_dir / "content" / lang / filename
        if not path.is_file():
            errors.append("%s: missing content/%s/%s" % (node_id, lang, filename))
            continue
        records, problems = document["parse"](read_body(path))
        for problem in problems:
            errors.append("%s/content/%s/%s, %s" % (node_id, lang, filename, problem))
        per_lang[lang] = records
    content_dir = node_dir / "content"
    for extra in sorted(d.name for d in content_dir.iterdir() if d.is_dir()) \
            if content_dir.is_dir() else []:
        if extra not in langs:
            errors.append("%s: content/%s/ is not a language of this course" % (node_id, extra))
    if len(per_lang) != len(langs):
        return None

    source = langs[0]
    reference = per_lang[source]
    if not reference:
        errors.append("%s: content/%s/%s has no %s" % (node_id, source, filename, item))
        return None
    for lang in langs[1:]:
        if len(per_lang[lang]) != len(reference):
            errors.append("%s: content/%s/%s has %d %s(s), %s has %d"
                          % (node_id, lang, filename, len(per_lang[lang]), item,
                             source, len(reference)))
            return None
        for position, (theirs, ours) in enumerate(zip(per_lang[lang], reference), 1):
            if document["shape"](theirs) != document["shape"](ours):
                errors.append("%s: %s %d of content/%s/%s does not match %s — %s are the "
                              "structure, and translating never changes them"
                              % (node_id, item, position, lang, filename, source,
                                 document["structure"]))
                return None

    return document["assemble"](per_lang, langs)


def assemble(course_dir, errors):
    info = read_json(course_dir / "info" / "info.json")
    if info.pop("$schema", None) is not None:
        print("warning: info.json declares $schema; delete that line — the schema "
              "belongs only to the generated JSON", file=sys.stderr)
    langs = languages(info)

    nodes = {}
    for node_dir in node_dirs(course_dir):
        node_id = node_dir.name
        node_file = node_dir / "node.json"
        if not node_file.is_file():
            errors.append("%s: no node.json" % node_id)
            continue
        node = read_json(node_file)
        if node.get("id") != node_id:
            errors.append("%s: node.json declares id '%s'" % (node_id, node.get("id")))
            node["id"] = node_id
        node_type = node.get("type")
        if type_for_id(node_id) != node_type:
            errors.append("%s: id prefix does not match type '%s'" % (node_id, node_type))
        content = dict(node.pop("content", None) or {})

        field, filename = LOCALIZED_BODY.get(node_type, (None, None))
        if field:
            if field in content:
                errors.append("%s: node.json still carries content.%s; it belongs in content/"
                              % (node_id, field))
            content[field] = collect_localized(node_dir, node_id, field, filename,
                                               langs, errors)

        if node_type in DOC_BODY:
            if content:
                errors.append("%s: node.json still carries content; it belongs in content/"
                              % node_id)
            content = collect_document(node_dir, node_id, node_type, langs, errors) or {}

        field, filename = SOLO_BODY.get(node_type, (None, None))
        if field:
            if field in content:
                errors.append("%s: node.json still carries content.%s; it belongs in content/"
                              % (node_id, field))
            found = solo_body_files(node_dir, filename)
            if not found:
                errors.append("%s: missing content/%s" % (node_id, filename))
            elif len(found) > 1:
                errors.append("%s: content/ holds %s; a %s prompt is one text, so it is "
                              "one file" % (node_id, ", ".join(p.name for p, _ in found),
                                            node_type))
            else:
                path, lang = found[0]
                content[field] = [{"lang": lang, "text": read_body(path)}]

        field, filename = PLAIN_BODY.get(node_type, (None, None))
        if field:
            path = node_dir / "content" / filename
            if path.is_file():
                content[field] = read_body(path)
            else:
                errors.append("%s: missing content/%s" % (node_id, filename))

        if content:
            node["content"] = content
        nodes[node_id] = node

    edges_by_source = {}
    edges_dir = course_dir / "edges"
    for edge_file in sorted(edges_dir.glob("*.json")) if edges_dir.is_dir() else []:
        source = edge_file.stem
        edges = read_json(edge_file)
        if source not in nodes:
            errors.append("edges/%s.json: no node folder '%s'" % (source, source))
        for edge in edges:
            if edge.get("from") != source:
                errors.append("edges/%s.json: an edge has from '%s'" % (source, edge.get("from")))
            if edge.get("to") not in nodes:
                errors.append("edges/%s.json: edge to unknown node '%s'" % (source, edge.get("to")))
        fallback = [i for i, edge in enumerate(edges) if "when" not in edge]
        if fallback and fallback[0] != len(edges) - 1:
            errors.append("edges/%s.json: the unconditional edge is not last; "
                          "edges after it are dead" % source)
        edges_by_source[source] = edges

    start = info.get("start")
    if start not in nodes:
        errors.append("info.start '%s' is not a node folder" % start)

    order = reading_order(nodes.keys(), edges_by_source, start)
    reachable = set()
    queue = [start] if start in nodes else []
    while queue:
        current = queue.pop()
        if current in reachable:
            continue
        reachable.add(current)
        queue.extend(edge["to"] for edge in edges_by_source.get(current, []) if edge.get("to") in nodes)
    for node_id in order:
        if node_id not in reachable:
            print("warning: %s is unreachable from %s" % (node_id, start), file=sys.stderr)

    course = {
        "$schema": SCHEMA,
        "info": info,
        "nodes": [nodes[node_id] for node_id in order],
        "edges": [edge for node_id in order for edge in edges_by_source.get(node_id, [])],
    }
    return course


def run(command):
    print("$ %s" % " ".join(str(part) for part in command))
    return subprocess.run(command).returncode


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("course_dir", help="the course folder")
    parser.add_argument("--json-only", action="store_true",
                        help="stop after the JSON, skipping validation, map and player")
    parser.add_argument("--builder-dir", help="scripts/ of the edukors-graph-builder skill")
    args = parser.parse_args()

    course_dir = Path(args.course_dir)
    errors = []
    course = assemble(course_dir, errors)
    for error in errors:
        print("error: %s" % error, file=sys.stderr)
    if errors:
        return 1

    slug = course_slug(course_dir)
    course_json = course_dir / OUTPUT / ("%s-course.json" % slug)
    write_json(course_json, course)
    print("built %s (%d nodes, %d edges)"
          % (course_json, len(course["nodes"]), len(course["edges"])))
    if args.json_only:
        return 0

    builder = Path(args.builder_dir) if args.builder_dir else find_builder()
    if builder is None:
        print("error: edukors-graph-builder scripts not found; pass --builder-dir",
              file=sys.stderr)
        return 1

    status = run([sys.executable, str(builder / "validate_course.py"), str(course_json)])
    if status != 0:
        return status
    for script, suffix in (("build_viewer.py", "map"),
                           ("build_player.py", "player")):
        out = course_dir / OUTPUT / ("%s-%s.html" % (slug, suffix))
        out.parent.mkdir(parents=True, exist_ok=True)
        status = run([sys.executable, str(builder / script), str(course_json), "-o", str(out)])
        if status != 0:
            return status
    return 0


if __name__ == "__main__":
    sys.exit(main())
