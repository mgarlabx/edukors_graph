# Schema — the Edukors Graph standard

*Part of [Edukors Graph](../README.md).*

A course is a single JSON file that describes **what** the student sees (**nodes**) and **in which order** (**edges**). Because edges can carry conditions, the order is not fixed: the course adapts to each student.

The contract is [schema.json](schema.json), a JSON Schema (draft 2020-12). This file explains it; the field descriptions inside the schema are the authority. A course names the version it was written against, which is also what makes an editor validate it as it is typed:

```json
{
  "$schema": "https://edukors.org/graph/schema/v1/",
  "info":  { "...": "..." },
  "nodes": [ ],
  "edges": [ ]
}
```

Every course file has three parts:

- `info` — everything about the course as a whole.
- `nodes` — the content and activities of the course.
- `edges` — directed links between nodes that define the sequence.

No other top-level key is allowed, and the same holds almost everywhere else in the format: an invented field is an error, not an extra.

The course begins at the `start` node. Every other node is reached by following edges from there.

## info

| Field | Required | What it is |
| ----- | :------: | ---------- |
| `course-id` | yes | A UUID. It identifies the course across versions, platforms and providers. |
| `source-language` | yes | The language the course was written in: ISO 639-1, with an optional region (`pt`, `en`, `pt-BR`). |
| `other-languages` | yes | The languages it has been translated into. May be `[]`, and must not repeat the source language. |
| `title` | yes | The title, in each language. |
| `description` | no | A short summary in each language, for catalogues and listings. |
| `author` | yes | The person or institution responsible for the content. |
| `version` | yes | `MAJOR.MINOR.PATCH`. |
| `date` | yes | The date of this version, as `YYYY-MM-DD`. |
| `start` | yes | The id of the first node, and the only way into the course. |
| `sections` | no | Names for the groups the nodes are displayed in. Purely visual: they do not affect the order. |
| `system-prompt` | no | Course-wide instructions sent as the system prompt of every AI call the course makes. Audience, tone and global rules live here, so each node only carries what is specific to it. |

## Text in several languages

Every string a student or an author reads is a list with one entry per language:

```json
"title": [
  { "lang": "en", "text": "Fractions without tears" },
  { "lang": "pt", "text": "Frações sem sofrimento" }
]
```

The source language must always be present, and a course that declares `other-languages` should carry every one of them for each visible text. Prompts are the exception: they are instructions for the AI, not content for the student, so a single English entry is normal and the AI is told to answer in the student's language.

Where markdown is accepted, inline HTML and LaTeX are accepted too. LaTeX goes between `$…$` or `\(…\)` inside a line, and between `$$…$$` or `\[…\]` as a block. A lone `$` only opens a formula when it is not preceded by a letter or digit and not followed by a space, so amounts like `R$ 10` and `US$5` stay as written.

## Node types

| Type             | What it is                                                                                 | Produces stored data? |
| ---------------- | ------------------------------------------------------------------------------------------ | --------------------- |
| `static-md`    | Markdown written in advance, shown as written.                                             | No                    |
| `static-html`  | HTML built in advance, shown as built (rendered sandboxed).                                | No                    |
| `dynamic-md`   | A prompt the AI runs during the course; the markdown it returns is shown.                  | No                    |
| `dynamic-html` | A prompt the AI runs during the course; the HTML it returns is shown (rendered sandboxed). | No                    |
| `essay`        | The student writes a text and the AI grades it.                                            | Yes                   |
| `quiz`         | A set of multiple-choice questions.                                                        | Yes                   |
| `form`         | A form the student fills in.                                                               | Yes                   |
| `bool`         | A yes/no question, usually asked to choose between two paths.                              | Yes                   |

Node ids carry their type as a prefix: `sm1` (static-md), `sh1` (static-html), `dm1` (dynamic-md), `dh1` (dynamic-html), `e1` (essay), `q1` (quiz), `f1` (form), `b1` (bool).

### Stored data

Essay, quiz, form and bool nodes save their results under keys named `<node-id>.<name>`:

- An **essay** `e1` produces `e1.text` (what the student wrote), `e1.score` (0–100, graded by the AI) and `e1.feedback`.
- A **quiz** `q1` produces `q1.score`, `q1.total` and `q1.percent` (0–100). A question with a `key` also stores the value of the chosen option, e.g. `q1.fractions`.
- A **form** `f1` produces one key per field, e.g. `f1.goal`.
- A **bool** `b1` produces `b1.answer`, which is `true` when the student answered yes and `false` when the student answered no.

These keys have two uses:

1. **In prompts** — `dynamic-md`, `dynamic-html` and essay nodes can embed stored values with `{{STORAGE: key}}`, e.g. `Write a study plan for a student whose goal is: {{STORAGE: f1.goal}}`. A key the student has not produced yet becomes an empty text, and the list of a `check` field becomes its values separated by `, `. The course-wide `system-prompt` is sent as written, without this replacement.
2. **In edge conditions** — they are what makes the course adaptive.

### Essay grading

The grading `prompt` of an essay receives the student's text after a line reading `--- STUDENT TEXT ---`, and must ask the AI to answer with a JSON object:

```json
{ "score": 85, "feedback": "Clear definition; add an example of your own." }
```

The score is rounded and kept between 0 and 100. When no such object can be read from the answer, there is no grade and the whole answer is shown as the feedback.

### Yes/no questions

A `bool` node asks a single question and offers two answers. Its `content` carries the `question` and, when the default wording of the frontend is not specific enough, a `yes-label` and a `no-label`:

```json
{
  "id": "b1",
  "type": "bool",
  "title": [{ "lang": "en", "text": "Go deeper?" }],
  "content": {
    "question": [{ "lang": "en", "text": "Do you want to know more about this topic?" }],
    "yes-label": [{ "lang": "en", "text": "Yes, take me through the detailed track" }],
    "no-label":  [{ "lang": "en", "text": "No, keep it short" }]
  }
}
```

An optional `default` (`true` or `false`) preselects one of the answers; without it the student must choose before moving on.

The answer is stored in `b1.answer` as `true` or `false`, and the two edges leaving the node turn it into a fork — the detailed track on yes, the short one on no:

```json
"edges": [
  { "from": "b1", "to": "sm5", "when": { "key": "b1.answer", "operator": "eq", "value": true } },
  { "from": "b1", "to": "sm9" }
]
```

## Adaptive learning

Edges leaving a node are evaluated **from top to bottom**, and the **first one whose `when` holds** is the path taken. An edge without `when` always holds, so it works as the fallback and should come last.

A `when` is a comparison between a stored key and a value, using one of these operators: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`, `not-contains`. Comparisons can be combined with `and` / `or` (which can be nested).

A condition on a key the student has not produced yet **never holds**, so an edge can safely test a node that may not have been answered.

An edge may lead **back** to a node already visited — "study again, then retake". Coming back is a new visit: essay, quiz, form and bool nodes are answered again and overwrite their keys, while dynamic nodes keep the content already generated. Every cycle needs a way out that the student can reach.

### Example

After quiz `q1`, strong students move on to `sm2` while the others get a review node first:

```json
"edges": [
  { "from": "q1",  "to": "sm2", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
  { "from": "q1",  "to": "sm3" },
  { "from": "sm3", "to": "sm2" }
]
```
