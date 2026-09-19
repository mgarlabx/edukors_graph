// Anything marked .copy puts its text on the clipboard when clicked.
document.addEventListener('click', function (event) {
  var el = event.target.closest('.copy');
  if (!el) return;
  var text = el.textContent.trim();

  var done = function () {
    el.classList.add('copied');
    clearTimeout(el._copied);
    el._copied = setTimeout(function () { el.classList.remove('copied'); }, 1200);
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done, function () { fallback(text) && done(); });
  } else if (fallback(text)) {
    done();
  }
});

// Plain http (a local server) has no clipboard API; a hidden textarea still works.
function fallback(text) {
  var area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();
  var ok = false;
  try { ok = document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(area);
  return ok;
}
