<?php
/**
 * Reads the configuration file and lets the environment override any value.
 *
 * The file is looked for in the places listed in edukors_config_file(). If none
 * of them exists the defaults below are used, so a deployment that keeps
 * everything in environment variables needs no file at all.
 */

declare(strict_types=1);

function edukors_config(?string $path = null): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'db'       => ['dsn' => '', 'user' => '', 'pass' => ''],
        'ai'       => [
            'key' => '', 'model' => '', 'url' => 'https://openrouter.ai/api/v1/chat/completions',
            'max_tokens' => 1200, 'temperature' => 0.7,
            'timeout' => 45, 'per_hour' => 40, 'per_day' => 5000,
            'referer' => '', 'title' => 'Edukors Graph Player',
        ],
        'judge'    => [
            'models' => [], 'timeout' => 45, 'strict_model' => true,
            'url' => 'https://openrouter.ai/api/alpha/decisions',
        ],
        // The catalogue's player: whether its AI steps run, and the share of the
        // account an anonymous visitor may spend -- see src/visitor.php.
        'catalog'  => ['ai' => true, 'per_hour' => 40, 'per_day' => 1000],
        'admin'    => ['user' => 'admin', 'hash' => ''],
        'base_url' => '',
        'dev_mode' => false,
    ];

    $file = $path ?? edukors_config_file();
    $fromFile = $file !== null ? require $file : [];
    if (!is_array($fromFile)) {
        throw new RuntimeException("config file did not return an array: $file");
    }

    $config = [
        'db'       => array_merge($defaults['db'], $fromFile['db'] ?? []),
        'ai'       => array_merge($defaults['ai'], $fromFile['ai'] ?? []),
        'judge'    => array_merge($defaults['judge'], $fromFile['judge'] ?? []),
        'catalog'  => array_merge($defaults['catalog'], $fromFile['catalog'] ?? []),
        'admin'    => array_merge($defaults['admin'], $fromFile['admin'] ?? []),
        'base_url' => $fromFile['base_url'] ?? $defaults['base_url'],
        'dev_mode' => $fromFile['dev_mode'] ?? $defaults['dev_mode'],
    ];

    // The environment wins, always: it is the only way to keep secrets out of
    // the filesystem on hosts that give you no place outside the web root.
    $overrides = [
        ['EDUKORS_DB_DSN',          'db',    'dsn'],
        ['EDUKORS_DB_USER',         'db',    'user'],
        ['EDUKORS_DB_PASS',         'db',    'pass'],
        ['EDUKORS_OPENROUTER_KEY',  'ai',    'key'],
        ['EDUKORS_MODEL',           'ai',    'model'],
        ['EDUKORS_AI_URL',          'ai',    'url'],
        ['EDUKORS_JUDGE_URL',       'judge', 'url'],
        ['EDUKORS_ADMIN_USER',      'admin', 'user'],
        ['EDUKORS_ADMIN_HASH',      'admin', 'hash'],
    ];
    foreach ($overrides as [$name, $section, $key]) {
        $value = edukors_env($name);
        if ($value !== null) {
            $config[$section][$key] = $value;
        }
    }
    $baseUrl = edukors_env('EDUKORS_BASE_URL');
    if ($baseUrl !== null) {
        $config['base_url'] = $baseUrl;
    }
    $devMode = edukors_env('EDUKORS_DEV_MODE');
    if ($devMode !== null) {
        $config['dev_mode'] = in_array(strtolower($devMode), ['1', 'true', 'yes', 'on'], true);
    }

    $config['base_url'] = rtrim((string) $config['base_url'], '/');

    return $config;
}

/** Whether this request arrived over HTTPS, directly or through a proxy. */
function edukors_is_https(): bool
{
    return (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * The path the player is served from, read from base_url: "/" when it has a
 * site of its own, "/graphs" when it sits in a subfolder of a bigger one.
 *
 * It is what scopes the cookies, so that a player installed beside another
 * application does not share cookie space with it.
 */
function edukors_base_path(): string
{
    $base = (string) (edukors_config()['base_url'] ?? '');
    $path = $base === '' ? '' : (string) (parse_url($base, PHP_URL_PATH) ?? '');
    $path = rtrim($path, '/');
    return $path === '' ? '/' : $path;
}

/**
 * The address of one of this application's own files, with a version token.
 *
 * The token is the file's own timestamp, so it changes exactly when the file
 * changes and never has to be remembered. It matters after a deploy: a browser
 * still holding the previous bridge.js would go on talking to the server the
 * old way.
 *
 * $path is relative to public/; $prefix is how the page that writes the link
 * reaches public/ from where it sits.
 */
function edukors_asset(string $path, string $prefix = ''): string
{
    $file  = __DIR__ . '/../public/' . $path;
    $stamp = is_file($file) ? (int) filemtime($file) : 0;
    return $prefix . $path . '?v=' . $stamp;
}

/**
 * Where the configuration file is, or null when there is none.
 *
 * The places are tried in this order:
 *
 *   1. EDUKORS_CONFIG, when the environment names the file outright;
 *   2. private/config.php beside this application — the layout of the
 *      repository, where the web root is the sibling `public` folder;
 *   3. private/graphs.config.php one level above the web root — the layout of a
 *      shared host, where the application is installed in a subfolder of a site
 *      (public_html/graphs) and cannot have a web root of its own. From
 *      public_html/graphs/src, three levels up is the account root, which is
 *      where a private folder beside public_html lives.
 *
 * Whichever it is, the file always sits outside anything the web server will
 * serve.
 */
function edukors_config_file(): ?string
{
    $candidates = [
        edukors_env('EDUKORS_CONFIG'),
        __DIR__ . '/../private/config.php',
        __DIR__ . '/../../../private/graphs.config.php',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== null && is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * An environment variable, or null when it is not set at all.
 *
 * A variable that is set but empty counts as a value, not as an absence: an
 * empty password is a real password, and it is the only way to express one
 * where the environment is all you have.
 */
function edukors_env(string $name): ?string
{
    $value = getenv($name);
    return $value === false ? null : $value;
}
