<?php

namespace Tests\Support\Doubles;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPal;

class TestablePayPalGatewayCallback extends PayPal
{
    private array $paypalSubscription = [];
    private array $paypalPayment = [];

    public function __construct(array $paypalSubscription = [])
    {
        $this->paypalSubscription = $paypalSubscription;
    }

    public function withPayPalPayment(array $paypalPayment): self
    {
        $this->paypalPayment = $paypalPayment;

        return $this;
    }

    protected function getPayPalSubscription($subscriptionId)
    {
        return $this->paypalSubscription;
    }

    protected function verifyPayPalPayment($payPalReferenceId)
    {
        return $this->paypalPayment;
    }

    protected function acquirePayPalCaptureLock($chargeId)
    {
        return true;
    }

    protected function releasePayPalCaptureLock($chargeId)
    {
        return null;
    }
}
