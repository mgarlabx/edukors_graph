<?php
/**
 * The front door, which is deliberately almost empty.
 *
 * Courses open from a learning platform and nowhere else, so there is no
 * catalogue here and no way in. This page exists to say so, and to give an
 * administrator the addresses their LMS asks for.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$base = edukors_config()['base_url'];
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edukors Graph player</title>
<link rel="stylesheet" href="<?= $h(edukors_asset('assets/admin.css')) ?>">
</head>
<body>
<header><h1>Edukors</h1><span class="meta">graph player</span>
  <nav><a class="btn" href="admin/">Admin</a></nav>
</header>
<main style="max-width:640px">
  <h2>Edukors Graph player</h2>
  <p class="lead">
    This server runs Edukors Graph courses for students who reach them through their
    learning platform. There is nothing to open here.
  </p>
  <div class="card">
    <h3>For an LMS administrator</h3>
    <p style="margin:0 0 6px">Login URL <span class="key"><?= $h($base) ?>/lti/login.php</span></p>
    <p style="margin:0 0 6px">Redirect URL <span class="key"><?= $h($base) ?>/lti/launch.php</span></p>
    <p style="margin:0">Launch URL <span class="key"><?= $h($base) ?>/lti/launch.php?course=&lt;course id&gt;</span></p>
  </div>
</main>
</body>
</html>
