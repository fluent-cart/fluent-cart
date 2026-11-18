<template>
  <tr class="fct-shipments-table__row" :class="{ 'is-open': expanded }" @click="onRowClick">
    <td>
      <!--
        Same permission as PUT .../shipments/{id}. Without it the status is
        still shown (the `denied` slot), just not changeable.
      -->
      <UserCan permission="orders/manage_statuses">
        <fct-dropdown
            trigger="click"
            :disabled="updating"
            :items="Object.entries(statusOptions).map(([slug, label]) => ({command: slug, label, disabled: slug === currentStatus}))"
            @command="updateStatus"
        >
          <button
              type="button"
              class="fct-shipment-status-trigger"
              :disabled="updating"
              :aria-label="statusAriaLabel"
          >
            <Badge :type="currentStatus" size="small">
              {{ statusLabel }}
              <DynamicIcon v-if="updating" name="Loading" class="fct-shipment-status-trigger__icon animate-spin"/>
              <DynamicIcon v-else name="ChevronDown" class="fct-shipment-status-trigger__icon"/>
            </Badge>
          </button>
        </fct-dropdown>

        <template #denied>
          <Badge :type="currentStatus" size="small" :text="statusLabel"/>
        </template>
      </UserCan>
    </td>

    <td>
      <div class="text-sm font-medium text-system-dark dark:text-gray-50">
        {{ title }}
        <span v-if="subtitle" class="font-normal text-system-mid dark:text-gray-300"> &#8211; {{ subtitle }}</span>
      </div>
      <div v-if="itemsText || shippedAt" class="text-xs text-system-mid dark:text-gray-300">
        {{ itemsText }}<template v-if="itemsText && shippedAt"> &#183; </template>{{ shippedAt }}
      </div>
    </td>

    <td>
      <span v-if="shipment.tracking_number" class="fct-shipment-tracking">
        <span class="font-mono text-xs">{{ shipment.tracking_number }}</span>
        <button
            type="button"
            class="fct-shipment-tracking__action"
            :aria-label="translate('Copy tracking number')"
            :title="translate('Copy tracking number')"
            @click="copyTracking"
        >
          <DynamicIcon name="Copy"/>
        </button>
        <a
            v-if="shipment.tracking_url"
            :href="shipment.tracking_url"
            target="_blank"
            rel="noopener noreferrer"
            class="fct-shipment-tracking__action"
            :aria-label="translate('Open tracking page')"
            :title="translate('Open tracking page')"
        >
          <DynamicIcon name="External"/>
        </a>
      </span>
      <span v-else class="fct-shipments-table__empty">&#8212;</span>
    </td>

    <td class="text-end">
      <span v-if="costText" class="text-sm font-semibold text-system-dark dark:text-gray-50 whitespace-nowrap">{{ costText }}</span>
    </td>

    <td>
      <div class="flex items-center justify-end gap-1">
        <fct-popover
            v-if="hasMoreActions"
            v-model:visible="moreOpen"
            trigger="click"
            placement="bottom-end"
            :width="confirmingDelete ? (hasCarrierLabel ? 290 : 250) : 220"
            popper-class="fct-shipment-menu-popover"
        >
          <template #reference>
            <IconButton
                tag="button"
                type="button"
                size="small"
                bg="transparent"
                border="none"
                aria-haspopup="true"
                :aria-expanded="moreOpen ? 'true' : 'false'"
                :aria-label="translate('More actions')"
                :title="translate('More actions')"
            >
              <DynamicIcon name="More"/>
            </IconButton>
          </template>

          <div v-if="confirmingDelete" class="fct-shipment-menu" role="alertdialog" :aria-label="translate('Delete shipment')">
            <p class="fct-shipment-menu__confirm">{{ deleteConfirmText }}</p>
            <div class="flex justify-end gap-2 px-1.5 pb-1.5">
              <fct-button size="small" @click="confirmingDelete = false">{{ translate('Cancel') }}</fct-button>
              <fct-button size="small" type="danger" :loading="deleting" @click="confirmDelete">{{ translate('Yes, Delete') }}</fct-button>
            </div>
          </div>

          <!-- Any pick closes the menu; the label stays a plain link so its nonce URL opens in a new tab. -->
          <div v-else class="fct-shipment-menu" @click="moreOpen = false">
            <div v-if="shipment.has_label || labelUrl" class="fct-shipment-menu__row">
              <a
                  :href="labelHref"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="fct-shipment-menu__item"
              >
                <DynamicIcon name="Download"/>
                {{ translate('Download label') }}
              </a>
            </div>
            <UserCan v-if="shipment.can_refresh_tracking" permission="orders/manage_statuses">
              <button type="button" class="fct-shipment-menu__item" :disabled="refreshing" @click="refreshTracking">
                <DynamicIcon name="Refresh"/>
                {{ translate('Refresh tracking') }}
              </button>
            </UserCan>
            <button v-if="shipment.tracking_number" type="button" class="fct-shipment-menu__item" @click="copyTracking">
              <DynamicIcon name="Copy"/>
              {{ translate('Copy tracking number') }}
            </button>
            <!-- Must match the DELETE route (orders/manage_statuses). Asks before deleting, so the menu stays open. -->
            <UserCan permission="orders/manage_statuses">
              <button
                  type="button"
                  class="fct-shipment-menu__item is-danger"
                  :disabled="deleting"
                  @click.stop="confirmingDelete = true"
              >
                <DynamicIcon name="Delete"/>
                {{ translate('Delete shipment') }}
              </button>
            </UserCan>
          </div>
        </fct-popover>

        <button
            type="button"
            class="fct-shipments-table__expand"
            :aria-expanded="expanded ? 'true' : 'false'"
            :aria-controls="detailsId"
            :aria-label="translate('Show details')"
            :title="translate('Show details')"
            @click="expanded = !expanded"
        >
          <DynamicIcon name="ChevronDown"/>
        </button>
      </div>
    </td>
  </tr>

  <!-- v-show keeps the row in the DOM so aria-controls always resolves. -->
  <tr v-show="expanded" :id="detailsId" class="fct-shipments-table__details">
    <td colspan="5">
      <dl class="fct-shipments-table__grid">
        <div>
          <dt>{{ translate('Latest tracking') }}</dt>
          <dd v-if="trackingDetail">
            {{ trackingDetail }}
            <span v-if="trackingDate" class="text-xs text-system-mid dark:text-gray-300"> &#183; {{ trackingDate }}</span>
          </dd>
          <dd v-else class="fct-shipments-table__muted">{{ translate('No carrier updates') }}</dd>
        </div>
        <div>
          <dt>{{ translate('Shipped via') }}</dt>
          <dd>{{ carrierLabel }}</dd>
        </div>
        <div>
          <dt>{{ translate('Note to customer') }}</dt>
          <dd v-if="shipment.notes" class="break-words">{{ shipment.notes }}</dd>
          <dd v-else class="fct-shipments-table__muted">{{ translate('None') }}</dd>
        </div>
      </dl>

      <div v-if="parcels.length" class="fct-shipment-parcels">
        <p :id="parcelsId" class="fct-shipment-parcels__title">{{ translate('Parcels') }}</p>
        <ul class="fct-shipment-parcels__list" :aria-labelledby="parcelsId">
          <li v-for="parcel in parcels" :key="parcel.key" class="fct-shipment-parcels__item">
            <span class="fct-shipment-parcels__name">{{ parcel.name }}</span>
            <span v-if="parcel.trackingNumber" class="fct-shipment-tracking">
              <span class="font-mono text-xs">{{ parcel.trackingNumber }}</span>
              <button
                  type="button"
                  class="fct-shipment-tracking__action"
                  :aria-label="parcel.copyAriaLabel"
                  :title="translate('Copy tracking number')"
                  @click="Clipboard.copy(parcel.trackingNumber)"
              >
                <DynamicIcon name="Copy"/>
              </button>
              <a
                  v-if="parcel.trackingUrl"
                  :href="parcel.trackingUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="fct-shipment-tracking__action"
                  :aria-label="parcel.trackingAriaLabel"
                  :title="translate('Open tracking page')"
              >
                <DynamicIcon name="External"/>
              </a>
            </span>
            <span v-else class="fct-shipments-table__muted">{{ translate('No tracking number') }}</span>
            <fct-button
                v-if="parcel.labelHref"
                tag="a"
                size="small"
                class="fct-shipment-parcels__label"
                :href="parcel.labelHref"
                target="_blank"
                rel="noopener noreferrer"
                :aria-label="parcel.labelAriaLabel"
            >
              <DynamicIcon name="Download" class="w-4 h-4 me-1.5"/>
              {{ translate('Download label') }}
            </fct-button>
          </li>
        </ul>
      </div>

      <div v-if="hasLabelLink || shipment.can_refresh_tracking" class="flex flex-wrap gap-2 mt-3">
        <fct-button
            v-if="hasLabelLink"
            tag="a"
            size="small"
            :href="labelHref"
            target="_blank"
            rel="noopener noreferrer"
        >
          <DynamicIcon name="Download" class="w-4 h-4 me-1.5"/>
          {{ translate('Download label') }}
        </fct-button>
        <UserCan v-if="shipment.can_refresh_tracking" permission="orders/manage_statuses">
          <fct-button size="small" :disabled="refreshing" @click="refreshTracking">
            <DynamicIcon name="Refresh" class="w-4 h-4 me-1.5" :class="{ 'animate-spin': refreshing }"/>
            {{ translate('Refresh tracking') }}
          </fct-button>
        </UserCan>
      </div>
    </td>
  </tr>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Badge from '@/Bits/Components/Badge.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import UserCan from '@/Bits/Components/Permission/UserCan.vue';
import Permission from '@/utils/permission/Permission';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import Str from '@/utils/support/Str';
import Clipboard from '@/utils/Clipboard';
import AppConfig from '@/utils/Config/AppConfig';
import { formatDate, getErrorMessage } from '@/Bits/common';
import { SHIPMENT_STATUSES } from '@/utils/orderConstants';
import { formatShipmentCost, withOrderCurrencyEstimate } from './shipmentCost';
import { shipmentHasCarrierLabel, shipmentLabelHref } from './shipmentLabel';

const props = defineProps({
  shipment: {
    type: Object,
    required: true
  },
  orderId: {
    type: Number,
    required: true
  },
  currency: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['deleted', 'updated', 'void-warning']);

const deleting = ref(false);
const updating = ref(false);
const refreshing = ref(false);
const moreOpen = ref(false);
const expanded = ref(false);
const detailsId = computed(() => 'fct-shipment-details-' + props.shipment.id);

const currentStatus = computed(() => props.shipment.status || '');

// PHP owns the labels (they are translated there); the constant list is only a
// fallback for a bundle running against a build that predates the localization.
const statusOptions = computed(() => {
  const localized = AppConfig.get('shipment_statuses');
  if (localized && Object.keys(localized).length) {
    return localized;
  }
  return SHIPMENT_STATUSES.reduce((options, slug) => {
    options[slug] = Str.headline(slug);
    return options;
  }, {});
});

const statusLabel = computed(() => {
  return statusOptions.value[currentStatus.value] || Str.headline(currentStatus.value);
});

const statusAriaLabel = computed(() => {
  /* translators: %1$s: the shipment's current status, e.g. Dispatched */
  return translate('Shipment status: %1$s. Change status', statusLabel.value);
});

const carrierLabel = computed(() => {
  return props.shipment.carrier_name || translate('Manual / Other');
});

const hasCarrierLabel = computed(() => shipmentHasCarrierLabel(props.shipment));

const deleteConfirmText = computed(() => {
  if (!hasCarrierLabel.value) {
    return translate('Delete this shipment?');
  }
  /* translators: %1$s: carrier name, e.g. Shippo */
  return translate('Order_Delete_Shipment', carrierLabel.value);
});

// The service picked or bought (e.g. "Standard", "DPD Classic"); absent on older shipments.
const serviceName = computed(() => (props.shipment.meta && props.shipment.meta.service_name) || '');

// A bought label leads with its service; anything else leads with the carrier.
const leadsWithService = computed(() => !!serviceName.value && (hasCarrierLabel.value || !!props.shipment.has_label));
const title = computed(() => leadsWithService.value ? serviceName.value : carrierLabel.value);
const subtitle = computed(() => leadsWithService.value ? '' : serviceName.value);

const itemsText = computed(() => {
  const items = Array.isArray(props.shipment.items) ? props.shipment.items : [];
  return items.map((lineItem) => lineItem.qty + '× ' + lineItem.item_name).join(', ');
});

const updateStatus = (status) => {
  if (!status || status === currentStatus.value) {
    return;
  }

  updating.value = true;

  Rest.put('orders/' + props.orderId + '/shipments/' + props.shipment.id, { status: status })
      .then((response) => {
        Notify.success(response.message);
        // The order's own shipping_status is derived from its shipments, so the
        // parent refetches rather than this row patching itself.
        emit('updated');
      })
      .catch((errors) => {
        Notify.error(getErrorMessage(errors, translate('Failed to update the shipment status.')));
      })
      .finally(() => {
        updating.value = false;
      });
};

// `shipped_at` and `created_at` are both stored in GMT — render them in the
// viewer's timezone like every other timestamp in the admin.
const shippedAt = computed(() => {
  return formatDate(props.shipment.shipped_at || props.shipment.created_at, true);
});

const costText = computed(() => {
  const meta = props.shipment.meta || {};
  const cost = formatShipmentCost(meta.cost, meta.cost_currency || props.currency || null);
  if (!cost) {
    return '';
  }
  // A label billed in another currency also shows its approximate order-currency figure.
  return withOrderCurrencyEstimate(cost, props.shipment.order_currency_estimate, props.currency);
});

// The latest carrier scan, e.g. "Arrived at facility".
const trackingDetail = computed(() => {
  const meta = props.shipment.meta || {};
  return typeof meta.tracking_status_details === 'string' ? meta.tracking_status_details : '';
});

const trackingDate = computed(() => {
  const meta = props.shipment.meta || {};
  return meta.tracking_status_date ? formatDate(meta.tracking_status_date, true) : '';
});

const httpUrl = (url) => (typeof url === 'string' && /^https?:\/\//i.test(url) ? url : '');

// A carrier-hosted label; a stored PDF goes through the label route instead.
const labelUrl = computed(() => httpUrl(props.shipment.meta && props.shipment.meta.label_url));

// A stored PDF streams from the label route; otherwise the carrier hosts it.
const labelHref = computed(() => {
  if (props.shipment.has_label) {
    return shipmentLabelHref(window.fluentCartRestVars?.rest, props.orderId, props.shipment.id);
  }
  return labelUrl.value;
});

const hasLabelLink = computed(() => !!(props.shipment.has_label || labelUrl.value));

const parcelsId = computed(() => 'fct-shipment-parcels-' + props.shipment.id);

// A carrier that buys one label per parcel stores them all in meta.parcels.
// The first parcel is the shipment's own label, so it keeps that link.
const parcels = computed(() => {
  const list = props.shipment.meta && props.shipment.meta.parcels;
  if (!Array.isArray(list) || list.length < 2) {
    return [];
  }
  return list.map((parcel, index) => {
    const entry = parcel || {};
    const number = index + 1;
    return {
      key: entry.transaction_id || index,
      trackingNumber: String(entry.tracking_number || ''),
      trackingUrl: httpUrl(entry.tracking_url),
      labelHref: index === 0 ? labelHref.value : httpUrl(entry.label_url),
      /* translators: %1$s: the parcel's position in the shipment, e.g. 2 */
      name: translate('Parcel %1$s', number),
      /* translators: %1$s: the parcel's position in the shipment, e.g. 2 */
      copyAriaLabel: translate('Copy tracking number for parcel %1$s', number),
      /* translators: %1$s: the parcel's position in the shipment, e.g. 2 */
      trackingAriaLabel: translate('Open tracking page for parcel %1$s', number),
      /* translators: %1$s: the parcel's position in the shipment, e.g. 2 */
      labelAriaLabel: translate('Download label for parcel %1$s', number)
    };
  });
});

const canManage = computed(() => Permission.resolve({permission: 'orders/manage_statuses', permissionType: 'any'}));

const hasMoreActions = computed(() => {
  return !!(canManage.value || hasLabelLink.value || props.shipment.can_refresh_tracking || props.shipment.tracking_number);
});

const confirmingDelete = ref(false);

// The whole row toggles its details, like a table row elsewhere in the admin.
// Its own controls keep their click, and selecting text does not toggle.
const onRowClick = (event) => {
  if (event.target.closest('button, a, input, .el-dropdown')) {
    return;
  }
  const selection = window.getSelection ? window.getSelection() : null;
  if (selection && String(selection).trim()) {
    return;
  }
  expanded.value = !expanded.value;
};

// Closing the menu drops a half-made delete, so it reopens on the actions.
watch(moreOpen, (open) => {
  if (!open) {
    confirmingDelete.value = false;
  }
});

const confirmDelete = () => {
  moreOpen.value = false;
  deleteShipment();
};

const copyTracking = () => {
  Clipboard.copy(String(props.shipment.tracking_number || ''));
};

const refreshTracking = () => {
  if (refreshing.value) {
    return;
  }
  refreshing.value = true;

  Rest.post('orders/' + props.orderId + '/shipments/' + props.shipment.id + '/refresh-tracking')
      .then((response) => {
        Notify.success(response.message);
        // A refresh can store newer scan details under the same status, so reload either way.
        emit('updated');
      })
      .catch((errors) => {
        Notify.error(getErrorMessage(errors, translate('Failed to refresh tracking.')));
      })
      .finally(() => {
        refreshing.value = false;
      });
};

const deleteShipment = () => {
  deleting.value = true;

  Rest.delete('orders/' + props.orderId + '/shipments/' + props.shipment.id)
      .then((response) => {
        // The shipment is gone either way; a failed label void must stay on screen.
        if (response.void_warning) {
          emit('void-warning', response.message);
        } else {
          Notify.success(response.message);
        }
        emit('deleted');
      })
      .catch((errors) => {
        Notify.error(getErrorMessage(errors, translate('Failed to delete shipment.')));
      })
      .finally(() => {
        deleting.value = false;
      });
};
</script>
