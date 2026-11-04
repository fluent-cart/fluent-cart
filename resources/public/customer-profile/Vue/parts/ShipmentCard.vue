<template>
  <div class="fct-shipment-card">

    <div v-if="problem" class="fct-shipment-card__alert">
      <strong>{{ shipment.status === 'failed' ? translate('The carrier could not deliver this parcel') : translate('This parcel went back to the store') }}</strong>
      <span v-if="shipment.tracking_status_details">{{ shipment.tracking_status_details }}</span>
      <span>{{ translate('Check the carrier page, or contact us.') }}</span>
      <small v-if="shipment.tracking_status_date">
        <!-- translators: %1$s is the date and time of the carrier's latest update -->
        {{ translate('Carrier update %1$s', dateTimeI18(shipment.tracking_status_date, 'date_time')) }}
      </small>
    </div>
    <template v-else>
      <ol v-if="step" class="fct-shipment-card__steps" :aria-label="translate('Delivery progress')">
        <li
            v-for="(stepLabel, index) in stepLabels"
            :key="index"
            :class="{'is-done': step > index}"
            :aria-current="step === index + 1 ? 'step' : null"
        >{{ stepLabel }}</li>
      </ol>
      <div v-if="shipment.tracking_status_details" class="fct-shipment-card__update">
        {{ shipment.tracking_status_details }}
        <small v-if="shipment.tracking_status_date">
          <!-- translators: %1$s is the date and time of the carrier's latest update -->
          {{ translate('Carrier update %1$s', dateTimeI18(shipment.tracking_status_date, 'date_time')) }}
        </small>
      </div>
    </template>

    <dl class="fct-shipment-card__meta">
      <div>
        <dt>{{ translate('Carrier') }}</dt>
        <dd>{{ shipment.carrier_name }}<template v-if="shipment.service_name"> &middot; {{ shipment.service_name }}</template></dd>
      </div>
      <div>
        <dt>{{ translate('Tracking Number') }}</dt>
        <dd>
          <span v-if="shipment.tracking_number" class="fct-ltr-isolate break-all">{{ shipment.tracking_number }}</span>
          <template v-else>{{ translate('No tracking number available') }}</template>
        </dd>
      </div>
    </dl>

    <div v-if="shipment.parcels && shipment.parcels.length" class="fct-shipment-card__group">
      <div class="fct-shipment-card__label">{{ translate('Parcels in this shipment') }}</div>
      <ul class="fct-shipment-card__list" role="list">
        <li v-for="(parcel, index) in shipment.parcels" :key="index">
          <!-- translators: %1$s is the parcel number -->
          <span>{{ translate('Parcel %1$s', translateNumber(index + 1)) }}</span>
          <a v-if="parcel.tracking_number && parcel.tracking_url" :href="parcel.tracking_url" target="_blank" rel="noopener noreferrer"
             class="underline-link-button fct-ltr-isolate break-all">{{ parcel.tracking_number }}</a>
          <span v-else class="fct-ltr-isolate break-all">{{ parcel.tracking_number }}</span>
        </li>
      </ul>
    </div>

    <div v-if="shipment.tracking_number" class="fct-shipment-card__actions">
      <a v-if="shipment.tracking_url" :href="shipment.tracking_url" target="_blank" rel="noopener noreferrer"
         class="el-button el-button--primary el-button--small">
        <!-- translators: %1$s is the carrier name -->
        {{ translate('Track on %1$s site', shipment.carrier_name) }}
      </a>
      <CopyToClipboard show-mode="icon_with_text" :text="shipment.tracking_number" :button-text="translate('Copy tracking number')"/>
    </div>

    <div v-if="shipment.items && shipment.items.length" class="fct-shipment-card__group">
      <div class="fct-shipment-card__label">{{ translate('In this shipment') }}</div>
      <ul class="fct-shipment-card__list" role="list">
        <li v-for="(item, index) in shipment.items" :key="index">
          <span>{{ item.item_name }}</span>
          <span>&times; {{ translateNumber(item.qty) }}</span>
        </li>
      </ul>
    </div>

    <div v-if="shipment.notes" class="fct-shipment-card__note">
      <div class="fct-shipment-card__label">{{ translate('Note from the store') }}</div>
      {{ shipment.notes }}
    </div>
  </div>
</template>

<script setup>
import {computed} from 'vue';
import CopyToClipboard from './CopyToClipboard.vue';
import translate, {dateTimeI18, translateNumber} from '../../translator/Translator';
import {isShipmentProblem, shipmentStep} from '../../utils/shipmentLabels';

const props = defineProps({
  shipment: {type: Object, required: true}
});

const step = computed(() => shipmentStep(props.shipment));
const problem = computed(() => isShipmentProblem(props.shipment));
const stepLabels = [translate('Shipped'), translate('On the way'), translate('Delivered')];
</script>
