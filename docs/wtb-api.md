# Documentazione API WTB (fonte esterna)

> Testo copiato dal portale WTB (b2b.wtbsnkrs.it, guide API) e fornito dal
> titolare il 12/11/2026. Non modificarlo: le nostre scelte stanno in docs/03
> e docs/06. **Manca la sezione «Regole»** (il testo copiato si interrompe sul
> titolo): incollarla in fondo appena disponibile.

---

Per il programmatore: l'API
Se usi il Connettore Shopify o i file, questa parte non ti serve.

Sicurezza. La chiave «catalogo e ordini» va solo sul server (variabile d'ambiente), mai nel JavaScript del browser, in un'app pubblica o in un repository. Inizia con la chiave di prova (wtb_test_…): stessi dati, ordini simulati.
Autenticazione
X-API-Key: wtb_xxxxxxxxxxxxxxxxxxxxxxxx
Indirizzo base: https://b2b.wtbsnkrs.it/api/v1 · solo JSON · limite 120 richieste al minuto per chiave (HTTP 429 oltre il limite). Errori: corpo JSON con il messaggio (400 dati non validi, 401 chiave errata, 403 non consentito, 404 non trovato).

GET /products/
Prodotti disponibili, a pagine. Parametri: page, page_size (max 100), brand, category, search (SKU, nome o EAN), sku=A,B,C. Ogni prodotto ha anche line (modello, es. 9060), gender (men, women, kids, unisex) e category_path, l'albero di categorie scelto da te nel portale (es. ["Sneakers", "New Balance", "9060"]). Puoi filtrare anche per line (es. line=9060) e gender (es. gender=kids). Se nel portale hai attivato «Solo la mia selezione», catalogo, stock e file mostrano solo i prodotti scelti; aggiungi all=1 per vedere comunque tutto il catalogo.

{
  "count": 240, "page": 1, "page_size": 50, "has_more": true, "currency": "EUR",
  "results": [{
    "sku": "DH2920-111", "name": "Nike Air Force 1 Low LE Triple White (GS)", "brand": "Nike",
    "category": "Sneakers", "description": "…", "images": ["https://…/photo.jpg"], "total_available": 41,
    "sizes": [{
      "variant_id": 1234, "size_eu": "38", "size_us": "5.5Y", "size_uk": "5", "ean": "0195…",
      "offers": [
        {"vat_regime": "margin", "stock_type": "immediate", "available": 12, "price": "63.84", "ships_in_days": "1-2"},
        {"vat_regime": "margin", "stock_type": "extended",  "available": 30, "price": "65.55", "ships_in_days": "3-5"}
      ]}]
  }]
}
Campo	Significato
price	Il tuo prezzo d'acquisto per pezzo in EUR. ordinary: netto, IVA aggiunta in fattura secondo il tuo paese e la tua partita IVA. margin: prezzo finale, IVA compresa e non esposta (regime del margine, non detraibile).
stock_type	immediate = nel nostro magazzino, parte in 1-2 giorni lavorativi. extended = parte in 3-5 giorni lavorativi. Se ordini più pezzi di quelli immediate, il resto è al prezzo extended.
available	Pezzi che puoi vendere adesso (un po' meno dello stock reale, per evitare vendite doppie).
GET /products/<sku>/
Un prodotto, stesso formato.

GET /stock/
Elenco compatto per la sincronizzazione frequente (una riga per taglia, regime IVA e tipo di stock). Consigliato: ogni 15-30 minuti.

{"count": 812, "updated_at": "…", "results": [
  {"sku": "DH2920-111", "variant_id": 1234, "size_eu": "38", "ean": "0195…", "vat_regime": "margin",
   "stock_type": "immediate", "available": 12, "price": "63.84", "ships_in_days": "1-2"}]}
GET /shipping/
Le tue tariffe: Retailer prezzo per pacco per zona e addebito per mancata consegna; Wholesaler tariffe del portale e ordine minimo. Le paia extended aggiungono extended_stock_transport in base al numero di paia esterne.

{"profile": "retail", "zones": [{"name": "UE 1", "countries": ["AT", "DE", "ES"], "first_piece": "15.00",
 "each_additional_piece": "4.00", "failed_delivery_fee": "20.00", "duties_paid_by_recipient": false}],
 "extended_stock_transport": {"by_pieces": [{"pieces": 1, "amount": "9.00"}], "free_from_pieces": 7}}
POST /quote/
Stesso corpo di POST /orders/ (in ship_to basta country): restituisce merce, spedizione, trasporto dal magazzino esterno, IVA e totale senza ordinare. Usalo per mostrare la spedizione giusta nel tuo checkout.

{"subtotal": "219.70", "shipping": "24.00", "extended_stock_transport": "9.00", "shipping_total": "33.00",
 "vat": "0.00", "total": "252.70", "vat_note": "…margin scheme…", "lines": [
 {"sku": "1203A537-110", "size_eu": "42", "vat_regime": "margin", "quantity": 1, "unit_price": "130.00", "stock_type": "immediate"}]}
POST /orders/
{
  "reference": "SHOP-10045",
  "lines": [
    {"variant_id": 1234, "quantity": 2},
    {"sku": "DH2920-111", "size_eu": "38.5", "vat_regime": "margin", "quantity": 1},
    {"item_id": "1234-M", "quantity": 1}
  ],
  "ship_to": {
    "full_name": "Mario Rossi", "company": "", "street": "Via Roma 1", "city": "Milano",
    "zip_code": "20100", "province": "MI", "country": "IT", "phone": "+39…", "email": "…"
  },
  "payment_method": "balance",
  "notes": ""
}
Ogni riga indica la taglia in uno di questi modi: variant_id, sku + size_eu, oppure item_id dei file (es. 1234-M). vat_regime è facoltativo: se manca usiamo quello delle paia disponibili (prima il margine).
reference è obbligatorio (il tuo numero d'ordine): inviarlo due volte non crea doppioni (risposta 200 con l'ordine già creato).
Senza ship_to spediamo al tuo indirizzo; con ship_to (dropship) la fattura è alla tua azienda e il pacco va al tuo cliente, senza prezzi.
payment_method: balance (credito prepagato, pagato subito) o bank_transfer (bonifico anticipato su proforma; in payment_instructions trovi IBAN, causale e importo). Carta non disponibile via API.
Risposte: 201 ordine creato · 200 stesso reference già presente · 400 dati non validi, ordine minimo (Wholesaler) o paia non disponibili (stock elenca cosa manca).
GET /orders/ · GET /orders/<id>/
I tuoi ordini (parametri reference, page). Stato: received → confirmed → paid → shipped (con tracking) oppure cancelled.

{"id": 812, "number": "WTB/2026/11/0042", "reference": "SHOP-10045", "status": "shipped", "dropship": true,
 "payment_method": "balance", "is_paid": true, "currency": "EUR", "subtotal": "193.23", "shipping": "0.00",
 "vat": "0.00", "total": "193.23", "lines": [{"sku": "DH2920-111", "size_eu": "38", "vat_regime": "margin",
 "quantity": 2, "unit_price": "63.84"}], "tracking": [{"carrier": "BRT", "number": "…", "url": "https://…"}]}
Chiave di prova
Funziona su tutti gli indirizzi con catalogo, prezzi e spedizioni veri, ma POST /orders/ simula soltanto: stessi controlli e totali, risposta con "test": true e "number": "TEST"; niente viene salvato, nessuna paia è riservata, nessuna email o notifica parte.

Schede nel tuo stile (Retailer)
Quando le schede sono approvate e attive, ogni prodotto di GET /products/ contiene anche:

"content": {"title": "…", "description_html": "<p>…</p>", "short_description": "…", "seo_title": "…",
            "meta_description": "…", "handle": "nike-air-force-1-white", "tags": […],
            "attributes": [{"name": "Colore", "value": "Bianco"}], "image_alt": "…"},
"images_formatted": ["https://…/api/v1/img/…jpg"]
Webhook
Per ogni cambio di stato inviamo un POST JSON all'indirizzo impostato nel portale.

{"id": 381, "event": "order.shipped", "created_at": "2026-11-03T15:20:11+01:00", "data": { …order, as in GET /orders/<id>/… }}
Eventi: order.created, order.confirmed, order.paid, order.shipped, order.tracking_updated, order.updated, order.cancelled, ping. Intestazioni: X-WTB-Event, X-WTB-Delivery (id unico: ignora i doppioni) e X-WTB-Signature: t=<unix>,v1=<hex>. Verifica la firma: HMAC-SHA256 di <t>.<corpo grezzo> con il tuo segreto (whsec_…, mostrato una volta nel portale), confronta con v1 e rifiuta richieste più vecchie di 5 minuti. Rispondi con un 2xx entro 10 secondi, altrimenti riproviamo fino a 8 volte in circa un giorno.

import hmac, hashlib, time
def valid(secret, header, body: bytes):
    p = dict(x.split("=", 1) for x in header.split(","))
    mac = hmac.new(secret.encode(), (p["t"] + ".").encode() + body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(mac, p["v1"]) and abs(time.time() - int(p["t"])) < 300
Prova veloce
curl https://b2b.wtbsnkrs.it/api/v1/products/?page_size=5 -H "X-API-Key: wtb_test_…"

curl -X POST https://b2b.wtbsnkrs.it/api/v1/quote/ -H "X-API-Key: wtb_test_…" -H "Content-Type: application/json" \
     -d '{"lines":[{"item_id":"1234-M","quantity":1}],"ship_to":{"country":"FR"}}'
Regole

_(sezione «Regole» da incollare)_
