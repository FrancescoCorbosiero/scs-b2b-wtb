# 07 — Pagine e funzionalità

Riferimento: le funzionalità di scs-b2b (D-002), adattate all'API WTB.
Mobile-first, IT/EN con selettore in header (default IT), area admin solo in
italiano. Convenzioni riprese da scs-b2b: righe di tabella interamente
cliccabili quando aprono un dettaglio; componenti "Azzera / Ripristina" dove
si configurano filtri o impostazioni; tutto funziona anche senza JavaScript
(Alpine migliora, non è indispensabile).

## Sito pubblico (senza login, indicizzabile)

Pagine di presentazione: **mai prodotti, foto prodotto o prezzi**.

- `/` — home: proposta di valore, "Sfoglia il catalogo" (vetrina), "Richiedi
  accesso", come funziona in 5 passi, spedizioni e tempi per zona (dai valori
  reali), FAQ.
- `/come-ordinare` — i passi: profilo → richiesta d'ordine → bonifico →
  conferma → spedizione con tracking.
- `/spedizioni` — zone, tariffe, tempi di partenza e transito, dazi extra-UE,
  letti da `shipping_zones` e dalle impostazioni.
- `/contatti` — recapiti, WhatsApp, coordinate per il bonifico (`BANK_*`).
- `/richiedi-accesso` — vedi sotto.

## `/vetrina` — catalogo senza prezzi (`noindex`)

Lo stesso catalogo di `/catalogo` (stessi template e filtri) aperto a tutti,
ma **senza prezzi**: tolti dai **dati** lato server (card, JSON della scheda
rapida, frammenti "carica altri"), non nascosti col CSS. Filtri e
ordinamenti per prezzo ignorati lato server; niente carrello né export. Al
posto del prezzo: "Riservato ai rivenditori" con invito ad accedere o a
chiedere il profilo. Chi ha la sessione viene portato su `/catalogo` con gli
stessi filtri.

## Account

- **`/richiedi-accesso`**: ragione sociale, **partita IVA (obbligatoria)**,
  indirizzo completo con provincia, paese (solo paesi serviti), referente
  (nome, email, telefono), lingua, note. CSRF, honeypot, max 3/ora per IP,
  blocco dei doppioni. Email all'admin e conferma di ricezione al cliente.
- **Approvazione** (`/admin/richieste-profilo`): "Approva" crea l'account con
  i dati della richiesta e manda l'invito; "Rifiuta" archivia.
- **Invito**: link monouso valido 72 ore per impostare la password su
  `/account/imposta-password` (la password non viaggia mai per email).
- **`/login`** con email e password; `/password-dimenticata` con risposta
  neutra.
- **`/account`**: profilo (precompila il checkout), cambio password,
  **`/account/ordini`** con stato, tracking e pro-forma PDF degli ordini
  confermati.

## `/catalogo`

**Card prodotto**: immagine (dal nostro dominio), pillola disponibilità
(alta/media/bassa), SKU copiabile, nome, brand, taglie con disponibilità, prezzo
"da X €" con l'etichetta del regime, badge "Pronta consegna" se c'è stock
`immediate`, CTA "Scegli taglie".

**Scheda rapida**: per ogni taglia una riga per regime in vendita con le
fasce (paia e prezzo in pronta consegna, paia e prezzo in stock esteso, tempi
di partenza), stepper quantità che non supera il disponibile, totale live con
il dettaglio per fascia, "Aggiungi al carrello" senza lasciare la pagina.

**Filtri** (tutti nella query string, URL condivisibili): brand, categoria
(albero `category_path`), linea, genere, categoria di taglia (adulto/GS/PS),
taglie, regime IVA, solo pronta consegna, prezzo min–max, disponibilità, solo
disponibili. **Ricerca** per nome, SKU o EAN. **Ordinamento**: rilevanza,
nome, prezzo ↑↓, disponibilità, novità. Paginazione con "Carica altri".
Taglie in EU (default), US o UK; densità griglia; scelte salvate in sessione.

**Esauriti**: restano visibili, marcati, in fondo alla griglia, senza CTA;
"Solo disponibili" li toglie.

**Export Excel** del risultato filtrato: SKU, nome, brand, linea, genere,
taglie EU/US/UK, EAN, regime, paia e prezzo in pronta consegna, paia e prezzo
in stock esteso, tempi di partenza. **Mai costi**, mai il nome del fornitore
(nemmeno nel nome del file).

## `/carrello`

Per ogni prodotto una tabella taglie × regimi con fasce, prezzi, stock e
quantità; "Prendi tutto" e "Svuota" per prodotto. Riepilogo laterale: paia
totali, subtotali per regime, spedizione (zona di destinazione scelta nel
riepilogo, precompilata dal profilo), IVA stimata per il paese di
fatturazione, totale da bonificare, consegna stimata, avviso di ordine minimo
con CTA disattivata sotto soglia. Calcoli autorevoli sempre lato server; lo
stock si ricontrolla a ogni apertura e all'invio. Carrello in sessione.

## Richiesta d'ordine

Vedi docs/06 § Checkout: preventivo WTB, ripensamento, email con istruzioni
di bonifico, pagina di conferma con i recapiti.

## Area admin (`/admin`, password dedicata, solo italiano)

Banner permanente con la **modalità WTB** (`FIXTURE` / `TEST` / `LIVE`).

- **Dashboard**: ordini in attesa di bonifico, ordini da pagare a WTB, saldo
  spedizioni 30 giorni, stato degli ultimi sync, avvisi (chiave WTB
  rifiutata, sync bloccato per calo prodotti, webhook sconosciuti, ordini
  `quote_unverified`, ordini WTB in errore).
- **Ordini** (`/admin/ordini`): elenco con filtri per stato e ricerca;
  dettaglio con righe (vendita, costo, margine), totali, spedizione incassata
  vs costo WTB, preventivo, azioni (conferma, riallinea, crea/ritenta ordine
  WTB, annulla, segna spedito), ordine WTB collegato con istruzioni di
  pagamento, stati, tracking ed eventi webhook.
- **Ordini WTB** (`/admin/ordini-fornitore`): elenco dall'API
  (`GET /orders/`) collegato agli ordini della piattaforma, dettaglio live.
- **Clienti** (`/admin/clienti`): crea con invito, reinvia invito, reset,
  disattiva, export Excel (mai hash o token).
- **Richieste di profilo** (`/admin/richieste-profilo`).
- **Margini** (`/admin/margini`): regole (docs/04) con priorità, attiva/
  disattiva, prodotti toccati, esempio di prezzo; ogni modifica fa il reprice.
- **Spedizione** (`/admin/spedizione`): zone, paesi, tariffe, soglie, tempi
  di transito; tariffe WTB correnti a confronto.
- **Impostazioni** (`/admin/impostazioni`): generata dal registro (docs/02),
  con storico delle modifiche e "Ripristina default".
- **Sync** (`/admin/sync`): ultimi job con esito e conteggi, "Sincronizza ora"
  (catalogo, stock), profilo WTB e fotografia delle tariffe.
- **Webhook** (`/admin/webhook`): eventi ricevuti con esito; "Simula evento"
  solo nelle modalità `fixture` e `test`.
- **In evidenza**: flag manuale per SKU, mostrato come badge e filtro.
