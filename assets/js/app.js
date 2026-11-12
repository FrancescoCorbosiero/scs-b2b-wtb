// Miglioramenti progressivi: tutto funziona anche senza JavaScript.
// Alpine nella build CSP (niente eval): i componenti si registrano con Alpine.data.
import Alpine from '@alpinejs/csp';

window.Alpine = Alpine;

const locale = document.documentElement.lang === 'en' ? 'en-IE' : 'it-IT';
const money = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' });
const euros = (cents) => money.format(cents / 100);

// Ripensamento (docs/06): conto alla rovescia con "Annulla" prima dell'invio reale della richiesta d'ordine.
Alpine.data('rethink', () => ({
  seconds: 0,
  total: 0,
  active: false,
  timer: null,
  init() {
    this.total = Number(this.$el.dataset.rethink || '0');
  },
  start(event) {
    if (this.total <= 0 || this.active) return;
    event.preventDefault();
    this.seconds = this.total;
    this.active = true;
    this.timer = setInterval(() => {
      this.seconds -= 1;
      if (this.seconds <= 0) {
        clearInterval(this.timer);
        this.$el.submit();
      }
    }, 1000);
  },
  cancel() {
    clearInterval(this.timer);
    this.active = false;
  },
}));

// Fasce lato client (stessa regola di TierCalculator): prima pronta consegna, poi stock esteso.
function split(tiers, qty) {
  const ordered = [...tiers].sort((a, b) => (a.s === 'immediate' ? 0 : 1) - (b.s === 'immediate' ? 0 : 1));
  let left = qty;
  const lines = [];
  for (const tier of ordered) {
    if (left <= 0) break;
    const take = Math.min(left, tier.a);
    if (take > 0) lines.push({ qty: take, price: tier.p });
    left -= take;
  }
  return lines;
}

function refreshQtyForm(form) {
  let pairs = 0;
  let total = 0;
  form.querySelectorAll('input[data-unit]').forEach((input) => {
    const max = Number(input.max || '0');
    let qty = Math.max(0, Math.min(max, Math.floor(Number(input.value || '0'))));
    if (String(qty) !== input.value) input.value = String(qty);
    const tiers = JSON.parse(input.dataset.tiers || '[]');
    const lines = split(tiers, qty);
    const text = lines.map((l) => `${l.qty} × ${euros(l.price)}`).join(' + ');
    const out = input.closest('td, div')?.parentElement?.querySelector('[data-unit-split]');
    if (out) out.textContent = text;
    pairs += qty;
    total += lines.reduce((sum, l) => sum + l.qty * l.price, 0);
  });
  const pairsOut = form.querySelector('[data-form-pairs]');
  const totalOut = form.querySelector('[data-form-total]');
  if (pairsOut) pairsOut.textContent = String(pairs);
  if (totalOut) totalOut.textContent = pairs > 0 ? euros(total) : '—';
}

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

  // Quantità con +/− e totale live per fascia.
  document.querySelectorAll('form[data-qty-form]').forEach(refreshQtyForm);
  document.addEventListener('quickview:loaded', (event) => {
    event.detail.querySelectorAll('form[data-qty-form]').forEach(refreshQtyForm);
  });
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-step]');
    if (!button) return;
    const input = button.closest('[data-stepper]')?.querySelector('input');
    if (!input) return;
    input.value = String(Math.max(0, Math.min(Number(input.max || '0'), Number(input.value || '0') + Number(button.dataset.step))));
    refreshQtyForm(input.form);
  });
  document.addEventListener('input', (event) => {
    if (event.target.matches('input[data-unit]')) refreshQtyForm(event.target.form);
  });

  // "Aggiungi al carrello" dalla scheda rapida senza lasciare la pagina.
  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('#quickview form[action="/carrello/aggiungi"]');
    if (!form) return;
    event.preventDefault();
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json', 'X-CSRF-Token': form.querySelector('input[name="_csrf"]').value },
        credentials: 'same-origin',
      });
      if (!response.ok) throw new Error(String(response.status));
      const data = await response.json();
      const link = document.querySelector('[data-cart-link]');
      if (link) {
        let badge = link.querySelector('[data-cart-count]');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'badge badge-ink';
          badge.dataset.cartCount = '';
          link.append(' ', badge);
        }
        badge.textContent = String(data.cart.pairs);
      }
      const note = document.createElement('p');
      note.className = 'alert alert-success mt-3';
      note.textContent = data.message;
      form.append(note);
      setTimeout(() => { document.getElementById('quickview')?.close(); }, 900);
    } catch (e) {
      form.submit();
    }
  });

  // Campi che aggiornano il riepilogo appena cambiano (es. paese di destinazione nel carrello).
  document.addEventListener('change', (event) => {
    if (event.target.matches('[data-autosubmit-field]')) event.target.form.submit();
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
