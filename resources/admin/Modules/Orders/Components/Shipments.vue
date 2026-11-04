<template>
  <!-- A pending void warning or an unconfirmed label keeps the card up without shipments. -->
  <Card.Container v-if="shipments.length || voidWarning || unconfirmed.length || recheckNotice" class="fct-shipments-card overflow-hidden">
    <Card.Header border_bottom>
      <template #title>
        <h2 class="fct-card-header-title is-small">
          {{ translate('Shipments') }}
          <span v-if="shipments.length" class="font-normal text-system-mid dark:text-gray-300">{{ shipments.length }}</span>
        </h2>
      </template>
      <!-- A single shipment's cost is already on its row. -->
      <template v-if="shipments.length > 1 && totalCost" #action>
        <span class="text-sm text-system-mid dark:text-gray-300">
          {{ translate('Total') }}
          <span class="font-semibold text-system-dark dark:text-gray-50">{{ totalCost }}</span>
        </span>
      </template>
    </Card.Header>

    <Card.Body v-if="voidWarning">
      <Alert
          type="warning"
          role="alert"
          closable
          :content="voidWarning"
          @close="$emit('dismiss-void-warning')"
      />
    </Card.Body>

    <Card.Body v-if="unconfirmed.length || recheckNotice">
      <UnconfirmedLabelNote
          v-for="pending in unconfirmed"
          :key="pending.id"
          class="mb-2"
          :order-id="order.id"
          :entry="pending"
          :content="
            /* translators: %s: carrier name */
            translate('Order_Did_Confirm', pending.carrier_name)
          "
          @update="$emit('unconfirmed', $event)"
          @found="$emit('shipment-updated')"
          @stale="$emit('shipment-updated')"
          @not-found="$emit('label-not-found', $event)"
      />
      <Alert
          v-if="recheckNotice"
          type="info"
          role="status"
          closable
          :content="recheckNotice"
          @close="$emit('dismiss-recheck-notice')"
      />
    </Card.Body>

    <div v-if="shipments.length" class="fct-shipments-table-wrap">
      <table class="fct-shipments-table">
        <thead>
          <tr>
            <th scope="col">{{ translate('Status') }}</th>
            <th scope="col">{{ translate('Shipment') }}</th>
            <th scope="col">{{ translate('Tracking') }}</th>
            <th scope="col" class="text-end">{{ translate('Cost') }}</th>
            <th scope="col"><span class="sr-only">{{ translate('Actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <ShipmentRecord
              v-for="shipment in shipments"
              :key="shipment.id"
              :shipment="shipment"
              :order-id="order.id"
              :currency="order.currency"
              @deleted="$emit('shipment-deleted')"
              @updated="$emit('shipment-updated')"
              @void-warning="$emit('void-warning', $event)"
          />
        </tbody>
      </table>
    </div>
  </Card.Container>
</template>

<script setup>
import { computed } from 'vue';
import * as Card from '@/Bits/Components/Card/Card.js';
import Alert from '@/Bits/Components/Alert.vue';
import ShipmentRecord from './ShipmentRecord.vue';
import UnconfirmedLabelNote from './UnconfirmedLabelNote.vue';
import translate from '@/utils/translator/Translator';
import { formatShipmentCostTotal } from './shipmentCost';

// Shipments ride along on the single-order payload (`Order::shipments()` is
// eager-loaded), so this card is purely presentational — the parent re-fetches
// the order after a create/delete and the new list flows back down as a prop.
// GET /orders/{id}/shipments stays a public endpoint; the admin just no longer
// needs it.
const props = defineProps({
  order: {
    type: Object,
    required: true
  },
  // A failed label void on delete. Owned by the page: it refetches (and remounts
  // this card) after the delete, and the warning must survive that.
  voidWarning: {
    type: String,
    default: ''
  },
  // "No label found" after a check; page-owned for the same reason as voidWarning.
  recheckNotice: {
    type: String,
    default: ''
  }
});

defineEmits(['shipment-deleted', 'shipment-updated', 'void-warning', 'dismiss-void-warning', 'unconfirmed', 'label-not-found', 'dismiss-recheck-notice']);

const unconfirmed = computed(() => (Array.isArray(props.order.unconfirmed_shipments) ? props.order.unconfirmed_shipments : []));

// Newest first, matching what GET /orders/{id}/shipments used to return
// (`ORDER BY shipped_at DESC`) — a plain hasMany relation carries no ordering.
// Sorted on a copy: never mutate a prop.
const shipments = computed(() => {
  if (!Array.isArray(props.order.shipments)) {
    return [];
  }
  return props.order.shipments.slice().sort((first, second) => {
    const firstDate = first.shipped_at || first.created_at || '';
    const secondDate = second.shipped_at || second.created_at || '';
    if (firstDate === secondDate) {
      return (second.id || 0) - (first.id || 0);
    }
    return firstDate < secondDate ? 1 : -1;
  });
});

// One total per currency; costs billed in different currencies are never summed.
const totalCost = computed(() => formatShipmentCostTotal(shipments.value, props.order.currency));
</script>
