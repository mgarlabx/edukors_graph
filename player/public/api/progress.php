<?php
/**
 * Keeps the server's copy of where a student is.
 *
 * The body is the state object the player keeps in localStorage, sent whenever
 * it changes. It is the student's own answers, so it is trusted for content but
 * checked for shape: node ids that are not in the course are dropped, and a
 * current step the course does not have is read as "finished".
 *
 * Nothing the server decides is decided from here. api/ai.php reads the node it
 * may write from the course and from progress.current_node, both of which this
 * endpoint can only move along the course's own edges.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/session.php';
require_once __DIR__ . '/../../src/progress.php';

// The answer has to be JSON and nothing else: a warning printed into the body
// would be read by the player as a broken response.
ini_set('display_errors', '0');

edukors_session_start();
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    edukors_json_error('POST only', 405);
}
// The session cookie is SameSite=None (see session.php), so refuse a POST
// another site forged; browsers that send no Sec-Fetch-Site are let through.
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
    edukors_json_error('bad request', 403);
}

$student = edukors_student();
if ($student === null) {
    edukors_json_error('no session', 401);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 512 * 1024) {
    edukors_json_error('the state is too large', 413);
}
$state = json_decode((string) $raw, true);
if (!is_array($state)) {
    edukors_json_error('bad request', 400);
}

$courseRow = db_row(
    "SELECT * FROM course WHERE course_uuid = ? AND status = 'published'
     ORDER BY created_at DESC, id DESC LIMIT 1",
    [$student['course']]
);
if ($courseRow === null) {
    edukors_json_error('course not available', 404);
}

$course   = Course::fromJson($courseRow['doc']);
$progress = progress_open($student['id'], $courseRow, $student['lang']);

try {
    $saved = progress_save($progress, $course, $state);
} catch (Throwable $e) {
    error_log('edukors progress: ' . $e->getMessage());
    edukors_json_error('the progress could not be saved', 500);
}

edukors_json([
    'ok'      => true,
    'current' => $saved['currentId'],
    'percent' => $course->percent($saved['history'], $saved['currentId']),
]);
