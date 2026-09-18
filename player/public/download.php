<?php
/**
 * The course as one HTML file, to run with no network.
 *
 * It is the same player, with the course in it and with the student's own work
 * so far. The steps written by AI cannot run without a server, so they say so
 * and let the student carry on -- see edukors_offline_script().
 *
 * The prompts are not in the file: an offline copy has no model to send them to.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/session.php';
require_once __DIR__ . '/../src/build.php';
require_once __DIR__ . '/../src/progress.php';

edukors_session_start();

$student = edukors_student();
if ($student === null) {
    http_response_code(403);
    echo 'This course opens from your learning platform.';
    exit;
}

$courseRow = db_row(
    "SELECT * FROM course WHERE course_uuid = ? AND status = 'published'
     ORDER BY created_at DESC, id DESC LIMIT 1",
    [$student['course']]
);
if ($courseRow === null) {
    http_response_code(404);
    echo 'This course is not published.';
    exit;
}

$course   = Course::fromJson($courseRow['doc']);
$progress = db_row(
    'SELECT * FROM progress WHERE student_id = ? AND course_uuid = ?',
    [$student['id'], $student['course']]
);
$state = $progress === null ? null : progress_state_for_player($progress);
$lang  = (string) ($state['lang'] ?? $student['lang']);

$html = edukors_build_offline($course, $lang, $state);

$name = edukors_filename($course->title($lang)) . '.html';

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($html));
header('Cache-Control: no-store, private');

echo $html;
