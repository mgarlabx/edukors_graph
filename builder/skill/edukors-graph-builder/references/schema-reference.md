# Course format — authoring reference

The authoritative contract is `../assets/course.schema.json`. This file is the
working version: what to write, what each field is for, and where courses break.
How the frontend runs a course — the answer an essay grading prompt must ask
for, the order options are shown in, what happens when an edge leads back to a
visited node, how `{{STORAGE: key}}` is filled in, how HTML is sandboxed, and
which texts accept markdown — is defined in the field descriptions of the
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
| `system-prompt` | no | English; sent as system prompt on every AI call of the course |

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
| `dynamic-md` | `dm` | `prompt` (localized; normally English only) |
| `dynamic-html` | `dh` | `prompt` (localized; normally English only) |
| `essay` | `e` | `instructions` (localized), `prompt` (plain English string) |
| `quiz` | `q` | `items` — list of questions |
| `form` | `f` | `items` — list of fields |
| `bool` | `b` | `question`, optional `yes-label`, `no-label`, `default` |

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

### essay

The student writes; the AI grades. `instructions` is what the student sees;
`prompt` is the hidden grading instruction, a single English string.

```json
{
  "id": "e1", "type": "essay", "section": 3,
  "title": [{ "lang": "pt", "text": "Explique com suas palavras" }],
  "content": {
    "instructions": [{ "lang": "pt", "text": "Em 150-250 palavras, explique..." }],
    "prompt": "Grade this student text about equivalent fractions. Criteria: correct definition (40), valid example (40), clarity (20). Return JSON: {\"score\": <0-100>, \"feedback\": \"<2-4 sentences, addressed to the student, in the student's language>\"}."
  }
}
```

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
require `options` (≥ 2); text types must not have them.

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

## Storage keys

Only four node types store data, and the key is always `<node-id>.<name>`:

| Node | Keys produced |
|------|---------------|
| essay `e1` | `e1.text`, `e1.score` (0–100), `e1.feedback` |
| quiz `q1` | `q1.score`, `q1.total`, `q1.percent` (0–100), plus `q1.<key>` per keyed question |
| form `f1` | one per field: `f1.goal`, `f1.pain`… (`check` fields store a list) |
| bool `b1` | `b1.answer` — `true` / `false` |

They have exactly two uses: `{{STORAGE: key}}` inside prompts (dynamic nodes and
essay grading), and `when` on edges. `dynamic-*` nodes produce nothing.

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
  { "key": "e1.score", "operator": "gte", "value": 60 },
  { "key": "f1.goal", "operator": "eq", "value": "curiosity" }
] }
```

A condition on a key the student has not produced yet never holds. That is a
feature — you can test a node that may have been skipped — but it also means a
condition placed *before* the node that produces its key is dead weight.

## A complete miniature course

Six nodes, two sections, one adaptive branch and one fork. Use it as the shape
template; a real course has more content per node.

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

    { "id": "e1", "type": "essay", "section": 2,
      "title": [{ "lang": "pt", "text": "Explique com suas palavras" }],
      "content": {
        "instructions": [{ "lang": "pt", "text": "Em 150 palavras, explique como somar 1/3 e 1/6 para um colega." }],
        "prompt": "Grade this student explanation of adding fractions with different denominators. Criteria: correct method (50), clarity of explanation (30), valid example (20). Return JSON: {\"score\": <0-100>, \"feedback\": \"<2-4 sentences addressed to the student, in the student's language>\"}." } }
  ],
  "edges": [
    { "from": "sm1", "to": "f1" },
    { "from": "f1", "to": "q1" },
    { "from": "q1", "to": "dm1", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
    { "from": "q1", "to": "sm2" },
    { "from": "sm2", "to": "dm1" },
    { "from": "dm1", "to": "e1" }
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
