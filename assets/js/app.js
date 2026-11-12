// Miglioramenti progressivi: tutto funziona anche senza JavaScript.
// Alpine nella build CSP (niente eval): i componenti stanno in assets/js/components/.
import Alpine from '@alpinejs/csp';

window.Alpine = Alpine;

function onReady(fn) {
  if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn);
}

onReady(() => {
  // Righe di tabella interamente cliccabili (convenzione ripresa da scs-b2b).
  document.addEventListener('click', (event) => {
    const row = event.target.closest('tr[data-href]');
    if (!row || event.target.closest('a, button, input, select, textarea, label, form')) return;
    window.location.href = row.dataset.href;
  });

  // Bottoni "copia".
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      const previous = button.textContent;
      button.textContent = button.dataset.copiedLabel || '✓';
      setTimeout(() => { button.textContent = previous; }, 1500);
    } catch (e) { /* copia non disponibile: resta il testo selezionabile */ }
  });

  // Conferma prima di azioni delicate.
  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});

Alpine.start();
