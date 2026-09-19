<?php
/** Every course version this server holds. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
require_once __DIR__ . '/../catalog/icons.php';
admin_require();

// The order the admin chose: the categories in theirs, the courses in theirs
// inside each one, and whatever was never placed anywhere at the end. In MySQL
// a comparison is 0 or 1, so `sort_order IS NULL` sorts the unplaced last.
$courses = db_all(
    'SELECT c.*, k.title AS category, k.sort_order AS category_order,
            (SELECT COUNT(*) FROM progress p WHERE p.course_uuid = c.course_uuid) AS students
     FROM course c
     LEFT JOIN category k ON k.id = c.category_id
     ORDER BY k.sort_order IS NULL, k.sort_order, k.title,
              c.sort_order, c.title, c.course_uuid, c.created_at DESC'
);

// With nothing on a shelf there is nothing to group, and a lone "on no
// category" heading over the whole table says less than no heading at all.
$shelves = array_filter($courses, static fn($c) => $c['category'] !== null) !== [];

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
  <tr><th>Title</th><th>Version</th><th>Status</th>
      <th>Students</th><th>Imported</th><th></th></tr>
  <?php $shelf = false; foreach ($courses as $c): ?>
    <?php if ($shelves && $shelf !== $c['category']): $shelf = $c['category']; ?>
    <tr><td class="shelf" colspan="6"><?= $shelf === null ? 'On no category' : h($shelf) ?></td></tr>
    <?php endif; ?>
  <tr>
    <td><?= h($c['title']) ?></td>
    <td class="num"><?= h($c['version']) ?></td>
    <td><span class="tag <?= h($c['status']) ?>"><?= h($c['status']) ?></span>
        <?php if ($c['warnings'] !== null): ?>
          <span class="tag" title="the import left warnings">!</span>
        <?php endif; ?></td>
    <td class="num"><?= (int) $c['students'] ?></td>
    <td class="num"><?= h(substr((string) $c['created_at'], 0, 10)) ?></td>
    <td class="actions">
      <?= catalog_action('course.php?id=' . (int) $c['id'], 'edit', 'Edit the course') ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php admin_foot();
