<?php
/**
 * The student's session.
 *
 * An LTI launch happens inside an iframe of the LMS, which makes the session
 * cookie a third-party cookie. Three things have to be right for a browser to
 * keep it, and none of them is optional:
 *
 *   SameSite=None   or it is not sent inside the frame at all;
 *   Secure          which a browser requires before it accepts SameSite=None;
 *   Partitioned     or Chrome, which no longer stores third-party cookies,
 *                   drops it anyway. It stores a partitioned one, keyed to the
 *                   site doing the framing (CHIPS).
 *
 * Safari blocks third-party cookies outright, partitioned or not. There the LMS
 * has to open the activity in a new window, which is a setting on its side; see
 * player.README.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function edukors_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = edukors_is_https();

    session_set_cookie_params([
        'lifetime' => 0,
        // Scoped to where the player is installed, so that a site hosting it in
        // a subfolder keeps its own cookies and this one out of each other's way.
        'path'     => edukors_base_path(),
        'httponly' => true,
        // Over plain HTTP -- which only happens while developing -- SameSite=None
        // would be refused, so fall back to Lax and keep the session working.
        'samesite' => $https ? 'None' : 'Lax',
        'secure'   => $https,
    ]);
    session_name('edukors_graphs');
    session_start();

    edukors_partition_cookie();
}

/**
 * Gives the session a new id, and keeps the cookie framed-safe while doing it.
 *
 * session_regenerate_id() sends a fresh cookie built from PHP's own parameters,
 * which cannot carry Partitioned -- so the new cookie arrives without it and a
 * browser inside an LMS frame throws it away, ending the session the launch had
 * just opened. The two calls belong together, and this is the only place that
 * knows it.
 */
function edukors_session_regenerate(): void
{
    session_regenerate_id(true);
    edukors_partition_cookie();
}

/**
 * Adds Partitioned to the session cookie PHP has just sent.
 *
 * setcookie() and session_start() cannot write that attribute, so the header is
 * rewritten by hand. Only the session cookie is touched, and only when it is
 * one a frame would need: every other Set-Cookie of this response is put back
 * exactly as it was.
 */
function edukors_partition_cookie(): void
{
    if (!edukors_is_https() || headers_sent()) {
        return;
    }

    $name    = session_name();
    $cookies = [];
    foreach (headers_list() as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookies[] = trim(substr($header, strlen('Set-Cookie:')));
        }
    }
    if ($cookies === []) {
        return;                       // the browser already had the cookie
    }

    header_remove('Set-Cookie');
    foreach ($cookies as $cookie) {
        // Only the session cookie, only when it is meant to cross into a frame,
        // and only if it does not say so already.
        $needsIt = stripos($cookie, $name . '=') === 0
            && stripos($cookie, 'SameSite=None') !== false
            && stripos($cookie, 'Partitioned') === false;
        header('Set-Cookie: ' . $cookie . ($needsIt ? '; Partitioned' : ''), false);
    }
}

/** The student of this session, or null when there is none. */
function edukors_student(): ?array
{
    return $_SESSION['student'] ?? null;
}

/** Records who just launched, replacing whatever was there. */
function edukors_sign_in(array $student, string $courseUuid, string $lang): void
{
    edukors_session_regenerate();
    $_SESSION['student'] = [
        'id'     => (int) $student['id'],
        'name'   => $student['name'] ?? null,
        'course' => $courseUuid,
        'lang'   => $lang,
    ];
}

/**
 * Headers every student-facing page needs.
 *
 * The page is meant to be framed by the LMS, so it must not send
 * X-Frame-Options: DENY. frame-ancestors is what says who may frame it.
 */
function edukors_frame_headers(?string $platformOrigin = null): void
{
    $ancestors = $platformOrigin === null || $platformOrigin === ''
        ? "'self' https:"
        : "'self' " . $platformOrigin;
    header("Content-Security-Policy: frame-ancestors $ancestors");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

/** Answers a JSON request and stops. */
function edukors_json($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** The error shape the player already knows how to read. */
function edukors_json_error(string $message, int $status): never
{
    edukors_json(['error' => ['message' => $message]], $status);
}

/** The body of a JSON request, as an array. */
function edukors_json_body(): array
{
    $raw  = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    return is_array($body) ? $body : [];
}
