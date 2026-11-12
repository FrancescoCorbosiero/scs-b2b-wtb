---
name: verify
description: Avvia e prova end to end la piattaforma SCS B2B · WTB (PHP + SQLite) in una sessione Claude Code web — installazione, migrazioni, sync da fixture, server locale, login admin e cliente, controlli con Playwright. Usala per verificare una modifica nell'app vera, non solo nei test.
---

# Provare l'app end to end

Tutto gira senza Docker: `php -S`, SQLite su file, email come file `.eml` in
`var/mail/`. Mai `WTB_MODE=live` e mai la chiave reale in una sessione di
sviluppo.

## 1. Dipendenze e asset

```bash
sh bin/dev-composer-install.sh      # composer install da sorgente (+ PHPStan dalla cache, docs/02)
npm ci --no-audit --no-fund && npm run build
```

## 2. Ambiente di sviluppo

```bash
cp -n .env.example .env
php bin/hash-password.php 'admin-dev-password'   # copia l'hash in ADMIN_PASSWORD_HASH del .env
```

Nel `.env`: `APP_ENV=development`, `WTB_MODE=fixture` (nessuna rete) oppure
`WTB_MODE=test` (API vera con la chiave di prova: in Claude Code web la mette
il proxy, lascia `WTB_API_KEY_TEST` vuota).

## 3. Database, catalogo, cliente di prova

```bash
php bin/migrate.php
php bin/sync.php products      # sync completo (fixture o API di prova)
php bin/sync.php stock         # sync rapido
php bin/dev-customer.php demo@example.com 'demo-password' IT
```

## 4. Server

```bash
php -S 127.0.0.1:8080 -t public bin/dev-router.php
```

- Sito: http://127.0.0.1:8080/ — vetrina `/vetrina`, login `/login`
  (demo@example.com / demo-password), catalogo `/catalogo`, carrello `/carrello`.
- Admin: http://127.0.0.1:8080/admin (password scelta al punto 2): banner della
  modalità in alto, impostazioni con storico, clienti, sync, ordini, webhook.
- Email: file `.eml` in `var/mail/` (inviti, reset, ordini). Il link d'invito
  è nel testo dell'email.
- Webhook di prova: `php bin/wtb-webhook.php <evento> <id ordine>` oppure
  "Simula evento" in `/admin/webhook`.

## 5. Controlli automatici

```bash
composer check                 # PHPUnit (Unit, Integration, Leak) + PHPStan
```

Per l'interfaccia: Playwright con il Chromium preinstallato
(`require('playwright')`, `chromium.launch()`), viewport mobile e desktop,
screenshot a `deviceScaleFactor: 1` per i controlli interni.

## Cosa guardare sempre

- Nessun costo del fornitore e nessuna parola "WTB" nelle pagine, nei JSON,
  negli export, nelle email e nel PDF del cliente (lo controlla anche
  `tests/Leak`).
- Vetrina senza prezzi nei dati (HTML e JSON), catalogo solo dopo il login.
- Pagine non pubbliche con `X-Robots-Tag: noindex, nofollow`.
