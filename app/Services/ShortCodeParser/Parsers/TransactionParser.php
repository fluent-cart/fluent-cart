<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentReceipt;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCartPro\App\Modules\Licensing\Models\License;

class TransactionParser extends BaseParser
{
    private $transaction;

    public function __construct($data)
    {
        $this->transaction = Arr::get($data, 'transaction');
        parent::__construct($data);
    }


    protected array $centColumns = [
        'total'
    ];

    public function parse($accessor = '', $code = '', $transformer = null): ?string
    {

        if (empty($this->transaction)) {
            return $code;
        }
        // Handle _formatted suffix for cent columns
        $formattedSuffix = '_formatted';
        if (Str::endsWith($accessor, $formattedSuffix)) {
            $baseAccessor = substr($accessor, 0, -strlen($formattedSuffix));
            if (in_array($baseAccessor, $this->centColumns)) {
                $amount = Arr::get($this->transaction, $baseAccessor);
                if (!is_numeric($amount)) {
                    return (string) $amount;
                }
                return CurrencySettings::getPriceHtml($amount, Arr::get($this->transaction, 'currency'));
            }
        }

        if (in_array($accessor, $this->centColumns)) {
            $amount = Arr::get($this->transaction, $accessor);
            if (!is_numeric($amount)) {
                return (string) $amount;
            }
            return (string) ($amount / 100);
        }
        return $this->get($accessor, $code);
    }

    public function getRefundAmount(): string
    {
        $amount = (int) Arr::get($this->transaction, 'total', 0);
        return (string) ($amount / 100);
    }

    public function getRefundAmountFormatted(): string
    {
        $amount = Arr::get($this->transaction, 'total', 0);
        return CurrencySettings::getPriceHtml($amount, Arr::get($this->transaction, 'currency'));
    }

}


