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
ID_RE = re.compile(r"^(sm|sh|dm|dh|q|f|b|c|s|n)[0-9]+$")
NAME_RE = re.compile(r"^[a-z][a-z0-9-]*$")
VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")
DATE_RE = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}$")
UUID_RE = re.compile(
    r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
)
KEY_RE = re.compile(r"^(dm|dh|q|f|b|c|s|n)[0-9]+\.[a-z][a-z0-9-]*$")
STORAGE_RE = re.compile(r"\{\{\s*STORAGE:\s*([^}]+?)\s*\}\}")

PREFIX = {
    "static-md": "sm",
    "static-html": "sh",
    "dynamic-md": "dm",
    "dynamic-html": "dh",
    "quiz": "q",
    "form": "f",
    "bool": "b",
    "choice": "c",
    "score": "s",
    "noul": "n",
}
NODE_TYPES = set(PREFIX)
# The nodes the AI decides with. The student never stops at one of them.
JUDGE_TYPES = ("choice", "score", "noul")
# Suffixes a judgement node appends to a question key on its own, and the two
# names a score node produces for the node as a whole. A question may use none
# of them, or its own answer would overwrite one of these.
JUDGE_SUFFIXES = ("-confidence", "-points")
JUDGE_RESERVED = ("total", "percent")
# Verbs that give away a feedback prompt judging all over again. Whole words only:
# a prompt has to be able to say "the judgement below" without being told off.
JUDGING_RE = re.compile(
    r"\b(grade[sd]?|grading|scores?|scored|scoring|judges?|judged|judging"
    r"|evaluat(?:e[sd]?|ing)|rates?|rated|rating|assess(?:es|ed|ing)?)\b",
    re.IGNORECASE,
)
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
    "dynamic-md": (["prompt"], ["from"]),
    "dynamic-html": (["prompt"], ["from"]),
    "quiz": (["items"], []),
    "form": (["items"], ["instructions"]),
    "bool": (["question"], ["yes-label", "no-label", "default"]),
    "choice": (["state", "items"], []),
    "score": (["state", "items"], []),
    "noul": (["state", "items"], []),
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


def whole_number(value):
    """True for a real integer. In Python True is an int, and a bool here is a typo."""
    return isinstance(value, int) and not isinstance(value, bool)


def validate_form(content, where, rep, langs):
    if "instructions" in content:
        check_localized(
            content["instructions"], f"{where}.instructions", rep, langs, langs, "instructions"
        )

    items = content.get("items")
    if not isinstance(items, list) or not items:
        rep.error(f"{where}.items", "a form needs at least one field")
        return []

    # A lone text-area is a writing task, and a writing task without an assignment
    # leaves the student with an empty box and a one-line label.
    if (
        len(items) == 1
        and isinstance(items[0], dict)
        and items[0].get("type") == "text-area"
        and "instructions" not in content
    ):
        rep.warn(
            where,
            "a single text-area and no 'instructions': a writing task needs its assignment -- "
            "what to write, how long, and what will be judged",
        )

    produced, keys = [], set()
    for i, field in enumerate(items):
        spot = f"{where}.items[{i}]"
        if not check_keys(
            field, spot, ["key", "type", "label"], ["required", "options", "min-words", "max-words"], rep
        ):
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

        limits = {}
        for bound in ("min-words", "max-words"):
            if bound not in field:
                continue
            if ftype is not None and ftype not in ("text-line", "text-area"):
                rep.error(spot, f"a '{ftype}' field counts no words, so it takes no '{bound}'")
            elif not whole_number(field[bound]) or field[bound] < 1:
                rep.error(spot, f"'{bound}' must be a whole number >= 1, found {field[bound]!r}")
            else:
                limits[bound] = field[bound]
        if len(limits) == 2 and limits["max-words"] < limits["min-words"]:
            rep.error(
                spot,
                f"'max-words' ({limits['max-words']}) is below 'min-words' ({limits['min-words']}): "
                "no answer can satisfy both",
            )

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


def validate_points(points, criteria, spot, rep):
    """The points each level is worth. They line up with the scale, one for one."""
    if not isinstance(points, list):
        rep.error(f"{spot}.points", "must be a list of numbers, one per level of 'criteria'")
        return
    for i, value in enumerate(points):
        if isinstance(value, bool) or not isinstance(value, (int, float)):
            rep.error(f"{spot}.points[{i}]", f"must be a number, found {value!r}")
            return
        if value < 0:
            rep.error(f"{spot}.points[{i}]", f"must be 0 or more, found {value!r}")
            return
    if isinstance(criteria, list) and len(points) != len(criteria):
        rep.error(
            f"{spot}.points",
            f"{len(points)} point value(s) for {len(criteria)} level(s): there must be exactly one "
            "per level, in the same order",
        )
        return
    if any(b < a for a, b in zip(points, points[1:])):
        rep.warn(
            f"{spot}.points",
            f"the points do not rise with the levels ({', '.join(str(p) for p in points)}): a higher "
            "level worth less than a lower one is almost always a typo",
        )


def validate_judge_criteria(criteria, ntype, spot, rep):
    """What an answer may be: the options, the scale, or what yes and no cover."""
    if ntype == "choice":
        if not isinstance(criteria, dict):
            rep.error(f"{spot}.criteria", "must be a map of the name of each option to what it covers")
            return
        if len(criteria) < 2:
            rep.error(f"{spot}.criteria", "a choice needs at least two options")
        if len(criteria) > 255:
            rep.error(f"{spot}.criteria", f"a choice takes at most 255 options, got {len(criteria)}")
        for name, what in criteria.items():
            if not isinstance(name, str) or not NAME_RE.match(name):
                rep.error(
                    f"{spot}.criteria",
                    f"the option '{name}' must be a name the edges can compare to: "
                    "lowercase letters, digits and -",
                )
            if what is not None and not isinstance(what, str):
                rep.error(f"{spot}.criteria.{name}", "must be a text saying what the option covers, or null")
        return

    if ntype == "score":
        if not isinstance(criteria, list):
            rep.error(
                f"{spot}.criteria",
                "must be the levels of the scale, in order, from the low end to the high end",
            )
            return
        if not 2 <= len(criteria) <= 10:
            rep.error(f"{spot}.criteria", f"a scale has between 2 and 10 levels, got {len(criteria)}")
        for i, level in enumerate(criteria):
            if not isinstance(level, str) or not level.strip():
                rep.error(f"{spot}.criteria[{i}]", "each level must say what it means")
        return

    # noul: 'criteria' only clears up what a yes and a no cover, and a plain
    # question does not need it
    if criteria is None:
        return
    if not isinstance(criteria, dict):
        rep.error(f"{spot}.criteria", "must say what 'true' and what 'false' cover")
        return
    for name, what in criteria.items():
        if name not in ("true", "false"):
            rep.error(f"{spot}.criteria", f"'{name}' is not a field here: only 'true' and 'false' are")
        elif not isinstance(what, str) or not what.strip():
            rep.error(f"{spot}.criteria.{name}", "must be a non-empty text")


def validate_judge(content, nid, ntype, where, rep):
    """A node the AI decides with: choice, score or noul.

    The three differ only in what an answer may be -- one of the options listed, a
    level of the scale listed, or a probability -- so everything around that is
    checked here once: a 'state' to judge, and one question per key. Returns the
    names produced, the texts to run the {{STORAGE: key}} checks over, and how many
    levels each score question has, which is what lets main() catch an edge
    comparing a level against a percentage.
    """
    produced, prompts, levels = [], [], {}

    state = content.get("state")
    if not isinstance(state, dict) or not state:
        rep.error(
            f"{where}.content.state",
            "the state the AI judges must be an object of named fields, e.g. "
            '{"task": "...", "answer": "{{STORAGE: f1.text}}"}',
        )
    else:
        for name, value in state.items():
            spot = f"{where}.content.state.{name}"
            if not NAME_RE.match(str(name)):
                rep.error(spot, "a field name is lowercase letters, digits and hyphens")
            if isinstance(value, str):
                # Treated as a prompt so it goes through the same {{STORAGE: key}}
                # checks: a key nothing produces, or one produced downstream, is the
                # same mistake here as in a dynamic node.
                prompts.append(value)
            elif isinstance(value, list):
                for j, entry in enumerate(value):
                    if not isinstance(entry, str):
                        rep.error(f"{spot}[{j}]", "must be a text")
                    else:
                        prompts.append(entry)
            else:
                rep.error(spot, "must be a text, or a list of texts")
        if not any(STORAGE_RE.search(text) for text in prompts):
            rep.warn(
                where,
                "the state reads no {{STORAGE: key}}, so the AI judges the same thing for every "
                "student and the node always takes the same edge",
            )

    items = content.get("items")
    if not isinstance(items, list) or not items:
        rep.error(f"{where}.content.items", "a judgement node needs at least one question")
        return produced, prompts, levels

    seen, scored = set(), False
    for index, item in enumerate(items):
        spot = f"{where}.content.items[{index}]"
        required = ["key", "instructions"] if ntype == "noul" else ["key", "instructions", "criteria"]
        optional = ["criteria"] if ntype == "noul" else []
        if ntype == "score":
            optional = optional + ["points"]
        if not check_keys(item, spot, required, optional, rep):
            continue

        key = item.get("key")
        if not isinstance(key, str) or not NAME_RE.match(key):
            rep.error(f"{spot}.key", "must be a name like 'track': lowercase letters, digits and -")
            continue
        hit = next((suffix for suffix in JUDGE_SUFFIXES if key.endswith(suffix)), None)
        if hit:
            rep.error(f"{spot}.key", f"cannot end in '{hit}': the node produces that key on its own")
            continue
        if key in JUDGE_RESERVED:
            rep.error(
                f"{spot}.key",
                f"'{key}' is what a score node produces for the whole node; name the question "
                "after what it judges",
            )
            continue
        if key in seen:
            rep.error(f"{spot}.key", f"'{key}' is used twice in the same node")
            continue
        seen.add(key)

        instructions = item.get("instructions")
        if not isinstance(instructions, str) or not instructions.strip():
            rep.error(f"{spot}.instructions", "the question the AI answers must be a non-empty string")
        else:
            prompts.append(instructions)

        criteria = item.get("criteria")
        validate_judge_criteria(criteria, ntype, spot, rep)

        produced.append(key)
        if ntype != "noul":
            produced.append(f"{key}-confidence")
        if ntype == "score":
            if isinstance(criteria, list):
                levels[key] = len(criteria)
            if "points" in item:
                validate_points(item["points"], criteria, spot, rep)
                produced.append(f"{key}-points")
                scored = True

    if scored:
        produced.extend(["total", "percent"])

    return produced, prompts, levels


def validate_node(node, index, rep, langs, source, section_numbers, ids):
    """Validates one node; returns (id, produced_keys, prompt_texts, score_levels)."""
    where = f"nodes[{index}]"
    if not isinstance(node, dict):
        rep.error(where, "each node must be an object")
        return None, [], [], {}

    ntype = node.get("type")
    nid = node.get("id")
    where = f"node {nid}" if isinstance(nid, str) else where

    if not check_keys(node, where, NODE_REQUIRED, NODE_OPTIONAL, rep):
        return None, [], [], {}

    if ntype not in NODE_TYPES:
        rep.error(where, f"invalid type {ntype!r} (one of {sorted(NODE_TYPES)})")
        return None, [], [], {}

    if not isinstance(nid, str) or not ID_RE.match(nid):
        rep.error(where, f"invalid id {nid!r}")
        return None, [], [], {}
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
        return nid, [], [], {}

    produced, prompts, levels = [], [], {}

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
        source_judge = content.get("from")
        if source_judge is not None and (
            not isinstance(source_judge, str) or not re.match(r"^(c|s|n)[0-9]+$", source_judge)
        ):
            rep.error(
                f"{where}.content.from",
                f"must be the id of a choice, score or noul node, found {source_judge!r}",
            )
            source_judge = None
        if source_judge:
            # A node written from a judgement is handed that judgement rendered in
            # full, so it needs no storage key of its own to be personalised. What
            # it must not do is form an opinion: the level is already settled.
            for text in prompts:
                found = JUDGING_RE.search(text)
                if found:
                    hit = found.group(0)
                    rep.warn(
                        where,
                        f"writes from the judgement of {source_judge} but its prompt says '{hit}': "
                        "the level is already settled, and a feedback that judges again can "
                        "contradict the number that routed the student -- tell it how to write, "
                        "not what to decide",
                    )
                    break
        elif prompts and not any(STORAGE_RE.search(p) for p in prompts):
            rep.warn(
                where,
                "dynamic node whose prompt reads no {{STORAGE: key}} — nothing personalises it, "
                "consider making it static",
            )
        produced = ["text"]

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

    elif ntype in JUDGE_TYPES:
        produced, prompts, levels = validate_judge(content, nid, ntype, where, rep)

    return nid, [f"{nid}.{name}" for name in produced], prompts, levels


def collect_condition_keys(cond, where, rep, depth=0):
    """Validates a condition tree; returns the (key, value) pairs it compares."""
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
    return [(key, cond["value"])]


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
    score_levels = {}  # node id -> {question key: how many levels}
    writes_from = {}  # node id of a dynamic node -> the judgement it writes from
    for i, node in enumerate(nodes):
        nid, keys, prompts, levels = validate_node(node, i, rep, langs, source, section_numbers, ids)
        if nid is None:
            continue
        if nid not in ids:
            order.append(nid)
        ids[nid] = node
        types[nid] = node.get("type")
        for key in keys:
            produced[key] = nid
        prompts_by_node[nid] = prompts
        if levels:
            score_levels[nid] = levels
        if node.get("type") in ("dynamic-md", "dynamic-html"):
            origin = (node.get("content") or {}).get("from")
            if isinstance(origin, str):
                writes_from[nid] = origin


    # A judgement anchored on a grade already given is no longer an independent
    # judgement: the schema asks for what the judgement needs and nothing else.
    graded_keys = {
        key
        for key, owner in produced.items()
        if types.get(owner) in JUDGE_TYPES or (types.get(owner) == "quiz" and key.endswith(".percent"))
    }
    for nid in order:
        if types.get(nid) not in JUDGE_TYPES:
            continue
        state = (ids[nid].get("content") or {}).get("state")
        if not isinstance(state, dict):
            continue
        for name, value in state.items():
            for key in STORAGE_RE.findall(value if isinstance(value, str) else " ".join(
                entry for entry in value if isinstance(entry, str)
            ) if isinstance(value, list) else ""):
                key = key.strip()
                if key in graded_keys and produced.get(key) != nid:
                    rep.warn(
                        f"node {nid}",
                        f"the state field '{name}' reads '{key}', a mark already given: the AI would "
                        "anchor on it instead of judging for itself -- give the judgement the work "
                        "and the task, not an earlier verdict",
                    )

    for nid, origin in writes_from.items():
        if origin not in ids:
            rep.error(f"node {nid}", f"writes from '{origin}', which is not a node")
        elif types.get(origin) not in JUDGE_TYPES:
            rep.error(
                f"node {nid}",
                f"writes from '{origin}', which is a {types.get(origin)} node and makes no judgement",
            )

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
            for key, value in collect_condition_keys(edge["when"], f"{where}.when", rep):
                reads.append((src, key, where, value))

    # fallback ordering
    for nid, out in outgoing.items():
        plain = [i for i, e in enumerate(out) if "when" not in e]
        if not plain and out:
            # For a node the AI decides with this is not a risk but a certainty
            # waiting to happen: the call can fail, and then the node produces no
            # key at all. With nothing to match, the player finds no edge and
            # reads that as the course being over — the student is shown a
            # finished course at 100%, silently.
            if types.get(nid) in JUDGE_TYPES:
                rep.error(
                    f"node {nid}",
                    "every outgoing edge is conditional; a judgement the AI could not make leaves "
                    "the student with nowhere to go — add an unconditional fallback edge last",
                )
            else:
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
        # The fallback of a judgement is the path taken when there is no judgement.
        # Sending it to a node that writes from that judgement asks it to write
        # feedback from nothing.
        if types.get(nid) in JUDGE_TYPES:
            for i in plain:
                target = out[i].get("to")
                if writes_from.get(target) == nid:
                    rep.error(
                        f"node {nid}",
                        f"its unconditional edge leads to {target}, which writes from this very "
                        "judgement -- that edge is the path taken when no judgement was made, so "
                        f"{target} would have nothing to write from; send the fallback elsewhere",
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

    def reaches_without(target, blocked):
        """True when the start still reaches target with 'blocked' taken out.

        Being a mere ancestor is not enough here: the judgement has to sit on
        *every* path in, or one of the others delivers a student to a node with no
        judgement to write from.
        """
        if not start or start in (target, blocked):
            return start == target
        seen, queue = {start}, deque([start])
        while queue:
            for edge in outgoing.get(queue.popleft(), []):
                nxt = edge["to"]
                if nxt == blocked or nxt in seen:
                    continue
                if nxt == target:
                    return True
                seen.add(nxt)
                queue.append(nxt)
        return False

    # A node written from a judgement is only ever reached through that judgement.
    for nid, origin in writes_from.items():
        if nid in ids and origin in ids and nid in reachable and reaches_without(nid, origin):
            rep.error(
                f"node {nid}",
                f"writes from the judgement of {origin}, but there is a path to it that never "
                f"passes through {origin}: a student taking that path would reach a node with no "
                "judgement to write from",
            )

    # keys used in edge conditions
    for reader, key, where, value in reads:
        if key not in produced:
            rep.error(where, f"condition reads '{key}', which no node produces")
            continue
        if ancestors and not upstream_of(produced[key], reader):
            rep.warn(
                where,
                f"'{key}' is produced by {produced[key]}, which is not on any path to {reader}; "
                "this condition can never hold",
            )
        # A level runs over the levels of its own question, not 0-100. Comparing it
        # against a percentage is the habit an essay grade left behind, and the edge
        # simply never fires.
        owner, _, name = key.partition(".")
        count = score_levels.get(owner, {}).get(name)
        if (
            count
            and isinstance(value, (int, float))
            and not isinstance(value, bool)
            and value > count - 1
        ):
            rep.warn(
                where,
                f"'{key}' runs from 0 to {count - 1}, over the levels of its own question, so "
                f"comparing it against {value} never holds. For a grade out of 100 give the "
                f"question 'points' and test {owner}.percent",
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
    used = {key for _, key, _, _ in reads}
    for nid, prompts in prompts_by_node.items():
        for prompt in prompts:
            used |= {k.strip() for k in STORAGE_RE.findall(prompt or "")}
    for nid in order:
        # dynamic nodes are left out on purpose: their .text is what the student is
        # shown, so it is never data nobody reads.
        if types.get(nid) in ("quiz", "form", "bool") + JUDGE_TYPES:
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
