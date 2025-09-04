<?php
// engine/Enums/SettingKey.php
declare(strict_types=1);

namespace Engine\Enum;

/**
 * Centralne klucze owner_settings – „jedno źródło prawdy”.
 * Używamy TYLKO tych stałych w silnikach zamiast gołych stringów.
 *
 * Konwencja nazw: segmenty rozdzielone kropką, od ogółu do szczegółu.
 * Przykład przechowywania:
 *  - value:   dla prostych stringów/liczb (np. plan.tier = "basic")
 *  - value_json: dla map/array (np. feature.flags = {"tags.enabled":true,...})
 */
enum SettingKey: string
{
    /* =========================
     * SaaS / Plan / Feature-gate
     * ========================= */
    case PLAN_TIER      = 'plan.tier';        // value: "free"|"basic"|"pro"|"ultra"
    case FEATURE_FLAGS  = 'feature.flags';    // value_json: { feature_key: bool }
    case FEATURE_LIMITS = 'feature.limits';   // value_json: { limit_key: scalar }

        /* ==============
     * Parser / Komendy
     * ============== */
    case PARSER_PREFIXES = 'parser.prefixes'; // value_json: ["daj","biere","moje"]

        /* ===================
     * Checkout / Sklep URL
     * =================== */
    case CHECKOUT_BASE_URL = 'checkout.base_url'; // value: np. "https://olaj.pl/checkout/"
    case SHOP_BASE_URL     = 'shop.base_url';     // value: np. "https://olaj.pl/"

        /* ==========================
     * CW (Centralny Wysyłacz)
     * ========================== */
    case CW_AUTO_REMINDERS_ENABLED = 'cw.auto_reminders.enabled'; // value: "0"|"1" (lub bool w value_json)
    case CW_RETRY_POLICY           = 'cw.retry.policy';           // value_json: { max_attempts:int, backoff_sec:int }
    case CW_TEMPLATES_MAP          = 'cw.templates.map';          // value_json: { event_key:string => template_id:int }
    case CW_EVENTS_ENABLED         = 'cw.events.enabled';         // value_json: { event_key:string => bool }

        /* ==================
     * Facebook / Messenger
     * ================== */
    case FB_PAGE_ID         = 'facebook.page_id';          // value: string
    case FB_PAGE_TOKEN      = 'facebook.page_token';       // value: string (token dostępu)
    case FB_APP_ID          = 'facebook.app_id';           // value: string
    case FB_APP_SECRET      = 'facebook.app_secret';       // value: string
    case FB_DEFAULT_PAGE_ID = 'facebook.default_page_id';  // value: string (gdy multi-page)

        /* ===================
     * Płatności / Payments
     * =================== */
    case PAY_ONLINE_PROVIDER   = 'payments.online.provider';   // value: np. "tpay"|"przelewy24"|"stripe"
    case PAY_ONLINE_CONFIG     = 'payments.online.config';     // value_json: { ... provider secrets ... }
    case PAY_TRANSFER_DETAILS  = 'payments.transfer.details';  // value_json: { account_no, recipient, title_tpl }
    case PAY_COD_ENABLED       = 'payments.cod.enabled';       // value: "0"|"1"

        /* ==================
     * Wysyłka / Shipping
     * ================== */
    case SHIP_MAX_PACKAGE_WEIGHT = 'shipping.max_package_weight'; // value: np. "30.0"
    case SHIP_WEIGHT_RULES       = 'shipping.weight_rules';       // value_json: [{ from:0, to:10, price:... }, ...]
    case SHIP_OWNER_COSTS        = 'shipping.owner_costs';        // value_json: { carrier_code => cost }
    case SHIP_PICKUP_POINTS      = 'shipping.pickup_points';      // value_json: { carrier_code => bool }

        /* ======================
     * Magazyn / Products+Stock
     * ====================== */
    case STOCK_RESERVATION_POLICY = 'stock.reservation.policy'; // value: np. "live_immediate_commit"|"defer"
    case PRODUCT_CODE_PREFIX      = 'products.code.prefix';     // value: np. "OLAJ-"
    case PRODUCT_TAGS_ENABLED     = 'products.tags.enabled';    // value: "0"|"1"

        /* =========
     * UI/Brand
     * ========= */
    case BRAND_NAME     = 'brand.name';      // value: "Olaj.pl"
    case BRAND_LOGO_URL = 'brand.logo_url';  // value: URL
    case THEME_COLOR    = 'ui.theme.color';  // value: np. "#6C5CE7"
    case PWA_ENABLED    = 'pwa.enabled';     // value: "0"|"1"

        /* ==================
     * Marketing / Kampanie
     * ================== */
    case MKT_COUPONS_ENABLED  = 'marketing.coupons.enabled';   // value: "0"|"1"
    case MKT_LOYALTY_ENABLED  = 'marketing.loyalty.enabled';   // value: "0"|"1"
    case MKT_RECO_AI_ENABLED  = 'marketing.reco_ai.enabled';   // value: "0"|"1"
    case MKT_DEFAULT_CHANNELS = 'marketing.default_channels';  // value_json: ["messenger","email"]

        /* ==================
     * Marketplace / Allegro
     * ================== */
    case MARKETPLACE_ALLEGRO_ENABLED = 'marketplace.allegro.enabled'; // value: "0"|"1"
    case MARKETPLACE_ALLEGRO_CONFIG  = 'marketplace.allegro.config';  // value_json: { client_id, client_secret, redirect_uri, ... }

        /* =========
     * Debug/Dev
     * ========= */
    case DEBUG_VERBOSE_LOGS = 'debug.verbose_logs'; // value: "0"|"1"
}
