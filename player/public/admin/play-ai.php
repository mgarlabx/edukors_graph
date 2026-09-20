<?php
/**
 * The model, for the admin's run of a course: the same request api/ai.php
 * takes, answered from the same prompts, with no student behind it -- so it
 * asks neither where one is nor what was already written for them.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/preview.php';
require_once __DIR__ . '/../../src/ai.php';

$row = preview_require_api();

$body   = edukors_json_body();
$nodeId = $body['node'] ?? null;
if (!is_string($nodeId) || preg_match('/^(dm|dh|c|s|n)[0-9]+$/', $nodeId) !== 1) {
    edukors_json_error('bad request', 400);
}

$course = Course::fromJson($row['doc']);
if (!$course->hasNode($nodeId)) {
    edukors_json_error('no such step', 404);
}

$state = preview_state($row, $course);
$lang  = (string) $state['lang'];
$vars  = is_array($state['vars']) ? $state['vars'] : [];

// A call to the model takes seconds, and the session would stay locked for all
// of them -- holding up every other page of the admin opened meanwhile.
session_write_close();

try {
    if (in_array((string) $course->nodeType($nodeId), Course::JUDGE_TYPES, true)) {
        // Judged for real, with nothing kept. An author trying a course wants
        // to see which way their own rubric actually sends a student, which is
        // the one thing the preview player's panel cannot tell them.
        $judged = ai_judge_node($course, $nodeId, $vars, null);
        $answer = json_encode([
            'judged' => $judged['judged'],
            'vars'   => (object) ai_judge_maps($judged['vars']),
            'reason' => $judged['reason'],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $answer = ai_write_step($course, $nodeId, $lang, $vars, null);
    }
} catch (AiError $e) {
    edukors_json_error($e->getMessage(), $e->status());
} catch (Throwable $e) {
    error_log('edukors admin ai: ' . $e->getMessage());
    edukors_json_error('the step could not be prepared', 500);
}

// The envelope of the Anthropic Messages API, which is what the player parses.
edukors_json(['content' => [['type' => 'text', 'text' => $answer]]]);
