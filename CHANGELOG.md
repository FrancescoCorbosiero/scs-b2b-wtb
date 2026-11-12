# Registro delle modifiche

Una riga per ogni modifica, in italiano semplice: cosa cambia per il titolare
o per i suoi clienti. Le più recenti in alto.

## 12/11/2026

- I clienti ordinano: carrello con le fasce pronta consegna / stock esteso,
  "Prendi tutto" e "Svuota", spedizione e IVA stimate per paese, ordine
  minimo; richiesta d'ordine con verifica della disponibilità presso il
  fornitore, qualche secondo per ripensarci, email con le coordinate del
  bonifico; in admin conferma del pagamento con pro-forma PDF (serie PFW),
  riallineamento delle quantità e annullamento; area "I miei ordini" con le
  pro-forma.
- Catalogo per i clienti con filtri, ricerca, ordinamenti, taglie EU/US/UK,
  scheda rapida con le fasce pronta consegna / stock esteso ed export Excel;
  vetrina pubblica senza prezzi; sito pubblico (home, come ordinare,
  spedizioni, contatti) con tariffe e tempi reali; modulo "Richiedi
  accesso" con approvazione dall'admin; regole margine da Admin › Margini
  con ricalcolo immediato dei prezzi; pagina Prodotti con costi e margini e
  flag "In evidenza".
- Il catalogo arriva dal fornitore: sync completo ogni 3 ore e dello stock
  ogni 15 minuti (anche a mano da Admin › Sync), tutto o niente, con blocco
  se il feed cala troppo; immagini servite dal nostro dominio; pagina Sync
  con l'esito dei job e le tariffe del fornitore; backup giornaliero del
  database; avviso in dashboard se il fornitore rifiuta la chiave.
- Base della piattaforma: area admin con password dedicata e banner della
  modalità del fornitore, impostazioni modificabili con storico e "Ripristina
  default", clienti creati dall'admin con invito via email (link valido 72
  ore), login, password dimenticata, profilo e cambio password, blocco dopo 5
  tentativi sbagliati, sito in italiano e inglese, file per la produzione
  (Docker) e controlli automatici a ogni modifica.
- Ricognizione dell'API del fornitore fatta con la chiave di prova: annotati
  in docs/03 comportamenti e differenze dalla documentazione; create le
  fixture con valori inventati; i prodotti esauriti restano visibili 30
  giorni (D-017, modificabile).
