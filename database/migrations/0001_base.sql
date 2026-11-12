-- 0001 · Base: impostazioni, zone e paesi, clienti e accesso, servizio.
-- Convenzioni (docs/08): importi in centesimi INTEGER, date ISO 8601 UTC in TEXT, JSON in TEXT, booleani 0/1.

CREATE TABLE settings (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    updated_by  TEXT NOT NULL DEFAULT ''
);

CREATE TABLE settings_audit (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    key         TEXT NOT NULL,
    old_value   TEXT,               -- NULL = prima valeva il default
    new_value   TEXT,               -- NULL = ripristinato il default
    changed_at  TEXT NOT NULL,
    changed_by  TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_settings_audit_key ON settings_audit (key, changed_at);

CREATE TABLE shipping_zones (
    zone              TEXT PRIMARY KEY,         -- IT | EU | EXTRA (o altre create dall'admin)
    name_it           TEXT NOT NULL,
    name_en           TEXT NOT NULL,
    fee_cents         INTEGER NOT NULL CHECK (fee_cents >= 0),
    free_from_items   INTEGER CHECK (free_from_items IS NULL OR free_from_items > 0),  -- NULL = mai gratis
    transit_days_min  INTEGER NOT NULL CHECK (transit_days_min >= 0),
    transit_days_max  INTEGER NOT NULL CHECK (transit_days_max >= transit_days_min),
    sort_order        INTEGER NOT NULL DEFAULT 100,
    updated_at        TEXT NOT NULL
);

CREATE TABLE countries (
    code        TEXT PRIMARY KEY,               -- ISO 3166-1 alpha-2
    is_eu       INTEGER NOT NULL DEFAULT 0,
    zone        TEXT NOT NULL REFERENCES shipping_zones (zone),
    served      INTEGER NOT NULL DEFAULT 1,
    sort_order  INTEGER NOT NULL DEFAULT 100
);

CREATE TABLE users (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    email             TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash     TEXT,                     -- NULL = invito non completato
    name              TEXT NOT NULL,
    company           TEXT NOT NULL,
    vat_number        TEXT NOT NULL,
    phone             TEXT NOT NULL DEFAULT '',
    address_street    TEXT NOT NULL DEFAULT '',
    address_city      TEXT NOT NULL DEFAULT '',
    address_zip       TEXT NOT NULL DEFAULT '',
    address_province  TEXT NOT NULL DEFAULT '',
    country_code      TEXT NOT NULL REFERENCES countries (code),
    locale            TEXT NOT NULL DEFAULT 'it' CHECK (locale IN ('it', 'en')),
    is_active         INTEGER NOT NULL DEFAULT 1,
    admin_notes       TEXT NOT NULL DEFAULT '',
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL,
    last_login_at     TEXT
);

CREATE TABLE user_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash  TEXT NOT NULL UNIQUE,           -- sha256 del token, il chiaro sta solo nel link
    purpose     TEXT NOT NULL CHECK (purpose IN ('invite', 'reset')),
    expires_at  TEXT NOT NULL,
    used_at     TEXT,
    created_at  TEXT NOT NULL
);
CREATE INDEX idx_user_tokens_user ON user_tokens (user_id, created_at);

CREATE TABLE account_requests (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    status            TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    reviewed_at       TEXT,
    reviewed_by       TEXT NOT NULL DEFAULT '',
    user_id           INTEGER REFERENCES users (id) ON DELETE SET NULL,
    email             TEXT NOT NULL COLLATE NOCASE,
    name              TEXT NOT NULL,
    company           TEXT NOT NULL,
    vat_number        TEXT NOT NULL,
    phone             TEXT NOT NULL DEFAULT '',
    address_street    TEXT NOT NULL DEFAULT '',
    address_city      TEXT NOT NULL DEFAULT '',
    address_zip       TEXT NOT NULL DEFAULT '',
    address_province  TEXT NOT NULL DEFAULT '',
    country_code      TEXT NOT NULL,
    locale            TEXT NOT NULL DEFAULT 'it',
    notes             TEXT NOT NULL DEFAULT '',
    ip_address        TEXT NOT NULL DEFAULT '',
    user_agent        TEXT NOT NULL DEFAULT '',
    created_at        TEXT NOT NULL
);
CREATE INDEX idx_account_requests_status ON account_requests (status, created_at);

CREATE TABLE login_attempts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address    TEXT NOT NULL,
    scope         TEXT NOT NULL CHECK (scope IN ('user', 'admin')),
    attempted_at  TEXT NOT NULL,
    success       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_login_attempts ON login_attempts (ip_address, scope, attempted_at);

CREATE TABLE rate_limit_hits (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket   TEXT NOT NULL,                     -- es. account_request, checkout, reset
    subject  TEXT NOT NULL,                     -- IP o id utente
    hit_at   TEXT NOT NULL
);
CREATE INDEX idx_rate_limit_hits ON rate_limit_hits (bucket, subject, hit_at);

CREATE TABLE job_runs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    job          TEXT NOT NULL,
    started_at   TEXT NOT NULL,
    finished_at  TEXT,
    status       TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'error', 'skipped')),
    counts       TEXT NOT NULL DEFAULT '{}',
    message      TEXT NOT NULL DEFAULT '',
    trigger      TEXT NOT NULL DEFAULT 'scheduler'   -- scheduler | admin | cli
);
CREATE INDEX idx_job_runs ON job_runs (job, started_at);

-- Sessioni server-side (carrello compreso): a DB solo l'hash dell'id del cookie.
CREATE TABLE sessions (
    id_hash     TEXT PRIMARY KEY,
    scope       TEXT NOT NULL CHECK (scope IN ('site', 'admin')),
    data        TEXT NOT NULL DEFAULT '{}',
    updated_at  TEXT NOT NULL,
    expires_at  TEXT NOT NULL
);
CREATE INDEX idx_sessions_expires ON sessions (expires_at);

-- Stato di servizio e avvisi persistenti (es. chiave del fornitore rifiutata, sync bloccato).
CREATE TABLE system_flags (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

-- Zone di spedizione al cliente (D-006): tariffe SEGNAPOSTO, le vere si impostano da /admin/spedizione.
INSERT INTO shipping_zones (zone, name_it, name_en, fee_cents, free_from_items, transit_days_min, transit_days_max, sort_order, updated_at) VALUES
    ('IT', 'Italia', 'Italy', 1500, NULL, 1, 2, 10, '2026-11-12T00:00:00Z'),
    ('EU', 'Unione Europea', 'European Union', 3000, NULL, 2, 4, 20, '2026-11-12T00:00:00Z'),
    ('EXTRA', 'Extra UE', 'Outside the EU', 4500, NULL, 3, 6, 30, '2026-11-12T00:00:00Z');

-- Paesi serviti (D-014): UE-27 + Regno Unito e Svizzera, Italia in testa.
INSERT INTO countries (code, is_eu, zone, served, sort_order) VALUES
    ('IT', 1, 'IT', 1, 0),
    ('AT', 1, 'EU', 1, 100), ('BE', 1, 'EU', 1, 100), ('BG', 1, 'EU', 1, 100), ('CY', 1, 'EU', 1, 100),
    ('CZ', 1, 'EU', 1, 100), ('DE', 1, 'EU', 1, 100), ('DK', 1, 'EU', 1, 100), ('EE', 1, 'EU', 1, 100),
    ('ES', 1, 'EU', 1, 100), ('FI', 1, 'EU', 1, 100), ('FR', 1, 'EU', 1, 100), ('GR', 1, 'EU', 1, 100),
    ('HR', 1, 'EU', 1, 100), ('HU', 1, 'EU', 1, 100), ('IE', 1, 'EU', 1, 100), ('LT', 1, 'EU', 1, 100),
    ('LU', 1, 'EU', 1, 100), ('LV', 1, 'EU', 1, 100), ('MT', 1, 'EU', 1, 100), ('NL', 1, 'EU', 1, 100),
    ('PL', 1, 'EU', 1, 100), ('PT', 1, 'EU', 1, 100), ('RO', 1, 'EU', 1, 100), ('SE', 1, 'EU', 1, 100),
    ('SI', 1, 'EU', 1, 100), ('SK', 1, 'EU', 1, 100),
    ('GB', 0, 'EXTRA', 1, 100), ('CH', 0, 'EXTRA', 1, 100);
