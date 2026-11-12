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
  120). Su 429: attesa di `Retry-After` se presente, altrimenti 30 s, poi
  nuovo tentativo (max 3).
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
2. Scarica **tutte** le pagine di `/products/` e valida tutto in memoria.
   Qualsiasi errore HTTP o di formato ⇒ nessuna scrittura, job in errore.
3. Se i prodotti attivi calerebbero oltre `WTB_SYNC_MAX_DROP_PERCENT` ⇒ stop e
   avviso in dashboard (un admin può forzare dal pannello sync).
4. Una transazione: upsert di prodotti, varianti e offerte; prodotti assenti
   ⇒ `is_active=0` (mai cancellati: gli ordini passati li citano); varianti
   assenti ⇒ inattive; offerte assenti ⇒ disponibilità 0.
5. Ricalcolo di categoria di taglia, totali, prezzi di vendita (reprice).

## Sync rapido (`stock_sync`)

1. `GET /stock/` (tutte le pagine, se paginato: da verificare in M0).
2. Aggiorna le offerte per (variante, regime, tipo di stock): disponibilità,
   costo, tempi di partenza. Solo se il payload è completo, le offerte non
   presenti delle varianti note vanno a 0.
3. Varianti sconosciute ⇒ import mirato con `GET /products/?sku=…`; se
   fallisce, ci pensa il prossimo sync completo.
4. Reprice delle sole offerte cambiate. Se `updated_at` non è cambiato dal
   giro precedente, il job finisce subito.

## Categoria di taglia

- `gender` = `men` / `women` / `unisex` ⇒ adulto.
- `gender` = `kids` ⇒ **GS** se la taglia US finisce con `Y` (es. `5.5Y`),
  **PS** se finisce con `C` (es. `10.5C`, anche toddler); in mancanza, sigle
  nel nome (`(GS)`, `(PS)`, `(TD)`).
- Da verificare sui dati reali in M0; il risultato alimenta filtro catalogo e
  regole margine.

## Immagini (D-011)

- In HTML le immagini sono sempre URL nostri (`/img/<id-prodotto>/<n>-<w>.jpg`).
- Il proxy serve dalla cache in `var/images/`; se manca il file, lo scarica
  dalla sorgente (solo `https`, solo host della whitelist ricavata in M0, max
  5 MB, solo `image/*`), crea le miniature (400/800 px se GD è disponibile) e
  lo serve con cache lunga. In errore: segnaposto.
- La chiave WTB si allega al download solo se l'host dell'immagine è quello
  dell'API e M0 ha dimostrato che serve.
- Dopo ogni sync un job scarica in anticipo le immagini dei prodotti nuovi.

## Fixture e modalità test

- **fixture**: un trasporto finto legge `fixtures/wtb/` (`products-page-1.json`,
  `stock.json`, `shipping.json`, `quote.json`, `order-created.json`,
  `order-detail.json`, `webhooks/*.json`). Le fixture partono dagli esempi di
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

_(da compilare con data, comportamenti osservati e differenze dalla
documentazione: forma delle risposte, paginazione, host delle immagini,
errori. **Niente valori commerciali reali** (prezzi, tariffe, disponibilità,
ordine minimo): il repository è pubblico, quei numeri vanno al titolare in
chat.)_
