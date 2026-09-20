<?php
/**
 * A course, read from the JSON stored in the database.
 *
 * The comparisons in `holds()` are a deliberate, literal port of `Course.holds`
 * in assets/course_player.html. The player decides the student's path in the
 * browser; the server has to reach the same answer from the same data, or the
 * two would disagree about where a student is. Anything changed here must be
 * changed there too.
 */

declare(strict_types=1);

final class Course
{
    /**
     * The node types the AI decides with, rather than the student. They are the
     * only ones a student never stops at, and the only ones whose storage keys
     * this server writes on its own authority.
     */
    public const JUDGE_TYPES = ['choice', 'score', 'noul'];

    private array $doc;
    private array $info;
    /** @var array<string,array> id => node */
    private array $nodes = [];
    /** @var array<string,array[]> from => edges, in file order */
    private array $edges = [];

    public function __construct(array $doc)
    {
        $this->doc  = $doc;
        $this->info = $doc['info'] ?? [];

        foreach ($doc['nodes'] ?? [] as $node) {
            if (isset($node['id'])) {
                $this->nodes[(string) $node['id']] = $node;
            }
        }
        foreach ($doc['edges'] ?? [] as $edge) {
            if (isset($edge['from'])) {
                $this->edges[(string) $edge['from']][] = $edge;
            }
        }
    }

    public static function fromJson(string $json): self
    {
        $doc = json_decode($json, true);
        if (!is_array($doc)) {
            throw new RuntimeException('the stored course is not valid JSON');
        }
        return new self($doc);
    }

    public function doc(): array           { return $this->doc; }
    public function info(): array          { return $this->info; }
    public function id(): string           { return (string) ($this->info['course-id'] ?? ''); }
    public function version(): string      { return (string) ($this->info['version'] ?? ''); }
    public function author(): string       { return (string) ($this->info['author'] ?? ''); }
    public function systemPrompt(): string { return (string) ($this->info['system-prompt'] ?? ''); }
    public function sourceLanguage(): string { return (string) ($this->info['source-language'] ?? 'en'); }

    /** The localStorage namespace the player uses, `<course-id>@<version>`. */
    public function scope(): string
    {
        return ($this->id() ?: 'course') . '@' . ($this->version() ?: '0');
    }

    /** Source language first, then the translations, without repetition. */
    public function languages(): array
    {
        $all = array_merge([$this->sourceLanguage()], $this->info['other-languages'] ?? []);
        return array_values(array_unique(array_filter($all)));
    }

    public function title(?string $lang = null): string
    {
        return self::localize($this->info['title'] ?? [], $lang ?? $this->sourceLanguage());
    }

    /**
     * The title as the `course.title` column holds it: the source language,
     * cut to the width of the column.
     *
     * It exists so that a stored title can be compared with the one in the
     * file it came from and the two are the same thing on both sides -- which
     * is how the server knows whether a course was renamed by hand, without
     * writing that down anywhere.
     */
    public static function storedTitle(string $doc): string
    {
        return mb_substr(self::fromJson($doc)->title(), 0, 255);
    }

    public function description(?string $lang = null): string
    {
        return self::localize($this->info['description'] ?? [], $lang ?? $this->sourceLanguage());
    }

    /** @return array<string,array> */
    public function nodes(): array { return $this->nodes; }

    public function hasNode(string $id): bool { return isset($this->nodes[$id]); }

    public function node(string $id): ?array { return $this->nodes[$id] ?? null; }

    public function nodeType(string $id): ?string
    {
        $node = $this->node($id);
        return $node === null ? null : (string) ($node['type'] ?? '');
    }

    /** Is this one of the nodes the AI decides? */
    public function isJudge(string $id): bool
    {
        return in_array((string) $this->nodeType($id), self::JUDGE_TYPES, true);
    }

    /** The ids of every node the AI decides, in no particular order. */
    public function judgeIds(): array
    {
        $ids = [];
        foreach ($this->nodes as $id => $node) {
            if (in_array((string) ($node['type'] ?? ''), self::JUDGE_TYPES, true)) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    /** Every edge leaving a node, in the order they appear in the file. */
    public function edgesFrom(string $id): array { return $this->edges[$id] ?? []; }

    /** `info.start`, or the first node nothing points at, as the player does. */
    public function firstNodeId(): ?string
    {
        $start = (string) ($this->info['start'] ?? '');
        if ($start !== '' && isset($this->nodes[$start])) {
            return $start;
        }
        $targets = [];
        foreach ($this->doc['edges'] ?? [] as $edge) {
            $targets[(string) ($edge['to'] ?? '')] = true;
        }
        foreach (array_keys($this->nodes) as $id) {
            if (!isset($targets[$id])) {
                return $id;
            }
        }
        return array_key_first($this->nodes);
    }

    /**
     * The next node: the first edge whose condition holds. Null means the
     * course ends here -- which is a normal ending, not an error.
     */
    public function nextNodeId(string $from, array $vars): ?string
    {
        foreach ($this->edgesFrom($from) as $edge) {
            if (self::holds($edge['when'] ?? null, $vars)) {
                return isset($edge['to']) ? (string) $edge['to'] : null;
            }
        }
        return null;
    }

    // -- conditions ------------------------------------------------------
    // Ported from Course.holds / #a / #o / #y / #n of the player.

    public static function holds($when, array $vars): bool
    {
        if ($when === null || $when === [] || !is_array($when)) {
            return true;                                  // no condition: always
        }
        if (isset($when['and']) && is_array($when['and'])) {
            foreach ($when['and'] as $part) {
                if (!self::holds($part, $vars)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($when['or']) && is_array($when['or'])) {
            foreach ($when['or'] as $part) {
                if (self::holds($part, $vars)) {
                    return true;
                }
            }
            return false;
        }

        $operator = (string) ($when['operator'] ?? '');
        $key      = (string) ($when['key'] ?? '');
        $left     = $vars[$key] ?? null;

        // A key the student has not produced yet never satisfies a condition.
        if ($left === null) {
            return false;
        }
        $right = $when['value'] ?? null;

        switch ($operator) {
            case 'eq':           return self::same($left, $right);
            case 'ne':           return !self::same($left, $right);
            case 'gt':           return self::num($left) >  self::num($right);
            case 'gte':          return self::num($left) >= self::num($right);
            case 'lt':           return self::num($left) <  self::num($right);
            case 'lte':          return self::num($left) <= self::num($right);
            case 'contains':     return self::contains($left, $right);
            case 'not-contains': return !self::contains($left, $right);
            default:             return false;            // unknown operator
        }
    }

    /** `eq` of the player: booleans as booleans, numbers as numbers, else text. */
    private static function same($a, $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return self::truthy($a) === self::truthy($b);
        }
        if (self::numeric($a) && self::numeric($b)) {
            return self::num($a) === self::num($b);
        }
        return self::text($a) === self::text($b);
    }

    /** A list contains a value; a text contains it ignoring case. */
    private static function contains($haystack, $needle): bool
    {
        if (is_array($haystack)) {
            foreach ($haystack as $item) {
                if (self::same($item, $needle)) {
                    return true;
                }
            }
            return false;
        }
        return str_contains(
            mb_strtolower(self::text($haystack)),
            mb_strtolower(self::text($needle))
        );
    }

    /** JavaScript `Number(x)`, as a float. NAN when it is not a number. */
    public static function num($value): float
    {
        if (is_bool($value))            { return $value ? 1.0 : 0.0; }
        if ($value === null)            { return 0.0; }
        if (is_int($value) || is_float($value)) { return (float) $value; }
        if (is_array($value)) {
            // Number([]) is 0, Number([n]) is n, anything longer is NaN.
            if (count($value) === 0) { return 0.0; }
            if (count($value) === 1) { return self::num(reset($value)); }
            return NAN;
        }
        $text = trim((string) $value);
        if ($text === '')          { return 0.0; }
        if (!is_numeric($text))    { return NAN; }
        return (float) $text;
    }

    /** JavaScript `Boolean(x)`. Note that the string "0" is true in JS. */
    public static function truthy($value): bool
    {
        if (is_bool($value))   { return $value; }
        if ($value === null)   { return false; }
        if (is_string($value)) { return $value !== ''; }
        if (is_int($value) || is_float($value)) {
            return !is_nan((float) $value) && (float) $value !== 0.0;
        }
        return true;                                   // arrays and objects
    }

    /** JavaScript `String(x)`. */
    public static function text($value): string
    {
        if (is_bool($value))   { return $value ? 'true' : 'false'; }
        if ($value === null)   { return 'null'; }
        if (is_array($value))  { return implode(',', array_map([self::class, 'text'], $value)); }
        if (is_float($value) && $value === floor($value) && is_finite($value)) {
            return (string) (int) $value;              // 70.0 prints as "70"
        }
        return (string) $value;
    }

    /** The player's numeric-ish test: not empty, not null, not a list, not NaN. */
    private static function numeric($value): bool
    {
        if ($value === '' || $value === null || is_array($value)) {
            return false;
        }
        return !is_nan(self::num($value));
    }

    // -- text ------------------------------------------------------------

    /**
     * One entry of a localized list: the exact language, then the same base
     * language, then the first entry. Same order as `Texts.localize`.
     */
    public static function localize($list, string $lang): string
    {
        if (is_string($list)) {
            return $list;
        }
        if (!is_array($list) || $list === []) {
            return '';
        }
        $base = explode('-', $lang)[0];
        foreach ($list as $entry) {
            if (is_array($entry) && ($entry['lang'] ?? null) === $lang) {
                return (string) ($entry['text'] ?? '');
            }
        }
        foreach ($list as $entry) {
            if (is_array($entry) && explode('-', (string) ($entry['lang'] ?? ''))[0] === $base) {
                return (string) ($entry['text'] ?? '');
            }
        }
        $first = reset($list);
        return is_array($first) ? (string) ($first['text'] ?? '') : '';
    }

    /**
     * Fills in every {{STORAGE: key}} of a prompt from the values the student
     * has produced. A key with no value becomes an empty text, and a list of
     * answers becomes its values separated by ", " -- the rule the schema sets.
     */
    public static function resolveStorage(string $prompt, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*STORAGE:\s*([^}]+?)\s*\}\}/',
            static function (array $match) use ($vars): string {
                $value = $vars[trim($match[1])] ?? null;
                if ($value === null)  { return ''; }
                if (is_array($value)) { return implode(', ', array_map([self::class, 'text'], $value)); }
                return self::text($value);
            },
            $prompt
        ) ?? $prompt;
    }

    /**
     * The course as the browser may see it: nothing a student could read that
     * the course does not mean to show them.
     *
     * Two kinds of thing are taken out. A prompt is replaced by a marker naming
     * its node: the player sends the marker where it would have sent a prompt,
     * and public/api/ai.php builds the real one here, on the server, from the
     * course in the database. A judge node loses more than that -- its state,
     * and every question's instructions, criteria and points -- because the
     * rubric a teacher wrote and the marks they hung on it are the answer key
     * of the step, and a `choice` node's criteria are literally the list of
     * decisions the course can make about a student.
     *
     * Taking all of it out is only possible because api/ai.php answers a
     * judgement with the storage keys already derived, so the browser has
     * nothing left to work them out from. It asks "judge c1" and is told what
     * came of it.
     */
    public function withoutPrompts(): array
    {
        $doc = $this->doc;
        unset($doc['info']['system-prompt']);
        $language = $this->sourceLanguage();

        foreach ($doc['nodes'] ?? [] as $index => $node) {
            $type = (string) ($node['type'] ?? '');
            $id   = (string) ($node['id'] ?? '');

            if ($type === 'dynamic-md' || $type === 'dynamic-html') {
                $doc['nodes'][$index]['content']['prompt'] = [
                    ['lang' => $language, 'text' => self::marker($id)],
                ];
                continue;
            }

            if (in_array($type, ['choice', 'score', 'noul'], true)) {
                unset(
                    $doc['nodes'][$index]['content']['state'],
                    $doc['nodes'][$index]['content']['confidence']
                );
                foreach ($node['content']['items'] ?? [] as $slot => $item) {
                    // Only the key survives, because the key is the one part of
                    // a question the browser has any use for: it is half the
                    // name of the storage keys the answer comes back under.
                    $doc['nodes'][$index]['content']['items'][$slot]
                        = ['key' => (string) ($item['key'] ?? '')];
                }
            }
        }

        return $doc;
    }

    /** The text that stands in for a prompt in the browser. */
    public static function marker(string $nodeId): string
    {
        return '#edukors:' . $nodeId;
    }

    /**
     * How far along the student is, as the player computes it: the nodes already
     * visited against those plus the shortest way still to go. It is an
     * estimate, recomputed at every step, never an exact count.
     */
    public function percent(array $history, ?string $currentId): int
    {
        if ($currentId === null || $currentId === '') {
            return 100;
        }
        $visited   = count(array_unique($history));
        $total     = $visited + $this->distanceToEnd($currentId);
        $percent   = $total > 0 ? (int) round($visited / $total * 100) : 0;
        return max(0, min(99, $percent));
    }

    /** Breadth-first search to the nearest node with no way out. */
    private function distanceToEnd(string $from): int
    {
        $queue = [[$from, 1]];
        $seen  = [$from => true];
        while ($queue !== []) {
            [$id, $depth] = array_shift($queue);
            $out = $this->edgesFrom($id);
            if ($out === []) {
                return $depth;
            }
            foreach ($out as $edge) {
                $to = (string) ($edge['to'] ?? '');
                if ($to !== '' && !isset($seen[$to])) {
                    $seen[$to] = true;
                    $queue[] = [$to, $depth + 1];
                }
            }
        }
        return 1;
    }
}
