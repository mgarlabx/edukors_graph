<?php
/** One course version: what it contains, how to hand it to an LMS. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
require_once __DIR__ . '/../../src/course.php';
admin_require();

$id  = (int) ($_GET['id'] ?? 0);
$row = db_row('SELECT * FROM course WHERE id = ?', [$id]);
if ($row === null) {
    http_response_code(404);
    admin_head('Not found');
    echo '<p class="empty">No such course.</p>';
    admin_foot();
    exit;
}

$versions = db_all(
    'SELECT * FROM course WHERE course_uuid = ? ORDER BY created_at DESC, id DESC',
    [$row['course_uuid']]
);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    // How the course is listed: all three belong to the course rather than to
    // one of its versions, so every version already stored takes them, and
    // src/import.php hands them to the next version imported.
    if (($_POST['action'] ?? '') === 'listing') {
        $title    = trim((string) ($_POST['title'] ?? ''));
        $category = (int) ($_POST['category_id'] ?? 0);
        $order    = (int) ($_POST['sort_order'] ?? 0);

        db_run(
            'UPDATE course SET category_id = ?, sort_order = ? WHERE course_uuid = ?',
            [$category > 0 ? $category : null, $order, $row['course_uuid']]
        );

        if ($title !== '') {
            db_run(
                'UPDATE course SET title = ?, title_custom = 1 WHERE course_uuid = ?',
                [mb_substr($title, 0, 255), $row['course_uuid']]
            );
            admin_flash("This course is listed as $title now.", 'ok');
        } else {
            // An empty name field hands the name back to the JSON, where each
            // version has a title of its own.
            $versions = db_all('SELECT id, doc FROM course WHERE course_uuid = ?', [$row['course_uuid']]);
            foreach ($versions as $v) {
                db_run(
                    'UPDATE course SET title = ?, title_custom = 0 WHERE id = ?',
                    [mb_substr(Course::fromJson($v['doc'])->title(), 0, 255), (int) $v['id']]
                );
            }
            admin_flash('Saved. The name comes from the course JSON again.', 'ok');
        }
        header('Location: course.php?id=' . $id);
        exit;
    }

    // Removing a version, or the course entire. The second takes the students'
    // progress with it -- there is nothing left for it to belong to -- but not
    // the AI calls: that log is the bill, and it is kept with the link to the
    // deleted progress emptied rather than the row thrown away.
    if (($_POST['action'] ?? '') === 'delete-version' && count($versions) > 1) {
        $heir = db_value(
            "SELECT id FROM course WHERE course_uuid = ? AND id <> ?
             ORDER BY status = 'published' DESC, created_at DESC, id DESC LIMIT 1",
            [$row['course_uuid'], $id]
        );
        // A student reading this version is moved to the one that remains, as
        // their next launch would have moved them anyway.
        db_run('UPDATE progress SET course_id = ? WHERE course_id = ?', [(int) $heir, $id]);
        db_run('DELETE FROM course WHERE id = ?', [$id]);
        admin_flash("Version {$row['version']} of {$row['title']} is gone.", 'ok');
        header('Location: index.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'delete-course') {
        $progress = array_column(
            db_all('SELECT id FROM progress WHERE course_uuid = ?', [$row['course_uuid']]),
            'id'
        );
        if ($progress !== []) {
            $holes = implode(', ', array_fill(0, count($progress), '?'));
            db_run("UPDATE ai_call SET progress_id = NULL WHERE progress_id IN ($holes)", $progress);
            db_run("DELETE FROM node_state WHERE progress_id IN ($holes)", $progress);
        }
        db_run('DELETE FROM progress WHERE course_uuid = ?', [$row['course_uuid']]);
        db_run('DELETE FROM course WHERE course_uuid = ?', [$row['course_uuid']]);
        admin_flash(
            "{$row['title']} is gone: " . count($versions) . ' version'
            . (count($versions) === 1 ? '' : 's') . ', and '
            . (count($progress) === 1 ? 'one student' : count($progress) . ' students')
            . ' who had started it.',
            'ok'
        );
        header('Location: index.php');
        exit;
    }

    $status = (string) ($_POST['status'] ?? '');
    if (in_array($status, ['draft', 'published', 'archived'], true)) {
        // Only one version of a course is the published one.
        if ($status === 'published') {
            db_run(
                "UPDATE course SET status = 'archived'
                 WHERE course_uuid = ? AND id <> ? AND status = 'published'",
                [$row['course_uuid'], $id]
            );
        }
        db_run('UPDATE course SET status = ? WHERE id = ?', [$status, $id]);
        admin_flash("This version is now $status.", 'ok');
    }
    header('Location: course.php?id=' . $id);
    exit;
}

$course = Course::fromJson($row['doc']);
$nodes  = $course->nodes();

$counts = [];
foreach ($nodes as $node) {
    $type = (string) ($node['type'] ?? '');
    $counts[$type] = ($counts[$type] ?? 0) + 1;
}

$categories = db_all('SELECT id, title FROM category ORDER BY sort_order, title');

$students = db_all(
    'SELECT p.*, s.name FROM progress p JOIN student s ON s.id = p.student_id
     WHERE p.course_uuid = ? ORDER BY p.updated_at DESC LIMIT 20',
    [$row['course_uuid']]
);
// The list above stops at twenty; what is about to be deleted does not.
$started = (int) db_value('SELECT COUNT(*) FROM progress WHERE course_uuid = ?', [$row['course_uuid']]);

$base     = edukors_config()['base_url'];
$warnings = $row['warnings'] === null ? [] : explode("\n", (string) $row['warnings']);

admin_head((string) $row['title']);
?>
<h2><?= h($row['title']) ?></h2>
<p class="lead">
  <?= h($course->author()) ?> · v<?= h($row['version']) ?> ·
  <?= h($row['languages']) ?> ·
  <span class="tag <?= h($row['status']) ?>"><?= h($row['status']) ?></span>
</p>

<?php if ($warnings !== []): ?>
<div class="notice">
  <strong>The import left <?= count($warnings) ?> warning<?= count($warnings) === 1 ? '' : 's' ?>.</strong>
  <ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row">
  <div class="card">
    <h3>Contents</h3>
    <p><?= count($nodes) ?> nodes, <?= count($course->doc()['edges'] ?? []) ?> edges,
       starting at <span class="nid"><?= h((string) $course->firstNodeId()) ?></span></p>
    <p>
      <?php foreach ($counts as $type => $n): ?>
        <span class="chip" style="background:<?= h(admin_type_colour($type)) ?>"><?= h($type) ?> <?= $n ?></span>
      <?php endforeach; ?>
    </p>
  </div>

  <div class="card">
    <h3>Give these to the LMS</h3>
    <p style="margin:0 0 6px">Login URL<br><span class="key"><?= h($base) ?>/lti/login.php</span></p>
    <p style="margin:0 0 6px">Redirect URL<br><span class="key"><?= h($base) ?>/lti/launch.php</span></p>
    <p style="margin:0 0 6px">Launch URL for this course<br>
      <span class="key"><?= h($base) ?>/lti/launch.php?course=<?= h($row['course_uuid']) ?></span></p>
    <p style="margin:0 0 6px">Public keyset URL<br>
      <span class="key"><?= h($base) ?>/lti/jwks.php</span></p>
    <p class="muted" style="font-size:12px;margin:10px 0 0">
      The key set is empty on purpose: this tool never answers the platform, so it signs nothing.
    </p>
  </div>
</div>

<div class="card">
  <h3>How it is listed</h3>
  <form method="post">
    <?= admin_csrf_field() ?>
    <input type="hidden" name="action" value="listing">
    <div class="row">
      <div style="flex: 3 1 320px">
        <label for="title">Name</label>
        <input id="title" name="title" type="text" maxlength="255" value="<?= h($row['title']) ?>">
      </div>
      <div style="flex: 2 1 200px">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id">
          <option value="0">— none —</option>
          <?php foreach ($categories as $k): ?>
            <option value="<?= (int) $k['id'] ?>"<?= (int) $row['category_id'] === (int) $k['id'] ? ' selected' : '' ?>><?= h($k['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex: 0 1 110px">
        <label for="sort_order">Order</label>
        <input id="sort_order" name="sort_order" type="number" step="1" value="<?= (int) $row['sort_order'] ?>">
      </div>
    </div>
    <p style="margin-top:14px"><button class="btn primary" type="submit">Save</button></p>
  </form>
  <p class="muted" style="font-size:12.5px;margin:10px 0 0">
    The three belong to the course, not to this version: every version stored takes them, and
    the next one imported arrives with them. <strong>Name</strong> is what this server lists the
    course under, and the title in the JSON no longer replaces it — that title is
    <q><?= h($course->title()) ?></q><?= (int) $row['title_custom'] === 1 ? '' : ', which is what this is' ?>,
    and emptying the field goes back to it. <strong>Order</strong> places the course inside its
    category, smallest first, courses sharing a number falling back to their name.
    Students see none of this: the player takes the title from the JSON, in the language they
    are reading, and never hears of the categories.
  </p>
</div>

<div class="card">
  <h3>Status</h3>
  <form method="post" style="display:flex;gap:8px;align-items:center">
    <?= admin_csrf_field() ?>
    <select name="status" style="width:auto">
      <?php foreach (['draft', 'published', 'archived'] as $s): ?>
        <option value="<?= $s ?>"<?= $row['status'] === $s ? ' selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Change</button>
    <span class="muted" style="font-size:12.5px">Publishing this version archives the other published one.</span>
  </form>
</div>

<?php if (count($versions) > 1): ?>
<div class="card">
  <h3>Versions of this course</h3>
  <table>
    <tr><th>Version</th><th>Status</th><th>Imported</th><th></th></tr>
    <?php foreach ($versions as $v): ?>
    <tr>
      <td class="num"><?= h($v['version']) ?></td>
      <td><span class="tag <?= h($v['status']) ?>"><?= h($v['status']) ?></span></td>
      <td class="num"><?= h(substr((string) $v['created_at'], 0, 16)) ?></td>
      <td><?php if ((int) $v['id'] !== $id): ?>
            <a class="btn" href="course.php?id=<?= (int) $v['id'] ?>">Open</a>
          <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h3>Students</h3>
  <?php if ($students === []): ?>
    <p class="empty">Nobody has opened this course yet.</p>
  <?php else: ?>
  <table>
    <tr><th>Student</th><th>Step</th><th>Progress</th><th>Last seen</th><th></th></tr>
    <?php foreach ($students as $s): ?>
    <tr>
      <td><?= h($s['name'] ?? '—') ?></td>
      <td><span class="nid"><?= h($s['current_node'] ?? 'finished') ?></span></td>
      <td><div class="meter"><i style="width:<?= (int) $s['percent'] ?>%"></i></div></td>
      <td class="num"><?= h(substr((string) $s['updated_at'], 0, 16)) ?></td>
      <td><a class="btn" href="student.php?id=<?= (int) $s['id'] ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<div class="card">
  <h3>Remove</h3>
  <p style="margin:0 0 12px">
    <?php if (count($versions) > 1): ?>
      This course has <?= count($versions) ?> versions stored. Removing one leaves the others,
      and a student who was reading it carries on in the version that remains — which is what
      their next launch would have done anyway.
    <?php else: ?>
      This is the only version stored, so removing the course removes it.
    <?php endif; ?>
    Removing the course removes every version and the
    <?= $started === 1 ? 'one student who' : $started . ' students who' ?>
    started it: where they were, what they answered, what the model wrote for them.
    The AI calls stay in the log — that log is the bill — with the student on them emptied.
    Neither can be undone, and re-importing the JSON brings the course back with nobody in it.
  </p>
  <p style="display:flex;gap:8px;align-items:center;margin:0">
    <?php if (count($versions) > 1): ?>
    <?php $others = count($versions) - 1; ?>
    <form method="post" onsubmit="return confirm('Remove version <?= h($row['version']) ?>? The <?= $others === 1 ? 'other one stays' : "other $others stay" ?>.')">
      <?= admin_csrf_field() ?>
      <input type="hidden" name="action" value="delete-version">
      <button class="btn danger" type="submit">Remove version <?= h($row['version']) ?></button>
    </form>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('Remove <?= h(addslashes($row['title'])) ?> entirely — every version, and <?= $started ?> student<?= $started === 1 ? '' : 's' ?> with it? This cannot be undone.')">
      <?= admin_csrf_field() ?>
      <input type="hidden" name="action" value="delete-course">
      <button class="btn danger" type="submit">Remove the course</button>
    </form>
  </p>
</div>

<?php admin_foot();
