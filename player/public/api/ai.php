<?php
/**
 * The only inference this server performs.
 *
 * It accepts exactly one shape:
 *
 *     { "node": "dm1" }              a step the AI writes
 *     { "node": "e1", "text": "…" }  an essay to grade
 *
 * There is no field here that carries a prompt, and no field that names a
 * model. Both come from the course in the database and from config.php.
 * Everything the caller can choose is checked against the course and against
 * where the student actually is.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/session.php';
require_once __DIR__ . '/../../src/ai.php';

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

// 1. There must be a student, put there by an LTI launch.
$student = edukors_student();
if ($student === null) {
    edukors_json_error('no session', 401);
}

$body   = edukors_json_body();
$nodeId = $body['node'] ?? null;
if (!is_string($nodeId) || preg_match('/^(dm|dh|e)[0-9]+$/', $nodeId) !== 1) {
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

$course = Course::fromJson($courseRow['doc']);

// 2. The node has to exist in the course this student is in.
if (!$course->hasNode($nodeId)) {
    edukors_json_error('no such step', 404);
}

$progress = db_row(
    'SELECT * FROM progress WHERE student_id = ? AND course_uuid = ?',
    [$student['id'], $student['course']]
);
if ($progress === null) {
    edukors_json_error('this course has not been started', 409);
}

// 3. It has to be the step the student is actually on. Without this, anyone
//    could walk the whole course and have every AI step written for them at
//    once, whatever path their answers would really have taken.
if ((string) $progress['current_node'] !== $nodeId) {
    edukors_json_error('that is not the current step', 403);
}

try {
    $type = (string) $course->nodeType($nodeId);

    if ($type === 'essay') {
        $text  = $body['text'] ?? '';
        $grade = ai_grade($progress, $course, $nodeId, is_string($text) ? $text : '');
        // The player reads a grade back out of the text of the answer, so the
        // score and the feedback go back the way it expects to find them.
        $answer = json_encode(
            ['score' => $grade['score'], 'feedback' => $grade['feedback']],
            JSON_UNESCAPED_UNICODE
        );
    } else {
        $answer = ai_generate($progress, $course, $nodeId);
    }
} catch (AiError $e) {
    edukors_json_error($e->getMessage(), $e->status());
} catch (Throwable $e) {
    error_log('edukors ai: ' . $e->getMessage());
    edukors_json_error('the step could not be prepared', 500);
}

// The envelope of the Anthropic Messages API, which is what the player parses.
// Answering in the shape it already reads is what lets the player stay
// untouched -- this server simply takes the place of the host it would ask.
edukors_json(['content' => [['type' => 'text', 'text' => $answer]]]);
