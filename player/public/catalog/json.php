<?php
/**
 * The course as the file it is, foldable. The page is src/json_page.php, which
 * the admin shows too.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/catalog.php';
require_once __DIR__ . '/../../src/json_page.php';

$row    = catalog_require_course();
$course = Course::fromJson($row['doc']);
// Only the download link needs it: the map of this course is one line up, in
// the list, and repeating it here would be a second way to the same page.
$query  = '?course=' . urlencode((string) $row['course_uuid']);

catalog_headers();

edukors_json_page($row, $course, [
    'back'      => './',
    'backLabel' => 'All courses',
    'download'  => 'download.php' . $query,
]);
