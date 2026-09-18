#!/usr/bin/env python3
"""Validate an Edukors course JSON file.

Checks three layers:

  1. structure  - the rules of course.schema.json (required fields, id prefixes,
                  allowed properties, patterns, localized text coverage)
  2. graph      - dangling edges, fallback ordering, reachability, dead ends
  3. storage    - keys used in edge conditions and in {{STORAGE: key}} references
                  exist and are produced upstream of where they are read

Usage:
    python3 validate_course.py course.json
    python3 validate_course.py course.json --quiet   # only errors and warnings

Exit code is 1 when there is at least one error, 0 otherwise.
No third-party dependencies.
"""

import argparse
import datetime
import json
import re
import sys
from collections import deque

LANG_RE = re.compile(r"^[a-z]{2}(-[A-Z]{2})?$")
ID_RE = re.compile(r"^(sm|sh|dm|dh|e|q|f|b)[0-9]+$")
NAME_RE = re.compile(r"^[a-z][a-z0-9-]*$")
VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")
DATE_RE = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}$")
UUID_RE = re.compile(
    r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
)
KEY_RE = re.compile(r"^(e|q|f|b)[0-9]+\.[a-z][a-z0-9-]*$")
STORAGE_RE = re.compile(r"\{\{\s*STORAGE:\s*([^}]+?)\s*\}\}")

PREFIX = {
    "static-md": "sm",
    "static-html": "sh",
    "dynamic-md": "dm",
    "dynamic-html": "dh",
    "essay": "e",
    "quiz": "q",
    "form": "f",
    "bool": "b",
}
NODE_TYPES = set(PREFIX)
OPERATORS = {"eq", "ne", "gt", "gte", "lt", "lte", "contains", "not-contains"}
FIELD_TYPES = {"text-line", "text-area", "radio", "check", "select"}
CHOICE_TYPES = {"radio", "check", "select"}

INFO_REQUIRED = [
    "course-id",
    "source-language",
    "other-languages",
    "title",
    "author",
    "version",
    "date",
    "start",
]
INFO_OPTIONAL = ["description", "sections", "system-prompt"]
NODE_REQUIRED = ["id", "type", "title", "content"]
NODE_OPTIONAL = ["section"]

CONTENT_FIELDS = {
    "static-md": (["item"], []),
    "static-html": (["item"], []),
    "dynamic-md": (["prompt"], []),
    "dynamic-html": (["prompt"], []),
    "essay": (["instructions", "prompt"], []),
    "quiz": (["items"], []),
    "form": (["items"], []),
    "bool": (["question"], ["yes-label", "no-label", "default"]),
}


class Report:
    def __init__(self):
        self.errors = []
        self.warnings = []
        self.missing = {}  # lang -> [places]
        self.correct_slots = []  # (node id, index of the correct option, option count)

    def error(self, where, msg):
        self.errors.append(f"{where}: {msg}")

    def warn(self, where, msg):
        self.warnings.append(f"{where}: {msg}")

    def missing_translation(self, lang, where):
        """Collected rather than printed: one missing translation usually means dozens."""
        self.missing.setdefault(lang, []).append(where)

    def finish(self):
        """Folds the collected repetitions into one line each."""
        for lang, places in sorted(self.missing.items()):
            sample = ", ".join(places[:3])
            more = f" and {len(places) - 3} more" if len(places) > 3 else ""
            self.warnings.append(
                f"translations: {len(places)} text(s) have no '{lang}' version ({sample}{more})"
            )


def check_keys(obj, where, required, optional, rep):
    """Required present, nothing unknown. Returns True when obj is a dict."""
    if not isinstance(obj, dict):
        rep.error(where, f"expected an object, found {type(obj).__name__}")
        return False
    for key in required:
        if key not in obj:
            rep.error(where, f"missing required field '{key}'")
    allowed = set(required) | set(optional)
    for key in obj:
        if key not in allowed:
            rep.error(where, f"unknown field '{key}' (the format allows no extra fields)")
    return True


def check_localized(value, where, rep, langs, required_langs=None, label="text"):
    """A localized text list: [{lang, text}, ...]."""
    if not isinstance(value, list) or not value:
        rep.error(where, f"{label} must be a non-empty list of {{lang, text}} objects")
        return
    seen = []
    for i, entry in enumerate(value):
        spot = f"{where}[{i}]"
        if not isinstance(entry, dict):
            rep.error(spot, "each entry must be an object with 'lang' and 'text'")
            continue
        extra = set(entry) - {"lang", "text"}
        if extra:
            rep.error(spot, f"unknown field(s) {sorted(extra)}")
        lang = entry.get("lang")
        if not isinstance(lang, str) or not LANG_RE.match(lang):
            rep.error(spot, f"invalid language code {lang!r} (expected e.g. 'pt', 'en', 'pt-BR')")
        else:
            if lang in seen:
                rep.error(spot, f"language '{lang}' appears twice")
            seen.append(lang)
            if langs and lang not in langs:
                rep.warn(spot, f"language '{lang}' is not declared in info")
        text = entry.get("text")
        if not isinstance(text, str):
            rep.error(spot, "'text' must be a string")
        elif not text.strip():
            rep.warn(spot, f"empty {label}")
    for i, lang in enumerate(required_langs or []):
        if lang not in seen:
            if i == 0:
                rep.error(where, f"missing the source language '{lang}'")
            else:
                rep.missing_translation(lang, where)


def localized_texts(value):
    if isinstance(value, list):
        return [e.get("text", "") for e in value if isinstance(e, dict)]
    if isinstance(value, str):
        return [value]
    return []


def validate_info(info, rep):
    """Returns (langs, source_lang, start, section_numbers)."""
    if not check_keys(info, "info", INFO_REQUIRED, INFO_OPTIONAL, rep):
        return [], None, None, set()

    source = info.get("source-language")
    if not isinstance(source, str) or not LANG_RE.match(source or ""):
        rep.error("info.source-language", f"invalid language code {source!r}")
        source = None

    others = info.get("other-languages")
    langs = [source] if source else []
    if not isinstance(others, list):
        rep.error("info.other-languages", "must be a list (use [] when there are no translations)")
    else:
        for lang in others:
            if not isinstance(lang, str) or not LANG_RE.match(lang):
                rep.error("info.other-languages", f"invalid language code {lang!r}")
            elif lang == source:
                rep.error("info.other-languages", f"'{lang}' is the source language and must not be repeated here")
            elif lang in langs:
                rep.error("info.other-languages", f"'{lang}' appears twice")
            else:
                langs.append(lang)

    cid = info.get("course-id")
    if not isinstance(cid, str) or not UUID_RE.match(cid or ""):
        rep.error("info.course-id", f"must be a UUID, found {cid!r}")

    if "title" in info:
        check_localized(info["title"], "info.title", rep, langs, langs, "title")
    if "description" in info:
        check_localized(info["description"], "info.description", rep, langs, langs, "description")

    author = info.get("author")
    if not isinstance(author, str) or not author.strip():
        rep.error("info.author", "must be a non-empty string")

    version = info.get("version")
    if not isinstance(version, str) or not VERSION_RE.match(version or ""):
        rep.error("info.version", f"must be MAJOR.MINOR.PATCH, found {version!r}")

    date = info.get("date")
    if not isinstance(date, str) or not DATE_RE.match(date or ""):
        rep.error("info.date", f"must be YYYY-MM-DD, found {date!r}")
    else:
        try:
            datetime.date.fromisoformat(date)
        except ValueError:
            rep.error("info.date", f"must be a real date, found {date!r}")

    start = info.get("start")
    if not isinstance(start, str) or not ID_RE.match(start or ""):
        rep.error("info.start", f"invalid node id {start!r}")
        start = None

    numbers = set()
    sections = info.get("sections")
    if sections is not None:
        if not isinstance(sections, list):
            rep.error("info.sections", "must be a list")
        else:
            for i, sec in enumerate(sections):
                spot = f"info.sections[{i}]"
                if not check_keys(sec, spot, ["number", "title"], [], rep):
                    continue
                num = sec.get("number")
                if not isinstance(num, int) or isinstance(num, bool) or num < 1:
                    rep.error(spot, f"'number' must be an integer >= 1, found {num!r}")
                elif num in numbers:
                    rep.error(spot, f"section number {num} is declared twice")
                else:
                    numbers.add(num)
                check_localized(sec.get("title"), f"{spot}.title", rep, langs, langs, "section title")

    sp = info.get("system-prompt")
    if sp is not None:
        if not isinstance(sp, str) or not sp.strip():
            rep.error("info.system-prompt", "must be a non-empty string")
        elif "language" not in sp.lower():
            rep.warn(
                "info.system-prompt",
                "does not mention the student's language; add 'Answer in the student's language.'",
            )

    return langs, source, start, numbers


def validate_quiz(content, where, rep, langs):
    items = content.get("items")
    if not isinstance(items, list) or not items:
        rep.error(f"{where}.items", "a quiz needs at least one question")
        return []
    produced, qkeys = [], set()
    for i, q in enumerate(items):
        spot = f"{where}.items[{i}]"
        if not check_keys(q, spot, ["question", "options"], ["key", "feedback"], rep):
            continue
        key = q.get("key")
        if key is not None:
            if not isinstance(key, str) or not NAME_RE.match(key):
                rep.error(spot, f"invalid 'key' {key!r} (lowercase letters, digits and hyphens)")
            elif key in {"score", "total", "percent"}:
                rep.error(spot, f"'key' cannot be '{key}' — the quiz already produces it")
            elif key in qkeys:
                rep.error(spot, f"key '{key}' is used twice in this quiz")
            else:
                qkeys.add(key)
                produced.append(key)
        check_localized(q.get("question"), f"{spot}.question", rep, langs, langs, "question")
        if "feedback" in q:
            check_localized(q["feedback"], f"{spot}.feedback", rep, langs, langs, "feedback")

        options = q.get("options")
        if not isinstance(options, list) or len(options) < 2:
            rep.error(f"{spot}.options", "a question needs at least two options")
            continue
        correct, values, correct_at = 0, set(), None
        for j, opt in enumerate(options):
            ospot = f"{spot}.options[{j}]"
            if not check_keys(opt, ospot, ["value", "label", "correct"], [], rep):
                continue
            value = opt.get("value")
            if not isinstance(value, str) or not NAME_RE.match(value):
                rep.error(ospot, f"invalid 'value' {value!r} (lowercase letters, digits and hyphens)")
            elif value in values:
                rep.error(ospot, f"option value '{value}' is used twice in this question")
            else:
                values.add(value)
            check_localized(opt.get("label"), f"{ospot}.label", rep, langs, langs, "option label")
            if opt.get("correct") is True:
                correct += 1
                if correct_at is None:
                    correct_at = j
            elif not isinstance(opt.get("correct"), bool):
                rep.error(ospot, "'correct' must be true or false")
        if correct != 1:
            rep.error(f"{spot}.options", f"exactly one option must be correct, found {correct}")
        elif correct_at is not None:
            rep.correct_slots.append((where, correct_at, len(options)))
    check_answer_spread(
        [s for s in rep.correct_slots if s[0] == where], where, rep, scope="this quiz"
    )
    return produced


def check_answer_spread(slots, where, rep, scope):
    """The correct option must not sit in the same slot every time — students notice."""
    if len(slots) < 3:
        return
    counts = {}
    for _, idx, _ in slots:
        counts[idx] = counts.get(idx, 0) + 1
    top, hits = max(counts.items(), key=lambda pair: pair[1])
    letter = chr(65 + top)
    if hits == len(slots):
        rep.warn(where, f"the correct answer is option {letter} in every question of {scope} — shuffle the options")
    elif hits / len(slots) > 0.6:
        rep.warn(
            where,
            f"the correct answer is option {letter} in {hits} of {len(slots)} questions of {scope} — spread it out",
        )


def validate_form(content, where, rep, langs):
    items = content.get("items")
    if not isinstance(items, list) or not items:
        rep.error(f"{where}.items", "a form needs at least one field")
        return []
    produced, keys = [], set()
    for i, field in enumerate(items):
        spot = f"{where}.items[{i}]"
        if not check_keys(field, spot, ["key", "type", "label"], ["required", "options"], rep):
            continue
        key = field.get("key")
        if not isinstance(key, str) or not NAME_RE.match(key):
            rep.error(spot, f"invalid 'key' {key!r} (lowercase letters, digits and hyphens)")
        elif key in keys:
            rep.error(spot, f"field key '{key}' is used twice in this form")
        else:
            keys.add(key)
            produced.append(key)
        ftype = field.get("type")
        if ftype not in FIELD_TYPES:
            rep.error(spot, f"invalid field type {ftype!r} (one of {sorted(FIELD_TYPES)})")
            ftype = None
        check_localized(field.get("label"), f"{spot}.label", rep, langs, langs, "field label")
        if "required" in field and not isinstance(field["required"], bool):
            rep.error(spot, "'required' must be true or false")

        options = field.get("options")
        if ftype in CHOICE_TYPES:
            if not isinstance(options, list) or len(options) < 2:
                rep.error(f"{spot}.options", f"a '{ftype}' field needs at least two options")
            else:
                values = set()
                for j, opt in enumerate(options):
                    ospot = f"{spot}.options[{j}]"
                    if not check_keys(opt, ospot, ["value", "label"], [], rep):
                        continue
                    value = opt.get("value")
                    if not isinstance(value, str) or not NAME_RE.match(value):
                        rep.error(ospot, f"invalid 'value' {value!r}")
                    elif value in values:
                        rep.error(ospot, f"option value '{value}' is used twice in this field")
                    else:
                        values.add(value)
                    check_localized(opt.get("label"), f"{ospot}.label", rep, langs, langs, "option label")
        elif ftype is not None and options is not None:
            rep.error(f"{spot}.options", f"a '{ftype}' field must not have options")
    return produced


def validate_node(node, index, rep, langs, source, section_numbers, ids):
    """Validates one node; returns (id, produced_keys, prompt_texts)."""
    where = f"nodes[{index}]"
    if not isinstance(node, dict):
        rep.error(where, "each node must be an object")
        return None, [], []

    ntype = node.get("type")
    nid = node.get("id")
    where = f"node {nid}" if isinstance(nid, str) else where

    if not check_keys(node, where, NODE_REQUIRED, NODE_OPTIONAL, rep):
        return None, [], []

    if ntype not in NODE_TYPES:
        rep.error(where, f"invalid type {ntype!r} (one of {sorted(NODE_TYPES)})")
        return None, [], []

    if not isinstance(nid, str) or not ID_RE.match(nid):
        rep.error(where, f"invalid id {nid!r}")
        return None, [], []
    if nid in ids:
        rep.error(where, "two nodes use this id")
    prefix = PREFIX[ntype]
    if not re.match(rf"^{prefix}[0-9]+$", nid):
        rep.error(where, f"id does not match type '{ntype}' (expected prefix '{prefix}')")

    section = node.get("section", 1)
    if not isinstance(section, int) or isinstance(section, bool) or section < 1:
        rep.error(where, f"'section' must be an integer >= 1, found {section!r}")
    elif section_numbers and section not in section_numbers:
        rep.warn(where, f"section {section} is not declared in info.sections")

    check_localized(node.get("title"), f"{where}.title", rep, langs, langs, "title")

    content = node.get("content")
    required, optional = CONTENT_FIELDS[ntype]
    if not check_keys(content, f"{where}.content", required, optional, rep):
        return nid, [], []

    produced, prompts = [], []

    if ntype in ("static-md", "static-html"):
        check_localized(content.get("item"), f"{where}.content.item", rep, langs, langs, "content")
        for text in localized_texts(content.get("item")):
            if len(text.split()) < 40:
                rep.warn(where, f"content is very short ({len(text.split())} words) for a teaching node")
                break

    elif ntype in ("dynamic-md", "dynamic-html"):
        # prompts are AI instructions, normally written in English whatever the course
        # language is, so neither the declared languages nor translations are required
        check_localized(content.get("prompt"), f"{where}.content.prompt", rep, None, None, "prompt")
        prompts = localized_texts(content.get("prompt"))
        if prompts and not any(STORAGE_RE.search(p) for p in prompts):
            rep.warn(
                where,
                "dynamic node whose prompt reads no {{STORAGE: key}} — nothing personalises it, "
                "consider making it static",
            )

    elif ntype == "essay":
        check_localized(
            content.get("instructions"), f"{where}.content.instructions", rep, langs, langs, "instructions"
        )
        prompt = content.get("prompt")
        if not isinstance(prompt, str) or not prompt.strip():
            rep.error(f"{where}.content.prompt", "the grading prompt must be a non-empty string")
        else:
            prompts = [prompt]
            low = prompt.lower()
            if "score" not in low:
                rep.warn(where, "the grading prompt never mentions 'score'; it must yield a 0-100 grade")
            if "feedback" not in low:
                rep.warn(where, "the grading prompt never mentions 'feedback'")
        produced = ["text", "score", "feedback"]

    elif ntype == "quiz":
        produced = ["score", "total", "percent"] + validate_quiz(content, f"{where}.content", rep, langs)

    elif ntype == "form":
        produced = validate_form(content, f"{where}.content", rep, langs)

    elif ntype == "bool":
        check_localized(content.get("question"), f"{where}.content.question", rep, langs, langs, "question")
        for label in ("yes-label", "no-label"):
            if label in content:
                check_localized(content[label], f"{where}.content.{label}", rep, langs, langs, label)
        if "default" in content and not isinstance(content["default"], bool):
            rep.error(f"{where}.content.default", "must be true or false")
        produced = ["answer"]

    return nid, [f"{nid}.{name}" for name in produced], prompts


def collect_condition_keys(cond, where, rep, depth=0):
    """Validates a condition tree; returns the storage keys it reads."""
    if depth > 8:
        rep.error(where, "condition nested too deeply")
        return []
    if not isinstance(cond, dict):
        rep.error(where, "'when' must be an object")
        return []

    if "and" in cond or "or" in cond:
        joiner = "and" if "and" in cond else "or"
        extra = set(cond) - {joiner}
        if extra:
            rep.error(where, f"an '{joiner}' condition must contain only '{joiner}', found {sorted(extra)}")
        group = cond.get(joiner)
        if not isinstance(group, list) or len(group) < 2:
            rep.error(f"{where}.{joiner}", "must be a list of at least two conditions")
            return []
        keys = []
        for i, sub in enumerate(group):
            keys += collect_condition_keys(sub, f"{where}.{joiner}[{i}]", rep, depth + 1)
        return keys

    extra = set(cond) - {"key", "operator", "value"}
    if extra:
        rep.error(where, f"unknown field(s) {sorted(extra)} in condition")
    for field in ("key", "operator", "value"):
        if field not in cond:
            rep.error(where, f"condition is missing '{field}'")
            return []

    key = cond["key"]
    if not isinstance(key, str) or not KEY_RE.match(key):
        rep.error(where, f"invalid key {key!r} (expected '<node-id>.<name>', e.g. 'q1.percent')")
        return []
    if cond["operator"] not in OPERATORS:
        rep.error(where, f"invalid operator {cond['operator']!r} (one of {sorted(OPERATORS)})")
    if not isinstance(cond["value"], (str, int, float, bool)):
        rep.error(where, "'value' must be a string, number or boolean")
    return [key]


def main():
    parser = argparse.ArgumentParser(description="Validate an Edukors course JSON file.")
    parser.add_argument("course", help="path to the course JSON")
    parser.add_argument("--quiet", action="store_true", help="hide the summary, show only problems")
    args = parser.parse_args()

    rep = Report()

    try:
        with open(args.course, encoding="utf-8") as handle:
            course = json.load(handle)
    except FileNotFoundError:
        print(f"ERROR: file not found: {args.course}")
        return 1
    except json.JSONDecodeError as exc:
        print(f"ERROR: invalid JSON at line {exc.lineno}, column {exc.colno}: {exc.msg}")
        return 1

    if not isinstance(course, dict):
        print("ERROR: the course must be a JSON object")
        return 1

    check_keys(course, "course", ["info", "nodes", "edges"], ["$schema"], rep)
    if "$schema" in course and not isinstance(course["$schema"], str):
        rep.error("$schema", "must be a string (the address of the schema)")

    langs, source, start, section_numbers = validate_info(course.get("info", {}), rep)

    nodes = course.get("nodes")
    if not isinstance(nodes, list) or not nodes:
        rep.error("nodes", "must be a non-empty list")
        nodes = []

    ids, order, produced, prompts_by_node, types = {}, [], {}, {}, {}
    for i, node in enumerate(nodes):
        nid, keys, prompts = validate_node(node, i, rep, langs, source, section_numbers, ids)
        if nid is None:
            continue
        if nid not in ids:
            order.append(nid)
        ids[nid] = node
        types[nid] = node.get("type")
        for key in keys:
            produced[key] = nid
        prompts_by_node[nid] = prompts

    edges = course.get("edges")
    if not isinstance(edges, list):
        rep.error("edges", "must be a list")
        edges = []

    outgoing = {nid: [] for nid in ids}
    reads = []  # (reader node id, key, where)
    for i, edge in enumerate(edges):
        where = f"edges[{i}]"
        if not check_keys(edge, where, ["from", "to"], ["when"], rep):
            continue
        src, dst = edge.get("from"), edge.get("to")
        where = f"edge {src} -> {dst}"
        ok = True
        if src not in ids:
            rep.error(where, f"'from' points to {src!r}, which is not a node")
            ok = False
        if dst not in ids:
            rep.error(where, f"'to' points to {dst!r}, which is not a node")
            ok = False
        if not ok:
            continue
        outgoing[src].append(edge)
        if "when" in edge:
            for key in collect_condition_keys(edge["when"], f"{where}.when", rep):
                reads.append((src, key, where))

    # fallback ordering
    for nid, out in outgoing.items():
        plain = [i for i, e in enumerate(out) if "when" not in e]
        if not plain and out:
            rep.warn(
                f"node {nid}",
                "every outgoing edge is conditional; a student matching none of them gets stuck — "
                "add an unconditional fallback edge last",
            )
        if len(plain) > 1:
            rep.warn(f"node {nid}", f"{len(plain)} unconditional edges; only the first can ever be taken")
        if plain and plain[0] < len(out) - 1:
            dead = len(out) - 1 - plain[0]
            rep.error(
                f"node {nid}",
                f"the unconditional edge is not last: the following {dead} edge(s) are unreachable",
            )

    # reachability from start
    reachable, ancestors = set(), {}
    if start and start in ids:
        queue = deque([start])
        reachable.add(start)
        while queue:
            nid = queue.popleft()
            for edge in outgoing[nid]:
                if edge["to"] not in reachable:
                    reachable.add(edge["to"])
                    queue.append(edge["to"])
        for nid in ids:
            seen, queue = set(), deque([nid])
            while queue:
                cur = queue.popleft()
                for edge in outgoing[cur]:
                    if edge["to"] not in seen:
                        seen.add(edge["to"])
                        queue.append(edge["to"])
            for target in seen:
                ancestors.setdefault(target, set()).add(nid)
        stranded = [nid for nid in order if nid not in reachable]
        if stranded:
            rep.error(
                "graph",
                f"no path from the start node reaches: {', '.join(stranded)} "
                "(usually a missing edge into them)",
            )
    elif start:
        rep.error("info.start", f"start node '{start}' does not exist")

    # dead ends
    terminals = [nid for nid in order if nid in reachable and not outgoing.get(nid)]
    if len(terminals) > 1:
        rep.warn(
            "graph",
            "several nodes end the course: " + ", ".join(terminals) + " — branches should reunite "
            "unless every one of these is a real ending",
        )

    def upstream_of(producer, reader):
        """True when producer is reader itself or can reach reader."""
        return producer == reader or producer in ancestors.get(reader, set())

    # keys used in edge conditions
    for reader, key, where in reads:
        if key not in produced:
            rep.error(where, f"condition reads '{key}', which no node produces")
        elif ancestors and not upstream_of(produced[key], reader):
            rep.warn(
                where,
                f"'{key}' is produced by {produced[key]}, which is not on any path to {reader}; "
                "this condition can never hold",
            )

    # keys used in prompts
    for nid, prompts in prompts_by_node.items():
        for prompt in prompts:
            for key in STORAGE_RE.findall(prompt or ""):
                key = key.strip()
                where = f"node {nid} prompt"
                if not KEY_RE.match(key):
                    rep.error(where, f"{{{{STORAGE: {key}}}}} is not a valid key ('<node-id>.<name>')")
                elif key not in produced:
                    rep.error(where, f"{{{{STORAGE: {key}}}}} reads a key no node produces")
                elif ancestors and not upstream_of(produced[key], nid):
                    rep.warn(
                        where,
                        f"{{{{STORAGE: {key}}}}} is produced by {produced[key]}, which the student may not "
                        f"have reached before {nid}; the value will be empty",
                    )

    # unused activity results
    used = {key for _, key, _ in reads}
    for nid, prompts in prompts_by_node.items():
        for prompt in prompts:
            used |= {k.strip() for k in STORAGE_RE.findall(prompt or "")}
    for nid in order:
        if types.get(nid) in ("quiz", "essay", "form", "bool"):
            keys = [k for k, owner in produced.items() if owner == nid]
            if keys and not any(k in used for k in keys):
                rep.warn(
                    f"node {nid}",
                    "stores data no edge and no prompt ever reads — branch on it or use it in a prompt",
                )

    # correct-answer positions across every quiz of the course
    check_answer_spread(rep.correct_slots, "quizzes", rep, scope="the whole course")

    # report
    rep.finish()
    for line in rep.errors:
        print(f"ERROR   {line}")
    for line in rep.warnings:
        print(f"WARNING {line}")

    if not args.quiet:
        if rep.errors or rep.warnings:
            print()
        sections = sorted({(n.get("section", 1) if isinstance(n, dict) else 1) for n in nodes})
        counts = {}
        for nid in order:
            counts[types[nid]] = counts.get(types[nid], 0) + 1
        skipped = len(nodes) - len(order)
        tail = f" ({skipped} unusable node(s) not counted)" if skipped > 0 else ""
        print(
            f"{len(order)} nodes, {len(edges)} edges, {len(sections)} section(s), "
            f"languages: {', '.join(langs) or '?'}{tail}"
        )
        print("  types: " + ", ".join(f"{k} x{v}" for k, v in sorted(counts.items())))
        if produced:
            print("  storage keys: " + ", ".join(sorted(produced)))
        print(f"\n{len(rep.errors)} error(s), {len(rep.warnings)} warning(s)")
        if not rep.errors and not rep.warnings:
            print("The course is valid.")
        elif not rep.errors:
            print("The course is valid; read the warnings before shipping it.")

    return 1 if rep.errors else 0


if __name__ == "__main__":
    sys.exit(main())
