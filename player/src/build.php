<?php
/**
 * Builds the HTML the student gets, out of assets/course_player.html.
 *
 * The template is the player the builder skill ships, taken as it is. It
 * already renders every node type, follows the edges, speaks ten languages and
 * follows the light/dark setting of the browser. What it does not have is a
 * model to ask, a place to keep progress and an identity for the student --
 * which is exactly what this server adds around it, without editing the
 * player's own code.
 *
 * Two builds come out of here:
 *
 *   online  -- the course with its prompts removed, plus assets/bridge.js,
 *              which answers the player's model calls from this server.
 *   offline -- a single file that runs with no network at all. Its AI steps
 *              say so and let the student carry on.
 *
 * Both splice the course into the same block build_player.py uses, so the
 * template stays interchangeable with the one in the skill.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/course.php';

const EDUKORS_BOOT_OPEN  = '<script type="application/json" id="edukors-player-boot">';
const EDUKORS_BOOT_CLOSE = '</script>';

/** The player template, read from disk. */
function edukors_template(): string
{
    $path = __DIR__ . '/../assets/course_player.html';
    $html = @file_get_contents($path);
    if ($html === false) {
        throw new RuntimeException("player template not found: $path");
    }
    if (!str_contains($html, EDUKORS_BOOT_OPEN)) {
        throw new RuntimeException('the template does not look like the Edukors player');
    }
    return $html;
}

/**
 * JSON safe to sit inside a <script type="application/json"> block.
 * JSON_HEX_TAG escapes < and >, which is what could close the block early.
 */
function edukors_boot_json(array $payload): string
{
    return json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/** Replaces the placeholder course of the template with a real payload. */
function edukors_splice_boot(string $html, array $payload): string
{
    $start = strpos($html, EDUKORS_BOOT_OPEN);
    $end   = strpos($html, EDUKORS_BOOT_CLOSE, $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('the template has no boot block');
    }
    $head = substr($html, 0, $start + strlen(EDUKORS_BOOT_OPEN));
    $tail = substr($html, $end);
    return $head . edukors_boot_json($payload) . $tail;
}

/** Puts the course title and language in the page, as build_player.py does. */
function edukors_set_head(string $html, Course $course, string $lang): string
{
    // Through a callback, so a '$' or a backslash in a title is not read as a
    // backreference of the replacement.
    $title = htmlspecialchars($course->title($lang), ENT_QUOTES, 'UTF-8');
    $html = preg_replace_callback(
        '#<title>.*?</title>#s',
        static fn() => "<title>$title</title>",
        $html,
        1
    ) ?? $html;

    $safeLang = htmlspecialchars($lang, ENT_QUOTES, 'UTF-8');
    return preg_replace_callback(
        '#<html lang="[^"]*"#',
        static fn() => "<html lang=\"$safeLang\"",
        $html,
        1
    ) ?? $html;
}

/** Inserts a script just before the player's own, so it runs first. */
function edukors_insert_before_player(string $html, string $script): string
{
    // The player's script is the one right after the boot block.
    $bootEnd = strpos($html, EDUKORS_BOOT_CLOSE, (int) strpos($html, EDUKORS_BOOT_OPEN));
    if ($bootEnd === false) {
        throw new RuntimeException('the template has no boot block');
    }
    $at = $bootEnd + strlen(EDUKORS_BOOT_CLOSE);
    return substr($html, 0, $at) . "\n" . $script . substr($html, $at);
}

/**
 * The page a student gets after an LTI launch.
 *
 * $bridge is what public/assets/bridge.js needs to know: where to send the
 * model calls and the progress, and the state already saved for this student.
 */
function edukors_build_online(Course $course, string $lang, array $bridge): string
{
    $payload = [
        // Prompts are stripped here: see Course::withoutPrompts().
        'course' => $course->withoutPrompts(),
        // The player reads both of these from the boot block already.
        'scope'  => $bridge['scope'],
        'lang'   => $lang,
    ];

    $html = edukors_splice_boot(edukors_template(), $payload);
    $html = edukors_set_head($html, $course, $lang);

    $config = edukors_boot_json($bridge);
    $script = '<script type="application/json" id="edukors-server">' . $config . '</script>'
            . "\n" . '<script src="' . edukors_asset('assets/bridge.js') . '"></script>';

    return edukors_insert_before_player($html, $script);
}

/**
 * The single file a student downloads to run the course offline.
 *
 * There is no model to ask, so the short script below answers the player's one
 * request itself, with a note in the student's language. The player then shows
 * that note as the step's content and lets the student move on -- which is what
 * it already does for any answer it gets. The prompts stay out of the file.
 */
function edukors_build_offline(Course $course, string $lang, ?array $state = null): string
{
    $payload = ['course' => $course->withoutPrompts(), 'lang' => $lang];

    $html = edukors_splice_boot(edukors_template(), $payload);
    $html = edukors_set_head($html, $course, $lang);

    $script = '<script>' . edukors_offline_script(edukors_offline_notice($lang), $course, $state)
            . '</script>';

    return edukors_insert_before_player($html, $script);
}

/** The text an AI step shows in the offline file. */
function edukors_offline_notice(string $lang): string
{
    $notices = [
        'en' => 'This step is written by artificial intelligence and does not work in the offline copy '
              . 'of the course. Go on to the next step.',
        'pt' => 'Este passo é escrito por inteligência artificial e não funciona na cópia offline do '
              . 'curso. Siga para o próximo passo.',
        'es' => 'Este paso lo escribe una inteligencia artificial y no funciona en la copia sin conexión '
              . 'del curso. Continúa al siguiente paso.',
        'fr' => "Cette étape est écrite par une intelligence artificielle et ne fonctionne pas dans la "
              . "copie hors ligne du cours. Passez à l'étape suivante.",
        'de' => 'Dieser Schritt wird von einer künstlichen Intelligenz geschrieben und funktioniert in der '
              . 'Offline-Kopie des Kurses nicht. Gehen Sie zum nächsten Schritt.',
        'it' => 'Questo passo è scritto da un\'intelligenza artificiale e non funziona nella copia offline '
              . 'del corso. Passa al passo successivo.',
        'ru' => 'Этот шаг создаётся искусственным интеллектом и не работает в офлайн-копии курса. '
              . 'Переходите к следующему шагу.',
        'zh' => '本步骤由人工智能生成，在课程的离线副本中无法使用。请继续下一步。',
        'hi' => 'यह चरण कृत्रिम बुद्धिमत्ता द्वारा लिखा जाता है और पाठ्यक्रम की ऑफ़लाइन प्रति में काम नहीं करता। '
              . 'अगले चरण पर जाएँ।',
        'ar' => 'هذه الخطوة يكتبها الذكاء الاصطناعي ولا تعمل في النسخة غير المتصلة من الدورة. '
              . 'انتقل إلى الخطوة التالية.',
    ];
    $base = explode('-', $lang)[0];
    return $notices[$lang] ?? $notices[$base] ?? $notices['en'];
}

/**
 * The offline answer, minified by hand to match the rest of the file.
 *
 * It does two things. It takes the place of the host the player would otherwise
 * ask, and always answers the same note. Returning a successful answer rather
 * than an error is deliberate: the player then treats the note as the content
 * of the step, so the student reads a plain explanation instead of a failure
 * with a retry button that could never succeed.
 *
 * And, when the student already has work in this course, it puts that work into
 * the copy: the steps the AI already wrote for them, their answers and their
 * feedback all travel with the file. The note is then left for the steps they
 * have not reached yet.
 */
function edukors_offline_script(string $notice, Course $course, ?array $state): string
{
    // Plain text, with no markup: a dynamic-md step renders it as a paragraph
    // and a dynamic-html step shows it as text inside its frame, so the same
    // answer reads correctly in both.
    $text = json_encode('⚠️ ' . $notice, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    $seed = '';
    if ($state !== null && $state !== []) {
        $key   = json_encode('edukors.player.' . $course->scope(), JSON_HEX_TAG);
        $value = json_encode(
            json_encode($state, JSON_UNESCAPED_UNICODE),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
        $seed = 'try{localStorage.setItem(' . $key . ',' . $value . ')}catch(e){}';
    }

    return '(function(){' . $seed . 'var n=' . $text . ',f=window.fetch;'
         . 'window.fetch=function(u,o){'
         . 'if(String(u).indexOf("api.anthropic.com")>-1)'
         . 'return Promise.resolve(new Response(JSON.stringify({content:[{type:"text",text:n}]}),'
         . '{status:200,headers:{"Content-Type":"application/json"}}));'
         . 'return f.apply(this,arguments)}})();';
}
