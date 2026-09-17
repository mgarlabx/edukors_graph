<?php
/** Everyone who has opened a course on this server. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
admin_require();

$rows = db_all(
    'SELECT p.*, s.name, s.email, c.title, pl.name AS platform
     FROM progress p
     JOIN student s       ON s.id  = p.student_id
     JOIN course c        ON c.id  = p.course_id
     JOIN lti_platform pl ON pl.id = s.platform_id
     ORDER BY p.updated_at DESC
     LIMIT 300'
);

admin_head('Students');
?>
<h2>Students</h2>
<p class="lead"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?>, most recently active first.</p>

<?php if ($rows === []): ?>
  <p class="empty">Nobody has launched a course yet.</p>
<?php else: ?>
<table>
  <tr><th>Student</th><th>Course</th><th>From</th><th>Step</th><th>Progress</th>
      <th>Last seen</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= h($r['name'] ?? '—') ?><?php if ($r['email']): ?><br>
        <span class="muted" style="font:11px var(--mono)"><?= h($r['email']) ?></span><?php endif; ?></td>
    <td><?= h($r['title']) ?></td>
    <td class="muted"><?= h($r['platform']) ?></td>
    <td><span class="nid"><?= h($r['current_node'] ?? 'finished') ?></span></td>
    <td><div class="meter"><i style="width:<?= (int) $r['percent'] ?>%"></i></div>
        <span class="muted" style="font:11px var(--mono)"><?= (int) $r['percent'] ?>%</span></td>
    <td class="num"><?= h(substr((string) $r['updated_at'], 0, 16)) ?></td>
    <td><a class="btn" href="student.php?id=<?= (int) $r['id'] ?>">Open</a></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php admin_foot();
