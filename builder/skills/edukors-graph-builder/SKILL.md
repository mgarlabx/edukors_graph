---
name: edukors-graph-builder
description: AUTHORING + GENERATION authority for Edukors courses. Covers (a) creating a course from scratch (info, nodes and edges from an idea, syllabus or brief) and (b) consolidating an exploded course (info/, nodes/, edges/) edited by hand. Either way it writes three files to the course's `_output/` folder, named after the slug: the course JSON (valid against course.schema.json), the map HTML and the player HTML. This skill owns the format (node types, edges, conditions, prompts, quality bar); the companion skill edukors-graph-editor defines the exploded folder layout. Use it whenever someone wants to create, design, extend, restructure, consolidate, build or rebuild a course, learning path, training module or adaptive track, or regenerate the JSON, map or player; also when they mention course.schema.json, Edukors, course nodes/edges, static-md/dynamic-md nodes, a grading rubric, an AI judgement of a student text, or a course graph, even if they only say "make me a course about X".
---

# Edukors Graph Builder

Turn an author's intent into a valid Edukors course: one JSON file where `nodes`
hold everything the student sees and does, and `edges` decide what comes next —
possibly a different next for each student.

The deliverable is always **three files** — the same course, seen three ways:

1. `<slug>-course.json` — the course itself, valid against `assets/course.schema.json`.
2. `<slug>-map.html` — the course graph, produced by embedding the JSON into `assets/course_viewer.html`.
3. `<slug>-player.html` — the course as the student meets it, produced by embedding the JSON into `assets/course_player.html`.

All three are presented to the author at the end, JSON first.

## Where this skill fits

Two skills split the work, and they are not interchangeable:

- **edukors-graph-editor** is the folder standard for *development*. The author
  works in the exploded folders — `info/`, `nodes/<node-id>/`, `edges/` — for as
  long as the course is being written and revised. That is where the source of
  truth lives.
- **edukors-graph-builder** (this skill) is the *generation* step. It defines the
  format itself and, at the end, assembles those folders into the three files
  above, written to the course's `_output/` folder, loose side by side.

So: edit in the fragmented folders, generate into `_output/`. Never hand-write
the course JSON as the primary artifact — it is a build output.

## Language rule

Work and think in English — prompts stored inside the course are written in
English too, because that is what the runtime AI reads best. But **every text the
author or the student will read is written in the author's language**: the
conversation, the summary, node titles, markdown bodies, quiz questions, form
labels and form instructions. If the author writes to you in Portuguese, the course
is authored in Portuguese (`source-language: "pt"`) and you answer in Portuguese.

The one exception is everything addressed to a model: `info.system-prompt` and
`dynamic-*` prompts are written in English and end with *"Answer in the student's
language."*; the `state`, `instructions` and `criteria` of a judgement are written
in English and carry no version per language, because no student ever sees them.

## Workflow

### 1. Collect the brief

Eight decisions define the course. Read them off the conversation first — authors
often give three or four in their opening message, and re-asking what was already
said is the fastest way to annoy them. Ask only for what is genuinely missing, in
one round, and offer a sensible default for each so the author can just say "all
defaults".

| # | Decision | What you need | Default if the author shrugs |
|---|----------|---------------|------------------------------|
| 1 | Size | number of nodes and number of sections | 12 nodes, 3 sections |
| 2 | Objectives | a syllabus, a list of competences, or learning outcomes | derive from the topic, state them back for confirmation |
| 3 | Theory base | attached files, URLs, an MCP server, or "search the web" | ask — never invent a bibliography |
| 4 | Type | traditional (sequential) or challenge-based (student builds a product) | traditional |
| 5 | Challenge details (only if 4 = challenge) | product type, delivery in parts or at the end, static or AI-generated challenge | report, delivered in parts, static |
| 6 | Content | static (written now) or dynamic (AI-generated per student from a form and/or assessment gaps) | static |
| 7 | Media | text only, or text plus images | text only |
| 8 | Path | linear, or adaptive graph (branching on assessment gaps and/or student choice) | linear with one adaptive branch |

Also settle title, author name and audience/level — one line, not an interrogation.

If an interactive option tool is available in this environment (e.g.
`ask_user_input_v0`), use it for decisions 1, 4, 6, 7 and 8, which are
multiple-choice by nature. Keep free text for objectives and the theory base.

Never start writing nodes while item 2 or item 3 is still unknown: a course
without objectives has nothing to sequence, and a course without a theory base
turns into plausible-sounding filler.

### 2. Build the theory base

- **Attached files**: read them. They are the primary source; quote concepts, not prose.
- **URLs**: fetch them.
- **MCP server**: query it if one is connected and relevant.
- **Web search**: only when the author asked for it or has no other source — search,
  then work from what you found. Prefer primary and academic sources.

Extract a working outline: the concepts, in the order they depend on each other.
This outline is what you distribute across nodes. If the sources are thin for part
of the syllabus, say so instead of padding.

### 3. Draft the blueprint before writing any JSON

Write a compact table — for yourself, and show it to the author when the course is
large — with one row per node:

```
id   type        section  title                     role / storage keys           next
sm1  static-md   1        Welcome and how it works  —                              f1
f1   form        1        What brings you here      f1.goal, f1.pain               sm2
...
```

Check the blueprint against the brief **before** producing JSON: the node count
matches item 1, the section count matches item 1, the type of every node follows
from items 4/6/7, and the branching follows item 8. Fixing a blueprint costs a
minute; fixing 600 lines of JSON costs an hour.

Allocation that works well for a course of N nodes in S sections:

- `sm1` is always the entry point: welcome, objectives, how the course works.
- If content or challenge is dynamic, the **second** node is the form `f1` that
  captures goals, pains and context. Everything dynamic downstream reads it.
- Per section: 2–4 content nodes, then one activity (quiz, bool, or a written
  delivery). **A written delivery costs three nodes, not one**: a `form` with the
  assignment and a `text-area`, a `score` that judges it, and a `dynamic-md` with
  `from` that writes the comment. Budget for that when you size the course.
- Each section's last activity is where branching happens, if the course adapts.
- Leave the last node as a closing node (summary, final delivery, or next steps).

### 4. Write the JSON

Read `references/schema-reference.md` before writing — it is the field-by-field
authoring reference (node contents, storage keys, conditions, id prefixes, and the
errors that actually happen).

Read `references/course-patterns.md` for the design recipes: traditional vs
challenge-based, dynamic personalization, images, and the adaptive-graph shapes.

Non-negotiables while writing:

- ids carry their type: `sm` static-md, `sh` static-html, `dm` dynamic-md,
  `dh` dynamic-html, `q` quiz, `f` form, `b` bool, `c` choice, `s` score, `n` noul.
- a course with any judgement node names its model exactly in `info.judge-model`
  (`jev-1.13.0`, never `jev-latest`): thresholds and points are tuned per version.
- every `choice`, `score` and `noul` node ends with an unconditional edge, and that
  edge never leads to a node with `from` pointing back at it — that path is the one
  taken when there was no judgement, so there would be nothing to write from.
- a level from a `score` runs 0 to `levels-1`, not 0–100. To grade out of 100, give
  the question `points` and branch on `<id>.percent`.
- every localized field is a list of `{lang, text}` covering `source-language`
  **and** every entry of `other-languages`.
- edges leaving a node are ordered: conditions first, the unconditional fallback
  last. Any edge after the fallback is dead.
- a condition may only test a key that some earlier node actually produces.
- in every quiz, the correct option changes position from question to question.
  Drafting puts the right answer first; shuffle each `options` list afterwards so
  no node — and no course — answers "always A".
- content nodes carry real teaching material, not headings. A static-md node is
  typically 150–500 words: explanation, a worked example, and why it matters.
  Three bullet points is a slide, not a course node.

Write the file to the working directory as `<slug>-course.json`. For a course of
more than ~15 nodes, write it section by section and append, rather than in one
enormous pass — a truncated write costs more than the extra steps, and the
validator will catch anything the assembly broke.

### 5. Validate, and fix until clean

```bash
python3 <skill-dir>/scripts/validate_course.py <slug>-course.json
```

It checks the schema rules, the graph (reachability, fallback ordering, dangling
edges), and the storage keys used in conditions and in `{{STORAGE: key}}`
references. Errors must all be fixed. Warnings are usually real problems too —
read each one and either fix it or be able to explain why it is intentional.

Never present a course that has not passed this script.

### 6. Open the course in all three views

An author judges a course by reading it, by seeing its shape, and by walking it.
Build both HTML files after the JSON validates:

```bash
python3 <skill-dir>/scripts/build_viewer.py <slug>-course.json -o <slug>-map.html
python3 <skill-dir>/scripts/build_player.py <slug>-course.json -o <slug>-player.html
```

- **The JSON** — the course itself, what gets imported into Edukors.
- **The map** — `<slug>-map.html`, the viewer with the course already embedded.
  No server, no load step, and nothing else to open: the file shows this course
  and only this course — its graph, the node panel and the file check. The viewer
  interface is in English; the course content inside it is shown in the course's
  own languages, switchable in the header when there is more than one.
- **The player** — `<slug>-player.html`, the course run as a student: a welcome
  screen, one step at a time, the trail of steps along the top, and the edges
  deciding what comes next from what the student answered. It renders every node
  type, keeps progress in the browser, and lets any finished step be revisited.
  Formulas are typeset with KaTeX, fetched from cdnjs the first time a step has
  one; without network they stay as the LaTeX source.
  Its interface follows the course language. `dynamic-md` and `dynamic-html` nodes
  ask the model of the host the file runs in, with `{{STORAGE: key}}` resolved
  from what the student produced. The player needs
  no key: it uses the host's `sample` capability when published as an Artifact
  that declares it, and otherwise the AI bridge of claude.ai chat artifacts.
  Opened anywhere else — a local file in a browser, an IDE preview, another
  harness — those steps show a note saying so and the course still runs.
  `choice`, `score` and `noul` nodes never ask a model here. They are invisible
  to a student, so this copy shows the author a panel instead: the question as
  written, the scale as declared, and the author picks a level and a confidence.
  That is what lets the author walk **every** branch of an adaptive course by
  opening one file, with no server and no key — and it is the answer to "how do I
  test this?". A node with `from` then writes its feedback from that pick, so the
  author sees what a given level actually produces.
  Outside the author's copy the player asks its host for the judgement, as a
  dynamic node asks for its text; the player server answers that, and nowhere
  else does. Where nothing answers, no judgement is made and none is invented:
  the node stores nothing and the student leaves by the unconditional edge. A
  judgement under the node's `confidence` counts the same way.
  The panel also has a **Download the request** button, which saves the call the
  node would make — `model`, `state` and `questions`, with every
  `{{STORAGE: key}}` already replaced by what the student produced — as
  `<node-id>-systemone.json`, to paste into the TypeSafe playground
  (https://console.typesafe.ai/playground). That turns the author's pick from a
  guess into a check of what the model actually answers.

Rebuild both after every change to the JSON, or the author reads a stale graph
and walks a stale course.

Present all three, JSON first, with the tool this environment provides for
sending files to the user (`present_files` when available). If no such tool
exists, tell the author the exact paths. Delivering fewer than three views is
incomplete work.

Where the player's AI steps can run depends on how it is presented:

- **claude.ai chat** (`present_files`): the player runs as a chat artifact and its
  AI steps work.
- **An environment with an `Artifact` publishing tool** (e.g. Claude Code): also
  publish `<slug>-player.html` with that tool, declaring
  `capabilities: {sample: {}}`, and give the author the link. That is the only
  way its AI steps run there; the viewer is asked once to allow it, and the calls
  spend the viewer's own Claude usage.
- **Anywhere else**: deliver the paths, and tell the author in one line that the
  dynamic steps show a note instead of content in a local preview. The `choice`, `score` and `noul` nodes work everywhere, including a
  local file: say so, because walking the branches is what the author needs.

### 7. Report

Keep it short and in the author's language: what was built (nodes, sections,
adaptive branches), the one or two design decisions worth knowing about, and an
offer to adjust. Do not re-list every node — it is in the map.

## Quality bar

Before presenting, check that:

- **The objectives are covered.** Walk item 2 of the brief and point each objective
  at the nodes that serve it. An uncovered objective means a missing node.
- **The theory base is visible.** A reader should recognise the sources in the
  content. Generic content that could have been written without the sources means
  the sources were not used.
- **Activities produce something the course uses.** A quiz whose score no edge and
  no prompt ever reads is decoration; either branch on it or give its questions
  `key`s and use them.
- **Every grade comes from a judgement.** No node shows the student a number that
  did not come out of `points` on a `score` question. A second opinion asked of a
  model in prose is exactly what this format removed.
- **No feedback argues with its grade.** Read each `from` prompt: it says how to
  write, never what to decide.
- **The quizzes cannot be guessed by position.** Read the correct slot of each
  question; if it is the same letter throughout, the quiz was never shuffled.
- **Every branch reunites.** Adaptive paths must come back to the main line (or
  reach a proper ending). Check the map: no node should trail off into nothing
  except the intended final node.
- **The map is readable.** Open the built HTML, look at the graph. If the shape is
  incomprehensible to you, it will be worse for the author.
- **The course plays.** The player opens on the welcome screen, the first step
  carries real content, and every activity can be answered and left. A step that
  cannot be completed is a broken node, not a detail. Where a delivery has word
  limits, check that a text inside the range is accepted and one outside it is not.

## Editing an existing course

When the author brings an existing course JSON and asks for changes, validate it
first to know its starting state, then edit in place: keep ids stable (a changed id
breaks every edge and every stored key that referenced it), bump `info.version`
(patch for fixes, minor for new nodes, major for restructuring) and set `info.date`
to today. Re-run steps 5 and 6.

## Files in this skill

- `references/schema-reference.md` — authoring reference for the format, with a complete miniature course. Read before writing JSON.
- `references/course-patterns.md` — design recipes for the eight brief decisions. Read while drafting the blueprint.
- `assets/course.schema.json` — the JSON Schema the course must satisfy.
- `assets/course_viewer.html` — viewer template used by `build_viewer.py`.
- `assets/course_player.html` — player template used by `build_player.py`. It carries a placeholder course, so the asset itself opens and can be inspected.
- `scripts/validate_course.py` — schema + graph + storage-key validation. No dependencies.
- `scripts/build_viewer.py` — embeds a course into a standalone viewer HTML. No dependencies.
- `scripts/build_player.py` — embeds a course into a standalone player HTML. No dependencies.
