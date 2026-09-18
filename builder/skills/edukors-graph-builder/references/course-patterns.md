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
sm1 welcome ─ sm2 concept ─ sm3 concept ─ q1 check ─ sm4 concept ─ … ─ e1 closing essay
```

Per section: introduce the concept, show it working, then check it. The check is
usually a `quiz` (fast, branchable) and once or twice an `essay` (slower, richer).

A traditional course still benefits from one or two `bool` forks — "want the
formal proof?" — which cost two nodes and make the course feel less like a book.

Close with an essay or a summary node that ties the objectives back together.

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
 ├ sm2 sm3  theory needed for part 1
 ├ e1       deliver part 1
 ├ sm4 sm5  theory needed for part 2
 ├ e2       deliver part 2
 └ e3       final assembled delivery
```

The briefing node states the challenge, the audience of the product, the format,
the length, and the criteria — the student should be able to picture the finished
artifact from node one.

**Delivery in parts or at the end** (author's choice):
- *In parts*: one `essay` per stage, each graded on its own criteria. The student
  gets feedback while there is still time to use it. Preferred default.
- *At the end*: intermediate nodes are content and planning only; a single final
  `essay` carries the grading. Simpler graph, weaker feedback loop.

**Grading prompt** — every delivery `essay` needs one. Make it specific to the
stage: the criteria with weights, what excellent looks like, what to ignore:

```
Grade this <part 1: problem statement and scope> of a <consulting report> written by
a <second-year business student>. Criteria: problem clearly bounded (30), stakeholders
identified (25), assumptions stated (25), professional register (20).
The theory covered so far is <X, Y>; do not penalise omission of topics not yet taught.
Return JSON: {"score": <0-100>, "feedback": "<3-5 sentences: one thing that works,
two concrete improvements, addressed to the student, in the student's language>"}.
```

Use the intermediate scores to branch: a low score on part 1 routes the student
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
  `e1.score` / `e1.feedback` for qualitative gaps.

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

Three rules for any adaptive graph:

1. **Every branch reunites** — or reaches the intended ending. A path that trails
   off leaves the student stranded mid-course.
2. **The fallback is last** and always exists. Every node with outgoing edges needs
   one unconditional edge, or a student who matches nothing gets stuck.
3. **Test only keys already produced** — a condition on a node further down the
   graph never holds, so that edge is dead and the fallback always wins.

The map draws edges that lead back to an earlier node dashed red.

## Writing prompts that hold up

Course prompts run unattended, on students you will never see. Brief them like a
freelancer: role, input, output shape, length, language.

- Put audience, tone and global rules in `info.system-prompt`, once.
- Put the specific task in the node prompt.
- Name the storage keys you want used and say what they mean.
- State the length in words and the format (markdown, HTML fragment, JSON).
- End with "Answer in the student's language."
- For grading prompts, state what *not* to penalise (topics not yet taught, minor
  typos, length).
