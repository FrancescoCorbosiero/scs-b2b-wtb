# 03 — Fornitore WTB: modalità, client, sync

Fonte: `docs/wtb-api.md`. Base URL `https://b2b.wtbsnkrs.it/api/v1`, solo
JSON, autenticazione con intestazione `X-API-Key`, limite **120 richieste al
minuto per chiave** (HTTP 429 oltre). Tutto ciò che sta in questo documento
vive in `src/Supplier/Wtb/` e nei job di sync.

## Modalità e chiavi

| Variabile `.env` | Default | Note |
|---|---|---|
| `WTB_MODE` | `fixture` | `fixture` / `test` / `live` (tabella in CLAUDE.md) |
| `WTB_BASE_URL` | `https://b2b.wtbsnkrs.it/api/v1` | unica destinazione a cui parte la chiave |
| `WTB_API_KEY_TEST` | vuota | chiave di prova `wtb_test_…` |
| `WTB_API_KEY_LIVE` | vuota | chiave reale: **solo** nel `.env` di produzione |
| `WTB_WEBHOOK_SECRET` | vuota | `whsec_…` dal portale WTB (mostrato una volta) |
| `WTB_HTTP_TIMEOUT` | 20 | secondi |
| `WTB_CATALOG_ALL` | 0 | 1 = aggiunge `all=1` (ignora "Solo la mia selezione" del portale) |
| `WTB_SYNC_MAX_DROP_PERCENT` | 50 | il sync completo si ferma se i prodotti attivi calerebbero oltre questa soglia |

- La chiave si sceglie in base alla modalità e viaggia solo verso
  `WTB_BASE_URL`; i log la oscurano sempre.
- In `APP_ENV=development` con la chiave della modalità vuota il client non
  manda l'intestazione: la aggiunge il proxy di Claude Code web (docs/02). In
  produzione una chiave vuota blocca sync e ordini con un errore chiaro.
- Una risposta 401 (chiave errata) mette un avviso in dashboard che resta
  finché una chiamata non torna a riuscire; i job riprovano al loro
  intervallo, senza insistere nel frattempo.

## Client HTTP

- `User-Agent` identificativo, `Accept: application/json`, timeout da `.env`.
- **Limite**: al massimo ~100 richieste al minuto per processo (margine sotto
  120). Su 429: attesa di `Retry-After` se presente (M0: c'è, in secondi),
  altrimenti 30 s, poi nuovo tentativo (max 3). Durante una richiesta del
  cliente (preventivo al checkout) non si aspetta più di 5 s: oltre, si
  rinuncia e vale il solo controllo locale (docs/06).
- **Letture e preventivi** (`GET`, `POST /quote/`): fino a 2 nuovi tentativi
  con backoff su errori di rete e 5xx.
- **Creazione ordine** (`POST /orders/`): i nuovi tentativi sono sicuri perché
  `reference` rende la chiamata idempotente (risposta 200 = ordine già creato
  con quel reference). Stesso corpo, stesso reference, max 3 tentativi.
- Errori tipizzati: 400 (dati non validi, ordine minimo, paia non disponibili:
  il corpo elenca in `stock` cosa manca), 401, 403, 404, 429, 5xx.
- Mai seguire redirect verso altri host con la chiave.

## Endpoint usati

| Endpoint | Dove si usa |
|---|---|
| `GET /products/` | sync completo del catalogo (`page_size=100`, tutte le pagine fino a `has_more=false`) |
| `GET /products/?sku=A,B,C` | import mirato di SKU comparsi nello stock ma non ancora in catalogo |
| `GET /stock/` | sync rapido di disponibilità e costi |
| `GET /shipping/` | profilo WTB (Retailer/Wholesaler), zone e tariffe, ordine minimo: a ogni sync completo |
| `POST /quote/` | verifica al checkout e prima di creare l'ordine (disponibilità, fasce, costo reale) |
| `POST /orders/` | creazione dell'ordine (docs/06) |
| `GET /orders/?reference=…`, `GET /orders/<id>/` | dettaglio in admin e rete di sicurezza ai webhook |
| webhook in arrivo | stati, pagamento, tracking (docs/06) |

## Mappatura del catalogo

| WTB | Modello interno | Note |
|---|---|---|
| `sku` | `products.sku` | chiave naturale, unica |
| `name`, `brand`, `category`, `description` | `products.*` | descrizione come testo semplice |
| `line`, `gender`, `category_path` | `products.line`, `.gender`, `.category_path` (JSON) | alimentano filtri e regole margine |
| `images[]` | `product_images` (URL sorgente, ordine) | servite tramite proxy (§ Immagini) |
| `sizes[].variant_id` | `variants.wtb_variant_id` | chiave dell'ordine |
| `sizes[].size_eu/us/uk`, `ean` | `variants.*` | taglie sempre **stringhe** (es. `38.5`, `5.5Y`) |
| `offers[]` | `offers` (variante × regime × tipo di stock) | `price` → `cost_cents` (riservato) |
| `offers[].ships_in_days` | `offers.ships_in_days` | testo, es. `1-2` |
| `content`, `images_formatted` | — | solo profilo Retailer; non usati in v1 |
| `total_available` | — | ricalcolato da noi |

- I prezzi arrivano come stringhe (`"63.84"`): si convertono in centesimi
  senza passare da float.
- `currency` diversa da `EUR` ⇒ sync annullato.
- Regime o tipo di stock sconosciuti ⇒ offerta scartata con avviso nel log
  del job (mai un'eccezione che blocca tutto il catalogo per una riga).

## Sync completo (`products_sync`)

1. `GET /shipping/` → salva profilo, zone, tariffe e ordine minimo WTB
   (fotografia in `wtb_snapshots`, mostrata in `/admin/spedizione`).
2. Scarica **tutte** le pagine di `/products/` seguendo solo `has_more` (M0:
   `count` non è affidabile e le pagine possono essere corte) e valida tutto
   in memoria. Qualsiasi errore HTTP o di formato ⇒ nessuna scrittura, job in
   errore.
3. Se i prodotti del feed calerebbero oltre `WTB_SYNC_MAX_DROP_PERCENT`
   rispetto al feed precedente ⇒ stop e avviso in dashboard (un admin può
   forzare dal pannello sync).
4. Una transazione: upsert di prodotti, varianti e offerte. Prodotti assenti
   dal feed (M0: WTB toglie dal feed anche gli esauriti) ⇒ offerte a 0, il
   prodotto resta visibile come **esaurito** per `catalog.sold_out_days`
   giorni dall'ultima volta che è comparso, poi `is_active=0` (mai
   cancellati: gli ordini passati li citano) (D-017). Varianti assenti da un
   prodotto presente ⇒ inattive; offerte assenti ⇒ disponibilità 0.
5. Ricalcolo di categoria di taglia, totali, prezzi di vendita (reprice).

## Sync rapido (`stock_sync`)

1. `GET /stock/` (M0: non paginato, sempre tutte le righe).
2. Aggiorna le offerte per (variante, regime, tipo di stock): disponibilità,
   costo, tempi di partenza. Solo se il payload è completo, le offerte non
   presenti delle varianti note vanno a 0.
3. Varianti sconosciute ⇒ import mirato con `GET /products/?sku=…`; se
   fallisce, ci pensa il prossimo sync completo.
4. Reprice delle sole offerte cambiate. Se l'impronta (hash) del payload è
   uguale a quella del giro precedente, il job finisce subito (M0:
   `updated_at` è l'ora della risposta e cambia sempre).

## Categoria di taglia

- `gender` = `men` / `women` / `unisex` ⇒ adulto.
- `gender` = `kids` ⇒ prima la sigla nel nome (`(GS)` ⇒ GS; `(PS)` o `(TD)`
  ⇒ PS), poi il suffisso delle taglie US (`Y` ⇒ GS, `C` ⇒ PS, es. `5.5Y`,
  `10.5C`); senza alcun segnale ⇒ GS.
- M0: tutti i prodotti `kids` osservati hanno `(GS)` nel nome, ma non tutti
  hanno taglie US con `Y`: per questo la sigla nel nome viene prima. Il
  risultato alimenta filtro catalogo e regole margine.

## Immagini (D-011)

- In HTML le immagini sono sempre URL nostri (`/img/<id-prodotto>/<n>-<w>.jpg`).
- Il proxy serve dalla cache in `var/images/`; se manca il file, lo scarica
  dalla sorgente (solo `https`, solo host della whitelist ricavata in M0, max
  5 MB, solo `image/*`), crea le miniature (400/800 px se GD è disponibile) e
  lo serve con cache lunga. In errore: segnaposto.
- La chiave WTB si allega al download solo se l'host dell'immagine è quello
  dell'API e `WTB_IMAGE_SEND_KEY=1`; M0 ha dimostrato che non serve (default 0).
- Dopo ogni sync un job scarica in anticipo le immagini dei prodotti nuovi.

## Fixture e modalità test

- **fixture**: un trasporto finto legge `fixtures/wtb/` (`products-page-1.json`,
  `products-page-2.json`, `products-extra.json`, `stock.json`,
  `shipping.json`, `quote.json`, `order-created.json`, `order-test.json`,
  `order-detail.json`, `orders-list.json`, `webhooks/*.json`, `errors/*.json`,
  `images/*.jpg`; dettagli in `fixtures/wtb/README.md`). Preventivi e ordini
  in modalità fixture sono calcolati da un piccolo simulatore con le stesse
  regole osservate in M0 (ripartizione, spedizione, IVA, errori). Le fixture partono dagli esempi di
  `docs/wtb-api.md`; dopo M0 riproducono la **forma** esatta delle risposte
  reali (campi, tipi, paginazione, casi limite) ma con **valori inventati**:
  prezzi, disponibilità, tariffe, ordine minimo, numeri d'ordine (D-016,
  repository pubblico). Circa 10 prodotti, scelti per coprire entrambi i
  regimi, entrambi i tipi di stock, taglie bambino e un esaurito. Mai chiavi,
  dati personali o valori commerciali reali.
- **Catture reali**: le risposte vere scaricate in sviluppo si salvano solo in
  `var/wtb-captures/` (ignorata da git) e servono a costruire le fixture; non
  si committano mai.
- **test**: API vera. `POST /orders/` viene **simulato da WTB** (`"test": true`,
  `"number": "TEST"`, nulla salvato, nessuna paia riservata, nessuna notifica):
  l'ordine al fornitore si registra con `mode=test` e stato `simulated`, non
  si rilegge mai e non riceve webhook.
- In `fixture` e `test` l'admin ha "Simula evento": costruisce un webhook
  firmato col segreto di sviluppo e lo manda al nostro endpoint, per provare
  tutto il percorso (anche da CLI: `php bin/wtb-webhook.php <evento> <ordine>`).

## Ricognizione M0 (con la chiave di prova)

Prima di scrivere codice applicativo, eseguire e annotare in § Esiti M0:

1. `GET /shipping/`: profilo, zone, tariffe, ordine minimo,
   `extended_stock_transport`.
2. `GET /products/?page_size=5`: campi reali, presenza di `content` e
   `images_formatted`, host delle immagini; un'immagine si scarica **senza**
   chiave?
3. `GET /stock/`: paginato? Le offerte a 0 compaiono? Cosa indica `updated_at`?
4. `POST /quote/` con `variant_id` + `vat_regime` + quantità oltre la pronta
   consegna: la combinazione è accettata? Come divide le righe per tipo di
   stock? Come calcola `extended_stock_transport`?
5. `POST /orders/` (chiave di prova, bonifico): forma completa della risposta,
   incluse `payment_instructions`.
6. Una risposta 429: c'è `Retry-After`?
7. Formati reali: taglie (frazioni EU, suffissi US), regimi e generi presenti.

## Esiti M0

Ricognizione del 12/11/2026 con la chiave di prova (iniettata dal proxy di
Claude Code web). Qui solo forma e comportamenti: i valori commerciali
(costi, tariffe, disponibilità) sono stati riferiti al titolare in chat e le
risposte vere stanno solo in `var/wtb-captures/` (fuori da git).

**Accesso**
- API raggiungibile; senza chiave risponde 401. Risposte dietro Cloudflare,
  `Content-Language: it`, nessuna intestazione sul limite nelle risposte 200.
- In Claude Code web il proxy **sostituisce sempre** `X-API-Key`: anche una
  chiave sbagliata inviata dal client diventa quella di prova. I casi 401 si
  provano quindi solo con le fixture.

**`GET /shipping/`**
- Campi: `profile` (il nostro account è `retail`), `currency` (non
  documentato), `zones[]` (`name`, `countries`, `first_piece`,
  `each_additional_piece`, `failed_delivery_fee`, `duties_paid_by_recipient`),
  `extended_stock_transport` con `by_pieces` (una voce per ogni numero di paia
  fino alla soglia) e `free_from_pieces`.
- Cinque zone WTB: Italia (con San Marino e Vaticano), tre zone UE con
  tariffe diverse, una zona extra UE (CH, LI, GB, NO) con
  `duties_paid_by_recipient: true`. Coprono tutti i paesi che serviamo, ma
  non coincidono con le nostre tre zone: la UE è divisa in tre fasce.
- Profilo Retailer: nessun ordine minimo nella risposta (esiste solo per
  Wholesaler; la forma di quel campo non è osservabile con il nostro profilo).

**`GET /products/`**
- Campi come da documentazione; `content` e `images_formatted` assenti
  (schede Retailer non attive). `page_size` di default 50; `all=1` per ora
  non cambia nulla.
- `count` conta anche prodotti che poi non vengono consegnati: i prodotti
  senza paia disponibili sono tolti dalla pagina **dopo** la paginazione, per
  cui una pagina può essere più corta di `page_size` anche con
  `has_more: true`. Il sync segue solo `has_more`.
- Un prodotto esaurito **sparisce** da `/products/` e da `/products/<sku>/`
  (404 `"Product not found or not available."`): "assente dal feed" vuol dire
  anche "esaurito", non solo "tolto dal catalogo" (vedi § Sync completo e
  D-017).
- `?sku=A,B` funziona; gli SKU sconosciuti sono ignorati senza errore.
- `line` può essere una stringa vuota. `category_path` =
  `["Sneakers", brand, linea (se c'è), genere in italiano]` (es. "Unisex",
  "Bambino", "Donna"). `description` è testo semplice e breve, anche vuoto.
- Generi presenti: `kids`, `unisex`, `women` (`men` è documentato ma al
  momento assente).
- Taglie sempre stringhe: EU con frazioni scritte `36 2/3`, `37 1/3` (spazio e
  barra: vanno codificate negli URL e ordinate come numeri); US con suffissi
  `Y` e `W` e a volte vuote; UK a volte vuote; `ean` al momento sempre
  stringa vuota.
- Ogni taglia ha una o due offerte; al momento nessuna taglia ha entrambi i
  regimi e le offerte `ordinary` sono solo `immediate`. Il costo della pronta
  consegna e quello dello stock esteso possono coincidere.
- Bambino: il suffisso `Y` non basta (alcuni prodotti GS hanno taglie US senza
  `Y`); la sigla `(GS)` nel nome c'è in tutti i prodotti `kids` osservati.
  Nessun PS (suffisso `C`) osservato.
- `total_available` = somma delle offerte del prodotto.

**Immagini**
- Stesso host dell'API (`b2b.wtbsnkrs.it`), percorso `/media/models/<hash>.jpg`,
  JPEG di circa 800 px, `Cache-Control: max-age=2592000` ed `ETag`; da 1 a 8
  immagini per prodotto.
- Si scaricano **senza chiave**: verificato da fuori dal proxy (200
  `image/jpeg`, stesso file ottenuto con la chiave). Il proxy immagini non
  allega quindi mai la chiave (`WTB_IMAGE_SEND_KEY=0`).

**`GET /stock/`**
- **Non paginato**: restituisce sempre tutte le righe (`page` e `page_size`
  ignorati) con `count`, `currency`, `updated_at`, `results`.
- Una riga per taglia × regime × tipo di stock; le righe a 0 non compaiono:
  il payload è completo, quindi un'offerta assente vale 0.
- `updated_at` è l'ora in cui è stata generata la risposta (cambia a ogni
  chiamata anche a dati identici): per saltare i giri senza novità si
  confronta l'impronta (hash) del payload.

**`POST /quote/`**
- Righe accettate: `variant_id` + `vat_regime` ✔, `sku` + `size_eu` +
  `vat_regime` ✔, `variant_id` senza regime (sceglie il margine). Senza
  `ship_to` usa l'Italia.
- Risposta: `currency`, `ship_to_country`, `pieces`, `subtotal`, `shipping`,
  `extended_stock_transport`, `shipping_total`, `vat`, `total`, `vat_note`,
  `lines[]` (`sku`, `size_eu`, `vat_regime`, `quantity`, `unit_price`,
  `stock_type`): una riga per fascia.
- La ripartizione pronta/estesa segue lo stock reale di WTB e può dare alla
  pronta consegna **più** paia di quelle indicate da `available`. Il
  preventivo resta la fonte della ripartizione (docs/06).
- Spedizione: `shipping` è la tariffa di zona (primo paio + paia aggiuntive)
  calcolata **solo sulle paia in pronta consegna**; le paia da stock esteso
  pagano solo `extended_stock_transport` secondo `by_pieces` (gratis da
  `free_from_pieces`), uguale per tutte le zone. Ordine tutto esteso ⇒
  `"shipping": "0"` (senza decimali: il parser accetta entrambe le forme).
- IVA di WTB verso di noi: aliquota ordinaria su righe `ordinary` **e su tutta
  la spedizione** (zona + trasporto esteso) se c'è almeno una riga ordinaria;
  ordine solo a margine ⇒ IVA 0 anche sulla spedizione. Non dipende dal paese
  di destinazione. `vat_note` in italiano; più note unite da `" · "`.
- Errori 400: `{"stock": ["<sku> EU <taglia> (<regime>): only N available."]}`
  oppure `"...: not available."`; paese non servito
  `{"ship_to": "Shipping to US is not available for retailers. Countries served: ..."}`
  (stringa, non lista); variante inesistente ⇒ 400 con il corpo del preventivo
  tutto `null` e `lines: []`, **senza messaggio**.

**`POST /orders/` (chiave di prova)**
- 201 con `"test": true`, `"number": "TEST"`, `"id": null`,
  `"status": "received"` e `"payment_instructions": null` anche con
  `bank_transfer`: la forma delle istruzioni di pagamento **non è osservabile**
  con la chiave di prova. Le fixture usano una forma presunta (`iban`,
  `beneficiary`, `reason`, `amount`) e il codice mostra qualunque chiave
  arrivi.
- Campi non documentati: `created_at`, `hand_delivery`, `test`. Le righe hanno
  `name` ma **non** `stock_type` (sono comunque divise per fascia: stessa
  taglia su due righe). `shipping` dell'ordine = tariffa di zona + trasporto
  esteso (= `shipping_total` del preventivo).
- Lo stesso `reference` rinviato in prova risponde di nuovo 201 (non si salva
  nulla): l'idempotenza (200) si verifica solo in live.
- Errori 400 per campo: `{"reference": "..."}`,
  `{"ship_to": "Missing field: full_name"}`, `{"payment_method": "..."}`,
  `{"stock": [...]}`.
- `GET /orders/` con la chiave di prova: lista vuota (`count`, `page`,
  `has_more`, `results`: niente `page_size`); `?reference=` sconosciuto ⇒
  lista vuota; `/orders/<id>/` sconosciuto ⇒ 404 `{"detail": "Order not found."}`.

**Limite di frequenza**
- Oltre il limite: 429 con intestazione `Retry-After` (secondi) e corpo
  `{"detail": "La richiesta è stata limitata (throttled). Expected available in N seconds."}`.
  La finestra è scorrevole.

**Errori in generale**
- Corpo JSON in stile Django REST: `{"detail": "..."}` per 401/404/429; per i
  400 un oggetto campo → messaggio (stringa o lista di stringhe). Messaggi in
  inglese per i 400, in italiano per 401 e 429.

**Differenze dalla documentazione (riassunto)**
1. `count` dei prodotti non affidabile e pagine corte con `has_more: true`.
2. Prodotti esauriti assenti dal feed (non elencati con offerte a 0).
3. `/stock/` non paginato; `updated_at` = ora della risposta.
4. Tariffa di zona solo sulle paia in pronta consegna; stock esteso con il
   solo trasporto dedicato; `shipping` a volte `"0"`.
5. IVA di WTB anche sulla spedizione quando c'è una riga ordinaria.
6. Ordini di prova: `id` nullo, nessuna istruzione di pagamento, idempotenza
   non verificabile; righe d'ordine senza `stock_type`.
7. Campi in più: `currency` in `/shipping/` e `/stock/`, `created_at`,
   `hand_delivery`, `test` negli ordini, `name` nelle righe d'ordine.
8. Variante inesistente nel preventivo: 400 senza messaggio.
