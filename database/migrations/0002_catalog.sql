-- 0002 · Catalogo dal fornitore: prodotti, immagini, varianti (taglie), offerte, fotografie di /shipping/.
-- La fonte di verità è il fornitore: nessun CRUD prodotti (regola d'oro 6).

CREATE TABLE products (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    sku               TEXT NOT NULL UNIQUE,
    name              TEXT NOT NULL,
    brand             TEXT NOT NULL DEFAULT '',
    category          TEXT NOT NULL DEFAULT '',
    line              TEXT NOT NULL DEFAULT '',
    gender            TEXT NOT NULL DEFAULT '',
    category_path     TEXT NOT NULL DEFAULT '[]',          -- JSON
    description       TEXT NOT NULL DEFAULT '',
    size_category     TEXT NOT NULL DEFAULT 'adult' CHECK (size_category IN ('adult', 'gs', 'ps')),
    is_active         INTEGER NOT NULL DEFAULT 1,          -- 0 = fuori catalogo (mai cancellato: gli ordini lo citano)
    in_feed           INTEGER NOT NULL DEFAULT 1,          -- 1 = presente nell'ultimo feed completo
    is_featured       INTEGER NOT NULL DEFAULT 0,
    total_available   INTEGER NOT NULL DEFAULT 0,          -- paia vendibili (regimi e tipi di stock in vendita)
    min_price_cents   INTEGER,                             -- prezzo di vendita minimo tra le offerte vendibili
    min_price_regime  TEXT,
    has_immediate     INTEGER NOT NULL DEFAULT 0,
    search_text       TEXT NOT NULL DEFAULT '',            -- sku, nome, brand, linea, EAN in minuscolo
    first_seen_at     TEXT NOT NULL,
    last_seen_at      TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);
CREATE INDEX idx_products_brand ON products (brand);
CREATE INDEX idx_products_line ON products (line);
CREATE INDEX idx_products_gender ON products (gender);
CREATE INDEX idx_products_active ON products (is_active, total_available);
CREATE INDEX idx_products_price ON products (min_price_cents);

CREATE TABLE product_images (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id  INTEGER NOT NULL REFERENCES products (id) ON DELETE CASCADE,
    position    INTEGER NOT NULL,
    source_url  TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'ok', 'error')),
    cached_at   TEXT,
    error       TEXT NOT NULL DEFAULT '',
    UNIQUE (product_id, position)
);

CREATE TABLE variants (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id      INTEGER NOT NULL REFERENCES products (id) ON DELETE CASCADE,
    wtb_variant_id  INTEGER NOT NULL UNIQUE,
    size_eu         TEXT NOT NULL,                      -- sempre stringhe ("38.5", "36 2/3")
    size_us         TEXT NOT NULL DEFAULT '',
    size_uk         TEXT NOT NULL DEFAULT '',
    size_sort       INTEGER NOT NULL DEFAULT 999000,    -- taglia EU in millesimi, per ordinare
    ean             TEXT NOT NULL DEFAULT '',
    is_active       INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX idx_variants_product ON variants (product_id, size_sort);

CREATE TABLE offers (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id        INTEGER NOT NULL REFERENCES variants (id) ON DELETE CASCADE,
    vat_regime        TEXT NOT NULL CHECK (vat_regime IN ('margin', 'ordinary')),
    stock_type        TEXT NOT NULL CHECK (stock_type IN ('immediate', 'extended')),
    available         INTEGER NOT NULL DEFAULT 0 CHECK (available >= 0),
    cost_cents        INTEGER NOT NULL,                 -- RISERVATO: prezzo d'acquisto, mai al cliente
    sale_price_cents  INTEGER,                          -- precalcolato (reprice)
    margin_rule_id    INTEGER,                          -- regola applicata (NULL = margine di default)
    ships_in_days     TEXT NOT NULL DEFAULT '',
    updated_at        TEXT NOT NULL,
    UNIQUE (variant_id, vat_regime, stock_type)
);

-- Fotografie delle risposte di configurazione del fornitore (profilo, zone, tariffe): solo admin.
CREATE TABLE wtb_snapshots (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    kind        TEXT NOT NULL,
    payload     TEXT NOT NULL,
    fetched_at  TEXT NOT NULL
);
CREATE INDEX idx_wtb_snapshots ON wtb_snapshots (kind, fetched_at);
