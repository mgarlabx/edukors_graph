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

| Field               |        Required        | What it is                                                                                                                                                                                                                                                   |
| ------------------- | :--------------------: | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `course-id`       |          yes          | A UUID. It identifies the course across versions, platforms and providers.                                                                                                                                                                                   |
| `source-language` |          yes          | The language the course was written in: ISO 639-1, with an optional region (`pt`, `en`, `pt-BR`).                                                                                                                                                      |
| `other-languages` |          yes          | The languages it has been translated into. May be`[]`, and must not repeat the source language.                                                                                                                                                            |
| `title`           |          yes          | The title, in each language.                                                                                                                                                                                                                                 |
| `description`     |           no           | A short summary in each language, for catalogues and listings.                                                                                                                                                                                               |
| `author`          |          yes          | The person or institution responsible for the content.                                                                                                                                                                                                       |
| `version`         |          yes          | `MAJOR.MINOR.PATCH`.                                                                                                                                                                                                                                       |
| `date`            |          yes          | The date of this version, as`YYYY-MM-DD`.                                                                                                                                                                                                                  |
| `start`           |          yes          | The id of the first node, and the only way into the course.                                                                                                                                                                                                  |
| `sections`        |           no           | Names for the groups the nodes are displayed in. Purely visual: they do not affect the order.                                                                                                                                                                |
| `system-prompt`   |           no           | Course-wide instructions sent as the system prompt of every call that generates content. Audience, tone and global rules live here, so each node only carries what is specific to it. It does not reach the judgement nodes, which take no system prompt. |

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

| Type             | Id prefix | What it is                                                                                 | Produces stored data? |
| ---------------- | --------- | ------------------------------------------------------------------------------------------ | --------------------- |
| `static-md`    | `sm`    | Markdown written in advance, shown as written.                                             | No                    |
| `static-html`  | `sh`    | HTML built in advance, shown as built (rendered sandboxed).                                | No                    |
| `dynamic-md`   | `dm`    | A prompt the AI runs during the course; the markdown it returns is shown.                  | Yes                   |
| `dynamic-html` | `dh`    | A prompt the AI runs during the course; the HTML it returns is shown (rendered sandboxed). | Yes                   |
| `quiz`         | `q`     | A set of multiple-choice questions.                                                        | Yes                   |
| `form`         | `f`     | A form the student fills in — including a writing task.                                   | Yes                   |
| `bool`         | `b`     | A yes/no question, usually asked to choose between two paths.                              | Yes                   |
| `choice`       | `c`     | The AI picks one of the options the author listed, and never anything else.                | Yes                   |
| `score`        | `s`     | The AI places the student on a scale of levels the author wrote.                           | Yes                   |
| `noul`         | `n`     | The AI answers a yes/no question with the probability that the answer is yes.              | Yes                   |

### The node types, one by one

Every node carries the same fields; only `content` changes shape with the type:

- `id` — unique in the course, and starts with the prefix of its type (`sm1`, `q2`).
- `type` — one of the ten types below.
- `title` — the name of the node in each language, as plain text. It appears on the course map.
- `content` — what the node shows or does.
- `section` — optional, 1 by default. It sets the group the node is displayed in (see `info.sections`).

#### static-md

**What it is for:** anything written in advance that every student reads the same way, such as explanations, instructions, worked examples and summaries. It is the most common node, and the cheapest one: nothing runs and nothing is stored.

`content.item` holds the markdown, in each language of the course:

```json
{
  "id": "sm1",
  "type": "static-md",
  "title": [{ "lang": "en", "text": "What a fraction is" }],
  "content": {
    "item": [
      { "lang": "en", "text": "## What a fraction is\n\nA fraction $\\frac{a}{b}$ names **a** parts of a whole cut into **b** equal parts." },
      { "lang": "pt", "text": "## O que é uma fração\n\nUma fração $\\frac{a}{b}$ indica **a** partes de um todo dividido em **b** partes iguais." }
    ]
  }
}
```

#### static-html

**What it is for:** content written in advance that markdown cannot hold, such as an interactive simulation, a diagram that responds to the student, or a custom layout. The HTML runs in a sandboxed iframe, so it must be self-contained, with CSS, SVG and scripts written inline and nothing loaded from outside.

`content.item` holds the markup, in each language:

```json
{
  "id": "sh1",
  "type": "static-html",
  "title": [{ "lang": "en", "text": "Cut the pizza" }],
  "content": {
    "item": [
      { "lang": "en", "text": "<style>button{font-size:1.2rem}</style><p>Slices: <b id=\"n\">2</b></p><button onclick=\"n.textContent=+n.textContent+1\">Cut once more</button>" }
    ]
  }
}
```

#### dynamic-md

**What it is for:** content that depends on the student, which the AI writes during the course. Examples are a study plan built from the goal the student gave in a form, an exercise that matches their level, or the feedback on a judgement. The text is generated once and kept, so the student sees the same content when they come back, and it is stored in `dm1.text`.

`content.prompt` is the instruction to the AI. It is usually in English only, and it can embed stored data with `{{STORAGE: key}}`. `content.from` is optional and names a judgement node (`c`, `s` or `n`) to write feedback from. See [Feedback from a judgement](#feedback-from-a-judgement).

```json
{
  "id": "dm1",
  "type": "dynamic-md",
  "title": [{ "lang": "en", "text": "Your study plan" }],
  "content": {
    "prompt": [{ "lang": "en", "text": "Write a one-week study plan on fractions for a student whose goal is: {{STORAGE: f1.goal}}. Answer in the student's language, in at most 200 words." }]
  }
}
```

#### dynamic-html

**What it is for:** the same as `dynamic-md`, but the AI returns HTML instead of markdown. Use it for a personalised interactive exercise, or for a visual that is built from the student's own answers. Nobody reviews this markup before it is shown, so it is always rendered sandboxed. It is stored in `dh1.text`.

`content.prompt` describes the page to build, and it accepts `{{STORAGE: key}}` and the optional `content.from` exactly as `dynamic-md` does:

```json
{
  "id": "dh1",
  "type": "dynamic-html",
  "title": [{ "lang": "en", "text": "Practice with your numbers" }],
  "content": {
    "prompt": [{ "lang": "en", "text": "Build a self-contained HTML page with inline CSS and JavaScript and no external files. It asks the student to shade {{STORAGE: f1.numerator}} of {{STORAGE: f1.denominator}} squares and checks the answer. Write all text in the student's language." }]
  }
}
```

#### quiz

**What it is for:** checking what the student knows with objective questions that each have a single right answer. The score it produces is what usually sends a student forward or back to a review.

`content.items` lists the questions. Each question has a `question`, and a list of `options` in which exactly one is marked `correct: true`. The options are never shuffled, so vary the position of the right one. Each question can also have a `feedback`, shown after the student answers, and a `key`, which stores the value of the option the student picked (`q1.half` below). The node stores `q1.score`, `q1.total` and `q1.percent`.

```json
{
  "id": "q1",
  "type": "quiz",
  "title": [{ "lang": "en", "text": "Check yourself" }],
  "content": {
    "items": [
      {
        "key": "half",
        "question": [{ "lang": "en", "text": "Which fraction is equal to $\\frac{1}{2}$?" }],
        "options": [
          { "value": "a", "label": [{ "lang": "en", "text": "$\\frac{2}{3}$" }], "correct": false },
          { "value": "b", "label": [{ "lang": "en", "text": "$\\frac{3}{6}$" }], "correct": true },
          { "value": "c", "label": [{ "lang": "en", "text": "$\\frac{1}{3}$" }], "correct": false }
        ],
        "feedback": [{ "lang": "en", "text": "Multiply the top and the bottom of $\\frac{1}{2}$ by 3." }]
      }
    ]
  }
}
```

#### form

**What it is for:** collecting what the student says, such as their goal, their prior experience, a preference that picks a track, or a text they write. Nothing is right or wrong here. The answers become data that later prompts, judgements and edges can use.

`content.instructions` is optional markdown shown above the fields. `content.items` lists the fields, and each field has a `key`, a `type`, a `label` and, optionally, `required`. The field types are:

- `text-line` and `text-area`, for free text. These accept `min-words` and `max-words`.
- `radio` and `select`, where the student picks one option.
- `check`, where the student picks several options.

The three types with options need an `options` list, and each option has a fixed `value` and a translated `label`. Each field stores one key, such as `f1.goal`. A writing task is a form with a single `text-area`; see [Writing tasks](#writing-tasks).

```json
{
  "id": "f1",
  "type": "form",
  "title": [{ "lang": "en", "text": "About you" }],
  "content": {
    "instructions": [{ "lang": "en", "text": "Two quick questions, so the course can fit you." }],
    "items": [
      {
        "key": "goal",
        "type": "text-line",
        "required": true,
        "label": [{ "lang": "en", "text": "What do you want fractions for?" }]
      },
      {
        "key": "level",
        "type": "radio",
        "label": [{ "lang": "en", "text": "How comfortable are you with fractions?" }],
        "options": [
          { "value": "new",  "label": [{ "lang": "en", "text": "I am new to them" }] },
          { "value": "some", "label": [{ "lang": "en", "text": "I know the basics" }] }
        ]
      }
    ]
  }
}
```

#### bool

**What it is for:** a single yes/no question that the student answers about themselves, usually to choose between two paths. Examples are "go deeper?" and "have you seen this before?".

Its `content` carries the `question` and, when the default wording of the frontend is not specific enough, a `yes-label` and a `no-label`:

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

#### choice

**What it is for:** having the AI sort the student into one of several named cases, based on what they have produced. Examples are picking the remedial, standard or advanced track, or naming the misconception an answer shows. The student never sees this node.

`content.state` holds what the AI judges. Each entry in `content.items` has a `key`, `instructions` and `criteria`, which maps each option name to what it covers. The node stores `c1.<key>` together with its `-confidence` key.

```json
{
  "id": "c1",
  "type": "choice",
  "title": [{ "lang": "en", "text": "Pick the track" }],
  "content": {
    "state": { "answer": "{{STORAGE: f1.text}}" },
    "items": [
      {
        "key": "track",
        "instructions": "Which track does this student need next, judging the field answer?",
        "criteria": {
          "remedial": "Confuses the basic concepts",
          "standard": "Has the essentials",
          "unclear":  "Too short or off-topic to tell"
        }
      }
    ]
  }
}
```

See [choice](#choice-1) under Judgements by the AI for the full rules.

#### score

**What it is for:** having the AI place what the student produced on a scale the author wrote, such as grading a writing task against a rubric or measuring how far an explanation goes. With `points`, it also gives a grade. The student never sees this node.

It has the same `state` and `items` as `choice`, but here `criteria` is a list of levels ordered from low to high. The optional `points` gives one value per level. The node stores `s1.<key>` (a level that need not be a whole number) and its `-confidence` key. With `points`, it also stores `-points`, `s1.total` and `s1.percent`.

```json
{
  "id": "s1",
  "type": "score",
  "title": [{ "lang": "en", "text": "Judge the text" }],
  "content": {
    "state": {
      "task": "Argue for one cat species you would adopt, in 150 to 250 words.",
      "answer": "{{STORAGE: f1.text}}"
    },
    "items": [
      {
        "key": "evidence",
        "instructions": "Judge how well the field answer backs its claims. Do not judge grammar.",
        "criteria": ["No evidence", "One example", "Several examples, weighed"],
        "points": [0, 50, 100]
      }
    ]
  }
}
```

See [score](#score-1) under Judgements by the AI for the full rules.

#### noul

**What it is for:** having the AI answer a single yes/no question about what the student produced, with a measure of how likely the answer is yes. Examples are "is this student ready to move on?" and "did they name the mechanism?". The student never sees this node.

It has the same `state` and `items` as the other judgement nodes. The `criteria` of each question is optional, and it says what `true` and `false` cover. The node stores `n1.<key>`, the probability of yes, from 0 to 1, and no `-confidence` key.

```json
{
  "id": "n1",
  "type": "noul",
  "title": [{ "lang": "en", "text": "Ready to move on?" }],
  "content": {
    "state": { "answer": "{{STORAGE: f1.text}}" },
    "items": [
      { "key": "ready", "instructions": "Is this student ready for the next part, judging the field answer?" }
    ]
  }
}
```

See [noul](#noul-1) under Judgements by the AI for the full rules.

### Stored data

Eight of the ten node types save their results under keys named `<node-id>.<name>`. Four hold what the student did:

- A **dynamic-md** `dm1` or **dynamic-html** `dh1` produces `dm1.text`: the content the AI generated. It is kept, so a later judgement can read the challenge this student was actually given.
- A **quiz** `q1` produces `q1.score`, `q1.total` and `q1.percent` (0–100). A question with a `key` also stores the value of the chosen option, e.g. `q1.fractions`.
- A **form** `f1` produces one key per field, e.g. `f1.goal` or `f1.text`.
- A **bool** `b1` produces `b1.answer`, which is `true` when the student answered yes and `false` when the student answered no.

The other three are answered by the AI, one set of keys per question named in the node:

- A **choice** `c1` produces `c1.track` (the name of the option picked) and `c1.track-confidence` (0–1).
- A **score** `s1` produces `s1.evidence` (the level reached) and `s1.evidence-confidence` (0–1). With `points` it also produces `s1.evidence-points`, and the node produces `s1.total` and `s1.percent`.
- A **noul** `n1` produces `n1.ready` (the probability that the answer is yes, 0–1). There is no separate confidence key: the probability is already it.

Watch the scales, which are not the same. `q1.percent` and `s1.percent` run from 0 to 100. A score node's level runs over the levels of its own question — 0 to 2 on a scale of three — and is **not a whole number**, because it weights each level by its probability. A noul and any `-confidence` key run from 0 to 1.

These keys have two uses:

1. **In prompts** — `dynamic-md` and `dynamic-html` nodes, and the `state` and `instructions` of the three judgement nodes, can embed stored values with `{{STORAGE: key}}`, e.g. `Write a study plan for a student whose goal is: {{STORAGE: f1.goal}}`. A key the student has not produced yet becomes an empty text, and the list of a `check` field becomes its values separated by `, `. The course-wide `system-prompt` is sent as written, without this replacement.
2. **In edge conditions** — they are what makes the course adaptive.

### Writing tasks

A writing task is a `form` with `instructions` and one `text-area` field. The instructions are the assignment — a block of markdown, unlike a field `label`, which is a single line — and `min-words` / `max-words` make the length a rule rather than a request:

```json
{
  "id": "f1",
  "type": "form",
  "title": [{ "lang": "en", "text": "The cat you would adopt" }],
  "content": {
    "instructions": [{ "lang": "en", "text": "## The cat you would adopt\n\nPick one cat species and argue for adopting it, in **150 to 250 words**. Cover its habitat, what it eats, and one trait that justifies your choice.\n\nYou will get a comment on what you wrote before moving on." }],
    "items": [
      {
        "key": "text",
        "type": "text-area",
        "required": true,
        "label": [{ "lang": "en", "text": "Your text" }],
        "min-words": 150,
        "max-words": 250
      }
    ]
  }
}
```

The student's text lands in `f1.text`. Nothing has been judged yet: the form only collects. A `score` node judges it next, and a `dynamic-md` node writes the feedback from that judgement — the chain described under [Feedback from a judgement](#feedback-from-a-judgement).

## Edges

An edge is a directed link from one node to the next. The whole order of a course lives in `edges`; a node knows nothing about what comes after it.

| Field    | Required | What it is                                                                   |
| -------- | :------: | ---------------------------------------------------------------------------- |
| `from` |   yes   | The id of the node the student is leaving.                                   |
| `to`   |   yes   | The id of the node the student goes to.                                      |
| `when` |    no    | The condition under which this edge is the one taken. Without it, it always is. |

```json
"edges": [
  { "from": "sm1", "to": "q1" },
  { "from": "q1",  "to": "sm2", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
  { "from": "q1",  "to": "sm3" }
]
```

### How the next node is chosen

When the student finishes a node, the player looks at the edges whose `from` is that node, **in the order they appear in the file**, and takes the **first one whose `when` holds**. The others are not looked at, even if their conditions also hold. The order of the edges is therefore part of their meaning:

- Put the most specific conditions first and the broadest last.
- An edge without `when` always holds, so it is the **fallback** and must be the last edge of its node: anything written after it could never be taken, and the course is refused. A node has at most one.
- A node with no edge leaving it ends the course, and that is a normal ending. But when a node has edges and **none of them holds**, the player also finds nowhere to go and ends the course there — silently, as if the student had finished. So a node whose edges are all conditional should always get an unconditional one last; on a judgement node it is required (see [Fallback is not optional](#fallback-is-not-optional)).

### Conditions

A `when` is either a single **comparison** or a group of them.

A comparison has three fields, all required:

- `key` — the stored value to test, written `<node-id>.<name>` (`q1.percent`, `f1.goal`, `c1.track`). It is the same key used in `{{STORAGE: key}}`; the keys each node produces are listed under [Stored data](#stored-data).
- `operator` — how to compare it (see below).
- `value` — what to compare it with: a number, a text, or `true` / `false`.

```json
{ "key": "q1.percent", "operator": "gte", "value": 70 }
```

Comparisons are combined with `and` (every one must hold) and `or` (at least one must hold). Each takes a list of two or more conditions, and those can themselves be `and` / `or` groups, nested as deep as needed:

```json
"when": { "and": [
  { "key": "q1.percent", "operator": "gte", "value": 50 },
  { "or": [
    { "key": "b1.answer", "operator": "eq", "value": true },
    { "key": "s1.evidence", "operator": "gte", "value": 1.5 }
  ] }
] }
```

There is no `not`. To negate a comparison, use its opposite operator: `ne` for `eq`, `lt` for `gte`, `not-contains` for `contains`.

### Operators

| Operator         | Holds when the stored value…         | Use it for                                                    | Example                                                                  |
| ---------------- | ------------------------------------ | ------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `eq`           | is equal to `value`                | a bool answer, a choice option, a quiz or form option value   | `{ "key": "c1.track", "operator": "eq", "value": "remedial" }`         |
| `ne`           | is different from `value`          | the same, negated                                             | `{ "key": "f1.level", "operator": "ne", "value": "beginner" }`         |
| `gt`           | is greater than `value`            | numbers                                                       | `{ "key": "q1.score", "operator": "gt", "value": 3 }`                  |
| `gte`          | is greater than or equal to `value` | numbers — the usual "at least" threshold                     | `{ "key": "q1.percent", "operator": "gte", "value": 70 }`              |
| `lt`           | is less than `value`               | numbers                                                       | `{ "key": "s1.evidence", "operator": "lt", "value": 1 }`               |
| `lte`          | is less than or equal to `value`   | numbers — the usual "at most" threshold                      | `{ "key": "n1.ready", "operator": "lte", "value": 0.3 }`               |
| `contains`     | contains `value`                   | a `check` field (a list of answers), or a text               | `{ "key": "f1.topics", "operator": "contains", "value": "decimals" }`  |
| `not-contains` | does not contain `value`           | the same, negated                                             | `{ "key": "f1.topics", "operator": "not-contains", "value": "decimals" }` |

How each one compares:

- **`eq` and `ne`** compare booleans as booleans, numbers as numbers (so `70` and `70.0` are equal), and anything else as text, exactly and case-sensitively. Compare a `bool` answer with the JSON `true` or `false`, **never with the text `"true"` or `"false"`**: a non-empty text counts as true, so `"false"` would match a yes.
- **`gt`, `gte`, `lt` and `lte`** are for numbers only. A value that is not a number makes the comparison fail. Prefer them over `eq` for anything the AI produces: a score level (`1.43`), a noul probability or a confidence is rarely a round number, so `eq` would almost never hold.
- **`contains` and `not-contains`** work on two kinds of stored value. On a list, such as the answers of a `check` field, they test whether one of its items equals `value`, with the same rules as `eq`. On a text, they test whether `value` appears anywhere in it, **ignoring case** — `"cat"` is found in `"Wildcats are…"` too, so choose the text with care.

### Keys that do not exist yet

A comparison on a key the student has not produced — a node they have not gone through, or a judgement that did not happen — **never holds, whatever the operator**. That includes `ne` and `not-contains`: "the answer is not *remedial*" does not hold when there is no answer at all. Inside an `and`, one such comparison is enough to make the group fail; inside an `or`, the other comparisons can still make it hold.

This is what makes it safe to test a node that may have been skipped: the edge is simply not taken, and the student goes on to the next one.

## Judgements by the AI

A `bool` node branches on what the student *says* about themselves. A `choice`, `score` or `noul` node branches on what the AI *reads* in what the student has already produced. They are the same three shapes, with the AI answering instead of the student:

| Answered by the AI | Answered by the student  | The answer is                                |
| ------------------ | ------------------------ | -------------------------------------------- |
| `choice`         | a`form` with `radio` | the name of one of the options listed        |
| `score`          | `quiz.percent`         | a position on a scale of levels              |
| `noul`           | `bool`                 | the probability that the answer is yes, 0–1 |

The student never stops at one of these nodes. They are passed through: the AI is asked, the answer is stored, and the course carries on along the first edge whose condition that answer satisfies.

The answer is always **inside the list the author wrote**. A choice node can only return one of its own options, and a score node only a position on its own scale — never something else, and never free text that has to be parsed back into shape. That is the whole point of this class of node, and it is why grading lives here rather than in a prompt that asks for a number and hopes to find one.

The course does not name the model that answers them. The player does, pinned to an exact version, and it also decides how sure that model has to be for a judgement to count.

### How one is written

All three carry the same fields:

- **`state`** — what the AI is given to judge: an object of named fields, each a text written as it stands or saved data embedded with `{{STORAGE: key}}`. Only what is named here is sent.
- **`items`** — the questions. Each has a `key` (which becomes the storage key), an `instructions` (the question itself) and a `criteria` (the answers allowed).

`state`, `instructions` and `criteria` are instructions for the AI, not content for the student, so they carry no version per language. Only the node's `title` is translated, because it appears on the course map.

Give the `state` **the task as well as the answer**. A judgement that sees only what the student wrote cannot tell whether they wrote what was asked, and a criterion like "does not merely copy the source texts" is unjudgeable unless the source texts are there:

```json
"state": {
  "task": "Argue for one cat species you would adopt, in 150 to 250 words, covering habitat, diet and one trait.",
  "answer": "{{STORAGE: f1.text}}"
}
```

The `instructions` then point at a field by name, and say what to leave aside — a question that excludes nothing tends to weigh everything:

```
Judge how well the field answer backs its claims with evidence. Do not judge grammar, length or tone.
```

All the questions of a node are judged **together, in one call**, against the same `state`, and none of them sees the answer of another. Asking three narrow questions costs almost nothing over asking one, and gives a course that is easier to adjust afterwards: change the threshold in an edge rather than rewrite a prompt.

A node holds one kind of question only. To ask a `choice` and a `score` at the same point, chain two nodes.

### choice

```json
{
  "id": "c1",
  "type": "choice",
  "title": [{ "lang": "en", "text": "Pick the track" }],
  "content": {
    "state": {
      "task": "Explain in your own words why equivalent fractions name the same number.",
      "answer": "{{STORAGE: f1.text}}"
    },
    "items": [
      {
        "key": "track",
        "instructions": "Which track does this student need next, judging the field answer?",
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
  "title": [{ "lang": "en", "text": "Judge the text" }],
  "content": {
    "state": {
      "task": "Argue for one cat species you would adopt, in 150 to 250 words.",
      "answer": "{{STORAGE: f1.text}}"
    },
    "items": [
      {
        "key": "evidence",
        "instructions": "Judge how well the field answer backs its claims. Do not judge grammar.",
        "criteria": [
          "No evidence given for the claims",
          "Claims backed by one example",
          "Claims backed by several examples, weighed against each other"
        ],
        "points": [0, 100, 200]
      }
    ]
  }
}
```

The stored `s1.evidence` is **not a whole number**: it weights each level number by its probability, so `1.43` on this scale is an ordinary answer meaning "between the second and the third, nearer the second". Compare it with `gte` and `lt`, not with `eq`.

Alongside it the node stores `s1.evidence-confidence`. How the weight fell across the levels — `0.57` on level 1 and `0.43` on level 2, say — is not a key: the player keeps it for the node that writes the feedback, which receives it level by level with the text of each, so the rubric never has to be typed twice.

To judge several things at once, give each its own question and join them in an edge with `and`. That is better than one question trying to weigh everything: when the priorities change, the numbers in the edge change, not the wording of a prompt.

#### Points and grades

A question with `points` is also worth a number. The points line up with `criteria`, one per level, and the stored `-points` is the expected value — each level's points weighted by its probability:

```
0 × 0.00  +  100 × 0.57  +  200 × 0.43  =  143
```

The node then stores `s1.total` (the sum over every question that carries points) and `s1.percent` (that sum over the highest it could have been, 0–100). A rubric of five questions worth 200 points each gives a grade out of 1000, exactly as a human rubric would.

This is what a grade is in this format: **a number worked out from the judgement, never a second opinion asked of the AI.** The number the student is shown and the number that routes them are the same number, so they cannot disagree. Leave `points` out on a question that only decides where the student goes next.

#### Confidence

Each question of a `choice` or a `score` also stores `-confidence`: how sure the AI is, from 0 to 1.

How sure it has to be for the judgement to count at all is not written in the course. The player decides it, next to the model it runs, because the number means nothing apart from that model. When any question of a node comes back under the player's floor, **the whole node counts as not judged**: nothing is stored, no edge testing its keys holds, and the student takes the unconditional edge.

A branch that is expensive to get wrong can ask for more than that, in its own edge — see [Fallback is not optional](#fallback-is-not-optional).

### noul

A yes/no question, answered with the probability that the answer is yes. `criteria` is optional and only says what a yes and a no cover:

```json
{
  "id": "n1",
  "type": "noul",
  "title": [{ "lang": "en", "text": "Ready to move on?" }],
  "content": {
    "state": {
      "answer": "{{STORAGE: f1.text}}"
    },
    "items": [
      {
        "key": "ready",
        "instructions": "Is this student ready for the next part, judging the field answer?",
        "criteria": {
          "true":  "The student names the mechanism, even loosely",
          "false": "The student only restates the result"
        }
      }
    ]
  }
}
```

The edges compare `n1.ready` against a threshold you pick: `0.5` when both answers are equally easy to act on, higher when acting on a wrong yes costs more, lower when missing a true yes costs more. A noul stores no `-confidence`: the probability is already the measure of how sure the AI is.

### Feedback from a judgement

A judgement is a number. Turning it into something a student can read is the job of a `dynamic-md` (or `dynamic-html`) node with a `from`:

```json
{
  "id": "dm1",
  "type": "dynamic-md",
  "title": [{ "lang": "en", "text": "Your feedback" }],
  "content": {
    "from": "s1",
    "prompt": [{ "lang": "en", "text": "Write 3 to 5 sentences to the student, in their language: one thing that works, up to two concrete improvements. Explain the level they reached in plain words. Do not judge again and do not give a number." }]
  }
}
```

With `from`, the AI receives — after the prompt — the `state` that node judged and the judgement itself, rendered in full: each question's instructions and its answer — on a score, the level reached, with the text of every level and the weight the judgement put on each; on a choice, the option picked; on a noul, the probability of a yes — then its points if any and its confidence. So the writer knows that `1.43` means *"between 'one example' and 'several examples, weighed', nearer the first"*, and says so, **without the rubric being written a second time**.

The prompt only says *how to write* — audience, length, tone, what to praise, what to correct. It must not judge again: the level is settled, and a feedback that argues with the number is the one thing this arrangement exists to prevent.

The chain, end to end:

```json
"nodes": [ "f1 (form, text-area)", "s1 (score, from f1.text)", "dm1 (dynamic-md, from s1)" ],
"edges": [
  { "from": "f1", "to": "s1" },
  { "from": "s1", "to": "dm1", "when": { "key": "s1.evidence", "operator": "gte", "value": 1 } },
  { "from": "s1", "to": "sm9" },
  { "from": "dm1", "to": "sm9" }
]
```

One judgement, one source of truth. The number that routes the student and the number the feedback explains are the same number.

Note that the unconditional edge of `s1` goes to `sm9`, **not** to `dm1`. A node written `from` a judgement is only reachable when that judgement exists; sending the fallback there would ask it to write from nothing, and a course that does is refused.

### Fallback is not optional

A judgement node decides nothing on its own — the edges leaving it do. **One of them must be unconditional**, because a judgement can land anywhere in its range, and because it may not happen at all.

A judgement does not happen when the call fails, or when the player does not accept the answer — one under its confidence floor, say. In either case **nothing is stored** — no level, no option, no confidence, no default, no middle value. No condition holds (see [Keys that do not exist yet](#keys-that-do-not-exist-yet)) and the student takes the unconditional edge.

That silence is deliberate. A grade and the feedback written from it both come out of the same answer, so a made-up number would not just send a student down the wrong path: it would become a confident, false account of their own work, addressed to them.

Use the confidence in an edge when one particular branch is expensive to get wrong — a stricter floor than the player's, for that path only:

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

Edges with conditions are what make the course adapt: two students who answer differently at the same node go on to different nodes. The branches can rejoin later, or never.

An edge may also lead **back** to a node already visited — "study again, then retake". Coming back is a new visit: quiz, form and bool nodes are answered again, and choice, score and noul nodes are judged again, so either way the new answers overwrite the old keys, while dynamic nodes keep the content already generated. Every cycle needs a way out that the student can reach.

This is how a rewrite loop is built: `f1 → s1`, and from `s1` an edge back to a tips node and on to `f1` when the level is too low. The student rewrites, the judgement runs again, and `f1.text` and the keys of `s1` are replaced.

### Example

After quiz `q1`, strong students move on to `sm2` while the others get a review node first:

```json
"edges": [
  { "from": "q1",  "to": "sm2", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
  { "from": "q1",  "to": "sm3" },
  { "from": "sm3", "to": "sm2" }
]
```

## Known limitations

- **The task is written twice.** A writing task appears in the `instructions` of the form, translated for the student, and again in the `state` of the judgement, in the language the AI is addressed in. There is no way for a judgement to reference a node's instructions, so the two are kept in step by hand. Change one and change the other.
- **A judgement gives no reasons.** The model returns a level, a distribution and a confidence — never prose. The node that writes the feedback therefore re-reads the student's answer to say *why*, anchored on a level it may not contradict. The verdict cannot drift; the wording of the justification still can.
