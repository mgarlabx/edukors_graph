<?php
/** Where a catalogue visitor's run of a course is: the player's state, kept in their session. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/visitor.php';

$row = visitor_require_api();

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 512 * 1024) {
    edukors_json_error('the state is too large', 413);
}
$state = json_decode((string) $raw, true);
if (!is_array($state)) {
    edukors_json_error('bad request', 400);
}

visitor_save($row, Course::fromJson($row['doc']), $state);

edukors_json(['ok' => true]);
