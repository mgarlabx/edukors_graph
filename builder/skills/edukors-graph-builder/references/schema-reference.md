# Course format — authoring reference

The authoritative contract is `../assets/course.schema.json`. This file is the
working version: what to write, what each field is for, and where courses break.
How the frontend runs a course — what a judgement stores and what it stores when
it did not happen, the order options are shown in, what happens when an edge leads
back to a visited node, how `{{STORAGE: key}}` is filled in, how HTML is sandboxed,
and which texts accept markdown — is defined in the field descriptions of the
schema. Read them there.

- [Shape of the file](#shape-of-the-file)
- [info](#info)
- [Localized text](#localized-text)
- [Nodes](#nodes)
- [Storage keys](#storage-keys)
- [Edges and conditions](#edges-and-conditions)
- [A complete miniature course](#a-complete-miniature-course)
- [Errors that actually happen](#errors-that-actually-happen)

## Shape of the file

```json
{
  "$schema": "https://edukors.org/graph/schema/v1/",
  "info":  { ... },
  "nodes": [ ... ],
  "edges": [ ... ]
}
```

No other top-level keys are allowed. The same is true almost everywhere else in
the format: unknown properties are rejected, so an invented field like
`"description"` on a node is an error, not an extra.

## info

| Field | Required | Notes |
|-------|----------|-------|
| `course-id` | yes | UUID. Generate one: `python3 -c "import uuid;print(uuid.uuid4())"` |
| `source-language` | yes | ISO 639-1, optional region: `pt`, `pt-BR`, `en`, `es` |
| `other-languages` | yes | array, may be `[]`, must not contain the source language |
| `title` | yes | localized list |
| `description` | no | localized list, for catalogues |
| `author` | yes | plain string — person or institution |
| `version` | yes | `MAJOR.MINOR.PATCH`, e.g. `1.0.0` |
| `date` | yes | `YYYY-MM-DD` |
| `start` | yes | id of the entry node; the only entry point |
| `sections` | no | `[{ "number": 1, "title": [...] }]` — names for the node groups |
| `system-prompt` | no | English; sent as system prompt on every call that *generates* content. Judgement nodes take none |
| `judge-model` | when the course judges | The exact version of the model behind `choice`/`score`/`noul`, e.g. `jev-1.13.0`. Never an alias |

`system-prompt` is where audience, tone and global rules live, so each dynamic
node only carries what is specific to it. End it with the language instruction:

```
You are tutoring second-year nursing students. Be concise, use clinical examples,
never give dosages without citing the protocol. Answer in the student's language.
```

## Localized text

Every visible string is a list, one entry per language:

```json
"title": [
  { "lang": "pt", "text": "Frações sem sofrimento" },
  { "lang": "en", "text": "Fractions without tears" }
]
```

The source language must be present. When `other-languages` is non-empty, write
every listed language for author- and student-facing text: titles, markdown,
questions, labels, feedback. The prompts of `dynamic-md` / `dynamic-html` are the
exception — they are instructions for the AI, so a single English entry is normal
and correct, since the AI is told to answer in the student's language.

## Nodes

Every node has `id`, `type`, `title`, `content`, and optionally `section`
(integer ≥ 1, defaults to 1, purely visual grouping — it does not affect order).

The id prefix must match the type:

| Type | Prefix | `content` fields |
|------|--------|------------------|
| `static-md` | `sm` | `item` (localized markdown) |
| `static-html` | `sh` | `item` (localized HTML, rendered sandboxed) |
| `dynamic-md` | `dm` | `prompt` (localized; normally English only), optional `from` |
| `dynamic-html` | `dh` | `prompt` (localized; normally English only), optional `from` |
| `quiz` | `q` | `items` — list of questions |
| `form` | `f` | `items` — list of fields, plus optional `instructions` (localized) |
| `bool` | `b` | `question`, optional `yes-label`, `no-label`, `default` |
| `choice` | `c` | `state`, `items`, optional `confidence` — the AI picks one of the options listed |
| `score` | `s` | `state`, `items`, optional `confidence` — the AI places the student on a scale |
| `noul` | `n` | `state`, `items` — the AI gives the probability of a yes |

There is no `essay`. A text written by the student is a `form` with a `text-area`,
judged by a `score` node — one judgement, restricted to the scale its author wrote,
instead of a grade parsed back out of prose.

The last three are the only nodes the student never sees: they are passed
through while the AI judges what the student has produced, and the edges leaving
them read the answer. Their `state`, `instructions` and `criteria` are
instructions for the AI, never shown, so they carry no version per language.

### static-md

The workhorse. Markdown written now, shown as written.

```json
{
  "id": "sm2", "type": "static-md", "section": 2,
  "title": [{ "lang": "pt", "text": "O que é uma fração" }],
  "content": { "item": [{ "lang": "pt", "text": "## O que é uma fração\n\nUma fração..." }] }
}
```

### static-html

Same idea, but HTML — use it for tables, side-by-side comparisons, and inline SVG
diagrams.

### dynamic-md / dynamic-html

A prompt the AI runs during the course; what it returns is shown to the student
and then frozen, so revisiting the node shows the same text. The prompt can read
anything the student already produced:

```json
{
  "id": "dm1", "type": "dynamic-md", "section": 2,
  "title": [{ "lang": "pt", "text": "Exemplos para o seu contexto" }],
  "content": { "prompt": [{ "lang": "en", "text":
    "Explain unit fractions with three worked examples drawn from the student's own context.\nStudent's goal: {{STORAGE: f1.goal}}\nStudent's difficulty: {{STORAGE: f1.pain}}\nReturn markdown, 300-400 words, with one short practice question at the end. Answer in the student's language." }] }
}
```

Write these prompts like a brief: role, input, required output shape, length,
language. A vague prompt produces vague teaching.

### dynamic-md written from a judgement

A `dynamic-md` (or `dynamic-html`) node with `from` is the node that turns a
judgement into something the student can read:

```json
{
  "id": "dm2", "type": "dynamic-md", "section": 3,
  "title": [{ "lang": "pt", "text": "Sua devolutiva" }],
  "content": {
    "from": "s1",
    "prompt": [{ "lang": "en", "text": "Write 3 to 5 sentences to the student, in their language: one thing that works, up to two concrete improvements. Explain in plain words the level they reached. Do not give a number and do not decide anything: the level is settled." }]
  }
}
```

With `from`, the AI is handed the judgement rendered in full after the prompt —
each question, the level reached with the text of its criterion, the weight on
each level, the points and the confidence. So the writer knows what `1.43` means
**without the rubric being written a second time**.

The prompt says *how to write*. It must not judge again: the level is settled, and
a feedback arguing with the number is the one thing this arrangement prevents. The
validator warns when the prompt says "grade", "score", "judge" or "evaluate".

The node is only reachable through its judgement, so the judge's unconditional
edge must lead somewhere else — the validator refuses a course where it does not.

### quiz

```json
{
  "id": "q1", "type": "quiz", "section": 1,
  "title": [{ "lang": "pt", "text": "Sondagem" }],
  "content": { "items": [
    { "key": "fractions",
      "question": [{ "lang": "pt", "text": "Quanto é 1/2 + 1/4?" }],
      "options": [
        { "value": "three-quarters", "label": [{ "lang": "pt", "text": "3/4" }], "correct": true },
        { "value": "two-sixths",     "label": [{ "lang": "pt", "text": "2/6" }], "correct": false }
      ],
      "feedback": [{ "lang": "pt", "text": "Some os numeradores depois de igualar..." }] }
  ] }
}
```

Exactly one option per question has `correct: true`. `value` is a stable
identifier (`^[a-z][a-z0-9-]*$`) — it is what gets stored, so it must not be
language-dependent. A question with a `key` becomes addressable on its own
(`q1.fractions`); `key` cannot be `score`, `total` or `percent`.

**Vary the position of the correct option.** Writing the right answer first
every time — the natural way to draft a question — turns the quiz into "always pick A" and
stops measuring anything. Before moving on, shuffle each question's `options`
list so the correct one lands in a different slot, roughly evenly across the
course and never in the same slot in every question of one node. Wrong options
must stay plausible wherever they end up: no "all of the above", no ordering
that depends on position. The validator warns when the correct answer clusters
in one slot, per quiz and across the whole course.

### form

Fields of type `text-line`, `text-area`, `radio`, `check`, `select`. Choice types
require `options` (≥ 2); text types must not have them. The optional
`instructions` is a block of markdown above the fields; a field `label` is a
single line, so anything longer belongs there.

A **writing task** is a form with `instructions` and one `text-area`.
`min-words`/`max-words` make a length a rule rather than a request — the player
counts as the student types and does not let them move on outside the range:

```json
{
  "id": "f2", "type": "form", "section": 3,
  "title": [{ "lang": "pt", "text": "Explique com suas palavras" }],
  "content": {
    "instructions": [{ "lang": "pt", "text": "## Explique com suas palavras\n\nEm **150 a 250 palavras**, explique a um colega como somar 1/3 e 1/6.\n\nVocê receberá um comentário sobre o que escreveu antes de seguir." }],
    "items": [
      { "key": "text", "type": "text-area", "required": true,
        "label": [{ "lang": "pt", "text": "Seu texto" }],
        "min-words": 150, "max-words": 250 }
    ]
  }
}
```

Nothing has been judged here: the form only collects. A `score` node judges
`f2.text` next, and a node with `from` writes the comment.

```json
{
  "id": "f1", "type": "form", "section": 1,
  "title": [{ "lang": "pt", "text": "Sobre você" }],
  "content": { "items": [
    { "key": "goal", "type": "radio", "required": true,
      "label": [{ "lang": "pt", "text": "O que te traz aqui?" }],
      "options": [
        { "value": "career", "label": [{ "lang": "pt", "text": "Trabalho" }] },
        { "value": "curiosity", "label": [{ "lang": "pt", "text": "Curiosidade" }] }
      ] },
    { "key": "pain", "type": "text-area", "required": true,
      "label": [{ "lang": "pt", "text": "Qual dificuldade você quer resolver?" }] }
  ] }
}
```

### bool

One yes/no question, used to fork the course — usually "do you want the long
version?".

```json
{
  "id": "b1", "type": "bool", "section": 2,
  "title": [{ "lang": "pt", "text": "Quer se aprofundar?" }],
  "content": {
    "question": [{ "lang": "pt", "text": "Quer ver a demonstração completa?" }],
    "yes-label": [{ "lang": "pt", "text": "Sim, quero a trilha detalhada" }],
    "no-label": [{ "lang": "pt", "text": "Não, seguir em frente" }]
  }
}
```

### choice / score / noul

The three nodes the AI decides with. All carry a `state` — an object of named
fields, each written out or built with `{{STORAGE: key}}` — and `items`, one
question per key. `choice` and `score` also take an optional `confidence`. All the
questions of a node are judged together, in one call, over the same state, by the
model named in `info.judge-model`.

```json
{
  "id": "c1", "type": "choice",
  "title": [{ "lang": "pt", "text": "Escolher a trilha" }],
  "content": {
    "state": {
      "task": "Explain in your own words why equivalent fractions name the same number.",
      "answer": "{{STORAGE: f2.text}}"
    },
    "confidence": 0.75,
    "items": [{
      "key": "track",
      "instructions": "Which track does this student need next, judging the field answer?",
      "criteria": {
        "remedial": "Confuses the basic concepts",
        "standard": "Has the essentials",
        "advanced": "Goes beyond what was taught",
        "unclear":  "Too short or too off-topic to tell"
      }
    }]
  }
}
```

Two habits decide whether a judgement is any good:

- **Give the state the task, not only the answer.** A judgement that sees only
  what the student wrote cannot tell whether they wrote what was asked, and a
  criterion like "does not merely copy the source" is unjudgeable without the
  source. Name the fields for what they hold; `instructions` points at them.
- **Say what not to judge.** A question that excludes nothing weighs everything:
  *"Judge the evidence in the field answer. Do not judge grammar or length."*

Never put a mark already given into a state — another judgement's level, or
`q1.percent`. The AI anchors on it instead of judging, and the validator warns.

`confidence` is the least the AI must be sure for the judgement to count. Below
it, **the whole node counts as not judged**: nothing is stored, no edge testing
its keys holds, and the student takes the unconditional edge. Declaring it once
on the node beats repeating a `-confidence` comparison in every edge. Around
`0.75` where the judgement carries a grade; leave it out where every branch is
cheap to get wrong.

`criteria` is what an answer may be, and it is the one part that differs:

- **choice** — a map of option name to what it covers. The names are compared by
  the edges, so they follow the same rule as a form option's `value`
  (`^[a-z][a-z0-9-]*$`), 2 to 255 of them. Add an `unclear` option: the AI must
  answer with one of these and has nowhere else to put a case the list forgot.
- **score** — the levels of the scale, in order, low end first, 2 to 10 of them.
  Describe situations, not degrees. The level stored is **fractional**: `1.43` on
  a scale of three is ordinary. Compare with `gte` and `lt`, never `eq`.
- **noul** — optional, and only says what `true` and `false` cover. A noul takes
  no `confidence`: the probability already is one.

A `score` question may also carry **`points`**, one number per level, in the same
order. The question then stores `<id>.<key>-points`, the expected value — each
level's points weighted by its probability — and the node stores `<id>.total` and
`<id>.percent`:

```json
{ "key": "evidence",
  "instructions": "Judge how well the field answer backs its claims. Ignore grammar.",
  "criteria": ["No evidence given", "Claims backed by one example",
               "Claims backed by several examples, weighed against each other"],
  "points": [0, 100, 200] }
```

`0×0.00 + 100×0.57 + 200×0.43 = 143`. Five questions worth 200 each give a grade
out of 1000, exactly as a human rubric would. **This is what a grade is in this
format: a number worked out from the judgement, never a second opinion asked of
the AI.** Omit `points` on a question that only decides where the student goes.

Every node like this needs an **unconditional edge**. A judgement the AI could
not make produces no key, nothing holds, and without that edge the player finds
no next step and shows the course as finished. Both validators refuse a course
that omits it.

## Storage keys

Eight node types store data, and the key is always `<node-id>.<name>`:

| Node | Keys produced |
|------|---------------|
| dynamic-md `dm1` | `dm1.text` — the content the AI generated, kept, so a later judgement can read the challenge this student was given |
| dynamic-html `dh1` | `dh1.text` — the same |
| quiz `q1` | `q1.score`, `q1.total`, `q1.percent` (0–100), plus `q1.<key>` per keyed question |
| form `f1` | one per field: `f1.goal`, `f1.text`… (`check` fields store a list) |
| bool `b1` | `b1.answer` — `true` / `false` |
| choice `c1` | per question: `c1.track` (the option picked), `c1.track-confidence` (0–1), `c1.track-probabilities` (option → probability) |
| score `s1` | per question: `s1.evidence` (level, fractional), `s1.evidence-confidence` (0–1), `s1.evidence-probabilities` (level → probability), `s1.evidence-legend` (level → its text). With `points`: `s1.evidence-points`, and for the node `s1.total` and `s1.percent` (0–100) |
| noul `n1` | per question: `n1.ready` — the probability of a yes, 0–1. No confidence key: the probability already is one |

Mind the scales. `q1.percent` and `s1.percent` run 0–100. A score node's **level**
runs over the levels of its own question — 0 to 2 on a scale of three. A noul and
every `-confidence` key run 0–1. Comparing a level against 60 is the habit an
essay grade left behind; the edge simply never fires, and the validator warns.

`-probabilities` and `-legend` hold maps, not single values, so edges do not
compare them. They are there for the node that writes the feedback, which gets
them rendered.

They have exactly two uses: `{{STORAGE: key}}` inside prompts (dynamic nodes and
the `state`/`instructions` of a judgement), and `when` on edges.

## Edges and conditions

```json
"edges": [
  { "from": "q1", "to": "sm4", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
  { "from": "q1", "to": "sm3" },
  { "from": "sm3", "to": "sm4" }
]
```

Edges leaving a node are evaluated top to bottom; the first whose `when` holds is
taken. An edge without `when` always holds — so it is the fallback and **must be
listed last** among the edges leaving that node.

Operators: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`, `not-contains`.
Combine with `and` / `or`, each taking a list of ≥ 2 conditions, nestable:

```json
"when": { "or": [
  { "key": "s1.method", "operator": "gte", "value": 2 },
  { "key": "f1.goal", "operator": "eq", "value": "curiosity" }
] }
```

A condition on a key the student has not produced yet never holds. That is a
feature — you can test a node that may have been skipped — but it also means a
condition placed *before* the node that produces its key is dead weight.

## A complete miniature course

Eight nodes, two sections, one adaptive branch and the graded chain
`form → score → dynamic-md`. Use it as the shape template; a real course has more
content per node.

```json
{
  "$schema": "https://edukors.org/graph/schema/v1/",
  "info": {
    "course-id": "3f1c1f6e-2a41-4f0e-9f77-8a6b0c2d7e10",
    "source-language": "pt",
    "other-languages": [],
    "title": [{ "lang": "pt", "text": "Frações sem sofrimento" }],
    "description": [{ "lang": "pt", "text": "Curso curto que se adapta ao que o estudante já sabe." }],
    "author": "Equipe Edukors",
    "version": "1.0.0",
    "date": "2026-09-15",
    "start": "sm1",
    "judge-model": "jev-1.13.0",
    "sections": [
      { "number": 1, "title": [{ "lang": "pt", "text": "Chegada" }] },
      { "number": 2, "title": [{ "lang": "pt", "text": "Fundamentos" }] }
    ],
    "system-prompt": "You are tutoring students aged 12-14. Be concise, use plain language, always give a worked example. Answer in the student's language."
  },
  "nodes": [
    { "id": "sm1", "type": "static-md", "section": 1,
      "title": [{ "lang": "pt", "text": "Bem-vindo" }],
      "content": { "item": [{ "lang": "pt", "text": "## Bem-vindo\n\nNeste curso você vai..." }] } },

    { "id": "f1", "type": "form", "section": 1,
      "title": [{ "lang": "pt", "text": "Sobre você" }],
      "content": { "items": [
        { "key": "goal", "type": "radio", "required": true,
          "label": [{ "lang": "pt", "text": "O que te traz aqui?" }],
          "options": [
            { "value": "career", "label": [{ "lang": "pt", "text": "Trabalho" }] },
            { "value": "curiosity", "label": [{ "lang": "pt", "text": "Curiosidade" }] }
          ] }
      ] } },

    { "id": "q1", "type": "quiz", "section": 1,
      "title": [{ "lang": "pt", "text": "Sondagem" }],
      "content": { "items": [
        { "key": "fractions",
          "question": [{ "lang": "pt", "text": "Quanto é 1/2 + 1/4?" }],
          "options": [
            { "value": "three-quarters", "label": [{ "lang": "pt", "text": "3/4" }], "correct": true },
            { "value": "two-sixths", "label": [{ "lang": "pt", "text": "2/6" }], "correct": false }
          ],
          "feedback": [{ "lang": "pt", "text": "Iguale os denominadores antes de somar." }] }
      ] } },

    { "id": "sm2", "type": "static-md", "section": 2,
      "title": [{ "lang": "pt", "text": "Revisão: o que é uma fração" }],
      "content": { "item": [{ "lang": "pt", "text": "## O que é uma fração\n\n..." }] } },

    { "id": "dm1", "type": "dynamic-md", "section": 2,
      "title": [{ "lang": "pt", "text": "Exemplos para o seu contexto" }],
      "content": { "prompt": [{ "lang": "en", "text": "Explain fraction addition with three examples suited to a student whose reason for studying is: {{STORAGE: f1.goal}}. Return markdown, 300 words, ending with one practice question. Answer in the student's language." }] } },

    { "id": "f2", "type": "form", "section": 2,
      "title": [{ "lang": "pt", "text": "Explique com suas palavras" }],
      "content": {
        "instructions": [{ "lang": "pt", "text": "## Explique com suas palavras\n\nEm **80 a 150 palavras**, explique a um colega como somar 1/3 e 1/6. Mostre o passo de igualar os denominadores.\n\nVocê receberá um comentário antes de seguir." }],
        "items": [
          { "key": "text", "type": "text-area", "required": true,
            "label": [{ "lang": "pt", "text": "Seu texto" }],
            "min-words": 80, "max-words": 150 }
        ] } },

    { "id": "s1", "type": "score", "section": 2,
      "title": [{ "lang": "pt", "text": "Medir a explicação" }],
      "content": {
        "state": {
          "task": "Explain to a classmate how to add 1/3 and 1/6, showing the step of equalising the denominators.",
          "answer": "{{STORAGE: f2.text}}"
        },
        "confidence": 0.75,
        "items": [
          { "key": "method",
            "instructions": "Judge whether the field answer gets the method right: equalise the denominators, then add the numerators. Do not judge grammar, length or tone.",
            "criteria": [
              "Adds numerators and denominators straight across, or gives no method",
              "Names the common denominator but does not carry the method through",
              "Carries the method through correctly, with the arithmetic right"
            ],
            "points": [0, 50, 100] }
        ] } },

    { "id": "dm2", "type": "dynamic-md", "section": 2,
      "title": [{ "lang": "pt", "text": "Sua devolutiva" }],
      "content": {
        "from": "s1",
        "prompt": [{ "lang": "en", "text": "Write 3 to 4 sentences to the student, in their language: one thing that works, then the single most useful next step. Explain in plain words the level they reached. Do not give a number and do not decide anything: the level is settled." }] } },

    { "id": "sm3", "type": "static-md", "section": 2,
      "title": [{ "lang": "pt", "text": "Fechamento" }],
      "content": { "item": [{ "lang": "pt", "text": "## O que você levou daqui\n\n..." }] } }
  ],
  "edges": [
    { "from": "sm1", "to": "f1" },
    { "from": "f1", "to": "q1" },
    { "from": "q1", "to": "dm1", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
    { "from": "q1", "to": "sm2" },
    { "from": "sm2", "to": "dm1" },
    { "from": "dm1", "to": "f2" },
    { "from": "f2", "to": "s1" },
    { "from": "s1", "to": "dm2", "when": { "key": "s1.method", "operator": "gte", "value": 1 } },
    { "from": "s1", "to": "sm3" },
    { "from": "dm2", "to": "sm3" }
  ]
}
```

## Errors that actually happen

| Symptom | Cause |
|---------|-------|
| `additionalProperties` rejected | invented a field; the format has no free-form extras |
| id/type mismatch | `{"id": "n3", "type": "static-md"}` — the prefix must be `sm` |
| edges after the fallback are never taken | the unconditional edge was not listed last |
| a branch never triggers | the condition tests a key produced by a node the student has not reached yet |
| unreachable node | wrote the node but forgot the edge into it |
| course stops mid-way | a non-final node with no outgoing edge |
| missing translation | `other-languages` lists `en` but a node title only has `pt` |
| quiz always scores wrong | zero or two options marked `correct: true` |
| `{{STORAGE: f1.goal}}` renders empty | field key is `objetivo`, not `goal`, or the form is downstream |
| condition compares the label | `value` is the stored identifier; never compare to the displayed text |
| a score branch never fires | compared the level against 60; a level runs 0 to `levels-1`. Give the question `points` and test `<id>.percent` |
| `points` rejected | one number per level, in the same order — 6 levels need 6 points |
| feedback contradicts the grade | the prompt of a node with `from` told the AI to grade; it must say how to write, never what to decide |
| fallback leads to the feedback node | the judge's unconditional edge is the path taken when there was no judgement, so it cannot go where a judgement is required |
| judgement anchored on a mark | the `state` read another judgement's level or `q1.percent`; give it the work and the task, not a verdict |
| `judge-model` rejected | it is an alias like `jev-latest`; name the version, `jev-1.13.0` |
