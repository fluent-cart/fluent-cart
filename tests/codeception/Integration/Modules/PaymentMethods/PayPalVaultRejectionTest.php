<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\Processor;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Spec: Cases/saved-payment-methods-paypal.md
 *
 * Guards the decision to retry an order without its vault attributes. Treating
 * an unrelated failure as a vault rejection would switch card-saving off for a
 * perfectly capable merchant account and retry a request that dropping the
 * vault block cannot fix.
 */
class PayPalVaultRejectionTest extends WPTestCase
{
    /**
     * Spec: Cases/saved-payment-methods-paypal.md [23]
     *   "A refusal from PayPal switches saving off and is explained to the merchant."
     */
    public function test_paypal_naming_the_vault_field_is_a_vault_rejection()
    {
        $error = new \WP_Error('general_error', 'msg', [
            'details' => [
                ['field' => '/payment_source/paypal/attributes/vault', 'issue' => 'INVALID_PARAMETER_SYNTAX'],
            ],
        ]);

        $this->assertTrue(
            Processor::isVaultRejection($error),
            'PayPal pointing at the vault field is the clearest possible evidence.'
        );
    }

    /**
     * Spec: Cases/saved-payment-methods-paypal.md [23]
     *   "A refusal from PayPal switches saving off and is explained to the merchant."
     */
    public function test_a_known_vault_issue_code_is_a_vault_rejection()
    {
        $error = new \WP_Error('general_error', 'msg', [
            'details' => [['issue' => 'PAYMENT_SOURCE_CANNOT_BE_USED']],
        ]);

        $this->assertTrue(Processor::isVaultRejection($error), 'A known vault issue code counts.');
    }

    /**
     * @dataProvider unrelatedFailures
     */
    public function test_an_unrelated_failure_is_not_a_vault_rejection(array $body, string $label)
    {
        $this->assertFalse(
            Processor::isVaultRejection(new \WP_Error('general_error', 'msg', $body)),
            $label . ' must not be mistaken for a vaulting problem.'
        );
    }

    public function unrelatedFailures(): array
    {
        return [
            'auth failure'     => [['name' => 'AUTHENTICATION_FAILURE', 'details' => [['issue' => 'INVALID_TOKEN']]], 'An expired access token'],
            'rate limit'       => [['name' => 'RATE_LIMIT_REACHED', 'details' => [['issue' => 'RATE_LIMIT_REACHED']]], 'A rate limit'],
            'malformed amount' => [['details' => [['field' => '/purchase_units/0/amount/value', 'issue' => 'INVALID_PARAMETER_SYNTAX']]], 'A bad amount'],
            'server error'     => [['name' => 'INTERNAL_SERVER_ERROR'], 'A PayPal outage'],
            'empty body'       => [[], 'An error carrying no detail'],
        ];
    }

    public function test_a_transport_error_carrying_no_body_is_not_a_vault_rejection()
    {
        $this->assertFalse(
            Processor::isVaultRejection(new \WP_Error('http_request_failed', 'cURL error 28: timed out')),
            'A timeout says nothing about whether the account can vault.'
        );
    }

    public function test_a_successful_response_is_not_a_vault_rejection()
    {
        $this->assertFalse(
            Processor::isVaultRejection(['id' => 'PPORDER1', 'status' => 'COMPLETED']),
            'Only a WP_Error can be a rejection.'
        );
    }

    public function test_the_recognised_issue_list_can_be_extended_without_a_core_release()
    {
        $filter = function ($issues) {
            $issues[] = 'SOME_NEW_PAYPAL_CODE';
            return $issues;
        };
        add_filter('fluent_cart/payments/paypal_vault_rejection_issues', $filter);

        $isRejection = Processor::isVaultRejection(new \WP_Error('general_error', 'msg', [
            'details' => [['issue' => 'SOME_NEW_PAYPAL_CODE']],
        ]));

        remove_filter('fluent_cart/payments/paypal_vault_rejection_issues', $filter);

        $this->assertTrue($isRejection, 'PayPal adds codes faster than a core release can follow.');
    }
}
