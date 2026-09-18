<?php
/**
 * The map of a course: its graph, drawn by the viewer the builder skill ships.
 *
 * The same file build_viewer.py writes on a laptop, built here from what the
 * database holds. It is a picture of how the course is put together -- the
 * steps, the branches, the conditions on them -- and it runs nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/catalog.php';
require_once __DIR__ . '/../../src/build.php';

$row    = catalog_require_course();
$course = Course::fromJson($row['doc']);

catalog_headers();
header('Content-Type: text/html; charset=utf-8');

echo edukors_build_map($course);
