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

  // Filtri del catalogo: aperti di default (senza JS si vedono sempre), chiusi su mobile se non ce ne sono di attivi.
  document.querySelectorAll('details[data-collapse-mobile="1"]').forEach((details) => {
    if (window.matchMedia('(max-width: 1023px)').matches) details.removeAttribute('open');
  });
  window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
    if (event.matches) document.querySelectorAll('details.filters').forEach((d) => d.setAttribute('open', ''));
  });

  // Moduli che si inviano da soli al cambio (es. ordinamento del catalogo).
  document.addEventListener('change', (event) => {
    const form = event.target.closest('form[data-autosubmit]');
    if (form) form.submit();
  });

  // Scheda rapida in una finestra; senza JavaScript il link apre la pagina del prodotto.
  const dialog = document.getElementById('quickview');
  if (dialog && typeof dialog.showModal === 'function') {
    const body = dialog.querySelector('[data-quickview-body]');
    document.addEventListener('click', async (event) => {
      const link = event.target.closest('a[data-quickview]');
      if (!link || event.ctrlKey || event.metaKey || event.shiftKey) return;
      event.preventDefault();
      body.innerHTML = '<p class="p-6 text-sm">…</p>';
      dialog.showModal();
      try {
        const url = link.href + (link.href.includes('?') ? '&' : '?') + 'partial=1';
        const response = await fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(String(response.status));
        body.innerHTML = await response.text();
        document.dispatchEvent(new CustomEvent('quickview:loaded', { detail: body }));
      } catch (e) {
        window.location.href = link.href;
      }
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog || event.target.closest('[data-dialog-close]')) dialog.close();
    });
  } else {
    document.addEventListener('click', (event) => {
      if (event.target.closest('[data-dialog-close]')) window.history.back();
    });
  }

  // "Carica altri": aggiunge le card della pagina successiva senza ricaricare.
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('a[data-load-more]');
    if (!button) return;
    event.preventDefault();
    const grid = document.getElementById('product-grid');
    try {
      const response = await fetch(button.dataset.loadMore, { credentials: 'same-origin' });
      if (!response.ok) throw new Error(String(response.status));
      grid.insertAdjacentHTML('beforeend', await response.text());
      const next = new URL(button.href, window.location.href);
      const page = Number(next.searchParams.get('page') || '2');
      window.history.replaceState(null, '', button.href);
      next.searchParams.set('page', String(page + 1));
      const total = Number(grid.dataset.total || '0');
      if (total && grid.children.length >= total) {
        button.remove();
      } else {
        button.href = next.toString();
        next.searchParams.set('partial', '1');
        button.dataset.loadMore = next.toString();
      }
    } catch (e) {
      window.location.href = button.href;
    }
  });

  // Conferma prima di azioni delicate (bottone con formaction, es. "Elimina").
  document.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-confirm-click]');
    if (button && !window.confirm(button.dataset.confirmClick)) event.preventDefault();
  });

  // Conferma prima di azioni delicate.
  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});

Alpine.start();
