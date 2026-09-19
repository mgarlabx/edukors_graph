<?php
/** The JSON of one course version, whatever its status, as the file it is. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/preview.php';
require_once __DIR__ . '/../../src/build.php';
admin_require();

$row  = preview_require_course();
$name = edukors_filename(Course::fromJson($row['doc'])->title())
      . '-' . edukors_filename((string) $row['version']) . '-course.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen((string) $row['doc']));

echo $row['doc'];
