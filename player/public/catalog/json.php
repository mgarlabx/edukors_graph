<?php
/**
 * The course as the file it is, foldable.
 *
 * The JSON goes into the page exactly as it was imported and is never decoded
 * here: the browser reads it, and what is shown is what a download would hand
 * over. The only thing done to the text is the escaping every <script> block
 * needs -- see edukors_js_literal(), which leaves it valid JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/catalog.php';
require_once __DIR__ . '/../../src/build.php';
require_once __DIR__ . '/icons.php';

$row    = catalog_require_course();
$course = Course::fromJson($row['doc']);
$query  = '?course=' . urlencode((string) $row['course_uuid']);

catalog_headers();
header('Content-Type: text/html; charset=utf-8');

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($course->title()) ?> — JSON</title>
<link rel="stylesheet" href="<?= $h(edukors_asset('assets/catalog.css', '../')) ?>">
</head>
<body>
<header>
  <h1>Edukors</h1><span class="meta">course json</span>
  <nav><a href="./">← All courses</a></nav>
</header>

<main>
  <h2><?= $h($course->title()) ?></h2>
  <p class="lead">
    Version <?= $h($row['version']) ?>, by <?= $h($row['author']) ?>.
    Every object and every array folds: the course opens with its three parts showing and each
    step closed, and a closed step says its id and its type.
  </p>

  <p class="tools">
    <button class="btn" type="button" data-json="expand">Expand all</button>
    <button class="btn" type="button" data-json="collapse">Collapse all</button>
    <a class="btn" href="download.php<?= $h($query) ?>">Download the file</a>
    <a class="btn" href="map.php<?= $h($query) ?>" target="_blank" rel="noopener">Map</a>
  </p>

  <div class="json" id="json">
    <noscript>This page folds the file with JavaScript. Without it, download the file instead.</noscript>
  </div>
</main>

<script type="application/json" id="course-json"><?= edukors_js_literal((string) $row['doc']) ?></script>
<script src="<?= $h(edukors_asset('assets/catalog-json.js', '../')) ?>"></script>
</body>
</html>
