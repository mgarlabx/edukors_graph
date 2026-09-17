/**
 * What this server adds around the player, without changing the player.
 *
 * The player asks a host for the model, keeps its progress in localStorage and
 * knows nothing about who is using it. This script, which runs just before the
 * player does, makes this server that host:
 *
 *   1. it answers the player's model call from api/ai.php;
 *   2. it seeds localStorage with the state the server has, so the student
 *      carries on from wherever they left off, on any device;
 *   3. it copies every change back to api/progress.php;
 *   4. it adds a link to download the course to run offline.
 *
 * Steps 2 and 3 work because ProgressStore.write() is the single place the
 * player saves from, and it always goes through localStorage.setItem.
 */
(function () {
  'use strict';

  var configNode = document.getElementById('edukors-server');
  if (!configNode) { return; }

  var config = JSON.parse(configNode.textContent);
  var stateKey = 'edukors.player.' + config.scope;

  // -- 1. the model ---------------------------------------------------------
  //
  // The player sends the prompt it has. Its prompts were replaced by a marker
  // naming the node (see Course::withoutPrompts in PHP), so what arrives here
  // is "#edukors:dm1", and for an essay the student's text after the separator
  // the schema prescribes. We forward the node id and the text -- never a
  // prompt, because there is no prompt in this page to forward.

  var nativeFetch = window.fetch.bind(window);

  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (url.indexOf('api.anthropic.com') === -1) {
      return nativeFetch.apply(this, arguments);
    }
    return askServer(init && init.body);
  };

  function askServer(body) {
    var prompt = '';
    try {
      var sent = JSON.parse(body || '{}');
      var message = (sent.messages || [])[0] || {};
      prompt = String(message.content || '');
    } catch (e) { /* falls through to the error below */ }

    var marker = /#edukors:((?:dm|dh|e)[0-9]+)/.exec(prompt);
    if (!marker) {
      return jsonResponse({ error: { message: 'unknown request' } }, 400);
    }

    var request = { node: marker[1] };
    var split = prompt.indexOf('--- STUDENT TEXT ---');
    if (split > -1) {
      request.text = prompt.slice(split + '--- STUDENT TEXT ---'.length).replace(/^\n/, '');
    }

    // The player asks for a step as soon as it renders it, which is sooner
    // than the save below would have gone out. api/ai.php only writes the step
    // the student is actually on, so the server has to be told where that is
    // before we ask -- otherwise the first AI step of every course is refused.
    return flush().then(function () {
      return nativeFetch(config.aiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(request),
        credentials: 'same-origin'
      });
    });
  }

  function jsonResponse(data, status) {
    return Promise.resolve(new Response(JSON.stringify(data), {
      status: status,
      headers: { 'Content-Type': 'application/json' }
    }));
  }

  // -- 2. the state the server has ------------------------------------------
  //
  // Written before the player boots, so the player reads it as its own. The
  // server's copy wins: a launch is the moment to agree on where the student is.

  try {
    if (config.state && config.state.currentId !== undefined) {
      window.localStorage.setItem(stateKey, JSON.stringify(config.state));
    }
  } catch (e) { /* private browsing: the session still runs, it just won't resume */ }

  // -- 3. copying every change back -----------------------------------------

  var pending = null;
  var timer = null;

  var setItem = Storage.prototype.setItem;
  Storage.prototype.setItem = function (key, value) {
    // Queue the state before the native call: if localStorage throws (private
    // mode, quota), the server copy must still be written.
    if (key === stateKey) {
      pending = value;
      clearTimeout(timer);
      timer = setTimeout(save, 400);
    }
    setItem.call(this, key, value);
  };

  function save() {
    flush().catch(function () { /* the next step will try again */ });
  }

  /** Sends whatever is waiting, and resolves once the server has it. */
  function flush() {
    clearTimeout(timer);
    if (pending === null) { return Promise.resolve(); }
    var body = pending;
    pending = null;
    return nativeFetch(config.progressUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    });
  }

  // A student who closes the tab mid-step should not lose that step.
  window.addEventListener('pagehide', function () {
    if (pending === null) { return; }
    clearTimeout(timer);
    var body = pending;
    pending = null;
    if (navigator.sendBeacon) {
      navigator.sendBeacon(config.progressUrl, new Blob([body], { type: 'application/json' }));
    }
  });

  // -- 4. the offline copy --------------------------------------------------
  //
  // Added to the player's own bar, with the player's own button styling, once
  // the bar exists.

  function addDownload() {
    var bar = document.querySelector('.edukors-player-bar');
    if (!bar || bar.querySelector('[data-edukors-download]')) { return; }

    var link = document.createElement('a');
    link.className = 'edukors-player-button edukors-player-button-small';
    link.href = config.downloadUrl;
    link.textContent = config.downloadLabel;
    link.setAttribute('data-edukors-download', '');
    link.setAttribute('download', '');
    bar.appendChild(link);
  }

  new MutationObserver(addDownload).observe(document.documentElement, {
    childList: true,
    subtree: true
  });
  document.addEventListener('DOMContentLoaded', addDownload);
})();
