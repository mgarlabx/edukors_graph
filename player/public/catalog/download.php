<?php
/**
 * The course JSON, as the file it is.
 *
 * Byte for byte what was imported: a course is a document, and this is the
 * document. Whoever downloads it can read it, validate it against the schema
 * it names, open it in the builder or import it into a server of their own.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/catalog.php';
require_once __DIR__ . '/../../src/build.php';

$row  = catalog_require_course();
$name = edukors_filename(Course::fromJson($row['doc'])->title()) . '-course.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen((string) $row['doc']));
header('X-Content-Type-Options: nosniff');

echo $row['doc'];
