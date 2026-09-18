#!/usr/bin/env python3
"""Split a single Edukors course JSON into the exploded course folder.

    python3 split_course.py <course>.json -o "<Course Title>"

Writes info/, nodes/<id>/, edges/<from>.json and copies the source file into
_output/. Rebuild with build_course.py; the round trip is exact.
"""
import argparse
import shutil
import sys
from pathlib import Path

import content_md
from _layout import (DOC_BODY, LOCALIZED_BODY, OUTPUT, PLAIN_BODY, SCHEMA, SOLO_BODY,
                     course_slug, languages, read_json, solo_body_path, type_for_id,
                     write_body, write_json)


def split(course, out_dir, force):
    info = course["info"]
    langs = languages(info)
    errors = []

    for sub in ("info", "nodes", "edges", OUTPUT):
        (out_dir / sub).mkdir(parents=True, exist_ok=True)

    existing = [d for d in (out_dir / "nodes").iterdir() if d.is_dir()]
    if existing and not force:
        return ["%s/nodes is not empty; pass --force to overwrite it" % out_dir]
    for stale in existing:
        shutil.rmtree(stale)
    for stale in (out_dir / "edges").glob("*.json"):
        stale.unlink()

    declared = course.get("$schema")
    if declared and declared != SCHEMA:
        print("warning: source declares $schema %s; the build writes %s"
              % (declared, SCHEMA), file=sys.stderr)
    write_json(out_dir / "info" / "info.json", info)

    for node in course["nodes"]:
        node_id = node["id"]
        node_type = node["type"]
        if type_for_id(node_id) != node_type:
            errors.append("%s: id prefix does not match type %s" % (node_id, node_type))
        node_dir = out_dir / "nodes" / node_id
        content = dict(node.get("content") or {})

        field, filename = LOCALIZED_BODY.get(node_type, (None, None))
        if field:
            entries = {entry["lang"]: entry["text"] for entry in content.pop(field, [])}
            for lang in entries:
                if lang not in langs:
                    errors.append("%s: content.%s has language '%s', not declared in info"
                                  % (node_id, field, lang))
            for lang, text in entries.items():
                write_body(node_dir / "content" / lang / filename, text)

        filename = DOC_BODY.get(node_type)
        if filename:
            whole, content = content, {}
            probe = (whole.get("question") if node_type == "bool"
                     else (whole.get("items") or [{}])[0].get("question")
                     or (whole.get("items") or [{}])[0].get("label") or [])
            found = {entry["lang"] for entry in probe}
            for lang in sorted(found, key=lambda l: (l not in langs, langs.index(l)
                                                     if l in langs else l)):
                if lang not in langs:
                    errors.append("%s: content has language '%s', not declared in info"
                                  % (node_id, lang))
                write_body(node_dir / "content" / lang / filename,
                           content_md.render(node_type, whole, lang))

        field, filename = SOLO_BODY.get(node_type, (None, None))
        if field:
            entries = content.pop(field, [])
            if len(entries) > 1:
                errors.append("%s: content.%s carries %d languages; a %s prompt is one "
                              "text, addressed to the model"
                              % (node_id, field, len(entries), node_type))
            for entry in entries:
                write_body(solo_body_path(node_dir, filename, entry["lang"]), entry["text"])

        field, filename = PLAIN_BODY.get(node_type, (None, None))
        if field and field in content:
            write_body(node_dir / "content" / filename, content.pop(field))

        stripped = {key: value for key, value in node.items() if key != "content"}
        if content:
            stripped["content"] = content
        write_json(node_dir / "node.json", stripped)

    by_source = {}
    for edge in course["edges"]:
        by_source.setdefault(edge["from"], []).append(edge)
    for source, edges in by_source.items():
        write_json(out_dir / "edges" / ("%s.json" % source), edges)

    return errors


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("course_json", help="the single-file course to split")
    parser.add_argument("-o", "--out", help="course folder (default: the course title)")
    parser.add_argument("--force", action="store_true", help="overwrite existing nodes/ and edges/")
    args = parser.parse_args()

    source = Path(args.course_json)
    course = read_json(source)
    info = course["info"]
    title = next(entry["text"] for entry in info["title"]
                 if entry["lang"] == info["source-language"])
    out_dir = Path(args.out) if args.out else Path(title)

    errors = split(course, out_dir, args.force)

    target = out_dir / OUTPUT / ("%s-course.json" % course_slug(out_dir))
    if source.resolve() != target.resolve():
        shutil.copyfile(source, target)

    for error in errors:
        print("error: %s" % error, file=sys.stderr)
    if errors:
        return 1
    print("split %d nodes and %d edges into %s/" % (len(course["nodes"]), len(course["edges"]), out_dir))
    print("rebuild with: python3 %s \"%s\"" % (Path(__file__).with_name("build_course.py"), out_dir))
    return 0


if __name__ == "__main__":
    sys.exit(main())
