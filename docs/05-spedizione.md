# 05 — Spedizione

## Cosa paga il cliente (D-006)

Una **tariffa fissa per ordine**, in base alla **zona di destinazione** (il
paese dell'indirizzo di spedizione; l'IVA invece segue il paese di
fatturazione, docs/04). Zone e tariffe si modificano da `/admin/spedizione`
(tabella `shipping_zones`; i paesi stanno in `countries` con la loro zona).

| Zona | Paesi | Tariffa per ordine | Gratis da |
|---|---|---|---|
| Italia | IT | 15,00 € *(segnaposto)* | — |
| UE | gli altri 26 Stati membri | 30,00 € *(segnaposto)* | — |
| Extra-UE | GB, CH | 45,00 € *(segnaposto)* | — |

- **Taratura in M0**: per ogni zona si calcola con `GET /shipping/` (e un
  `POST /quote/` di prova) quanto WTB fa pagare un ordine di `orders.min_items`
  paia tutte in pronta consegna verso un paese della zona, si arrotonda ai 5 €
  superiori e si propone al titolare. Esempio con le tariffe d'esempio della
  documentazione WTB (15 € il primo paio + 4 € ogni paio in più): 5 paia =
  31 € ⇒ tariffa 35 €. I costi WTB reali usati per il calcolo si riportano al
  titolare in chat, non nei file (D-016); le tariffe decise per i clienti
  vivono nel database (sono comunque pubbliche su `/spedizioni`). Fatto il
  12/11/2026: proposta inviata in chat, insieme al caso peggiore degli ordini
  misti pronta + estesa (le paia estese pagano un trasporto a parte, vedi
  sotto).
- "Gratis da N paia" è facoltativo per zona (vuoto = mai).
- La spedizione è una riga del totale, col trattamento IVA di docs/04.
- I paesi fuori dalle zone non sono serviti: non compaiono nei selettori.

## Cosa paghiamo a WTB (solo admin)

WTB addebita per pacco e per zona (profilo Retailer: primo paio + ogni paio in
più; profilo Wholesaler: tariffe del portale), più `extended_stock_transport`
per le paia da stock esteso (gratis oltre una soglia di paia), più un
eventuale addebito per mancata consegna.

Come lo calcola davvero (M0, docs/03 § Esiti M0):

- la tariffa di zona conta **solo le paia in pronta consegna**;
- le paia da stock esteso pagano solo `extended_stock_transport`, a scaglioni
  per numero di paia estese, uguale per tutte le zone e gratis da una soglia;
- con almeno una riga in regime ordinario WTB applica l'IVA anche a tutta la
  spedizione; un ordine solo a margine non ha IVA nemmeno sulla spedizione.

Il nostro costo di spedizione di un ordine è quindi
`shipping` + `extended_stock_transport` del preventivo (= `shipping_total`),
IVA esclusa.

- Il costo esatto di ogni ordine arriva da `POST /quote/` (checkout e
  creazione ordine) e si salva sull'ordine.
- Ogni ordine in admin mostra **"Spedizione incassata / costo WTB / differenza"**;
  la dashboard mostra il saldo degli ultimi 30 giorni. Se il saldo è
  negativo, è il segnale per ritoccare le tariffe.
- `/admin/spedizione` mostra le tariffe WTB correnti (dall'ultimo
  `GET /shipping/`) accanto alle nostre.

## Tempi

- **Partenza**: dalle offerte (`ships_in_days`): di solito 1-2 giorni
  lavorativi in pronta consegna, 3-5 in stock esteso. Per un ordine misto vale
  la più lunga (finché WTB non chiarisce se spedisce in uno o due pacchi,
  docs/10).
- **Transito**: per zona, in `shipping_zones` (default Italia 1-2, UE 2-4,
  Extra-UE 3-6 giorni lavorativi).
- Pagina `/spedizioni`, scheda rapida e checkout mostrano la **consegna
  stimata** = partenza + transito, letta dai valori reali (cambiando i dati
  cambiano anche i testi).
- **Extra-UE**: se WTB indica `duties_paid_by_recipient`, il checkout avvisa
  che dazi e oneri doganali sono a carico del destinatario.
