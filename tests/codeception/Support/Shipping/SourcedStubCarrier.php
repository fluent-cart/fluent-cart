<?php

namespace Tests\Support\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Concerns\ProvidesLiveRates;
use FluentCart\App\Modules\Shipping\Carriers\Core\AbstractShippingCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Core\BaseCarrierSettings;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;

/**
 * A live-rate carrier on the real ProvidesLiveRates trait. Tests set its rate
 * sources and each source's answer, and read back every outbound fetch.
 * Registers once and is switched off through $enabled.
 */
class SourcedStubCarrier extends AbstractShippingCarrier
{
    use ProvidesLiveRates;

    public const SLUG = 'stub_sourced';

    public static bool $enabled = false;

    /** @var array<string, string> source id => label; [] = one quote */
    public static array $sources = [];

    /** @var array<string, array[]|\WP_Error> answer per source; '' is the unsplit quote */
    public static array $answers = [];

    /** @var string[] source of every outbound fetch, '' for the unsplit one */
    public static array $fetches = [];

    /** @var array[] destination of every outbound fetch */
    public static array $destinations = [];

    public static int $budget = 20;

    /** @var string[]|null form fields the request holds; null keeps fetchLiveRates() */
    public static ?array $requestFields = null;

    /** @var array[] every request sent through sendLiveRatesRequest() */
    public static array $requests = [];

    /** @var array[] $args of every liveRatesRequest() call */
    public static array $requestArgs = [];

    public static function install(array $sources, array $answers): void
    {
        self::$enabled = true;
        self::$sources = $sources;
        self::$answers = $answers;
        self::$fetches = [];
        self::$destinations = [];
        self::$budget  = 20;
        self::$requestFields = null;
        self::$requests = [];
        self::$requestArgs = [];

        if (!CarrierManager::carrier(self::SLUG)) {
            CarrierManager::getInstance()->register(self::SLUG, new self(new SourcedStubSettings()));
        }
    }

    public static function uninstall(): void
    {
        self::$enabled = false;
        self::$sources = [];
        self::$answers = [];
        self::$fetches = [];
        self::$requestFields = null;
    }

    public static function rate(string $id, int $cost): array
    {
        return [
            'id'             => $id,
            'title'          => 'Sourced ' . $id,
            'cost'           => $cost,
            'currency'       => 'USD',
            'states'         => [],
            'description'    => '',
            'estimated_days' => 2,
        ];
    }

    public function meta(): array
    {
        return ['slug' => self::SLUG, 'name' => 'Sourced Stub', 'supports' => ['rates']];
    }

    public function fields(): array
    {
        return [];
    }

    public function isEnabled(): bool
    {
        return self::$enabled;
    }

    protected function liveRatesBudget(): int
    {
        return self::$budget;
    }

    protected function liveRateSources(): array
    {
        return self::$sources;
    }

    protected function fetchLiveRates(array $destination, array $items, string $currency)
    {
        self::$destinations[] = $destination;

        return $this->answer('');
    }

    protected function liveRatesRequest(array $destination, array $items, string $currency, array $args, string $source): ?array
    {
        if (self::$requestFields === null) {
            return null;
        }
        self::$requestArgs[] = $args;
        $form = isset($args['form_data']) ? $args['form_data'] : [];

        return ['to' => $destination['postcode'], 'form' => array_intersect_key($form, array_flip(self::$requestFields))];
    }

    public function buildsLiveRatesRequests(): bool
    {
        return self::$requestFields !== null;
    }

    protected function sendLiveRatesRequest(array $request, string $currency, string $source)
    {
        self::$requests[] = $request;

        return $this->answer($source);
    }

    protected function fetchLiveRatesForSource(string $source, array $destination, array $items, string $currency)
    {
        return $this->answer($source);
    }

    private function answer(string $source)
    {
        self::$fetches[] = $source;

        return self::$answers[$source] ?? [];
    }
}

class SourcedStubSettings extends BaseCarrierSettings
{
    protected string $metaKey = 'fluent_cart_carrier_settings_stub_sourced';

    public function getDefaults(): array
    {
        return [];
    }

    public function get($key = '')
    {
        $settings = ['is_active' => SourcedStubCarrier::$enabled ? 'yes' : 'no', 'enable_rates' => 'yes'];

        return $key === '' ? $settings : ($settings[$key] ?? null);
    }

    public function save(array $data): void
    {
    }
}
