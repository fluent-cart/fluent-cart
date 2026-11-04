<template>
  <el-drawer
      v-model="visible"
      size="560px"
      append-to="#fct_admin_app_wrapper"
      :show-close="false"
      :close-on-click-modal="!submitting"
      :close-on-press-escape="!submitting"
      class="fct-shipment-drawer"
      @closed="$emit('close', stale)"
  >
    <template #header="{ close, titleId }">
      <div class="fct-shipment-drawer__head">
        <div class="flex items-start gap-3">
          <div class="flex-1 min-w-0">
            <h2 :id="titleId" class="fct-shipment-drawer__title">{{ translate('Create shipment') }}</h2>
            <div class="fct-shipment-drawer__order">{{ orderLine }}</div>
            <div v-if="shipTo" class="fct-shipment-drawer__ship-to">{{ shipToLine }}</div>
          </div>
          <IconButton
              tag="button"
              type="button"
              size="small"
              bg="transparent"
              border="none"
              hover="primary"
              :aria-label="translate('Close')"
              @click="close"
          >
            <DynamicIcon name="Cross"/>
          </IconButton>
        </div>

        <ol class="fct-shipment-steps">
          <li
              v-for="(label, index) in stepLabels"
              :key="label"
              :class="{ 'is-done': index + 1 < step, 'is-current': index + 1 === step }"
          >
            <button
                type="button"
                :disabled="!reachable[index]"
                :aria-current="index + 1 === step ? 'step' : null"
                @click="goToStep(index + 1)"
            >
              <span class="fct-shipment-steps__bar" aria-hidden="true"></span>
              {{ index + 1 }}. {{ label }}
            </button>
          </li>
        </ol>
      </div>
    </template>

    <el-form
        ref="formRef"
        :model="form"
        label-position="top"
        @submit.prevent
    >
      <Alert v-if="errorMessage" type="error" class="mb-4" role="alert" :content="errorMessage"/>
      <UnconfirmedLabelNote
          v-for="entry in pendingEntries"
          :key="entry.id"
          class="mb-4"
          :order-id="order.id"
          :entry="entry"
          :content="entry.can_recheck
            ? translate('Order_Carrier_Did_Confirm')
            : translate('Order_Carrier_Did')"
          @update="$emit('unconfirmed', $event)"
          @found="onLabelFound"
          @not-found="onLabelNotFound"
          @stale="stale = true"
      />
      <Alert v-if="recheckNotice" type="info" class="mb-4" role="status" :content="recheckNotice"/>

      <section v-if="step === 1">
        <div class="flex items-center justify-between gap-3 mb-2">
          <h3 ref="stepHeading" tabindex="-1" class="text-sm font-semibold m-0 outline-none">{{ translate('Items to ship') }}</h3>
          <button
              v-if="physicalItems.length"
              type="button"
              class="fct-shipment-link"
              @click="toggleAllQtys"
          >
            {{ allRemainingSet ? translate('Clear all') : translate('Ship all remaining') }}
          </button>
        </div>

        <table class="fct-media-content-list w-full">
          <tbody>
            <tr
                v-for="item in physicalItems"
                :key="item.id"
                class="align-top border-t border-gray-divider dark:border-dark-500"
            >
              <!-- Only the item is dimmed when shipped; the badge keeps full contrast. -->
              <td class="w-[64px] pe-3 py-3 align-top" :class="{ 'opacity-60': remaining(item) === 0 }">
                <div class="product-thumbnail w-[46px] h-[46px] flex-shrink-0 overflow-hidden">
                  <img
                      :src="itemImage(item)"
                      :alt="item.post_title"
                      class="w-full h-full object-contain rounded"
                  />
                </div>
              </td>
              <td class="py-3" :class="{ 'opacity-60': remaining(item) === 0 }">
                <div class="product-title m-0">{{ item.post_title }}</div>
                <div v-if="itemVariant(item)" class="product-variation-title m-0">
                  &#8211; {{ itemVariant(item) }}
                </div>
                <div class="text-xs text-system-mid dark:text-gray-300 mt-1">
                  {{ fulfilledLabel(item) }}
                </div>
              </td>
              <td class="py-3 ps-3 text-end align-middle w-32">
                <QuantityStepper
                    v-if="remaining(item) > 0"
                    v-model="shipQtys[item.id]"
                    :max="remaining(item)"
                    :label="shipQtyLabel(item)"
                    show-max
                />
                <Badge v-else status="shipped" size="small" :text="translate('Fully Shipped')"/>
              </td>
            </tr>
            <tr v-if="physicalItems.length === 0">
              <td colspan="3" class="py-4 text-center text-system-mid dark:text-gray-300">
                {{ translate('No physical items found in this order.') }}
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-else-if="step === 2">
        <h3 ref="stepHeading" tabindex="-1" class="sr-only">{{ translate('Method') }}</h3>

        <Alert
            v-if="carriersFailed"
            type="warning"
            class="mb-3"
            role="alert"
            :content="translate('Could not load the carrier list. Falling back to Manual / Other.')"
        />

        <div class="fct-shipment-ship-with">
          <label :for="shipWithId" class="text-sm font-medium">{{ translate('Ship with') }}</label>
          <el-select
              :id="shipWithId"
              v-model="form.carrier_slug"
              class="w-[200px]"
              :loading="carriersLoading"
              @change="onCarrierChange"
          >
            <el-option
                v-for="option in carrierOptions"
                :key="option.value"
                :value="option.value"
                :label="option.label"
            />
          </el-select>
        </div>

        <div v-if="carrierQuotesRates" class="mb-4">
          <div class="fct-shipment-rates" :aria-busy="quoteLoading ? 'true' : 'false'">
            <div class="fct-shipment-rates__head">
              <span>{{ ratesHeading }}</span>
              <button
                  type="button"
                  class="fct-shipment-link"
                  :disabled="quoteLoading || allQtysZero || submitting"
                  @click="fetchQuote"
              >
                <DynamicIcon name="Refresh" class="w-3.5 h-3.5" :class="{ 'animate-spin': quoteLoading }"/>
                {{ translate('Refresh') }}
              </button>
            </div>

            <template v-if="quoteLoading">
              <div v-for="row in 3" :key="row" class="fct-shipment-rate is-skeleton" aria-hidden="true">
                <span class="fct-shipment-rate__radio"></span>
                <span class="flex-1">
                  <span class="fct-shipment-skeleton w-2/5"></span>
                  <span class="fct-shipment-skeleton w-1/4 mt-2"></span>
                </span>
                <span class="fct-shipment-skeleton w-16"></span>
              </div>
            </template>

            <div
                v-else-if="quoteRates.length"
                role="radiogroup"
                :aria-label="rateListLabel"
            >
              <button
                  v-for="(rate, index) in quoteRates"
                  :key="rate.rate_object_id"
                  :ref="(element) => { rateButtons[index] = element; }"
                  type="button"
                  role="radio"
                  class="fct-shipment-rate"
                  :aria-checked="rate.rate_object_id === form.selected_rate_id ? 'true' : 'false'"
                  :tabindex="rateTabIndex(rate, index)"
                  @click="onRateChange(rate.rate_object_id)"
                  @keydown="onRateKeydown($event, index)"
              >
                <span class="fct-shipment-rate__radio" aria-hidden="true"></span>
                <span class="flex-1 min-w-0">
                  <span class="flex flex-wrap items-center gap-1.5">
                    <span class="font-medium">{{ rate.service_name }}</span>
                    <span v-if="rate.rate_object_id === highlights.cheapest" class="fct-shipment-tag is-cheapest">{{ translate('Cheapest') }}</span>
                    <span v-if="rate.rate_object_id === highlights.fastest" class="fct-shipment-tag is-fastest">{{ translate('Fastest') }}</span>
                  </span>
                  <span v-if="rate.estimated_days" class="block text-xs text-system-mid dark:text-gray-300">
                    {{ estimatedDaysLabel(rate.estimated_days) }}
                  </span>
                </span>
                <span class="font-semibold whitespace-nowrap">{{ formatQuoteCost(rate) }}</span>
              </button>
              <button
                  v-if="offersCustomCost"
                  :ref="(element) => { rateButtons[quoteRates.length] = element; }"
                  type="button"
                  role="radio"
                  class="fct-shipment-rate"
                  :aria-checked="customCost ? 'true' : 'false'"
                  :tabindex="customCost ? 0 : -1"
                  @click="chooseCustomCost"
                  @keydown="onRateKeydown($event, quoteRates.length)"
              >
                <span class="fct-shipment-rate__radio" aria-hidden="true"></span>
                <span class="flex-1 min-w-0">
                  <span class="font-medium">{{ translate('Custom price') }}</span>
                  <span class="block text-xs text-system-mid dark:text-gray-300">
                    {{ translate('Enter the cost yourself. Use 0 for free shipping.') }}
                  </span>
                </span>
              </button>
            </div>
          </div>

          <Alert v-if="quoteError" type="error" class="mt-2" role="alert" :content="quoteError"/>
          <div role="status" aria-live="polite" class="sr-only">{{ quoteStatus }}</div>
        </div>

        <div v-if="carrierBuysLabel && selectedRate" class="fct-shipment-sumbar">
          <DynamicIcon name="Tag" class="w-4 h-4 flex-shrink-0"/>
          <div class="flex-1 min-w-0">
            <div class="font-medium">{{ selectedRateTitle }}</div>
            <div class="text-xs text-system-mid dark:text-gray-300">
              {{ translate('%1$s adds the tracking number when the label is bought.', selectedCarrierName) }}
            </div>
            <div v-if="costBillingHint" class="fct-shipment-cost-estimate text-xs text-system-mid dark:text-gray-300 mt-1">
              {{ costBillingHint }}
            </div>
          </div>
          <div class="text-end">
            <div class="text-xs text-system-mid dark:text-gray-300">{{ translate('Label cost') }}</div>
            <div class="font-semibold whitespace-nowrap">{{ formatQuoteCost(selectedRate) }}</div>
          </div>
        </div>

        <template v-if="!carrierBuysLabel">
          <!-- A zone method brings its own name and price. -->
          <Animation :visible="!zoneMethodPicked" accordion>
            <el-row :gutter="16">
              <el-col v-if="form.carrier_slug === CARRIER_MANUAL" :xs="24" :sm="12">
                <el-form-item :label="translate('Carrier Name')" prop="carrier_name">
                  <el-input
                      v-model="form.carrier_name"
                      :maxlength="100"
                      :placeholder="translate('Optional carrier name')"
                  />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item :label="translate('Shipping Cost')" prop="cost">
                  <PriceInput
                      v-model="form.cost"
                      :currency="costCurrency"
                      :show-currency-sign="!!costCurrency"
                      fixed-decimals
                      :placeholder="translate('0.00')"
                  >
                    <template v-if="showCostCurrencyCode" #suffix>
                      <span class="text-xs">{{ costCurrency }}</span>
                    </template>
                  </PriceInput>
                </el-form-item>
              </el-col>
            </el-row>
          </Animation>

          <el-row :gutter="16">
            <el-col :xs="24" :sm="12">
              <el-form-item :label="translate('Tracking Number')" prop="tracking_number">
                <el-input
                    v-model="form.tracking_number"
                    :maxlength="255"
                    :placeholder="translate('Enter tracking number')"
                />
              </el-form-item>
            </el-col>
            <el-col :xs="24" :sm="12">
              <el-form-item :label="translate('Tracking URL')" prop="tracking_url">
                <el-input
                    v-model="form.tracking_url"
                    :maxlength="500"
                    :placeholder="methodTrackingPattern || translate('https://example.com/track/...')"
                    :aria-describedby="trackingUrlHintId"
                />
                <template #error="{ error }">
                  <div class="el-form-item__error" role="alert">{{ error }}</div>
                </template>
              </el-form-item>
            </el-col>
          </el-row>
          <p :id="trackingUrlHintId" class="fct-shipment-tracking-hint">
            {{ translate('The tracking URL fills in from the shipping method\'s pattern, when it has one.') }}
          </p>
        </template>
      </section>

      <section v-else>
        <h3 ref="stepHeading" tabindex="-1" class="sr-only">{{ _x('Review', 'Shipment wizard step') }}</h3>

        <dl class="fct-shipment-review">
          <div>
            <dt>{{ translate('Items') }}</dt>
            <dd>{{ itemsSummary }}</dd>
          </div>
          <div>
            <dt>{{ translate('Carrier') }}</dt>
            <dd>
              {{ reviewCarrier }}
              <span v-if="reviewCarrierMeta" class="text-xs text-system-mid dark:text-gray-300">{{ reviewCarrierMeta }}</span>
            </dd>
          </div>
          <div>
            <dt>{{ translate('Tracking') }}</dt>
            <dd v-if="carrierBuysLabel" class="text-system-mid dark:text-gray-300">{{ translate('Assigned when the label is bought') }}</dd>
            <dd v-else-if="form.tracking_number" class="font-mono">{{ form.tracking_number }}</dd>
            <dd v-else class="text-system-mid dark:text-gray-300">{{ translate('Not set') }}</dd>
          </div>
          <div>
            <dt>{{ translate('Cost') }}</dt>
            <dd class="font-semibold">{{ reviewCost }}</dd>
          </div>
        </dl>

        <div v-if="!editShippedAt" class="flex flex-wrap items-center gap-2 text-sm mb-4">
          <DynamicIcon name="Clock" class="w-4 h-4 text-system-mid dark:text-gray-300"/>
          <span>{{ translate('Shipped now') }}</span>
          <button type="button" class="fct-shipment-link" @click="revealShippedAt">{{ translate('Change') }}</button>
        </div>
        <el-form-item v-else :label="translate('Shipped At')" prop="shipped_at">
          <el-date-picker
              ref="shippedAtRef"
              v-model="form.shipped_at"
              type="datetime"
              :placeholder="translate('Select date and time')"
              format="YYYY-MM-DD HH:mm:ss"
              value-format="YYYY-MM-DD HH:mm:ss"
              class="w-full"
          />
        </el-form-item>

        <button v-if="!showNote" type="button" class="fct-shipment-link mb-4" @click="revealNote">
          <DynamicIcon name="Plus" class="w-3.5 h-3.5"/>
          {{ translate('Add a note for the customer') }}
        </button>
        <Animation :visible="showNote" accordion>
          <el-form-item :label="translate('Note to customer')" prop="notes">
            <el-input
                ref="noteRef"
                v-model="form.notes"
                type="textarea"
                :rows="2"
                :placeholder="translate('Optional. Shown to the customer with this shipment.')"
            />
          </el-form-item>
        </Animation>

        <el-form-item class="mb-0">
          <el-checkbox v-model="form.notify_customer">
            {{ translate('Notify Customer') }}
          </el-checkbox>
        </el-form-item>
      </section>
    </el-form>

    <template #footer>
      <div class="flex flex-wrap items-center gap-2">
        <span class="flex-1 text-start text-sm text-system-mid dark:text-gray-300">{{ footerSummary }}</span>

        <el-button v-if="step === 1" @click="closeDrawer">{{ translate('Cancel') }}</el-button>
        <el-button v-else :disabled="submitting" @click="goToStep(step - 1)">{{ translate('Back') }}</el-button>

        <el-button
            v-if="step < 3"
            type="primary"
            :disabled="step === 2 && (carriersLoading || quoteLoading)"
            @click="next"
        >
          {{ translate('Continue') }}
        </el-button>

        <!-- A label purchase spends money, so it is confirmed with its price. -->
        <el-popconfirm
            v-else-if="carrierBuysLabel"
            :title="buyLabelConfirmTitle"
            :confirm-button-text="translate('Buy Label')"
            :cancel-button-text="translate('Cancel')"
            width="250"
            popper-class="fct-delete-confirm-popover"
            @confirm="submit"
        >
          <template #reference>
            <el-button
                type="primary"
                :loading="submitting"
                :disabled="submitDisabled"
            >
              {{ buyLabelButtonText }}
            </el-button>
          </template>
        </el-popconfirm>
        <el-button
            v-else
            type="primary"
            :loading="submitting"
            :disabled="submitDisabled"
            @click="submit"
        >
          {{ translate('Create Shipment') }}
        </el-button>
      </div>
    </template>
  </el-drawer>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import Alert from '@/Bits/Components/Alert.vue';
import UnconfirmedLabelNote from './UnconfirmedLabelNote.vue';
import Badge from '@/Bits/Components/Badge.vue';
import PriceInput from '@/Bits/Components/Inputs/PriceInput.vue';
import Animation from '@/Bits/Components/Animation.vue';
import QuantityStepper from '@/Bits/Components/Inputs/QuantityStepper.vue';
import translate, { _x, pluralizeTranslate } from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import Asset from '@/utils/support/Asset';
import Arr from '@/utils/support/Arr';
import { getErrorMessage, getOrderItemImageUrl } from '@/Bits/common';
import { FULFILLMENT_TYPE_PHYSICAL, CARRIER_MANUAL } from '@/utils/orderConstants';
import { fetchDispatchCarriers } from '@/Modules/Shipping/useCarriers';
import { formatShipmentCost, formatOrderCurrencyEstimate, withOrderCurrencyEstimate } from './shipmentCost';
import { shipmentCostCurrency } from './shipmentCostCurrency';
import { defaultShipmentCarrier } from './shipmentCarrierDefault';
import { shipmentStepsReachable, rateHighlights } from './shipmentSteps';

dayjs.extend(utc);

const props = defineProps({
  order: {
    type: Object,
    required: true
  }
});

const emit = defineEmits(['created', 'close', 'unconfirmed']);

const visible = ref(true);
const step = ref(1);
const stepHeading = ref();
const shippedAtRef = ref();
const noteRef = ref();
const rateButtons = ref([]);
const editShippedAt = ref(false);
const showNote = ref(false);

const formRef = ref();
const submitting = ref(false);
const errorMessage = ref('');
// The page owns the server's list of unconfirmed purchases; one for this carrier locks Buy.
const pendingEntries = computed(() => (Array.isArray(props.order.unconfirmed_shipments) ? props.order.unconfirmed_shipments : [])
    .filter(entry => entry.carrier === form.value.carrier_slug));
// The server gave no answer, so its list may be stale too; held until the page refetches on close.
const unknownCarrier = ref('');
const stale = ref(false);
const locked = computed(() => pendingEntries.value.length > 0 || unknownCarrier.value === form.value.carrier_slug);
const recheckNotice = ref('');

const carriers = ref([]);
// Three states, not two: still loading / loaded / failed. Collapsing the first two
// would show a "no carriers" error and disable the submit for the whole duration of
// the fetch, on every cold open of this form.
const carriersLoading = ref(true);
const carriersFailed = ref(false);

const carrierOptions = computed(() => {
  // The endpoint lists every REGISTERED carrier, so the only way to have none is a
  // failed request. Manual is always registered, and the server accepts it whether
  // or not it is enabled (the enable toggle governs checkout, not recording), so
  // falling back to it here cannot produce a request the server will refuse.
  if (!carriers.value.length) {
    return [{ value: CARRIER_MANUAL, label: translate('Manual / Other') }];
  }
  return carriers.value.map((carrier) => {
    // A disabled carrier is still recordable, but say so — otherwise it is
    // indistinguishable from one that is actually offered at checkout.
    /* translators: %1$s: carrier name */
    const label = carrier.is_enabled === false
        ? translate('%1$s (disabled)', carrier.name)
        : carrier.name;

    return { value: carrier.slug, label: label };
  });
});

const findCarrier = (slug) => carriers.value.find((carrier) => carrier.slug === slug);

const physicalItems = computed(() => {
  if (!props.order || !Array.isArray(props.order.order_items)) {
    return [];
  }
  return props.order.order_items.filter(function (item) {
    return item.fulfillment_type === FULFILLMENT_TYPE_PHYSICAL;
  });
});

const shipQtys = ref({});

const form = ref({
  carrier_slug: CARRIER_MANUAL,
  carrier_name: '',
  tracking_number: '',
  tracking_url: '',
  // Cents, as PriceInput emits; '' when no cost is set.
  cost: '',
  cost_currency: '',
  selected_rate_id: '',
  notes: '',
  notify_customer: true,
  shipped_at: ''
});

// The carrier buys the label: it assigns tracking and sets the cost.
const carrierBuysLabel = computed(() => {
  const carrier = findCarrier(form.value.carrier_slug);
  return !!carrier && carrier.supports_labels === true;
});

// The carrier can price the shipment (Manual quotes its zone methods too).
const carrierQuotesRates = computed(() => {
  const carrier = findCarrier(form.value.carrier_slug);
  return !!carrier && carrier.supports_rates === true;
});

const selectedCarrierName = computed(() => {
  const carrier = findCarrier(form.value.carrier_slug);
  return carrier ? carrier.name : '';
});

const orderCurrency = computed(() => ((props.order && props.order.currency) || '').toUpperCase());

const costCurrency = computed(() => shipmentCostCurrency({
  rateCurrency: form.value.cost_currency,
  buysLabel: carrierBuysLabel.value,
  orderCurrency: orderCurrency.value,
}));

// A sign such as "$" is shared by several currencies; name the code when the
// cost is not in the order's own currency.
const showCostCurrencyCode = computed(() => !!costCurrency.value && costCurrency.value !== orderCurrency.value);

const quoteRates = ref([]);
const quoteLoading = ref(false);
const quoteError = ref('');
const quoteStatus = ref('');

// Bumped by every fetch and reset, so a response for a carrier or quantities the
// admin has since changed is dropped instead of re-arming the form.
let quoteToken = 0;

const selectedRate = computed(() => {
  return quoteRates.value.find((rate) => rate.rate_object_id === form.value.selected_rate_id) || null;
});

// Manual / Other can skip the zone methods and take a typed cost (0 = free shipping).
const customCost = ref(false);
const offersCustomCost = computed(() => carrierQuotesRates.value && !carrierBuysLabel.value && quoteRates.value.length > 0);

const awaitingRateSelection = computed(() => carrierBuysLabel.value && !selectedRate.value);

const zoneMethodPicked = computed(() => !carrierBuysLabel.value && !!selectedRate.value);

const methodTrackingPattern = computed(() => {
  return (zoneMethodPicked.value && selectedRate.value.tracking_url_pattern) || '';
});

// Matches PHP rawurlencode, so the shown URL is the one the server would build.
const encodeTrackingNumber = (value) => encodeURIComponent(value)
    .replace(/[!'()*]/g, (char) => '%' + char.charCodeAt(0).toString(16).toUpperCase());

const methodTrackingUrl = computed(() => {
  const number = String(form.value.tracking_number || '').trim();
  if (!methodTrackingPattern.value || !number) {
    return '';
  }
  return methodTrackingPattern.value.split('{tracking_number}').join(encodeTrackingNumber(number));
});

// Only a value this form filled in is replaced; a hand-typed URL is kept.
let filledTrackingUrl = '';
watch(methodTrackingUrl, (url) => {
  if (form.value.tracking_url === '' || form.value.tracking_url === filledTrackingUrl) {
    form.value.tracking_url = url;
    filledTrackingUrl = url;
  }
});

const highlights = computed(() => rateHighlights(quoteRates.value));

// A label carrier's rate is bought; a manual method only sets the cost.
const rateListLabel = computed(() => carrierBuysLabel.value
    ? translate('Select the service to purchase:')
    : translate('Select a shipping method to set the cost:'));

const ratesHeading = computed(() => {
  if (quoteLoading.value) {
    /* translators: %1$s: carrier name */
    return translate('Getting rates from %1$s…', selectedCarrierName.value);
  }
  if (allQtysZero.value) {
    return translate('Set at least one item to ship to see rates.');
  }
  const count = quoteRates.value.length;
  if (!count) {
    return translate('No rates loaded.');
  }
  return carrierBuysLabel.value
      /* translators: %s: number of rates */
      ? pluralizeTranslate('%s rate for this address', '%s rates for this address', count)
      /* translators: %s: number of shipping methods */
      : pluralizeTranslate('%s method from your shipping zones', '%s methods from your shipping zones', count);
});

const trackingUrlHintId = 'fct-shipment-tracking-url-hint';
const shipWithId = 'fct-shipment-ship-with';

// The picker shows and emits local time; the column is GMT like every other
// timestamp in the schema, so the value is converted on the way out.
const localTimestamp = () => dayjs().format('YYYY-MM-DD HH:mm:ss');

const toUtcTimestamp = (localValue) => {
  if (!localValue) {
    return '';
  }
  const parsed = dayjs(localValue);
  if (!parsed.isValid()) {
    return '';
  }
  return parsed.utc().format('YYYY-MM-DD HH:mm:ss');
};

const unitsLeft = (item) => {
  return Math.max(0, (item.quantity || 0) - (Number(item.line_meta?.refunded_quantity) || 0));
};

// Mirrors OrderItem::shippedUnitsKept().
const shippedKept = (item) => {
  const shippedRefunded = Number(item.line_meta?.refunded_shipped_quantity) || 0;
  return Math.min(Math.max(0, (Number(item.fulfilled_quantity) || 0) - shippedRefunded), unitsLeft(item));
};

const remaining = (item) => {
  return Math.max(0, unitsLeft(item) - shippedKept(item));
};

const placeholderImage = Asset.productPlaceholder();

const itemImage = (item) => getOrderItemImageUrl(item, placeholderImage);

const itemVariant = (item) => {
  const variant = item.variation_display_title || item.title;
  if (variant && variant !== item.post_title) {
    return variant;
  }
  return '';
};

const shipQtyLabel = (item) => {
  /* translators: %1$s: product name */
  return translate('Quantity to ship now for %1$s', item.post_title);
};

const fulfilledLabel = (item) => {
  /* translators: %1$s: quantity already shipped, %2$s: total quantity ordered */
  return translate('%1$s of %2$s shipped', shippedKept(item), unitsLeft(item));
};


const allRemainingSet = computed(() => physicalItems.value.every((item) => shipQtys.value[item.id] === remaining(item)));

const toggleAllQtys = () => {
  const clear = allRemainingSet.value;
  const qtys = {};
  physicalItems.value.forEach((item) => {
    qtys[item.id] = clear ? 0 : remaining(item);
  });
  shipQtys.value = qtys;
};

const formatQuoteCost = (rate) => {
  const cost = formatShipmentCost(rate.cost_cents, rate.currency);
  if (!cost) {
    return translate('Free');
  }
  const code = (rate.currency || '').toUpperCase();
  if (!code || code === orderCurrency.value) {
    return cost;
  }
  return withOrderCurrencyEstimate(cost + ' ' + code, rate.order_currency_estimate, orderCurrency.value);
};

/* translators: %s: estimated number of days in transit */
const estimatedDaysLabel = (days) => pluralizeTranslate('Est. %s day', 'Est. %s days', days);

const selectedRateTitle = computed(() => {
  const rate = selectedRate.value;
  if (!rate) {
    return '';
  }
  /* translators: %1$s: shipping service name, %2$s: estimated delivery, e.g. Est. 2 days */
  return rate.estimated_days ? translate('%1$s · %2$s', rate.service_name, estimatedDaysLabel(rate.estimated_days)) : rate.service_name;
});

const buyLabelConfirmTitle = computed(() => {
  if (!selectedRate.value) {
    return translate('Buy this label?');
  }
  // The popconfirm title is plain text, so first-strong isolates (U+2068/U+2069)
  // stand in for <bdi>: an RTL sign such as د.إ must not run into the "?".
  const isolate = (value) => '⁨' + value + '⁩';
  /* translators: %1$s: service name, %2$s: formatted label cost */
  return translate('Buy %1$s for %2$s?', isolate(selectedRate.value.service_name), isolate(formatQuoteCost(selectedRate.value)));
});

const buyLabelButtonText = computed(() => {
  if (!selectedRate.value) {
    return translate('Buy label');
  }
  /* translators: %1$s: formatted label cost */
  return translate('Buy label · %1$s', formatQuoteCost(selectedRate.value));
});

// The label is the merchant's cost in the carrier's currency; the order-currency
// figure is only an estimate and is never what gets saved.
const costBillingHint = computed(() => {
  const rate = selectedRate.value;
  const code = ((rate && rate.currency) || '').toUpperCase();
  if (!carrierBuysLabel.value || !code || code === orderCurrency.value) {
    return '';
  }
  const estimate = formatOrderCurrencyEstimate(rate.order_currency_estimate, orderCurrency.value);
  if (!estimate) {
    /* translators: %1$s: carrier name, e.g. Shippo, %2$s: currency code, e.g. USD */
    return translate('Billed by %1$s in %2$s.', selectedCarrierName.value, code);
  }
  /* translators: %1$s: carrier name, e.g. Shippo, %2$s: currency code, e.g. USD, %3$s: approximate amount in the order's currency */
  return translate('Billed by %1$s in %2$s. ≈ %3$s in the order currency.', selectedCarrierName.value, code, estimate);
});

// A quote prices one item set for one carrier; changing either invalidates it.
const resetQuote = () => {
  quoteToken++;
  quoteLoading.value = false;
  quoteRates.value = [];
  quoteError.value = '';
  quoteStatus.value = '';
  form.value.cost = '';
  form.value.cost_currency = '';
  form.value.selected_rate_id = '';
  customCost.value = false;
};

const onCarrierChange = (slug) => {
  const carrier = findCarrier(slug);
  // API carriers ship under their own name; the free-text Carrier Name field only
  // applies to the manual option. Clearing on the way back to manual matters —
  // otherwise the field reappears pre-filled with the carrier the admin just
  // switched away from, and they'd file a manual shipment under its name.
  if (carrier && slug !== CARRIER_MANUAL) {
    form.value.carrier_name = carrier.name;
  } else {
    form.value.carrier_name = '';
  }
  resetQuote();
};

watch(shipQtys, () => {
  if (carrierQuotesRates.value) {
    resetQuote();
  }
}, { deep: true });

const collectDispatchItems = () => {
  const items = [];
  physicalItems.value.forEach((item) => {
    const qty = shipQtys.value[item.id];
    if (qty > 0) {
      items.push({ order_item_id: item.id, qty: qty });
    }
  });
  return items;
};

// Keyed off the same collector the submit and quote paths use: a cleared input
// holds null, which a strict `=== 0` check would miss.
const allQtysZero = computed(() => collectDispatchItems().length === 0);

const totalQty = computed(() => collectDispatchItems().reduce((sum, item) => sum + item.qty, 0));

const reachable = computed(() => shipmentStepsReachable({
  hasItems: !allQtysZero.value,
  carriersReady: !carriersLoading.value,
  buysLabel: carrierBuysLabel.value,
  listsMethods: carrierQuotesRates.value && !carrierBuysLabel.value,
  rateSelected: !!selectedRate.value,
  customPrice: customCost.value,
  quoteFailed: !quoteLoading.value && !!quoteError.value,
}));

const submitDisabled = computed(() => {
  return allQtysZero.value || submitting.value || locked.value || carriersLoading.value || awaitingRateSelection.value;
});

// Picking a rate reprices the shipment, not just the radio.
const onRateChange = (rateId) => {
  const rate = quoteRates.value.find((item) => item.rate_object_id === rateId);
  if (!rate) {
    return;
  }
  form.value.cost = rate.cost_cents;
  form.value.cost_currency = rate.currency || '';
  form.value.selected_rate_id = rate.rate_object_id;
  customCost.value = false;
};

const chooseCustomCost = () => {
  customCost.value = true;
  form.value.selected_rate_id = '';
  form.value.cost = '';
  form.value.cost_currency = '';
};

// Roving tabindex: only the checked row (or the first) is in the tab order.
const rateTabIndex = (rate, index) => {
  if (customCost.value) {
    return -1;
  }
  if (form.value.selected_rate_id) {
    return rate.rate_object_id === form.value.selected_rate_id ? 0 : -1;
  }
  return index === 0 ? 0 : -1;
};

const onRateKeydown = (event, index) => {
  const moves = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
  if (!(event.key in moves)) {
    return;
  }
  event.preventDefault();
  const count = quoteRates.value.length + (offersCustomCost.value ? 1 : 0);
  const target = (index + moves[event.key] + count) % count;
  if (target === quoteRates.value.length) {
    chooseCustomCost();
  } else {
    onRateChange(quoteRates.value[target].rate_object_id);
  }
  const button = rateButtons.value[target];
  if (button) {
    button.focus();
  }
};

const fetchQuote = () => {
  quoteError.value = '';
  quoteStatus.value = '';
  quoteLoading.value = true;

  const requestToken = ++quoteToken;
  const isStale = () => requestToken !== quoteToken;

  Rest.post('orders/' + props.order.id + '/shipments/quote', {
    carrier_slug: form.value.carrier_slug,
    items: collectDispatchItems()
  }).then((response) => {
    if (isStale()) {
      return;
    }

    const rates = Arr.get(response, 'quote.rates', []);
    quoteRates.value = Array.isArray(rates) ? rates.filter((rate) => rate.rate_object_id) : [];

    if (!quoteRates.value.length) {
      quoteError.value = translate('This carrier returned no rates for these items and this address.');
      return;
    }

    /* translators: %s: number of rates */
    quoteStatus.value = pluralizeTranslate('%s rate loaded', '%s rates loaded', quoteRates.value.length);

    // The carrier preselects the service the customer paid for, not the cheapest.
    const preselected = Arr.get(response, 'quote.rate_object_id', '');
    const isOffered = quoteRates.value.some((rate) => rate.rate_object_id === preselected);

    // A Refresh keeps the admin's typed custom cost.
    if (!customCost.value) {
      onRateChange(isOffered ? preselected : quoteRates.value[0].rate_object_id);
    }
  }).catch((errors) => {
    if (isStale()) {
      return;
    }
    resetQuote();
    quoteError.value = getErrorMessage(errors, translate('Could not fetch rates from this carrier. Please try again.'));
  }).finally(() => {
    // Only the newest request owns the spinner.
    if (!isStale()) {
      quoteLoading.value = false;
    }
  });
};

// Rates load on their own on the Method step. A carrier or quantity change
// clears them, so this re-quotes exactly when the last quote went stale.
watch([step, () => form.value.carrier_slug, carrierQuotesRates], () => {
  if (step.value !== 2 || !carrierQuotesRates.value || allQtysZero.value) {
    return;
  }
  if (!quoteRates.value.length && !quoteLoading.value) {
    fetchQuote();
  }
});

const stepLabels = [translate('Items'), translate('Method'), _x('Review', 'Shipment wizard step')];

const goToStep = (target) => {
  if (target < 1 || target > 3 || !reachable.value[target - 1] || target === step.value) {
    return;
  }
  errorMessage.value = '';
  step.value = target;
  // Move focus with the content, so a screen reader hears the new step.
  nextTick(() => {
    if (stepHeading.value) {
      stepHeading.value.focus();
    }
  });
};

const next = () => {
  if (reachable.value[step.value]) {
    goToStep(step.value + 1);
    return;
  }
  if (step.value === 1) {
    errorMessage.value = translate('Please enter a quantity greater than 0 for at least one item.');
    return;
  }
  errorMessage.value = carrierBuysLabel.value
      ? translate('Select a rate to continue.')
      : translate('Select a shipping method to continue.');
};

const revealShippedAt = () => {
  editShippedAt.value = true;
  // Until Change, shipped_at stays empty and the server stamps the Create time.
  form.value.shipped_at = localTimestamp();
  nextTick(() => {
    if (shippedAtRef.value) {
      shippedAtRef.value.focus();
    }
  });
};

const revealNote = () => {
  showNote.value = true;
  nextTick(() => {
    if (noteRef.value) {
      noteRef.value.focus();
    }
  });
};

const closeDrawer = () => {
  visible.value = false;
};

const orderNumber = computed(() => props.order.invoice_no || ('#' + props.order.id));

/* translators: %1$s: order number */
const orderLine = computed(() => translate('Order %1$s', orderNumber.value));

// One line of its own, cut with an ellipsis in the drawer; the full address shows on hover.
const shipTo = computed(() => {
  const address = (props.order.shipping_address && props.order.shipping_address.formatted_address) || {};
  return [address.full_name, address.full_address].filter(Boolean).join(', ');
});

/* translators: %1$s: customer name and shipping address */
const shipToLine = computed(() => translate('Ship to %1$s', shipTo.value));

const itemsSummary = computed(() => {
  return physicalItems.value
      .filter((item) => shipQtys.value[item.id] > 0)
      .map((item) => shipQtys.value[item.id] + '× ' + item.post_title)
      .join(', ');
});

const costText = computed(() => {
  if (carrierBuysLabel.value) {
    return selectedRate.value ? formatQuoteCost(selectedRate.value) : '';
  }
  return formatShipmentCost(Number(form.value.cost) || 0, costCurrency.value || null);
});

const footerSummary = computed(() => {
  /* translators: %s: number of items to ship */
  const items = pluralizeTranslate('%s item', '%s items', totalQty.value);
  /* translators: %1$s: number of items, %2$s: shipping cost */
  return costText.value ? translate('%1$s · %2$s', items, costText.value) : items;
});

const reviewCarrier = computed(() => {
  if (carrierBuysLabel.value) {
    return selectedRate.value ? selectedRate.value.service_name : selectedCarrierName.value;
  }
  const typedName = zoneMethodPicked.value ? '' : String(form.value.carrier_name || '').trim();
  const name = typedName || selectedCarrierName.value || translate('Manual / Other');
  /* translators: %1$s: carrier name, %2$s: shipping method name */
  return selectedRate.value ? translate('%1$s – %2$s', name, selectedRate.value.service_name) : name;
});

const reviewCarrierMeta = computed(() => {
  if (!carrierBuysLabel.value || !selectedRate.value) {
    return '';
  }
  const days = selectedRate.value.estimated_days;
  /* translators: %1$s: carrier name, %2$s: estimated delivery, e.g. Est. 2 days */
  return days
      ? translate('via %1$s · %2$s', selectedCarrierName.value, estimatedDaysLabel(days))
      : translate('via %1$s', selectedCarrierName.value);
});

const reviewCost = computed(() => costText.value || translate('Not set'));

onMounted(() => {
  const qtys = {};
  physicalItems.value.forEach((item) => {
    qtys[item.id] = remaining(item);
  });
  shipQtys.value = qtys;

  fetchDispatchCarriers().then((carrierList) => {
    carriers.value = carrierList;
    // Start on the carrier the customer paid for; never on a value the select does not offer.
    const initialSlug = defaultShipmentCarrier(props.order, carrierList, form.value.carrier_slug);
    if (initialSlug !== form.value.carrier_slug) {
      form.value.carrier_slug = initialSlug;
      onCarrierChange(initialSlug);
    }
  }).catch(() => {
    carriersFailed.value = true;
  }).finally(() => {
    carriersLoading.value = false;
  });
});

// The API takes the cost in decimal units; the form holds cents.
const costForRequest = () => {
  const cents = form.value.cost;
  if (cents === '' || cents === null || cents === undefined || isNaN(Number(cents))) {
    return null;
  }
  return (Number(cents) / 100).toFixed(2);
};

const sendShipment = () => {
  const items = collectDispatchItems();

  if (items.length === 0) {
    errorMessage.value = translate('Please enter a quantity greater than 0 for at least one item.');
    // submit() claimed the flag before validating; release it so the form recovers.
    submitting.value = false;
    return;
  }

  // A label may have been bought, but the server never said so; a manual shipment buys nothing.
  const lockUnconfirmed = () => {
    if (carrierBuysLabel.value) {
      unknownCarrier.value = form.value.carrier_slug;
    }
    stale.value = true;
    errorMessage.value = translate('Order_Server_Stopped');
  };

  Rest.post('orders/' + props.order.id + '/shipments', {
    carrier_slug: form.value.carrier_slug,
    carrier_name: zoneMethodPicked.value ? '' : form.value.carrier_name,
    tracking_number: form.value.tracking_number,
    tracking_url: form.value.tracking_url,
    cost: costForRequest(),
    cost_currency: costCurrency.value,
    selected_rate_id: form.value.selected_rate_id,
    items: items,
    notes: form.value.notes,
    notify_customer: form.value.notify_customer,
    shipped_at: toUtcTimestamp(form.value.shipped_at)
  }).then(function (response) {
    // An empty body means the server died mid-request; a label may have been bought.
    if (!response || !response.shipment) {
      lockUnconfirmed();
      return;
    }
    emit('unconfirmed', response.unconfirmed_shipments || []);
    Notify.success(response.message);
    emit('created', {
      shipment: response.shipment,
      order_shipping_status: response.order_shipping_status
    });
    closeDrawer();
  }).catch(function (errors) {
    if (errors && errors.name === 'AbortError') {
      return;
    }
    // A fatal, proxy page or dropped connection: no JSON body, a 5xx, or no status_code.
    if (carrierBuysLabel.value && (!errors || !errors.data || !errors.status_code || errors.status_code >= 500)) {
      lockUnconfirmed();
      return;
    }
    const body = (errors && errors.data) || {};
    if (Array.isArray(body.unconfirmed_shipments)) {
      emit('unconfirmed', body.unconfirmed_shipments);
    }
    errorMessage.value = getErrorMessage(errors, translate('An error occurred while creating the shipment.'));
  }).finally(function () {
    submitting.value = false;
  });
};

const onLabelFound = (response) => {
  emit('created', {
    shipment: response.shipment,
    order_shipping_status: response.order_shipping_status
  });
  closeDrawer();
};

const onLabelNotFound = (message) => {
  errorMessage.value = '';
  recheckNotice.value = message;
};

// The Buy error belongs to the lock; once the carrier is unlocked it is stale.
watch(() => pendingEntries.value.length, (count, previous) => {
  if (previous && !count) {
    errorMessage.value = '';
  }
});

const submit = () => {
  if (submitting.value || locked.value) {
    return;
  }

  errorMessage.value = '';
  recheckNotice.value = '';

  if (!formRef.value) {
    return;
  }

  if (!reachable.value[2]) {
    errorMessage.value = carrierBuysLabel.value
        ? translate('Select a rate to continue.')
        : translate('Select a shipping method to continue.');
    return;
  }

  // Claim the flag NOW, not in sendShipment(). validate() is async, so leaving the
  // claim until after it means every click in the same frame passes the guard, and
  // the button's :loading binding is no help either — Vue only disables it on the
  // next tick. Measured: three clicks in one frame POSTed three times and created
  // three shipments, triple-counting the fulfilled quantities.
  submitting.value = true;

  formRef.value.validate((valid) => {
    if (!valid) {
      submitting.value = false;
      return;
    }
    sendShipment();
  });
};
</script>
