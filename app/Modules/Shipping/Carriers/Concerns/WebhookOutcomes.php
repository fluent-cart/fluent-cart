<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Concerns;

use FluentCart\App\Models\Shipment;

/**
 * One response shape for every carrier webhook, so it can be logged the same way:
 * [int $httpStatus, ['outcome' => applied|unchanged|ignored|rejected|error, 'reason' => string, ...]].
 * Documented in dev-docs/shipping/03-carrier-framework.md (Webhook outcomes).
 */
trait WebhookOutcomes
{
    protected function webhookApplied(Shipment $shipment, bool $changed): array
    {
        return [200, [
            'outcome'     => $changed ? 'applied' : 'unchanged',
            'reason'      => $changed ? 'status_updated' : 'no_change',
            'applied'     => $changed,
            'shipment_id' => (int) $shipment->id,
            'status'      => (string) $shipment->status,
        ]];
    }

    /**
     * Acknowledged with 200 so the carrier does not retry an event we will never use.
     *
     * @param array $details Optional tracking_number, carrier and mode (test|live), shown in the log.
     */
    protected function webhookIgnored(string $reason, ?Shipment $shipment = null, array $details = []): array
    {
        $body = ['outcome' => 'ignored', 'reason' => $reason, 'ignored' => $reason];
        if ($shipment) {
            $body['shipment_id'] = (int) $shipment->id;
        }
        if ($details) {
            $body['details'] = $details;
        }

        return [200, $body];
    }

    protected function webhookRejected(int $status, string $reason, string $message = ''): array
    {
        return [$status, [
            'outcome' => 'rejected',
            'reason'  => $reason,
            'message' => $message !== '' ? $message : $reason,
        ]];
    }

    /**
     * A 5xx, so the carrier retries.
     */
    protected function webhookFailed(int $status, string $reason, string $message, ?Shipment $shipment = null): array
    {
        $body = ['outcome' => 'error', 'reason' => $reason, 'message' => $message];
        if ($shipment) {
            $body['shipment_id'] = (int) $shipment->id;
        }

        return [$status, $body];
    }
}
