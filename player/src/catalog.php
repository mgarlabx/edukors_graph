<?php
/**
 * The catalogue: which courses are open to anyone, and in what order.
 *
 * Everything under public/catalog/ comes through here, and it answers the same
 * question the admin's own list answers -- with one difference that is the
 * whole point of the place: it only ever sees published courses. A draft or an
 * archived version is not a course the public has, so it is not one this file
 * will return, by uuid or otherwise.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/course.php';

/**
 * Every published course, in the order the admin put them in: the categories
 * by theirs, the courses by theirs inside each one, whatever was never placed
 * anywhere at the end. One row per course -- a course_uuid has a single
 * published version, and if a hand-written UPDATE ever left two, the newest
 * wins here as it does everywhere else.
 */
function catalog_courses(): array
{
    $rows = db_all(
        "SELECT c.id, c.course_uuid, c.version, c.title, c.author, c.languages,
                c.source_language, c.created_at, c.sort_order,
                k.title AS category
         FROM course c
         LEFT JOIN category k ON k.id = c.category_id
         WHERE c.status = 'published'
         ORDER BY k.sort_order IS NULL, k.sort_order, k.title,
                  c.sort_order, c.title, c.course_uuid, c.created_at DESC, c.id DESC"
    );

    $courses = [];
    foreach ($rows as $row) {
        $courses[$row['course_uuid']] ??= $row;
    }
    return array_values($courses);
}

/** The published version of one course, or null when there is none. */
function catalog_course(string $uuid): ?array
{
    if (preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) !== 1) {
        return null;
    }
    return db_row(
        "SELECT * FROM course WHERE course_uuid = ? AND status = 'published'
         ORDER BY created_at DESC, id DESC LIMIT 1",
        [$uuid]
    );
}

/**
 * The published course named by ?course=, or a 404 page and nothing else.
 *
 * Every page of the catalogue starts with this, so a uuid that is not a
 * published course is answered the same way everywhere: with a page, not with
 * a crash, and never with a hint that the course exists as a draft.
 */
function catalog_require_course(): array
{
    $row = catalog_course((string) ($_GET['course'] ?? ''));
    if ($row !== null) {
        return $row;
    }

    http_response_code(404);
    catalog_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Not found — Edukors</title>'
       . '<p style="font:16px system-ui;margin:3rem">'
       . 'No published course with that address. <a href="./">The catalogue</a> has the ones there are.'
       . '</p>';
    exit;
}

/** The headers every page of the catalogue sends. */
function catalog_headers(): void
{
    // A course map and an anonymous player are pages, not frames of somebody
    // else's site -- unlike the player a launch opens, which lives in one.
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
