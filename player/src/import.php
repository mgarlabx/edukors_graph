<?php
/**
 * Validating a course JSON and putting it in the database.
 *
 * Both the command line (tools/import.php) and the admin upload page come
 * through here, so there is one definition of what importing means.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/course.php';
require_once __DIR__ . '/validate.php';

/**
 * @return array{ok:bool, errors:string[], warnings:string[], course_id:?int,
 *                uuid:?string, version:?string, title:?string, replaced:bool}
 */
function edukors_import(string $json, bool $publish = false): array
{
    $result = [
        'ok' => false, 'errors' => [], 'warnings' => [],
        'course_id' => null, 'uuid' => null, 'version' => null,
        'title' => null, 'replaced' => false,
    ];

    $doc = json_decode($json, true);
    if ($doc === null && json_last_error() !== JSON_ERROR_NONE) {
        $result['errors'][] = 'invalid JSON: ' . json_last_error_msg();
        return $result;
    }

    $validator = new CourseValidator();
    $validator->validate($doc);
    $result['errors']   = $validator->errors();
    $result['warnings'] = $validator->warnings();
    if (!$validator->ok()) {
        return $result;
    }

    $course = new Course($doc);
    $uuid    = $course->id();
    $version = $course->version();

    $existing = db_row(
        'SELECT id FROM course WHERE course_uuid = ? AND version = ?',
        [$uuid, $version]
    );

    $columns = [
        'course_uuid'     => $uuid,
        'version'         => $version,
        'title'           => mb_substr($course->title(), 0, 255),
        'author'          => mb_substr($course->author(), 0, 255),
        'source_language' => $course->sourceLanguage(),
        'languages'       => implode(',', $course->languages()),
        'start_node'      => (string) $course->firstNodeId(),
        // Stored as delivered, so what the server runs is what the author wrote.
        'doc'             => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'warnings'        => $result['warnings'] === [] ? null : implode("\n", $result['warnings']),
    ];

    if ($existing !== null) {
        // Re-importing the same version replaces it: authors iterate on a
        // course long before they think to bump the version number.
        $sets = implode(', ', array_map(static fn($c) => db_column($c) . ' = ?', array_keys($columns)));
        $params = array_values($columns);
        $params[] = $existing['id'];
        db_run("UPDATE course SET $sets WHERE id = ?", $params);
        if ($publish) {
            // Only one version of a course is the published one, as in the admin.
            db_run(
                "UPDATE course SET status = 'archived'
                 WHERE course_uuid = ? AND id <> ? AND status = 'published'",
                [$uuid, $existing['id']]
            );
            db_run("UPDATE course SET status = 'published' WHERE id = ?", [$existing['id']]);
        }
        $result['course_id'] = (int) $existing['id'];
        $result['replaced']  = true;
    } else {
        if ($publish) {
            db_run(
                "UPDATE course SET status = 'archived' WHERE course_uuid = ? AND status = 'published'",
                [$uuid]
            );
        }
        $columns['status']     = $publish ? 'published' : 'draft';
        $columns['created_at'] = db_now();
        $names  = implode(', ', array_map('db_column', array_keys($columns)));
        $holes  = implode(', ', array_fill(0, count($columns), '?'));
        db_run("INSERT INTO course ($names) VALUES ($holes)", array_values($columns));
        $result['course_id'] = (int) edukors_db()->lastInsertId();
    }

    $result['ok']      = true;
    $result['uuid']    = $uuid;
    $result['version'] = $version;
    $result['title']   = $course->title();
    return $result;
}
