<?php

namespace FluentCart\App\Modules\PaymentMethods\Core;

use FluentCart\App\Services\Payments\PaymentInstance;

interface PaymentGatewayInterface
{
    public function has(string $feature): bool;
    public function meta(): array;
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance);
    public function handleIPN();
    public function getOrderInfo(array $data);
    public function fields();
}
