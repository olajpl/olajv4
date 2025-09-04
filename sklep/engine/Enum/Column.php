<?php
// engine/Enum/Column.php

declare(strict_types=1);

namespace Engine\Enum;

final class Column
{
    public const ACTION = 'action';
    public const ACTIVE = 'active';
    public const ADDED_BY_ADMIN_ID = 'added_by_admin_id';
    public const ADDRESS = 'address';
    public const ADDRESS_SET = 'address_set';
    public const ALLOW_DUPLICATES = 'allow_duplicates';
    public const ALT = 'alt';
    public const AMOUNT = 'amount';
    public const AMOUNT_AUTHORIZED = 'amount_authorized';
    public const AMOUNT_CAPTURED = 'amount_captured';
    public const AMOUNT_PLN = 'amount_pln';
    public const AMOUNT_RECEIVED = 'amount_received';
    public const AMOUNT_REFUNDED = 'amount_refunded';
    public const APARTMENT_NO = 'apartment_no';
    public const API_KEY = 'api_key';
    public const APP_SECRET = 'app_secret';
    public const ATTEMPTS = 'attempts';
    public const AVAILABLE_FROM = 'available_from';
    public const BADGE_STYLE = 'badge_style';
    public const BATCH_ID = 'batch_id';
    public const BLIK_PHONE = 'blik_phone';
    public const BODY = 'body';
    public const BODY_TEXT = 'body_text';
    public const BOOKED_AT = 'booked_at';
    public const BRAND = 'brand';
    public const BUILDING_NO = 'building_no';
    public const CAMPAIGN_ID = 'campaign_id';
    public const CAPTURED_AT = 'captured_at';
    public const CARRIER = 'carrier';
    public const CART_SID = 'cart_sid';
    public const CATEGORY_ID = 'category_id';
    public const CHANNEL = 'channel';
    public const CHECKOUT_COMPLETED = 'checkout_completed';
    public const CHECKOUT_TOKEN = 'checkout_token';
    public const CITY = 'city';
    public const CLAIM_STATUS = 'claim_status';
    public const CLAIMANT_PLATFORM = 'claimant_platform';
    public const CLAIMANT_PLATFORM_ID = 'claimant_platform_id';
    public const CLICKED_AT = 'clicked_at';
    public const CLIENT_COUNT = 'client_count';
    public const CLIENT_ID = 'client_id';

    // 🚀 Dodane
    public const ORDER_STATUS = 'order_status';
    public const PAID_STATUS = 'paid_status';
    public const ORDER_GROUP_ID = 'order_group_id';
    public const ORDER_ID = 'order_id';
}
