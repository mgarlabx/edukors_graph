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
    private const ID_RE      = '/^(sm|sh|dm|dh|e|q|f|b)[0-9]+$/';
    private const NAME_RE    = '/^[a-z][a-z0-9-]*$/';
    private const VERSION_RE = '/^[0-9]+\.[0-9]+\.[0-9]+$/';
    private const DATE_RE    = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';
    private const UUID_RE    = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    private const KEY_RE     = '/^(e|q|f|b)[0-9]+\.[a-z][a-z0-9-]*$/';
    private const STORAGE_RE = '/\{\{\s*STORAGE:\s*([^}]+?)\s*\}\}/';

    private const PREFIX = [
        'static-md' => 'sm', 'static-html' => 'sh',
        'dynamic-md' => 'dm', 'dynamic-html' => 'dh',
        'essay' => 'e', 'quiz' => 'q', 'form' => 'f', 'bool' => 'b',
    ];
    private const OPERATORS   = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'not-contains'];
    private const FIELD_TYPES = ['text-line', 'text-area', 'radio', 'check', 'select'];
    private const CHOICE_TYPES = ['radio', 'check', 'select'];
    private const CONTENT_FIELDS = [
        'static-md'    => [['item'], []],
        'static-html'  => [['item'], []],
        'dynamic-md'   => [['prompt'], []],
        'dynamic-html' => [['prompt'], []],
        'essay'        => [['instructions', 'prompt'], []],
        'quiz'         => [['items'], []],
        'form'         => [['items'], []],
        'bool'         => [['question'], ['yes-label', 'no-label', 'default']],
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
    /** @var array<int,array{0:string,1:string,2:string}> reader, key, where */
    private array $reads = [];

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
        if (!$this->checkKeys($info, 'info', $required, ['description', 'sections', 'system-prompt'])) {
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
                break;

            case 'essay':
                $this->checkLocalized($content['instructions'], "$where.instructions", $this->langs, 'instructions');
                if (!is_string($content['prompt']) || trim($content['prompt']) === '') {
                    $this->error("$where.prompt", 'the grading prompt must be a non-empty string');
                } else {
                    $this->prompts[$id][] = $content['prompt'];
                    if (stripos($content['prompt'], 'score') === false) {
                        $this->warn("node $id", "the grading prompt never mentions 'score'; it must yield a 0-100 grade");
                    }
                    if (stripos($content['prompt'], 'feedback') === false) {
                        $this->warn("node $id", "the grading prompt never mentions 'feedback'");
                    }
                }
                $this->produce($id, ['text', 'score', 'feedback']);
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
        $items = $content['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $this->error("$where.items", 'a form needs at least one field');
            return;
        }

        $seenKeys = [];
        foreach (array_values($items) as $index => $field) {
            $spot = "$where.items[$index]";
            if (!$this->checkKeys($field, $spot, ['key', 'type', 'label'], ['required', 'options'])) {
                continue;
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
                foreach ($this->conditionKeys($edge['when'], "$where.when") as $key) {
                    $this->reads[] = [$from, $key, $where];
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
                $this->warn("node $id", 'every edge leaving it is conditional; a student matching none of '
                    . 'them gets stuck -- add an unconditional edge last');
            }
            if (count($plain) > 1) {
                $this->warn("node $id", count($plain) . ' unconditional edges; only the first can ever be taken');
            }
            if ($plain !== [] && $plain[0] < count($out) - 1) {
                $dead = count($out) - 1 - $plain[0];
                $this->error("node $id", "the unconditional edge is not last: the $dead edge(s) after it "
                    . 'can never be taken');
            }
        }
    }

    /** Checks a condition tree and returns the storage keys it reads. */
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
        return [$key];
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
        foreach ($this->reads as [$reader, $key, $where]) {
            if (!isset($this->produced[$key])) {
                $this->error($where, "the condition reads '$key', which no node produces");
            } elseif (!$upstream($this->produced[$key], $reader)) {
                $this->warn($where, "'$key' is produced by {$this->produced[$key]}, which is not on any path "
                    . "to $reader; this condition can never hold");
            }
        }

        // Keys read by prompts, through {{STORAGE: key}}.
        $usedKeys = [];
        foreach ($this->reads as [, $key, ]) {
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

        // Activity nodes whose results nothing ever reads.
        foreach ($this->order as $id) {
            if (!in_array($this->types[$id] ?? '', ['quiz', 'essay', 'form', 'bool'], true)) {
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
