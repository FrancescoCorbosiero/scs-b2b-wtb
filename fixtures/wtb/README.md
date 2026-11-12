# Fixture del fornitore

Risposte finte dell'API del fornitore, usate dalla modalità `WTB_MODE=fixture`
e dai test automatici. Hanno la **forma** esatta delle risposte reali
osservate nella ricognizione M0 (docs/03 § Esiti M0) ma **valori inventati**:
SKU, nomi, prezzi, disponibilità, tariffe, numeri d'ordine, IBAN e tracking
sono di fantasia (D-016, repository pubblico). Le risposte vere non entrano
mai qui: stanno solo in `var/wtb-captures/`, fuori da git.

| File | Endpoint | Note |
|---|---|---|
| `shipping.json` | `GET /shipping/` | profilo `retail`, 4 zone inventate, trasporto stock esteso a scaglioni |
| `products-page-1.json`, `products-page-2.json` | `GET /products/?page=N` | 10 prodotti; `count` (12) più alto dei prodotti consegnati, come l'API vera |
| `products-extra.json` | `GET /products/?sku=…` | prodotto presente solo nello stock: serve all'import mirato |
| `stock.json` | `GET /stock/` | non paginato; due disponibilità diverse dal catalogo; `ZX1010-010` assente (diventa esaurito); `ZX1011-110` nuovo |
| `quote.json`, `quote-extended-only.json` | `POST /quote/` | esempi per i test del mapper (`"shipping": "0"` nel secondo) |
| `order-created.json` | `POST /orders/` (live) | con `payment_instructions` in forma **presunta** (in prova arrivano `null`) |
| `order-test.json` | `POST /orders/` (chiave di prova) | `"test": true`, `"number": "TEST"`, `"id": null` |
| `order-detail.json`, `orders-list.json` | `GET /orders/<id>/`, `GET /orders/` | con tracking di un corriere |
| `webhooks/*.json` | eventi in arrivo | busta `{id, event, created_at, data}` |
| `errors/*.json` | risposte d'errore | 400 per campo, 400 senza messaggio, 401, 404, 429 |
| `images/*.jpg` | immagini prodotto | segnaposto disegnati da noi; gli URL senza file usano `placeholder.jpg` |

Copertura voluta: entrambi i regimi (anche sulla stessa taglia, `ZX1009-900`),
entrambi i tipi di stock (anche sulla stessa taglia), taglie EU con frazioni
(`36 2/3`), US con suffissi `Y`, `C`, `W` e vuote, GS riconoscibile solo dal
nome (`ZX1002-200`), PS (`ZX1003-300`), linea vuota, virgolette nel nome,
disponibilità alta, un esaurito.

I costi hanno sempre centesimi dispari (es. `71.37`): così i test "leak"
possono cercarli nelle uscite verso il cliente senza falsi allarmi (i prezzi
di vendita sono arrotondati all'euro e l'IVA al 22% dà sempre centesimi pari).

In modalità fixture preventivi e ordini non sono file fissi: li calcola un
piccolo simulatore (`src/Supplier/Wtb/Fixture/`) con le regole viste in M0
(ripartizione pronta/estesa, tariffa di zona solo sulle paia in pronta
consegna, trasporto dello stock esteso, IVA, errori 400).
