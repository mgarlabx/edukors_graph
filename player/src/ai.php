<?php
/**
 * Inference, kept on this side of the wire.
 *
 * The browser never sends a prompt, because it never has one: the course it
 * receives carries a marker where every prompt used to be, and a judge node
 * arrives with its state, its instructions and its criteria removed altogether
 * (see Course::withoutPrompts). All it can ask for is "run node dm1 of the
 * course I am in", and everything else -- which prompt, with which values
 * filled in, which model, how long an answer -- is decided here, from the
 * course stored in the database and from config.php.
 *
 * That is the whole point: there is no request this endpoint accepts that would
 * run text of the caller's choosing.
 *
 * Two kinds of call live here. A dynamic node is *written* by the model, and
 * its text is frozen so a student always reads the same thing. A choice, score
 * or noul node is *judged* by it: the model reads what the student produced and
 * returns a distribution, from which this file derives the storage keys the
 * schema names. The second kind has a rule the first does not -- a judgement
 * that did not happen stores nothing at all, so no condition on its keys holds
 * and the student takes the unconditional edge. Nothing here ever stores a
 * made-up judgement.
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
    $prompt = Course::resolveStorage($prompt, $vars);

    // `from` names the judgement this step explains. The judgement is handed
    // over whole -- what was judged, the level reached, the scale it was
    // reached on and how the weight fell across it -- because the prompt only
    // says how to write it up. It must not judge again, and a second opinion
    // that disagreed with the number routing the student would be worse than
    // no comment at all.
    $judge = $course->node((string) ($node['content']['from'] ?? ''));
    if ($judge !== null) {
        $prompt .= "\n\n" . ai_judgement_block($judge, $vars);
    }

    return ai_call($course->systemPrompt(), $prompt, $lang, $progressId, $nodeId, 'generate');
}

/**
 * A judgement as the node that writes from it receives it.
 *
 * A literal port of `Ai.judgement` in assets/course_player.html: the preview
 * player builds this in the browser, this server builds it here, and a course
 * must read the same either way. Anything changed here must be changed there.
 */
function ai_judgement_block(array $judge, array $vars): string
{
    $id      = (string) ($judge['id'] ?? '');
    $content = $judge['content'] ?? [];
    $lines   = [
        '--- THE JUDGEMENT ALREADY MADE ---',
        '',
        'This was decided by a separate model against the scale written below. '
            . 'It is settled: explain it, do not revisit it.',
        '',
        'What was judged:',
    ];

    foreach (($content['state'] ?? []) as $field => $value) {
        $text = is_array($value) ? implode(', ', $value) : (string) $value;
        $lines[] = "  $field: " . Course::resolveStorage($text, $vars);
    }

    foreach (($content['items'] ?? []) as $item) {
        $key   = (string) ($item['key'] ?? '');
        $value = $vars["$id.$key"] ?? null;
        if ($value === null) {
            continue;
        }
        $lines[] = '';
        $lines[] = "Question \"$key\": " . (string) ($item['instructions'] ?? '');

        $legend = $vars["$id.$key-legend"] ?? null;
        $probs  = $vars["$id.$key-probabilities"] ?? null;
        if (is_array($legend)) {
            $lines[] = "  Level reached: " . ai_judge_number($value)
                     . ' on a scale of 0 to ' . (count($legend) - 1);
            foreach ($legend as $level => $text) {
                $weight  = is_array($probs) ? ($probs[(string) $level] ?? null) : null;
                $share   = $weight === null ? '' : '  (' . round(100 * (float) $weight) . '% of the weight)';
                $nearest = round((float) $value) == (float) $level ? '  <- nearest level' : '';
                $lines[] = "    $level - $text$share$nearest";
            }
        } else {
            $lines[] = '  Answer: ' . ai_judge_number($value);
        }

        $points = $vars["$id.$key-points"] ?? null;
        if ($points !== null) {
            $lines[] = '  Points: ' . ai_judge_number($points);
        }
        $confidence = $vars["$id.$key-confidence"] ?? null;
        if ($confidence !== null) {
            $lines[] = '  How sure the model is: ' . ai_judge_number($confidence);
        }
    }

    $total = $vars["$id.total"] ?? null;
    if ($total !== null) {
        $lines[] = '';
        $lines[] = 'Total: ' . ai_judge_number($total) . ' points ('
                 . ai_judge_number($vars["$id.percent"] ?? 0) . '% of the highest possible)';
    }

    return implode("\n", $lines);
}

/**
 * A number as JavaScript prints it, because the other half of this pair is
 * JavaScript: 1.43 stays 1.43, and 143.0 is "143", not "143.00".
 */
function ai_judge_number($value): string
{
    if (is_bool($value))  { return $value ? 'true' : 'false'; }
    if (!is_numeric($value)) { return (string) $value; }
    $number = (float) $value;
    return (string) ($number == (int) $number ? (int) $number : $number);
}

// ---------------------------------------------------------------------------
// Judgements -- the choice, score and noul nodes
// ---------------------------------------------------------------------------

/**
 * A judgement that did not happen.
 *
 * Not an error, and never shown to anybody: every judge node is required to
 * carry an unconditional edge precisely so that this has somewhere to go. The
 * message says why, and is kept with the node so an author can read it.
 */
final class AiNotJudged extends RuntimeException {}

/**
 * Judges a node and returns the storage keys it produced.
 *
 * @return array{judged:bool,vars:array,reason:?string}
 */
function ai_judge(array $progressRow, Course $course, string $nodeId): array
{
    $type = (string) ($course->nodeType($nodeId) ?? '');
    if (!in_array($type, Course::JUDGE_TYPES, true)) {
        throw new AiError("node $nodeId is not a node the AI judges", 400);
    }

    ai_ensure_judge_columns();

    $progressId = (int) $progressRow['id'];
    $state      = progress_state($progressRow);
    $history    = is_array($state['history'] ?? null) ? $state['history'] : [];
    $visit      = count(array_keys($history, $nodeId, true)) + 1;

    // A reload comes back to the same step. The judgement it already paid for
    // is the one that chose the path, so it is handed back rather than made
    // again. Coming back later, after other steps, is a new visit -- and the
    // schema asks for a new judgement then, overwriting the keys.
    $frozen = ai_judge_frozen($progressId, $nodeId, $visit);
    if ($frozen !== null) {
        return $frozen;
    }

    $result = ai_judge_node($course, $nodeId, progress_vars($progressRow), $progressId);

    node_state_write($progressId, $nodeId, $type, [
        'verdict' => json_encode(
            ['visit' => $visit] + $result['verdict'],
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ),
        // The comparable number, for the admin's column. A judgement with no
        // points has none, and a judgement that did not happen has none either.
        'score' => isset($result['vars']["$nodeId.percent"])
            ? (int) round((float) $result['vars']["$nodeId.percent"])
            : null,
    ]);

    unset($result['verdict']);
    return $result;
}

/** The judgement already made on this visit, or null if there is none. */
function ai_judge_frozen(int $progressId, string $nodeId, int $visit): ?array
{
    $row = node_state_read($progressId, $nodeId);
    if ($row === null || ($row['verdict'] ?? null) === null || $row['verdict'] === '') {
        return null;
    }
    $verdict = json_decode((string) $row['verdict'], true);
    if (!is_array($verdict) || ($verdict['visit'] ?? null) !== $visit) {
        return null;
    }
    return [
        'judged' => (bool) ($verdict['judged'] ?? false),
        'vars'   => is_array($verdict['vars'] ?? null) ? $verdict['vars'] : [],
        'reason' => $verdict['reason'] ?? null,
    ];
}

/**
 * Judges a node with nothing kept, as ai_write_step() writes a step.
 *
 * Everything is derived before anything is returned, because the confidence
 * floor belongs to the node and not to one of its questions: one question under
 * it and the whole node counts as not judged.
 *
 * @return array{judged:bool,vars:array,reason:?string,verdict:array}
 */
function ai_judge_node(Course $course, string $nodeId, array $vars, ?int $progressId): array
{
    $node = $course->node($nodeId);
    $type = $node === null ? '' : (string) ($node['type'] ?? '');
    if (!in_array($type, Course::JUDGE_TYPES, true)) {
        throw new AiError("node $nodeId is not a node the AI judges", 400);
    }

    $content = $node['content'] ?? [];
    $items   = is_array($content['items'] ?? null) ? $content['items'] : [];
    $verdict = ['node' => $nodeId, 'type' => $type, 'at' => gmdate('Y-m-d H:i:s')];

    try {
        $model = ai_judge_model($course);
        $state = ai_judge_state($content, $vars);

        $verdict['model'] = $model;
        $verdict['state'] = $state;

        // Once, and never asked twice. A decisions model answers in types
        // rather than in prose, so an answer that cannot be read is not a
        // wording to be repaired by saying it differently -- it is a model
        // that did not answer the question the author wrote.
        $answers = ai_judge_ask(
            ai_judge_body($type, $items, $state, $model),
            $progressId,
            $nodeId
        );
        $verdict['answers'] = $answers;

        $verdict['judged'] = true;
        $verdict['vars']   = ai_judge_vars(
            $nodeId, $type, $content, ai_judge_read($answers, $type, $items)
        );

        return ['judged' => true, 'vars' => $verdict['vars'], 'reason' => null, 'verdict' => $verdict];
    } catch (AiNotJudged | AiError $stopped) {
        // Both end the same way, and on purpose: a model that refused, a model
        // that could not be reached and a rate limit all mean there is no
        // judgement, and the course has one path for that.
        $verdict['judged'] = false;
        $verdict['reason'] = $stopped->getMessage();
        return ['judged' => false, 'vars' => [], 'reason' => $verdict['reason'], 'verdict' => $verdict];
    }
}

/**
 * The slug that answers this course's judgements.
 *
 * A course names an exact version in `info.judge-model` because its thresholds,
 * its points and its confidence floors were tuned against one version of one
 * model. A server says here which of its own models stands for that name, and a
 * name it has no line for is not answered by the generation model instead: a
 * judgement from an unknown model is the thing the exact version exists to
 * prevent.
 */
function ai_judge_model(Course $course): string
{
    $named = trim((string) ($course->info()['judge-model'] ?? ''));
    if ($named === '') {
        throw new AiNotJudged('this course names no judge-model.');
    }
    $models = edukors_config()['judge']['models'] ?? [];
    $slug   = is_array($models) ? trim((string) ($models[$named] ?? '')) : '';
    if ($slug === '') {
        throw new AiNotJudged("this server has no model configured for judge-model \"$named\".");
    }
    return $slug;
}

/**
 * The node's state, as the decisions endpoint receives it.
 *
 * A literal port of `JudgeView.request` in assets/course_player.html: every
 * {{STORAGE: key}} filled in from the student's work, and a field the author
 * wrote as a list stays a list, because `state` takes a text, an object or a
 * list and the author's own copy sends it that way.
 *
 * @return array<string,string|string[]>
 */
function ai_judge_state(array $content, array $vars): array
{
    $state = [];
    foreach (($content['state'] ?? []) as $field => $value) {
        $state[(string) $field] = is_array($value)
            ? array_map(
                static fn($part) => Course::resolveStorage((string) $part, $vars),
                array_values($value)
              )
            : Course::resolveStorage((string) $value, $vars);
    }
    return $state;
}

/**
 * The request a judgement makes, without sending it.
 *
 * The twin of `JudgeView.request` in assets/course_player.html, which is what
 * the author's copy saves to paste into the playground: the same {model, state,
 * questions}. A question is the author's own item with its node's type on it.
 * There is no prompt here and no instruction this server wrote -- a decisions
 * model is asked in the shape it answers in, which is the whole reason the
 * schema was written against these three types.
 *
 * Separate from ai_judge_ask() so that tools/judge-probe.php can try a model
 * against a real course without a database behind it, and try the same body
 * this would have sent rather than one written twice.
 */
function ai_judge_body(string $type, array $items, array $state, string $model): array
{
    $questions = [];
    foreach ($items as $item) {
        $question = [
            'type'         => $type,
            'instructions' => (string) ($item['instructions'] ?? ''),
        ];
        // `criteria` is the options of a choice, the levels of a score, and on
        // a noul the words for a yes and a no, which the author may leave out.
        // Absent stays absent: an empty one would be a list nobody wrote.
        if (isset($item['criteria']) && $item['criteria'] !== []) {
            $question['criteria'] = $item['criteria'];
        }
        $questions[(string) ($item['key'] ?? '')] = $question;
    }

    return [
        'model'     => $model,
        'state'     => $state,
        'questions' => $questions,
        // A pinned route. `info.judge-model` names an exact version because the
        // thresholds on the edges, the points on the levels and the confidence
        // floors were tuned against it, and a fallback to another provider
        // moves under them without saying so.
        'provider'  => ['allow_fallbacks' => false],
    ];
}

/**
 * One call, with the judge's own settings.
 *
 * @return array<string,array> the answers, keyed as the questions were
 */
function ai_judge_ask(array $body, ?int $progressId, string $nodeId): array
{
    $judge  = edukors_config()['judge'];
    $asked  = (string) ($body['model'] ?? '');
    $answer = ai_decide($body, $progressId, $nodeId, (int) ($judge['timeout'] ?? 45));

    if (($judge['strict_model'] ?? true) && $answer['model'] !== ''
        && !ai_judge_same_model($answer['model'], $asked)) {
        throw new AiNotJudged(
            "the answer came back from \"{$answer['model']}\", which is not the \"$asked\" this course is tuned against."
        );
    }
    if ($answer['answers'] === []) {
        throw new AiNotJudged('the model answered nothing.');
    }

    return $answer['answers'];
}

/**
 * Whether what answered is what was asked for.
 *
 * Exact, with one allowance: a versioned slug comes back as the dated build
 * behind it, so "typesafe/jev-1.13" answers as "typesafe/jev-1.13-20260917".
 * That is the same version of the same model, and a date is the only thing it
 * may add. Anything else is another model, which is what naming an exact
 * version exists to refuse.
 */
function ai_judge_same_model(string $answered, string $asked): bool
{
    return $answered === $asked
        || preg_match('/^' . preg_quote($asked, '/') . '-\d{8}$/', $answered) === 1;
}

/**
 * Reads the answers, each one checked against the question it was asked of.
 *
 * Strict throughout, and never repaired: a number this server invented is not a
 * judgement, and the level it moves is what chooses the student's path.
 * Everything that does not read cleanly ends as a node that was not judged,
 * which every course carries an unconditional edge for.
 *
 * @return array<string,array> question key => its answer, checked
 */
function ai_judge_read(array $answers, string $type, array $items): array
{
    $read = [];
    foreach ($items as $item) {
        $key   = (string) ($item['key'] ?? '');
        $given = $answers[$key] ?? null;
        if (!is_array($given)) {
            // Never re-asked on its own: that would be a second judgement, made
            // against a different context, and the schema says the questions of
            // a node are judged together, in one call.
            throw new AiNotJudged("question \"$key\" was not answered.");
        }
        $read[$key] = match ($type) {
            'noul'   => ai_judge_read_noul($key, $given),
            'choice' => ai_judge_read_choice($key, $given, $item),
            default  => ai_judge_read_score($key, $given, $item),
        };
    }
    return $read;
}

/** A noul: one number from 0 to 1, and nothing else to read. */
function ai_judge_read_noul(string $key, array $given): array
{
    if (!is_numeric($given['noul'] ?? null)) {
        throw new AiNotJudged("question \"$key\" came back without a number.");
    }
    return ['noul' => ai_judge_unit($key, (float) $given['noul'])];
}

/** A choice: the option it picked, how sure it is, and where the rest of its belief sat. */
function ai_judge_read_choice(string $key, array $given, array $item): array
{
    $options = is_array($item['criteria'] ?? null)
        ? array_map('strval', array_keys($item['criteria']))
        : [];
    if ($options === []) {
        throw new AiNotJudged("question \"$key\" has no options to be answered with.");
    }

    $choice = is_string($given['choice'] ?? null) ? trim($given['choice']) : '';
    if ($choice === '') {
        throw new AiNotJudged("question \"$key\" chose nothing.");
    }
    if (!in_array($choice, $options, true)) {
        // An answer from outside the list the author wrote is the one thing
        // these nodes exist to rule out.
        throw new AiNotJudged("question \"$key\" answered \"$choice\", which is not one of its options.");
    }

    return [
        'choice'        => $choice,
        'confidence'    => ai_judge_confidence($key, $given),
        'probabilities' => ai_judge_spread($key, $given, $options),
    ];
}

/** A score: where on the scale it landed, how sure it is, and its belief over the levels. */
function ai_judge_read_score(string $key, array $given, array $item): array
{
    $levels = is_array($item['criteria'] ?? null) ? count($item['criteria']) : 0;
    if ($levels < 2) {
        throw new AiNotJudged("question \"$key\" has no scale to be answered on.");
    }
    if (!is_numeric($given['score'] ?? null)) {
        throw new AiNotJudged("question \"$key\" came back without a level.");
    }

    // The level the model answered with, which is its own number and not one
    // worked out here: it runs from 0, the first level listed, to one less than
    // the number of levels, and it is not a whole number.
    $score = (float) $given['score'];
    if ($score < 0 || $score > $levels - 1) {
        throw new AiNotJudged(
            "question \"$key\" came back at $score, which is off a scale of $levels levels."
        );
    }

    return [
        'score'         => $score,
        'confidence'    => ai_judge_confidence($key, $given),
        'probabilities' => ai_judge_spread($key, $given, array_map('strval', range(0, $levels - 1))),
    ];
}

/**
 * One question's belief, checked against the labels it was allowed.
 *
 * A choice answers with a map of its options; a score with one number per
 * level, in order. Both arrive here as the labels the author wrote, in the
 * author's own order, so that what is stored does not depend on which of the
 * two shapes came over the wire.
 *
 * @return array<string,float> label => 0 to 1
 */
function ai_judge_spread(string $key, array $given, array $labels): array
{
    $spread = $given['probabilities'] ?? null;
    if (!is_array($spread) || $spread === []) {
        throw new AiNotJudged("question \"$key\" came back with no probabilities.");
    }

    // A score's list arrives with the numbers 0, 1, 2 as its keys, and PHP has
    // already turned those into integers; the labels they are matched against
    // are text.
    $found = [];
    foreach ($spread as $label => $weight) {
        $found[(string) $label] = $weight;
    }

    $read = [];
    $sum  = 0.0;
    foreach ($labels as $label) {
        if (!array_key_exists($label, $found)) {
            throw new AiNotJudged("question \"$key\" left \"$label\" out of its probabilities.");
        }
        if (!is_numeric($found[$label])) {
            throw new AiNotJudged("question \"$key\" gave \"$label\" something that is not a number.");
        }
        $read[$label] = ai_judge_unit($key, (float) $found[$label]);
        $sum         += $read[$label];
        unset($found[$label]);
    }

    foreach ($found as $label => $weight) {
        // A label nobody asked for, carrying nothing, is a stray zero and costs
        // the judgement nothing. Carrying weight, it is belief placed outside
        // the list the author wrote.
        if (is_numeric($weight) && (float) $weight > 0) {
            throw new AiNotJudged("question \"$key\" put weight on \"$label\", which is not one of its labels.");
        }
    }

    // The numbers come back rounded, so a whole that misses 1 by a rounding
    // step is the format and not a defect. Anything wider is a distribution
    // that does not mean what it says, and nothing here renormalises it into
    // one that does: the level a renormalised spread moves is a path chosen by
    // arithmetic this server invented.
    $slack = max(0.02, 0.005 * count($labels));
    if (abs($sum - 1.0) > $slack) {
        throw new AiNotJudged(
            "question \"$key\" added up to " . round($sum, 3) . ", not to 1."
        );
    }

    return $read;
}

/** How sure the model says it is, which the node's floor is measured against. */
function ai_judge_confidence(string $key, array $given): float
{
    if (!is_numeric($given['confidence'] ?? null)) {
        throw new AiNotJudged("question \"$key\" came back without a confidence.");
    }
    return ai_judge_unit($key, (float) $given['confidence']);
}

/** Every number these answers carry is a probability, and none of them may leave 0..1. */
function ai_judge_unit(string $key, float $value): float
{
    if ($value < 0 || $value > 1) {
        throw new AiNotJudged("question \"$key\" came back with $value, which is not a number from 0 to 1.");
    }
    return $value;
}

/**
 * The storage keys a judgement produces.
 *
 * The twin of `JudgeView.collect` in assets/course_player.html, which mints the
 * same keys from what an author picked in the preview. The two have to agree
 * down to the rounding, or a course would branch one way on a laptop and
 * another way here. Anything changed here must be changed there.
 */
function ai_judge_vars(string $nodeId, string $type, array $content, array $read): array
{
    $items = is_array($content['items'] ?? null) ? $content['items'] : [];
    $floor = is_numeric($content['confidence'] ?? null) ? (float) $content['confidence'] : 0.0;

    $vars     = [];
    $total    = 0.0;
    $possible = 0.0;
    $scored   = false;

    foreach ($items as $item) {
        $key    = (string) ($item['key'] ?? '');
        $answer = $read[$key] ?? [];

        if ($type === 'noul') {
            // The probability it returns is already the measure of how sure it
            // is, so a noul carries no confidence key and takes no floor.
            $vars["$nodeId.$key"] = round((float) $answer['noul'], 2);
            continue;
        }

        // How sure the model says it is, as it says it. It is not read back out
        // of the spread: the model answers this question itself, and a floor an
        // author set on the preview's slider means this number.
        $sure = round((float) $answer['confidence'], 3);
        if ($sure < $floor) {
            throw new AiNotJudged("question \"$key\" came back at $sure, under this node's floor of $floor.");
        }

        if ($type === 'choice') {
            $vars["$nodeId.$key"]               = (string) $answer['choice'];
            $vars["$nodeId.$key-confidence"]    = $sure;
            $vars["$nodeId.$key-probabilities"] = ai_judge_shares($answer['probabilities'], 3);
            continue;
        }

        // A score. The level is not a whole number, and 1.43 on a scale of
        // three is an ordinary answer meaning "between the second and the
        // third, nearer the second".
        $criteria = is_array($item['criteria'] ?? null) ? array_values($item['criteria']) : [];
        $chance   = is_array($answer['probabilities'] ?? null) ? $answer['probabilities'] : [];

        $vars["$nodeId.$key"]               = round((float) $answer['score'], 2);
        $vars["$nodeId.$key-confidence"]    = $sure;
        $vars["$nodeId.$key-probabilities"] = ai_judge_shares($chance, 2);
        $vars["$nodeId.$key-legend"]        = ai_judge_legend($criteria);

        $points = $item['points'] ?? null;
        if (is_array($points) && $criteria !== [] && count($points) === count($criteria)) {
            $earned = 0.0;
            foreach (array_values($points) as $i => $worth) {
                $earned += (float) $worth * (float) ($chance[(string) $i] ?? 0);
            }
            $vars["$nodeId.$key-points"] = round($earned, 2);
            // The total sums what each question earned before rounding and
            // rounds once at the end, as the player does -- not the sum of the
            // rounded keys above.
            $total   += $earned;
            $possible = $possible + max(array_map('floatval', array_values($points)));
            $scored   = true;
        }
    }

    if ($scored) {
        $vars["$nodeId.total"]   = round($total, 2);
        $vars["$nodeId.percent"] = $possible > 0 ? (int) round($total / $possible * 100) : 0;
    }

    return $vars;
}

/** The probabilities as the schema stores them, every label kept, rounded once. */
function ai_judge_shares(array $chance, int $places): array
{
    $shares = [];
    foreach ($chance as $label => $weight) {
        $shares[(string) $label] = round((float) $weight, $places);
    }
    return $shares;
}

/** Level number to the words the author wrote for it. */
function ai_judge_legend(array $criteria): array
{
    $legend = [];
    foreach (array_values($criteria) as $level => $text) {
        $legend[(string) $level] = (string) $text;
    }
    return $legend;
}

/**
 * The maps a judgement produces are keyed by level number, and PHP turns "0",
 * "1", "2" back into a list the moment it encodes them. The schema says these
 * are maps and the player reads them as maps, so they are cast back on the way
 * out.
 */
function ai_judge_maps(array $vars): array
{
    foreach ($vars as $key => $value) {
        if (is_array($value) && (str_ends_with($key, '-probabilities') || str_ends_with($key, '-legend'))) {
            $vars[$key] = (object) $value;
        }
    }
    return $vars;
}

/**
 * One call to write a step, through OpenRouter.
 *
 * Everything that shapes the request comes from the course and from config.php.
 * Nothing here can be influenced by what the browser sent beyond the node it
 * named.
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
    if (($ai['model'] ?? '') === '') {
        throw new AiError('no model is configured on this server', 503);
    }

    // The course-wide instructions go as written: {{STORAGE: key}} is resolved
    // in a node's prompt, never in the system prompt.
    $system = trim($systemPrompt . "\n\nThe student's language is \"$lang\". Answer in that language.");

    $answer = ai_send([
        'model'       => $ai['model'],
        'max_tokens'  => (int) $ai['max_tokens'],
        'temperature' => (float) $ai['temperature'],
        // Asks OpenRouter to put what the call cost, in dollars, in `usage`.
        'usage'       => ['include' => true],
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ], $progressId, $nodeId, $kind, (int) $ai['timeout']);

    if ($answer['text'] === '') {
        throw new AiError('the model returned an empty answer', 502);
    }

    return $answer['text'];
}

/**
 * One POST to OpenRouter, and nothing decided here beyond the key.
 *
 * Shared by the two calls this server makes. They do not go to the same place:
 * a step is written by a chat model at `ai.url`, and a judgement is asked of a
 * decisions model at `judge.url`, which refuses chat/completions in so many
 * words. What they share is the account, the headers and the patience.
 *
 * It writes nothing: no rate limit, no log, no exception. The caller owns all
 * three, because what counts as an empty answer differs between the two.
 *
 * @return array{status:int,json:?array,error:?string}
 */
function ai_http(string $url, array $body, int $timeout): array
{
    $ai = edukors_config()['ai'];

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

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        // The player shows a spinner with no timeout of its own, so this one
        // has to be the thing that gives up.
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw       = curl_exec($curl);
    $status    = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    // No curl_close(): it has done nothing since PHP 8.0 and is deprecated from
    // 8.5, where calling it would print a notice into the JSON this answers with.

    if ($raw === false) {
        return [
            'status' => 0,
            'json'   => null,
            'error'  => $curlError !== '' ? $curlError : 'the call did not finish',
        ];
    }

    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        return ['status' => $status, 'json' => null, 'error' => 'unreadable answer'];
    }
    if ($status >= 400) {
        return [
            'status' => $status,
            'json'   => $json,
            'error'  => (string) ($json['error']['message'] ?? "request failed ($status)"),
        ];
    }

    return ['status' => $status, 'json' => $json, 'error' => null];
}

/**
 * A call that did not come back with an answer, as the error it is.
 *
 * The log keeps the short form, which is what fits its column; this is the
 * sentence, and for an AiError it is what reaches whoever is looking at the
 * step -- so a call that never landed says so, and is told apart from one that
 * landed on a refusal.
 */
function ai_failed(array $call): AiError
{
    if ($call['status'] === 0) {
        return new AiError('could not reach the model: ' . $call['error'], 504);
    }
    if ($call['json'] === null) {
        return new AiError('the model returned something unreadable', 502);
    }
    return new AiError((string) $call['error'], 502);
}

/**
 * A step written by a chat model: a body in, what the model said out.
 *
 * The rate limit and the log are here rather than in ai_http() so that the two
 * kinds of call are counted and logged the same way, against the same account.
 *
 * @return array{text:string,model:string,finish:string}
 */
function ai_send(array $body, ?int $progressId, string $nodeId, string $kind, int $timeout): array
{
    $ai = edukors_config()['ai'];
    if (($ai['key'] ?? '') === '') {
        throw new AiError('no model is configured on this server', 503);
    }
    $asked = (string) ($body['model'] ?? '');
    if ($asked === '') {
        throw new AiError('no model is configured on this server', 503);
    }

    ai_check_rate($progressId, (int) $ai['per_hour'], (int) $ai['per_day']);

    $call = ai_http((string) $ai['url'], $body, $timeout);
    if ($call['error'] !== null) {
        ai_log($progressId, $nodeId, $kind, $asked, null, null, false, $call['error']);
        throw ai_failed($call);
    }

    $answer = $call['json'];
    $text   = trim((string) ($answer['choices'][0]['message']['content'] ?? ''));
    $usage  = $answer['usage'] ?? [];
    // The model that answered, which is not always the slug that was asked for:
    // a router such as openrouter/auto picks one of its own. A judgement is
    // refused over that difference -- see ai_judge_ask() -- so it is logged as
    // what actually answered, not as what was wanted.
    $answered = (string) ($answer['model'] ?? '');
    ai_log(
        $progressId, $nodeId, $kind, $answered !== '' ? $answered : $asked,
        isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
        isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        $text !== '',
        $text === '' ? 'empty answer' : null,
        isset($usage['cost']) && is_numeric($usage['cost']) ? (float) $usage['cost'] : null
    );

    return [
        'text'   => $text,
        'model'  => $answered,
        'finish' => (string) ($answer['choices'][0]['finish_reason'] ?? ''),
    ];
}

/**
 * A judgement asked of a decisions model: a request in, typed answers out.
 *
 * It does not go where ai_send() goes, and it cannot. A decisions model is not
 * a chat model: OpenRouter refuses it at chat/completions and says so, which is
 * why the judge has an endpoint of its own in `judge.url`. The envelope it
 * answers with has no message in it either -- the answers arrive typed, under
 * the keys the questions were asked under, with nothing to parse out of prose.
 *
 * @return array{answers:array,model:string}
 */
function ai_decide(array $body, ?int $progressId, string $nodeId, int $timeout): array
{
    $ai    = edukors_config()['ai'];
    $judge = edukors_config()['judge'];
    if (($ai['key'] ?? '') === '') {
        throw new AiError('no model is configured on this server', 503);
    }
    $asked = (string) ($body['model'] ?? '');
    if ($asked === '') {
        throw new AiError('no model is configured on this server', 503);
    }
    $url = trim((string) ($judge['url'] ?? ''));
    if ($url === '') {
        throw new AiError('no decisions endpoint is configured on this server', 503);
    }

    // Paid from the same account as a written step, so counted against the
    // same limits.
    ai_check_rate($progressId, (int) $ai['per_hour'], (int) $ai['per_day']);

    $call = ai_http($url, $body, $timeout);
    if ($call['error'] !== null) {
        ai_log($progressId, $nodeId, 'judge', $asked, null, null, false, $call['error']);
        throw ai_failed($call);
    }

    $answer   = $call['json'];
    $answers  = is_array($answer['answers'] ?? null) ? $answer['answers'] : [];
    $usage    = $answer['usage'] ?? [];
    $answered = (string) ($answer['model'] ?? '');
    ai_log(
        $progressId, $nodeId, 'judge', $answered !== '' ? $answered : $asked,
        // A decisions call names its tokens differently from a chat call, and
        // it produces none: the answers are typed, not written.
        isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
        isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
        $answers !== [],
        $answers === [] ? 'empty answer' : null,
        isset($usage['cost']) && is_numeric($usage['cost']) ? (float) $usage['cost'] : null
    );

    return ['answers' => $answers, 'model' => $answered];
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

/**
 * The two upgrades judgements need on a database built before them: somewhere
 * to keep a verdict, and a `kind` that admits one. Added the first time they
 * are missed, as `cost` was, so that an installation updates itself instead of
 * failing until somebody runs an ALTER by hand. Once per request.
 */
function ai_ensure_judge_columns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $hasVerdict = (int) db_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'node_state' AND COLUMN_NAME = 'verdict'"
    );
    if ($hasVerdict === 0) {
        db_run('ALTER TABLE node_state ADD COLUMN verdict MEDIUMTEXT NULL AFTER answer');
    }

    $kind = (string) db_value(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_call' AND COLUMN_NAME = 'kind'"
    );
    if ($kind !== '' && strpos($kind, "'judge'") === false) {
        // 'grade' is kept although no code writes it any more. This table is
        // the bill, and rows from before the essay node went away are history.
        db_run("ALTER TABLE ai_call MODIFY kind ENUM('generate','grade','judge') NOT NULL");
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
