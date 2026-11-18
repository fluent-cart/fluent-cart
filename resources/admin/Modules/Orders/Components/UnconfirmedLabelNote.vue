<template>
  <Alert type="warning" role="status" :content="content">
    <!-- Must match POST .../recheck-unconfirmed and .../dismiss-unconfirmed (orders/manage_statuses). -->
    <UserCan permission="orders/manage_statuses">
      <div class="fct-unconfirmed-label-check mt-2 flex flex-wrap items-center gap-3">
        <fct-button v-if="entry.can_recheck" size="small" :loading="busy === 'check'" :disabled="!!busy" :aria-busy="busy === 'check' ? 'true' : 'false'" @click="check">
          {{ translate('Check again') }}
        </fct-button>
        <fct-popover
            confirm
            :title="translate('Order_Mark_Purchase')"
            :confirm-button-text="translate('Mark resolved')"
            :cancel-button-text="translate('Cancel')"
            width="260"
            @confirm="dismiss"
        >
          <template #reference>
            <fct-button size="small" :loading="busy === 'dismiss'" :disabled="!!busy">
              {{ translate('Mark resolved') }}
            </fct-button>
          </template>
        </fct-popover>
        <span class="text-sm">{{ result }}</span>
      </div>
    </UserCan>
  </Alert>
</template>

<script setup>
import { ref } from 'vue';
import Alert from '@/Bits/Components/Alert.vue';
import UserCan from '@/Bits/Components/Permission/UserCan.vue';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import { getErrorMessage } from '@/Bits/common';

// One label purchase the carrier has not confirmed: {id, carrier, carrier_name, at, can_recheck}.
const props = defineProps({
  orderId: {
    type: [Number, String],
    required: true
  },
  entry: {
    type: Object,
    required: true
  },
  content: {
    type: String,
    required: true
  }
});

// `update` hands the page the server's list after every answer; the page owns it.
const emit = defineEmits(['update', 'found', 'not-found', 'stale']);

const busy = ref('');
const result = ref('');

const report = (body) => {
  if (body && Array.isArray(body.unconfirmed_shipments)) {
    emit('update', body.unconfirmed_shipments);
  }
};

const post = (path, action) => {
  busy.value = action;
  result.value = '';
  return Rest.post('orders/' + props.orderId + '/shipments/' + path, {unconfirmed_id: props.entry.id}).finally(function () {
    busy.value = '';
  });
};

const check = () => {
  if (busy.value) {
    return;
  }

  post('recheck-unconfirmed', 'check').then(function (response) {
    Notify.success(response.message);
    emit('found', response);
    report(response);
  }).catch(function (errors) {
    const body = (errors && errors.data) || {};
    if (body.code === 'no_unconfirmed_request') {
      // Settled elsewhere, maybe as a shipment the page has not loaded.
      emit('stale');
    } else if (!body.purchase_unconfirmed && /_label_not_found$/.test(body.code || '')) {
      // Another open attempt for this carrier still locks Buy.
      const carrierStillPending = (body.unconfirmed_shipments || []).some(entry => entry.carrier === props.entry.carrier);
      emit('not-found', carrierStillPending
          ? translate('No label was found for this purchase.')
          : translate('No label was found at the carrier. You can buy a new one.'));
    } else {
      result.value = body.purchase_unconfirmed
          ? translate('The carrier hasn\'t confirmed this label yet. Try again in a minute.')
          : getErrorMessage(errors, translate('Could not check the label. Please try again.'));
    }
    report(body);
  });
};

const dismiss = () => {
  if (busy.value) {
    return;
  }

  post('dismiss-unconfirmed', 'dismiss').then(function (response) {
    Notify.success(response.message);
    report(response);
  }).catch(function (errors) {
    const body = (errors && errors.data) || {};
    if (body.code !== 'no_unconfirmed_request') {
      result.value = getErrorMessage(errors);
    }
    report(body);
  });
};
</script>
