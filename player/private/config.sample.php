<?php
/**
 * Edukors Graph player -- configuration.
 *
 * WARNING: this file holds secrets. Copy it to private/config.php and keep it
 * OUT of the document root: the web root of the site must be player/public,
 * never player/. Nothing under private/ should ever be reachable over HTTP.
 *
 * If the hosting does not let you move the web root, keep the private/.htaccess
 * that denies every request, and prefer the environment variables named beside
 * each value -- an environment variable always wins over what is written here,
 * so on a container or a PaaS you can leave this file with its defaults.
 * EDUKORS_CONFIG names an alternative path for this file.
 *
 * For the admin password, run `php tools/admin-password.php` and paste what it
 * prints. Do not copy a hash from anywhere else: a hash belongs to one password,
 * so a borrowed one only opens to whatever password made it.
 */

return [

    // Database -- EDUKORS_DB_DSN, EDUKORS_DB_USER, EDUKORS_DB_PASS
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=edukors_graphs;charset=utf8mb4',
        'user' => 'edukors_graphs',
        'pass' => 'change-this-password',
    ],

    // Inference, through OpenRouter -- EDUKORS_OPENROUTER_KEY, EDUKORS_MODEL
    'ai' => [
        'key'         => 'sk-or-v1-...',
        // Where to send the call that writes a step (EDUKORS_AI_URL). Change it
        // only to put a gateway of your own in front of OpenRouter -- the
        // answer must keep the same shape. A judgement does NOT go here: see
        // judge.url below.
        'url'         => 'https://openrouter.ai/api/v1/chat/completions',
        // Check the exact slug at https://openrouter.ai/models before deploying.
        'model'       => 'openai/gpt-5.6-luna',
        'max_tokens'  => 1200,
        'temperature' => 0.7,
        'timeout'     => 45,    // seconds; the player has no timeout of its own
        'per_hour'    => 40,    // calls per student per course per hour
        'per_day'     => 5000,  // calls per day, whole installation
        // OpenRouter shows these on its dashboard; both are optional.
        'referer'     => 'https://player.edukors.org',
        'title'       => 'Edukors Graph Player',
    ],

    // Judgements: the choice, score and noul nodes.
    //
    // A course names the model that answers them in `info.judge-model`, as an
    // exact version and never an alias, because the thresholds in its edges, the
    // points on its levels and its confidence floors were all tuned against one
    // version of one model. This map is where a server says which slug answers
    // each of those names.
    //
    // A course whose judge-model has no line here is refused at import, and at
    // run time its judgements do not happen: the student takes the unconditional
    // edge. It is never quietly answered by `ai.model` -- a judgement from an
    // unknown model is exactly the thing the exact version exists to prevent.
    'judge' => [
        'models' => [
            // 'jev-1.13.0' => 'typesafe/jev-1.13',
        ],
        // Where a judgement goes (EDUKORS_JUDGE_URL). It is not ai.url and it
        // cannot be: jev is a decisions model, and OpenRouter refuses it at
        // chat/completions in so many words --
        //   "typesafe/jev-1.13 is a decisions model and cannot be used with
        //    the chat/completions endpoint."
        // This endpoint takes {model, state, questions} and answers with the
        // typed answers themselves, which is the shape the choice, score and
        // noul nodes were written against.
        'url'          => 'https://openrouter.ai/api/alpha/decisions',
        'timeout'      => 45,
        // Refuse a judgement that came back from a slug other than the one asked
        // for. OpenRouter serves one name from several providers, and a route
        // that moves under a tuned floor is the alias the schema forbids.
        'strict_model' => true,
    ],

    // The admin pages -- EDUKORS_ADMIN_USER, EDUKORS_ADMIN_HASH
    'admin' => [
        'user' => 'admin',
        'hash' => '$2y$12$replace.this.with.a.real.password_hash',
    ],

    // Public address of player/public, with no trailing slash -- EDUKORS_BASE_URL.
    // It is what the LMS is given as the tool URL, so it must be the real one.
    'base_url' => 'https://player.edukors.org',

    // Set to true only while developing: it opens a fake session at
    // public/course.php?dev=<course-uuid>, with no LMS. NEVER true in production.
    // EDUKORS_DEV_MODE
    'dev_mode' => false,
];
