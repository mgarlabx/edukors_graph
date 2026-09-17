<?php
/**
 * The database connection, and the few helpers every page uses.
 *
 * Everything goes through prepared statements; no query in this project ever
 * interpolates a value into SQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function edukors_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = edukors_config()['db'];
    if ($db['dsn'] === '') {
        throw new RuntimeException(
            'No database configured. Copy private/config.sample.php to private/config.php.'
        );
    }

    $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/** Runs a statement and returns it, ready to be fetched. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $statement = edukors_db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

/** The first row, or null. */
function db_row(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Every row. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** The first column of the first row, or null. */
function db_value(string $sql, array $params = [])
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * A column name, quoted so it cannot collide with a reserved word.
 * Only ever called with names this code chose, never with anything from input.
 */
function db_column(string $name): string
{
    if (preg_match('/^[a-z_][a-z0-9_]*$/i', $name) !== 1) {
        throw new InvalidArgumentException("not a column name: $name");
    }
    return '`' . $name . '`';
}

/** Now, in the format MySQL DATETIME columns expect. */
function db_now(): string
{
    return gmdate('Y-m-d H:i:s');
}
