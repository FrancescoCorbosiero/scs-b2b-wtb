# 08 — Modello dati (SQLite)

Schema indicativo: rifinirlo in M1 mantenendo nomi e significati, e tenere
questo file allineato alle migrazioni. Convenzioni: importi in **centesimi
INTEGER** (`*_cents`), percentuali in punti base (`*_bp`, 500 = 5%), date
ISO 8601 UTC in TEXT, JSON in TEXT, booleani 0/1. I campi **riservati**
(costi, margini, preventivi WTB) non escono mai dall'area admin.

## Catalogo

**`products`** — `id`, `sku` UNIQUE, `name`, `brand`, `category`, `line`,
`gender`, `category_path` (JSON), `description`, `size_category`
(`adult`|`gs`|`ps`), `is_active`, `is_featured`, `total_available`
(denormalizzato), `min_price_cents` + `min_price_regime` (per card e filtri),
`has_immediate`, `first_seen_at`, `last_seen_at`, `updated_at`. Indici su
`brand`, `line`, `gender`, `is_active`, `min_price_cents`.

**`product_images`** — `id`, `product_id` FK, `position`, `source_url`,
`cached_at` NULL, `status` (`pending`|`ok`|`error`).

**`variants`** — `id`, `product_id` FK, `wtb_variant_id` UNIQUE, `size_eu`,
`size_us`, `size_uk` (stringhe, mai numeri), `ean`, `is_active`. UNIQUE
(`product_id`, `size_eu`).

**`offers`** — `id`, `variant_id` FK, `vat_regime` (`margin`|`ordinary`),
`stock_type` (`immediate`|`extended`), `available`, `cost_cents`
(**riservato**), `sale_price_cents` (precalcolato), `ships_in_days`,
`updated_at`. UNIQUE (`variant_id`, `vat_regime`, `stock_type`).

**`margin_rules`** — `id`, `priority`, `match_type`
(`sku`|`brand`|`name`|`line`|`gender`|`category`|`size_category`),
`match_value`, `regime` NULL (= tutti), `stock_type` NULL (= tutti),
`margin_type` (`percent`|`fixed`|`fixed_price`), `margin_value` (bp o
centesimi), `is_active`, `created_at`, `updated_at`.

**`wtb_snapshots`** — `id`, `kind` (`shipping`), `payload` (JSON), `fetched_at`:
profilo, zone, tariffe e ordine minimo WTB dall'ultimo `GET /shipping/`.

## Impostazioni e riferimento

**`settings`** — `key` PK, `value`, `updated_at`, `updated_by`.
**`settings_audit`** — `id`, `key`, `old_value`, `new_value`, `changed_at`,
`changed_by`.

**`countries`** — `code` PK (ISO 3166-1 alpha-2), `is_eu`, `zone`
(`IT`|`EU`|`EXTRA`), `served`, `sort_order`. Seed: UE-27 + GB + CH, Italia in
testa. I nomi si mostrano localizzati (intl).

**`shipping_zones`** — `zone` PK, `fee_cents`, `free_from_items` NULL,
`transit_days_min`, `transit_days_max`, `updated_at`.

## Clienti e accesso

**`users`** — `id`, `email` UNIQUE, `password_hash` NULL (NULL = invito non
completato), `name`, `company`, `vat_number`, `phone`, `address_street`,
`address_city`, `address_zip`, `address_province`, `country_code`, `locale`,
`is_active`, `created_at`, `updated_at`, `last_login_at`.

**`user_tokens`** — `id`, `user_id` FK, `token_hash` UNIQUE (sha256),
`purpose` (`invite`|`reset`), `expires_at`, `used_at`, `created_at`.

**`account_requests`** — `id`, `status` (`pending`|`approved`|`rejected`),
`reviewed_at`, `user_id` NULL, dati aziendali e referente come in `users`,
`notes`, `ip_address`, `user_agent`, `created_at`.

**`login_attempts`** — `id`, `ip_address`, `scope` (`user`|`admin`),
`attempted_at`, `success`.

## Ordini

**`orders`** — `id` (anche numero d'ordine e causale del bonifico), `user_id`
FK, `status` (`pending`|`confirmed`|`shipped`|`cancelled`), `locale`;
anagrafica congelata (azienda, P.IVA, nome, email, telefono, indirizzo di
fatturazione con provincia e paese); spedizione (`ship_to_mode`
`billing`|`other`, campi del destinatario); `shipping_zone`, `vat_scheme`
(`domestic`|`intra_eu`|`export`), `vat_rate_bp`; totali
`subtotal_margin_cents`, `subtotal_ordinary_cents`, `shipping_cents`,
`vat_cents`, `total_cents`; **riservati**: `expected_cost_cents`,
`wtb_shipping_cost_cents`, `quote_snapshot` (JSON); `quote_unverified`;
`receipt_number` NULL; `vies_checked_at` NULL; `email_customer_sent`,
`email_admin_sent`; `notes`, `ip_address`, `user_agent`; `created_at`,
`confirmed_at`, `shipped_at`, `cancelled_at`.

**`order_lines`** — `id`, `order_id` FK, `product_id`, `variant_id`, `sku`,
`name`, `brand`, `size_eu`, `size_us`, `size_uk`, `ean`, `vat_regime`,
`qty_immediate`, `unit_price_immediate_cents`, `qty_extended`,
`unit_price_extended_cents`, `line_total_cents`, `ships_in_days`;
**riservati**: `unit_cost_immediate_cents`, `unit_cost_extended_cents`.
Le righe sono la fotografia al momento dell'ordine: il riallineamento le
modifica, il sync mai.

**`supplier_orders`** — `id`, `order_id` FK, `mode`
(`fixture`|`test`|`live`), `reference` UNIQUE, `attempt`, `wtb_order_id`
NULL, `wtb_number` NULL, `status`, `is_paid`, `payment_method`,
`payment_instructions` (JSON), importi WTB (`subtotal_cents`,
`shipping_cents`, `vat_cents`, `total_cents`: sono costi, **riservati**),
`tracking` (JSON), `request_payload`, `response_payload`, `last_error`,
`last_event_at`, `created_at`, `updated_at`.

**`webhook_events`** — `id`, `delivery_id` UNIQUE, `event`,
`signature_valid`, `supplier_order_id` NULL, `payload`, `received_at`,
`processed_at`, `error`.

**`receipt_counters`** — `year` PK, `last_number`: numerazione
`PFW-<anno>-<NNNN>` (D-015), assegnata alla conferma.

## Servizio

**`job_runs`** — `id`, `job`, `started_at`, `finished_at`, `status`
(`ok`|`error`|`skipped`), `counts` (JSON), `message`.

**`migrations`** — `version` PK, `applied_at`.

Il carrello non sta nel database: vive nella sessione server-side.
