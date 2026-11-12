# 01 — Panoramica

## Cos'è

Un catalogo B2B di sneakers per i rivenditori (negozi, reseller con partita
IVA) di SHOES & CLOTHING RESELLING. Il catalogo, lo stock e i costi arrivano
dall'API del fornitore WTB; il titolare ci applica i suoi margini e vende ai
rivenditori. Gli ordini vengono girati a WTB, che spedisce direttamente al
rivenditore (dropship), senza prezzi nel pacco e con fattura intestata al
titolare.

È una piattaforma sorella di scs-b2b (stesso titolare, fornitore
GoldenSneakers), ma indipendente (D-001).

## Utenti

- **Visitatore**: vede il sito pubblico e la vetrina (catalogo senza prezzi),
  può chiedere un profilo.
- **Rivenditore** (account personale, creato dall'admin o approvando una
  richiesta di profilo): vede prezzi e disponibilità, ordina, segue i suoi
  ordini e scarica le pro-forma.
- **Admin** (il titolare e i suoi collaboratori, password dedicata): gestisce
  clienti, ordini, margini, spedizioni, impostazioni, ordini a WTB.

## Flusso principale

1. Il rivenditore chiede un profilo da `/richiedi-accesso` (dati aziendali con
   partita IVA, indirizzo, referente); l'admin approva e parte l'invito via
   email per impostare la password.
2. Accede al catalogo: per ogni taglia vede le offerte disponibili, con il
   regime IVA (margine o ordinario), i pezzi in pronta consegna e in stock
   esteso, i tempi di partenza e il suo prezzo.
3. Compone il carrello e invia la **richiesta d'ordine** (minimo 5 paia, D-014):
   la piattaforma verifica la disponibilità presso WTB, salva l'ordine e
   manda al cliente le istruzioni di bonifico.
4. Arrivato il bonifico, l'admin **conferma**: parte l'email con la
   pro-forma PDF e la piattaforma crea l'ordine presso WTB (D-009).
5. L'admin paga WTB con bonifico (le istruzioni arrivano da WTB e si vedono in
   admin). WTB spedisce e ci avvisa via webhook: il cliente riceve il
   tracking per email e lo trova nella sua area.

## Fuori scope (in v1)

- Pagamenti online, carte, checkout con pagamento immediato.
- Fatture: la piattaforma emette solo **pro-forma**; la fattura la fa
  l'amministrazione del titolare.
- Resi e reclami: gestiti a mano fuori dalla piattaforma.
- Prodotti non WTB, pro-forma manuali, accesso ospite, verifica VIES
  automatica (rinviati, D-013).
- Qualsiasi modifica a scs-b2b o al sito WordPress principale.

## Dati aziendali (default in config, da confermare col titolare)

- Ragione sociale: SHOES & CLOTHING RESELLING OFFICIAL STORE
- Email pubblica: shoes.clothing_reselling@yahoo.com
- Telefono e WhatsApp: +39 392 772 0691 (`https://wa.me/393927720691`)
- Sede: Via Paolo Ricci 12/a, 76121 Barletta (BT)
- P.IVA: 091460600729
- Email amministrativa (riceve gli ordini, non mostrata): info@shoesclothingstore.com
- Sito principale: https://shoesclothingstore.com/
- Dominio della piattaforma: **da decidere** (domanda aperta, docs/10)

## Glossario

| Termine | Significato |
|---|---|
| **Variante** | Una taglia di un prodotto (`variant_id` WTB), con EAN e taglie EU/US/UK |
| **Offerta** | Una combinazione variante × regime IVA × tipo di stock, con disponibilità, costo e tempi |
| **Regime del margine** (`margin`) | Il costo WTB è un prezzo finale con IVA compresa e non esposta, non detraibile |
| **Regime ordinario** (`ordinary`) | Il costo WTB è netto; l'IVA si aggiunge in fattura |
| **Pronta consegna** (`immediate`) | Paia nel magazzino WTB, partenza in 1-2 giorni lavorativi |
| **Stock esteso** (`extended`) | Paia in un magazzino esterno, partenza in 3-5 giorni lavorativi, prezzo diverso |
| **Fasce** | Per una variante e un regime: prima si consumano le paia in pronta consegna, poi quelle in stock esteso, ciascuna col suo prezzo |
| **Costo** | Il `price` WTB: il nostro prezzo d'acquisto. Riservato (regola d'oro 1) |
| **Prezzo di vendita** | Costo + margine del titolare, arrotondato: quello che vede il cliente |
| **Reference** | Il nostro codice d'ordine inviato a WTB: rende la creazione idempotente |
| **Dropship** | WTB spedisce direttamente al cliente del titolare, senza prezzi nel pacco |
