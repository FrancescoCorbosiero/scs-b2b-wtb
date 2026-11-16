-- 0006 · Ordini composti dall'admin (D-019): stessa tabella degli ordini dei clienti, segnati con la loro origine.
-- "checkout" = richiesta del cliente; "admin" = ordine di prova composto in /admin/ordini/nuovo (niente email al
-- cliente, niente pro-forma: serve a provare l'ordine al fornitore in modalità test e live).

ALTER TABLE orders ADD COLUMN source TEXT NOT NULL DEFAULT 'checkout' CHECK (source IN ('checkout', 'admin'));
