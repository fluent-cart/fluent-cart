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
        if (in_array($accessor, $this->centColumns)) {
            $amount = Arr::get($this->transaction, $accessor);
            if (!is_numeric($amount)) {
                return $amount;
            }
            return CurrencySettings::getPriceHtml(
                $amount,
                Arr::get($this->transaction, 'currency')
            );
        }
        return $this->get($accessor, $code);
    }

    public function getRefundAmount()
    {
        $refundAmount = Arr::get($this->transaction, 'total', 0);
        return CurrencySettings::getPriceHtml(
            $refundAmount,
            Arr::get($this->transaction, 'currency')
        );
    }

}


