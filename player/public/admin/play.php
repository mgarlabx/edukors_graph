<?php
/**
 * One course version, taken by the admin, with the AI steps written by the
 * model -- see src/preview.php for what that is and what it is not.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/preview.php';
require_once __DIR__ . '/../../src/build.php';
admin_require();

$row    = preview_require_course();
$course = Course::fromJson($row['doc']);
$state  = preview_state($row, $course);
$query  = '?id=' . (int) $row['id'];

// What public/assets/bridge.js needs, pointed at the admin's own endpoints.
$bridge = [
    // Apart from any student's in the same browser, and from the other
    // versions of this course.
    'scope'       => 'admin-' . (int) $row['id'] . '@' . $course->scope(),
    'aiUrl'       => 'play-ai.php' . $query,
    'progressUrl' => 'play-state.php' . $query,
    'downloadUrl' => null,
    // The session's copy wins over this browser's, as the database's does for
    // a student: it is the one the prompts are filled from.
    'state'       => $state,
];

header('Content-Type: text/html; charset=utf-8');

echo edukors_build_online($course, (string) $state['lang'], $bridge, '../');
