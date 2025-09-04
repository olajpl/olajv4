### [DATABASE] Refactor: ai_reports

- Added column `report_type` ENUM('sales','stock','client','custom') DEFAULT 'custom'
- Added column `status` ENUM('pending','generating','ready','error') DEFAULT 'pending'
- Added column `generated_at` DATETIME NULL
### [DATABASE] Refactor: api_credentials

- Changed `provider` to ENUM('inpost','facebook','chatgpt','allegro','stripe','custom')
- Added `active` ENUM('yes','no') DEFAULT 'yes'
- Added `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP
### [DATABASE] Refactor: beta_features

- Changed `enabled` to ENUM('yes','no') DEFAULT 'no'
- Added UNIQUE KEY on (`owner_id`, `feature_code`)

### [DATABASE] Refactor: campaign_products

- Set `campaign_id` and `product_id` as NOT NULL
- Added UNIQUE KEY on (`campaign_id`, `product_id`)
- Added `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
### [DATABASE] Refactor: campaigns

- Changed `is_active` to ENUM('yes','no') DEFAULT 'yes'
- Added `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
- Added `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP
### [DATABASE] Feature: cart_item_meta

- Added `cart_item_meta` table for flexible cart item options
### [DATABASE] Refactor: categories

- Added `slug` VARCHAR(255) for friendly URL usage
- Added `sort_order` INT for display ordering
- Added `created_at` DATETIME for tracking
- Added `parent_id` INT for category nesting (FK to self)
### [DATABASE] Refactor: checkout_access_log

- Changed `group_status` to ENUM('draft','checkout_completed','paid','cancelled')
- Changed `payment_status` to ENUM('pending','paid','failed','cancelled')
- Added `client_token` VARCHAR(64) to track access per user

### [DATABASE] Refactor: client_addresses

- Changed `is_default` to ENUM('yes','no') DEFAULT 'no'
- Added `type` ENUM('delivery','billing','locker','pickup') DEFAULT 'delivery'
- Added `country_code` CHAR(2) DEFAULT 'PL'
### [DATABASE] Refactor: client_info (full)

- Changed marketing_optin_* to ENUM('yes','no') DEFAULT 'no'
- Added `loyalty_points` INT DEFAULT 0
- Added `preferred_language` VARCHAR(8) DEFAULT 'pl'
- Added `blocked` ENUM + `blocked_reason`
- Added `birthday` DATE
- Added `external_id` VARCHAR(64)

### [DATABASE] Refactor: client_info (full)

- Changed marketing_optin_* to ENUM('yes','no') DEFAULT 'no'
- Added `loyalty_points` INT DEFAULT 0
- Added `preferred_language` VARCHAR(8) DEFAULT 'pl'
- Added `blocked` ENUM('yes','no') DEFAULT 'no' + `blocked_reason` TEXT
- Added `birthday` DATE
- Added `external_id` VARCHAR(64)

### [DATABASE] Refactor: client_platform_ids

- Added `created_at` and `updated_at` for tracking lifecycle
- Added `active` ENUM('yes','no') DEFAULT 'yes'
- Added `platform_token` TEXT for push IDs, tokens, webhook keys

### [DATABASE] Refactor: client_tag_links

- Added `created_at` timestamp
- Added `added_by_user_id` (nullable)
- Added `source` ENUM('manual','automation','live','order','system') DEFAULT 'manual'
- Added `note` TEXT
### [DATABASE] Refactor: client_tag_links

- Added `created_at` timestamp
- Added `added_by_user_id` (nullable)
- Added `source` ENUM('manual','automation','live','order','system') DEFAULT 'manual'
- Added `note` TEXT

### [DATABASE] Refactor: client_tags

- Renamed column `name` → `label`
- Added `slug`, `description`, `is_system`
- Added `created_at` and `updated_at`
- Added UNIQUE constraint on (`owner_id`, `label`)
### [DATABASE] Refactor: clients

- Renamed `name` → `display_name`
- Added `status` ENUM('active','blocked','test','archived') DEFAULT 'active'
- Added `source` ENUM('shop','live','parser','import','admin','api') DEFAULT 'shop'
- Added `newsletter_optin` + `newsletter_optin_at`
- Added `created_at` timestamp
### [DATABASE] Refactor: discount_codes

- Changed `active` to ENUM('yes','no') DEFAULT 'yes'
- Added `applies_to` ENUM('cart','product','shipping','custom')
- Added `min_cart_total` (minimum order value)
- Added `auto_generated` flag
- Added `label` and `description`
[DATABASE] Refactor: owners.id + owner_id (30+ tables)

Changed owners.id to int unsigned NOT NULL AUTO_INCREMENT

Updated all related owner_id columns to int unsigned NOT NULL

Dropped and re-added 30+ foreign keys to reference owners.id

All FK now include ON DELETE CASCADE

Ensured full type alignment across all modules

[DATABASE] Refactor: draw_claims

Changed id, result_id, draw_id, prize_id to int unsigned

Dropped and re-added foreign keys:

fk_dcl_draw → draws(id) ON DELETE CASCADE

fk_dcl_prize → draw_prizes(id) ON DELETE SET NULL

fk_dcl_result → draw_results(id) ON DELETE CASCADE

All referenced tables updated to use int unsigned primary keys if needed

### [DATABASE] Refactor: draws.id (unsigned)

- Changed `draws.id` to `int unsigned`
- Changed `draw_id` to `int unsigned` in all referencing tables
- Foreign keys temporarily removed for consistency; will be restored in final FK pass
### [DATABASE] Refactor: draw_presets

- Changed `id` to `int unsigned`
- Added `label` and `description` fields for better UI/UX context
- Added `updated_at` and `deleted_at` columns for tracking and soft deletion
### [DATABASE] Refactor: draw_prizes

- Changed `quantity` to `int unsigned` with default 1
- Added `description` and `image_url` columns for prize details
- Added `created_at`, `updated_at`, and `deleted_at` timestamps
- Added missing foreign key: `draw_id` → `draws(id)` (ON DELETE CASCADE)

### [DATABASE] Cleanup: Global FK wipe for full schema refactor

- Dropped all foreign key constraints across all tables in `eew3ha_adminola`
- Enabled unsigned ID migration, column normalization, and refactoring
- Foreign keys will be rebuilt cleanly in final step of refactor
### [DATABASE] Unsigned: owners.id

- Dropped FK `orders_ibfk_3`
- Changed `owners.id` to `int unsigned`
- Updated `orders.owner_id` to match
- Will restore FK after refactor
### [DATABASE] Unsigned: draws.id

- Changed `draws.id` to `int unsigned`
- Updated `draw_results.draw_id` to match

### [DATABASE] Massive FK Restore: Olaj.pl V4 Audit Recovery

- Restored 60+ foreign key constraints across the database following FK audit and refactor
- Covered dependencies between key tables: `orders`, `clients`, `products`, `owners`, `payments`, `shipping`, `draws`, etc.
- FK constraints re-added in structured order per table:
  - `orders`, `order_groups`, `order_items`, `payments`, `products`, `draws`, `live_streams`, `clients`, `suppliers`, `shipping_methods`, and more
- Duplicates automatically skipped by MySQL if already present
- Based on audit data from 2025-08-15 foreign key dependency list
- FK consistency now maintained for ongoing schema cleanup
### [DATABASE] Refactor: Applied full ALTER patch from db2_refactor_alter.sql

- All existing tables updated to normalized schema
- Types normalized (`int` → `int unsigned`, etc.)
- Missing `FOREIGN KEY` constraints restored (if valid)
- `ENGINE=InnoDB`, `utf8mb4_unicode_ci` enforced globally
- `db3.sql` contains full post-refactor snapshot of current structure
### [DATABASE] Patch: Add missing columns after central engine ALTER

- Added `deleted_at` to `products`
- Added `vat_rate`, `updated_at` to `order_items`
2025-08-15 • LIVE — broadcast aktualnej prezentacji
- ALTER TABLE live_streams: dodano pola current_*:
  current_product_id INT NULL
  current_title VARCHAR(255) NULL
  current_price DECIMAL(10,2) NULL
  current_vat DECIMAL(5,2) NULL
  current_pitch TEXT NULL
  current_bullets_json JSON NULL
  current_code VARCHAR(64) NULL
  current_updated_at DATETIME NULL
- Cel: szybkie podanie prowadzącemu danych produktu i publikacja „co teraz prezentujemy” na frontend sklepu.
- Używane przez:
  • admin/live/ajax/ajax_presenter_prefill.php
  • admin/live/ajax/ajax_presenter_broadcast.php
  • shop/api/live/current.php
- Future: rozważ przeniesienie historii do tabeli live_presentations (archiwum slotów).
## 2025-08-15 — Refaktor relacji produktów (FK + cleanup)

- products: usunięto zdublowany UNIQUE INDEX `owner_id` (na kolumnach `owner_id, code`);
  pozostawiono `u_owner_code (owner_id, code)` jako docelowy indeks unikalny.
- products: dodano FK `fk_products_category` (`category_id` → `categories.id`)
  z regułą `ON DELETE SET NULL`, `ON UPDATE CASCADE`.
- product_images: zmieniono `product_images_ibfk_1` na `ON DELETE CASCADE, ON UPDATE CASCADE`
  (usunięcie produktu usuwa jego zdjęcia).
- twelve_nc_map: zmieniono `twelve_nc_map_ibfk_2` na `ON DELETE CASCADE, ON UPDATE CASCADE`
  (mapowania 12NC usuwane razem z produktem).

Opcjonalne (włączone, jeśli zastosowano):
- products: dodano `deleted_at` (soft delete) i `visibility` ENUM('public','hidden','archived').
- products: dodano `u_owner_sku (owner_id, sku)` — unikalność SKU per owner.
- product_images: dodano `sort_order` i `alt_text` dla ergonomii w panelu.
## 2025-08-16 LIVE finalize
- ajax_finalize_batch.php: przekazywanie dwóch argumentów do LiveEngine::finalizeBatch(int $liveId, int $operatorUserId).
- Walidacja owner_id/operator_id/live_id, kontrola przynależności LIVE→owner.
- __live_boot.php: wymagane auth.php (ustawia $_SESSION['user']).
- Poprawka deleteItem(): usunięcie odwołania do nieistniejącego $og.
IVE: stabilize accordions + add flow
- __live_boot.php: single APP_ROOT, safe session, json_* helpers, ctx()
- ajax_live_temp_list.php: HTML accordions by default, JSON opt-in
- live.js: single init, single fetchAndRender(?format=html), robust Add submit, force <details> toggle
- view.php: one form, one OLAJ_LIVE_CFG, #clientAccordion container, single live.js include