#!/usr/bin/env php
<?php
/**
 * Imports a course JSON into the database.
 *
 *     php tools/import.php course.json
 *     php tools/import.php course.json --publish
 *
 * Errors stop the import and are printed; warnings are kept with the course and
 * shown in the admin. Re-importing the same version replaces it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/import.php';

$args    = array_slice($argv, 1);
$publish = in_array('--publish', $args, true);
$files   = array_values(array_filter($args, static fn($a) => !str_starts_with($a, '--')));

if ($files === []) {
    fwrite(STDERR, "usage: php tools/import.php <course.json> [--publish]\n");
    exit(2);
}

$failed = 0;
foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "ERROR   file not found: $file\n");
        $failed++;
        continue;
    }

    try {
        $result = edukors_import((string) file_get_contents($file), $publish);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR   $file: {$e->getMessage()}\n");
        $failed++;
        continue;
    }

    foreach ($result['errors'] as $line) {
        fwrite(STDERR, "ERROR   $line\n");
    }
    foreach ($result['warnings'] as $line) {
        fwrite(STDOUT, "WARNING $line\n");
    }

    if (!$result['ok']) {
        fwrite(STDERR, "Not imported: $file (" . count($result['errors']) . " error(s))\n");
        $failed++;
        continue;
    }

    $what   = $result['replaced'] ? 'Replaced' : 'Imported';
    $status = $publish ? 'published' : 'draft';
    fwrite(STDOUT, "$what {$result['title']} — {$result['uuid']} v{$result['version']} ($status)\n");
}

exit($failed > 0 ? 1 : 0);
