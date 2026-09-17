<?php
/**
 * Just enough JWT to check what an LMS sends.
 *
 * The integration is one-way: the LMS tells this server who the student is, and
 * this server tells the LMS nothing. That removes most of LTI's cryptography --
 * there is no message to sign, so this tool needs no key pair and publishes no
 * key set. All that is left is verifying an RS256 signature against the
 * platform's public keys, which PHP's openssl does.
 */

declare(strict_types=1);

/** base64url, as JWT uses it. */
function jwt_b64_decode(string $text): string
{
    $padded = strtr($text, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $decoded = base64_decode($padded, true);
    return $decoded === false ? '' : $decoded;
}

/** The header and the payload of a token, without checking anything. */
function jwt_peek(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $header  = json_decode(jwt_b64_decode($parts[0]), true);
    $payload = json_decode(jwt_b64_decode($parts[1]), true);
    if (!is_array($header) || !is_array($payload)) {
        return null;
    }
    return ['header' => $header, 'payload' => $payload];
}

/**
 * Checks the signature of an RS256 token against a JWKS key set.
 * Returns the payload, or throws.
 */
function jwt_verify(string $token, array $jwks): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        throw new RuntimeException('the token is not a JWT');
    }
    [$headerPart, $payloadPart, $signaturePart] = $parts;

    $header = json_decode(jwt_b64_decode($headerPart), true);
    if (!is_array($header)) {
        throw new RuntimeException('the token header is unreadable');
    }
    if (($header['alg'] ?? '') !== 'RS256') {
        // LTI 1.3 requires RS256, and accepting anything else -- 'none' above
        // all -- is how these integrations get broken into.
        throw new RuntimeException('unsupported signature algorithm');
    }

    $jwk = jwt_find_key($jwks, $header['kid'] ?? null);
    if ($jwk === null) {
        throw new RuntimeException('the platform has no key with this id');
    }

    $pem = jwt_jwk_to_pem($jwk);
    $ok  = openssl_verify(
        $headerPart . '.' . $payloadPart,
        jwt_b64_decode($signaturePart),
        $pem,
        OPENSSL_ALGO_SHA256
    );
    if ($ok !== 1) {
        throw new RuntimeException('the signature does not match');
    }

    $payload = json_decode(jwt_b64_decode($payloadPart), true);
    if (!is_array($payload)) {
        throw new RuntimeException('the token payload is unreadable');
    }
    return $payload;
}

/** The key with this id, or the only RSA key when the token names none. */
function jwt_find_key(array $jwks, ?string $kid): ?array
{
    $keys = $jwks['keys'] ?? [];
    if (!is_array($keys)) {
        return null;
    }
    foreach ($keys as $key) {
        if (is_array($key) && ($key['kty'] ?? '') === 'RSA' && ($key['kid'] ?? null) === $kid) {
            return $key;
        }
    }
    if ($kid === null) {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kty'] ?? '') === 'RSA') {
                return $key;
            }
        }
    }
    return null;
}

/**
 * An RSA public key in PEM, built from the modulus and exponent of a JWK.
 *
 * PHP has no jwk-to-pem, so the DER is assembled here: the RSA public key is
 * SEQUENCE(INTEGER n, INTEGER e), wrapped in the SubjectPublicKeyInfo header
 * that says "this is RSA", and printed as base64 between the PEM lines.
 */
function jwt_jwk_to_pem(array $jwk): string
{
    $modulus  = jwt_b64_decode((string) ($jwk['n'] ?? ''));
    $exponent = jwt_b64_decode((string) ($jwk['e'] ?? ''));
    if ($modulus === '' || $exponent === '') {
        throw new RuntimeException('the key is missing its modulus or exponent');
    }

    $sequence = jwt_der(0x30, jwt_der_int($modulus) . jwt_der_int($exponent));

    // The algorithm identifier for rsaEncryption, with its NULL parameters.
    $algorithm = jwt_der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
    $bitString = jwt_der(0x03, "\x00" . $sequence);
    $der       = jwt_der(0x30, $algorithm . $bitString);

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/** One DER element: a tag, its length, and its contents. */
function jwt_der(int $tag, string $contents): string
{
    $length = strlen($contents);
    if ($length < 0x80) {
        $header = chr($length);
    } else {
        $bytes  = ltrim(pack('N', $length), "\x00");
        $header = chr(0x80 | strlen($bytes)) . $bytes;
    }
    return chr($tag) . $header . $contents;
}

/** A DER INTEGER, with the leading zero a positive number needs. */
function jwt_der_int(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00" . $bytes;
    }
    return jwt_der(0x02, $bytes);
}
