<?php
/**
 * The three things the catalogue offers on a course, drawn.
 *
 * Inline SVG, stroked in currentColor: no icon font, no sprite sheet, nothing
 * to fetch. Each one is also given a label, because an icon alone tells a
 * screen reader nothing -- and tells a first-time visitor little more.
 */

declare(strict_types=1);

/** One action icon, as a link. */
function catalog_action(string $href, string $icon, string $label, bool $blank = false): string
{
    $target = $blank ? ' target="_blank" rel="noopener"' : '';
    $safe   = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    return '<a class="action" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
         . $target . ' title="' . $safe . '" aria-label="' . $safe . '">'
         . catalog_icon($icon) . '</a>';
}

function catalog_icon(string $name): string
{
    $open = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" '
          . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

    $paths = [
        // the graph: a course is nodes with edges between them
        'map'      => '<circle cx="5.5" cy="6" r="2.5"/><circle cx="18.5" cy="12" r="2.5"/>'
                    . '<circle cx="5.5" cy="18" r="2.5"/>'
                    . '<path d="M7.7 7.3 16.3 10.7M16.3 13.3 7.7 16.7"/>',
        // the player: press it and the course runs
        'play'     => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5 16 12l-6 3.5z"/>',
        // the JSON: the braces it is written in
        'json'     => '<path d="M9.5 4C7 4 7.5 9.2 5 10.8v2.4C7.5 14.8 7 20 9.5 20"/>'
                    . '<path d="M14.5 4c2.5 0 2 5.2 4.5 6.8v2.4c-2.5 1.6-2 6.8-4.5 6.8"/>',
        // the admin's edit: a brush, handle up and to the right
        'edit'     => '<path d="M20 4 11.5 12.5"/>'
                    . '<path d="M13.5 14.5 9.5 10.5c-2 0-3.5 1.5-3.5 3.5 0 1.8-1 3-2.5 4 3 1 7 .5 8.5-1 .8-.8 1-1.6 1.5-2.5z"/>',
    ];

    return $open . ($paths[$name] ?? '') . '</svg>';
}
