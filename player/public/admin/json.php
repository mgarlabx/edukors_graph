<?php
/** The JSON of one course version, whatever its status: the catalogue's page. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/preview.php';
require_once __DIR__ . '/../../src/json_page.php';
admin_require();

$row = preview_require_course();

edukors_json_page($row, Course::fromJson($row['doc']), [
    'back'      => 'index.php',
    'backLabel' => 'Courses',
    'download'  => 'download.php?id=' . (int) $row['id'],
]);
