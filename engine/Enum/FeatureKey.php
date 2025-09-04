<?php
// engine/Enum/FeatureKey.php — Olaj.pl V4 (subskrypcje / dostępność funkcji)
declare(strict_types=1);

namespace Engine\Enum;

enum FeatureKey: string
{
    // ✅ BASIC
    case LIVE_MODULE            = 'live_module';
    case CW                     = 'cw';
    case SHIPPING_INTEGRATIONS = 'shipping_integrations';

        // 🟡 PRO
    case PARSER_DAJ            = 'parser_daj';
    case BULK_ADD_PRODUCTS     = 'bulk_add_products';
    case PRODUCT_TAGS          = 'product_tags';
    case ANALYTICS_DASHBOARD   = 'analytics_dashboard';

        // 🔴 ULTRA
    case ADVANCED_PAYMENTS     = 'advanced_payments';
    case MULTI_WAREHOUSE       = 'multi_warehouse';
    case AI_LOCAL              = 'ai_local';
    case API_ACCESS            = 'api_access';

    // 🎯 Dodatkowe (do przyszłego rozwoju)
    // case MOBILE_APP         = 'mobile_app';
    // case CUSTOM_BRANDING    = 'custom_branding';

    public function label(): string
    {
        return match ($this) {
            self::LIVE_MODULE            => 'Moduł LIVE',
            self::CW                     => 'Centralny Wysyłacz',
            self::SHIPPING_INTEGRATIONS => 'Integracje wysyłek',
            self::PARSER_DAJ            => 'Parser „daj”',
            self::BULK_ADD_PRODUCTS     => 'Masowe dodawanie produktów',
            self::PRODUCT_TAGS          => 'Tagi produktów',
            self::ANALYTICS_DASHBOARD   => 'Dashboard analityczny',
            self::ADVANCED_PAYMENTS     => 'Rozszerzone płatności',
            self::MULTI_WAREHOUSE       => 'Wiele magazynów',
            self::AI_LOCAL              => 'AI lokalne (opis, insight)',
            self::API_ACCESS            => 'Dostęp API',
        };
    }

    public function module(): string
    {
        return match ($this) {
            self::LIVE_MODULE            => 'LIVE',
            self::CW                     => 'CW',
            self::SHIPPING_INTEGRATIONS => 'SHIPPING',
            self::PARSER_DAJ            => 'CW',
            self::BULK_ADD_PRODUCTS     => 'PRODUCTS',
            self::PRODUCT_TAGS          => 'PRODUCTS',
            self::ANALYTICS_DASHBOARD   => 'ADMIN',
            self::ADVANCED_PAYMENTS     => 'PAYMENTS',
            self::MULTI_WAREHOUSE       => 'STOCK',
            self::AI_LOCAL              => 'AI',
            self::API_ACCESS            => 'API',
        };
    }
}
