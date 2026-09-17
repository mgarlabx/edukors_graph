<?php
/**
 * LTI 1.3, in one direction only.
 *
 * The LMS tells this server who the student is and which course they opened.
 * This server sends nothing back: no grades, no roster, no deep linking. That
 * is a deliberate limit, and it makes the integration much smaller -- a tool
 * that never signs a message to the platform needs no key pair and publishes no
 * key set, so all that is implemented here is the receiving half.
 *
 * The flow is the one the specification prescribes:
 *
 *   1. the LMS calls lti/login.php  -- we answer with a redirect carrying a
 *      state and a nonce we just stored;
 *   2. the LMS posts an id_token to lti/launch.php -- we check its signature,
 *      its claims, and that the state and nonce are the ones we issued.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/course.php';

const LTI_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/';

class LtiError extends RuntimeException {}

/** A registered platform, found by what the LMS says about itself. */
function lti_platform(string $issuer, ?string $clientId, ?string $deploymentId): ?array
{
    $sql    = 'SELECT * FROM lti_platform WHERE issuer = ?';
    $params = [$issuer];
    if ($clientId !== null && $clientId !== '') {
        $sql .= ' AND client_id = ?';
        $params[] = $clientId;
    }
    if ($deploymentId !== null && $deploymentId !== '') {
        $sql .= ' AND deployment_id = ?';
        $params[] = $deploymentId;
    }
    return db_row($sql . ' LIMIT 1', $params);
}

/** A value no one can guess, for the state and the nonce. */
function lti_random(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * Step 1: where to send the browser so the LMS can authenticate the student.
 * Stores the state and the nonce that step 2 will insist on finding again.
 */
function lti_login_url(array $platform, array $request, string $redirectUri): string
{
    $state = lti_random();
    $nonce = lti_random();

    db_run('DELETE FROM lti_launch WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 600)]);
    db_run(
        'INSERT INTO lti_launch (state, nonce, platform_id, course_uuid, created_at)
         VALUES (?, ?, ?, ?, ?)',
        [$state, $nonce, $platform['id'], lti_course_from_target($request['target_link_uri'] ?? ''), db_now()]
    );

    $query = [
        'scope'                    => 'openid',
        'response_type'            => 'id_token',
        'response_mode'            => 'form_post',
        'prompt'                   => 'none',
        'client_id'                => $platform['client_id'],
        'redirect_uri'             => $redirectUri,
        'login_hint'               => (string) ($request['login_hint'] ?? ''),
        'state'                    => $state,
        'nonce'                    => $nonce,
    ];
    if (($request['lti_message_hint'] ?? '') !== '') {
        $query['lti_message_hint'] = (string) $request['lti_message_hint'];
    }

    return $platform['auth_login_url'] . (str_contains($platform['auth_login_url'], '?') ? '&' : '?')
         . http_build_query($query);
}

/**
 * Step 2: checks everything about the token, and returns what it says.
 *
 * @return array{platform:array, claims:array, course_uuid:?string}
 */
function lti_verify_launch(string $idToken, string $state): array
{
    $launch = db_row('SELECT * FROM lti_launch WHERE state = ?', [$state]);
    if ($launch === null) {
        throw new LtiError('this launch did not start here');
    }
    if ($launch['used_at'] !== null) {
        throw new LtiError('this launch has already been used');
    }
    if (strtotime((string) $launch['created_at']) < time() - 600) {
        throw new LtiError('this launch took too long; open the activity again');
    }
    // Spent whatever happens next, so the same token cannot be replayed.
    db_run('UPDATE lti_launch SET used_at = ? WHERE state = ?', [db_now(), $state]);

    $platform = db_row('SELECT * FROM lti_platform WHERE id = ?', [$launch['platform_id']]);
    if ($platform === null) {
        throw new LtiError('the platform is no longer registered');
    }

    $peek = jwt_peek($idToken);
    if ($peek === null) {
        throw new LtiError('the token is unreadable');
    }

    $claims = jwt_verify($idToken, lti_jwks($platform, $peek['header']['kid'] ?? null));

    lti_check_claims($claims, $platform, (string) $launch['nonce']);

    // The course may be named by the launch URL or by a custom parameter; both
    // are things an LMS administrator can set without touching anything else.
    $courseUuid = $launch['course_uuid']
        ?: lti_course_from_target((string) ($claims[LTI_CLAIM . 'target_link_uri'] ?? ''))
        ?: lti_uuid($claims[LTI_CLAIM . 'custom']['course_id'] ?? null);

    if ($courseUuid === null) {
        throw new LtiError('this activity does not say which course to open');
    }

    return ['platform' => $platform, 'claims' => $claims, 'course_uuid' => $courseUuid];
}

/** Everything the specification says a resource link launch must carry. */
function lti_check_claims(array $claims, array $platform, string $nonce): void
{
    $now = time();

    if (($claims['iss'] ?? '') !== $platform['issuer']) {
        throw new LtiError('the token comes from another platform');
    }

    // `aud` is the client id, and may be a list.
    $audience = $claims['aud'] ?? null;
    $audiences = is_array($audience) ? $audience : [$audience];
    if (!in_array($platform['client_id'], $audiences, true)) {
        throw new LtiError('the token was not issued for this tool');
    }

    if (!isset($claims['exp']) || (int) $claims['exp'] < $now - 60) {
        throw new LtiError('the token has expired');
    }
    if (isset($claims['iat']) && (int) $claims['iat'] > $now + 60) {
        throw new LtiError('the token is dated in the future');
    }
    if (($claims['nonce'] ?? '') !== $nonce) {
        throw new LtiError('the token answers a different login');
    }
    if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
        throw new LtiError('the token does not say who the student is');
    }

    if (($claims[LTI_CLAIM . 'version'] ?? '') !== '1.3.0') {
        throw new LtiError('only LTI 1.3 is supported');
    }
    if (($claims[LTI_CLAIM . 'message_type'] ?? '') !== 'LtiResourceLinkRequest') {
        throw new LtiError('this tool only answers a resource link launch');
    }
    if ((string) ($claims[LTI_CLAIM . 'deployment_id'] ?? '') !== (string) $platform['deployment_id']) {
        throw new LtiError('this deployment is not registered');
    }
}

/**
 * The platform's public keys, cached for an hour.
 *
 * A key id we have never seen means the platform has rotated its keys, so the
 * cache is refetched once before giving up.
 */
function lti_jwks(array $platform, ?string $kid): array
{
    $cached = json_decode((string) $platform['jwks_cache'], true);
    $fresh  = $platform['jwks_fetched_at'] !== null
        && strtotime((string) $platform['jwks_fetched_at']) > time() - 3600;

    if (is_array($cached) && $fresh && jwt_find_key($cached, $kid) !== null) {
        return $cached;
    }

    $fetched = lti_fetch_jwks((string) $platform['jwks_url']);
    db_run(
        'UPDATE lti_platform SET jwks_cache = ?, jwks_fetched_at = ? WHERE id = ?',
        [json_encode($fetched), db_now(), $platform['id']]
    );
    return $fetched;
}

function lti_fetch_jwks(string $url): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $keys = $raw === false ? null : json_decode((string) $raw, true);
    if ($status >= 400 || !is_array($keys) || !isset($keys['keys'])) {
        throw new LtiError('could not read the public keys of the platform');
    }
    return $keys;
}

/** The course uuid in a launch URL, e.g. .../lti/launch.php?course=<uuid>. */
function lti_course_from_target(string $target): ?string
{
    if ($target === '') {
        return null;
    }
    $query = parse_url($target, PHP_URL_QUERY);
    if (!is_string($query)) {
        return null;
    }
    parse_str($query, $params);
    return lti_uuid($params['course'] ?? null);
}

/** A value that really is a uuid, or null. */
function lti_uuid($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $pattern = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    return preg_match($pattern, $value) === 1 ? strtolower($value) : null;
}

/** Creates or updates the student the token describes. */
function lti_student(array $platform, array $claims): array
{
    $subject = (string) $claims['sub'];
    $name    = $claims['name'] ?? trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? ''));
    $name    = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null;
    $email   = isset($claims['email']) && is_string($claims['email'])
        ? mb_substr($claims['email'], 0, 255) : null;
    $locale  = $claims[LTI_CLAIM . 'launch_presentation']['locale'] ?? null;
    $locale  = is_string($locale) ? mb_substr($locale, 0, 10) : null;

    $existing = db_row(
        'SELECT * FROM student WHERE platform_id = ? AND subject = ?',
        [$platform['id'], $subject]
    );

    if ($existing !== null) {
        db_run(
            'UPDATE student SET name = ?, email = ?, locale = ?, last_seen_at = ? WHERE id = ?',
            [$name, $email, $locale, db_now(), $existing['id']]
        );
        return db_row('SELECT * FROM student WHERE id = ?', [$existing['id']]);
    }

    db_run(
        'INSERT INTO student (platform_id, subject, name, email, locale, created_at, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$platform['id'], $subject, $name, $email, $locale, db_now(), db_now()]
    );
    return db_row('SELECT * FROM student WHERE id = ?', [(int) edukors_db()->lastInsertId()]);
}

/** The language to start the course in: what the LMS says, if the course has it. */
function lti_language(array $claims, Course $course): string
{
    $locale = $claims[LTI_CLAIM . 'launch_presentation']['locale'] ?? null;
    if (is_string($locale)) {
        $languages = $course->languages();
        if (in_array($locale, $languages, true)) {
            return $locale;
        }
        $base = explode('-', str_replace('_', '-', $locale))[0];
        foreach ($languages as $language) {
            if (explode('-', $language)[0] === $base) {
                return $language;
            }
        }
    }
    return $course->sourceLanguage();
}
