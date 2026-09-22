<?php
/**
 * The catalogue's run of a course: the player, with the model answering, for
 * somebody nobody sent.
 *
 * It is the admin's run (src/preview.php) opened to anyone, and what changes is
 * who pays. There is no student and no admin behind the call, so nothing is
 * written to progress or node_state and nobody joins the list of students;
 * where the run is lives in the visitor's own session, one state per course
 * version, and it is what fills the {{STORAGE: key}} of a prompt.
 *
 * Nobody signed in, so the calls are counted by where they come from: a keyed
 * hash of the address, never the address itself (see visitor_id). One visitor
 * gets a student's hourly share, and all of them together get a daily share of
 * the account that is theirs alone -- config.php, `catalog` -- so the open door
 * can never spend what the students' courses need.
 *
 * Only published courses, as everywhere in the catalogue: a course version is
 * found by its uuid through catalog_course(), never by an id.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/progress.php';
require_once __DIR__ . '/session.php';

const VISITOR_SESSION = 'edukors_graphs_catalog';

/**
 * Whether the catalogue's AI steps run. When they do not -- switched off in
 * config.php, or no model to ask -- the catalogue serves the offline copy, as
 * it always did, rather than a player whose every AI step fails.
 */
function visitor_ai_open(): bool
{
    $config = edukors_config();
    return (bool) $config['catalog']['ai']
        && (string) $config['ai']['key'] !== ''
        && (string) $config['ai']['model'] !== '';
}

/**
 * The visitor's session: a cookie of its own, apart from a student's and from
 * the admin's. The catalogue is never framed, so it needs none of what makes
 * the student's cookie cross into an LMS -- Lax is enough, and the endpoints
 * check where a POST came from besides.
 */
function visitor_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => edukors_base_path(),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => edukors_is_https(),
    ]);
    session_name(VISITOR_SESSION);
    session_start();
}

/**
 * For the two endpoints the catalogue's player calls: a POST from this site,
 * the AI switched on, and a published course -- or a JSON error the player
 * knows how to show.
 */
function visitor_require_api(): array
{
    // The answer has to be JSON and nothing else: a warning printed into the
    // body would be read by the player as a broken response.
    ini_set('display_errors', '0');

    catalog_headers();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        edukors_json_error('POST only', 405);
    }
    // Browsers that send no Sec-Fetch-Site are let through, as everywhere else.
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
        edukors_json_error('bad request', 403);
    }
    if (!visitor_ai_open()) {
        edukors_json_error('the catalogue does not run AI steps', 403);
    }

    $row = catalog_course((string) ($_GET['course'] ?? ''));
    if ($row === null) {
        edukors_json_error('no such course', 404);
    }

    visitor_session_start();
    return $row;
}

/**
 * Where the visitor's run of this version is, as their session has it -- or
 * null, without opening a session to find out when they have none. A visitor
 * who only looks at a course is not given a cookie for it.
 */
function visitor_saved_state(array $courseRow): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!isset($_COOKIE[VISITOR_SESSION])) {
            return null;
        }
        visitor_session_start();
    }
    $state = $_SESSION['catalog'][(int) $courseRow['id']]['state'] ?? null;
    return is_array($state) ? progress_for_player($state) : null;
}

/** Where the run is, or where it starts: what a prompt is filled from. */
function visitor_state(array $courseRow, Course $course): array
{
    return visitor_saved_state($courseRow)
        ?? progress_for_player(progress_initial_state($course, $course->sourceLanguage()));
}

/** Keeps what the player sent, checked for shape: see progress_shape(). */
function visitor_save(array $courseRow, Course $course, array $state): void
{
    $_SESSION['catalog'][(int) $courseRow['id']]['state'] = progress_shape($course, $state);
}

/**
 * The text the model already wrote for this visitor on this step, or null.
 *
 * A written step is frozen here as a student's is in node_state: coming back to
 * it, or reloading the page on it, shows the same text and pays for nothing.
 */
function visitor_text(array $courseRow, string $nodeId): ?string
{
    $text = $_SESSION['catalog'][(int) $courseRow['id']]['texts'][$nodeId] ?? null;
    return is_string($text) && $text !== '' ? $text : null;
}

/**
 * Freezes a written step. The session was closed for the length of the call,
 * so it is opened again -- which also reads whatever the player saved while
 * the model was writing -- and closed at once.
 */
function visitor_keep_text(array $courseRow, string $nodeId, string $text): void
{
    visitor_session_start();
    $_SESSION['catalog'][(int) $courseRow['id']]['texts'][$nodeId] = $text;
    session_write_close();
}

/**
 * Who a visitor is, as far as the bill is concerned: where they call from.
 *
 * A session would not do -- dropping the cookie makes a new one, as often as
 * anybody likes. The address is kept only as a keyed hash, short enough to
 * count by and useless for finding anyone: the key is a secret of this server,
 * so the hash cannot be matched against a list of addresses without it. An
 * IPv6 host is handed a whole /64, and is one visitor across all of it.
 */
function visitor_id(): string
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $packed  = @inet_pton($address);
    // Not an IPv4 address written the IPv6 way, whose first half is the same
    // for every one of them.
    if ($packed !== false && strlen($packed) === 16
        && !str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
        $address = bin2hex(substr($packed, 0, 8)) . '::/64';
    }
    return substr(hash_hmac('sha256', $address, (string) edukors_config()['ai']['key']), 0, 16);
}
