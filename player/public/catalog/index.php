<?php
/**
 * The catalogue: every published course, and the three ways into each one.
 *
 * This is the only page of this server anybody may open without arriving from
 * a learning platform, and the only one that names a course to somebody who
 * was not sent to it. Everything on it is published work; nothing here asks
 * who is reading, and nothing here is written down about them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/catalog.php';
require_once __DIR__ . '/icons.php';

$courses = catalog_courses();

catalog_headers();
header('Content-Type: text/html; charset=utf-8');

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Courses — Edukors</title>
<link rel="stylesheet" href="<?= $h(edukors_asset('assets/catalog.css', '../')) ?>">
</head>
<body>
<header>
  <h1>Edukors</h1><span class="meta">courses</span>
</header>

<main>
  <h2>Courses</h2>
  <p class="lead">
    <?= count($courses) ?> published course<?= count($courses) === 1 ? '' : 's' ?>.
    Each one can be read as a map of its steps, taken from beginning to end, or read as the
    file it is written in. Nothing here needs an account, and nothing here is kept about you:
    a course taken from this page runs entirely in your browser and is forgotten when you
    close it.
  </p>

  <?php if ($courses === []): ?>
    <p class="empty">Nothing published yet.</p>
  <?php endif; ?>

  <?php $shelf = false; foreach ($courses as $c): ?>
    <?php if ($shelf !== $c['category']): ?>
      <?php if ($shelf !== false): ?></ul><?php endif; $shelf = $c['category']; ?>
      <p class="shelf"><?= $shelf === null ? 'Other courses' : $h($shelf) ?></p>
      <ul class="courses">
    <?php endif; ?>
    <li class="course">
      <span class="name"><?= $h($c['title']) ?></span>
      <span class="meta"><?= $h($c['author']) ?> ·
        <span class="langs"><?= $h($c['languages']) ?></span></span>
      <span class="actions">
        <?php $q = '?course=' . urlencode((string) $c['course_uuid']); ?>
        <?= catalog_action('map.php' . $q,  'map',  'Map of the course', true) ?>
        <?= catalog_action('play.php' . $q, 'play', 'Take the course',   true) ?>
        <?php // Downloading the file is offered on the JSON page, where somebody
              // who wants the file is already looking at it. ?>
        <?= catalog_action('json.php' . $q,  'json', 'Read the JSON')          ?>
      </span>
    </li>
  <?php endforeach; ?>
  <?php if ($shelf !== false): ?></ul><?php endif; ?>
</main>
</body>
</html>
