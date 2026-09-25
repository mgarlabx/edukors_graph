<?php
/**
 * Validates a course JSON before it is imported.
 *
 * It is a port of the three layers of the builder's validate_course.py --
 * structure, graph and storage keys -- minus the one check that is a matter of
 * pedagogy rather than of running the course (how the correct answers of the
 * quizzes are spread across the options). Keeping that one in the skill avoids
 * two implementations of a judgement call drifting apart.
 *
 * An error stops the import: it is something that would break in front of a
 * student. A warning is kept with the course and shown in the admin.
 */

declare(strict_types=1);

final class CourseValidator
{
    private const LANG_RE    = '/^[a-z]{2}(-[A-Z]{2})?$/';
    private const ID_RE      = '/^(sm|sh|dm|dh|q|f|b|c|s|n)[0-9]+$/';
    private const NAME_RE    = '/^[a-z][a-z0-9-]*$/';
    private const VERSION_RE = '/^[0-9]+\.[0-9]+\.[0-9]+$/';
    private const DATE_RE    = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';
    private const UUID_RE    = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    private const KEY_RE     = '/^(dm|dh|q|f|b|c|s|n)[0-9]+\.[a-z][a-z0-9-]*$/';
    private const STORAGE_RE = '/\{\{\s*STORAGE:\s*([^}]+?)\s*\}\}/';
    private const FROM_RE    = '/^(c|s|n)[0-9]+$/';
    /**
     * Verbs that give away a feedback prompt judging all over again. Whole words
     * only: a prompt has to be able to say "the judgement below" without being
     * told off for it.
     */
    private const JUDGING_RE = '/\b(grade[sd]?|grading|scores?|scored|scoring|judges?|judged|judging'
        . '|evaluat(?:e[sd]?|ing)|rates?|rated|rating|assess(?:es|ed|ing)?)\b/i';

    private const PREFIX = [
        'static-md' => 'sm', 'static-html' => 'sh',
        'dynamic-md' => 'dm', 'dynamic-html' => 'dh',
        'quiz' => 'q', 'form' => 'f', 'bool' => 'b',
        'choice' => 'c', 'score' => 's', 'noul' => 'n',
    ];
    /** The nodes the AI decides with. The student never stops at one of them. */
    private const JUDGE_TYPES = ['choice', 'score', 'noul'];
    /**
     * Suffixes a judgement appends to a question key on its own, and the two
     * names a score node produces for the node as a whole. A question may use
     * none of them, or its own answer would overwrite one of these.
     */
    private const JUDGE_SUFFIXES = ['-confidence', '-points'];
    private const JUDGE_RESERVED = ['total', 'percent'];
    private const OPERATORS   = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'not-contains'];
    private const FIELD_TYPES = ['text-line', 'text-area', 'radio', 'check', 'select'];
    private const CHOICE_TYPES = ['radio', 'check', 'select'];
    private const CONTENT_FIELDS = [
        'static-md'    => [['item'], []],
        'static-html'  => [['item'], []],
        'dynamic-md'   => [['prompt'], ['from']],
        'dynamic-html' => [['prompt'], ['from']],
        'quiz'         => [['items'], []],
        'form'         => [['items'], ['instructions']],
        'bool'         => [['question'], ['yes-label', 'no-label', 'default']],
        'choice'       => [['state', 'items'], []],
        'score'        => [['state', 'items'], []],
        'noul'         => [['state', 'items'], []],
    ];

    private array $errors = [];
    private array $warnings = [];

    private array $langs = [];
    private string $source = '';
    private ?string $start = null;
    private array $sectionNumbers = [];

    /** @var array<string,array> id => node */
    private array $ids = [];
    private array $order = [];
    /** @var array<string,string> storage key => the node that produces it */
    private array $produced = [];
    /** @var array<string,string[]> node id => the prompts it carries */
    private array $prompts = [];
    private array $types = [];
    private array $outgoing = [];
    /** @var array<int,array{0:string,1:string,2:string,3:mixed}> reader, key, where, value */
    private array $reads = [];
    /** @var array<string,string> a dynamic node => the judgement it writes from */
    private array $writesFrom = [];
    /** @var array<string,array<string,int>> score node => question key => how many levels */
    private array $scoreLevels = [];

    public function errors(): array   { return $this->errors; }
    public function warnings(): array { return $this->warnings; }
    public function ok(): bool        { return $this->errors === []; }

    private function error(string $where, string $message): void
    {
        $this->errors[] = "$where: $message";
    }

    private function warn(string $where, string $message): void
    {
        $this->warnings[] = "$where: $message";
    }

    public function validate($course): bool
    {
        if (!is_array($course)) {
            $this->error('course', 'the course must be a JSON object');
            return false;
        }

        $this->checkKeys($course, 'course', ['info', 'nodes', 'edges'], ['$schema']);
        if (array_key_exists('$schema', $course) && !is_string($course['$schema'])) {
            $this->error('$schema', 'must be a string (the address of the schema)');
        }
        $this->validateInfo($course['info'] ?? []);

        $nodes = $course['nodes'] ?? null;
        if (!is_array($nodes) || $nodes === [] || !array_is_list($nodes)) {
            $this->error('nodes', 'must be a non-empty list');
            $nodes = [];
        }
        foreach (array_values($nodes) as $index => $node) {
            $this->validateNode($node, $index);
        }

        $edges = $course['edges'] ?? null;
        if (!is_array($edges) || !array_is_list($edges)) {
            $this->error('edges', 'must be a list');
            $edges = [];
        }
        $this->validateEdges(array_values($edges));

        $this->validateGraph();

        return $this->ok();
    }

    // -- info ------------------------------------------------------------

    private function validateInfo($info): void
    {
        $required = ['course-id', 'source-language', 'other-languages', 'title',
                     'author', 'version', 'date', 'start'];
        $optional = ['description', 'sections', 'system-prompt'];
        if (!$this->checkKeys($info, 'info', $required, $optional)) {
            return;
        }

        $source = $info['source-language'] ?? null;
        if (!is_string($source) || preg_match(self::LANG_RE, $source) !== 1) {
            $this->error('info.source-language', "invalid language code (expected e.g. 'pt', 'en', 'pt-BR')");
            $source = '';
        }
        $this->source = (string) $source;

        $others = $info['other-languages'] ?? [];
        if (!is_array($others)) {
            $this->error('info.other-languages', 'must be a list (use [] when there are no translations)');
            $others = [];
        }
        $seen = [];
        foreach ($others as $lang) {
            if (!is_string($lang) || preg_match(self::LANG_RE, $lang) !== 1) {
                $this->error('info.other-languages', 'invalid language code ' . json_encode($lang));
            } elseif ($lang === $this->source) {
                $this->error('info.other-languages', "must not repeat the source language '$lang'");
            } elseif (in_array($lang, $seen, true)) {
                $this->error('info.other-languages', "'$lang' appears twice");
            } else {
                $seen[] = $lang;
            }
        }
        $this->langs = array_values(array_unique(array_filter(
            array_merge([$this->source], array_filter($others, 'is_string'))
        )));

        $courseId = $info['course-id'] ?? null;
        if (!is_string($courseId) || preg_match(self::UUID_RE, $courseId) !== 1) {
            $this->error('info.course-id', 'must be a UUID');
        }
        if (!is_string($info['author'] ?? null) || trim((string) $info['author']) === '') {
            $this->error('info.author', 'must be a non-empty string');
        }
        if (!is_string($info['version'] ?? null) || preg_match(self::VERSION_RE, (string) $info['version']) !== 1) {
            $this->error('info.version', 'must be MAJOR.MINOR.PATCH, e.g. 1.0.0');
        }
        $date = $info['date'] ?? null;
        if (!is_string($date) || preg_match(self::DATE_RE, $date) !== 1) {
            $this->error('info.date', 'must be YYYY-MM-DD');
        } else {
            [$y, $m, $d] = array_map('intval', explode('-', $date));
            if (!checkdate($m, $d, $y)) {
                $this->error('info.date', "must be a real date, found '$date'");
            }
        }
        $this->checkLocalized($info['title'] ?? null, 'info.title', $this->langs, 'title');
        if (isset($info['description'])) {
            $this->checkLocalized($info['description'], 'info.description', $this->langs, 'description');
        }

        $start = $info['start'] ?? null;
        if (!is_string($start) || preg_match(self::ID_RE, $start) !== 1) {
            $this->error('info.start', 'must be the id of a node, e.g. "sm1"');
        } else {
            $this->start = $start;
        }

        $sections = $info['sections'] ?? [];
        if (!is_array($sections)) {
            $this->error('info.sections', 'must be a list');
            $sections = [];
        }
        foreach ($sections as $index => $section) {
            $where = "info.sections[$index]";
            if (!$this->checkKeys($section, $where, ['number', 'title'], [])) {
                continue;
            }
            $number = $section['number'] ?? null;
            if (!is_int($number) || $number < 1) {
                $this->error("$where.number", 'must be an integer of 1 or more');
            } elseif (in_array($number, $this->sectionNumbers, true)) {
                $this->error("$where.number", "section $number appears twice");
            } else {
                $this->sectionNumbers[] = $number;
            }
            $this->checkLocalized($section['title'] ?? null, "$where.title", $this->langs, 'title');
        }

        if (isset($info['system-prompt'])) {
            if (!is_string($info['system-prompt']) || trim($info['system-prompt']) === '') {
                $this->error('info.system-prompt', 'must be a non-empty string when present');
            } elseif (stripos($info['system-prompt'], 'language') === false) {
                $this->warn('info.system-prompt', 'does not mention the language the AI should answer in');
            }
        }
    }

    // -- nodes -----------------------------------------------------------

    private function validateNode($node, int $index): void
    {
        $where = "nodes[$index]";
        // A node missing a field is still registered by its id, so the edges
        // that name it are not reported as dangling on top of the real error.
        $this->checkKeys($node, $where, ['id', 'type', 'title', 'content'], ['section']);
        if (!is_array($node)) {
            return;
        }

        $id   = $node['id'] ?? null;
        $type = $node['type'] ?? null;

        if (!is_string($id) || preg_match(self::ID_RE, $id) !== 1) {
            $this->error($where, 'invalid id ' . json_encode($id) . " (expected e.g. 'sm1', 'q2')");
            return;
        }
        $where = "node $id";
        if (isset($this->ids[$id])) {
            $this->error($where, 'this id is used by more than one node');
            return;
        }

        if (!is_string($type) || !isset(self::PREFIX[$type])) {
            $this->error($where, 'unknown type ' . json_encode($type));
            return;
        }
        $prefix = self::PREFIX[$type];
        if (preg_match('/^' . $prefix . '[0-9]+$/', $id) !== 1) {
            $this->error($where, "a '$type' node must have an id starting with '$prefix'");
        }

        if (isset($node['section'])) {
            $section = $node['section'];
            if (!is_int($section) || $section < 1) {
                $this->error("$where.section", 'must be an integer of 1 or more');
            } elseif ($this->sectionNumbers !== [] && !in_array($section, $this->sectionNumbers, true)) {
                $this->warn("$where.section", "section $section has no title in info.sections");
            }
        }

        $this->checkLocalized($node['title'] ?? null, "$where.title", $this->langs, 'title');

        $this->ids[$id]   = $node;
        $this->order[]    = $id;
        $this->types[$id] = $type;
        $this->prompts[$id] = [];

        $this->validateContent($node['content'] ?? null, $id, $type);
    }

    private function validateContent($content, string $id, string $type): void
    {
        $where = "node $id.content";
        [$required, $optional] = self::CONTENT_FIELDS[$type];
        if (!$this->checkKeys($content, $where, $required, $optional)) {
            return;
        }

        switch ($type) {
            case 'static-md':
            case 'static-html':
                $this->checkLocalized($content['item'], "$where.item", $this->langs, 'content');
                foreach ($this->textsOf($content['item']) as $text) {
                    $words = count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                    if ($words < 40) {
                        $this->warn("node $id", "content is very short ($words words) for a teaching node");
                        break;
                    }
                }
                break;

            case 'dynamic-md':
            case 'dynamic-html':
                // A prompt is an instruction to the AI, normally written in
                // English whatever the course language is: neither the declared
                // languages nor the translations are required.
                $this->checkLocalized($content['prompt'], "$where.prompt", null, 'prompt', []);
                $texts = $this->textsOf($content['prompt']);
                foreach ($texts as $text) {
                    $this->prompts[$id][] = $text;
                }
                $from = $content['from'] ?? null;
                if ($from !== null && (!is_string($from) || preg_match(self::FROM_RE, $from) !== 1)) {
                    $this->error("$where.from", 'must be the id of a choice, score or noul node');
                    $from = null;
                }
                if (is_string($from) && $from !== '') {
                    $this->writesFrom[$id] = $from;
                    // A node written from a judgement is handed that judgement
                    // rendered in full, so it needs no storage key of its own to
                    // be personalised. What it must not do is form an opinion:
                    // the level is already settled.
                    foreach ($texts as $text) {
                        if (preg_match(self::JUDGING_RE, $text, $hit) === 1) {
                            $this->warn("node $id", "writes from the judgement of $from but its prompt says "
                                . "'{$hit[0]}': the level is already settled, and a feedback that judges again "
                                . 'can contradict the number that routed the student -- tell it how to write, '
                                . 'not what to decide');
                            break;
                        }
                    }
                } else {
                    $personalised = false;
                    foreach ($texts as $text) {
                        if (preg_match(self::STORAGE_RE, $text) === 1) {
                            $personalised = true;
                            break;
                        }
                    }
                    if ($texts !== [] && !$personalised) {
                        $this->warn("node $id", 'a dynamic node whose prompt reads no {{STORAGE: key}} -- nothing '
                            . 'personalises it, consider making it static');
                    }
                }
                $this->produce($id, ['text']);
                break;

            case 'quiz':
                $this->validateQuiz($content, $id, $where);
                break;

            case 'form':
                $this->validateForm($content, $id, $where);
                break;

            case 'bool':
                $this->checkLocalized($content['question'], "$where.question", $this->langs, 'question');
                foreach (['yes-label', 'no-label'] as $label) {
                    if (isset($content[$label])) {
                        $this->checkLocalized($content[$label], "$where.$label", $this->langs, $label);
                    }
                }
                if (isset($content['default']) && !is_bool($content['default'])) {
                    $this->error("$where.default", 'must be true or false');
                }
                $this->produce($id, ['answer']);
                break;

            case 'choice':
            case 'score':
            case 'noul':
                $this->validateJudge($content, $id, $type, $where);
                break;
        }
    }

    /**
     * A node the AI decides with: choice, score or noul.
     *
     * The three differ only in what an answer may be -- one of the options
     * listed, a level of the scale listed, or a probability -- so everything
     * around that is checked here once: a 'state' to judge, and one question per
     * key. Every question produces '<id>.<key>'; choice and score add
     * '-confidence', and a score question with 'points' adds '-points' and gives
     * the node a 'total' and a 'percent'.
     */
    private function validateJudge(array $content, string $id, string $type, string $where): void
    {
        $prompts = [];
        $state   = $content['state'] ?? null;
        if (!is_array($state) || $state === [] || array_is_list($state)) {
            $this->error("$where.state", 'the state the AI judges must be an object of named fields, e.g. '
                . '{"task": "...", "answer": "{{STORAGE: f1.text}}"}');
        } else {
            foreach ($state as $name => $value) {
                $spot = "$where.state.$name";
                if (preg_match(self::NAME_RE, (string) $name) !== 1) {
                    $this->error($spot, 'a field name is lowercase letters, digits and hyphens');
                }
                if (is_string($value)) {
                    $prompts[] = $value;
                } elseif (is_array($value) && array_is_list($value)) {
                    foreach ($value as $j => $entry) {
                        if (!is_string($entry)) {
                            $this->error("$spot" . "[$j]", 'must be a text');
                        } else {
                            $prompts[] = $entry;
                        }
                    }
                } else {
                    $this->error($spot, 'must be a text, or a list of texts');
                }
            }
            $reads = false;
            foreach ($prompts as $text) {
                if (preg_match(self::STORAGE_RE, $text) === 1) {
                    $reads = true;
                    break;
                }
            }
            if (!$reads) {
                $this->warn("node $id", 'the state reads no {{STORAGE: key}}, so the AI judges the same thing '
                    . 'for every student and the node always takes the same edge');
            }
        }
        // Recorded as prompts so they go through the same {{STORAGE: key}}
        // checks: a key nothing produces, or one produced downstream, is the
        // same mistake here as in a dynamic node.
        foreach ($prompts as $text) {
            $this->prompts[$id][] = $text;
        }

        $items = $content['items'] ?? null;
        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            $this->error("$where.items", 'a judgement node needs at least one question');
            return;
        }

        $seen   = [];
        $scored = false;
        foreach (array_values($items) as $index => $item) {
            $spot     = "$where.items[$index]";
            $required = $type === 'noul' ? ['key', 'instructions'] : ['key', 'instructions', 'criteria'];
            $optional = $type === 'noul' ? ['criteria'] : [];
            if ($type === 'score') {
                $optional[] = 'points';
            }
            if (!$this->checkKeys($item, $spot, $required, $optional)) {
                continue;
            }

            $key = $item['key'] ?? null;
            if (!is_string($key) || preg_match(self::NAME_RE, $key) !== 1) {
                $this->error("$spot.key", "must be a name like 'track': lowercase letters, digits and -");
                continue;
            }
            $clash = null;
            foreach (self::JUDGE_SUFFIXES as $suffix) {
                if (str_ends_with($key, $suffix)) {
                    $clash = $suffix;
                    break;
                }
            }
            if ($clash !== null) {
                $this->error("$spot.key", "cannot end in '$clash': the node produces that key on its own");
                continue;
            }
            if (in_array($key, self::JUDGE_RESERVED, true)) {
                $this->error("$spot.key", "'$key' is what a score node produces for the whole node; name the "
                    . 'question after what it judges');
                continue;
            }
            if (isset($seen[$key])) {
                $this->error("$spot.key", "'$key' is used twice in the same node");
                continue;
            }
            $seen[$key] = true;

            if (!is_string($item['instructions'] ?? null) || trim($item['instructions']) === '') {
                $this->error("$spot.instructions", 'the question the AI answers must be a non-empty string');
            } else {
                $this->prompts[$id][] = $item['instructions'];
            }

            $criteria = $item['criteria'] ?? null;
            $this->validateJudgeCriteria($criteria, $type, $spot);

            $produced = [$key];
            if ($type !== 'noul') {
                $produced[] = "$key-confidence";
            }
            if ($type === 'score') {
                if (is_array($criteria) && array_is_list($criteria)) {
                    $this->scoreLevels[$id][$key] = count($criteria);
                }
                if (array_key_exists('points', $item)) {
                    $this->validatePoints($item['points'], $criteria, $spot);
                    $produced[] = "$key-points";
                    $scored = true;
                }
            }
            $this->produce($id, $produced);
        }

        if ($scored) {
            $this->produce($id, self::JUDGE_RESERVED);
        }
    }

    /** The points each level is worth. They line up with the scale, one for one. */
    private function validatePoints($points, $criteria, string $spot): void
    {
        if (!is_array($points) || !array_is_list($points)) {
            $this->error("$spot.points", "must be a list of numbers, one per level of 'criteria'");
            return;
        }
        foreach ($points as $i => $value) {
            if (is_bool($value) || (!is_int($value) && !is_float($value))) {
                $this->error("$spot.points[$i]", 'must be a number');
                return;
            }
            if ($value < 0) {
                $this->error("$spot.points[$i]", "must be 0 or more, found $value");
                return;
            }
        }
        if (is_array($criteria) && array_is_list($criteria) && count($points) !== count($criteria)) {
            $this->error("$spot.points", count($points) . ' point value(s) for ' . count($criteria)
                . ' level(s): there must be exactly one per level, in the same order');
            return;
        }
        for ($i = 1; $i < count($points); $i++) {
            if ($points[$i] < $points[$i - 1]) {
                $this->warn("$spot.points", 'the points do not rise with the levels ('
                    . implode(', ', array_map('strval', $points)) . '): a higher level worth less than a '
                    . 'lower one is almost always a typo');
                return;
            }
        }
    }

    /** What an answer may be: the options, the scale, or what yes and no cover. */
    private function validateJudgeCriteria($criteria, string $type, string $spot): void
    {
        if ($type === 'choice') {
            if (!is_array($criteria) || array_is_list($criteria)) {
                $this->error("$spot.criteria", 'must be a map of the name of each option to what it covers');
                return;
            }
            if (count($criteria) < 2) {
                $this->error("$spot.criteria", 'a choice needs at least two options');
            }
            if (count($criteria) > 255) {
                $this->error("$spot.criteria", 'a choice takes at most 255 options, got ' . count($criteria));
            }
            foreach ($criteria as $name => $what) {
                if (preg_match(self::NAME_RE, (string) $name) !== 1) {
                    $this->error("$spot.criteria", "the option '$name' must be a name the edges can compare "
                        . 'to: lowercase letters, digits and -');
                }
                if ($what !== null && !is_string($what)) {
                    $this->error("$spot.criteria.$name", 'must be a text saying what the option covers, or null');
                }
            }
            return;
        }

        if ($type === 'score') {
            if (!is_array($criteria) || !array_is_list($criteria)) {
                $this->error("$spot.criteria", 'must be the levels of the scale, in order, from the low end '
                    . 'to the high end');
                return;
            }
            if (count($criteria) < 2 || count($criteria) > 10) {
                $this->error("$spot.criteria", 'a scale has between 2 and 10 levels, got ' . count($criteria));
            }
            foreach ($criteria as $index => $level) {
                if (!is_string($level) || trim($level) === '') {
                    $this->error("$spot.criteria[$index]", 'each level must say what it means');
                }
            }
            return;
        }

        // noul: 'criteria' only clears up what a yes and a no cover, and a plain
        // question does not need it.
        if ($criteria === null) {
            return;
        }
        if (!is_array($criteria) || array_is_list($criteria)) {
            $this->error("$spot.criteria", "must say what 'true' and what 'false' cover");
            return;
        }
        foreach ($criteria as $name => $what) {
            if ($name !== 'true' && $name !== 'false') {
                $this->error("$spot.criteria", "'$name' is not a field here: only 'true' and 'false' are");
            } elseif (!is_string($what) || trim($what) === '') {
                $this->error("$spot.criteria.$name", 'must be a non-empty text');
            }
        }
    }

    private function validateQuiz(array $content, string $id, string $where): void
    {
        $items = $content['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $this->error("$where.items", 'a quiz needs at least one question');
            return;
        }

        $this->produce($id, ['score', 'total', 'percent']);
        $seenKeys = [];

        foreach (array_values($items) as $index => $item) {
            $spot = "$where.items[$index]";
            if (!$this->checkKeys($item, $spot, ['question', 'options'], ['key', 'feedback'])) {
                continue;
            }
            $this->checkLocalized($item['question'], "$spot.question", $this->langs, 'question');
            if (isset($item['feedback'])) {
                $this->checkLocalized($item['feedback'], "$spot.feedback", $this->langs, 'feedback');
            }

            if (isset($item['key'])) {
                $key = $item['key'];
                if (!is_string($key) || preg_match(self::NAME_RE, $key) !== 1) {
                    $this->error("$spot.key", 'must be lowercase letters, digits and hyphens');
                } elseif (in_array($key, ['score', 'total', 'percent'], true)) {
                    $this->error("$spot.key", "'$key' is reserved by the quiz itself");
                } elseif (in_array($key, $seenKeys, true)) {
                    $this->error("$spot.key", "'$key' is used by more than one question");
                } else {
                    $seenKeys[] = $key;
                    $this->produce($id, [$key]);
                }
            }

            $options = $item['options'] ?? null;
            if (!is_array($options) || count($options) < 2) {
                $this->error("$spot.options", 'a question needs at least two options');
                continue;
            }
            $correct = 0;
            $values  = [];
            foreach (array_values($options) as $optionIndex => $option) {
                $optionSpot = "$spot.options[$optionIndex]";
                if (!$this->checkKeys($option, $optionSpot, ['value', 'label', 'correct'], [])) {
                    continue;
                }
                if (!is_string($option['value']) || preg_match(self::NAME_RE, $option['value']) !== 1) {
                    $this->error("$optionSpot.value", 'must be lowercase letters, digits and hyphens');
                } elseif (in_array($option['value'], $values, true)) {
                    $this->error("$optionSpot.value", "'{$option['value']}' appears twice in this question");
                } else {
                    $values[] = $option['value'];
                }
                $this->checkLocalized($option['label'], "$optionSpot.label", $this->langs, 'label');
                if (!is_bool($option['correct'])) {
                    $this->error("$optionSpot.correct", 'must be true or false');
                } elseif ($option['correct']) {
                    $correct++;
                }
            }
            if ($correct !== 1) {
                $this->error("$spot.options", "exactly one option must be correct, found $correct");
            }
        }
    }

    private function validateForm(array $content, string $id, string $where): void
    {
        if (array_key_exists('instructions', $content)) {
            $this->checkLocalized($content['instructions'], "$where.instructions", $this->langs, 'instructions');
        }

        $items = $content['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $this->error("$where.items", 'a form needs at least one field');
            return;
        }

        // A writing task is a text-area and the assignment above it. Without
        // the assignment the student is given a box and no question.
        $areas = 0;
        foreach ($items as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'text-area') {
                $areas++;
            }
        }
        if ($areas === 1 && count($items) === 1 && !array_key_exists('instructions', $content)) {
            $this->warn("node $id", "a single text-area and no 'instructions': a writing task needs its "
                . 'assignment -- say what the student is to write');
        }

        $seenKeys = [];
        foreach (array_values($items) as $index => $field) {
            $spot = "$where.items[$index]";
            if (!$this->checkKeys($field, $spot, ['key', 'type', 'label'],
                    ['required', 'options', 'min-words', 'max-words'])) {
                continue;
            }

            $limits = [];
            foreach (['min-words', 'max-words'] as $bound) {
                if (!array_key_exists($bound, $field)) {
                    continue;
                }
                $value = $field[$bound];
                if (is_bool($value) || !is_int($value) || $value < 1) {
                    $this->error("$spot.$bound", 'must be a whole number of 1 or more');
                } elseif (in_array($field['type'] ?? null, self::CHOICE_TYPES, true)) {
                    $this->error("$spot.$bound", "counts words, so it means nothing on a "
                        . (string) $field['type'] . ' field');
                } else {
                    $limits[$bound] = $value;
                }
            }
            if (count($limits) === 2 && $limits['max-words'] < $limits['min-words']) {
                $this->error("$spot.max-words", "'max-words' ({$limits['max-words']}) is below 'min-words' "
                    . "({$limits['min-words']}): no answer can satisfy both");
            }

            $key = $field['key'] ?? null;
            if (!is_string($key) || preg_match(self::NAME_RE, $key) !== 1) {
                $this->error("$spot.key", 'must be lowercase letters, digits and hyphens');
                $key = null;
            } elseif (in_array($key, $seenKeys, true)) {
                $this->error("$spot.key", "'$key' is used by more than one field");
                $key = null;
            }
            if ($key !== null) {
                $seenKeys[] = $key;
                $this->produce($id, [$key]);
            }

            $this->checkLocalized($field['label'], "$spot.label", $this->langs, 'label');
            if (isset($field['required']) && !is_bool($field['required'])) {
                $this->error("$spot.required", 'must be true or false');
            }

            $type = $field['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::FIELD_TYPES, true)) {
                $this->error("$spot.type", 'must be one of ' . implode(', ', self::FIELD_TYPES));
                continue;
            }

            $isChoice = in_array($type, self::CHOICE_TYPES, true);
            $options  = $field['options'] ?? null;
            if ($isChoice) {
                if (!is_array($options) || count($options) < 2) {
                    $this->error("$spot.options", "a '$type' field needs at least two options");
                    continue;
                }
                $values = [];
                foreach (array_values($options) as $optionIndex => $option) {
                    $optionSpot = "$spot.options[$optionIndex]";
                    if (!$this->checkKeys($option, $optionSpot, ['value', 'label'], [])) {
                        continue;
                    }
                    if (!is_string($option['value']) || preg_match(self::NAME_RE, $option['value']) !== 1) {
                        $this->error("$optionSpot.value", 'must be lowercase letters, digits and hyphens');
                    } elseif (in_array($option['value'], $values, true)) {
                        $this->error("$optionSpot.value", "'{$option['value']}' appears twice in this field");
                    } else {
                        $values[] = $option['value'];
                    }
                    $this->checkLocalized($option['label'], "$optionSpot.label", $this->langs, 'label');
                }
            } elseif ($options !== null) {
                $this->error("$spot.options", "a '$type' field takes no options");
            }
        }
    }

    /** Records the storage keys a node produces, as `<node-id>.<name>`. */
    private function produce(string $id, array $names): void
    {
        foreach ($names as $name) {
            $this->produced["$id.$name"] = $id;
        }
    }

    // -- edges and graph --------------------------------------------------

    private function validateEdges(array $edges): void
    {
        foreach (array_keys($this->ids) as $id) {
            $this->outgoing[$id] = [];
        }

        foreach ($edges as $index => $edge) {
            $where = "edges[$index]";
            $this->checkKeys($edge, $where, ['from', 'to'], ['when']);
            if (!is_array($edge)) {
                continue;
            }
            $from = $edge['from'] ?? null;
            $to   = $edge['to'] ?? null;
            $where = 'edge ' . json_encode($from) . ' -> ' . json_encode($to);

            $ok = true;
            if (!is_string($from) || !isset($this->ids[$from])) {
                $this->error($where, "'from' is not a node of this course");
                $ok = false;
            }
            if (!is_string($to) || !isset($this->ids[$to])) {
                $this->error($where, "'to' is not a node of this course");
                $ok = false;
            }
            if (!$ok) {
                continue;
            }

            $this->outgoing[$from][] = $edge;
            if (array_key_exists('when', $edge)) {
                foreach ($this->conditionKeys($edge['when'], "$where.when") as [$key, $value]) {
                    $this->reads[] = [$from, $key, $where, $value];
                }
            }
        }

        // The fallback edge -- the one with no condition -- must come last:
        // the player takes the first edge that holds, so anything after it is
        // unreachable.
        foreach ($this->outgoing as $id => $out) {
            $plain = [];
            foreach ($out as $position => $edge) {
                if (!array_key_exists('when', $edge)) {
                    $plain[] = $position;
                }
            }
            if ($plain === [] && $out !== []) {
                // For a node the AI decides with this is not a risk but a
                // certainty waiting to happen: the call can fail, and then the
                // node produces no key at all. With nothing to match, the player
                // finds no edge, and reads that as the course being over -- the
                // student is shown a finished course at 100%, silently.
                if (in_array($this->types[$id] ?? '', self::JUDGE_TYPES, true)) {
                    $this->error("node $id", 'every edge leaving it is conditional; a judgement the AI could '
                        . 'not make leaves the student with nowhere to go -- add an unconditional edge last');
                } else {
                    $this->warn("node $id", 'every edge leaving it is conditional; a student matching none of '
                        . 'them gets stuck -- add an unconditional edge last');
                }
            }
            if (count($plain) > 1) {
                $this->warn("node $id", count($plain) . ' unconditional edges; only the first can ever be taken');
            }
            if ($plain !== [] && $plain[0] < count($out) - 1) {
                $dead = count($out) - 1 - $plain[0];
                $this->error("node $id", "the unconditional edge is not last: the $dead edge(s) after it "
                    . 'can never be taken');
            }

            // The fallback of a judgement is the path taken when there is no
            // judgement. Sending it to a node that writes from that judgement
            // asks that node to write feedback out of nothing.
            if (in_array($this->types[$id] ?? '', self::JUDGE_TYPES, true)) {
                foreach ($plain as $position) {
                    $target = (string) ($out[$position]['to'] ?? '');
                    if (($this->writesFrom[$target] ?? null) === $id) {
                        $this->error("node $id", "its unconditional edge leads to $target, which writes from "
                            . 'this very judgement -- that edge is the path taken when no judgement was made, '
                            . "so $target would have nothing to write from; send the fallback elsewhere");
                    }
                }
            }
        }
    }

    /**
     * Checks a condition tree and returns what it reads: one [key, value]
     * pair per comparison. The value comes back because a level compared
     * against a percentage is a mistake only the value can reveal.
     */
    private function conditionKeys($when, string $where, int $depth = 0): array
    {
        if ($depth > 8) {
            $this->error($where, 'condition nested too deeply');
            return [];
        }
        if (!is_array($when)) {
            $this->error($where, "'when' must be an object");
            return [];
        }

        foreach (['and', 'or'] as $joiner) {
            if (array_key_exists($joiner, $when)) {
                $extra = array_diff(array_keys($when), [$joiner]);
                if ($extra !== []) {
                    $this->error($where, "an '$joiner' condition may contain only '$joiner'");
                }
                $group = $when[$joiner];
                if (!is_array($group) || count($group) < 2) {
                    $this->error("$where.$joiner", 'must be a list of at least two conditions');
                    return [];
                }
                $keys = [];
                foreach (array_values($group) as $index => $part) {
                    $keys = array_merge($keys, $this->conditionKeys($part, "$where.{$joiner}[$index]", $depth + 1));
                }
                return $keys;
            }
        }

        $extra = array_diff(array_keys($when), ['key', 'operator', 'value']);
        if ($extra !== []) {
            $this->error($where, 'unknown field(s) ' . implode(', ', $extra) . ' in condition');
        }
        foreach (['key', 'operator', 'value'] as $field) {
            if (!array_key_exists($field, $when)) {
                $this->error($where, "condition is missing '$field'");
                return [];
            }
        }

        $key = $when['key'];
        if (!is_string($key) || preg_match(self::KEY_RE, $key) !== 1) {
            $this->error($where, 'invalid key ' . json_encode($key) . " (expected '<node-id>.<name>')");
            return [];
        }
        if (!in_array($when['operator'], self::OPERATORS, true)) {
            $this->error($where, 'invalid operator ' . json_encode($when['operator']));
        }
        if (!is_string($when['value']) && !is_int($when['value'])
            && !is_float($when['value']) && !is_bool($when['value'])) {
            $this->error($where, "'value' must be a string, a number or a boolean");
        }
        return [[$key, $when['value']]];
    }

    private function validateGraph(): void
    {
        // Without a valid start node there is no reachability to compute; the
        // checks on keys and prompts below still run, as validate_course.py does.
        $reachable = [];
        if ($this->start !== null && !isset($this->ids[$this->start])) {
            $this->error('info.start', "start node '{$this->start}' does not exist");
        } elseif ($this->start !== null) {
            $reachable = $this->reachableFrom($this->start);
            $stranded  = array_values(array_diff($this->order, array_keys($reachable)));
            if ($stranded !== []) {
                $this->error('graph', 'no path from the start node reaches: ' . implode(', ', $stranded)
                    . ' (usually an edge missing into them)');
            }
        }

        // Which nodes can reach which: used to tell whether the node that
        // produces a key comes before the node that reads it.
        $descendants = [];
        foreach (array_keys($this->ids) as $id) {
            $descendants[$id] = $this->reachableFrom($id, false);
        }

        $terminals = [];
        foreach ($this->order as $id) {
            if (isset($reachable[$id]) && ($this->outgoing[$id] ?? []) === []) {
                $terminals[] = $id;
            }
        }
        if (count($terminals) > 1) {
            $this->warn('graph', 'several nodes end the course: ' . implode(', ', $terminals)
                . ' -- branches should reunite unless each of these is a real ending');
        }

        $upstream = static function (string $producer, string $reader) use ($descendants): bool {
            return $producer === $reader || isset($descendants[$producer][$reader]);
        };

        // Keys read by edge conditions.
        foreach ($this->reads as [$reader, $key, $where, $value]) {
            if (!isset($this->produced[$key])) {
                $this->error($where, "the condition reads '$key', which no node produces");
                continue;
            }
            if (!$upstream($this->produced[$key], $reader)) {
                $this->warn($where, "'$key' is produced by {$this->produced[$key]}, which is not on any path "
                    . "to $reader; this condition can never hold");
            }

            // A level runs over the levels of its own question, not 0-100.
            // Comparing it against a percentage is a habit the essay grade left
            // behind, and the edge simply never fires.
            [$owner, $name] = array_pad(explode('.', $key, 2), 2, '');
            $levels = $this->scoreLevels[$owner][$name] ?? null;
            if ($levels !== null && !is_bool($value) && (is_int($value) || is_float($value))
                && $value > $levels - 1) {
                $this->warn($where, "'$key' runs from 0 to " . ($levels - 1) . ', over the levels of its own '
                    . "question, so comparing it against $value never holds. For a grade out of 100 give the "
                    . "question 'points' and test $owner.percent");
            }
        }

        // A judgement anchored on a mark already given is no longer an
        // independent judgement: the AI reads the earlier verdict and agrees
        // with it instead of looking at the work.
        $graded = [];
        foreach ($this->produced as $key => $owner) {
            if (in_array($this->types[$owner] ?? '', self::JUDGE_TYPES, true)
                || (($this->types[$owner] ?? '') === 'quiz' && str_ends_with($key, '.percent'))) {
                $graded[$key] = true;
            }
        }
        foreach ($this->order as $id) {
            if (!in_array($this->types[$id] ?? '', self::JUDGE_TYPES, true)) {
                continue;
            }
            $state = $this->ids[$id]['content']['state'] ?? null;
            if (!is_array($state)) {
                continue;
            }
            foreach ($state as $name => $value) {
                $text = is_array($value) ? implode(' ', array_filter($value, 'is_string')) : (string) $value;
                preg_match_all(self::STORAGE_RE, $text, $matches);
                foreach ($matches[1] as $raw) {
                    $key = trim($raw);
                    if (isset($graded[$key]) && ($this->produced[$key] ?? null) !== $id) {
                        $this->warn("node $id", "the state field '$name' reads '$key', a mark already given: "
                            . 'the AI would anchor on it instead of judging for itself -- give the judgement '
                            . 'the work and the task, not an earlier verdict');
                    }
                }
            }
        }

        // A node written from a judgement is only ever reached through it.
        foreach ($this->writesFrom as $id => $origin) {
            if (!isset($this->ids[$origin])) {
                $this->error("node $id", "writes from '$origin', which is not a node");
                continue;
            }
            if (!in_array($this->types[$origin] ?? '', self::JUDGE_TYPES, true)) {
                $this->error("node $id", "writes from '$origin', which is not a choice, score or noul node");
                continue;
            }
            if (isset($reachable[$id]) && $this->reachesWithout($id, $origin)) {
                $this->error("node $id", "writes from the judgement of $origin, but there is a path to it that "
                    . "never passes through $origin: a student taking that path would reach a node with no "
                    . 'judgement to write from');
            }
        }

        // Keys read by prompts, through {{STORAGE: key}}.
        $usedKeys = [];
        foreach ($this->reads as [, $key, , ]) {
            $usedKeys[$key] = true;
        }
        foreach ($this->prompts as $id => $prompts) {
            foreach ($prompts as $prompt) {
                preg_match_all(self::STORAGE_RE, (string) $prompt, $matches);
                foreach ($matches[1] as $raw) {
                    $key = trim($raw);
                    $usedKeys[$key] = true;
                    $where = "node $id prompt";
                    if (preg_match(self::KEY_RE, $key) !== 1) {
                        $this->error($where, "{{STORAGE: $key}} is not a valid key ('<node-id>.<name>')");
                    } elseif (!isset($this->produced[$key])) {
                        $this->error($where, "{{STORAGE: $key}} reads a key no node produces");
                    } elseif (!$upstream($this->produced[$key], $id)) {
                        $this->warn($where, "{{STORAGE: $key}} is produced by {$this->produced[$key]}, which the "
                            . "student may not have reached before $id; the value will be empty");
                    }
                }
            }
        }

        // Nodes whose results nothing ever reads: an activity answered for
        // nothing, or a judgement paid for and thrown away.
        foreach ($this->order as $id) {
            $stores = array_merge(['quiz', 'form', 'bool'], self::JUDGE_TYPES);
            if (!in_array($this->types[$id] ?? '', $stores, true)) {
                continue;
            }
            $own = array_keys(array_filter($this->produced, static fn($owner) => $owner === $id));
            if ($own === []) {
                continue;
            }
            $anyUsed = false;
            foreach ($own as $key) {
                if (isset($usedKeys[$key])) {
                    $anyUsed = true;
                    break;
                }
            }
            if (!$anyUsed) {
                $this->warn("node $id", 'stores data that no edge and no prompt ever reads -- branch on it '
                    . 'or use it in a prompt');
            }
        }
    }

    /** @return array<string,true> the nodes reachable by following edges. */
    private function reachableFrom(string $from, bool $includeSelf = true): array
    {
        $seen  = $includeSelf ? [$from => true] : [];
        $queue = [$from];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($this->outgoing[$id] ?? [] as $edge) {
                $to = (string) ($edge['to'] ?? '');
                if ($to !== '' && !isset($seen[$to])) {
                    $seen[$to] = true;
                    $queue[] = $to;
                }
            }
        }
        return $seen;
    }

    /**
     * Is there a way from the start to $target that never passes through
     * $avoid? For a node that writes from a judgement, a yes means a student
     * can arrive at it with nothing to write from.
     */
    private function reachesWithout(string $target, string $avoid): bool
    {
        if ($this->start === null || !isset($this->ids[$this->start])) {
            return false;
        }
        $queue = [$this->start];
        $seen  = [$this->start => true];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($this->outgoing[$id] ?? [] as $edge) {
                $to = (string) ($edge['to'] ?? '');
                if ($to === '' || $to === $avoid || isset($seen[$to])) {
                    continue;
                }
                if ($to === $target) {
                    return true;
                }
                $seen[$to] = true;
                $queue[] = $to;
            }
        }
        return false;
    }

    // -- shared checks ----------------------------------------------------

    /** Required fields present, and nothing the format does not allow. */
    private function checkKeys($object, string $where, array $required, array $optional): bool
    {
        // json_decode gives [] for {} as well as for [], so an empty array is
        // taken as an object: its missing fields are then reported by name.
        if (!is_array($object) || ($object !== [] && array_is_list($object))) {
            $this->error($where, 'expected an object');
            return false;
        }
        $ok = true;
        foreach ($required as $key) {
            if (!array_key_exists($key, $object)) {
                $this->error($where, "missing required field '$key'");
                $ok = false;
            }
        }
        $allowed = array_merge($required, $optional);
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->error($where, "unknown field '$key' (the format allows no extra fields)");
            }
        }
        return $ok;
    }

    /**
     * A localized text list: [{lang, text}, ...].
     * $required is the list of languages that must be present -- null for the
     * prompts of dynamic nodes, which normally exist in one language only.
     */
    private function checkLocalized(
        $value,
        string $where,
        ?array $required,
        string $label,
        ?array $declared = null
    ): void {
        // $declared defaults to the languages of the course; a dynamic prompt
        // passes [] because it is an instruction to the AI, normally written in
        // English whatever language the course is in.
        $declared ??= $this->langs;
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            $this->error($where, "$label must be a non-empty list of {lang, text} objects");
            return;
        }
        $seen = [];
        foreach ($value as $index => $entry) {
            $spot = "$where"."[$index]";
            if (!is_array($entry) || array_is_list($entry)) {
                $this->error($spot, "each entry must be an object with 'lang' and 'text'");
                continue;
            }
            $extra = array_diff(array_keys($entry), ['lang', 'text']);
            if ($extra !== []) {
                $this->error($spot, 'unknown field(s) ' . implode(', ', $extra));
            }
            $lang = $entry['lang'] ?? null;
            if (!is_string($lang) || preg_match(self::LANG_RE, $lang) !== 1) {
                $this->error($spot, 'invalid language code ' . json_encode($lang));
            } elseif (in_array($lang, $seen, true)) {
                $this->error($spot, "language '$lang' appears twice");
            } else {
                $seen[] = $lang;
                if ($declared !== [] && !in_array($lang, $declared, true)) {
                    $this->warn($spot, "language '$lang' is not declared in info");
                }
            }
            $text = $entry['text'] ?? null;
            if (!is_string($text)) {
                $this->error($spot, "'text' must be a string");
            } elseif (trim($text) === '') {
                $this->warn($spot, "empty $label");
            }
        }
        foreach (array_values($required ?? []) as $index => $lang) {
            if (in_array($lang, $seen, true)) {
                continue;
            }
            if ($index === 0) {
                $this->error($where, "missing the source language '$lang'");
            } else {
                $this->warn($where, "missing the translation into '$lang'");
            }
        }
    }

    /** The texts of a localized list, or the string itself. */
    private function textsOf($value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $texts = [];
        foreach ($value as $entry) {
            if (is_array($entry) && isset($entry['text']) && is_string($entry['text'])) {
                $texts[] = $entry['text'];
            }
        }
        return $texts;
    }
}
