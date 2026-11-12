# 09 — Sicurezza

## Perimetro

- **Pubblico e indicizzabile**: `/`, `/come-ordinare`, `/spedizioni`,
  `/contatti`, `/richiedi-accesso`: nessun prodotto, foto prodotto o prezzo.
- **Pubblico ma `noindex`**: `/vetrina` (prezzi tolti dai dati lato server,
  filtri e ordinamenti per prezzo ignorati), `/img/...` (immagini).
- **Pubblico, firmato**: `/webhooks/wtb` (solo POST con firma valida).
- **Tutto il resto** dietro sessione e `noindex`: catalogo, carrello, account,
  admin. `robots.txt` con `Allow` solo per le pagine pubbliche,
  `X-Robots-Tag: noindex, nofollow` da nginx sul resto, meta robots nel layout.

## Autenticazione

- **Clienti**: account creati dall'admin o approvando una richiesta, mai
  attivati da soli. Invito e reset con token monouso (sha256 a DB, chiaro solo
  nel link), validità 72 h per l'invito; un nuovo token invalida i precedenti;
  reset con risposta neutra e max 3 token/ora per utente. Password Argon2id,
  minimo 10 caratteri. Account disattivato ⇒ sessione chiusa alla richiesta
  successiva.
- **Admin**: password dedicata (`ADMIN_PASSWORD_HASH`, Argon2id, generata con
  `bin/hash-password.php`), sessione distinta da quella cliente.
- **Sessioni**: cookie `HttpOnly`, `Secure` (forzato in produzione),
  `SameSite=Lax`; id rigenerato al login; durata configurabile.
- **Lockout**: 5 tentativi falliti in 15 minuti per (IP, ambito) ⇒ blocco
  temporaneo con messaggio generico.

## IP reale dietro Caddy (critico)

Senza configurazione PHP vede l'IP del container Caddy e ogni limite per IP
diventa un blocco globale. nginx: `set_real_ip_from` sulla subnet della
network `caddy` + `real_ip_header X-Forwarded-For`; lato PHP `ClientIp` si
fida di `X-Forwarded-For` / `X-Forwarded-Proto` solo dalle subnet fidate.
Test: due IP diversi non condividono il contatore dei tentativi.

## Hardening

- CSRF su ogni POST (confronto a tempo costante), tranne il webhook.
- Twig autoescape, niente `|raw` su dati di WTB o dei clienti; le descrizioni
  WTB si mostrano come testo.
- PDO con prepared statements, nessuna concatenazione SQL.
- Header: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: same-origin`, CSP stretta (`default-src 'self'`,
  `img-src 'self' data:`, niente `unsafe-inline`).
- Honeypot e limiti: richiesta profilo 3/ora per IP, richiesta d'ordine
  3/ora per account e IP.
- Dati WTB validati al sync (tipi, lunghezze, enum), mai fidati.

## Webhook

- Firma HMAC-SHA256 su `<t>.<corpo grezzo>` con `WTB_WEBHOOK_SECRET`,
  confronto a tempo costante, finestra di 5 minuti.
- Duplicati scartati per `X-WTB-Delivery`; corpo massimo 1 MB; solo
  `application/json`.
- Una firma non valida non tocca il database (solo una riga di log senza
  segreto e senza corpo completo).

## Proxy immagini (anti-SSRF)

Scarica solo da URL salvati dal sync (mai da parametri della richiesta),
solo `https`, solo host in whitelist, niente redirect verso altri host,
massimo 5 MB, solo `image/*`; file salvati con nomi generati da noi.

## Segreti e dati riservati

- Chiavi WTB, segreto webhook, hash password, SMTP, coordinate bancarie: solo
  in `.env` (gitignored); `.env.example` con segnaposto.
- **Repository pubblico** (D-016): nei file versionati nessun dato commerciale
  reale (costi, disponibilità, tariffe e condizioni WTB, margini, dati dei
  clienti). Fixture con valori inventati; catture reali solo in
  `var/wtb-captures/`.
- I log oscurano `X-API-Key`, firme e token; le eccezioni mostrate all'utente
  sono generiche, i dettagli solo nel log.
- **Test "leak"** (docs/02): nessun costo e nessuna menzione del fornitore
  nelle uscite verso il cliente (HTML, JSON, frammenti, export, email, PDF).
  Punti facili da sbagliare: JSON del carrello e della scheda rapida, export,
  messaggi d'errore, nomi file, attributi `alt` delle immagini.

## Robustezza

- Error handler globale: pagina cortese, dettagli solo nel log.
- Sync tutto-o-niente e soglia di calo prodotti (docs/03): un feed rotto non
  svuota mai il catalogo.
- Backup giornaliero del database con 14 giorni di storico (docs/02); copia
  fuori dalla VPS consigliata.
