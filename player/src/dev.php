<?php
/**
 * A stand-in student, for working on the server without an LMS.
 *
 * Only reachable when 'dev_mode' is true in config.php. It creates one student
 * on a platform row that exists for this purpose, so everything downstream --
 * progress, node_state, the rate limit -- behaves exactly as it does in
 * production. Never turn dev_mode on for a server students can reach.
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

function edukors_dev_sign_in(string $courseUuid): ?array
{
    if (!edukors_config()['dev_mode']) {
        return null;
    }

    // The same row course.php will serve, so a draft fails here, not there.
    $courseRow = db_row(
        "SELECT * FROM course WHERE course_uuid = ? AND status = 'published'
         ORDER BY created_at DESC, id DESC LIMIT 1",
        [$courseUuid]
    );
    if ($courseRow === null) {
        return null;
    }

    $platformId = (int) (db_value("SELECT id FROM lti_platform WHERE issuer = 'dev'") ?? 0);
    if ($platformId === 0) {
        db_run(
            'INSERT INTO lti_platform (name, issuer, client_id, deployment_id,
                                       auth_login_url, jwks_url, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['Development', 'dev', 'dev', 'dev', '', '', db_now()]
        );
        $platformId = (int) edukors_db()->lastInsertId();
    }

    $student = db_row('SELECT * FROM student WHERE platform_id = ? AND subject = ?', [$platformId, 'dev']);
    if ($student === null) {
        db_run(
            'INSERT INTO student (platform_id, subject, name, email, locale, created_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$platformId, 'dev', 'Development student', null, null, db_now(), db_now()]
        );
        $student = db_row('SELECT * FROM student WHERE id = ?', [(int) edukors_db()->lastInsertId()]);
    }

    $lang = (string) $courseRow['source_language'];
    edukors_sign_in($student, $courseUuid, $lang);

    return edukors_student();
}
