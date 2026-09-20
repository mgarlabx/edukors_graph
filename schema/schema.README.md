# Schema — the Edukors Graph standard

*Part of [Edukors Graph](../README.md).*

A course is a single JSON file that describes the **steps** a student goes through (**nodes**) and **in which order** they come (**edges**). Because edges can carry conditions, the order is not fixed: the course adapts to each student.

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
| `choice`       | The AI picks one of the options the author listed, and never anything else.                 | Yes                   |
| `score`        | The AI places the student on a scale of levels the author wrote.                            | Yes                   |
| `noul`         | The AI answers a yes/no question with the probability that the answer is yes.               | Yes                   |

Node ids carry their type as a prefix: `sm1` (static-md), `sh1` (static-html), `dm1` (dynamic-md), `dh1` (dynamic-html), `e1` (essay), `q1` (quiz), `f1` (form), `b1` (bool), `c1` (choice), `s1` (score), `n1` (noul). The nodes that store data carry a one-letter prefix, the ones that only show content carry two.

The last three are the only nodes the student never sees. They are passed through: the AI judges what the student has produced so far, the answer is stored, and the course carries on. They are described under [Judgements by the AI](#judgements-by-the-ai).

### Stored data

Seven of the eleven node types save their results under keys named `<node-id>.<name>`. The first four are answered by the student:

- An **essay** `e1` produces `e1.text` (what the student wrote), `e1.score` (0–100, graded by the AI) and `e1.feedback`.
- A **quiz** `q1` produces `q1.score`, `q1.total` and `q1.percent` (0–100). A question with a `key` also stores the value of the chosen option, e.g. `q1.fractions`.
- A **form** `f1` produces one key per field, e.g. `f1.goal`.
- A **bool** `b1` produces `b1.answer`, which is `true` when the student answered yes and `false` when the student answered no.

The other three are answered by the AI, one key per question named in the node:

- A **choice** `c1` produces `c1.track` (the name of the option picked) and `c1.track-confidence` (0–1).
- A **score** `s1` produces `s1.evidence` (the level reached) and `s1.evidence-confidence` (0–1).
- A **noul** `n1` produces `n1.ready` (the probability that the answer is yes, 0–1). There is no separate confidence key: the probability is already it.

Watch the scales, which are not the same. `e1.score` and `q1.percent` run from 0 to 100. A score node runs over the levels of its own question — 0 to 2 on a scale of three — and is **not a whole number**, because it averages the levels the AI weighed. A noul and any `-confidence` key run from 0 to 1.

These keys have two uses:

1. **In prompts** — `dynamic-md`, `dynamic-html` and essay nodes, and the `state` and `instructions` of the three judgement nodes, can embed stored values with `{{STORAGE: key}}`, e.g. `Write a study plan for a student whose goal is: {{STORAGE: f1.goal}}`. A key the student has not produced yet becomes an empty text, and the list of a `check` field becomes its values separated by `, `. The course-wide `system-prompt` is sent as written, without this replacement.
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

## Judgements by the AI

A `bool` node branches on what the student *says* about themselves. A `choice`, `score` or `noul` node branches on what the AI *reads* in what the student has already produced. They are the same three shapes, with the AI answering instead of the student:

| Answered by the AI | Answered by the student | The answer is |
| ------------------ | ----------------------- | ------------- |
| `choice`           | a `form` with `radio`   | the name of one of the options listed |
| `score`            | `quiz.percent`, `essay.score` | a position on a scale of levels |
| `noul`             | `bool`                  | the probability that the answer is yes, 0–1 |

The student never stops at one of these nodes. They are passed through: the AI is asked, the answer is stored, and the course carries on along the first edge whose condition that answer satisfies.

The answer is always **inside the list the author wrote**. A choice node can only return one of its own options, and a score node only a position on its own scale — never something else, and never free text that has to be parsed back into shape.

### How one is written

All three carry the same two fields:

- **`state`** — what the AI is given to judge, built with `{{STORAGE: key}}`. Only what is named here is sent.
- **`items`** — the questions. Each has a `key` (which becomes the storage key), an `instructions` (the question itself) and a `criteria` (the answers allowed).

`state`, `instructions` and `criteria` are instructions for the AI, not content for the student, so they are plain strings with no version per language — like the grading `prompt` of an essay. Only the node's `title` is translated, because it appears on the course map.

All the questions of a node are judged **together, in one call**, against the same `state`, and none of them sees the answer of another. Asking three narrow questions costs almost nothing over asking one, and gives a course that is easier to adjust afterwards: change the threshold in an edge rather than rewrite a prompt.

A node holds one kind of question only. To ask a `choice` and a `score` at the same point, chain two nodes.

### choice

```json
{
  "id": "c1",
  "type": "choice",
  "title": [{ "lang": "en", "text": "Pick the track" }],
  "content": {
    "state": "What the student wrote:\n{{STORAGE: e1.text}}",
    "items": [
      {
        "key": "track",
        "instructions": "Which track does this student need next?",
        "criteria": {
          "remedial": "Confuses the basic concepts and needs them again",
          "standard": "Has the essentials and can carry on",
          "advanced": "Goes beyond what was taught",
          "unclear":  "The text is too short or too off-topic to tell"
        }
      }
    ]
  }
}
```

The names of the options (`remedial`, `standard`…) are what get stored and what the edges compare against, so they must not change with the language of the course — the same rule as the `value` of a quiz or form option. Include an option like `unclear` when the state may fit none of the others: the AI has to answer with one of them, and has nowhere else to put a case the list forgot.

### score

`criteria` is the scale, in order, from the low end to the high end. The position is the number of the level, so the first one listed is level 0:

```json
{
  "id": "s1",
  "type": "score",
  "title": [{ "lang": "en", "text": "Measure the essay" }],
  "content": {
    "state": "What the student wrote:\n{{STORAGE: e1.text}}",
    "items": [
      {
        "key": "evidence",
        "instructions": "How well does the student back their claims?",
        "criteria": [
          "No evidence given for the claims",
          "Claims backed by one example",
          "Claims backed by several examples, weighed against each other"
        ]
      }
    ]
  }
}
```

The stored `s1.evidence` is **not a whole number**: it averages the levels the AI weighed, so `1.43` on this scale is an ordinary answer meaning "between the second and the third, nearer the second". Compare it with `gte` and `lt`, not with `eq`.

To judge several things at once, give each its own question and join them in an edge with `and`. That is better than one question trying to weigh everything: when the priorities change, the numbers in the edge change, not the wording of a prompt.

### noul

A yes/no question, answered with the probability that the answer is yes. `criteria` is optional and only says what a yes and a no cover:

```json
{
  "id": "n1",
  "type": "noul",
  "title": [{ "lang": "en", "text": "Ready to move on?" }],
  "content": {
    "state": "{{STORAGE: e1.text}}",
    "items": [
      {
        "key": "ready",
        "instructions": "Is this student ready for the next part?",
        "criteria": {
          "true":  "The student names the mechanism, even loosely",
          "false": "The student only restates the result"
        }
      }
    ]
  }
}
```

The edges compare `n1.ready` against a threshold you pick: `0.5` when both answers are equally easy to act on, higher when acting on a wrong yes costs more, lower when missing a true yes costs more.

### Fallback is not optional

A judgement node decides nothing on its own — the edges leaving it do. **One of them must be unconditional**, because the call can fail and because a judgement can land anywhere in its range. When no answer is stored, no condition holds (see [Adaptive learning](#adaptive-learning)) and the student takes that edge.

Use the confidence when the wrong path is expensive: send the student down the demanding track only when the AI is both sure of the option and sure of itself.

```json
"edges": [
  { "from": "c1", "to": "sm5",
    "when": { "key": "c1.track", "operator": "eq", "value": "remedial" } },
  { "from": "c1", "to": "sm7",
    "when": { "and": [
      { "key": "c1.track",            "operator": "eq",  "value": "advanced" },
      { "key": "c1.track-confidence", "operator": "gte", "value": 0.8 }
    ] } },
  { "from": "c1", "to": "sm6" }
]
```

## Adaptive learning

Edges leaving a node are evaluated **from top to bottom**, and the **first one whose `when` holds** is the path taken. An edge without `when` always holds, so it works as the fallback and should come last.

A `when` is a comparison between a stored key and a value, using one of these operators: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`, `not-contains`. Comparisons can be combined with `and` / `or` (which can be nested).

A condition on a key the student has not produced yet **never holds**, so an edge can safely test a node that may not have been answered.

An edge may lead **back** to a node already visited — "study again, then retake". Coming back is a new visit: essay, quiz, form and bool nodes are answered again, and choice, score and noul nodes are judged again, so either way the new answers overwrite the old keys, while dynamic nodes keep the content already generated. Every cycle needs a way out that the student can reach.

### Example

After quiz `q1`, strong students move on to `sm2` while the others get a review node first:

```json
"edges": [
  { "from": "q1",  "to": "sm2", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
  { "from": "q1",  "to": "sm3" },
  { "from": "sm3", "to": "sm2" }
]
```
