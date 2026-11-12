-- 0004 · Ordini dei clienti (docs/06, docs/08). Le righe sono la fotografia al momento dell'ordine:
-- il riallineamento le modifica, il sync mai. I campi RISERVATI (costi, preventivo) restano in admin.

CREATE TABLE orders (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,   -- anche numero d'ordine e causale del bonifico
    user_id                   INTEGER NOT NULL REFERENCES users (id),
    status                    TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'shipped', 'cancelled')),
    mode                      TEXT NOT NULL CHECK (mode IN ('fixture', 'test', 'live')),   -- modalità del fornitore alla nascita
    locale                    TEXT NOT NULL DEFAULT 'it',
    checkout_token            TEXT NOT NULL UNIQUE,                -- invio idempotente della richiesta

    -- Anagrafica congelata
    company                   TEXT NOT NULL,
    vat_number                TEXT NOT NULL,
    contact_name              TEXT NOT NULL,
    email                     TEXT NOT NULL,
    phone                     TEXT NOT NULL DEFAULT '',
    billing_street            TEXT NOT NULL DEFAULT '',
    billing_city              TEXT NOT NULL DEFAULT '',
    billing_zip               TEXT NOT NULL DEFAULT '',
    billing_province          TEXT NOT NULL DEFAULT '',
    billing_country           TEXT NOT NULL,

    -- Spedizione
    ship_to_mode              TEXT NOT NULL DEFAULT 'billing' CHECK (ship_to_mode IN ('billing', 'other')),
    ship_name                 TEXT NOT NULL DEFAULT '',
    ship_company              TEXT NOT NULL DEFAULT '',
    ship_street               TEXT NOT NULL DEFAULT '',
    ship_city                 TEXT NOT NULL DEFAULT '',
    ship_zip                  TEXT NOT NULL DEFAULT '',
    ship_province             TEXT NOT NULL DEFAULT '',
    ship_country              TEXT NOT NULL,
    ship_phone                TEXT NOT NULL DEFAULT '',
    shipping_zone             TEXT NOT NULL,
    shipping_fee_cents        INTEGER NOT NULL DEFAULT 0,          -- tariffa della zona al momento dell'ordine
    shipping_free_from        INTEGER,                             -- soglia di gratuità al momento dell'ordine

    -- IVA e totali (docs/04)
    vat_scheme                TEXT NOT NULL CHECK (vat_scheme IN ('domestic', 'intra_eu', 'export')),
    vat_rate_bp               INTEGER NOT NULL DEFAULT 0,
    shipping_regime           TEXT NOT NULL DEFAULT 'ordinary' CHECK (shipping_regime IN ('ordinary', 'final')),
    pairs                     INTEGER NOT NULL DEFAULT 0,
    subtotal_margin_cents     INTEGER NOT NULL DEFAULT 0,
    subtotal_ordinary_cents   INTEGER NOT NULL DEFAULT 0,
    shipping_cents            INTEGER NOT NULL DEFAULT 0,
    vat_cents                 INTEGER NOT NULL DEFAULT 0,
    total_cents               INTEGER NOT NULL DEFAULT 0,
    departure_days            TEXT NOT NULL DEFAULT '',
    delivery_days             TEXT NOT NULL DEFAULT '',

    -- RISERVATI (solo admin)
    expected_cost_cents       INTEGER NOT NULL DEFAULT 0,          -- merce al costo del fornitore
    wtb_shipping_cost_cents   INTEGER,                             -- spedizione + trasporto stock esteso del fornitore
    quote_snapshot            TEXT,                                -- preventivo del fornitore (JSON)
    quote_unverified          INTEGER NOT NULL DEFAULT 0,          -- fornitore irraggiungibile al checkout

    -- Documenti, email, note
    manual_tracking           TEXT,                                -- tracking inserito a mano (ripiego se il webhook non arriva)
    receipt_number            TEXT UNIQUE,                         -- PFW-<anno>-<NNNN> (D-015), alla conferma
    vies_checked_at           TEXT,
    email_customer_sent       INTEGER NOT NULL DEFAULT 0,
    email_admin_sent          INTEGER NOT NULL DEFAULT 0,
    notes                     TEXT NOT NULL DEFAULT '',            -- note del cliente (mai al fornitore)
    admin_notes               TEXT NOT NULL DEFAULT '',
    ip_address                TEXT NOT NULL DEFAULT '',
    user_agent                TEXT NOT NULL DEFAULT '',
    created_at                TEXT NOT NULL,
    updated_at                TEXT NOT NULL,
    confirmed_at              TEXT,
    shipped_at                TEXT,
    cancelled_at              TEXT
);
CREATE INDEX idx_orders_status ON orders (status, created_at);
CREATE INDEX idx_orders_user ON orders (user_id, created_at);

-- I numeri d'ordine partono da 1001.
INSERT INTO sqlite_sequence (name, seq) VALUES ('orders', 1000);

CREATE TABLE order_lines (
    id                           INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id                     INTEGER NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    product_id                   INTEGER,
    variant_id                   INTEGER,
    wtb_variant_id               INTEGER NOT NULL,
    sku                          TEXT NOT NULL,
    name                         TEXT NOT NULL,
    brand                        TEXT NOT NULL DEFAULT '',
    size_eu                      TEXT NOT NULL,
    size_us                      TEXT NOT NULL DEFAULT '',
    size_uk                      TEXT NOT NULL DEFAULT '',
    ean                          TEXT NOT NULL DEFAULT '',
    vat_regime                   TEXT NOT NULL CHECK (vat_regime IN ('margin', 'ordinary')),
    qty_immediate                INTEGER NOT NULL DEFAULT 0,
    unit_price_immediate_cents   INTEGER,
    qty_extended                 INTEGER NOT NULL DEFAULT 0,
    unit_price_extended_cents    INTEGER,
    line_total_cents             INTEGER NOT NULL,
    ships_in_days                TEXT NOT NULL DEFAULT '',
    unit_cost_immediate_cents    INTEGER,                          -- RISERVATO
    unit_cost_extended_cents     INTEGER                           -- RISERVATO
);
CREATE INDEX idx_order_lines_order ON order_lines (order_id);

-- Numerazione delle pro-forma per anno (D-015).
CREATE TABLE receipt_counters (
    year         INTEGER PRIMARY KEY,
    last_number  INTEGER NOT NULL DEFAULT 0
);
