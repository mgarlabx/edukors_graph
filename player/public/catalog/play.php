<?php
/**
 * The course itself, taken by anybody, kept by nobody.
 *
 * This is the file public/download.php hands a student to run with no network,
 * served as a page instead of as a download: one self-contained copy of the
 * player with the course in it. That is what makes it safe to leave open to
 * anyone -- it asks this server for nothing at all after the page has loaded,
 * so there is no session to start, no progress to write down and no model to
 * pay for. The steps written by AI say so and let the visitor carry on, as
 * they do in the downloaded copy.
 *
 * Whoever wants those steps to run has to take the course from their learning
 * platform, where there is a student to charge the work to.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/catalog.php';
require_once __DIR__ . '/../../src/build.php';

$row    = catalog_require_course();
$course = Course::fromJson($row['doc']);

// No state and no student: the player opens at the first step and asks for the
// language itself when the course has more than one.
$html = edukors_build_offline($course, $course->sourceLanguage(), null);

catalog_headers();
header('Content-Type: text/html; charset=utf-8');

echo $html;
