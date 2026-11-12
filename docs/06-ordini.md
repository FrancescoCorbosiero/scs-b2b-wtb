# 06 — Ordini: cliente, WTB, webhook

## Stati

**Ordine del cliente** (`orders.status`):

```
pending (in attesa di bonifico) ─► confirmed (pagato, pro-forma emessa) ─► shipped (tracking)
   └──────────────► cancelled ◄──────────────┘
```

**Ordine al fornitore** (`supplier_orders.status`): ricalca gli stati WTB
`received → confirmed → paid → shipped` oppure `cancelled`, più due stati
locali: `simulated` (creato con la chiave di prova) ed `error` (creazione non
riuscita, si può ritentare in sicurezza).

## Checkout (richiesta d'ordine)

Campi: dati di fatturazione dal profilo (azienda, P.IVA, indirizzo con
**provincia** per l'Italia, paese), contatto (nome, email, telefono),
spedizione **alla sede** (default) o **a un altro indirizzo** (destinatario,
azienda, via, CAP, città, provincia, paese, telefono), note. La zona di
spedizione segue il paese di destinazione e deve essere servita. CSRF,
honeypot, max 3 invii/ora per account e IP.

All'invio, in quest'ordine:

1. **Controllo locale**: quantità entro le offerte correnti, ordine minimo
   (il maggiore tra `orders.min_items` e il minimo WTB del profilo
   Wholesaler).
2. **Preventivo WTB** (`POST /quote/`, righe `variant_id` + `vat_regime` +
   `quantity`, `ship_to` con il solo paese):
   - paia non più disponibili ⇒ il carrello si riduce, il cliente vede cosa è
     cambiato e riconferma;
   - meno paia in pronta consegna del previsto (quindi più paia in fascia
     estesa) ⇒ prezzi ricalcolati sulla ripartizione WTB, messaggio "Prezzi
     aggiornati", il cliente riconferma;
   - WTB irraggiungibile ⇒ si accetta col solo controllo locale e l'ordine è
     marcato `quote_unverified` per l'admin.
3. **Ripensamento**: conto alla rovescia di `orders.rethink_seconds` con
   "Annulla" prima dell'invio reale (senza JavaScript l'invio è diretto).
4. **Salvataggio** in `pending`: numero d'ordine, anagrafica e indirizzo
   congelati, righe congelate (prodotto, taglie, EAN, regime, ripartizione per
   fascia, prezzi di vendita; costi e preventivo WTB solo per l'admin), totali
   (docs/04, docs/05), regime IVA del cliente, lingua.
5. **Email**: al cliente nella sua lingua, riepilogo e istruzioni di bonifico
   (coordinate `BANK_*`, importo, causale = numero d'ordine) con l'avviso che
   l'ordine si conferma all'arrivo del pagamento; all'admin, in italiano,
   tutto compresi costi, margine, preventivo WTB ed eventuali avvisi.
6. Con `WTB_ORDER_TRIGGER=on_request`: creazione immediata dell'ordine a WTB.
7. Carrello svuotato, pagina di conferma.

Un errore SMTP non perde mai l'ordine: è già salvato, i flag
`email_customer_sent` / `email_admin_sent` restano a 0 e l'errore va nel log.

## Azioni admin sull'ordine

- **Conferma pagamento ricevuto** → `confirmed`, numero pro-forma
  (`RECEIPT_PREFIX`, serie annuale), email al cliente con la **pro-forma PDF**.
  Per clienti UE con righe ordinarie chiede la spunta "P.IVA verificata su
  VIES" (docs/04). Poi, con `WTB_ORDER_TRIGGER=on_confirm`, crea l'ordine a
  WTB e mostra l'esito; se la creazione fallisce, la conferma resta valida.
- **Riallinea** (finché non esiste un ordine WTB): quantità riga per riga
  con lo stock corrente accanto, totali ricalcolati, eventuale nuova email al
  cliente con l'importo aggiornato.
- **Crea / ritenta ordine WTB**: in modalità `manual` o dopo un errore.
- **Annulla** → `cancelled`. Se esiste un ordine WTB vivo, promemoria:
  l'API non ha un annullamento, va annullato dal portale WTB (link); lo stato
  si allinea col webhook `order.cancelled`.
- **Segna come spedito** a mano, con tracking: solo come ripiego se il
  webhook non arriva.

## Ordine a WTB (`SupplierOrderService`)

| `.env` | Default | Uso |
|---|---|---|
| `WTB_ORDERS_ENABLED` | 1 | kill switch: 0 = nessun ordine parte, nemmeno a mano |
| `WTB_ORDER_TRIGGER` | `on_confirm` | `on_confirm` / `on_request` / `manual` (D-009) |
| `WTB_PAYMENT_METHOD` | `bank_transfer` | oppure `balance` (D-004) |
| `WTB_REFERENCE_PREFIX` | `SCSW` | prefisso del reference |
| `WTB_SHIP_TO_EMAIL` | `admin` | email passata a WTB nel `ship_to`: `admin` (la nostra) finché WTB non chiarisce se scrive al destinatario; `customer` dopo |
| `WTB_MAX_ORDER_EUR` | 0 | tetto sul costo stimato di un ordine (0 = nessuno) |

Passi:

1. Controlli: kill switch, modalità, tetto di spesa.
2. `POST /quote/`: se mancano paia, nessuna creazione; avviso all'admin con
   l'elenco (`stock`) e invito a riallineare.
3. `POST /orders/`:

   ```json
   {
     "reference": "SCSW-123-1",
     "lines": [{ "variant_id": 1234, "vat_regime": "margin", "quantity": 2 }],
     "ship_to": {
       "full_name": "…", "company": "…", "street": "…", "city": "…",
       "zip_code": "…", "province": "…", "country": "IT",
       "phone": "…", "email": "…"
     },
     "payment_method": "bank_transfer",
     "notes": ""
   }
   ```

   Una riga per variante + regime: il tipo di stock lo assegna WTB (prima la
   pronta consegna). M0: la combinazione `variant_id` + `vat_regime` è
   accettata. Mai prezzi né note del cliente nel payload. Le righe della
   risposta di WTB non dicono il tipo di stock: la ripartizione per fascia si
   prende dal preventivo e resta quella congelata nelle righe dell'ordine.
4. Risposte: **201** creato; **200** reference già presente ⇒ è lo stesso
   ordine, si aggiorna e basta; errore di rete, timeout o 5xx ⇒ nuovo
   tentativo con lo **stesso** reference (max 3), poi stato `error` col bottone
   "Ritenta" (sicuro); **400** ⇒ fallimento certo, messaggio WTB all'admin.
5. Totale WTB diverso dal costo atteso di oltre 1 € ⇒ avviso all'admin.
6. Bonifico: le `payment_instructions` (IBAN, causale, importo) compaiono
   nell'ordine con i bottoni "copia", nella lista "Da pagare a WTB" della
   dashboard e nell'email admin. Il pagato arriva col webhook `order.paid`.
   M0: con la chiave di prova WTB restituisce `payment_instructions: null`,
   per cui la forma vera si vedrà solo in live; il codice mostra tutte le
   chiavi che arrivano (IBAN, causale e importo evidenziati se riconosciuti).

**Reference**: `<prefisso>-<id ordine>-<tentativo>`. Il tentativo cresce solo
se un ordine WTB precedente per lo stesso ordine è stato annullato.

**Modalità test e fixture**: la risposta (`"test": true` / fixture) si salva
con stato `simulated`; nessuna rilettura, nessun webhook atteso. M0: in prova
lo stesso `reference` risponde sempre 201 (WTB non salva nulla), quindi
l'idempotenza con risposta 200 si potrà osservare solo in live.

## Webhook WTB (`POST /webhooks/wtb`)

- Rotta pubblica fuori da sessione e CSRF; si legge il corpo grezzo.
- **Firma**: intestazione `X-WTB-Signature: t=<unix>,v1=<hex>`;
  HMAC-SHA256 di `<t>.<corpo grezzo>` con `WTB_WEBHOOK_SECRET`, confronto a
  tempo costante; richieste più vecchie di 5 minuti rifiutate. Firma non
  valida ⇒ 401 e log (senza segreto né corpo completo).
- **Duplicati**: `X-WTB-Delivery` è unico in `webhook_events`; un duplicato
  risponde 200 senza rielaborare.
- Si salva l'evento, si aggiornano gli stati, le email partono dopo la
  risposta: **2xx entro 10 secondi** (altrimenti WTB riprova fino a 8 volte in
  circa un giorno).
- L'ordine si trova dal `reference` nei dati (o dall'id WTB). Ordine
  sconosciuto ⇒ evento salvato, 200, avviso in admin.
- Gli stati non tornano mai indietro (eventi fuori ordine), tranne
  `cancelled`.

| Evento | Effetto |
|---|---|
| `order.created`, `order.confirmed` | stato dell'ordine al fornitore |
| `order.paid` | pagato a WTB |
| `order.shipped` | tracking salvato; ordine del cliente `shipped`; email di spedizione al cliente |
| `order.tracking_updated` | tracking aggiornato; email solo per numeri nuovi |
| `order.updated` | importi e righe riletti; differenze segnalate all'admin |
| `order.cancelled` | annullato; email all'admin (il cliente non viene avvisato in automatico: rimborso e comunicazione li decide l'admin) |
| `ping` | 200 |

## Tracking al cliente

Email nella lingua del cliente con corriere e numero di spedizione; il link
compare solo se il dominio dell'URL è tra i corrieri di
`shipping.carrier_domains` (mai un URL del fornitore, regola d'oro 2). Lo
stesso in `/account/ordini`.

## Rete di sicurezza (`orders_poll`)

Ogni ora: gli ordini WTB live non conclusi e fermi da più di 2 ore si rileggono
con `GET /orders/?reference=…` e passano dalle stesse transizioni dei webhook
(stesso codice).

## Checklist prima di `WTB_MODE=live`

1. IVA (docs/04) validata dal commercialista, diciture aggiornate.
2. Risposte di WTB alle domande aperte (docs/10), decisioni aggiornate.
3. Sul server: `WTB_API_KEY_LIVE`, `WTB_WEBHOOK_SECRET`; URL
   `https://<dominio>/webhooks/wtb` impostato nel portale WTB; evento `ping`
   ricevuto (visibile in `/admin/webhook`).
4. Tariffe di spedizione e margini confermati dal titolare.
5. Backup giornaliero attivo e un ripristino provato.
6. Primo ordine reale piccolo seguito dall'inizio alla fine: preventivo,
   creazione, bonifico a WTB, webhook, tracking, email al cliente.
