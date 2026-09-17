<?php
/**
 * Step 2 of the launch: the LMS posts the id_token here.
 *
 * Everything about the token is checked before anything is written: the
 * signature against the platform's published keys, the issuer, the audience,
 * the expiry, the nonce, the deployment, and that the state is the one we
 * issued and has not been used. Only then does the student get a session.
 *
 * Nothing is sent back to the LMS, here or ever.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/lti.php';
require_once __DIR__ . '/../../src/session.php';
require_once __DIR__ . '/../../src/progress.php';

// When the platform refuses the login it answers with an error rather than a
// token, and it sends that back as a plain redirect. Reporting what it said is
// the whole difference between a launch that can be fixed and one that cannot:
// the reason is the platform's, and it never reaches anyone otherwise.
$sent = array_merge($_GET, $_POST);
if (isset($sent['error'])) {
    launch_fail(
        'Your learning platform refused the login: '
        . (string) ($sent['error_description'] ?? $sent['error'])
        . ' (' . (string) $sent['error'] . ')'
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // A launch is a form post. Arriving here by GET means the browser was sent
    // straight to this address instead of being taken through the login step
    // first -- which is what happens when the activity in the platform is not
    // bound to the registered LTI 1.3 tool, or when the address was simply
    // opened by hand.
    launch_fail(
        'This address is where your learning platform posts a launch; it is not a page to open. '
        . 'If you reached it from an activity, that activity is not using the registered '
        . 'LTI 1.3 tool: in the activity settings, choose the preconfigured tool by name rather '
        . 'than leaving it to be matched automatically.'
    );
}

$idToken = (string) ($_POST['id_token'] ?? '');
$state   = (string) ($_POST['state'] ?? '');
if ($idToken === '' || $state === '') {
    launch_fail('the launch is incomplete: it carried no id_token');
}

try {
    $launch = lti_verify_launch($idToken, $state);
} catch (LtiError $e) {
    launch_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('edukors lti launch: ' . $e->getMessage());
    launch_fail('the launch could not be verified');
}

$courseRow = db_row(
    "SELECT * FROM course WHERE course_uuid = ? AND status = 'published'
     ORDER BY created_at DESC, id DESC LIMIT 1",
    [$launch['course_uuid']]
);
if ($courseRow === null) {
    launch_fail('this activity points at a course that is not published');
}

$course  = Course::fromJson($courseRow['doc']);
$student = lti_student($launch['platform'], $launch['claims']);
$lang    = lti_language($launch['claims'], $course);

// Opens the record now, so the course page has somewhere to read from.
progress_open((int) $student['id'], $courseRow, $lang);

edukors_session_start();
edukors_sign_in($student, (string) $launch['course_uuid'], $lang);

edukors_frame_headers();
header('Cache-Control: no-store');
header('Location: ../course.php', true, 303);
exit;


function launch_fail(string $message): never
{
    http_response_code(400);
    edukors_frame_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Edukors</title>'
       . '<p style="font:16px system-ui;margin:3rem;max-width:34rem;line-height:1.5">'
       . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}
