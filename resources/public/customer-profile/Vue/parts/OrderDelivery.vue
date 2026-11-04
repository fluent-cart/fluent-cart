<template>
  <div class="fct-order-delivery">
    <div
        v-for="(shipment, index) in shipments"
        :key="shipment.id"
        class="fct-order-delivery__row"
        :class="{'is-open': isOpen(shipment)}"
    >
      <div class="fct-order-delivery__head">
        <button
            type="button"
            class="fct-order-delivery__toggle"
            :aria-expanded="isOpen(shipment) ? 'true' : 'false'"
            :aria-controls="'fct-shipment-body-' + shipment.id"
            @click="toggle(shipment.id)"
        >
          <svg class="fct-order-delivery__chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M6 4l4 4-4 4"/>
          </svg>
          <span class="fct-order-delivery__title">
            <!-- translators: %1$s is the shipment number -->
            <span class="fct-order-delivery__name">{{ translate('Shipment %1$s', translateNumber(index + 1)) }}</span>
            <small>{{ itemNames(shipment.items) }}</small>
            <small v-if="lastUpdate(shipment)">
              <!-- translators: %1$s is the date and time of the shipment's latest update -->
              {{ translate('Last update %1$s', dateTimeI18(lastUpdate(shipment), 'date_time')) }}
            </small>
          </span>
        </button>
        <div class="fct-order-delivery__side">
          <Badge :type="shipment.status" :text="shipmentLabel(shipment)"/>
          <small v-if="expectedText(shipment)" class="fct-order-delivery__eta">{{ expectedText(shipment) }}</small>
          <a v-if="shipment.tracking_url" :href="shipment.tracking_url" target="_blank" rel="noopener noreferrer"
             class="el-button el-button--x-small">{{ translate('Track') }}</a>
        </div>
      </div>
      <Animation :visible="isOpen(shipment)" accordion>
        <div :id="'fct-shipment-body-' + shipment.id" class="fct-order-delivery__body">
          <ShipmentCard :shipment="shipment"/>
        </div>
      </Animation>
    </div>

    <div v-if="unshippedItems.length" class="fct-order-delivery__row fct-order-delivery__row--waiting">
      <div class="fct-order-delivery__head">
        <span class="fct-order-delivery__title">
          <span class="fct-order-delivery__name">{{ translate('Not shipped yet') }}</span>
          <small>{{ itemNames(unshippedItems) }}</small>
          <small>{{ translate('We will email you when it ships.') }}</small>
        </span>
        <div class="fct-order-delivery__side">
          <Badge type="info" :text="translate('Waiting')"/>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {ref} from 'vue';
import Animation from '@/Bits/Components/Animation.vue';
import Badge from './Badge.vue';
import ShipmentCard from './ShipmentCard.vue';
import translate, {dateTimeI18, translateNumber} from '../../translator/Translator';
import {defaultOpenIds, expectedText, lastUpdate, shipmentLabel} from '../../utils/shipmentLabels';

const props = defineProps({
  shipments: {type: Array, default: () => []},
  unshippedItems: {type: Array, default: () => []}
});

// Not persisted: every page load applies the default again.
const openIds = ref(defaultOpenIds(props.shipments));

const isOpen = shipment => openIds.value.includes(shipment.id);

const toggle = (id) => {
  openIds.value = openIds.value.includes(id)
      ? openIds.value.filter(openId => openId !== id)
      : [...openIds.value, id];
};

const itemNames = items => (items || [])
    .map(item => item.qty > 1 ? item.item_name + ' × ' + translateNumber(item.qty) : item.item_name)
    .join(', ');
</script>
