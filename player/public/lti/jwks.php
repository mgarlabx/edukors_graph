<?php
/**
 * The public keys of this tool: there are none, and that is the answer.
 *
 * A platform asks a tool for a key set so it can verify messages the tool
 * signs. This one signs nothing -- no grade passback, no roster, no deep
 * linking, nothing goes back to the LMS at all -- so it has no keys, and this
 * endpoint says so in the form the specification expects.
 *
 * It exists because the registration form of every LMS asks for the address of
 * a key set, and an empty one is the honest answer. A platform only ever reads
 * it when verifying something this tool sent, which never happens.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

echo json_encode(['keys' => []]);
