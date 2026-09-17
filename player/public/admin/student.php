<?php
/** One student's way through one course, with everything they produced. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
require_once __DIR__ . '/../../src/progress.php';
admin_require();

$id  = (int) ($_GET['id'] ?? 0);
$row = db_row(
    'SELECT p.*, s.name, s.email, s.subject, c.doc, c.title, pl.name AS platform
     FROM progress p
     JOIN student s       ON s.id  = p.student_id
     JOIN course c        ON c.id  = p.course_id
     JOIN lti_platform pl ON pl.id = s.platform_id
     WHERE p.id = ?',
    [$id]
);
if ($row === null) {
    http_response_code(404);
    admin_head('Not found');
    echo '<p class="empty">No such student record.</p>';
    admin_foot();
    exit;
}

$course = Course::fromJson($row['doc']);
$state  = progress_state($row);
$vars   = is_array($state['vars'] ?? null) ? $state['vars'] : [];
$nodes  = db_all('SELECT * FROM node_state WHERE progress_id = ? ORDER BY id', [$id]);
$calls  = db_all('SELECT * FROM ai_call WHERE progress_id = ? ORDER BY id DESC LIMIT 30', [$id]);
$history = is_array($state['history'] ?? null) ? $state['history'] : [];

admin_head($row['name'] ?? 'Student');
?>
<h2><?= h($row['name'] ?? 'Unnamed student') ?></h2>
<p class="lead">
  <?= h($row['title']) ?> ·
  <?= h($row['platform']) ?> ·
  <?= (int) $row['percent'] ?>% ·
  <?= $row['finished_at'] === null
        ? 'on step ' . h((string) $row['current_node'])
        : 'finished ' . h(substr((string) $row['finished_at'], 0, 16)) ?>
</p>

<div class="card">
  <h3>The way through</h3>
  <p>
    <?php if ($history === []): ?><span class="empty">Not started.</span><?php endif; ?>
    <?php foreach ($history as $nid): ?>
      <span class="nid" title="<?= h((string) $course->nodeType((string) $nid)) ?>"><?= h((string) $nid) ?></span>
    <?php endforeach; ?>
    <?php if ($row['current_node'] !== null): ?>
      <span class="chip" style="background:var(--select)"><?= h((string) $row['current_node']) ?> now</span>
    <?php endif; ?>
  </p>
</div>

<div class="card">
  <h3>Stored data</h3>
  <?php if ($vars === []): ?><p class="empty">Nothing yet.</p><?php else: ?>
  <table>
    <tr><th>Key</th><th>Value</th></tr>
    <?php foreach ($vars as $key => $value): ?>
    <tr>
      <td><span class="key"><?= h((string) $key) ?></span></td>
      <td><?= h(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<h3 style="margin-top:22px">What each step produced</h3>
<?php if ($nodes === []): ?><p class="empty">Nothing yet.</p><?php endif; ?>
<?php foreach ($nodes as $n): ?>
<div class="card">
  <p style="margin:0 0 8px">
    <span class="chip" style="background:<?= h(admin_type_colour((string) $n['node_type'])) ?>"><?= h($n['node_type']) ?></span>
    <span class="nid"><?= h($n['node_id']) ?></span>
    <span class="muted" style="font-size:12px">
      <?= h(Course::localize($course->node((string) $n['node_id'])['title'] ?? [], (string) $row['lang'])) ?>
      · <?= (int) $n['visits'] ?> visit<?= (int) $n['visits'] === 1 ? '' : 's' ?>
      <?php if ($n['score'] !== null): ?> · <strong><?= (int) $n['score'] ?>/100</strong><?php endif; ?>
    </span>
  </p>

  <?php if ($n['generated_text'] !== null): ?>
    <h3>Written by the AI</h3>
    <div class="prose"><?= h($n['generated_text']) ?></div>
  <?php endif; ?>

  <?php if ($n['answer'] !== null): ?>
    <h3>Answer</h3>
    <div class="code"><?= h(json_encode(json_decode((string) $n['answer'], true),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
  <?php endif; ?>

  <?php if ($n['feedback'] !== null && $n['feedback'] !== ''): ?>
    <h3>Feedback</h3>
    <div class="prose"><?= h($n['feedback']) ?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($calls !== []): ?>
<div class="card">
  <h3>AI calls</h3>
  <table>
    <tr><th>When</th><th>Step</th><th>Kind</th><th>Tokens</th><th>Result</th></tr>
    <?php foreach ($calls as $c): ?>
    <tr>
      <td class="num"><?= h(substr((string) $c['created_at'], 0, 16)) ?></td>
      <td><span class="nid"><?= h($c['node_id']) ?></span></td>
      <td><?= h($c['kind']) ?></td>
      <td class="num"><?= (int) $c['tokens_in'] ?> / <?= (int) $c['tokens_out'] ?></td>
      <td><?= (int) $c['ok'] === 1 ? 'ok' : '<span style="color:var(--bad)">' . h((string) $c['error']) . '</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php admin_foot();
