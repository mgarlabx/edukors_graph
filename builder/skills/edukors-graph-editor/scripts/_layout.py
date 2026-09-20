"""Shared layout rules for the exploded Edukors course folder.

One course = one folder: info/, nodes/<id>/{node.json,content/...}, edges/<from>.json,
plus the generated _output/ (json, map and player files, all side by side).
"""
import json
import re
import unicodedata
from pathlib import Path

SCHEMA = "https://edukors.org/graph/schema/v1/"

# everything the build generates lives under this one folder of the course
OUTPUT = "_output"

# id prefix -> node type.
#
# The order matters: type_for_id() takes the first prefix that matches, so every
# two-letter prefix has to come before the one-letter prefix it starts with.
# "sm" before "s" is the live case -- reverse those two and every static-md node
# in every course starts reading as a score.
PREFIX_TYPE = [
    ("sm", "static-md"),
    ("sh", "static-html"),
    ("dm", "dynamic-md"),
    ("dh", "dynamic-html"),
    ("e", "essay"),
    ("q", "quiz"),
    ("f", "form"),
    ("b", "bool"),
    ("c", "choice"),
    ("s", "score"),
    ("n", "noul"),
]

# node type -> (content field externalized per language, file name)
LOCALIZED_BODY = {
    "static-md": ("item", "item.md"),
    "static-html": ("item", "item.html"),
    "essay": ("instructions", "instructions.md"),
}

# node type -> (content field externalized without language, file name)
PLAIN_BODY = {
    "essay": ("prompt", "prompt.md"),
}

# node type -> (content field that is a localized list but holds a single text,
# externalized to one file above the language folders, file name)
SOLO_BODY = {
    "dynamic-md": ("prompt", "prompt.md"),
    "dynamic-html": ("prompt", "prompt.md"),
}

# The language a solo body carries when its file name does not say otherwise.
SOLO_LANG = "en"

# node type -> file name of the per-language document holding its whole content.
# An activity is not a field with prose around it: its questions, options, labels
# and feedback only make sense read together, in one language, so the whole
# content becomes one document per language. content_md.py owns their syntax.
DOC_BODY = {
    "quiz": "quiz.md",
    "form": "form.md",
    "bool": "bool.md",
}


def type_for_id(node_id):
    """The type the id prefix announces, or None if it announces nothing valid."""
    for prefix, node_type in PREFIX_TYPE:
        if node_id.startswith(prefix) and node_id[len(prefix):].isdigit():
            return node_type
    return None


def languages(info):
    """Course languages, source first."""
    return [info["source-language"]] + list(info.get("other-languages") or [])


def slugify(text):
    plain = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    slug = re.sub(r"[^a-z0-9]+", "-", plain.lower()).strip("-")
    return slug or "course"


def course_slug(course_dir):
    """File slug of a course: its folder name, kebab-cased, without a -course tail."""
    slug = slugify(Path(course_dir).resolve().name)
    return slug[:-len("-course")] if slug.endswith("-course") else slug


def solo_body_path(node_dir, filename, lang):
    """Where a solo body of that language lives: prompt.md in English, else prompt.<lang>.md."""
    base, _, ext = filename.rpartition(".")
    name = filename if lang == SOLO_LANG else "%s.%s.%s" % (base, lang, ext)
    return node_dir / "content" / name


def solo_body_files(node_dir, filename):
    """The solo body files present, as (path, lang) pairs in file-name order.

    content/prompt.md is SOLO_LANG; content/prompt.<lang>.md is that language.
    """
    base, _, ext = filename.rpartition(".")
    content_dir = node_dir / "content"
    if not content_dir.is_dir():
        return []
    found = []
    for path in sorted(content_dir.glob("%s*.%s" % (base, ext))):
        middle = path.name[len(base):-(len(ext) + 1)]
        if middle and not middle.startswith("."):
            continue
        found.append((path, middle[1:] if middle else SOLO_LANG))
    return found


def natural_key(node_id):
    match = re.match(r"^([a-z]+)(\d+)$", node_id)
    return (match.group(1), int(match.group(2))) if match else (node_id, 0)


def read_body(path):
    """Read an externalized body. Exactly one trailing newline is removed."""
    text = path.read_text(encoding="utf-8")
    return text[:-1] if text.endswith("\n") else text


def write_body(path, text):
    """Write an externalized body, always with one trailing newline (see read_body)."""
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text + "\n", encoding="utf-8")


def read_json(path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def node_dirs(course_dir):
    """Node folders, in natural id order."""
    nodes = course_dir / "nodes"
    if not nodes.is_dir():
        return []
    return sorted((d for d in nodes.iterdir() if d.is_dir()), key=lambda d: natural_key(d.name))


def reading_order(node_ids, edges_by_source, start):
    """Breadth-first from start; unreachable nodes appended in natural id order."""
    remaining = set(node_ids)
    order = []
    queue = [start] if start in remaining else []
    seen = set(queue)
    while queue:
        current = queue.pop(0)
        if current in remaining:
            order.append(current)
            remaining.discard(current)
        for edge in edges_by_source.get(current, []):
            target = edge.get("to")
            if target in remaining and target not in seen:
                seen.add(target)
                queue.append(target)
    order.extend(sorted(remaining, key=natural_key))
    return order
