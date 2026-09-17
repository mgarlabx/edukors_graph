<?php
/**
 * The course itself: the player, with this student's course in it.
 *
 * Reached only from an LTI launch, which is what put the student in the
 * session. There is no catalogue and no way to name another course here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/session.php';
require_once __DIR__ . '/../src/build.php';
require_once __DIR__ . '/../src/progress.php';

edukors_session_start();

$student = edukors_student();

// While developing, ?dev=<course-uuid> opens a course with a stand-in student
// and no LMS. It only works when dev_mode is on in config.php.
if ($student === null && edukors_config()['dev_mode'] && isset($_GET['dev'])) {
    require_once __DIR__ . '/../src/dev.php';
    $student = edukors_dev_sign_in((string) $_GET['dev']);
}

if ($student === null) {
    http_response_code(403);
    edukors_frame_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Edukors</title>'
       . '<p style="font:16px system-ui;margin:3rem">This course opens from your learning platform.</p>';
    exit;
}

$courseRow = db_row(
    "SELECT * FROM course WHERE course_uuid = ? AND status = 'published'
     ORDER BY created_at DESC, id DESC LIMIT 1",
    [$student['course']]
);
if ($courseRow === null) {
    http_response_code(404);
    edukors_frame_headers();
    echo 'This course is not published.';
    exit;
}

$course   = Course::fromJson($courseRow['doc']);
$progress = progress_open($student['id'], $courseRow, $student['lang']);
$state    = progress_state_for_player($progress);
$lang     = (string) ($state['lang'] ?? $student['lang']);

// What public/assets/bridge.js needs to do its work.
$bridge = [
    // The player's own localStorage namespace. Naming the student in it keeps
    // two people who share a browser from reading each other's answers.
    'scope'    => $student['id'] . '@' . $course->scope(),
    'aiUrl'       => 'api/ai.php',
    'progressUrl' => 'api/progress.php',
    'downloadUrl' => 'download.php',
    'downloadLabel' => edukors_download_label($lang),
    // The state the server has, which wins over whatever is in this browser:
    // a launch is the moment to agree on where the student actually is.
    'state'    => $state,
];

edukors_frame_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');

echo edukors_build_online($course, $lang, $bridge);


/** The wording of the download link, in the languages the player speaks. */
function edukors_download_label(string $lang): string
{
    $labels = [
        'en' => 'Download', 'pt' => 'Baixar',  'es' => 'Descargar',
        'fr' => 'Télécharger', 'de' => 'Herunterladen', 'it' => 'Scarica',
        'ru' => 'Скачать', 'zh' => '下载', 'hi' => 'डाउनलोड', 'ar' => 'تنزيل',
    ];
    $base = explode('-', $lang)[0];
    return $labels[$lang] ?? $labels[$base] ?? $labels['en'];
}
