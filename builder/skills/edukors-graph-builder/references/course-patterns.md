# Course design patterns

Recipes for turning the eight brief decisions into a graph. Each section maps to
one decision; combine them.

- [Sizing: nodes and sections](#sizing-nodes-and-sections)
- [Objectives into nodes](#objectives-into-nodes)
- [Traditional courses](#traditional-courses)
- [Challenge-based courses](#challenge-based-courses)
- [Static vs dynamic content](#static-vs-dynamic-content)
- [Images](#images)
- [Linear path vs adaptive graph](#linear-path-vs-adaptive-graph)
- [Grading with a rubric](#grading-with-a-rubric)
- [Writing prompts that hold up](#writing-prompts-that-hold-up)

## Sizing: nodes and sections

The author gives a node count and a section count. Respect both — they are the
budget, not a suggestion. Distribute evenly, then adjust so no section ends on a
content node without an activity.

| Nodes | Sections | Typical shape per section |
|-------|----------|---------------------------|
| 8–10 | 2–3 | 2 content + 1 activity |
| 12–16 | 3–4 | 3 content + 1 activity |
| 20–30 | 4–6 | 4 content + 1–2 activities |

Fixed costs that come out of the budget: `sm1` (welcome), `f1` (only if anything
is dynamic or the challenge is generated), and a closing node. Branch-only nodes
(a remediation `sm`, a deep-dive `sm`) count too — an adaptive course spends 15–25%
of its budget on paths not every student sees.

If the author's count cannot hold the objectives, say so and propose a number.
Do not silently exceed the budget.

## Objectives into nodes

Take the syllabus or competence list and, for each item, decide: which node
teaches it, and which node checks it. Two rules keep the course honest:

1. Every objective has at least one content node and appears in at least one
   activity. An objective that is taught but never checked cannot be assessed;
   one that is checked but never taught is a trap.
2. Every node serves an objective. If you cannot name the objective a node serves,
   the node is filler — cut it and give the budget to a thin objective.

Sections follow the objectives' dependency order, not the source material's table
of contents.

## Traditional courses

Sequential exposition: concepts in didactic order, each building on the last.

```
sm1 welcome ─ sm2 concept ─ sm3 concept ─ q1 check ─ sm4 concept ─ … ─ f2/s1/dm1 closing delivery
```

Per section: introduce the concept, show it working, then check it. The check is
usually a `quiz` (fast, branchable) and once or twice a written delivery (slower,
richer) — see [Grading with a rubric](#grading-with-a-rubric), which costs three
nodes, not one.

A traditional course still benefits from one or two `bool` forks — "want the
formal proof?" — which cost two nodes and make the course feel less like a book.

Close with a written delivery or a summary node that ties the objectives back together.

## Challenge-based courses

The student builds a product; the theory arrives as the input needed for the next
part of it. The spine of the course is the product, not the syllabus.

**Product must be copy-pasteable into a text area.** Valid: report, legal opinion,
analysis, plan, specification, markdown/HTML/LaTeX document, source code, SQL,
config. Not valid: anything requiring a file upload, a drawing, a video, a
deployed system. When the author asks for "an app" or "a system", scope the
deliverable to the artifacts that are text: the spec, the schema, the code.

**Structure** — each section is one stage of the product:

```
sm1 briefing (the challenge, the deliverable, how it is graded)
 ├ sm2 sm3        theory needed for part 1
 ├ f1 s1 dm1      deliver part 1, judge it, comment on it
 ├ sm4 sm5        theory needed for part 2
 ├ f2 s2 dm2      deliver part 2, judged against its own criteria
 └ f3 s3 dm3      final assembled delivery
```

The briefing node states the challenge, the audience of the product, the format,
the length, and the criteria — the student should be able to picture the finished
artifact from node one.

**Delivery in parts or at the end** (author's choice):
- *In parts*: one `form → score → dynamic-md` chain per stage, each judged on its
  own criteria. The student gets feedback while there is still time to use it.
  Preferred default. Budget three nodes per delivery.
- *At the end*: intermediate nodes are content and planning only; a single final
  chain carries the grading. Simpler graph, weaker feedback loop.

**Grading rubric** — every delivery needs one, on its `score` node. One question
per criterion, each with its own scale and its own `points`, so the weights live
in numbers rather than inside a paragraph:

```json
{ "state": { "task": "Part 1 of a consulting report: state the problem and its scope.",
             "answer": "{{STORAGE: f1.text}}" },
  "items": [
    { "key": "problem",
      "instructions": "Judge how clearly the field answer bounds the problem. Do not judge register or length.",
      "criteria": ["No problem stated, or too broad to act on",
                   "A problem, but its edges are left open",
                   "A problem with its scope and exclusions stated"],
      "points": [0, 15, 30] },
    { "key": "stakeholders",
      "instructions": "Judge whether the field answer identifies who is affected. Judge only that.",
      "criteria": ["Names none", "Names them", "Names them and what each one wants"],
      "points": [0, 12, 25] }
  ] }
```

Say in the `instructions` what *not* to weigh — topics not yet taught, typos,
length. `s1.percent` is then the grade for the stage, and `s1.total` the points.

Use the intermediate results to branch: a low `s1.problem` routes the student
through a remediation node before part 2.

**Static vs generated challenge**:
- *Static*: the challenge is written into `sm1` — same for everyone, easy to
  moderate, comparable across students.
- *Generated*: `f1` captures the student's context, pains and desires; a
  `dynamic-md` node right after generates the personalised challenge from them,
  and every later grading prompt reads `{{STORAGE: f1.*}}` so the criteria match
  the challenge the student actually received.

```json
{ "id": "dm1", "type": "dynamic-md",
  "content": { "prompt": [{ "lang": "en", "text": "Write a challenge briefing for a student who works in: {{STORAGE: f1.context}} and wants to solve: {{STORAGE: f1.pain}}. The deliverable is a 2-page consulting report, pasted as markdown. State the scenario, the client, the deliverable, the length and the four grading criteria. 350-450 words. Answer in the student's language." }] } }
```

## Static vs dynamic content

*Static* content is written now, reviewed by the author, identical for everyone —
use it for definitions, methods, anything that must be correct.

*Dynamic* content is generated per student at run time from a prompt — use it for
examples, applications to the student's context, and remediation of the specific
gap an assessment found. Never use it for the canonical definition of a concept.

A good dynamic node reads at least one storage key. A `dynamic-md` node with no
`{{STORAGE:}}` reference is just static content you did not write and cannot
review — if nothing personalises it, write it as `static-md` instead.

Sources of personalisation:
- **Form** (`f1`, near the start): goals, prior experience, context, pains.
- **Assessment gaps**: `q1.percent` for level, `q1.<key>` for the specific miss,
  `s1.<key>` for a judged dimension. A node with `from` is the purpose-built way
  to write from a judgement.

Mixed courses are the norm: static backbone, dynamic examples and remediation.

## Images

Only when the author asked for them. Three options, in order of preference:

1. **Inline SVG in a `static-html` node** — diagrams, timelines, comparisons,
   processes. Self-contained, always available, no licensing issue. This is the
   right answer for most educational images.
2. **Markdown `![alt](url)` in a `static-md` node** — only with a URL you actually
   verified during research. Never invent image URLs; a broken image in node 3
   destroys trust in the whole course.
3. **`dynamic-html` producing a diagram** — when the illustration must reflect the
   student's own data.

Always write meaningful alt text, and make sure the surrounding text carries the
teaching on its own: the image illustrates, it does not replace.

## Linear path vs adaptive graph

**Linear**: one edge out of every node, no `when`. Simple, predictable, fine for
short courses. Even here, add the fallback edges correctly.

**Adaptive**: three shapes, mix freely.

*Remediation after a check* — the standard one:

```json
{ "from": "q1", "to": "sm5", "when": { "key": "q1.percent", "operator": "gte", "value": 70 } },
{ "from": "q1", "to": "sm4" },
{ "from": "sm4", "to": "sm5" }
```

Weak students get `sm4` and rejoin at `sm5`. Threshold 70 is a sensible default;
use 60 for hard material, 80 for prerequisites.

*Targeted remediation* — branch on the specific question missed, using its `key`:

```json
{ "from": "q1", "to": "dm2", "when": { "key": "q1.fractions", "operator": "ne", "value": "three-quarters" } },
{ "from": "q1", "to": "sm5" }
```

Sending the student to a `dynamic-md` node that reads the same key produces
remediation aimed at the actual misconception.

*Student choice* — a `bool` fork:

```json
{ "from": "b1", "to": "sm6", "when": { "key": "b1.answer", "operator": "eq", "value": true } },
{ "from": "b1", "to": "sm8" }
```

Combine when a decision depends on two things:

```json
{ "from": "q1", "to": "sm9", "when": { "and": [
    { "key": "q1.percent", "operator": "gte", "value": 80 },
    { "key": "f1.goal", "operator": "eq", "value": "career" } ] } }
```

*Judged by the AI* — the branch the student did not declare. A `bool` forks on
what the student says about themselves and a quiz on what they got right; a
`choice` forks on what the AI reads in what they wrote. Use it after a `form`
with free text, where the answer that matters is not a number:

```json
{ "id": "c1", "type": "choice",
  "title": [{ "lang": "pt", "text": "Escolher a trilha" }],
  "content": {
    "state": { "task": "Explain in your own words why this works.",
               "answer": "{{STORAGE: f1.text}}" },
    "items": [{ "key": "track",
      "instructions": "Which track does this student need next?",
      "criteria": { "remedial": "Confuses the basic concepts",
                    "standard": "Has the essentials",
                    "advanced": "Goes beyond what was taught",
                    "unclear":  "Too short or too off-topic to tell" } }] } }
```

```json
{ "from": "c1", "to": "sm5", "when": { "key": "c1.track", "operator": "eq", "value": "remedial" } },
{ "from": "c1", "to": "sm7", "when": { "and": [
    { "key": "c1.track",            "operator": "eq",  "value": "advanced" },
    { "key": "c1.track-confidence", "operator": "gte", "value": 0.8 } ] } },
{ "from": "c1", "to": "sm6" }
```

The confidence is there for the branch you would regret taking wrongly: send a
student down the demanding track only when the AI is sure. For anything the
author can weigh in numbers, use a `score` node with one question per dimension
and join them with `and` — when the priorities change, the numbers in the edge
change, not the wording of a prompt.

Three rules for any adaptive graph:

1. **Every branch reunites** — or reaches the intended ending. A path that trails
   off leaves the student stranded mid-course.
2. **The fallback is last** and always exists. Every node with outgoing edges needs
   one unconditional edge, or a student who matches nothing gets stuck. On a
   `choice`, `score` or `noul` node this is not advice but a rule the validators
   enforce: a judgement the AI could not make produces no key, so nothing holds,
   and the player reads "no next step" as the course being finished.
3. **Test only keys already produced** — a condition on a node further down the
   graph never holds, so that edge is dead and the fallback always wins.

The map draws edges that lead back to an earlier node dashed red.

## Grading with a rubric

Anything the student writes and the course judges is three
nodes, and each does one thing:

```
f1  form         the assignment and a text-area  ->  f1.text
s1  score        the rubric: one question per criterion  ->  s1.<key>, s1.percent
dm1 dynamic-md   from: s1  ->  the comment the student reads
```

One judgement, one source of truth. The number that routes the student and the
number the feedback explains are the same number, so they cannot disagree. That is
the whole reason for the shape: two graders over one text will differ, and the
student ends up reading one verdict while the graph acts on another.

**The form** carries `instructions` — the assignment, in markdown, in the
student's language — and one `text-area`. Put the length in `min-words`/
`max-words` when it is a rule, and only in the prose when it is a wish.

**The score** carries the rubric:

- One question per dimension. Two dimensions in one question lower the confidence
  and give you nothing to branch on separately.
- `criteria` describes **situations, not degrees**. "Names one example" tells the
  AI where the line is; "good use of evidence" does not.
- Keep the step between levels even: the answer is a weighted average over them.
- `points` only where there is a grade to give. A question that merely decides the
  next node needs none.
- How sure a judgement must be to count is set by the player, not the course.
  Below that floor nothing is stored and the student takes the fallback — which
  is why that unconditional edge must go somewhere sensible, not to the feedback
  node. A branch that needs more certainty asks for it in its own edge, with
  `<id>.<key>-confidence`.
- The `state` gets **the task and the answer**, never a mark already given.

**The feedback node** says how to write, never what to decide. It receives the
judgement rendered in full — levels, their texts, the weights, the points, the
confidence — so it never needs the rubric repeated in its prompt, and it must not
re-open the verdict.

**The rewrite loop** is the reason to branch on a low level:

```json
{ "from": "s1", "to": "dm1", "when": { "key": "s1.method", "operator": "gte", "value": 1 } },
{ "from": "s1", "to": "sm4" },
{ "from": "sm4", "to": "f1" }
```

Weak work goes to a tips node and back to the form; the student rewrites and the
judgement runs again, replacing `f1.text` and every key of `s1`. Keep the
threshold in the edge and nowhere else — a number repeated in the node text and in
the instructions is a number that will drift.

## Writing prompts that hold up

Course prompts run unattended, on students you will never see. Brief them like a
freelancer: role, input, output shape, length, language.

- Put audience, tone and global rules in `info.system-prompt`, once.
- Put the specific task in the node prompt.
- Name the storage keys you want used and say what they mean.
- State the length in words and the format (markdown, HTML fragment, JSON).
- End with "Answer in the student's language."
- A judgement is not a prompt: its `instructions` ask one question about one named
  field of the `state`, and say what to leave aside — "Do not judge grammar,
  length or tone." A question that excludes nothing weighs everything.
- A node with `from` is briefed on tone and shape only. Never ask it to grade,
  score or evaluate: that number already exists.
