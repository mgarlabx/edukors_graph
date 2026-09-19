<?php
/** The map of one course version, whatever its status. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/preview.php';
require_once __DIR__ . '/../../src/build.php';
admin_require();

$row = preview_require_course();

header('Content-Type: text/html; charset=utf-8');

echo edukors_build_map(Course::fromJson($row['doc']));
