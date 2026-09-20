"""The markdown documents of the activity nodes: quiz.md, form.md and bool.md.

Three node types are not a field with prose around it — they are a small document
that only means anything read whole: a quiz, a form, a yes/no question. In the
JSON their languages interleave item by item, so the author can never read one
through in any single language (a 4-question quiz in three languages is 443 lines
nobody proofreads). Here each language gets the whole thing in one file.

    content/<lang>/quiz.md          content/<lang>/form.md      content/<lang>/bool.md

    ## 1. habitat                   ## 1. interest (radio,      Do you want the
                                           required)            advanced track?
    Which cat cannot roar?
                                    Which group now?            - [ ] yes: Yes please
    - [ ] jaguar: Jaguar                                        - [x] no: No, go on
    - [x] snow-leopard: Snow …      - big: Big cats
    - [ ] lion: Lion                - small: Small wild cats

    > The snow leopard lives …      ## 2. country (text-line)

                                    Where are you writing from?

Everything is markdown and may hold images, tables and LaTeX. Two rules keep long
content unambiguous, in all three documents: anything inside a fenced code block
is content and never structure, and a line indented by two spaces or more
continues the option above it. So the only thing that cannot be written at column
zero, unfenced, is a line that looks like a heading or an option — the same line
that would need indenting or fencing to render correctly anyway.

This module is the only place that knows the syntax. For each type DOCUMENTS
gives four functions: render() writes the file, parse() reads it back, shape()
says what every language must agree on, and assemble() merges the languages into
the `content` of the node. render and parse are exact — text in, same text out.
"""
import re

HEADING = re.compile(r"^## +(\d+)\.[ \t]*([a-z][a-z0-9-]*)?[ \t]*$")
FIELD_HEADING = re.compile(r"^## +(\d+)\.[ \t]+([a-z][a-z0-9-]*)[ \t]*\(([^)]*)\)[ \t]*$")
CHOICE = re.compile(r"^- \[([ xX])\] ([a-z][a-z0-9-]*):[ \t]?(.*)$")
OPTION = re.compile(r"^- ([a-z][a-z0-9-]*):[ \t]?(.*)$")
FENCE = re.compile(r"^ {0,3}(```|~~~)")
CONTINUATION_INDENT = "      "
RESERVED_KEYS = ("score", "total", "percent")
FIELD_TYPES = ("text-line", "text-area", "radio", "check", "select")
WORDS_BOTH = re.compile(r"^(\d+)\s*-\s*(\d+)\s+words$")
WORDS_MIN = re.compile(r"^min\s+(\d+)\s+words$")
WORDS_MAX = re.compile(r"^max\s+(\d+)\s+words$")
WITH_OPTIONS = ("radio", "check", "select")


# ---------------------------------------------------------------- rendering

class _Fields(list):
    """The fields of a form, carrying the text written above them.

    The instructions are prose, not structure, so they ride alongside rather than
    as a record: every language writes its own, and none of them has to agree with
    another the way a field does.
    """

    instructions = ""


def _indent_block(text):
    """Every line but the first of an option label, indented under it."""
    return [CONTINUATION_INDENT + line if line.strip() else "" for line in text.split("\n")]


def _quote_block(text):
    return ["> " + line if line.strip() else ">" for line in text.split("\n")]


def _text_of(entries, lang):
    for entry in entries:
        if entry["lang"] == lang:
            return entry["text"]
    return ""


def _render_option(head, label):
    """An option line plus the indented lines of a label that does not fit on it."""
    lines = label.split("\n")
    out = [("%s %s" % (head, lines[0])).rstrip()]
    if len(lines) > 1:
        out.extend(_indent_block("\n".join(lines[1:])))
    return out


def render_quiz(items, lang):
    lines = []
    for number, item in enumerate(items, 1):
        if lines:
            lines.append("")
        heading = "## %d." % number
        if item.get("key"):
            heading += " " + item["key"]
        lines.extend([heading, ""])
        lines.extend(_text_of(item["question"], lang).split("\n"))
        lines.append("")
        for option in item["options"]:
            mark = "x" if option.get("correct") else " "
            lines.extend(_render_option("- [%s] %s:" % (mark, option["value"]),
                                        _text_of(option["label"], lang)))
        if item.get("feedback"):
            lines.append("")
            lines.extend(_quote_block(_text_of(item["feedback"], lang)))
    return "\n".join(lines)


def _words_attribute(field):
    """The word limits as they are written in a field heading, or nothing."""
    low, high = field.get("min-words"), field.get("max-words")
    if low is not None and high is not None:
        return "%d-%d words" % (low, high)
    if low is not None:
        return "min %d words" % low
    if high is not None:
        return "max %d words" % high
    return None


def render_form(content, lang):
    items = content.get("items") or []
    lines = []
    if content.get("instructions"):
        lines.extend(_text_of(content["instructions"], lang).split("\n"))
    for number, field in enumerate(items, 1):
        if lines:
            lines.append("")
        attributes = [field["type"]]
        if "required" in field:
            attributes.append("required" if field["required"] else "optional")
        words = _words_attribute(field)
        if words:
            attributes.append(words)
        lines.extend(["## %d. %s (%s)" % (number, field["key"], ", ".join(attributes)), ""])
        lines.extend(_text_of(field["label"], lang).split("\n"))
        if field.get("options"):
            lines.append("")
            for option in field["options"]:
                lines.extend(_render_option("- %s:" % option["value"],
                                            _text_of(option["label"], lang)))
    return "\n".join(lines)


def render_bool(content, lang):
    lines = _text_of(content["question"], lang).split("\n")
    answers = (("yes", "yes-label", True), ("no", "no-label", False))
    if "yes-label" in content or "no-label" in content or "default" in content:
        lines.append("")
        for name, field, side in answers:
            mark = "x" if content.get("default") is side else " "
            label = _text_of(content[field], lang) if field in content else ""
            lines.extend(_render_option("- [%s] %s:" % (mark, name), label))
    return "\n".join(lines)


# ------------------------------------------------------------------ parsing

def _fence_mask(lines):
    """True for every line that sits inside (or is) a fenced code block."""
    mask = []
    inside = False
    for line in lines:
        if FENCE.match(line):
            mask.append(True)
            inside = not inside
        else:
            mask.append(inside)
    return mask


def _dedent(lines):
    """Drop the common indentation the renderer added under an option."""
    widths = [len(line) - len(line.lstrip(" ")) for line in lines if line.strip()]
    cut = min(widths) if widths else 0
    return [line[cut:] if line.strip() else "" for line in lines]


class _Reader:
    def __init__(self, text):
        self.lines = text.split("\n")
        self.fenced = _fence_mask(self.lines)
        self.at = 0
        self.errors = []

    def error(self, message, at=None):
        self.errors.append("line %d: %s" % ((self.at if at is None else at) + 1, message))

    def structural(self, pattern, at=None):
        at = self.at if at is None else at
        if at >= len(self.lines) or self.fenced[at]:
            return None
        return pattern.match(self.lines[at])

    def skip_blank(self):
        while self.at < len(self.lines) and not self.lines[self.at].strip():
            self.at += 1

    def read_text_until(self, *patterns):
        """Everything from here to the first structural line matching a pattern."""
        start = self.at
        while self.at < len(self.lines):
            if any(self.structural(pattern) for pattern in patterns):
                break
            self.at += 1
        return "\n".join(self.lines[start:self.at]).strip("\n")

    def read_option_label(self, head):
        """The label of the option just read, with the lines indented under it."""
        extra, pending = [], []
        while self.at < len(self.lines):
            line = self.lines[self.at]
            if self.fenced[self.at] or line.startswith("  "):
                extra.extend(pending)
                pending = []
                extra.append(line)
            elif not line.strip():
                pending.append("")
            else:
                break
            self.at += 1
        self.at -= len(pending)
        if not extra:
            return head
        return "\n".join(([head] if head else []) + _dedent(extra)).strip("\n")

    def read_options(self, pattern, marked=False):
        """The run of option lines starting here."""
        options = []
        while True:
            match = self.structural(pattern)
            if not match:
                break
            groups = match.groups()
            mark, value, head = (groups if marked else (None,) + groups)
            at = self.at
            self.at += 1
            label = self.read_option_label(head.rstrip())
            options.append({"value": value, "label": label, "correct": bool(mark)
                            and mark.lower() == "x", "at": at})
            self.skip_blank()
        return options


def _read_feedback(reader):
    start = reader.at
    while reader.at < len(reader.lines) and reader.lines[reader.at].startswith(">"):
        reader.at += 1
    if reader.at == start:
        return None
    lines = [line[2:] if line.startswith("> ") else line[1:]
             for line in reader.lines[start:reader.at]]
    return "\n".join(lines).strip("\n")


def _check_ids(reader, options, at, what):
    seen = set()
    for option in options:
        if not option["label"].strip():
            reader.error("%s '%s' has no text" % (what, option["value"]), option["at"])
        if option["value"] in seen:
            reader.error("%s id '%s' is used twice" % (what, option["value"]), at)
        seen.add(option["value"])


def _start_of_items(reader, pattern, what):
    """Refuse anything before the first heading, where nothing can belong."""
    reader.skip_blank()
    if reader.at < len(reader.lines) and not reader.structural(pattern):
        reader.error("the file must start with a %s heading" % what)
        return False
    return True


def parse_quiz(text):
    """Read a quiz.md. Returns (questions, errors)."""
    reader = _Reader(text)
    questions = []
    if not _start_of_items(reader, HEADING, "question, '## 1. <key>'"):
        return questions, reader.errors

    while reader.at < len(reader.lines):
        match = reader.structural(HEADING)
        if not match:
            reader.error("expected a question heading, '## <n>. <key>'")
            break
        at = reader.at
        number, key = int(match.group(1)), match.group(2)
        if number != len(questions) + 1:
            reader.error("question numbered %d, expected %d" % (number, len(questions) + 1))
        if key in RESERVED_KEYS:
            reader.error("key '%s' is produced by the quiz itself" % key)
        if key and any(question["key"] == key for question in questions):
            reader.error("key '%s' is used by another question" % key)
        reader.at += 1

        question = reader.read_text_until(CHOICE, HEADING)
        if not question.strip():
            reader.error("question has no text", at)
        options = reader.read_options(CHOICE, marked=True)
        if len(options) < 2:
            reader.error("question has %d option(s); it needs at least 2" % len(options), at)
        correct = [option for option in options if option["correct"]]
        if len(correct) != 1:
            reader.error("question has %d option(s) marked [x]; exactly one is correct"
                         % len(correct), at)
        _check_ids(reader, options, at, "option")
        feedback = _read_feedback(reader)
        reader.skip_blank()
        questions.append({"key": key, "question": question, "feedback": feedback,
                          "options": [{"value": option["value"], "label": option["label"],
                                       "correct": option["correct"]} for option in options]})
    return questions, reader.errors


def parse_form(text):
    """Read a form.md. Returns (fields, errors).

    Anything above the first field heading is the assignment, which a writing task
    needs and a plain form does without.
    """
    reader = _Reader(text)
    fields = _Fields()
    reader.skip_blank()
    fields.instructions = reader.read_text_until(FIELD_HEADING, HEADING)
    reader.skip_blank()
    if not reader.structural(FIELD_HEADING):
        reader.error("no field heading, '## 1. <key> (<type>)'"
                     + (" -- everything above was read as the instructions"
                        if fields.instructions else ""), 0)
        return fields, reader.errors

    while reader.at < len(reader.lines):
        match = reader.structural(FIELD_HEADING)
        if not match:
            if reader.structural(HEADING):
                reader.error("a field heading needs its type, '## <n>. <key> (<type>)'")
            else:
                reader.error("expected a field heading, '## <n>. <key> (<type>)'")
            break
        at = reader.at
        number, key = int(match.group(1)), match.group(2)
        attributes = [part.strip() for part in match.group(3).split(",") if part.strip()]
        if number != len(fields) + 1:
            reader.error("field numbered %d, expected %d" % (number, len(fields) + 1))
        if any(field["key"] == key for field in fields):
            reader.error("key '%s' is used by another field" % key)
        field_type = attributes[0] if attributes else ""
        if field_type not in FIELD_TYPES:
            reader.error("'%s' is not a field type; use one of %s"
                         % (field_type, ", ".join(FIELD_TYPES)))
        required, words = None, (None, None)
        for attribute in attributes[1:]:
            if attribute == "required":
                required = True
            elif attribute == "optional":
                required = False
            elif WORDS_BOTH.match(attribute):
                pair = WORDS_BOTH.match(attribute)
                words = (int(pair.group(1)), int(pair.group(2)))
            elif WORDS_MIN.match(attribute):
                words = (int(WORDS_MIN.match(attribute).group(1)), None)
            elif WORDS_MAX.match(attribute):
                words = (None, int(WORDS_MAX.match(attribute).group(1)))
            else:
                reader.error("'%s' is not a field attribute; use 'required', 'optional' "
                             "or a length such as '150-250 words'" % attribute)
        if words != (None, None) and field_type not in ("text-line", "text-area"):
            reader.error("a %s field counts no words" % field_type)
        reader.at += 1

        label = reader.read_text_until(OPTION, FIELD_HEADING, HEADING)
        if not label.strip():
            reader.error("field has no label", at)
        options = reader.read_options(OPTION)
        if field_type in WITH_OPTIONS and len(options) < 2:
            reader.error("a %s field needs at least 2 options" % field_type, at)
        if field_type in ("text-line", "text-area") and options:
            reader.error("a %s field takes no options" % field_type, at)
        _check_ids(reader, options, at, "option")
        reader.skip_blank()
        fields.append({"key": key, "type": field_type, "required": required, "label": label,
                       "min-words": words[0], "max-words": words[1],
                       "options": [{"value": option["value"], "label": option["label"]}
                                   for option in options]})
    return fields, reader.errors


def parse_bool(text):
    """Read a bool.md. Returns ([the question], errors) — one record, like the others."""
    reader = _Reader(text)
    question = reader.read_text_until(CHOICE)
    if not question.strip():
        reader.error("the file must start with the question", 0)
    answers = reader.read_options(CHOICE, marked=True)
    names = [answer["value"] for answer in answers]
    if names and names != ["yes", "no"]:
        reader.error("the answers are '- [ ] yes:' and '- [ ] no:', in that order",
                     answers[0]["at"])
        answers = []
    marked = [answer for answer in answers if answer["correct"]]
    if len(marked) > 1:
        reader.error("both answers are marked [x]; at most one is preselected",
                     answers[0]["at"])
    reader.skip_blank()
    if reader.at < len(reader.lines):
        reader.error("nothing can follow the answers")
    labels = {answer["value"]: answer["label"] for answer in answers}
    return [{"question": question,
             "yes-label": labels.get("yes") or None,
             "no-label": labels.get("no") or None,
             "default": (marked[0]["value"] == "yes") if marked else None}], reader.errors


# ------------------------------------------- what the languages must agree on

def quiz_shape(question):
    return (question["key"],
            tuple((option["value"], option["correct"]) for option in question["options"]),
            question["feedback"] is not None)


def form_shape(field):
    return (field["key"], field["type"], field["required"],
            field["min-words"], field["max-words"],
            tuple(option["value"] for option in field["options"]))


def bool_shape(record):
    return (record["default"], record["yes-label"] is None, record["no-label"] is None)


# --------------------------------------------- merging the languages into one

def _localized(per_lang, langs, position, pick):
    return [{"lang": lang, "text": pick(per_lang[lang][position])} for lang in langs]


def assemble_quiz(per_lang, langs):
    items = []
    for position, question in enumerate(per_lang[langs[0]]):
        item = {}
        if question["key"]:
            item["key"] = question["key"]
        item["question"] = _localized(per_lang, langs, position, lambda q: q["question"])
        item["options"] = [
            {"value": option["value"],
             "label": _localized(per_lang, langs, position,
                                 lambda q, i=index: q["options"][i]["label"]),
             "correct": option["correct"]}
            for index, option in enumerate(question["options"])]
        if question["feedback"] is not None:
            item["feedback"] = _localized(per_lang, langs, position, lambda q: q["feedback"])
        items.append(item)
    return {"items": items}


def assemble_form(per_lang, langs):
    items = []
    for position, field in enumerate(per_lang[langs[0]]):
        item = {"key": field["key"], "type": field["type"]}
        if field["required"] is not None:
            item["required"] = field["required"]
        item["label"] = _localized(per_lang, langs, position, lambda f: f["label"])
        for bound in ("min-words", "max-words"):
            if field[bound] is not None:
                item[bound] = field[bound]
        if field["options"]:
            item["options"] = [
                {"value": option["value"],
                 "label": _localized(per_lang, langs, position,
                                     lambda f, i=index: f["options"][i]["label"])}
                for index, option in enumerate(field["options"])]
        items.append(item)
    written = [lang for lang in langs if getattr(per_lang[lang], "instructions", "").strip()]
    if not written:
        return {"items": items}
    return {"instructions": [{"lang": lang, "text": per_lang[lang].instructions}
                             for lang in written], "items": items}


def assemble_bool(per_lang, langs):
    record = per_lang[langs[0]][0]
    content = {"question": _localized(per_lang, langs, 0, lambda r: r["question"])}
    for name, field in (("yes", "yes-label"), ("no", "no-label")):
        if record[field] is not None:
            content[field] = _localized(per_lang, langs, 0, lambda r, f=field: r[f])
    if record["default"] is not None:
        content["default"] = record["default"]
    return content


DOCUMENTS = {
    "quiz": {"file": "quiz.md", "render": render_quiz, "parse": parse_quiz,
             "shape": quiz_shape, "assemble": assemble_quiz, "item": "question",
             "structure": "key, option ids, which option is correct and whether there "
                          "is feedback"},
    "form": {"file": "form.md", "render": render_form, "parse": parse_form,
             "shape": form_shape, "assemble": assemble_form, "item": "field",
             "structure": "key, type, required/optional, any word limits and option ids"},
    "bool": {"file": "bool.md", "render": render_bool, "parse": parse_bool,
             "shape": bool_shape, "assemble": assemble_bool, "item": "question",
             "structure": "which answer is preselected and whether the answers are "
                          "reworded"},
}


def render(node_type, content, lang):
    """The document of one language, from the content of a node."""
    document = DOCUMENTS[node_type]
    if node_type in ("bool", "form"):
        return document["render"](content, lang)
    return document["render"](content.get("items") or [], lang)
