<?php

namespace Tests\Support\Shipping;

use FluentCart\App\App;
use FluentCart\App\Modules\Shipping\Carriers\Core\AbstractShippingCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Core\BaseCarrierSettings;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Services\Shipments\ShipmentInstance;

/**
 * A fake carrier. Tests set the rates it quotes at checkout, what its dispatch,
 * cancel and admin quote return, and read back what each call received.
 * CarrierManager cannot unregister, so the stub registers once and is switched
 * off through $enabled.
 */
class StubShippingCarrier extends AbstractShippingCarrier
{
    public const SLUG = 'stub';

    /** @var array[] rates returned by getRates() */
    public static array $rates = [];

    /** @var array<string, array[]> rates per requested `currency` arg; wins over $rates */
    public static array $ratesByCurrency = [];

    /** @var string[]|null currencies canQuoteInCurrency() accepts; null = undeclared */
    public static ?array $quotableCurrencies = null;

    /** @var array[] args of every getRates() call */
    public static array $calls = [];

    public static bool $enabled = true;

    /** @var string[] meta()['supports'] */
    public static array $supports = ['rates', 'labels'];

    public static bool $enableLabels = true;

    /** @var array|\WP_Error|null returned by makeShipmentFromShipmentInstance() */
    public static $dispatchResult = null;

    /** @var callable|null runs inside dispatch, standing in for the carrier's HTTP call */
    public static $onDispatch = null;

    /** @var int[] DB transaction level seen by each dispatch */
    public static array $dispatchLevels = [];

    /** @var \WP_Error|bool|null returned by cancelShipmentFromShipmentInstance() */
    public static $cancelResult = null;

    /** @var array[] instance data of every cancel call */
    public static array $cancelCalls = [];

    /** @var int[] DB transaction level seen by each cancel */
    public static array $cancelLevels = [];

    public static bool $supportsSync = true;

    /** @var string|\WP_Error returned by getLabelDownloadUrl() */
    public static $labelUrl = '';

    /** @var int[] shipment ids getLabelDownloadUrl() was asked for */
    public static array $labelUrlCalls = [];

    /** @var bool returned by canTrackWithoutLabel() */
    public static bool $tracksWithoutLabel = false;

    /** @var bool|\WP_Error|null returned by syncTrackingFromShipmentInstance() */
    public static $syncResult = false;

    /** @var callable|null runs inside sync, standing in for the carrier's pull */
    public static $onSync = null;

    /** @var int number of syncTrackingFromShipmentInstance() calls */
    public static int $syncCalls = 0;

    public static string $webhookUrl = '';

    /** @var array[] fields() */
    public static array $fields = [];

    /** @var callable|null runs as the carrier's webhook handler */
    public static $onWebhook = null;

    /** @var int number of handleWebhook() calls */
    public static int $webhookCalls = 0;

    /** @var array|\WP_Error|null returned by getShipmentQuote() */
    public static $quoteResult = null;

    /** @var array items the last getShipmentQuote() call received */
    public static array $lastQuoteItems = [];

    /** @var int[] DB transaction level seen by each quote */
    public static array $quoteLevels = [];

    /** @var array returned by getSetupNotices() */
    public static array $setupNotices = [];

    /** @var array|\WP_Error|null returned by runAction(); null = the base class answer */
    public static $actionResult = null;

    /** @var array[] [action, data] of every runAction() call */
    public static array $actionCalls = [];

    public static function install(array $rates = []): void
    {
        self::reset();
        self::$rates   = $rates;
        self::$enabled = true;

        if (!CarrierManager::carrier(self::SLUG)) {
            CarrierManager::getInstance()->register(self::SLUG, new self(new StubCarrierSettings()));
        }
    }

    /** Registers StubRecheckCarrier, the stub that also rechecks unconfirmed purchases. */
    public static function installRecheck(array $rates = []): void
    {
        self::install($rates);

        if (!CarrierManager::carrier(StubRecheckCarrier::SLUG)) {
            CarrierManager::getInstance()->register(StubRecheckCarrier::SLUG, new StubRecheckCarrier(new StubCarrierSettings()));
        }
    }

    public static function uninstall(): void
    {
        self::reset();
        self::$enabled = false;
    }

    private static function reset(): void
    {
        self::$rates          = [];
        self::$ratesByCurrency    = [];
        self::$quotableCurrencies = null;
        self::$calls          = [];
        self::$supports       = ['rates', 'labels'];
        self::$enableLabels   = true;
        self::$dispatchResult = null;
        self::$onDispatch     = null;
        self::$dispatchLevels = [];
        self::$cancelResult   = null;
        self::$cancelCalls    = [];
        self::$cancelLevels   = [];
        self::$supportsSync   = true;
        self::$labelUrl       = '';
        self::$labelUrlCalls  = [];
        self::$tracksWithoutLabel = false;
        self::$syncResult     = false;
        self::$onSync         = null;
        self::$syncCalls      = 0;
        self::$webhookUrl     = '';
        self::$fields         = [];
        StubCarrierSettings::$saved = [];
        self::$onWebhook      = null;
        self::$webhookCalls   = 0;
        self::$quoteResult    = [
            'rate_object_id' => 'stub_rate',
            'service_name'   => 'Stub — Ground',
            'cost_cents'     => 500,
            'currency'       => 'USD',
            'rates'          => [
                [
                    'rate_object_id' => 'stub_rate',
                    'service_name'   => 'Stub — Ground',
                    'cost_cents'     => 500,
                    'currency'       => 'USD',
                ],
            ],
        ];
        self::$lastQuoteItems = [];
        self::$quoteLevels    = [];
        self::$setupNotices   = [];
        self::$actionResult   = null;
        self::$actionCalls    = [];
    }

    public function meta(): array
    {
        return [
            'slug'     => self::SLUG,
            'name'     => 'Stub Carrier',
            'supports' => self::$supports,
        ];
    }

    public function fields(): array
    {
        return self::$fields;
    }

    public function isEnabled(): bool
    {
        return self::$enabled;
    }

    public function getSettings(): BaseCarrierSettings
    {
        return new StubCarrierSettings();
    }

    public function getRates(array $args): array
    {
        self::$calls[] = $args;

        $currency = (string) ($args['currency'] ?? '');
        if (isset(self::$ratesByCurrency[$currency])) {
            return self::$ratesByCurrency[$currency];
        }

        return self::$rates;
    }

    public function canQuoteInCurrency(string $currency): ?bool
    {
        if (self::$quotableCurrencies === null) {
            return null;
        }

        return in_array(strtoupper($currency), self::$quotableCurrencies, true);
    }

    public function makeShipmentFromShipmentInstance(ShipmentInstance $instance)
    {
        self::$dispatchLevels[] = (int) App::db()->transactionLevel();

        if (self::$onDispatch) {
            (self::$onDispatch)($instance);
        }

        return self::$dispatchResult;
    }

    public function cancelShipmentFromShipmentInstance(ShipmentInstance $instance)
    {
        self::$cancelCalls[]  = $instance->data;
        self::$cancelLevels[] = (int) App::db()->transactionLevel();

        return self::$cancelResult;
    }

    /** A shipment the stub bought a label for carries its transaction_id. */
    public function hasLabel(\FluentCart\App\Models\Shipment $shipment): bool
    {
        return !empty(is_array($shipment->meta) ? ($shipment->meta['transaction_id'] ?? '') : '');
    }

    public function getLabelDownloadUrl(\FluentCart\App\Models\Shipment $shipment)
    {
        self::$labelUrlCalls[] = (int) $shipment->id;

        return self::$labelUrl;
    }

    public function supportsTrackingSync(): bool
    {
        return self::$supportsSync;
    }

    public function canTrackWithoutLabel(\FluentCart\App\Models\Shipment $shipment): bool
    {
        return self::$tracksWithoutLabel;
    }

    public function syncTrackingFromShipmentInstance(ShipmentInstance $instance)
    {
        self::$syncCalls++;

        if (self::$onSync) {
            return (self::$onSync)($instance);
        }

        return self::$syncResult;
    }

    public function getWebhookUrl(): string
    {
        return self::$webhookUrl;
    }

    public function handleWebhook(): void
    {
        self::$webhookCalls++;

        if (self::$onWebhook) {
            (self::$onWebhook)();
        }
    }

    public function getSetupNotices(): array
    {
        return self::$setupNotices;
    }

    public function runAction(string $action, array $data)
    {
        self::$actionCalls[] = [$action, $data];

        return self::$actionResult === null ? parent::runAction($action, $data) : self::$actionResult;
    }

    public function getShipmentQuote(ShipmentInstance $instance)
    {
        self::$quoteLevels[]  = (int) App::db()->transactionLevel();
        self::$lastQuoteItems = $instance->items;

        return self::$quoteResult;
    }
}

/**
 * The stub plus supportsUnconfirmedRecheck(); the base stub has no such method,
 * like an older add-on.
 */
class StubRecheckCarrier extends StubShippingCarrier
{
    public const SLUG = 'stub_recheck';

    public function meta(): array
    {
        return [
            'slug'     => self::SLUG,
            'name'     => 'Stub Recheck Carrier',
            'supports' => self::$supports,
        ];
    }

    public function supportsUnconfirmedRecheck(): bool
    {
        return true;
    }
}

class StubCarrierSettings extends BaseCarrierSettings
{
    protected string $metaKey = 'fluent_cart_carrier_settings_stub';

    public function getDefaults(): array
    {
        return ['is_active' => 'yes'];
    }

    /** @var array what the last save() stored; never written to the database */
    public static array $saved = [];

    public function get($key = '')
    {
        $settings = array_merge(self::$saved, [
            'is_active'     => StubShippingCarrier::$enabled ? 'yes' : 'no',
            'enable_labels' => StubShippingCarrier::$enableLabels ? 'yes' : 'no',
        ]);

        if ($key === '') {
            return $settings;
        }

        return $settings[$key] ?? null;
    }

    public function save(array $data): void
    {
        self::$saved = $data;
    }
}
