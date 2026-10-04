/**
 * Page « Cache Faaaster » : onglets (.seg), interrupteurs DS (button.switch +
 * champ caché) et confirmation des purges totales. Sans dépendance.
 */
(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var tab = event.target.closest('.fstr-ds .seg button[data-href]');
    if (tab) {
      window.location.href = tab.getAttribute('data-href');
      return;
    }

    var sw = event.target.closest('.fstr-ds button.switch');
    if (sw) {
      var on = !sw.classList.contains('on');
      sw.classList.toggle('on', on);
      sw.setAttribute('aria-checked', on ? 'true' : 'false');
      sw.setAttribute('data-state', on ? 'checked' : 'unchecked');
      var input = sw.previousElementSibling;
      if (input && input.type === 'hidden') {
        input.value = on ? sw.getAttribute('data-value') : input.getAttribute('data-off');
        // Case de liste (name[]) : un champ décoché n'est pas envoyé.
        if (input.name.slice(-2) === '[]') {
          input.disabled = !on;
        }
      }
    }
  });

  document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });
})();
