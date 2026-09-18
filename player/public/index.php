<?php
/**
 * The front door, which is the catalogue.
 *
 * Everything this server offers to somebody who was not sent here by a
 * learning platform is in public/catalog/, and this is how they arrive at it:
 * the address they typed is the root, and the root is the list of courses.
 *
 * The page itself lives one folder down rather than here, so that it can sit
 * beside the four pages it links to -- the map, the player, the JSON and the
 * download -- and so that every one of them resolves its assets the same way.
 *
 * The addresses an LMS administrator needs are no longer on this page: they
 * are in the admin, on the platforms page and on each course's own, which is
 * where somebody who has a password to this server is already looking.
 */

declare(strict_types=1);

// Relative on purpose: this application is installed at whatever path the site
// gives it -- the root of its own host, or /graphs of a bigger site -- and a
// relative redirect lands in the right place under either, with nothing to
// configure. Browsers have resolved these since RFC 7231.
header('Location: catalog/', true, 302);
header('Content-Type: text/html; charset=utf-8');

echo '<!doctype html><meta charset="utf-8"><title>Edukors</title>'
   . '<p style="font:16px system-ui;margin:3rem"><a href="catalog/">The courses</a></p>';
