# Variabili d'ambiente (`.env`)

`.env.example` contiene solo le variabili, a gruppi separati da una riga
vuota e senza commenti. Le spiegazioni stanno qui, con lo stesso ordine.
Si copia `.env.example` in `.env` (mai committato) e si riavviano i
container dopo ogni modifica.

Le manopole che cambiano spesso (indirizzi admin per le email, tariffe,
margini, ordine minimo…) non sono qui: sono in Admin › Impostazioni.

## 1. Applicazione

| Variabile | Valori | Significato |
|---|---|---|
| `APP_ENV` | `development` · `production` · `test` | Ambiente. `WTB_MODE=live` è ammesso solo con `production`. |
| `APP_DEBUG` | `0` · `1` | `1` = dettagli degli errori a video (solo in `development`). |
| `APP_URL` | URL senza barra finale | Indirizzo pubblico, usato nei link delle email. |
| `APP_DOMAIN` | dominio | Solo produzione: label di Caddy (D-018: `ingrosso.shoesclothingstore.com`). |
| `APP_TIMEZONE` | es. `Europe/Rome` | Fuso per date e orari mostrati (nel database tutto è UTC). |
| `LOG_LEVEL` | `debug` · `info` · `warning` · `error` | Livello dei log in `var/log/`. |
| `PUBLIC_SITE` | `0` · `1` | Lato cliente (D-019). `0` = spento, `/` porta all'admin e si usano gli ordini di prova in `/admin/ordini/nuovo`; `1` = sito, vetrina, login, catalogo, carrello e account accesi. |

## 2. Database

| Variabile | Significato |
|---|---|
| `DB_PATH` | File SQLite (D-010), relativo alla radice del progetto o assoluto. |

## 3. Sessioni e accesso admin

| Variabile | Significato |
|---|---|
| `SESSION_LIFETIME` | Durata della sessione in secondi (28800 = 8 ore). |
| `SESSION_SECURE` | Cookie solo su HTTPS: `auto` (secondo la richiesta), `1`, `0`. In produzione è sempre `1`. |
| `TRUSTED_PROXIES` | CIDR separati da virgola da cui accettare `X-Forwarded-For/Proto`. In produzione: subnet della network docker `caddy` e quella interna dello stack. |
| `ADMIN_PASSWORD_HASH` | Hash Argon2id della password admin: `php bin/hash-password.php 'la-password'`. |

## 4. Email

| Variabile | Significato |
|---|---|
| `MAIL_TRANSPORT` | `log` = nessun invio, ogni email diventa un file `.eml` in `var/mail/` (sviluppo). `smtp` = invio vero (AWS SES in produzione). **Per ricevere email davvero serve `smtp`.** |
| `MAIL_FROM` | Mittente. Con SES deve essere un indirizzo o dominio verificato. |
| `MAIL_FROM_NAME` | Nome del mittente. |
| `SMTP_HOST` | Server SMTP (es. `email-smtp.eu-west-1.amazonaws.com`). Vuoto con `smtp` = ogni invio fallisce. |
| `SMTP_PORT` | `587` (con `tls`) o `465` (con `ssl`). |
| `SMTP_USER` / `SMTP_PASSWORD` | Credenziali SMTP (per SES: le credenziali SMTP, non le chiavi IAM). |
| `SMTP_ENCRYPTION` | `tls` (STARTTLS), `ssl`, vuoto = nessuna cifratura. |

I destinatari delle email admin non sono nel `.env`: sono l'impostazione
"Email che ricevono ordini e avvisi" (`company.admin_emails`). Un invio non
riuscito non blocca l'operazione: finisce in `var/log/` come
"Email non inviata" con il motivo.

## 5. Bonifici e pro-forma

| Variabile | Significato |
|---|---|
| `BANK_HOLDER`, `BANK_NAME`, `BANK_IBAN`, `BANK_BIC` | Coordinate per i bonifici dei clienti (D-003). Mai nei file versionati. |
| `RECEIPT_PREFIX` | Prefisso della pro-forma, numerazione `<prefisso>-<anno>-<NNNN>` (D-015). |

## 6. Fornitore: modalità e chiavi (D-008, docs/03)

| Variabile | Significato |
|---|---|
| `WTB_MODE` | `fixture` = file in `fixtures/wtb/`, nessuna rete; `test` = API vera con la chiave di prova, ordini simulati dal fornitore; `live` = ordini veri con soldi veri, **solo sul server di produzione**. |
| `WTB_BASE_URL` | Indirizzo dell'API. |
| `WTB_API_KEY_TEST` | Chiave di prova `wtb_test_…`. In Claude Code web resta vuota: la aggiunge il proxy. |
| `WTB_API_KEY_LIVE` | Chiave reale: solo nel `.env` di produzione, al momento del live. |
| `WTB_WEBHOOK_SECRET` | Segreto dei webhook (`whsec_…`) dal portale. Vuoto = nessun webhook valido. In sviluppo basta un valore inventato per "Simula evento" e `bin/wtb-webhook.php`. |
| `WTB_HTTP_TIMEOUT` | Timeout delle chiamate in secondi. |

## 7. Fornitore: catalogo e immagini

| Variabile | Significato |
|---|---|
| `WTB_CATALOG_ALL` | `1` = aggiunge `all=1` (ignora "Solo la mia selezione" del portale). |
| `WTB_SYNC_MAX_DROP_PERCENT` | Il sync completo si ferma se il feed dei prodotti cala oltre questa percentuale. |
| `WTB_IMAGE_HOSTS` | Host da cui il proxy immagini può scaricare, separati da virgola. |
| `WTB_IMAGE_SEND_KEY` | `1` = allega la chiave al download delle immagini (M0: non serve). |

## 8. Fornitore: intervalli dei job (secondi)

| Variabile | Default | Job |
|---|---|---|
| `WTB_STOCK_SYNC_INTERVAL` | 900 | Sync rapido di stock e prezzi. |
| `WTB_PRODUCTS_SYNC_INTERVAL` | 10800 | Sync completo del catalogo. |
| `WTB_ORDERS_POLL_INTERVAL` | 3600 | Rilettura degli ordini al fornitore. |

## 9. Fornitore: ordini (docs/06)

| Variabile | Significato |
|---|---|
| `WTB_ORDERS_ENABLED` | Kill switch: `0` = nessun ordine parte verso il fornitore, nemmeno a mano. |
| `WTB_ORDER_TRIGGER` | `on_confirm` (alla conferma del bonifico, D-009), `on_request`, `manual`. |
| `WTB_PAYMENT_METHOD` | `bank_transfer` (D-004) o `balance`. |
| `WTB_REFERENCE_PREFIX` | Prefisso del reference: `<prefisso>-<id ordine>-<tentativo>`. |
| `WTB_SHIP_TO_EMAIL` | Email nel `ship_to`: `admin` (la nostra) o `customer`. |
| `WTB_MAX_ORDER_EUR` | Tetto sul costo stimato di un ordine al fornitore in euro (`0` = nessun tetto). |

## 10. Backup

| Variabile | Significato |
|---|---|
| `BACKUP_KEEP_DAYS` | Giorni di backup giornalieri conservati in `var/backups/`. |
