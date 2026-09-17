<?php
/** Every course version this server holds. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
admin_require();

$courses = db_all(
    'SELECT c.*,
            (SELECT COUNT(*) FROM progress p WHERE p.course_uuid = c.course_uuid) AS students
     FROM course c
     ORDER BY c.title, c.course_uuid, c.created_at DESC'
);

admin_head('Courses');
?>
<h2>Courses</h2>
<p class="lead">
  <?= count($courses) ?> version<?= count($courses) === 1 ? '' : 's' ?> stored.
  A student opens the published version of a course; importing the same version again replaces it.
</p>
<p><a class="btn primary" href="upload.php">Import a course JSON</a></p>

<?php if ($courses === []): ?>
  <p class="empty">Nothing imported yet.</p>
<?php else: ?>
<table>
  <tr><th>Title</th><th>Version</th><th>Languages</th><th>Status</th>
      <th>Students</th><th>Imported</th><th></th></tr>
  <?php foreach ($courses as $c): ?>
  <tr>
    <td><a href="course.php?id=<?= (int) $c['id'] ?>"><?= h($c['title']) ?></a><br>
        <span class="muted" style="font:11px var(--mono)"><?= h($c['course_uuid']) ?></span></td>
    <td class="num"><?= h($c['version']) ?></td>
    <td class="num"><?= h($c['languages']) ?></td>
    <td><span class="tag <?= h($c['status']) ?>"><?= h($c['status']) ?></span>
        <?php if ($c['warnings'] !== null): ?>
          <span class="tag" title="the import left warnings">!</span>
        <?php endif; ?></td>
    <td class="num"><?= (int) $c['students'] ?></td>
    <td class="num"><?= h(substr((string) $c['created_at'], 0, 10)) ?></td>
    <td><a class="btn" href="course.php?id=<?= (int) $c['id'] ?>">Open</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php admin_foot();
