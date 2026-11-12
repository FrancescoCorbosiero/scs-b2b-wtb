-- 0003 · Regole margine (docs/04) e chiave dell'albero delle categorie per i filtri del catalogo.

CREATE TABLE margin_rules (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    priority      INTEGER NOT NULL DEFAULT 100,        -- più basso = valutata prima (le regole SKU vengono sempre per prime)
    match_type    TEXT NOT NULL CHECK (match_type IN ('sku', 'brand', 'name', 'line', 'gender', 'category', 'size_category')),
    match_value   TEXT NOT NULL,
    regime        TEXT CHECK (regime IS NULL OR regime IN ('margin', 'ordinary')),          -- NULL = tutti
    stock_type    TEXT CHECK (stock_type IS NULL OR stock_type IN ('immediate', 'extended')), -- NULL = tutti
    margin_type   TEXT NOT NULL CHECK (margin_type IN ('percent', 'fixed', 'fixed_price')),
    margin_value  INTEGER NOT NULL CHECK (margin_value >= 0),   -- punti base (percent) o centesimi (fixed, fixed_price)
    is_active     INTEGER NOT NULL DEFAULT 1,
    note          TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    updated_by    TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_margin_rules_active ON margin_rules (is_active, priority);

-- Albero delle categorie: category_path unito da "/" (es. "Sneakers/New Balance/9060/Unisex/"), per i filtri a prefisso.
ALTER TABLE products ADD COLUMN category_key TEXT NOT NULL DEFAULT '';
CREATE INDEX idx_products_category_key ON products (category_key);
