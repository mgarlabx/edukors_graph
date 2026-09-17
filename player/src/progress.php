<?php
/**
 * Where a student is in a course, and what they have produced.
 *
 * Two records, with different jobs:
 *
 *   progress.state -- the literal mirror of the object the player keeps in
 *       localStorage. It is what lets a student open the course on another
 *       device and carry on from the same step. Written by api/progress.php.
 *
 *   node_state -- this server's own record, one row per node. api/ai.php writes
 *       the generated text here before the student ever sees it, which is what
 *       freezes a dynamic node and what stops a page reload from paying for a
 *       second call. The answers, scores and feedback are derived from the
 *       mirror so the admin can read them without parsing JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/course.php';

/** The state a course starts from, shaped as the player expects it. */
function progress_initial_state(Course $course, string $lang): array
{
    return [
        'lang'             => $lang,
        'isLanguageChosen' => false,
        'currentId'        => $course->firstNodeId(),
        'history'          => [],
        'vars'             => (object) [],
        'answers'          => (object) [],
    ];
}

/** The row for this student and course, created on the first launch. */
function progress_open(int $studentId, array $courseRow, string $lang): array
{
    $row = db_row(
        'SELECT * FROM progress WHERE student_id = ? AND course_uuid = ?',
        [$studentId, $courseRow['course_uuid']]
    );
    if ($row !== null) {
        // A student who was playing an older version keeps their answers, but
        // is moved onto the version now published.
        if ((int) $row['course_id'] !== (int) $courseRow['id']) {
            db_run('UPDATE progress SET course_id = ?, updated_at = ? WHERE id = ?',
                [$courseRow['id'], db_now(), $row['id']]);
            $row['course_id'] = $courseRow['id'];
        }
        return $row;
    }

    $course = Course::fromJson($courseRow['doc']);
    $state  = progress_initial_state($course, $lang);
    $now    = db_now();

    db_run(
        'INSERT INTO progress (student_id, course_uuid, course_id, lang, current_node, percent,
                               state, started_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
        [
            $studentId, $courseRow['course_uuid'], $courseRow['id'], $lang,
            $state['currentId'], json_encode($state, JSON_UNESCAPED_UNICODE), $now, $now,
        ]
    );

    return db_row('SELECT * FROM progress WHERE id = ?', [(int) edukors_db()->lastInsertId()]);
}

/** The saved state, as an array the player can read back. */
function progress_state(array $progressRow): array
{
    $state = json_decode((string) $progressRow['state'], true);
    return is_array($state) ? $state : [];
}

/**
 * The state shaped for the player: `vars` and `answers` are maps, and an empty
 * PHP array would otherwise be written as [] rather than {}.
 */
function progress_state_for_player(array $progressRow): array
{
    $state = progress_state($progressRow);
    foreach (['vars', 'answers'] as $key) {
        if (($state[$key] ?? []) === []) {
            $state[$key] = (object) [];
        }
    }
    return $state;
}

/** The storage keys a student has produced, for conditions and prompts. */
function progress_vars(array $progressRow): array
{
    $vars = progress_state($progressRow)['vars'] ?? [];
    return is_array($vars) ? $vars : [];
}

/**
 * Saves the state the player sent, and keeps node_state in step with it.
 *
 * The state is checked for shape but trusted for content: it is the student's
 * own answers, and the only thing it can spoil is their own progress. What it
 * may never do is decide anything the server decides -- which is why
 * api/ai.php reads the node it may generate from the course, never from here.
 */
function progress_save(array $progressRow, Course $course, array $state): array
{
    $history   = array_values(array_filter(
        is_array($state['history'] ?? null) ? $state['history'] : [],
        static fn($id) => is_string($id) && $course->hasNode($id)
    ));
    $currentId = $state['currentId'] ?? null;
    if (!is_string($currentId) || !$course->hasNode($currentId)) {
        $currentId = null;                       // null is how the player says "finished"
    }
    $lang = is_string($state['lang'] ?? null) ? $state['lang'] : $progressRow['lang'];
    if (!in_array($lang, $course->languages(), true)) {
        $lang = $course->sourceLanguage();
    }

    $clean = [
        'lang'             => $lang,
        'isLanguageChosen' => (bool) ($state['isLanguageChosen'] ?? false),
        'currentId'        => $currentId,
        'history'          => $history,
        'vars'             => is_array($state['vars'] ?? null) ? $state['vars'] : [],
        'answers'          => is_array($state['answers'] ?? null) ? $state['answers'] : [],
    ];
    $stored = $clean;
    foreach (['vars', 'answers'] as $key) {
        if ($stored[$key] === []) {
            $stored[$key] = (object) [];
        }
    }

    $percent    = $course->percent($history, $currentId);
    $finished   = $currentId === null && $history !== [];
    $finishedAt = $progressRow['finished_at'];
    if ($finished && $finishedAt === null) {
        $finishedAt = db_now();
    }

    db_run(
        'UPDATE progress SET lang = ?, current_node = ?, percent = ?, state = ?,
                             updated_at = ?, finished_at = ? WHERE id = ?',
        [
            $lang, $currentId, $percent,
            json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            db_now(), $finishedAt, $progressRow['id'],
        ]
    );

    progress_sync_nodes((int) $progressRow['id'], $course, $clean);

    return $clean;
}

/**
 * Writes the answers of the mirror into node_state, one row per node, so the
 * admin can read a student's essay or quiz without unpacking a JSON blob.
 */
function progress_sync_nodes(int $progressId, Course $course, array $state): void
{
    $answers = $state['answers'] ?? [];
    $vars    = $state['vars'] ?? [];
    if (!is_array($answers)) {
        return;
    }

    // How many times the student has been through each node. It is already in
    // the history, which lists every visit in order, so counting it there is
    // both simpler and right -- a save that changes nothing must not look like
    // another visit.
    $visits = array_count_values(array_filter(
        $state['history'] ?? [],
        static fn($id) => is_string($id)
    ));

    foreach ($answers as $nodeId => $answer) {
        if (!is_string($nodeId) || !$course->hasNode($nodeId)) {
            continue;
        }
        $type = (string) $course->nodeType($nodeId);

        // A dynamic node's answer is the generated text, which api/ai.php has
        // already stored. Leave that column alone: the server owns it.
        if ($type === 'dynamic-md' || $type === 'dynamic-html') {
            continue;
        }

        $score = $vars["$nodeId.score"] ?? null;
        if ($type === 'quiz') {
            $score = $vars["$nodeId.percent"] ?? null;   // the comparable number
        }

        node_state_write($progressId, $nodeId, $type, [
            'answer'   => json_encode($answer, JSON_UNESCAPED_UNICODE),
            'score'    => is_numeric($score) ? (int) round((float) $score) : null,
            'feedback' => isset($vars["$nodeId.feedback"]) ? (string) $vars["$nodeId.feedback"] : null,
            'visits'   => max(1, (int) ($visits[$nodeId] ?? 1)),
        ]);
    }
}

/** Creates or updates one node_state row, leaving columns not named alone. */
function node_state_write(int $progressId, string $nodeId, string $type, array $fields): void
{
    $columns = array_merge(
        ['progress_id' => $progressId, 'node_id' => $nodeId, 'node_type' => $type],
        $fields,
        ['updated_at' => db_now()]
    );

    $names  = implode(', ', array_map('db_column', array_keys($columns)));
    $holes  = implode(', ', array_fill(0, count($columns), '?'));
    $update = [];
    foreach (array_keys($fields) as $name) {
        $column = db_column($name);
        $update[] = "$column = VALUES($column)";
    }
    $update[] = '`updated_at` = VALUES(`updated_at`)';

    db_run(
        "INSERT INTO node_state ($names) VALUES ($holes)
         ON DUPLICATE KEY UPDATE " . implode(', ', $update),
        array_values($columns)
    );
}

/** One node_state row, or null. */
function node_state_read(int $progressId, string $nodeId): ?array
{
    return db_row(
        'SELECT * FROM node_state WHERE progress_id = ? AND node_id = ?',
        [$progressId, $nodeId]
    );
}
