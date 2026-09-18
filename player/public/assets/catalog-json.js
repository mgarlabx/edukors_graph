/**
 * The course JSON, as something you can fold.
 *
 * A course is a long file, and most of the time the question is about one step
 * of it. So every object and every array is drawn as a fold: closed, it is one
 * line saying what is inside; open, it shows its contents and nothing else has
 * to move. The folds are <details> elements, so the browser does the opening,
 * the keyboard reaches them and a find-in-page still works on what is open.
 *
 * Children are built the first time a fold is opened. A course of a few hundred
 * steps would otherwise put tens of thousands of nodes in the page for the sake
 * of the twenty anyone reads.
 */
(function () {
  'use strict';

  var source = document.getElementById('course-json');
  var root = document.getElementById('json');
  if (!source || !root) { return; }

  var data;
  try {
    data = JSON.parse(source.textContent);
  } catch (error) {
    root.textContent = 'This course is not readable as JSON.';
    return;
  }

  var isBranch = function (value) {
    return value !== null && typeof value === 'object';
  };

  var entriesOf = function (value) {
    if (Array.isArray(value)) {
      return value.map(function (item, index) { return [String(index), item]; });
    }
    return Object.keys(value).map(function (key) { return [key, value[key]]; });
  };

  /** What a closed fold says about what is inside it. */
  var hintOf = function (value) {
    var count = entriesOf(value).length;
    var what = Array.isArray(value)
      ? count + (count === 1 ? ' item' : ' items')
      : count + (count === 1 ? ' key' : ' keys');

    // A step of a course is worth naming while it is closed: its id and its
    // type are the two things anyone is looking for in this file.
    if (Array.isArray(value)) { return what; }
    var marks = ['id', 'type', 'lang', 'number', 'key'].filter(function (mark) {
      var held = value[mark];
      return typeof held === 'string' || typeof held === 'number';
    }).slice(0, 2).map(function (mark) { return value[mark]; });

    return marks.length === 0 ? what : marks.join(' · ') + ' — ' + what;
  };

  var scalar = function (value) {
    var span = document.createElement('span');
    if (value === null) {
      span.className = 'null';
      span.textContent = 'null';
      return span;
    }
    if (typeof value === 'string') {
      span.className = 'str';
      span.textContent = JSON.stringify(value);
      return span;
    }
    span.className = typeof value === 'number' ? 'num' : 'bool';
    span.textContent = String(value);
    return span;
  };

  var keySpan = function (key) {
    var span = document.createElement('span');
    span.className = 'key';
    span.textContent = key + ': ';
    return span;
  };

  var build = function (key, value) {
    var item = document.createElement('li');

    if (!isBranch(value)) {
      item.className = 'leaf';
      if (key !== null) { item.appendChild(keySpan(key)); }
      item.appendChild(scalar(value));
      return item;
    }

    var details = document.createElement('details');
    var summary = document.createElement('summary');
    if (key !== null) { summary.appendChild(keySpan(key)); }

    var braces = document.createElement('span');
    braces.textContent = Array.isArray(value) ? '[ … ] ' : '{ … } ';
    summary.appendChild(braces);

    var hint = document.createElement('span');
    hint.className = 'count';
    hint.textContent = hintOf(value);
    summary.appendChild(hint);

    details.appendChild(summary);

    // The children of a fold are built the first time it opens, and `fill` is
    // kept on the element so that opening one from code fills it there and
    // then. The toggle event is queued rather than raised on the spot, and
    // whoever opens a fold wants what is inside it before the next line runs.
    details.fill = function () {
      if (details.filled) { return; }
      details.filled = true;
      var list = document.createElement('ul');
      entriesOf(value).forEach(function (entry) {
        list.appendChild(build(entry[0], entry[1]));
      });
      details.appendChild(list);
    };
    details.addEventListener('toggle', function () {
      if (details.open) { details.fill(); }
    });

    item.appendChild(details);
    return item;
  };

  /** Opens a fold and builds what is in it, in that order and at once. */
  var openFold = function (fold) {
    fold.open = true;
    if (fold.fill) { fold.fill(); }
  };

  var list = document.createElement('ul');
  list.appendChild(build(null, data));
  root.textContent = '';
  root.appendChild(list);

  // The top of the file is open to begin with: the course, and the three parts
  // it is made of. Every step inside them stays folded.
  var top = root.querySelector('details');
  if (top) {
    openFold(top);
    Array.prototype.forEach.call(top.querySelectorAll(':scope > ul > li > details'), openFold);
  }

  document.addEventListener('click', function (event) {
    var what = event.target.getAttribute && event.target.getAttribute('data-json');
    if (what !== 'expand' && what !== 'collapse') { return; }

    if (what === 'collapse') {
      Array.prototype.forEach.call(root.querySelectorAll('details'), function (fold) {
        fold.open = false;
      });
      return;
    }

    // Expanding builds every fold that has never been opened, which is the one
    // moment this page pays for the whole file at once. It is asked for. Each
    // pass opens what the pass before it brought into the page, so it ends
    // when a pass finds nothing closed -- one pass per level of the file.
    var closed = root.querySelectorAll('details:not([open])');
    while (closed.length > 0) {
      Array.prototype.forEach.call(closed, openFold);
      closed = root.querySelectorAll('details:not([open])');
    }
  });
}());
