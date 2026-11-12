# 10 — Roadmap e domande aperte

Ogni milestone è uno o più PR piccoli, ognuno con test, documenti aggiornati e
una riga in `CHANGELOG.md`. A fine milestone: riepilogo al titolare e
controlli di "fatto".

## Milestone

**M0 — Ricognizione dell'API (nessun codice applicativo)**
Controlli di docs/03 § Ricognizione con la chiave di prova; risposte reali
solo in `var/wtb-captures/`; fixture con la stessa forma e valori inventati in
`fixtures/wtb/`; proposta di tariffe fisse per zona (docs/05).
✔ Fatto quando: § Esiti M0 compilata (comportamenti, nessun valore
commerciale), fixture salvate con valori inventati e senza segreti, proposta
di tariffe inviata al titolare in chat, differenze dalla documentazione
annotate.

**M1 — Fondamenta**
Scaffolding Slim/Twig/PHP-DI, SQLite e migrazioni, registro impostazioni con
pagina admin e storico, layout e i18n IT/EN, login admin e **account clienti**
(creazione da admin, invito, login, reset, profilo) con lockout, pagine
d'errore, log, email (trasporto `log` in sviluppo, SMTP in produzione), CI,
`.claude/skills/verify/SKILL.md`, README, `CHANGELOG.md`, file Docker di
produzione, mappa del codice in CLAUDE.md.
✔ Fatto quando: l'app gira in locale con `php -S`, admin e cliente fanno login,
un'impostazione cambiata dall'admin compare nello storico, `composer check`
verde in CI.

**M2 — Fornitore WTB**
Client (modalità, limiti, errori), mapper, `products_sync` e `stock_sync`,
scheduler e `job_runs`, categoria di taglia, proxy immagini, `/admin/sync`.
✔ Fatto quando: il sync da fixture e da API di prova popola il database; un
secondo giro è idempotente; un errore a metà non scrive nulla; la soglia di
calo prodotti blocca; le immagini escono dal nostro dominio.

**M3 — Prezzi e catalogo**
Regole margine e reprice, fasce, `/catalogo` (card, scheda rapida, filtri,
ricerca, ordinamenti, paginazione, taglie EU/US/UK), export Excel, vetrina,
sito pubblico, richiesta di profilo e approvazione.
✔ Fatto quando: filtri componibili via URL, prezzi e fasce corretti nei test
a tabella, vetrina senza prezzi (HTML, JSON, frammenti), test "leak" verde su
catalogo, vetrina ed export.

**M4 — Carrello, checkout, IVA e spedizione**
Carrello con fasce e regimi, `TaxPolicy`, zone e tariffe, preventivo WTB al
checkout, ripensamento, ordini e righe, email con istruzioni di bonifico,
conferma admin con pro-forma PDF, riallineamento, annullamento.
✔ Fatto quando: flusso completo in modalità `fixture` e `test` (con il
preventivo vero), totali corretti nei test per ogni combinazione
paese × regime × spedizione, SMTP spento non perde ordini, test "leak" verde
su email e PDF.

**M5 — Ordini WTB e webhook**
`SupplierOrderService` (trigger, payload, idempotenza, errori), istruzioni di
pagamento, `/webhooks/wtb` (firma, duplicati, stati), `orders_poll`, email di
tracking, `/admin/ordini-fornitore`, `/admin/webhook` con "Simula evento",
avvisi in dashboard.
✔ Fatto quando: ordine creato con la chiave di prova (`simulated`) e in
fixture; ritentativo con lo stesso reference senza doppioni; webhook firmati
validi/non validi/duplicati/fuori ordine gestiti nei test; tracking al
cliente solo con link di corrieri.

**M6 — Messa in produzione**
Revisione sicurezza e mobile, deploy sulla VPS in modalità `test`, backup e
ripristino provati, poi checklist di docs/06 per passare a `live`.
✔ Fatto quando: la checklist di docs/06 è completa e il primo ordine reale è
arrivato a destinazione.

**Dopo la v1 (se il titolare li chiede)**: prodotti propri, pro-forma
manuali, verifica VIES automatica, descrizioni SEO di WTB, report vendite.

## Domande aperte (chiedere, NON assumere)

**Al titolare**

1. Dominio o sottodominio della piattaforma, e nome mostrato ai clienti.
2. Tariffe di spedizione definitive (dopo la proposta di M0) ed eventuali
   soglie di gratuità per zona.
3. Margini: va bene il 5% di default? Servono regole per brand fin
   dall'inizio? (quelle di scs-b2b erano tarate sui costi GoldenSneakers)
4. Ordine minimo: 5 paia anche qui?
5. Coordinate bancarie per i bonifici dei clienti; indirizzi email che
   ricevono gli ordini.
6. Regimi in vendita: entrambi, o uno solo? (dopo il commercialista)
7. Serie pro-forma separata `PFW` (D-015): va bene?
8. WTB addebita le mancate consegne: si girano al cliente? Va scritto nelle
   condizioni?
9. Flag "In evidenza": serve?
10. Vetrina: resta `noindex` (default) o si vuole indicizzata?

**Al commercialista** (prima del live)

11. Rivendita di beni comprati da WTB in regime del margine: regime da
    applicare alle vendite ai rivenditori, diciture obbligatorie, vendite a
    clienti UE ed extra-UE di beni a margine, trattamento della spedizione
    negli ordini a margine o misti, pro-forma con due blocchi separati.
12. Clienti UE con righe ordinarie: la verifica VIES va fatta a ogni ordine?
    Serve quella automatica?

**A WTB**

13. Come si annulla un ordine? La documentazione non prevede un endpoint.
14. Ordini a bonifico non pagati: per quanto restano aperti, le paia restano
    riservate, ci sono penali? (decide se passare a `WTB_ORDER_TRIGGER=on_request`)
15. Dropship: chi risulta mittente su pacco ed etichetta? WTB scrive o manda
    SMS al destinatario (`ship_to.email`/`phone`)? Con quale nome?
    (decide `WTB_SHIP_TO_EMAIL`)
16. Ordine misto pronta consegna + stock esteso: uno o due pacchi? Con quali
    tempi?
17. La sezione "Regole" della documentazione (manca nel testo copiato):
    condizioni di rivendita ad altri rivenditori, uso delle foto.
18. Immagini: si possono scaricare e ripubblicare dal nostro dominio? Servono
    la chiave?
19. Resi, mancate consegne, difetti: procedura e costi.
20. Webhook: arrivano anche per ordini creati dal portale? Il segreto è unico
    per account?

## Valori già decisi (non richiedere di nuovo)

- Registro completo in `docs/00-decisioni.md`. In sintesi: piattaforma
  separata (D-001); clienti pagano con bonifico (D-003); WTB pagato con
  bonifico (D-004); in vendita pronta consegna e stock esteso (D-005);
  spedizione a tariffa fissa per zona (D-006); IVA per regime, da validare
  (D-007); passaggio test/live con `WTB_MODE` (D-008); ordine WTB alla
  conferma del pagamento (D-009); SQLite (D-010); immagini dal nostro dominio
  (D-011); fornitore mai nominato al cliente (D-012); repository pubblico,
  nessun dato commerciale reale nei file (D-016).
- Valuta EUR; taglie primarie EU; lingue IT (default) + EN; area admin solo
  in italiano; paesi serviti UE-27 + GB + CH; partita IVA obbligatoria.
- Deploy: Docker Compose sulla VPS del titolare dietro Caddy Docker Proxy,
  stack separato da scs-b2b.
