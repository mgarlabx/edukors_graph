<?php
/**
 * Tries a model against one judge node of a course, and says whether it can do
 * the job -- before a student is the one who finds out.
 *
 *     php tools/judge-probe.php course.json c1
 *     php tools/judge-probe.php course.json s1 --model openai/gpt-4o-2024-11-20
 *     php tools/judge-probe.php course.json c1 --answer "what the student wrote"
 *
 * It answers the three questions worth asking of a judge model:
 *
 *   1. Does the slug come back as the slug that was asked for? `judge.strict_model`
 *      refuses a judgement that came from somewhere else, and OpenRouter serves one
 *      name from several providers -- so a slug that does not round-trip refuses
 *      every judgement of every course, silently, for ever.
 *   2. Does it obey the JSON contract -- every question answered, only the labels
 *      the author wrote, whole numbers adding up to 100?
 *   3. What does the judgement actually produce, and does it clear the node's
 *      confidence floor?
 *
 * It writes nothing: no database, no progress, no node_state, no ai_call row --
 * so the daily limit does not see it either. The call itself is real and is paid
 * for like any other.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/ai.php';

// -- what was asked for ----------------------------------------------------

$args = array_slice($argv, 1);
$file = $node = $model = null;
$answer = 'I think cities should grow upward rather than outward, because the cost of '
        . 'running a sprawling city falls on everybody while the benefit falls on a few. '
        . 'The people who lose are the ones already living in the low blocks, who lose '
        . 'their light to the tower next door.';

for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--model' && isset($args[$i + 1]))  { $model  = $args[++$i]; continue; }
    if ($args[$i] === '--answer' && isset($args[$i + 1])) { $answer = $args[++$i]; continue; }
    if ($file === null) { $file = $args[$i]; } elseif ($node === null) { $node = $args[$i]; }
}

if ($file === null || $node === null) {
    fwrite(STDERR, "usage: php tools/judge-probe.php <course.json> <node-id> [--model <slug>] [--answer <text>]\n");
    exit(2);
}
if (!is_file($file)) {
    fwrite(STDERR, "no such file: $file\n");
    exit(2);
}

$course = Course::fromJson((string) file_get_contents($file));
if (!in_array((string) $course->nodeType($node), Course::JUDGE_TYPES, true)) {
    fwrite(STDERR, "$node is not a choice, score or noul node\n");
    exit(2);
}

$named = trim((string) ($course->info()['judge-model'] ?? ''));
if ($model === null) {
    try {
        $model = ai_judge_model($course);
    } catch (AiNotJudged $e) {
        fwrite(STDERR, 'this course cannot be judged yet: ' . $e->getMessage() . "\n"
            . "Add a line for it under judge.models in private/config.php, or pass --model.\n");
        exit(2);
    }
}

$config = edukors_config();
$key    = (string) ($config['ai']['key'] ?? '');
if ($key === '' || $key === 'sk-or-v1-...') {
    fwrite(STDERR, "no OpenRouter key yet: ai.key in private/config.php is still the sample's\n"
        . "placeholder. Put a real key there, or in EDUKORS_OPENROUTER_KEY.\n");
    exit(2);
}
if ($model === 'FILL-ME-IN') {
    fwrite(STDERR, "judge.models still says FILL-ME-IN for \"$named\". Put the OpenRouter slug\n"
        . "there, or pass one with --model to try it before writing it down.\n");
    exit(2);
}

// -- the request this node would make --------------------------------------

$step    = $course->node($node);
$content = $step['content'];
$items   = $content['items'];

// Every {{STORAGE: key}} of the state is answered with the same stand-in text,
// so the probe needs no student behind it.
$vars = [];
foreach ($content['state'] ?? [] as $value) {
    foreach (is_array($value) ? $value : [$value] as $text) {
        preg_match_all('/\{\{\s*STORAGE:\s*([^}]+?)\s*\}\}/', (string) $text, $found);
        foreach ($found[1] as $raw) {
            $vars[trim($raw)] = $answer;
        }
    }
}

$state    = ai_judge_state($content, $vars);
$messages = [
    ['role' => 'system', 'content' => ai_judge_system()],
    ['role' => 'user',   'content' => ai_judge_questions((string) $step['type'], $items, $state)],
];
$body = ai_judge_body($messages, $model, $items);

echo "course      " . $course->title() . "\n";
echo "node        $node (" . $step['type'] . ", " . count($items) . " question(s))\n";
echo "judge-model " . ($named === '' ? '(none named)' : $named) . "\n";
echo "asking      $model\n";
echo "floor       " . (is_numeric($content['confidence'] ?? null) ? $content['confidence'] : '0 (none set)') . "\n\n";

// -- the call, made here rather than through ai_send(), which logs to MySQL --

$curl = curl_init((string) $config['ai']['url']);
curl_setopt_array($curl, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => (int) ($config['judge']['timeout'] ?? 45),
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$raw    = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$oops   = curl_error($curl);

if ($raw === false) {
    fwrite(STDERR, "could not reach the model: $oops\n");
    exit(1);
}
$reply = json_decode((string) $raw, true);
if (!is_array($reply)) {
    fwrite(STDERR, "the model returned something unreadable\n");
    exit(1);
}
if ($status >= 400) {
    fwrite(STDERR, 'the request failed: ' . (string) ($reply['error']['message'] ?? "status $status") . "\n");
    exit(1);
}

$text     = trim((string) ($reply['choices'][0]['message']['content'] ?? ''));
$finish   = (string) ($reply['choices'][0]['finish_reason'] ?? '');
$answered = (string) ($reply['model'] ?? '');
$cost     = $reply['usage']['cost'] ?? null;

$verdicts = [];
$ok       = true;

// 1. the slug
if ($answered === '') {
    $verdicts[] = ['?', 'the answer names no model, so strict_model cannot check it'];
} elseif ($answered === $model) {
    $verdicts[] = ['ok', "the slug round-trips: strict_model will accept it"];
} else {
    $ok = false;
    $verdicts[] = ['NO', "asked \"$model\", answered \"$answered\"\n"
        . "     strict_model would refuse every judgement. Either put \"$answered\" in\n"
        . "     judge.models instead, or set judge.strict_model => false and accept that\n"
        . "     the route can move under your thresholds."];
}

// 2. the contract
$spread = null;
if ($finish === 'length') {
    $ok = false;
    $verdicts[] = ['NO', 'the answer was cut off -- ai_judge_tokens() sized it too small for this node'];
} else {
    try {
        $spread = ai_judge_read($text, (string) $step['type'], $items);
        $verdicts[] = ['ok', 'the answer obeys the JSON contract'];
    } catch (AiNotJudged $e) {
        $ok = false;
        $verdicts[] = ['NO', 'the answer was refused: ' . $e->getMessage()];
    }
}

// 3. the judgement
$vars = [];
if ($spread !== null) {
    try {
        $vars = ai_judge_vars($node, (string) $step['type'], $content, $spread);
        $verdicts[] = ['ok', 'the judgement clears the node\'s confidence floor'];
    } catch (AiNotJudged $e) {
        $ok = false;
        $verdicts[] = ['NO', 'not judged: ' . $e->getMessage() . "\n"
            . '     On one answer that is not a verdict on the model -- try a few. On most of'
            . "\n     them, the floor is above what this model will commit to."];
    }
}

foreach ($verdicts as [$mark, $line]) {
    printf("%-4s %s\n", $mark, $line);
}

echo "\n--- what it said " . str_repeat('-', 50) . "\n$text\n";

if ($vars !== []) {
    echo "\n--- the keys it would store " . str_repeat('-', 40) . "\n";
    foreach ($vars as $key => $value) {
        printf("  %-28s %s\n", $key, json_encode($value, JSON_UNESCAPED_UNICODE));
    }
    $next = $course->nextNodeId($node, $vars);
    echo "\n  the student would go to: " . ($next ?? '(the end of the course)') . "\n";
}

if ($cost !== null) {
    echo "\ncost " . ai_cost_label($cost) . " for this one call\n";
}

exit($ok ? 0 : 1);
