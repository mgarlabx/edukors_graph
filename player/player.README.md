# Player

*Part of [Edukors Graph](../README.md).*

The **player** is the server that delivers Edukors Graph courses to students.

The builder skill already produces a standalone player — one HTML file that runs a course with no server at all. That file is a preview for the author. It keeps progress in one browser, and it asks whatever AI host it happens to be running inside to write the dynamic steps, which works inside an AI assistant and nowhere else.

This server is the other half. It takes the same player, unchanged, and gives it the four things a real deployment needs:

| What it adds        | Why a deployment needs it                                                                                                                                      |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **LTI**       | Students arrive from Moodle, Canvas or Blackboard, already identified.                                                                                         |
| **Inference** | The dynamic steps and the essay grading run here, with this server's own key, from prompts the browser never sees.                                             |
| **Progress**  | Where a student is, what they answered, what the AI wrote for them and what feedback they got, all in MySQL, so any device resumes where the last one stopped. |
| **Download**  | One HTML file the student can keep and run with no network.                                                                                                    |
| **Catalogue** | A public front page listing the published courses, each openable as a map, as a course to take anonymously, or as the file it is written in.                     |

It is written in plain PHP, with no framework and no Composer dependency. PHP 8.1 or later with `pdo_mysql`, `curl`, `openssl` and `json` is the whole requirement.

## How it fits together

```
Moodle / Canvas / Blackboard
    │   OIDC login  →  signed id_token          nothing is ever sent back
    ▼
public/lti/login.php → public/lti/launch.php → session → public/course.php
                                                              │
                        serves assets/course_player.html with │
                          · the course, prompts removed       │
                          · public/assets/bridge.js           │
                                                              ▼
                                       bridge.js intercepts the player's
                                       one model call and routes it to
                              public/api/ai.php   public/api/progress.php
```

The player is used exactly as the skill ships it. Nothing in [assets/course_player.html](assets/course_player.html) is edited — it is a verbatim copy of [the builder's asset](../builder/skill/edukors-graph-builder/assets/course_player.html). When that one changes, copy it over again.

That is possible because the player was already written to ask a *host* for the model, trying, in order, a Claude artifact capability, a `fetch` to `api.anthropic.com`, and a claude.ai bridge. In an ordinary browser only the `fetch` exists, and it is meant to be intercepted. This server steps into that role: [public/assets/bridge.js](public/assets/bridge.js) catches the call and hands it to [public/api/ai.php](public/api/ai.php), which answers in the same envelope the player already reads.

### Why the prompts are not in the page

If the course were served as written, every prompt — including the hidden grading prompt of each essay — would sit in the page source for any student to read, and the model call would carry text of the browser's choosing.

So the course is served with its prompts replaced by a marker naming the node (`Course::withoutPrompts` in [src/course.php](src/course.php)):

| in the database             | in the browser   |
| --------------------------- | ---------------- |
| the prompt of `dm1`       | `#edukors:dm1` |
| the grading prompt of `e1` | `#edukors:e1`  |
| `info.system-prompt`      | removed        |

The bridge reads the node id out of the marker and sends `{"node":"dm1"}` — or, for an essay, `{"node":"e1","text":"…"}`. **There is no prompt in the page to send.** `api/ai.php` then insists on all of this before it calls anything:

1. there is a session, opened by an LTI launch;
2. the node exists in the course this student is in, and is of the right type;
3. it is the step the student is actually on, so nobody can have the whole course written at once;
4. a dynamic step already written is returned from the database, with no second call;
5. an essay is at most 20,000 characters;
6. the student is under their hourly limit and the server under its daily one;
7. the prompt is built here, from the stored course, with `{{STORAGE: key}}` filled in from the student's own saved answers;
8. the model, the token limit and the temperature come from `config.php` and from nowhere else.

That is about the **page a student is given**. The catalogue publishes something else: the course JSON as it was imported, prompts and all — see below. The two are not in conflict, they answer different questions. A prompt in the page is a prompt the browser can edit and send back as its own; a prompt in a published document is the author's work, read by whoever the author published it to. If a server holds courses whose prompts should not be public, that server should not publish its catalogue — [public/catalog/](public/catalog/) is the only thing here that opens without a launch, and it is a folder that can simply be deleted.

## Installing

1. **Database.** Create one, then apply the seven tables to it:

   ```
   mysql -u root -e "CREATE DATABASE edukors_graphs DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -u root edukors_graphs < sql/schema.sql
   ```

   `schema.sql` creates no database of its own, because on shared hosting you do not get to name one — the control panel hands you something like `u123456789_courses`.
2. **Configuration.** Copy `private/config.sample.php` to `private/config.php` and fill it in: the database, the OpenRouter key and model, an admin password hash, and `base_url`.

   For the password, run this and paste what it prints:

   ```
   php tools/admin-password.php
   ```

   It asks for the password without showing it and gives back the hash. Never copy a hash from anywhere else — a hash belongs to one particular password, so borrowing one means the only password that opens the admin is whatever that example used.

   Most values can also come from an environment variable, which always wins — see the comments in the sample file.
3. **Web root.** Point the site at `player/public`, **not** at `player`. Everything else — the configuration, the code, the player template — then sits above the web root where no request can reach it. `private/.htaccess` denies access as a second line of defence, for hosting that will not let you move the root.
4. **HTTPS.** Required in practice, not by choice: see the note on cookies below.
5. **Courses.** Either

   ```
   php tools/import.php course.json --publish
   ```

   or the admin at `/admin/`, which takes an upload or pasted JSON.
6. **Platforms.** Register each LMS at `/admin/platforms.php`. That page also shows the four URLs the LMS administrator will ask you for.

## Installing in a subfolder of an existing site

Step 3 above assumes the player gets a web root of its own. On shared hosting it usually cannot: there is one `public_html`, it already holds a site, and the player has to live at `example.org/graphs` beside it. That works, and needs nothing changed in the code.

**Copy the whole application into the subfolder**, keeping its shape:

```
public_html/
└── graphs/
    ├── public/          ← the only folder the web may reach
    ├── src/  assets/  sql/  tools/
```

`tools/deploy.sh` does the copying, and leaves out what an installation has no use for:

```
./tools/deploy.sh /path/to/public_html/graphs
./tools/deploy.sh --dry-run /path/to/public_html/graphs    # see it first
```

**Send the app's URL into `public/`** with a rewrite. On Apache, in the `.htaccess` of the web root:

```apache
RewriteRule ^graphs$ /graphs/ [R=301,L]

RewriteCond %{REQUEST_URI} !^/graphs/public/
RewriteRule ^graphs(?:/(.*))?$ /graphs/public/$1 [L]
```

That single pair of rules is what makes the subfolder install safe: anything outside `graphs/public/` — the code, the SQL, the player template — is rewritten to a path that does not exist and answers 404. The `.htaccess` files in `src/`, `sql/`, `tools/` and `assets/` deny those folders a second time, in case the rewrite is ever removed.

If the site already has a catch-all rule, **exclude the new path from it**, or the player is silently swallowed by whatever the catch-all points at:

```apache
RewriteCond %{REQUEST_URI} !^/graphs(/|$)
```

**Put the configuration one level above the web root.** `src/config.php` looks for `../../../private/graphs.config.php`, which from `public_html/graphs/src` is the account root — where shared hosting expects a private folder to sit, beside `public_html` and unreachable by HTTP.

Nothing else changes. Every URL inside the player is relative, so it finds `api/ai.php` and `assets/bridge.js` through the rewrite exactly as it would at a root of its own, and `base_url` (`https://example.org/graphs`) is what scopes the session cookies to `/graphs` so they never collide with the cookies of the site it lives beside.

## Registering the tool in an LMS

| The LMS asks for        | Give it                                                          |
| ----------------------- | ---------------------------------------------------------------- |
| Login / initiate URL    | `https://your.server/lti/login.php`                            |
| Redirect / callback URL | `https://your.server/lti/launch.php`                           |
| Launch URL for a course | `https://your.server/lti/launch.php?course=<course-id>`        |
| Public keyset URL       | `https://your.server/lti/jwks.php` — an empty set, on purpose |

The course can also be named by a custom parameter `course_id` instead of the query string; both are accepted.

**The key set is empty, and that is not an omission.** LTI asks a tool for a key pair so the platform can verify messages the tool sends it. This integration is one-way — no grade passback, no roster, no deep linking — so there is no message to sign and there are no keys. The endpoint exists because every registration form asks for its address; a platform only ever reads it to check something the tool sent, which never happens. What is implemented is the receiving half only: the platform's `id_token` is checked for its RS256 signature against the platform's published keys, its issuer, its audience, its expiry, its nonce, its deployment, and a `state` this server issued and has not already spent.

### In Moodle, step by step

Registration goes in two directions, which is why it cannot be done in one sitting: Moodle needs this tool's URLs to create the registration, and only then does it produce the client and deployment ids this server needs back.

**1. Register the tool in Moodle.** Site administration → Plugins → Activity modules → External tool → **Manage tools** → *configure a tool manually*.

Not the **Add tool** box at the top of that page: it takes the registration URL of a tool that supports dynamic registration, and this one does not. Pasting any of the addresses below into it gets you a malformed tool that launches with a plain GET instead of a login — which is the one failure that looks like a server problem and is not. The link you want is *configure a tool manually*, underneath that box.

| Field                    | Value                                                              |
| ------------------------ | ------------------------------------------------------------------ |
| Tool name                | Edukors                                                            |
| Tool URL                 | `https://your.server/lti/launch.php`                             |
| LTI version              | **LTI 1.3**                                                  |
| Public key type          | Keyset URL                                                         |
| Public keyset URL        | `https://your.server/lti/jwks.php`                               |
| Initiate login URL       | `https://your.server/lti/login.php`                              |
| Redirection URI(s)       | `https://your.server/lti/launch.php`                             |
| Default launch container | Embed, without blocks (or New window — see the cookie note below) |

Under **Privacy**, turn on *Share launcher's name with tool* and *Share launcher's email with tool*. Without them the launch still works, but every student shows up unnamed in the admin.

**2. Copy what Moodle generated.** Back in Manage tools, on the card of the tool you just saved, open **View configuration details**. It shows:

| Moodle calls it            | This server calls it      |
| -------------------------- | ------------------------- |
| Platform ID                | Issuer                    |
| Client ID                  | Client ID                 |
| Deployment ID              | Deployment ID             |
| Authentication request URL | Authentication URL        |
| Public keyset URL          | Public key set (JWKS) URL |

**3. Register the platform here.** `/admin/platforms.php`, paste those five, save.

**4. Add the activity to a Moodle course.** In the course, *Add an activity* → **External tool** → choose the preconfigured tool. Then point it at one course of yours, either by setting the activity's **Tool URL** to `https://your.server/lti/launch.php?course=<course-id>`, or by adding a custom parameter:

```
course_id=<course-id>
```

The course id is the `info.course-id` of the JSON, and the admin shows the whole URL ready to copy on each course's page.

**5. Open it as a student.** The first launch creates the student and their progress; from then on they resume wherever they were, on any device that launches from the same Moodle account.

### The cookie problem, which every LTI tool has

A launch happens inside an iframe of the LMS, which makes this server's session cookie a third-party cookie. Three attributes have to be right, and the code sets all three:

- **`SameSite=None`**, or the cookie is not sent inside the frame at all. Over plain HTTP it would be refused, so the code falls back to `Lax` — enough for local development, no good in production.
- **`Secure`**, which a browser requires before it will accept `SameSite=None`. So **HTTPS is not optional** in production.
- **`Partitioned`**, because Chrome no longer stores third-party cookies at all. It does store a partitioned one, keyed to the site doing the framing (CHIPS). PHP cannot write that attribute through `setcookie()`, so `edukors_partition_cookie()` rewrites the header by hand.

Safari blocks third-party cookies outright, partitioned or not. There the cure is on the LMS side: set the activity to **open in a new window**. All three platforms support that.

If a launch succeeds and the course page then says "This course opens from your learning platform", the cookie was dropped. That is this, and nothing else.

The session cookie is `edukors_graphs`, scoped to the path in `base_url`, so it never shares cookie space with another application on the same domain.

## The catalogue

`public/catalog/` is the public face of the server, and the only part of it anybody may open: the front door redirects there, and it lists every course whose status is `published` — in the order the admin put them in, the categories by theirs and the courses by theirs inside each one. A draft is not a course the public has, so it is not listed, and asking for one by its id is answered the same way an invented id is: with a 404 that says nothing about what exists.

Each course is a line, with three things you can do with it:

| Icon         | What opens                                                                                                                                                               |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **map**      | `catalog/map.php` — the course graph, drawn by [assets/course_viewer.html](assets/course_viewer.html), the viewer the builder skill ships. The same file `build_viewer.py` writes on a laptop, built here from what the database holds. |
| **play**     | `catalog/play.php` — the course itself, anonymously.                                                                                                                     |
| **JSON**     | `catalog/json.php` — the file it is written in, every object and array folding, a folded step naming its id and its type. Downloading it is offered there, on the page of somebody already looking at the file, rather than as a fourth icon on every line. `catalog/download.php` is what that link asks for. |

**The anonymous course is the offline copy.** It is the single self-contained file `download.php` hands a student, served as a page instead of as a download. That is what makes it safe to leave open to anyone: once the page has loaded it asks this server for nothing, so there is no session to start, no progress to write down and no model to pay for. The steps written by AI say so and let the visitor carry on, exactly as in the downloaded copy — and their prompts are not in that page, as they are not in any player page. Whoever wants those steps to actually run takes the course from their learning platform, where there is a student to attribute the work to.

**The JSON is the document, whole.** Prompts included, `info.system-prompt` included: it is byte for byte what was imported, so that whoever downloads it can validate it against the schema it names, open it in the builder, or import it into a server of their own.

The catalogue has no CSS of the admin's and no session of anyone's; [public/assets/catalog.css](public/assets/catalog.css) and [public/assets/catalog-json.js](public/assets/catalog-json.js) are all it loads, and it sets no cookie.

## Running a course

`public/course.php` serves the player and nothing else: the student sees the player, and only the player. Light and dark follow the browser, as the player already does.

`public/assets/bridge.js` adds, in about 160 lines:

- the model call, routed to this server;
- the saved state, written into `localStorage` before the player boots, so a launch resumes wherever the student left off — the server's copy wins;
- every change copied back to `api/progress.php`, debounced, and flushed before any model call and on `pagehide`;
- a download link in the player's own bar.

## The offline copy

`public/download.php` is the PHP equivalent of the skill's `build_player.py`: it splices the course into the same `<script id="edukors-player-boot">` block and hands back one self-contained file.

It also carries the student's own work — the steps the AI has already written for them, their answers and their feedback all travel with the file.

For the steps they have not reached, there is no model to ask, so a short script answers the player's request itself with a note:

> This step is written by artificial intelligence and does not work in the offline copy of the course. Go on to the next step.

It answers *successfully* rather than with an error on purpose: the player then shows the note as the step's content, and the student moves on — instead of a failure with a retry button that could never succeed. The prompts are not in the downloaded file either.

Everything else — reading, prebuilt HTML, quizzes, forms, yes/no questions, the branching — works with no network at all.

One thing does not travel: an image a course refers to by URL, as the [samples](../samples/samples.README.md) do with Wikimedia Commons. The markup goes into the file, the file does not. A course meant to be taken offline should carry its illustrations as inline SVG in a `static-html` node, which is what the builder's design patterns already recommend.

## The database

Eight tables, in `sql/schema.sql`. The course JSON is stored whole, in `course.doc`: the schema is the contract of this project, and taking it apart into tables would only create a second, diverging description of it. Only what listing and routing need is copied into columns.

| Table            | What it holds                                                               |
| ---------------- | --------------------------------------------------------------------------- |
| `category`     | the shelves the admin lists courses on: a name and a place in the order      |
| `course`       | one row per imported version, with the JSON and the import warnings         |
| `lti_platform` | the LMSs allowed to launch, and their cached public keys                    |
| `lti_launch`   | the state and nonce of a login in flight, for ten minutes                   |
| `student`      | one row per person, identified by platform and `sub`                      |
| `progress`     | where each student is, plus the state object mirrored from the player       |
| `node_state`   | what each step produced: the AI's text, the answer, the score, the feedback |
| `ai_call`      | every call to the model, for the rate limit and for the bill                |

`progress.state` and `node_state` have different jobs. `state` is the literal mirror of what the player keeps in `localStorage`, and it is what makes a course resumable on another device. `node_state` is the server's own record: `api/ai.php` writes a generated step there *before* the student sees it, which is what freezes the content and what stops a page reload from paying twice.

## Importing and validation

`src/validate.php` is a port of the builder's `validate_course.py` — its structure, graph and storage-key layers. An **error** stops the import: it is something that would break in front of a student (an edge to a node that does not exist, an id whose prefix disagrees with its type, an unconditional edge that is not last, a `{{STORAGE: key}}` nothing produces). A **warning** is kept with the course and shown on its page in the admin.

The one check not ported is how the correct answers of a quiz are spread across the options. That is a judgement about teaching rather than about running the course, and leaving it in the skill avoids two implementations of it drifting apart. Run `validate_course.py` for that.

**How a course is listed.** The **How it is listed** card on a course's page in the admin holds three things the JSON has no say in: the **name** this server lists the course under whatever `info.title` says, the **category** it is on, and its **order** inside that category, smallest first. All three belong to the course rather than to one of its versions — every version already stored takes them, and `src/import.php` hands them to the next version imported, which is what `course.title_custom` is for. Emptying the name hands each version its own title from the JSON back. Students see none of it: the player takes the title from the JSON, in the language they are reading, and never hears of the categories.

Categories are managed at `/admin/categories.php` — a name and a number each, the number deciding where the group comes in the list. `course.category_id` is the schema's only foreign key, and it is `ON DELETE SET NULL`: removing a category leaves its courses alone, on no category, listed after the rest.

**Removing a course.** The **Remove** card at the end of a course's page takes either one version or the course entire. Removing one version of several leaves the others and moves a student who was reading it to the version that remains, which is what their next launch would have done anyway. Removing the course takes every version and the progress of everyone who had started it — where they were, what they answered, what the model wrote for them. The `ai_call` rows stay: that log is the bill, so it keeps them with `progress_id` emptied. Neither can be undone, and re-importing the JSON brings the course back with nobody in it.

## Notes on running this

- **KaTeX.** The player fetches KaTeX 0.18.6 from `cdnjs.cloudflare.com`, with an integrity hash, the first time a step contains LaTeX. Under a strict CSP in the LMS, either allow that origin or host KaTeX yourself. Without it the LaTeX source stays readable; nothing breaks.
- **Two tabs at once.** The state is mirrored last-write-wins, and every launch reseeds the browser from the database. Two tabs open on the same course at the same time is a known case, not a handled one.
- **The quiz answers are in the page.** The player runs in the browser, so `options[].correct` and the authored feedback are in the source, as they are in any client-side player. The prompts are not. Closing the answers as well would mean rendering the steps on the server, which is a different design.
- **When the model fails**, the player shows a notice with "Try again" and lets the student carry on. An ungraded essay writes no `<id>.score`, so an edge testing that score does not hold and the student takes the fallback — a path the course already knows how to handle.
- **`dev_mode`** in `config.php` opens `course.php?dev=<course-id>` with a stand-in student and no LMS. It is for working on the server. Never turn it on where students can reach it.

## The files

```
player/
├─ sql/schema.sql              the database
├─ private/config.sample.php   the configuration to copy; never in the web root
├─ assets/course_player.html   the skill's player, verbatim
├─ assets/course_viewer.html   the skill's map viewer, verbatim
├─ src/
│  ├─ config.php   db.php   session.php   admin.php     plumbing
│  ├─ course.php                          a course: text, graph, conditions, prompts
│  ├─ validate.php                        what may be imported
│  ├─ import.php                          importing, for the CLI and the admin
│  ├─ build.php                           the player HTML, online and offline, and the map
│  ├─ catalog.php                         what is published, and in what order
│  ├─ progress.php                        where a student is, and what they produced
│  ├─ ai.php                              prompts, guards, OpenRouter
│  ├─ lti.php  jwt.php                    receiving a launch
│  └─ dev.php                             the stand-in student
├─ public/                     ← the web root
│  ├─ index.php  course.php  download.php
│  ├─ catalog/…                           the public list: map, play, json (and its download)
│  ├─ lti/login.php  lti/launch.php  lti/jwks.php
│  ├─ api/ai.php  api/progress.php
│  ├─ assets/bridge.js  assets/admin.css  assets/catalog.css  assets/catalog-json.js
│  └─ admin/…                             courses, categories, students, platforms, AI calls
└─ tools/
   ├─ import.php               php tools/import.php course.json --publish
   ├─ admin-password.php       php tools/admin-password.php
   └─ deploy.sh                ./tools/deploy.sh /path/to/site/graphs
```

The admin borrows the look of the course viewer the skill ships — grey paper, one hairline round every card, one blue accent, monospace for ids and storage keys. Students never see it; they only ever see the player.
