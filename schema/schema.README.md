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
| `system-prompt` | no | Course-wide instructions sent as the system prompt of every call that *generates* content. Audience, tone and global rules live here, so each node only carries what is specific to it. It does not reach the judgement nodes, which take no system prompt. |
| `judge-model` | when the course judges | The exact version of the model that answers `choice`, `score` and `noul` nodes, e.g. `jev-1.13.0`. Never an alias: thresholds, points and confidence floors are tuned against one version, and an alias moves under them. |

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
| `dynamic-md`   | A prompt the AI runs during the course; the markdown it returns is shown.                  | Yes                   |
| `dynamic-html` | A prompt the AI runs during the course; the HTML it returns is shown (rendered sandboxed). | Yes                   |
| `quiz`         | A set of multiple-choice questions.                                                        | Yes                   |
| `form`         | A form the student fills in — including a writing task.                                    | Yes                   |
| `bool`         | A yes/no question, usually asked to choose between two paths.                              | Yes                   |
| `choice`       | The AI picks one of the options the author listed, and never anything else.                 | Yes                   |
| `score`        | The AI places the student on a scale of levels the author wrote.                            | Yes                   |
| `noul`         | The AI answers a yes/no question with the probability that the answer is yes.               | Yes                   |

Node ids carry their type as a prefix: `sm1` (static-md), `sh1` (static-html), `dm1` (dynamic-md), `dh1` (dynamic-html), `q1` (quiz), `f1` (form), `b1` (bool), `c1` (choice), `s1` (score), `n1` (noul). The two nodes that only show content written in advance carry a two-letter prefix; every other node stores data under keys of its own.

The last three are the only nodes the student never sees. They are passed through: the AI judges what the student has produced so far, the answer is stored, and the course carries on. They are described under [Judgements by the AI](#judgements-by-the-ai).

There is no essay node. A text written by the student is a `form` with a `text-area`, and the judging is a `score` node — one judgement, restricted to the scale its author wrote, instead of a grade parsed back out of prose. See [Writing tasks](#writing-tasks).

### Stored data

Eight of the ten node types save their results under keys named `<node-id>.<name>`. Four hold what the student did:

- A **dynamic-md** `dm1` or **dynamic-html** `dh1` produces `dm1.text`: the content the AI generated. It is kept, so a later judgement can read the challenge this student was actually given.
- A **quiz** `q1` produces `q1.score`, `q1.total` and `q1.percent` (0–100). A question with a `key` also stores the value of the chosen option, e.g. `q1.fractions`.
- A **form** `f1` produces one key per field, e.g. `f1.goal` or `f1.text`.
- A **bool** `b1` produces `b1.answer`, which is `true` when the student answered yes and `false` when the student answered no.

The other three are answered by the AI, one set of keys per question named in the node:

- A **choice** `c1` produces `c1.track` (the name of the option picked), `c1.track-confidence` (0–1) and `c1.track-probabilities` (each option to its probability).
- A **score** `s1` produces `s1.evidence` (the level reached), `s1.evidence-confidence` (0–1), `s1.evidence-probabilities` (each level number to its probability) and `s1.evidence-legend` (each level number back to the text of its criterion). With `points` it also produces `s1.evidence-points`, and the node produces `s1.total` and `s1.percent`.
- A **noul** `n1` produces `n1.ready` (the probability that the answer is yes, 0–1). There is no separate confidence key: the probability is already it.

Watch the scales, which are not the same. `q1.percent` and `s1.percent` run from 0 to 100. A score node's level runs over the levels of its own question — 0 to 2 on a scale of three — and is **not a whole number**, because it weights each level by its probability. A noul and any `-confidence` key run from 0 to 1.

The `-probabilities` and `-legend` keys are maps, not single values, so edges do not compare them. They exist for the node that writes the feedback, which receives them rendered.

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
| `score`            | `quiz.percent`          | a position on a scale of levels |
| `noul`             | `bool`                  | the probability that the answer is yes, 0–1 |

The student never stops at one of these nodes. They are passed through: the AI is asked, the answer is stored, and the course carries on along the first edge whose condition that answer satisfies.

The answer is always **inside the list the author wrote**. A choice node can only return one of its own options, and a score node only a position on its own scale — never something else, and never free text that has to be parsed back into shape. That is the whole point of this class of node, and it is why grading lives here rather than in a prompt that asks for a number and hopes to find one.

The model that answers them is named once, in `info.judge-model`, as an exact version.

### How one is written

All three carry the same fields:

- **`state`** — what the AI is given to judge: an object of named fields, each a text written as it stands or saved data embedded with `{{STORAGE: key}}`. Only what is named here is sent.
- **`items`** — the questions. Each has a `key` (which becomes the storage key), an `instructions` (the question itself) and a `criteria` (the answers allowed).
- **`confidence`** — optional, on `choice` and `score`: the floor below which the judgement does not count.

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
    "confidence": 0.75,
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

Alongside it the node stores `s1.evidence-confidence`, `s1.evidence-probabilities` (`{"0": 0.0, "1": 0.57, "2": 0.43}`) and `s1.evidence-legend` (each level number back to its text). The last two are maps: edges do not compare them, but the node that writes the feedback receives them, which is why the rubric never has to be typed twice.

To judge several things at once, give each its own question and join them in an edge with `and`. That is better than one question trying to weigh everything: when the priorities change, the numbers in the edge change, not the wording of a prompt.

#### Points and grades

A question with `points` is also worth a number. The points line up with `criteria`, one per level, and the stored `-points` is the expected value — each level's points weighted by its probability:

```
0 × 0.00  +  100 × 0.57  +  200 × 0.43  =  143
```

The node then stores `s1.total` (the sum over every question that carries points) and `s1.percent` (that sum over the highest it could have been, 0–100). A rubric of five questions worth 200 points each gives a grade out of 1000, exactly as a human rubric would.

This is what a grade is in this format: **a number worked out from the judgement, never a second opinion asked of the AI.** The number the student is shown and the number that routes them are the same number, so they cannot disagree. Leave `points` out on a question that only decides where the student goes next.

#### The confidence floor

`confidence` is the least the AI has to be sure for the judgement to count. When any question of the node comes back under it, **the whole node counts as not judged**: nothing is stored, no edge testing its keys holds, and the student takes the unconditional edge.

Declaring it once on the node keeps the rule in one place, instead of repeating a `-confidence` comparison in every edge leaving it. Around `0.75` is a sensible floor where the judgement carries a grade or sends the student down a track that is expensive to get wrong; leave it out when every branch is cheap to get wrong.

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

The edges compare `n1.ready` against a threshold you pick: `0.5` when both answers are equally easy to act on, higher when acting on a wrong yes costs more, lower when missing a true yes costs more. A noul takes no `confidence` floor: the probability is already the measure of how sure the AI is, so a floor would be a second reading of the same number.

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

With `from`, the AI receives — after the prompt — the `state` that node judged and the judgement itself, rendered in full: each question's instructions, the level reached with the text of its criterion, its points if any, its confidence and the whole distribution. So the writer knows that `1.43` means *"between 'one example' and 'several examples, weighed', nearer the first"*, and says so, **without the rubric being written a second time**.

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

A judgement does not happen when the call fails, or when it comes back under the node's `confidence`. In either case **nothing is stored** — no level, no option, no confidence, no default, no middle value. No condition holds (see [Adaptive learning](#adaptive-learning)) and the student takes the unconditional edge.

That silence is deliberate. A grade and the feedback written from it both come out of the same answer, so a made-up number would not just send a student down the wrong path: it would become a confident, false account of their own work, addressed to them.

Use the confidence in an edge when one particular branch is expensive to get wrong — a stricter floor than the node's, for that path only:

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

An edge may lead **back** to a node already visited — "study again, then retake". Coming back is a new visit: quiz, form and bool nodes are answered again, and choice, score and noul nodes are judged again, so either way the new answers overwrite the old keys, while dynamic nodes keep the content already generated. Every cycle needs a way out that the student can reach.

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
