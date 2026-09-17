#!/usr/bin/env php
<?php
/**
 * Turns a password into the line to paste into the configuration file.
 *
 *     php tools/admin-password.php
 *
 * It asks for the password twice, without showing it, and never writes it
 * anywhere: what comes out is the hash, which is all the server needs and which
 * nobody can read a password back out of.
 *
 * Use this rather than copying a hash from anywhere else. A hash is the hash of
 * one particular password -- borrowing one from a document or an example means
 * the only password that opens the admin is whatever that example used.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = ask('Password for the admin: ');
if ($password === '') {
    fwrite(STDERR, "No password given; nothing to do.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "That is under 8 characters. Choose a longer one.\n");
    exit(1);
}
if (ask('Type it again:        ') !== $password) {
    fwrite(STDERR, "The two do not match; nothing to do.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\nPut this in the 'admin' block of your configuration file:\n\n";
echo "    'admin' => [\n";
echo "        'user' => 'admin',\n";
echo "        'hash' => '", $hash, "',\n";
echo "    ],\n\n";
echo "Keep the single quotes: between double quotes PHP would read the \$2y as a\n";
echo "variable and store a broken hash.\n";

/** Reads a line from the terminal without showing what is typed. */
function ask(string $prompt): string
{
    fwrite(STDERR, $prompt);

    $quiet = stripos(PHP_OS_FAMILY, 'Windows') === false
        && shell_exec('stty -echo 2>/dev/null; echo ok') !== null;

    $line = fgets(STDIN);

    if ($quiet) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }

    return $line === false ? '' : rtrim($line, "\r\n");
}
