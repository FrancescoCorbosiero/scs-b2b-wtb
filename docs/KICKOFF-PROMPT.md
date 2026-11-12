# Avvio con Claude Code (web)

Questo file serve solo per il primo avvio; dopo si può togliere.

## Prima di incollare il prompt

1. **Repository**: `scs-b2b-wtb`, con il contenuto di questo kit nella
   radice (`CLAUDE.md`, `docs/`) come primo commit su `main`. È pubblico
   (D-016): nessun dato commerciale reale nei file versionati.
2. **Ambiente cloud** (su claude.ai/code, selettore dell'ambiente sotto la
   casella del messaggio → nuovo ambiente o impostazioni di quello esistente):
   - Accesso di rete: **Trusted** (il default) basta per Composer, npm e GitHub.
   - **Segreto di rete** (Network secrets → Add secret): nome "WTB prova";
     siti consentiti `b2b.wtbsnkrs.it`; intestazione personalizzata con nome
     `X-API-Key`, **prefisso vuoto**, valore = la chiave di prova `wtb_test_…`.
     Il proxy la aggiunge alle richieste verso WTB e la chiave non entra mai
     nella sessione.
   - Se il modulo non ti lascia scegliere l'intestazione: accesso di rete
     **Custom** con `b2b.wtbsnkrs.it` (spunta "includi la lista di default")
     e la variabile d'ambiente `WTB_API_KEY_TEST=wtb_test_…`.
   - **Mai la chiave reale** nell'ambiente di Claude Code: va solo nel `.env`
     del server, al momento del live (docs/06 § Checklist).
3. Facoltativo: se l'interfaccia ti permette di aggiungere un secondo
   repository alla sessione, aggiungi anche `scs-b2b` come riferimento.

## Prompt da incollare

---

Leggi `CLAUDE.md`, poi tutti i file di `docs/` in ordine (00 → 10) e
`docs/wtb-api.md`: sono la specifica completa del progetto, con le decisioni
già prese e le regole non negoziabili.

Se in questa sessione è disponibile anche il repository `scs-b2b`, usalo solo
come riferimento d'implementazione (struttura, sicurezza, email, PDF, stile
grafico): riprendi ciò che serve adattandolo, senza creare dipendenze tra i
due progetti. Se non c'è, la specifica basta.

Poi:

1. **M0 — ricognizione dell'API.** Verifica che
   `https://b2b.wtbsnkrs.it/api/v1` sia raggiungibile: la chiave di prova è
   nell'ambiente (segreto di rete del proxy, oppure variabile
   `WTB_API_KEY_TEST`). Non chiedermela e non scriverla mai in file, log,
   commit o messaggi. Esegui i controlli di `docs/03` § Ricognizione M0
   (gli ordini solo con la chiave di prova, che li simula). Il repository è
   pubblico (regola d'oro 4): salva le risposte reali solo in
   `var/wtb-captures/`, crea in `fixtures/wtb/` fixture con la stessa forma ma
   valori inventati, e in § Esiti M0 annota comportamenti e differenze, mai
   valori commerciali. Poi riportami qui in chat: profilo WTB, zone e tariffe
   con la proposta di tariffe fisse (`docs/05`), host delle immagini e se si
   scaricano senza chiave, forma di `/stock/` e di `/quote/`, differenze
   dalla documentazione. Se l'API non è raggiungibile, dimmelo con l'errore
   preciso e prosegui con fixture costruite dagli esempi di
   `docs/wtb-api.md`.
2. **Piano della M1.** Presentami struttura delle cartelle, rotte, schema
   iniziale del database, registro delle impostazioni, scheduler e CI.
   Aspetta la mia conferma prima di scrivere codice.
3. Procedi una milestone alla volta (`docs/10`), con PR piccoli: test,
   documenti aggiornati e una riga in `CHANGELOG.md` per ciascuno. A fine
   milestone riepiloga e fai i controlli di "fatto".
4. Davanti a una domanda aperta di `docs/10` o a qualsiasi ambiguità:
   fermati e chiedi.

In questa sessione mai `WTB_MODE=live` e mai la chiave reale. Ricorda le
regole d'oro: il costo WTB non arriva mai al cliente e il fornitore non si
nomina mai lato cliente.

---
