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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();
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

$versions = db_all(
    'SELECT * FROM course WHERE course_uuid = ? ORDER BY created_at DESC, id DESC',
    [$row['course_uuid']]
);
$students = db_all(
    'SELECT p.*, s.name FROM progress p JOIN student s ON s.id = p.student_id
     WHERE p.course_uuid = ? ORDER BY p.updated_at DESC LIMIT 20',
    [$row['course_uuid']]
);

$base     = edukors_config()['base_url'];
$warnings = $row['warnings'] === null ? [] : explode("\n", (string) $row['warnings']);

admin_head($course->title());
?>
<h2><?= h($course->title()) ?></h2>
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
<?php admin_foot();
