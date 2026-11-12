-- 0005 · Ordini al fornitore e webhook in arrivo (docs/06). Importi del fornitore = costi: RISERVATI.

CREATE TABLE supplier_orders (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id              INTEGER NOT NULL REFERENCES orders (id),
    mode                  TEXT NOT NULL CHECK (mode IN ('fixture', 'test', 'live')),
    reference             TEXT NOT NULL UNIQUE,                -- <prefisso>-<id ordine>-<tentativo>: rende la creazione idempotente
    attempt               INTEGER NOT NULL DEFAULT 1,
    wtb_order_id          INTEGER,
    wtb_number            TEXT,
    status                TEXT NOT NULL CHECK (status IN ('pending', 'received', 'confirmed', 'paid', 'shipped', 'cancelled', 'simulated', 'error')),
    is_paid               INTEGER NOT NULL DEFAULT 0,
    payment_method        TEXT NOT NULL,
    payment_instructions  TEXT,                                -- JSON: IBAN, causale, importo (forma libera)
    subtotal_cents        INTEGER,
    shipping_cents        INTEGER,
    vat_cents             INTEGER,
    total_cents           INTEGER,
    expected_total_cents  INTEGER,                             -- totale del preventivo prima della creazione
    tracking              TEXT NOT NULL DEFAULT '[]',          -- JSON: [{carrier, number, url}]
    request_payload       TEXT,
    response_payload      TEXT,
    last_error            TEXT NOT NULL DEFAULT '',
    warnings              TEXT NOT NULL DEFAULT '[]',          -- JSON: avvisi per l'admin (totale diverso, righe cambiate)
    last_event_at         TEXT,
    created_by            TEXT NOT NULL DEFAULT '',
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL
);
CREATE INDEX idx_supplier_orders_order ON supplier_orders (order_id);
CREATE INDEX idx_supplier_orders_status ON supplier_orders (status, updated_at);
CREATE INDEX idx_supplier_orders_wtb ON supplier_orders (wtb_order_id);

-- Solo eventi con firma valida (una firma non valida non tocca il database, docs/09).
CREATE TABLE webhook_events (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id        TEXT NOT NULL UNIQUE,                   -- X-WTB-Delivery: i doppioni si scartano
    event              TEXT NOT NULL,
    signature_valid    INTEGER NOT NULL DEFAULT 1,
    supplier_order_id  INTEGER REFERENCES supplier_orders (id),
    payload            TEXT NOT NULL,
    simulated          INTEGER NOT NULL DEFAULT 0,             -- da "Simula evento" (fixture/test)
    received_at        TEXT NOT NULL,
    processed_at       TEXT,
    outcome            TEXT NOT NULL DEFAULT '',               -- applied | stale | unknown_order | ping | ignored | error
    error              TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_webhook_events_received ON webhook_events (received_at);
CREATE INDEX idx_webhook_events_outcome ON webhook_events (outcome, received_at);
