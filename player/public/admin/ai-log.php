<?php
/** What the courses have been asking the model, and what it cost. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
admin_require();

$calls = db_all(
    'SELECT a.*, s.name, c.title
     FROM ai_call a
     LEFT JOIN progress p ON p.id = a.progress_id
     LEFT JOIN student  s ON s.id = p.student_id
     LEFT JOIN course   c ON c.id = p.course_id
     ORDER BY a.id DESC LIMIT 200'
);
$day = db_row(
    'SELECT COUNT(*) AS n, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout,
            SUM(ok = 0) AS failed
     FROM ai_call WHERE created_at > ?',
    [gmdate('Y-m-d H:i:s', time() - 86400)]
);
$limits = edukors_config()['ai'];

admin_head('AI calls');
?>
<h2>AI calls</h2>
<p class="lead">
  <?= (int) $day['n'] ?> in the last 24 hours, of a limit of <?= (int) $limits['per_day'] ?> ·
  <?= (int) $day['tin'] ?> tokens in, <?= (int) $day['tout'] ?> out ·
  <?= (int) $day['failed'] ?> failed ·
  each student may make <?= (int) $limits['per_hour'] ?> an hour.
</p>

<?php if ($calls === []): ?>
  <p class="empty">No call has been made yet.</p>
<?php else: ?>
<table>
  <tr><th>When</th><th>Student</th><th>Course</th><th>Step</th><th>Kind</th>
      <th>Tokens</th><th>Result</th></tr>
  <?php foreach ($calls as $c): ?>
  <tr>
    <td class="num"><?= h(substr((string) $c['created_at'], 0, 16)) ?></td>
    <td><?= h($c['name'] ?? '—') ?></td>
    <td><?= h($c['title'] ?? '—') ?></td>
    <td><span class="nid"><?= h($c['node_id']) ?></span></td>
    <td><?= h($c['kind']) ?></td>
    <td class="num"><?= (int) $c['tokens_in'] ?> / <?= (int) $c['tokens_out'] ?></td>
    <td><?= (int) $c['ok'] === 1
        ? 'ok'
        : '<span style="color:var(--bad)">' . h((string) $c['error']) . '</span>' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php admin_foot();
