# SCS B2B · WTB

Catalogo sneakers B2B ad accesso riservato per i rivenditori di **SHOES &
CLOTHING RESELLING**. La specifica completa è in [`docs/`](docs/) (si parte da
`CLAUDE.md`); le modifiche, in parole semplici, in [`CHANGELOG.md`](CHANGELOG.md).

Stack: PHP 8.3 (Slim 4, PHP-DI, Twig), SQLite in WAL, Tailwind CSS + Alpine.js
(compilati in build), PHPMailer, dompdf, Monolog. In produzione: Docker Compose
dietro Caddy Docker Proxy.

## Sviluppo

```bash
composer install                     # in Claude Code web: sh bin/dev-composer-install.sh (docs/02)
npm ci && npm run build              # asset in public/assets (non versionati)
cp .env.example .env                 # valori sicuri: WTB_MODE=fixture, email come file .eml
php bin/hash-password.php 'una-password-admin-lunga'   # → ADMIN_PASSWORD_HASH nel .env
php bin/migrate.php
php bin/sync.php products && php bin/sync.php stock
php bin/dev-customer.php demo@example.com 'demo-password'
php -S 127.0.0.1:8080 -t public bin/dev-router.php
composer check                       # test + PHPStan, come la CI
```

Guida passo passo per provare tutto: [`.claude/skills/verify/SKILL.md`](.claude/skills/verify/SKILL.md).

## Modalità del fornitore

| `WTB_MODE` | Dati | Ordini al fornitore |
|---|---|---|
| `fixture` | file in `fixtures/wtb/`, nessuna rete | simulati in locale |
| `test` | API vera con `WTB_API_KEY_TEST` | simulati dal fornitore (`"test": true`) |
| `live` | API vera con `WTB_API_KEY_LIVE` | **veri**, solo con `APP_ENV=production` |

L'area admin mostra sempre la modalità attiva. Gli ordini ricordano la modalità
in cui sono nati.

## Deploy sulla VPS

1. Clona il repository in una cartella dedicata (stack separato da scs-b2b).
2. Crea il `.env` partendo da `.env.example`: `APP_ENV=production`,
   `APP_URL=https://<dominio>`, `APP_DOMAIN=<dominio>` (per la label di
   Caddy), `ADMIN_PASSWORD_HASH`, SMTP (AWS SES), `BANK_*`, `WTB_MODE=test` e
   `WTB_API_KEY_TEST` per il primo giro, `TRUSTED_PROXIES` vuoto (l'IP reale
   lo ricava nginx).
3. La network esterna `caddy` deve esistere (è quella di Caddy Docker Proxy).
   Se usa una subnet diversa da quelle private standard, aggiungila in
   `docker/nginx/default.conf` (`set_real_ip_from`).
4. `docker compose up -d --build`: il container `php` applica le migrazioni
   all'avvio, `scheduler` esegue i job periodici, `web` (nginx) è l'unico sulla
   network `caddy`. Nessuna porta pubblicata.
5. Primo catalogo: `docker compose exec php php bin/sync.php products`.
6. Nel portale del fornitore imposta il webhook
   `https://<dominio>/webhooks/wtb` e copia il segreto in `WTB_WEBHOOK_SECRET`,
   poi `docker compose up -d` per rileggere il `.env`. L'evento `ping` compare
   in `/admin/webhook`.

Aggiornare: `git pull && docker compose up -d --build`.

## Chiavi e segreti

- Vivono solo nel `.env` del server: mai in git, nei log, nei messaggi.
- **Rotazione della chiave del fornitore**: genera la nuova chiave nel portale,
  sostituisci `WTB_API_KEY_TEST` o `WTB_API_KEY_LIVE` nel `.env`,
  `docker compose up -d`, controlla in `/admin/sync` che il sync riesca,
  revoca la vecchia chiave nel portale.
- **Segreto dei webhook**: stessa procedura con `WTB_WEBHOOK_SECRET`; tra il
  cambio nel portale e il riavvio i webhook firmati col segreto nuovo vengono
  rifiutati (401) e il fornitore li ritenta: nessun evento perso.
- **Password admin**: `php bin/hash-password.php` e nuovo `ADMIN_PASSWORD_HASH`.

## Passaggio da test a live

Prima la checklist di [`docs/06-ordini.md`](docs/06-ordini.md#checklist-prima-di-wtb_modelive)
(IVA validata dal commercialista, tariffe e margini confermati, backup
provato). Poi nel `.env`: `WTB_MODE=live`, `WTB_API_KEY_LIVE=…`, e
`docker compose up -d`. Si torna indietro rimettendo `WTB_MODE=test` (D-008).
Il primo ordine reale va seguito dall'inizio alla fine.

## Backup e ripristino

- Il job `backup` fa ogni giorno una copia consistente del database
  (`VACUUM INTO`) in `var/backups/` e ne tiene `BACKUP_KEEP_DAYS` (14).
  Consigliata una copia fuori dalla VPS (es. `rsync` notturno della cartella).
- Backup a mano: `docker compose exec php php bin/backup.php`.
- **Ripristino**: `docker compose stop php scheduler`, copia il file di backup
  scelto su `var/db.sqlite` dentro il volume (cancella `db.sqlite-wal` e
  `db.sqlite-shm` se presenti), `docker compose start php scheduler`. Le
  migrazioni mancanti si riapplicano da sole all'avvio.
