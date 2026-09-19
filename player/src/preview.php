<?php
/**
 * The admin's own run of a course: the player, with the model answering.
 *
 * The catalogue's player asks this server for nothing, so its AI steps only
 * say that they cannot run. The admin's is the online player a launch gets,
 * with one difference that decides everything else: there is no student. So
 * nothing is written to progress or node_state, nobody is added to the list of
 * students, and no step is frozen -- an author trying a prompt wants it run
 * again. Where the run is lives in the admin's session, one state per course
 * version, and it is what fills the {{STORAGE: key}} of a prompt.
 *
 * It opens any version, a draft included: trying a course before publishing
 * it is most of what this is for. The calls are paid from the same account as
 * the students', so they are logged and count against the daily limit.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/course.php';
require_once __DIR__ . '/progress.php';
require_once __DIR__ . '/session.php';

/** The course version named by ?id=, or a 404 page and nothing else. */
function preview_require_course(): array
{
    $row = db_row('SELECT * FROM course WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
    if ($row !== null) {
        return $row;
    }

    http_response_code(404);
    admin_head('Not found');
    echo '<p class="empty">No such course.</p>';
    admin_foot();
    exit;
}

/**
 * For the two endpoints the admin's player calls: an admin, a POST from this
 * site, and a course version -- or a JSON error the player knows how to show.
 */
function preview_require_api(): array
{
    // The answer has to be JSON and nothing else: a warning printed into the
    // body would be read by the player as a broken response.
    ini_set('display_errors', '0');

    admin_session_start();
    admin_headers();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        edukors_json_error('POST only', 405);
    }
    // The admin cookie is SameSite=Strict already; this is the second lock.
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
        edukors_json_error('bad request', 403);
    }
    if (!admin_is_signed_in()) {
        edukors_json_error('signed out; sign in to the admin again', 401);
    }

    $row = db_row('SELECT * FROM course WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
    if ($row === null) {
        edukors_json_error('no such course', 404);
    }
    return $row;
}

/** Where the admin's run of this version is, or where it starts. */
function preview_state(array $courseRow, Course $course): array
{
    $state = $_SESSION['preview'][(int) $courseRow['id']] ?? null;
    if (!is_array($state)) {
        $state = progress_initial_state($course, $course->sourceLanguage());
    }
    // vars and answers are maps to the player, and an empty PHP array would be
    // written as [] rather than {}.
    foreach (['vars', 'answers'] as $key) {
        if (($state[$key] ?? []) === []) {
            $state[$key] = (object) [];
        }
    }
    return $state;
}

/**
 * Keeps what the player sent, checked for shape as a student's state is. Only
 * its language and its storage keys are ever read back here, but the whole of
 * it is what the page is reseeded from when it is opened again.
 */
function preview_save(array $courseRow, Course $course, array $state): void
{
    $currentId = $state['currentId'] ?? null;
    $lang      = $state['lang'] ?? null;

    $_SESSION['preview'][(int) $courseRow['id']] = [
        'lang'             => in_array($lang, $course->languages(), true) ? $lang : $course->sourceLanguage(),
        'isLanguageChosen' => (bool) ($state['isLanguageChosen'] ?? false),
        'currentId'        => is_string($currentId) && $course->hasNode($currentId) ? $currentId : null,
        'history'          => array_values(array_filter(
            is_array($state['history'] ?? null) ? $state['history'] : [],
            static fn($id) => is_string($id) && $course->hasNode($id)
        )),
        'vars'             => is_array($state['vars'] ?? null) ? $state['vars'] : [],
        'answers'          => is_array($state['answers'] ?? null) ? $state['answers'] : [],
    ];
}
