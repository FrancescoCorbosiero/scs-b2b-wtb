# 04 — Prezzi e IVA

Tutte le regole di questo documento vivono in tre servizi puri: `Pricing`
(prezzi di vendita e fasce), `Tax` (`TaxPolicy`) e `Shipping` (docs/05).
Importi sempre in centesimi interi.

## Le offerte WTB

Per ogni variante (taglia) WTB espone fino a **quattro offerte**: regime IVA
(`margin` | `ordinary`) × tipo di stock (`immediate` | `extended`). Ognuna ha
disponibilità, costo (`price`, riservato) e tempi di partenza.

- `margin`: il costo è un prezzo finale, IVA compresa e non esposta, non
  detraibile.
- `ordinary`: il costo è netto; WTB aggiunge l'IVA in fattura al titolare.
- Se si ordinano più paia di quelle `immediate`, WTB fattura il resto al
  prezzo `extended` dello stesso regime.

## Unità vendibile e fasce

Il cliente compra **taglia + regime** (es. "42 · margine"). Ogni unità ha
fino a due **fasce**: pronta consegna e stock esteso. Per una quantità `q`:

1. le prime `min(q, disponibili_immediate)` paia al prezzo di vendita della
   fascia pronta consegna;
2. le restanti, fino a `disponibili_extended`, al prezzo della fascia estesa.

Massimo ordinabile = pronta consegna + esteso (solo pronta consegna con
`catalog.sell_extended` spento). Catalogo, scheda rapida e carrello mostrano
il dettaglio, es. "3 × 120 € · partenza 1-2 gg + 2 × 125 € · partenza 3-5 gg".

Il carrello conserva solo (variante, regime, quantità): prezzi e fasce si
ricalcolano sempre dalle offerte correnti e si **congelano** nelle righe
dell'ordine all'invio (con la ripartizione confermata dal preventivo WTB,
docs/06).

## Prezzo di vendita = costo + margine

Per ogni offerta: `prezzo_vendita = arrotonda(costo + margine)`, precalcolato
a ogni sync e a ogni modifica delle regole (reprice).

**Regole margine** (`/admin/margini`), valutate in quest'ordine:

1. regole **SKU** (uno o più codici separati da virgola): sempre per prime;
2. le altre regole attive, in ordine di priorità: **brand**, **nome
   contiene**, **linea** (`line`, es. 9060), **genere**, **categoria**
   (`category_path` contiene), **categoria di taglia** (adulto/GS/PS);
3. nessuna regola ⇒ **margine di default** (impostazione, 5% di partenza).

Implementazione (`src/Pricing/`): ogni regola ha un numero di priorità (più
basso = valutata prima); le regole SKU vengono comunque prima di tutte; a
parità di priorità conta il tipo nell'ordine dell'elenco qui sopra, poi la
regola più vecchia. Vale la **prima** che combacia. Brand e linea combaciano
per intero (senza distinguere maiuscole), "nome" e "categoria" per
contenuto. Ogni offerta ricorda la regola applicata (`offers.margin_rule_id`):
da lì l'admin vede quanti prodotti tocca ogni regola, un esempio di prezzo e
quante offerte un prezzo fisso porta sotto costo.

Ogni regola può valere per un solo regime e/o un solo tipo di stock (default:
tutti). Tipi:

- `percent`: costo × (1 + m/100)
- `fixed`: costo + m €
- `fixed_price`: prezzo di vendita esatto, ignora costo e arrotondamento;
  l'admin vede un avviso se è sotto costo.

**Arrotondamento** (`prices.rounding`): `whole` (default, all'euro), `half`
(a 0,50) o `none`; sempre **per eccesso**, così il margine non scende mai sotto
la regola.

Per ogni regola l'admin vede quanti prodotti tocca e un esempio di prezzo.

> **Nota sul regime del margine**: nel prezzo di vendita di un'offerta a
> margine è compresa l'IVA che il titolare deve sul suo margine (in Italia
> 22/122 della differenza tra vendita e acquisto). A parità di ricarico il
> guadagno netto è quindi più basso che su un'offerta ordinaria: l'admin
> mostra accanto al margine lordo una **stima** del margine al netto di
> quell'IVA. È una stima informativa, da validare col commercialista.

## IVA — proposta tecnica di default (D-007)

> ⚠ **Questa sezione è una proposta tecnica, non un parere fiscale.** Prima
> di `WTB_MODE=live` il titolare la fa validare dal suo commercialista (vedi
> docs/10). Le regole vivono solo in `TaxPolicy`, le aliquote nelle
> impostazioni `tax.*` e le diciture in `lang/`: cambiarle non tocca il resto
> del codice.

**Clienti**: solo aziende con partita IVA (obbligatoria in registrazione).
Il trattamento dipende dal **paese di fatturazione** del cliente:

| Paese del cliente | Righe a margine | Righe ordinarie |
|---|---|---|
| Italia | prezzo finale, IVA non esposta | netto + IVA `tax.vat_rate_it` (22%) |
| Altro Stato UE (P.IVA) | prezzo finale, IVA non esposta | netto, IVA 0% (cessione intracomunitaria non imponibile) |
| Regno Unito, Svizzera | prezzo finale, IVA non esposta | netto, IVA 0% (esportazione non imponibile) |

- **Spedizione**: una riga a sé con il trattamento di `tax.shipping_regime`
  (default `ordinary`: netta + IVA secondo la tabella; alternativa `final`:
  importo finale senza IVA esposta).
- **Totali di un ordine**: righe a margine + righe ordinarie nette +
  spedizione + IVA (solo su righe ordinarie e, se ordinaria, spedizione).
  `totale da bonificare = margine + ordinario + spedizione + IVA`.
- **Pro-forma ed email**: le righe sono divise in due blocchi (margine /
  ordinario), ciascuno con la sua dicitura. Diciture di partenza, da
  validare: margine "Regime del margine – beni di occasione (art. 36 D.L.
  41/1995)"; UE "Operazione non imponibile (art. 41 D.L. 331/1993)"; export
  "Operazione non imponibile (art. 8 D.P.R. 633/1972)".
- **Partita IVA UE**: la piattaforma ne controlla solo il formato. Per i
  clienti UE con righe ordinarie, la conferma dell'ordine in admin chiede di
  spuntare "P.IVA verificata su VIES" (la verifica automatica è rinviata,
  D-013).
- **In catalogo** ogni prezzo porta l'etichetta del suo regime ("Margine ·
  prezzo finale" / "Ordinario · + IVA") e c'è un filtro per regime; il
  carrello mostra i subtotali per regime e l'IVA stimata per il paese del
  cliente.
- **Regimi in vendita** (`catalog.regimes`): entrambi (default), solo margine
  o solo ordinario. Le offerte del regime spento spariscono da catalogo,
  carrello ed export.

## Cosa vede chi

| Dato | Cliente | Admin |
|---|---|---|
| Prezzo di vendita per fascia e regime | ✔ | ✔ |
| Disponibilità per fascia, tempi di partenza | ✔ | ✔ |
| Costo WTB, margine, stima margine netto | ✘ (regola d'oro 1) | ✔ |
| Costo reale di spedizione WTB | ✘ | ✔ |
