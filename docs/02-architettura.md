# 02 — Architettura

## Stack

| Layer | Scelta | Note |
|---|---|---|
| Runtime | PHP 8.3 (FPM in produzione) | |
| Framework | Slim 4 + PHP-DI | niente Laravel, niente SPA |
| Database | SQLite 3 via PDO | WAL, `foreign_keys=ON`, `busy_timeout=5000` (D-010) |
| Template | Twig | autoescape sempre attivo |
| CSS/JS | Tailwind CSS + Alpine.js | compilati in build in `public/assets`; Node solo in build |
| Email | PHPMailer via SMTP | AWS SES in produzione; trasporto `log` in sviluppo |
| PDF | dompdf | pro-forma |
| Log | Monolog | file in `var/log/`, mai token o chiavi |
| Config | vlucas/phpdotenv + registro impostazioni | `.env` per segreti e interruttori, DB per le manopole del titolare |

Rendering server-side con progressive enhancement; le interazioni (carrello,
scheda rapida) usano endpoint JSON interni con sessione e CSRF.

### SQLite: regole

- Un solo file (`DB_PATH`, default `var/db.sqlite`) su disco locale, mai su
  filesystem di rete. All'apertura: `PRAGMA journal_mode=WAL`,
  `foreign_keys=ON`, `busy_timeout=5000`.
- Importi in **centesimi INTEGER**; date in testo ISO 8601 UTC; JSON in TEXT.
- Le scritture lunghe (sync) stanno in una transazione unica e breve: si
  scarica e valida tutto PRIMA di aprire la transazione.
- Le migrazioni sono file SQL numerati in `database/migrations/`, applicati da
  `bin/migrate.php` e registrati in una tabella `migrations`.

## Struttura del repository

Cartelle per **area di business**, così una richiesta del titolare porta
subito ai file giusti:

```
.
├── CLAUDE.md · CHANGELOG.md · README.md · .env.example
├── docs/                      # specifica viva (questo kit)
├── .claude/skills/verify/     # come provare l'app end to end
├── .github/workflows/         # CI: composer check
├── bin/                       # migrate, sync, scheduler, hash-password, wtb-webhook (dev)
├── config/                    # bootstrap, container, rotte, middleware
├── database/migrations/       # 0001_*.sql …
├── fixtures/wtb/              # risposte WTB per modalità fixture e test automatici
├── lang/                      # it.php (fonte), en.php
├── public/                    # index.php, assets compilati, robots.txt
├── src/
│   ├── Supplier/Wtb/          # UNICO punto che conosce l'API WTB
│   ├── Catalog/               # sync, prodotti, ricerca e filtri, immagini
│   ├── Pricing/               # prezzi di vendita, fasce, regole margine
│   ├── Tax/                   # TaxPolicy: IVA per regime e paese
│   ├── Shipping/              # zone e tariffe al cliente
│   ├── Orders/                # carrello, checkout, ordini, pro-forma, ordini al fornitore
│   ├── Accounts/              # clienti, inviti, richieste di profilo, login
│   ├── Settings/              # registro tipizzato delle impostazioni
│   ├── Jobs/                  # job periodici dello scheduler
│   ├── Http/                  # controller e middleware (cliente, admin, webhook)
│   └── Support/               # Db, Money, Mailer, Lang, Session, Csrf, ClientIp, View
├── templates/
├── tests/                     # Unit/, Integration/, Leak/
├── docker/                    # nginx, php, scheduler
├── docker-compose.yml         # produzione dietro Caddy
└── package.json · tailwind.config.js
```

## Confini tra i moduli

- **`Supplier/Wtb`** traduce WTB ↔ modello interno: client HTTP, mapper del
  catalogo, creazione e lettura ordini, preventivi, verifica firma webhook,
  trasporto "fixture". Espone interfacce (`SupplierCatalog`, `SupplierOrders`)
  che il resto dell'app usa senza sapere che c'è WTB.
- **`Pricing`, `Tax`, `Shipping`** sono servizi **puri** (nessun I/O): ricevono
  dati, restituiscono importi. Si testano a tabella e sono l'unico posto dove
  vivono le rispettive regole.
- **`Orders`** orchestra: legge carrello e offerte, chiama Pricing/Tax/Shipping,
  salva, chiede a `SupplierOrders` di creare l'ordine, manda le email.
- I controller sono sottili: validano l'input, chiamano un servizio,
  renderizzano.

## Registro delle impostazioni

`src/Settings/Registry.php` dichiara ogni manopola del titolare: chiave, tipo
(`int`, `money`, `bool`, `enum`, `string`, `json`), default, etichetta,
gruppo, validazione. I valori modificati vivono nella tabella `settings`;
ogni modifica finisce in `settings_audit` (chi, quando, prima, dopo), così
"disfare" è sempre possibile. La pagina `/admin/impostazioni` è generata dal
registro, con "Ripristina default" per ogni voce.

Voci iniziali (i nomi sono indicativi, il significato no):

| Chiave | Default | Uso |
|---|---|---|
| `orders.min_items` | 5 | ordine minimo in paia (si usa il maggiore tra questo e il minimo WTB del profilo Wholesaler) |
| `orders.rethink_seconds` | 15 | conto alla rovescia "Annulla" prima dell'invio reale |
| `catalog.sell_extended` | true | vendere anche lo stock esteso (D-005) |
| `catalog.regimes` | `margin,ordinary` | regimi IVA in vendita (docs/04) |
| `catalog.per_page` | 24 | prodotti per pagina |
| `catalog.sold_out_days` | 30 | giorni in cui un prodotto sparito dal feed resta visibile come esaurito (D-017) |
| `catalog.availability_high_min` / `_low_max` | 60 / 20 | soglie della pillola disponibilità |
| `prices.rounding` | `whole` | `whole` / `half` / `none`, sempre per eccesso |
| `prices.default_margin_type` / `_value` | `percent` / 5 | margine di default (D-014) |
| `tax.vat_rate_it` | 22 | aliquota per le righe ordinarie verso clienti italiani |
| `tax.shipping_regime` | `ordinary` | trattamento IVA della spedizione (docs/04) |
| `shipping.carrier_domains` | elenco | domini dei corrieri ammessi nei link di tracking |
| `images.proxy` | true | immagini servite dal nostro dominio (D-011) |

Restano in `.env` (servono accesso al server, quindi sono più protetti): chiavi
e modalità WTB, trigger e kill switch degli ordini, coordinate bancarie,
SMTP, password admin. `.env.example` elenca TUTTE le variabili citate nei
documenti, commentate riga per riga, con valori di sviluppo sicuri
(`WTB_MODE=fixture`).

## Scheduler e job

Il container `scheduler` esegue `bin/scheduler.php`: un ciclo che lancia i
job dichiarati in `src/Jobs/` secondo il loro intervallo, con un lock per job
(niente esecuzioni sovrapposte) e una riga in `job_runs` per ogni esecuzione.

| Job | Intervallo default (`.env`) | Cosa fa |
|---|---|---|
| `stock_sync` | `WTB_STOCK_SYNC_INTERVAL=900` (15 min, come consiglia WTB) | `GET /stock/`: disponibilità e costi, ricalcolo prezzi |
| `products_sync` | `WTB_PRODUCTS_SYNC_INTERVAL=10800` (3 h) | `GET /products/`: catalogo completo |
| `orders_poll` | 3600 (1 h) | rete di sicurezza ai webhook: rilegge gli ordini WTB non conclusi |
| `cleanup` | giornaliero | eventi webhook vecchi, tentativi di login, cache immagini orfane |
| `backup` | giornaliero | copia consistente del database (`VACUUM INTO`), conserva 14 giorni |

Dall'admin: "Sincronizza ora" per catalogo e stock.

## Test e qualità

- **Unit** (`tests/Unit`): servizi puri (fasce e prezzi, margini,
  arrotondamenti, IVA per regime e paese, spedizione), mapper WTB sulle
  fixture, verifica firma webhook.
- **Integration** (`tests/Integration`): su un file SQLite temporaneo con le
  migrazioni vere: sync tutto-o-niente, checkout, ciclo dell'ordine, creazione
  ordine al fornitore con trasporto finto, webhook (firma, duplicati, stati).
- **Leak** (`tests/Leak`): genera le uscite verso il cliente (pagine, JSON,
  frammenti, export, email, testo del PDF) con fixture dai costi riconoscibili
  e verifica che **nessun costo** e **la parola "WTB"** non compaiano mai.
- `composer check` = PHPUnit + PHPStan (livello ≥ 6). CI su GitHub Actions a
  ogni PR.

## Sviluppo in Claude Code web

- **Chiave WTB**: nell'ambiente Claude Code la chiave di prova è un *segreto di
  rete* per l'host `b2b.wtbsnkrs.it` (intestazione `X-API-Key`, senza
  prefisso): il proxy la aggiunge alle richieste e la chiave non entra mai
  nella macchina. Per questo, con `APP_ENV=development` e la chiave della
  modalità vuota, il client **non invia** l'intestazione. In produzione una
  chiave vuota è un errore di configurazione bloccante.
- **Composer**: se i download dei pacchetti da GitHub rispondono 403 (il proxy
  GitHub della sessione blocca gli archivi dei repository non collegati),
  usare `composer install --prefer-source`.
- **Niente Docker per sviluppare**: `php -S` con un router che serve i file
  statici, SQLite su file, email col trasporto `log` (file `.eml` in
  `var/mail/`). Docker serve solo per la produzione.
- **Browser**: Playwright con Chromium per le verifiche dell'interfaccia
  (in Claude Code web di solito in `/opt/pw-browsers/`).
- **Mai** `WTB_MODE=live` e mai la chiave reale in una sessione di sviluppo.

## Deploy (VPS del titolare)

Stesso schema di scs-b2b, stack separato:

- Servizi: `web` (nginx, document root `public/`), `php` (php-fpm 8.3),
  `scheduler` (php cli). Volume `data` montato su `var/` (database, cache
  immagini, log, backup) condiviso da `php` e `scheduler`.
- Solo `web` sta sulla network esterna `caddy`, con le label di Caddy Docker
  Proxy (dominio, `reverse_proxy`). Nessuna porta pubblicata.
- nginx: header di sicurezza e `X-Robots-Tag: noindex, nofollow` tranne le
  pagine pubbliche; IP reale da `X-Forwarded-For` solo dalla subnet di Caddy
  (docs/09).
- L'endpoint `/webhooks/wtb` deve essere raggiungibile da internet.
- README con: setup, build, deploy, rotazione chiavi, passaggio test → live,
  backup e ripristino.

## Identità visiva

Stessa identità di scs-b2b (stesso titolare): nero "ink" e fondo "paper",
titoli in Archivo, testo in Inter, font self-hosted, mobile-first, animazioni
spente con `prefers-reduced-motion`, niente stili inline (CSP). Se il
repository scs-b2b è disponibile nella sessione, riprendere CSS e asset da lì.
