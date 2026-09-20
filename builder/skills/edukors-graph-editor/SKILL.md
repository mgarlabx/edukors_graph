---
name: edukors-graph-editor
description: FOLDER STANDARD for Edukors courses during development: every course lives exploded as info/, nodes/ (one folder per node id, content split by language into .md/.html files), edges/, plus the generated _output/ holding the JSON, map and player files side by side. Typically the author works in these fragmented folders through the whole process, and only at the end does the companion skill **edukors-graph-builder** generate the JSON, map and player into `_output/`. Use this skill whenever a course in my_courses is created, opened, edited, reviewed, split or rebuilt, whenever someone asks where a node's text lives, and before writing any course JSON by hand.
---

# Edukors Graph Editor — course folder architecture

A course is **not** a file here. A course is a folder, and the single
`*-course.json` that Edukors imports is a *build artifact* of that folder.

The reason is editing: a 600-line JSON with markdown crammed into `"text"`
strings cannot be read, diffed or edited by a human — or by a model without
rewriting the whole file. Exploded, each node's prose is a real `.md`/`.html`
file that can be opened, edited and reviewed on its own, and the JSON is
regenerated in one command.

The authoring rules of the format itself (node types, storage keys, conditions,
quality bar) live in the **edukors-graph-builder** skill. This skill defines
only *where things are stored* and *how the JSON is produced from them*.

The division of labour, in the order it happens: the author spends the whole
development process inside these fragmented folders (this skill), and only at
the end does **edukors-graph-builder** generate the JSON, map and player into
`_output/`. Anything under `_output/` is disposable — regenerate it, never edit
it.

## The layout

```
<Course Title>/                     ← one folder per course, human-readable name
├── info/
│   └── info.json                   ← the `info` object, alone
├── nodes/
│   └── <node-id>/                  ← one folder per node, name = the node id
│       ├── node.json               ← the node, minus its externalized prose
│       └── content/                ← only for the types that externalize prose
│           ├── <lang>/             ← one folder per language
│           │   └── item.md         ← the prose, loose in a .md or .html file
│           └── prompt.md           ← non-localized text (the prompts)
├── edges/
│   └── <from-node-id>.json         ← the edges leaving that node, ordered
└── _output/                        ← everything GENERATED, nothing hand-written
    ├── <slug>-course.json          ← GENERATED from info + nodes + edges
    ├── <slug>-map.html             ← GENERATED from the json
    └── <slug>-player.html          ← GENERATED from the json
```

`_output/` is **generated output**, all of it. Never edit anything inside it by
hand: the next build overwrites it, and an edit made there is an edit that
silently disappears. Everything a human changes lives in `info/`, `nodes/` and
`edges/` — the one folder name separates the two, so the source of a course is
the three folders beside `_output/` and nothing else. The build recreates
`_output/` from scratch, so deleting it loses nothing.

`<slug>` is the kebab-case of the **course folder name**, e.g.
`World Cats 1 (short)/` → `_output/world-cats-1-short-course.json`,
`_output/world-cats-1-short-map.html`, `_output/world-cats-1-short-player.html`.
The three files sit loose in `_output/`, with no subfolders. Renaming the folder
renames the three outputs on the next build; delete the old ones (or the whole
`_output/`, which the build rebuilds).

## What goes where

### info/

`info/info.json` holds exactly the `info` object of the schema — no wrapper, no
nodes, no edges — and **never a `"$schema"` line**. It is the only file in
`info/`.

```json
{
  "course-id": "5a959482-32c3-4d75-b0c9-3d0025951f67",
  "source-language": "en",
  "other-languages": ["pt"],
  ...
}
```

The `$schema` declaration belongs to the generated course JSON alone, and the
build writes it there from a single constant, `SCHEMA` in `scripts/_layout.py`.
Repeating it in `info.json` would break the editor: VS Code honours `$schema` in
any JSON file, fetches that URL and validates the file against it — and the
course schema demands `info`, `nodes` and `edges`, which a bare `info` object
will never have. The result is a file permanently marked invalid for a
declaration that is not even used from there. A `$schema` left over in an older
`info.json` is dropped with a warning; delete the line.

To move the whole repository to a new schema version, edit that one constant. A
course that declared a different URL before being split gets the constant on its
first rebuild — `split_course.py` warns when it sees one, so the change is never
silent.

### nodes/

One folder per node, **named exactly as the node id** (`sm1/`, `q1/`, `dm2/`).
Inside, `node.json` is the node object with its externalized prose removed, and
`content/` holds that prose as files: one folder per language for what the
student reads, and the prompts loose above those folders.

Which field is externalized, and to which file:

| Node type | Field taken out of `node.json` | File |
|-----------|-------------------------------|------|
| `static-md` | `content.item` | `content/<lang>/item.md` |
| `static-html` | `content.item` | `content/<lang>/item.html` |
| `essay` | `content.instructions` | `content/<lang>/instructions.md` |
| `essay` | `content.prompt` (plain string, not localized) | `content/prompt.md` |
| `dynamic-md`, `dynamic-html` | `content.prompt` (one text, not translated) | `content/prompt.md` |
| `quiz` | `content` entire — the activity as a document | `content/<lang>/quiz.md` |
| `form` | `content` entire — the activity as a document | `content/<lang>/form.md` |
| `bool` | `content` entire — the activity as a document | `content/<lang>/bool.md` |
| `choice`, `score`, `noul` | nothing — `content` stays in `node.json` | — |

The judgement nodes are the exception on purpose. Their `state`, `instructions`
and `criteria` are instructions for the AI, never translated, and the option
names and levels are compared by the edges — so the JSON *is* the readable form,
and splitting it across files would only put distance between an option and the
edge that tests it. The round trip is exact either way.

Three rules behind the table:

- **A prompt is prose, not output.** `dynamic-html` generates HTML, but the
  prompt that generates it is instructions in prose, so it is `.md`. The `.html`
  extension is only for content that *is* HTML: `static-html`. And a prompt is
  long prose — 100 to 300 words of headings, lists and `{{STORAGE: key}}`
  references — which is exactly the text a JSON string destroys: one endless line
  of `\n` and escaped quotes, unreadable and undiffable. Prompts live in files.
- **Non-localized text sits above the language folders.** Both prompts of the
  format are addressed to the model, not to the student: the essay grading prompt
  and the prompt of a `dynamic-*` node, which tells the model to answer in the
  student's language and is therefore never translated. Both are
  `content/prompt.md`, beside the `<lang>/` folders rather than inside one — a
  `content/<lang>/prompt.md` would be a folder wrapping a single file forever.
- **An activity is a document, not a field.** A quiz, a form and a bool are not
  prose with a structure around it — they *are* the structure, and it only means
  anything read whole: a question with its options and its feedback, a field with
  its choices. In the JSON the languages interleave item by item, so the author
  can never read one through in any single language; a 4-question quiz in three
  languages is 443 lines nobody proofreads. So their whole `content` leaves
  `node.json` and becomes one document per language — 43 readable lines each.
  Their syntax is below.

The two prompts differ in one detail, because the schema does. The essay's is a
plain string, so `content/prompt.md` is the whole of it. A `dynamic-*` prompt is
a localized list holding a single entry, so the build has to give that entry a
language: **`content/prompt.md` is English**, and a prompt written in another
language is `content/prompt.<lang>.md` — `content/prompt.pt-BR.md` builds
`[{ "lang": "pt-BR", "text": … }]`. Prefer plain `prompt.md`: the schema itself
recommends writing prompts in English and instructing the model to answer in the
student's language, and the suffix exists so that a course that does otherwise is
visible in its file names instead of silently relabelled. Two prompt files in one
node folder is an error, not a translation.

`node.json` does **not** keep a placeholder for what was externalized: the files
are the only source. One fact, one place — a pointer field would just be a second
copy to fall out of sync.

One language folder per language of the course: `info.source-language` plus every
entry of `info.other-languages`, and no others. A missing folder is a missing
translation and the build refuses it, with no exception: a language folder only
ever holds text the student reads, and that text exists in every language. The
prompts are not affected — they never live in a language folder.

#### The activity documents

`content/<lang>/quiz.md`, `form.md` and `bool.md` hold the whole activity in one
language, and `scripts/content_md.py` is the only place that knows their syntax.

**quiz.md** — one heading per question:

```markdown
## 1. habitat

Which of these cats lives in the mountains of Central Asia and cannot roar?

- [ ] jaguar: Jaguar
- [x] snow-leopard: Snow leopard
- [ ] lion: Lion

> The snow leopard lives in the Himalayas. Although it is a Panthera, its
> throat does not allow a full roar.
```

The heading is the question number and then its `key`, which a question without
one simply omits (`## 3.`). Everything between the heading and the first option
is the question. Each option is `- [ ] <value>: <label>`, where `[x]` marks the
correct one and `<value>` is the stable id that gets stored and that the `when` of
an edge compares against. The trailing blockquote is the `feedback`, and a
question without feedback has none.

**form.md** — one heading per field, carrying its type:

```markdown
## 1. interest (radio, required)

Which group of cats do you want to explore now?

- big: Big cats
- small: Small wild cats
- domestic: Domestic cats

## 2. country (text-line, optional)

Which country are you from?
```

The parenthesis is the field's `type` — `text-line`, `text-area`, `radio`,
`check` or `select` — and then, when the node says so, `required` or `optional`.
Saying neither leaves `required` out of the JSON altogether, which the schema
reads as optional; `optional` writes it explicitly as `false`, so a field that
was written that way stays that way. The body is the `label`, and the options are
`- <value>: <label>`, with no checkbox, because a form has no right answer. Only
`radio`, `check` and `select` take options, and they need at least two; the text
types take none.

**bool.md** — the question, and the two answers only when they are reworded:

```markdown
Do you want to see the advanced track?

- [ ] yes: Yes, take me deeper
- [x] no: No, keep it short
```

The text before the answers is the `question`. The `yes:` and `no:` lines carry
`yes-label` and `no-label`, the wording that replaces the frontend's own; a bool
that keeps the default wording and preselects nothing is just its question, with
no list at all. `[x]` is `default`, the preselected answer — at most one, and
neither marked means the student has to choose, which is what the schema
intends.

All three are markdown throughout and may hold images, tables and LaTeX — the
player typesets `$…$` and `$$…$$` with KaTeX. Two rules keep long content
unambiguous:

- **A fenced code block is content, never structure.** A `##` or a `- [x] a: b`
  inside ``` fences is text, so a quiz can ask about markdown or shell without
  the parser mistaking the example for a question or a field.
- **An indented line continues the option above it.** An option whose label needs
  an image, display math or several lines writes them indented under it; the
  indentation is stripped when the label is read back.

```markdown
- [x] curva-b: ![Gaussian decay](https://example.org/b.png)

      $$y = e^{-x^{2}}$$
```

So the only thing that cannot be written at column zero, unfenced, is a line that
looks like a heading or an option — the same line that would need indenting or
fencing to render correctly anyway. A `:` inside a label is safe, including
LaTeX set-builder notation: the cut is at the first `: ` *after a valid id*, and
an id is only lowercase letters, digits and hyphens.

**The structure belongs to the source language.** Everything that is not text is
repeated in every language file — they have to be, for each file to be readable
on its own: a quiz's keys, option ids and which option is correct; a form's keys,
types, `required` and option ids; a bool's preselected answer. So the build reads
the structure from the file of `info.source-language` and refuses any other
language that disagrees — a different number of items, a different order, a
renamed id, a changed type, another option marked `[x]`. Translating is free;
reshaping an activity in one language only is an error, not a merge.

### edges/

One file per **source** node: `edges/q1.json` is the ordered list of edges
leaving `q1`.

```json
[
  { "from": "q1", "to": "sm4", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
  { "from": "q1", "to": "sm3" }
]
```

Order inside the file is the evaluation order — conditions first, the
unconditional fallback **last**. That invariant is what the split buys: the
ordering that matters is local to one small file instead of buried in a
200-entry global array. Every `from` in the file must equal the file's name; the
build checks it. A node with no outgoing edges (the final node) has no file.

## Working on a course

**Regenerate after every change.** Everything under `_output/` is stale the
moment a node file is touched, and a stale map is worse than no map.

```bash
python3 .claude/skills/edukors-graph-editor/scripts/build_course.py "<Course Title>"
```

That one command assembles the JSON into `_output/`, runs the validator, and
builds the map and the player beside it — it locates the edukors-graph-builder
scripts on its own (pass `--builder-dir` if it cannot). Use `--json-only` to skip
the HTML while iterating. It exits non-zero on any error; a course that does not build is not
done.

**Building is not testing.** Tell the author to open `_output/<slug>-player.html`
and walk the course. Everything works from a double-clicked file except the steps
the AI writes — `dynamic-md`, `dynamic-html` and the grading of an `essay` — which
show a note instead of content outside an AI host. The `choice`, `score` and
`noul` nodes do work there: each shows a panel with its question and the answers
it allows, and the author picks, which is how every branch of an adaptive course
gets walked without a server.

**Importing an existing single-file course** — including everything under
`other/` and `samples/`, which are still flat files:

```bash
python3 .claude/skills/edukors-graph-editor/scripts/split_course.py <path-to>-course.json -o "<Course Title>"
```

It writes the full folder structure and copies the original into `_output/`. The
round trip is faithful: splitting and rebuilding reproduces the same `info`, the
same nodes and the same edges, down to the text. The one thing it normalizes is
the order of the `nodes` and `edges` arrays, which carries no meaning in the
format — only the order *within* one edge file does, and that is preserved.

**Creating a course from scratch**: author it with the edukors-graph-builder
skill, then split the result into this layout and delete the loose file. Do not
hand-write the exploded folders node by node — writing prose into `.md` files is
pleasant, but getting `info.json`, the eight `content` shapes and the edge
ordering right by hand is the part the scripts already do.

**Editing an existing course**: change only `info/`, `nodes/`, `edges/`; then
rebuild. Never edit anything under `_output/` — not even "just this once",
because the next build throws it away.

## Invariants the build enforces

- a node folder's name equals the `id` inside its `node.json`;
- the id prefix matches the type (`sm`/`sh`/`dm`/`dh`/`e`/`q`/`f`/`b`);
- every language of the course has a file for every externalized field, and no
  language folder that the course does not declare;
- no leftover `content` key in `node.json` for a field that was externalized, and
  no leftover `content/` folder on a node whose type externalizes nothing
  (`form`, `bool`);
- exactly one `content/prompt*.md` in a `dynamic-*` node folder;
- every `quiz.md`, `form.md` and `bool.md` parses, numbers its items 1..n and
  repeats no `key` or option id; a quiz question has at least two options and
  exactly one `[x]`; a form field has a known type, and options only if its type
  takes them; a bool has `yes` and `no`, in that order, and at most one `[x]`;
- every language of an activity agrees with the source language on its structure;
- every `from` in an edge file matches the file name, and every `to` and `from`
  names an existing node folder;
- `info.start` names an existing node;
- `info.json` carries no `$schema` (a leftover one is dropped with a warning).

Node order in the generated JSON is the reading order of the course: breadth
first from `info.start`, following the edges, with unreachable nodes appended
last (and reported, since an unreachable node is almost always a missing edge).

## Files in this skill

- `scripts/build_course.py` — exploded folder → the JSON, map and player in `_output/`. No dependencies.
- `scripts/split_course.py` — single course JSON → exploded folder. No dependencies.
- `scripts/_layout.py` — the layout rules both scripts share (the mapping table above, in code).
- `scripts/content_md.py` — the syntax of `content/<lang>/{quiz,form,bool}.md`: render() writes them, parse() reads them back.
