# 00 — Registro delle decisioni

Ogni decisione del titolare (o presa per lui come default, e segnata così) ha
una voce: **cosa** si è deciso, **perché**, **dove vive** (impostazione,
variabile `.env`, file) e **come si torna indietro**. Prima di cambiare un
comportamento, cerca qui se c'è una decisione che lo riguarda; se la cambi,
non cancellare la voce: aggiungine una nuova che la sostituisce e marca la
vecchia "superata da D-xxx".

Formato:

```
### D-xxx · gg/mm/aaaa · Titolo
- Decisione:
- Perché:
- Dove vive:
- Come si torna indietro:
```

---

### D-001 · 12/11/2026 · Piattaforma dedicata a WTB, separata da scs-b2b
- Decisione: nuovo repository `scs-b2b-wtb`, nuova installazione con il suo
  database e il suo dominio. Nessun codice condiviso a runtime con scs-b2b.
- Perché: i due fornitori hanno prodotti in comune (stessi SKU) e regole
  diverse su prezzi, IVA e spedizione; tenerli separati evita conflitti e non
  mette a rischio la piattaforma GoldenSneakers in produzione.
- Dove vive: questo repository.
- Come si torna indietro: per un futuro catalogo unico, il fornitore è già
  isolato in `src/Supplier/` (docs/02).

### D-002 · 12/11/2026 · scs-b2b come specifica funzionale, non come codice
- Decisione: le funzionalità di scs-b2b sono il riferimento (vedi docs/07); il
  codice si può riscrivere da zero o riprendere dove conviene, adattandolo.
- Perché: richiesta del titolare; l'API WTB cambia il cuore del modello.
- Dove vive: docs/07.
- Come si torna indietro: n/a.

### D-003 · 12/11/2026 · I clienti pagano solo con bonifico
- Decisione: la richiesta d'ordine riceve le istruzioni di bonifico; l'ordine
  si conferma all'arrivo del pagamento (conferma manuale dell'admin).
- Perché: canale unico scelto dal titolare.
- Dove vive: docs/06; coordinate bancarie `BANK_*` in `.env`.
- Come si torna indietro: altri metodi richiedono una nuova decisione e un
  nuovo modulo di pagamento.

### D-004 · 12/11/2026 · Pagamento a WTB con bonifico
- Decisione: gli ordini a WTB si creano con `payment_method: bank_transfer`
  (pro-forma WTB con IBAN, causale e importo da saldare).
- Perché: scelta del titolare.
- Dove vive: `WTB_PAYMENT_METHOD=bank_transfer` in `.env`.
- Come si torna indietro: `WTB_PAYMENT_METHOD=balance` (credito prepagato,
  scalato alla creazione).

### D-005 · 12/11/2026 · In vendita sia pronta consegna sia stock esteso
- Decisione: si vendono le paia `immediate` (partenza 1-2 gg) e `extended`
  (partenza 3-5 gg), con prezzo a fasce (docs/04).
- Perché: scelta del titolare.
- Dove vive: impostazione `catalog.sell_extended` (default: sì).
- Come si torna indietro: spegnere l'impostazione (si vende solo `immediate`).

### D-006 · 12/11/2026 · Spedizione al cliente a tariffa fissa per zona
- Decisione: una tariffa fissa per ordine in base alla zona di destinazione
  (Italia, resto UE, UK/CH); valori iniziali tarati in M0 sulle tariffe reali
  di WTB e confermati dal titolare. Il costo vero WTB di ogni ordine è
  visibile in admin. *(Regola scelta come default su indicazione del
  titolare: "una tariffa fissa standard, scegli tu".)*
- Perché: semplice da comunicare; il confronto incassato/costo in admin dice
  quando ritoccarla.
- Dove vive: `/admin/spedizione` (tabella `shipping_zones`), docs/05.
- Come si torna indietro: cambiare le tariffe o attivare la soglia di
  gratuità per zona dall'admin; una tariffa calcolata (costo WTB + ricarico)
  richiede una nuova decisione.

### D-007 · 12/11/2026 · IVA progettata da zero, per regime
- Decisione: ogni riga porta il suo regime (`margin` o `ordinary`); regole e
  diciture di default in docs/04. Nessun riferimento alla gestione IVA di
  scs-b2b.
- Perché: indicazione del titolare ("qui è tutto nuovo"); WTB vende in due
  regimi diversi.
- Dove vive: `TaxPolicy` (unico modulo), impostazioni `tax.*`, testi in `lang/`.
- Come si torna indietro: ogni regola è isolata; **prima del live va validata
  dal commercialista** (docs/10).

### D-008 · 12/11/2026 · Passaggio chiave di prova → chiave reale con una riga
- Decisione: `WTB_MODE=fixture|test|live`, con `WTB_API_KEY_TEST` e
  `WTB_API_KEY_LIVE` entrambe nel `.env` del server.
- Perché: il titolare ha entrambe le chiavi e vuole passare dall'una all'altra
  facilmente.
- Dove vive: `.env`; banner della modalità in tutta l'area admin.
- Come si torna indietro: rimettere `WTB_MODE=test`.

### D-009 · 12/11/2026 · Ordine a WTB alla conferma del pagamento *(default)*
- Decisione: l'ordine presso WTB parte quando l'admin conferma che il bonifico
  del cliente è arrivato.
- Perché: con il bonifico a WTB e senza un endpoint di annullamento, è il
  modo che non lascia mai ordini a WTB per clienti che non pagano.
- Dove vive: `WTB_ORDER_TRIGGER=on_confirm` in `.env`.
- Come si torna indietro: `on_request` (ordine a WTB già alla richiesta del
  cliente, blocca subito lo stock) quando WTB conferma che gli ordini a
  bonifico non pagati scadono senza penali; `manual` per crearli solo a mano.

### D-010 · 12/11/2026 · SQLite in tutti gli ambienti *(default)*
- Decisione: un solo database SQLite (modalità WAL) in sviluppo, test e
  produzione.
- Perché: un solo dialetto SQL (niente schema duplicato tra test e
  produzione), test e verifiche veloci nelle sessioni Claude Code senza server
  di database, backup come copia di un file; il carico di un catalogo B2B è
  modesto.
- Dove vive: `DB_PATH` in `.env`, docs/02.
- Come si torna indietro: l'accesso è tutto via PDO e le migrazioni sono SQL
  semplice: passare a MySQL o PostgreSQL è una nuova decisione con migrazione
  dei dati.

### D-011 · 12/11/2026 · Immagini servite dal nostro dominio *(default)*
- Decisione: le immagini dei prodotti passano da un proxy con cache locale
  (`/img/...`), mai collegate direttamente all'host del fornitore.
- Perché: il fornitore resta invisibile (regola d'oro 2), funziona anche se
  le immagini WTB richiedono la chiave, CSP semplice (`img-src 'self'`).
- Dove vive: servizio immagini (docs/03), impostazione `images.proxy`.
- Come si torna indietro: spegnere l'impostazione (link diretti; sconsigliato).

### D-012 · 12/11/2026 · Il fornitore non si nomina al cliente *(default)*
- Decisione: white-label completo lato cliente (pagine, email, PDF, export,
  nomi file, URL).
- Perché: i clienti sono rivenditori; nominare il fornitore li porterebbe a
  comprare direttamente da lui.
- Dove vive: CLAUDE.md regola d'oro 2, test "cost leak" esteso alla parola
  "WTB".
- Come si torna indietro: nuova decisione del titolare.

### D-013 · 12/11/2026 · Perimetro della v1 *(default)*
- Decisione: in v1 sito pubblico, vetrina senza prezzi, richiesta di
  profilo, account con invito, catalogo, carrello, checkout con bonifico,
  pro-forma PDF, ordini WTB con webhook e tracking, admin. Rinviati: prodotti
  propri, pro-forma manuali, accesso ospite con password condivisa, verifica
  VIES automatica.
- Perché: prima la base solida, poi le aggiunte che il titolare chiederà.
- Dove vive: docs/07, docs/10.
- Come si torna indietro: ogni funzione rinviata è una milestone a sé.

### D-014 · 12/11/2026 · Valori di partenza ripresi da scs-b2b *(default)*
- Decisione: ordine minimo 5 paia; margine di default 5%; arrotondamento del
  prezzo di vendita all'euro intero per eccesso; lingue IT (default) ed EN;
  partita IVA obbligatoria per i clienti; paesi serviti UE-27 + Regno Unito e
  Svizzera.
- Perché: sono le scelte già fatte dal titolare sull'altra piattaforma, come
  punto di partenza.
- Dove vive: impostazioni da admin (`orders.min_items`, margine di default,
  `prices.rounding`), tabella `countries`.
- Come si torna indietro: dall'admin.

### D-015 · 12/11/2026 · Serie delle pro-forma separata *(default)*
- Decisione: numerazione `PFW-<anno>-<NNNN>`, distinta dalla serie `PF` di
  scs-b2b.
- Perché: evitare due documenti con lo stesso numero dallo stesso titolare.
- Dove vive: `RECEIPT_PREFIX=PFW` in `.env`, tabella `receipt_counters`.
- Come si torna indietro: cambiare il prefisso (vale per i nuovi numeri).

### D-016 · 12/11/2026 · Repository pubblico
- Decisione: il repository GitHub resta pubblico. Di conseguenza nei file
  versionati non entrano mai dati commerciali reali (CLAUDE.md, regola d'oro
  4): le fixture hanno valori inventati, le risposte reali di WTB restano in
  `var/wtb-captures/` (ignorata da git), i numeri reali (costi, tariffe WTB,
  margini) si comunicano al titolare in chat e vivono nel database.
- Perché: scelta del titolare. Chiunque può leggere il codice e i documenti,
  compreso il nome del fornitore: la regola tiene almeno fuori costi e
  condizioni commerciali.
- Dove vive: CLAUDE.md regola d'oro 4, `.gitignore`, docs/03 § Fixture.
- Come si torna indietro: rendere il repository privato dalle impostazioni
  GitHub; la regola sui dati reali conviene mantenerla comunque (ciò che è
  stato pubblicato può essere già stato copiato).
