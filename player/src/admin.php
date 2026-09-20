<?php
/**
 * The admin side: who may open it, and the frame every page is drawn in.
 *
 * One account, from config.php. There is no user table here on purpose: this
 * is the author's door, not the students' -- students only ever arrive through
 * an LTI launch and never see any of these pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Unlike the student session, this one is never framed by anyone, so it
    // takes the strict setting.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => edukors_base_path(),
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => edukors_is_https(),
    ]);
    session_name('edukors_graphs_admin');
    session_start();
}

function admin_is_signed_in(): bool
{
    return ($_SESSION['admin'] ?? false) === true;
}

/** Stops the page unless someone is signed in. */
function admin_require(): void
{
    admin_session_start();
    admin_headers();
    if (!admin_is_signed_in()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Whether the configuration carries a password anyone could actually use.
 *
 * An empty hash means none was set. A hash PHP does not recognise means
 * something else was put there -- a placeholder left in place, or a password
 * written where its hash belongs. Both leave the admin unopenable, and both
 * deserve to say so rather than answer "wrong password" forever.
 */
function admin_password_is_set(): bool
{
    $hash = (string) (edukors_config()['admin']['hash'] ?? '');
    if ($hash === '') {
        return false;
    }
    return password_get_info($hash)['algo'] !== null;
}

function admin_check_password(string $user, string $password): bool
{
    if (!admin_password_is_set()) {
        return false;                       // no usable password: no way in
    }
    $admin = edukors_config()['admin'];
    // Compared both ways round so a wrong user name costs the same as a wrong
    // password, and neither can be found by timing the answer.
    $userOk = hash_equals((string) $admin['user'], $user);
    $passOk = password_verify($password, (string) $admin['hash']);
    return $userOk && $passOk;
}

function admin_sign_in(): void
{
    // No pairing with edukors_partition_cookie() here, unlike the student
    // session: this cookie is SameSite=Strict and these pages are never framed,
    // so there is no partition for it to belong to.
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf']  = bin2hex(random_bytes(16));
}

function admin_sign_out(): void
{
    $_SESSION = [];
    session_destroy();
}

// -- forms ---------------------------------------------------------------

function admin_csrf(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(admin_csrf()) . '">';
}

/** Refuses a POST that did not come from a form of this site. */
function admin_check_csrf(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('This form has expired. Go back and try again.');
    }
}

function admin_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, private');
}

/** A message carried across a redirect. */
function admin_flash(?string $message = null, string $kind = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

// -- the page frame -------------------------------------------------------

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_head(string $title, bool $withNav = true): void
{
    $nav = [
        'index.php'      => 'Courses',
        'categories.php' => 'Categories',
        'students.php'   => 'Students',
        'platforms.php'  => 'Platforms',
        'ai-log.php'     => 'AI calls',
    ];
    $here = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    if ($here === 'admin' || $here === '') {
        $here = 'index.php';   // reached as /admin/
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' — Edukors</title>';
    echo '<link rel="stylesheet" href="' . h(edukors_asset('assets/admin.css', '../')) . '"></head><body>';

    echo '<header><h1>Edukors</h1><span class="meta">player admin</span>';
    if ($withNav) {
        echo '<nav>';
        foreach ($nav as $file => $label) {
            $current = $file === $here ? ' style="border-color:#1F5FA8;color:#1F5FA8"' : '';
            echo '<a class="btn" href="' . h($file) . '"' . $current . '>' . h($label) . '</a>';
        }
        echo '<a class="btn" href="logout.php">Sign out</a></nav>';
    }
    echo '</header><main>';

    $flash = admin_flash();
    if ($flash !== null) {
        echo '<div class="notice ' . h($flash['kind']) . '">' . h($flash['message']) . '</div>';
    }
}

function admin_foot(): void
{
    echo '</main><script src="' . h(edukors_asset('assets/admin.js', '../')) . '"></script></body></html>';
}

/** The colour the viewer gives a node type, so the two agree. */
function admin_type_colour(string $type): string
{
    $colours = [
        'static-md' => '#4A6E8A', 'static-html' => '#3F7F76',
        'dynamic-md' => '#7A4FB0', 'dynamic-html' => '#A24897',
        'quiz' => '#2E7D5B', 'form' => '#1F6FA8', 'bool' => '#B03A48',
        'choice' => '#5B5BA8', 'score' => '#8A7A1E', 'noul' => '#1F7A8C',
    ];
    return $colours[$type] ?? '#7A8B98';
}
