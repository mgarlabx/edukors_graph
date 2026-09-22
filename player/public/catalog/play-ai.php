<?php
/**
 * The model, for a catalogue visitor's run of a course: the same request
 * api/ai.php takes, answered from the same prompts, charged to the visitor --
 * see src/visitor.php for what that means and what it is limited to.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/visitor.php';
require_once __DIR__ . '/../../src/ai.php';

$row = visitor_require_api();

$body   = edukors_json_body();
$nodeId = $body['node'] ?? null;
if (!is_string($nodeId) || preg_match('/^(dm|dh|c|s|n)[0-9]+$/', $nodeId) !== 1) {
    edukors_json_error('bad request', 400);
}

$course = Course::fromJson($row['doc']);
if (!$course->hasNode($nodeId)) {
    edukors_json_error('no such step', 404);
}

$state = visitor_state($row, $course);

// The step the visitor is on, as for a student: the player tells the server
// where it is before it asks, and nothing else is written for them.
if (($state['currentId'] ?? null) !== $nodeId) {
    edukors_json_error('that is not the current step', 403);
}

$lang   = (string) $state['lang'];
$vars   = is_array($state['vars']) ? $state['vars'] : [];
$judges = in_array((string) $course->nodeType($nodeId), Course::JUDGE_TYPES, true);

$frozen = $judges ? null : visitor_text($row, $nodeId);
if ($frozen !== null) {
    edukors_json(['content' => [['type' => 'text', 'text' => $frozen]]]);
}

// A call to the model takes seconds, and the session would stay locked for all
// of them -- holding up the player's own saves meanwhile.
session_write_close();

try {
    if ($judges) {
        // Judged for real and not kept: a judgement is the route, and the
        // visitor's route lives in their own state, which the player keeps.
        $judged = ai_judge_node($course, $nodeId, $vars, null, visitor_id());
        $answer = json_encode([
            'judged' => $judged['judged'],
            'vars'   => (object) ai_judge_maps($judged['vars']),
            'reason' => $judged['reason'],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $answer = ai_write_step($course, $nodeId, $lang, $vars, null, visitor_id());
        visitor_keep_text($row, $nodeId, $answer);
    }
} catch (AiError $e) {
    edukors_json_error($e->getMessage(), $e->status());
} catch (Throwable $e) {
    error_log('edukors catalogue ai: ' . $e->getMessage());
    edukors_json_error('the step could not be prepared', 500);
}

// The envelope of the Anthropic Messages API, which is what the player parses.
edukors_json(['content' => [['type' => 'text', 'text' => $answer]]]);
