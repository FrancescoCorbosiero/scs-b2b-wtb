# SCS B2B · WTB

Catalogo sneakers B2B ad accesso riservato per i rivenditori di **SHOES &
CLOTHING RESELLING**, alimentato dall'API del fornitore **WTB**
(`https://b2b.wtbsnkrs.it/api/v1`). Il rivenditore sfoglia il catalogo (taglie,
disponibilità, tempi di partenza, prezzi), compone un carrello e invia una
**richiesta d'ordine** che paga con **bonifico**. All'arrivo del pagamento
l'admin conferma e la piattaforma crea l'ordine presso WTB in **dropship**:
WTB spedisce direttamente al rivenditore, senza prezzi nel pacco.

Riferimento funzionale: la piattaforma `scs-b2b` dello stesso titolare
(fornitore GoldenSneakers). È un riferimento, non una dipendenza: qui prezzi,
IVA, spedizione e ordini sono progettati da zero sull'API WTB.

## Leggi prima di scrivere codice

Leggi i file in ordine PRIMA di toccare il codice, e rileggi quelli pertinenti
prima di ogni modifica.

| File | Contenuto |
|---|---|
| `docs/00-decisioni.md` | Registro delle decisioni del titolare: cosa, perché, dove vive, come si torna indietro |
| `docs/01-panoramica.md` | Obiettivo, utenti, flussi, fuori scope, dati aziendali, glossario |
| `docs/02-architettura.md` | Stack, struttura, confini tra moduli, impostazioni, scheduler, test, sviluppo in Claude Code web, deploy |
| `docs/03-fornitore-wtb.md` | Modalità fixture/test/live, client HTTP, sync catalogo e stock, immagini, ricognizione M0 |
| `docs/04-prezzi-iva.md` | Offerte a fasce, margini, regimi IVA (margine/ordinario), totali |
| `docs/05-spedizione.md` | Tariffe al cliente, costo reale WTB, tempi |
| `docs/06-ordini.md` | Ciclo dell'ordine, ordine a WTB, bonifico, webhook, tracking |
| `docs/07-funzionalita.md` | Pagine e funzioni, lato cliente e admin |
| `docs/08-modello-dati.md` | Schema del database |
| `docs/09-sicurezza.md` | Autenticazione, sessioni, segreti, webhook, hardening |
| `docs/10-roadmap.md` | Milestone, domande aperte, valori già decisi |
| `docs/wtb-api.md` | Documentazione API del fornitore (fonte esterna, non modificarla se non per aggiungere ciò che manca) |

## Regole d'oro (non negoziabili)

1. **Il costo del fornitore non raggiunge MAI il cliente.** Il campo `price`
   delle offerte WTB è il NOSTRO prezzo d'acquisto: si salva come `cost` e
   resta in area admin. Mai in HTML, JSON, export, email al cliente, PDF,
   messaggi d'errore o log esposti. Un test automatico ("cost leak") lo
   verifica su tutte le uscite verso il cliente: non si spegne mai.
2. **Il fornitore non si nomina mai lato cliente.** "WTB" compare solo in area
   admin, email admin, log e codice. Le immagini prodotto si servono dal
   nostro dominio (docs/03), il tracking mostra solo link dei corrieri
   (docs/06).
3. **Le chiavi WTB e il segreto dei webhook vivono solo nell'ambiente del
   server** (`.env`, mai committato): mai nel browser, nei log, nei messaggi
   d'errore, nei fixture, nei commit. In sviluppo si usa solo la chiave di
   prova; `WTB_MODE=live` esiste solo sul server di produzione.
4. **Il repository è pubblico (D-016): nei file versionati non entrano mai dati
   commerciali reali.** Niente risposte reali di WTB, costi, disponibilità,
   tariffe WTB, ordine minimo WTB, margini reali, coordinate bancarie, dati
   dei clienti. Le fixture in `fixtures/wtb/` hanno la forma delle risposte
   vere ma valori **inventati**; le risposte reali catturate in sviluppo
   stanno solo in `var/wtb-captures/` (fuori da git). I numeri reali si
   riferiscono al titolare in chat, non nei file. Prima di ogni commit
   controlla il diff con questo in mente.
5. **Catalogo e prezzi dietro login e `noindex`.** Sono pubbliche solo le
   pagine di presentazione (indicizzabili, senza prodotti né prezzi) e la
   vetrina senza prezzi (`noindex`, prezzi tolti dai dati lato server).
6. **La fonte di verità del catalogo è WTB.** Nessun CRUD prodotti. Il sync è
   tutto o niente: un errore non svuota né corrompe mai il catalogo.
7. **Soldi in centesimi interi**, mai float; arrotondamenti in un solo punto
   (`PricingService`). Gli ordini a WTB sono idempotenti tramite `reference`.
8. **Sicurezza di base sempre**: prepared statements, escaping (Twig
   autoescape, niente `|raw` su dati esterni), CSRF su ogni POST (tranne il
   webhook, che è firmato), rate limit su login e form.
9. **Se una specifica è ambigua o manca un dato: chiedi, non assumere.** Le
   domande aperte sono in `docs/10-roadmap.md`.

## Fare e disfare: come si lavora su questo progetto

Il titolare chiederà spesso modifiche, e alcune verranno annullate. Il progetto
è costruito perché cambiare idea costi poco:

- **`docs/` è la specifica viva.** Ogni cambio di comportamento aggiorna i
  documenti nello stesso PR. Ogni decisione del titolare va in
  `docs/00-decisioni.md` (data, decisione, motivo, dove vive, come si torna
  indietro): prima di cambiare qualcosa, controlla se c'è una decisione che lo
  riguarda.
- **Una modifica = un PR piccolo**, autosufficiente e annullabile con un
  `git revert`. Commit in italiano, all'imperativo ("Aggiungi filtro per
  linea").
- **Manopole, non codice.** Ciò che il titolare cambia spesso (tariffe,
  margini, ordine minimo, regimi in vendita, soglie…) è un'impostazione da
  admin: registro tipizzato in `src/Settings/` (aggiungere una manopola = una
  voce con tipo, default, etichetta e validazione, più un test). Segreti e
  interruttori rischiosi (modalità WTB, chiavi, trigger degli ordini, kill
  switch) stanno in `.env`.
- **Confini netti.** Solo `src/Supplier/Wtb/` conosce endpoint e JSON di WTB;
  il resto lavora su modelli interni. Prezzi, IVA e spedizione sono servizi
  puri con test unitari: una regola si cambia in un punto solo.
- **Migrazioni solo in avanti**, numerate. Mai modificare una migrazione già
  applicata: per togliere o cambiare qualcosa se ne scrive una nuova.
- **Testi in `lang/`**: `lang/it.php` è la fonte (default), `lang/en.php` ha
  le stesse chiavi (fallback sull'italiano). Nessuna stringa nei template.
- **`CHANGELOG.md`**: a ogni PR una riga in italiano semplice, scritta per il
  titolare (cosa cambia per lui o per i suoi clienti).
- **`composer check`** (test + PHPStan) verde prima di ogni PR; la CI di
  GitHub Actions lo ripete.
- **`.claude/skills/verify/SKILL.md`**: come lanciare e provare l'app end to
  end (va creato in M1). Aggiornalo quando cambia il modo di provarla.
- **Mappa del codice** in fondo a questo file: aggiornala quando sposti o
  aggiungi un modulo, così chi arriva dopo trova subito dove intervenire.

## Modalità del fornitore (`WTB_MODE`)

| Modalità | Catalogo, stock, spedizioni, preventivi | Creazione ordini |
|---|---|---|
| `fixture` | file in `fixtures/wtb/`, nessuna rete | risposta finta da fixture |
| `test` | API vera con la chiave di prova (`WTB_API_KEY_TEST`): dati reali | **simulata da WTB** (`"test": true`, niente salvato, nessuna paia riservata) |
| `live` | API vera con la chiave reale (`WTB_API_KEY_LIVE`) | **ordini veri, con soldi veri** |

Si passa da una modalità all'altra cambiando una riga del `.env` e
riavviando i container. L'area admin mostra sempre un banner con la modalità
attiva. Gli ordini ricordano la modalità in cui sono nati e non si mescolano.
Dettagli in `docs/03-fornitore-wtb.md`.

## Stack (vincolante salvo nuova decisione)

- PHP 8.3, Composer, Slim 4 + PHP-DI, Twig, PDO
- **SQLite** (WAL) in sviluppo, test e produzione: un solo dialetto SQL
  (decisione D-010, docs/02)
- Tailwind CSS + Alpine.js compilati in build (Node solo per la build, mai a
  runtime), font self-hosted
- PHPMailer via SMTP (AWS SES in produzione), dompdf per la pro-forma, Monolog
- Docker Compose sulla VPS del titolare, dietro Caddy Docker Proxy (network
  esterna `caddy`), stack separato da scs-b2b

## Comandi (da tenere allineati a quelli reali)

```bash
composer install                 # dipendenze PHP (in Claude Code web: --prefer-source, docs/02)
npm ci && npm run build          # asset in public/assets
php bin/migrate.php              # applica le migrazioni
php bin/sync.php products        # sync completo del catalogo (idempotente)
php bin/sync.php stock           # sync rapido di stock e prezzi
php bin/scheduler.php            # ciclo dei job periodici (container scheduler)
composer check                   # test + PHPStan
```

## Mappa del codice

_Da compilare in M1 e tenere aggiornata: per ogni area (fornitore, prezzi, IVA,
spedizione, ordini, webhook, catalogo, account, admin, impostazioni) il
percorso dei file principali._
