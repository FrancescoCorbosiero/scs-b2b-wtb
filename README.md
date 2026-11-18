# SCS B2B · WTB

Catalogo sneakers B2B ad accesso riservato per i rivenditori di **SHOES &
CLOTHING RESELLING**. La specifica completa è in [`docs/`](docs/) (si parte da
`CLAUDE.md`); le modifiche, in parole semplici, in [`CHANGELOG.md`](CHANGELOG.md).

Stack: PHP 8.3 (Slim 4, PHP-DI, Twig), SQLite in WAL, Tailwind CSS + Alpine.js
(compilati in build), PHPMailer, dompdf, Monolog. In produzione: Docker Compose
dietro Caddy Docker Proxy.

**Stato attuale (D-019)**: si usa solo l'area admin. Il lato cliente (sito,
vetrina, login, catalogo, carrello) è spento con `PUBLIC_SITE=0` e `/` porta a
`/admin`. Gli ordini si compongono in **Admin › Nuovo ordine** per provare
il fornitore, prima in `test` (ordini simulati) e poi in `live`.

## Modalità del fornitore

| `WTB_MODE` | Dati | Ordini al fornitore | Webhook dal fornitore |
|---|---|---|---|
| `fixture` | file in `fixtures/wtb/`, nessuna rete | simulati in locale | solo "Simula" in Notifiche fornitore |
| `test` | API vera con `WTB_API_KEY_TEST` | simulati dal fornitore (`"test": true`, niente salvato) | **nessuno** (il fornitore non notifica gli ordini di prova): solo "Simula" in Notifiche fornitore |
| `live` | API vera con `WTB_API_KEY_LIVE` | **veri**, solo con `APP_ENV=production` | veri, firmati con `WTB_WEBHOOK_SECRET` |

L'area admin mostra sempre la modalità attiva. Gli ordini ricordano la modalità
in cui sono nati e non si mescolano.

## Produzione (VPS)

Sulla VPS non serve PHP: **ogni comando dell'app gira dentro il container
`php`**, che ha già le dipendenze. Dalla cartella del progetto:

```bash
docker compose exec php php bin/<comando>.php
```

Lanciare `php bin/…` direttamente sulla VPS fallisce con
`vendor/autoload.php: No such file or directory`: è normale, `vendor/` esiste
solo nell'immagine.

### Primo avvio

1. Clona il repository in una cartella dedicata (stack separato da scs-b2b).
2. Crea il `.env` da `.env.example` e compila solo la parte in alto (D-021):
   - `APP_ENV=production`, `APP_URL=https://<dominio>`,
     `APP_DOMAIN=<dominio>` (label di Caddy);
   - `ADMIN_PASSWORD_HASH` (vedi § Chiavi e segreti);
   - `MAIL_TRANSPORT=smtp`, `MAIL_FROM`, `MAIL_FROM_NAME`, `SMTP_HOST`,
     `SMTP_USER`, `SMTP_PASSWORD` (AWS SES);
   - `BANK_*` (servono solo col lato cliente acceso);
   - `PUBLIC_SITE=0`, `WTB_MODE=test`, `WTB_API_KEY_TEST=wtb_test_…`;
     `WTB_WEBHOOK_SECRET` resta vuoto finché si prova in `test` (la
     simulazione dall'admin non lo chiede).

   Il blocco "Avanzate" resta commentato: ha già i default giusti (anche
   `TRUSTED_PROXIES` vuoto, l'IP reale lo ricava nginx). Le scelte sugli
   ordini al fornitore e sul catalogo si fanno in **Admin › Impostazioni**.
3. La network esterna `caddy` deve esistere (quella di Caddy Docker Proxy). Se
   usa una subnet diversa da quelle private standard, aggiungila in
   `docker/nginx/default.conf` (`set_real_ip_from`).
4. `docker compose up -d --build`. Il container `php` **applica da solo le
   migrazioni** a ogni avvio (non serve `bin/migrate.php`), `scheduler` esegue
   i job periodici (sync stock ogni 15 minuti, catalogo ogni 3 ore, backup),
   `web` (nginx) è l'unico sulla network `caddy`. Nessuna porta pubblicata.
5. Primo catalogo, senza aspettare lo scheduler:
   ```bash
   docker compose exec php php bin/sync.php products
   ```
   ```bash
   docker compose exec php php bin/sync.php stock
   ```
   oppure da **Admin › Aggiornamenti › Aggiorna ora**.
6. Apri `https://<dominio>/admin`: banner "Modalità: PROVA" in alto, prodotti in
   Admin › Prodotti.

### Aggiornare

```bash
git pull
```
```bash
docker compose up -d --build
```

Le migrazioni nuove si applicano all'avvio del container `php`. Dopo una
modifica del solo `.env` basta `docker compose up -d --force-recreate php scheduler`;
le modifiche in Admin › Impostazioni valgono subito, senza riavvii.

Se il `.env` ha ancora variabili spostate nell'admin (`WTB_ORDERS_ENABLED`,
`WTB_ORDER_TRIGGER`, `WTB_PAYMENT_METHOD`, `WTB_MAX_ORDER_EUR`,
`WTB_SHIP_TO_EMAIL`, `WTB_SHIP_TO_PHONE`, `WTB_CATALOG_ALL`), al primo avvio il
loro valore viene copiato in Admin › Impostazioni (una volta sola) e
`docker compose logs php` dice quali righe togliere dal `.env`.

### Comandi utili

| Cosa | Comando |
|---|---|
| Stato dei container | `docker compose ps` |
| Log dell'app | `docker compose logs -f php` (file in `var/log/` dentro il volume) |
| Applicare le migrazioni a mano | `docker compose exec php php bin/migrate.php` |
| Sync catalogo / stock | `docker compose exec php php bin/sync.php products` · `… stock` |
| Webhook simulato (solo `fixture`/`test`, serve un `WTB_WEBHOOK_SECRET` anche inventato) | `docker compose exec php php bin/wtb-webhook.php order.shipped <n. ordine>` |
| Backup a mano | `docker compose exec php php bin/backup.php` |
| Hash della password admin | `docker compose exec php php bin/hash-password.php 'la-password'` |

## Prove degli ordini

### In `test` (ordini simulati, nessun rischio)

1. **Admin › Nuovo ordine**: cerca uno SKU, aggiungi le paia, scrivi
   l'indirizzo di consegna, "Verifica la disponibilità e salva" (preventivo
   vero del fornitore: disponibilità e costi reali).
2. Nella pagina dell'ordine: **Invia l'ordine al fornitore** → stato
   "Simulato", riferimento `SCSW-<n>-1`, totali del fornitore accanto al
   previsto.
3. Il fornitore non manda notifiche (webhook) per gli ordini di prova: per
   vedere il resto del giro usa **Admin › Notifiche fornitore › Simula**
   ("Ordine ricevuto dal fornitore", "Pagamento ricevuto", "Spedito"). Non
   serve nessun segreto nel `.env`.
4. In `test` i dati per il bonifico al fornitore arrivano vuoti e
   Admin › Ordini al fornitore non mostra gli ordini simulati: è normale.

### In `live` (ordini veri, soldi veri)

1. Webhook: nel portale del fornitore imposta l'indirizzo
   `https://<dominio>/webhooks/wtb` e copia il segreto `whsec_…` (mostrato una
   volta sola) in `WTB_WEBHOOK_SECRET`.
2. Nel `.env`: `WTB_MODE=live`, `WTB_API_KEY_LIVE=…`; poi
   `docker compose up -d --force-recreate php scheduler`. Per i primi ordini
   conviene una spesa massima in Admin › Impostazioni › Ordini al fornitore.
3. Primo ordine piccolo da Admin › Nuovo ordine, seguito dall'inizio alla
   fine: creazione (stato "Ricevuto"), bonifico al fornitore con IBAN,
   causale e importo mostrati nell'ordine e in dashboard, eventi in
   Admin › Notifiche fornitore (ordine ricevuto, pagamento ricevuto, spedito).
   Se una notifica non arriva: "Aggiorna dal fornitore" nella pagina dell'ordine (lo
   scheduler rilegge comunque ogni ora).
4. Un ordine live non si annulla dall'API: si annulla contattando
   direttamente il fornitore (o dal portale).
5. Si torna indietro rimettendo `WTB_MODE=test` (D-008).

Prima di riaprire il lato cliente (`PUBLIC_SITE=1`) va completata la
checklist di [`docs/06-ordini.md`](docs/06-ordini.md#checklist-prima-di-wtb_modelive)
(IVA validata dal commercialista, tariffe e margini confermati, backup provato).

## Chiavi e segreti

- Vivono solo nel `.env` del server: mai in git, nei log, nei messaggi.
- **Password admin**: `docker compose exec php php bin/hash-password.php 'la-password'`,
  copia l'hash in `ADMIN_PASSWORD_HASH` **tra apici singoli** (contiene `$`),
  poi ricrea i container.
- **Rotazione della chiave del fornitore**: genera la nuova chiave nel portale,
  sostituisci `WTB_API_KEY_TEST` o `WTB_API_KEY_LIVE` nel `.env`, ricrea i
  container, controlla in Admin › Aggiornamenti che l'aggiornamento riesca, revoca la vecchia
  chiave nel portale.
- **Segreto dei webhook**: stessa procedura con `WTB_WEBHOOK_SECRET`; tra il
  cambio nel portale e il riavvio i webhook firmati col segreto nuovo vengono
  rifiutati (401) e il fornitore li ritenta (fino a 8 volte in circa un
  giorno): nessun evento perso.

## Backup e ripristino

- Il job `backup` fa ogni giorno una copia consistente del database
  (`VACUUM INTO`) in `var/backups/` e ne tiene `BACKUP_KEEP_DAYS` (14).
  Consigliata una copia fuori dalla VPS (es. `rsync` notturno della cartella).
- **Ripristino**: `docker compose stop php scheduler`, copia il file di backup
  scelto su `var/db.sqlite` dentro il volume (cancella `db.sqlite-wal` e
  `db.sqlite-shm` se presenti), `docker compose start php scheduler`. Le
  migrazioni mancanti si riapplicano da sole all'avvio.

## Sviluppo (in locale o in Claude Code web)

```bash
composer install                     # in Claude Code web: sh bin/dev-composer-install.sh (docs/02)
npm ci && npm run build              # asset in public/assets (non versionati)
cp .env.example .env                 # valori sicuri: WTB_MODE=fixture, email come file .eml
php bin/hash-password.php 'una-password-admin-lunga'   # → ADMIN_PASSWORD_HASH nel .env
php bin/migrate.php
php bin/sync.php products && php bin/sync.php stock
php -S 127.0.0.1:8080 -t public bin/dev-router.php
composer check                       # test + PHPStan, come la CI
```

Per provare anche il lato cliente: `PUBLIC_SITE=1` nel `.env` e
`php bin/dev-customer.php demo@example.com 'demo-password'`. Guida passo passo:
[`.claude/skills/verify/SKILL.md`](.claude/skills/verify/SKILL.md).
