<?php
/**
 * The course itself, taken by anybody.
 *
 * The online player, as a launch gets it and as the admin's run of a course
 * gets it: its AI steps are written by the model, through play-ai.php, and
 * where the visitor is goes to play-state.php. There is no student behind it,
 * so nothing about the visitor is written down but the calls they cost -- see
 * src/visitor.php, which is also where the limits on them are.
 *
 * With the catalogue's AI switched off in config.php, this is what it was
 * before: the file public/download.php hands a student to run with no network,
 * served as a page. Its AI steps say so and let the visitor carry on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/visitor.php';
require_once __DIR__ . '/../../src/build.php';

$row    = catalog_require_course();
$course = Course::fromJson($row['doc']);

catalog_headers();
header('Content-Type: text/html; charset=utf-8');

if (!visitor_ai_open()) {
    // No state and no student: the player opens at the first step and asks for
    // the language itself when the course has more than one.
    echo edukors_build_offline($course, $course->sourceLanguage(), null);
    exit;
}

// Null for a visitor with no session. Then this browser's own copy is the one
// the player resumes from, and bridge.js hands it to the server before the
// first question.
$state = visitor_saved_state($row);
session_write_close();

$query = '?course=' . urlencode((string) $row['course_uuid']);

// What public/assets/bridge.js needs, pointed at the catalogue's own endpoints.
$bridge = [
    // Apart from a student's in the same browser, and from the admin's; one
    // version at a time, as the session keeps it.
    'scope'       => 'catalog-' . (int) $row['id'] . '@' . $course->scope(),
    'aiUrl'       => 'play-ai.php' . $query,
    'progressUrl' => 'play-state.php' . $query,
    // The course file is on the JSON page, one line up in the list.
    'downloadUrl' => null,
    'state'       => $state,
];

// The page carries the visitor's state, so it is theirs alone.
header('Cache-Control: no-store, private');

echo edukors_build_online($course, (string) ($state['lang'] ?? $course->sourceLanguage()), $bridge, '../');
