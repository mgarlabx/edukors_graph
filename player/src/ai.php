<?php
/**
 * Inference, kept on this side of the wire.
 *
 * The browser never sends a prompt, because it never has one: the course it
 * receives carries a marker where every prompt used to be (see
 * Course::withoutPrompts). All it can ask for is "run node dm1 of the course I
 * am in", and everything else -- which prompt, with which values filled in,
 * which model, how long an answer -- is decided here, from the course stored in
 * the database and from config.php.
 *
 * That is the whole point: there is no request this endpoint accepts that would
 * run text of the caller's choosing.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/course.php';
require_once __DIR__ . '/progress.php';

class AiError extends RuntimeException
{
    public function __construct(string $message, private int $status = 502)
    {
        parent::__construct($message);
    }

    public function status(): int { return $this->status; }
}

/**
 * Runs the prompt of a dynamic node and returns the text.
 *
 * The result is written to node_state before it is returned, which is what
 * freezes it: a student who comes back to the node, on this or on any other
 * device, is shown the same content, and a reload never pays for a second call.
 */
function ai_generate(array $progressRow, Course $course, string $nodeId): string
{
    $node = $course->node($nodeId);
    $type = $node === null ? '' : (string) ($node['type'] ?? '');
    if ($type !== 'dynamic-md' && $type !== 'dynamic-html') {
        throw new AiError("node $nodeId is not a node the AI writes", 400);
    }

    $existing = node_state_read((int) $progressRow['id'], $nodeId);
    if ($existing !== null && $existing['generated_text'] !== null && $existing['generated_text'] !== '') {
        return (string) $existing['generated_text'];
    }

    $state = progress_state($progressRow);
    $text  = ai_write_step(
        $course,
        $nodeId,
        (string) ($state['lang'] ?? $course->sourceLanguage()),
        progress_vars($progressRow),
        (int) $progressRow['id']
    );

    node_state_write((int) $progressRow['id'], $nodeId, $type, ['generated_text' => $text]);

    return $text;
}

/**
 * Runs the prompt of a dynamic node, with nothing kept: the text, and only that.
 *
 * ai_generate() is this plus the freezing. The admin's own run of a course
 * comes here directly, with no student and so no progress to charge the call
 * to or to freeze the answer in -- an author trying a prompt wants it run
 * again, not handed back.
 */
function ai_write_step(Course $course, string $nodeId, string $lang, array $vars, ?int $progressId): string
{
    $node = $course->node($nodeId);
    $type = $node === null ? '' : (string) ($node['type'] ?? '');
    if ($type !== 'dynamic-md' && $type !== 'dynamic-html') {
        throw new AiError("node $nodeId is not a node the AI writes", 400);
    }

    $prompt = Course::localize($node['content']['prompt'] ?? [], $lang);
    if (trim($prompt) === '') {
        throw new AiError("node $nodeId has no prompt", 400);
    }

    return ai_call(
        $course->systemPrompt(),
        Course::resolveStorage($prompt, $vars),
        $lang,
        $progressId,
        $nodeId,
        'generate'
    );
}

/**
 * Grades an essay and returns {score, feedback}.
 *
 * The student's text is appended after the separator the schema prescribes, and
 * the node's own `<id>.text` key is made available to the prompt first, so a
 * grading prompt can quote what the student wrote.
 */
function ai_grade(array $progressRow, Course $course, string $nodeId, string $studentText): array
{
    $state = progress_state($progressRow);
    $grade = ai_grade_text(
        $course,
        $nodeId,
        $studentText,
        (string) ($state['lang'] ?? $course->sourceLanguage()),
        progress_vars($progressRow),
        (int) $progressRow['id']
    );
    $studentText = trim($studentText);

    node_state_write((int) $progressRow['id'], $nodeId, 'essay', [
        'answer'   => json_encode(['text' => $studentText], JSON_UNESCAPED_UNICODE),
        'score'    => $grade['score'],
        'feedback' => $grade['feedback'],
    ]);

    return $grade;
}

/**
 * Grades an essay with nothing kept, as ai_write_step() writes a step.
 */
function ai_grade_text(
    Course $course, string $nodeId, string $studentText, string $lang, array $vars, ?int $progressId
): array {
    $node = $course->node($nodeId);
    if ($node === null || ($node['type'] ?? '') !== 'essay') {
        throw new AiError("node $nodeId is not an essay", 400);
    }

    $studentText = trim($studentText);
    if ($studentText === '') {
        throw new AiError('there is no text to grade', 400);
    }
    if (mb_strlen($studentText) > 20000) {
        throw new AiError('the text is too long to grade', 413);
    }

    $vars["$nodeId.text"] = $studentText;

    $prompt = (string) ($node['content']['prompt'] ?? '');
    if (trim($prompt) === '') {
        throw new AiError("node $nodeId has no grading prompt", 400);
    }

    return ai_parse_grade(ai_call(
        $course->systemPrompt(),
        Course::resolveStorage($prompt, $vars) . "\n\n--- STUDENT TEXT ---\n" . $studentText,
        $lang,
        $progressId,
        $nodeId,
        'grade'
    ));
}

/**
 * Reads {"score", "feedback"} out of an answer, the way the player does.
 *
 * When no such object can be found there is no grade, and the whole answer
 * becomes the feedback -- the rule the schema sets. A missing score means any
 * edge testing `<id>.score` will not hold, so the student takes the fallback:
 * ungraded is a path the course already knows how to handle.
 */
function ai_parse_grade(string $answer): array
{
    $text    = trim($answer);
    $trimmed = trim(preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $text) ?? $text);

    $open  = strpos($trimmed, '{');
    $close = strrpos($trimmed, '}');
    if ($open !== false && $close !== false && $close > $open) {
        $object = json_decode(substr($trimmed, $open, $close - $open + 1), true);
        if (is_array($object)) {
            $score = $object['score'] ?? null;
            return [
                'score'    => is_numeric($score) ? max(0, min(100, (int) round((float) $score))) : null,
                'feedback' => (string) ($object['feedback'] ?? $trimmed),
            ];
        }
    }

    return ['score' => null, 'feedback' => $text];
}

/**
 * One call to the model, through OpenRouter.
 *
 * Everything that shapes the request comes from the course and from config.php.
 * Nothing here can be influenced by what the browser sent beyond the node it
 * named and, for an essay, the text the student typed.
 */
function ai_call(
    string $systemPrompt,
    string $userPrompt,
    string $lang,
    ?int $progressId,
    string $nodeId,
    string $kind
): string {
    $ai = edukors_config()['ai'];
    if (($ai['key'] ?? '') === '' || ($ai['model'] ?? '') === '') {
        throw new AiError('no model is configured on this server', 503);
    }

    ai_check_rate($progressId, (int) $ai['per_hour'], (int) $ai['per_day']);

    // The course-wide instructions go as written: {{STORAGE: key}} is resolved
    // in a node's prompt, never in the system prompt.
    $system = trim($systemPrompt . "\n\nThe student's language is \"$lang\". Answer in that language.");

    $body = [
        'model'       => $ai['model'],
        'max_tokens'  => (int) $ai['max_tokens'],
        'temperature' => (float) $ai['temperature'],
        // Asks OpenRouter to put what the call cost, in dollars, in `usage`.
        'usage'       => ['include' => true],
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $ai['key'],
    ];
    if (($ai['referer'] ?? '') !== '') {
        $headers[] = 'HTTP-Referer: ' . $ai['referer'];
    }
    if (($ai['title'] ?? '') !== '') {
        $headers[] = 'X-Title: ' . $ai['title'];
    }

    $curl = curl_init((string) $ai['url']);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        // The player shows a spinner with no timeout of its own, so this one
        // has to be the thing that gives up.
        CURLOPT_TIMEOUT        => (int) $ai['timeout'],
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw    = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);

    if ($raw === false) {
        ai_log($progressId, $nodeId, $kind, $ai['model'], null, null, false, $curlError);
        throw new AiError('could not reach the model: ' . $curlError, 504);
    }

    $answer = json_decode((string) $raw, true);
    if (!is_array($answer)) {
        ai_log($progressId, $nodeId, $kind, $ai['model'], null, null, false, 'unreadable answer');
        throw new AiError('the model returned something unreadable', 502);
    }
    if ($status >= 400) {
        $message = (string) ($answer['error']['message'] ?? "request failed ($status)");
        ai_log($progressId, $nodeId, $kind, $ai['model'], null, null, false, $message);
        throw new AiError($message, 502);
    }

    $text = trim((string) ($answer['choices'][0]['message']['content'] ?? ''));
    $usage = $answer['usage'] ?? [];
    ai_log(
        // The model that answered, which is not always the slug that was asked
        // for: a router such as openrouter/auto picks one of its own.
        $progressId, $nodeId, $kind, (string) ($answer['model'] ?? $ai['model']),
        isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
        isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        $text !== '',
        $text === '' ? 'empty answer' : null,
        isset($usage['cost']) && is_numeric($usage['cost']) ? (float) $usage['cost'] : null
    );

    if ($text === '') {
        throw new AiError('the model returned an empty answer', 502);
    }

    return $text;
}

/**
 * Stops one student, or one bad day, from emptying the account. A call with no
 * progress is the admin's own, which has no hourly share -- but it is paid
 * from the same account, so the daily limit counts it all the same.
 */
function ai_check_rate(?int $progressId, int $perHour, int $perDay): void
{
    if ($perHour > 0 && $progressId !== null) {
        $mine = (int) db_value(
            'SELECT COUNT(*) FROM ai_call WHERE progress_id = ? AND created_at > ?',
            [$progressId, gmdate('Y-m-d H:i:s', time() - 3600)]
        );
        if ($mine >= $perHour) {
            throw new AiError('too many requests for now; try again in a little while', 429);
        }
    }
    if ($perDay > 0) {
        $all = (int) db_value(
            'SELECT COUNT(*) FROM ai_call WHERE created_at > ?',
            [gmdate('Y-m-d H:i:s', time() - 86400)]
        );
        if ($all >= $perDay) {
            throw new AiError('this server has reached its daily limit', 429);
        }
    }
}

function ai_log(
    ?int $progressId, string $nodeId, string $kind, string $model,
    ?int $tokensIn, ?int $tokensOut, bool $ok, ?string $error, ?float $cost = null
): void {
    ai_ensure_cost_column();
    db_run(
        'INSERT INTO ai_call (progress_id, node_id, kind, model, tokens_in, tokens_out, cost, ok, error, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$progressId, $nodeId, $kind, mb_substr($model, 0, 80), $tokensIn, $tokensOut, $cost, $ok ? 1 : 0,
         $error === null ? null : mb_substr($error, 0, 255), db_now()]
    );
}

/**
 * `ai_call.cost` came after the first installations, whose table does not have
 * it. Rather than leave every call failing until someone runs an ALTER by hand,
 * the column is added the first time it is missed. Once per request.
 */
function ai_ensure_cost_column(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $has = (int) db_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_call' AND COLUMN_NAME = 'cost'"
    );
    if ($has === 0) {
        db_run('ALTER TABLE ai_call ADD COLUMN cost DECIMAL(12,8) NULL AFTER tokens_out');
    }
}

/** A cost as the admin reads it: dollars, with as many decimals as a cheap call needs. */
function ai_cost_label($cost): string
{
    if ($cost === null || $cost === '') {
        return '—';
    }
    $cost = (float) $cost;
    $text = number_format($cost, $cost >= 0.01 ? 4 : 6, '.', '');
    return '$' . rtrim(rtrim($text, '0'), '.');
}
