<script setup>
import {computed, ref} from "vue";

import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import TransitionAccordion from "@/Bits/Components/TransitionAccordion.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

/**
 * Guarded control for the store-wide subscription billing decision.
 *
 * Shows only the CURRENT mode as a compact status card with a short
 * how-it-works summary. Editing requires clicking Change, acknowledging a
 * disclaimer, and applying from a dialog — so the setting can never be
 * flipped by a stray click. Apply persists the two keys on its own —
 * `settings/store` merges partial payloads, so the rest of the page's
 * possibly half-edited values stay untouched.
 *
 * Receives the whole settings level as `value` (disable_nesting), so it
 * reads/writes both `subscription_management_mode` and
 * `subscription_system_charge`.
 */
const props = defineProps({
  name: {type: String, required: true},
  field: {type: Object},
  fieldKey: {type: String},
  value: {required: true},
  variant: {type: String},
  nesting: {type: Boolean, default: false},
  statePath: {type: String},
  form: {type: Object, required: true},
  label: {type: String},
  attribute: {type: Object},
  callback: {type: Function},
});

const dialogVisible = ref(false);
const applying = ref(false);
const draftMode = ref('gateway_managed');
const draftSystemCharge = ref(false);
const draftManualFallback = ref(false);

const currentMode = computed(() => props.value?.subscription_management_mode || 'gateway_managed');
const systemChargeOn = computed(() => props.value?.subscription_system_charge === 'yes');
const manualFallbackOn = computed(() => props.value?.subscription_manual_fallback === 'yes');
const isStoreManaged = computed(() => currentMode.value === 'store_managed');

const scheduleItems = computed(() => props.field?.schedule_items || []);

const systemChargeGateways = computed(() => props.field?.system_charge_gateways || []);

const systemChargeSupportNote = computed(() => {
  const all = systemChargeGateways.value.map(item => item.label);
  if (!all.length) {
    return '';
  }

  /* translators: %1$s: comma-separated payment gateway names */
  return translate('Supported by %1$s.', all.join(', '));
});

const statusBadge = computed(() => {
  if (!isStoreManaged.value) {
    return manualFallbackOn.value
        ? {label: translate('Gateway Billing · All methods'), type: 'success'}
        : {label: translate('Gateway Billing'), type: 'success'};
  }
  return systemChargeOn.value
      ? {label: translate('Store Billing · Auto-charge'), type: 'warning'}
      : {label: translate('Store Billing'), type: 'warning'};
});

const fallbackRenewalHint = computed(() => translate('Gateways without built-in recurring billing can still sell subscriptions. Their renewals are invoiced by email before each due date.'));

const statusDescription = computed(() => {
  if (!isStoreManaged.value) {
    return translate('Stripe, PayPal, and other subscription-ready gateways charge renewals automatically.');
  }
  if (systemChargeOn.value) {
    return translate('Your store creates renewal invoices and charges saved payment methods automatically.');
  }
  return translate('Your store creates renewal invoices before each due date. Customers pay via email link.');
});

const openChangeFlow = () => {
  draftMode.value = currentMode.value;
  draftSystemCharge.value = systemChargeOn.value;
  draftManualFallback.value = manualFallbackOn.value;
  dialogVisible.value = true;
};

const applyDraft = () => {
  const prefix = props.statePath || '';
  const mode = draftMode.value;
  const systemCharge = draftSystemCharge.value ? 'yes' : 'no';
  const manualFallback = draftManualFallback.value ? 'yes' : 'no';

  applying.value = true;

  Rest.post('settings/store', {
    subscription_management_mode: mode,
    subscription_system_charge: systemCharge,
    subscription_manual_fallback: manualFallback,
  })
      .then(() => {
        // FormModel.setValue writes the shared values and fires the change
        // callbacks — same contract as a native input change.
        props.form.setValue(`${prefix}subscription_management_mode`, mode);
        props.form.setValue(`${prefix}subscription_system_charge`, systemCharge);
        props.form.setValue(`${prefix}subscription_manual_fallback`, manualFallback);

        Notify.success(translate('Renewal billing updated'));
        dialogVisible.value = false;
      })
      .catch((errors) => {
        Notify.error(errors.data?.message || translate('Could not save the subscription billing mode.'));
      })
      .finally(() => {
        applying.value = false;
      });
};

// Close icon, Escape and backdrop clicks all route through before-close;
// blocking here keeps the dialog from being dismissed mid-request, which
// would read as a cancellation while the save still lands.
const handleBeforeClose = (done) => {
  if (applying.value) {
    return;
  }
  done();
};

const modeOptions = ['gateway_managed', 'store_managed'];

const handleCardKeydown = (e) => {
  const current = modeOptions.indexOf(draftMode.value);
  let next = -1;
  if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
    next = (current + 1) % modeOptions.length;
  } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
    next = (current - 1 + modeOptions.length) % modeOptions.length;
  } else if (e.key === ' ' || e.key === 'Enter') {
    e.preventDefault();
    return;
  } else {
    return;
  }
  e.preventDefault();
  draftMode.value = modeOptions[next];
  e.currentTarget.closest('[role="radiogroup"]')
      ?.querySelectorAll('[role="radio"]')[next]
      ?.focus();
};
</script>

<template>
  <div class="fct-subscription-mode-manager">
    <div class="flex items-start justify-between gap-4 rounded border border-gray-divider bg-gray-25 p-4 dark:border-slate-700 dark:bg-slate-800">
      <div>
        <div class="flex items-center gap-2">
          <Badge :type="statusBadge.type" :text="statusBadge.label"/>
        </div>
        <p class="mt-2 text-sm text-system-mid dark:text-slate-300">{{ statusDescription }}</p>

        <p v-if="!isStoreManaged && manualFallbackOn" class="mt-1 text-xs text-system-dark-light dark:text-slate-400">
          {{ fallbackRenewalHint }}
        </p>

        <ul v-if="isStoreManaged && scheduleItems.length" class="mt-2 pl-4 text-xs list-disc text-system-dark-light dark:text-slate-400">
          <li class="-ml-4 mb-1 list-none font-medium text-system-mid dark:text-slate-300">{{ translate('Renewal invoices created') }}:</li>
          <li v-for="item in scheduleItems" :key="item.label" class="mb-0.5">
            <strong>{{ item.label }}:</strong> {{ item.when }}
          </li>
        </ul>
      </div>

      <el-button plain size="small" @click="openChangeFlow">
        {{ translate('Change') }}
      </el-button>
    </div>

    <el-dialog
        v-model="dialogVisible"
        :title="translate('Renewal Billing')"
        width="560px"
        append-to-body
        :before-close="handleBeforeClose"
        class="fct-subscription-mode-dialog"
    >
      <div class="fct-subscription-mode-cards" role="radiogroup" :aria-label="translate('Renewal Billing')">
        <!-- Gateway Billing -->
        <div
            class="fct-subscription-mode-card"
            :class="{ 'is-selected': draftMode === 'gateway_managed' }"
            role="radio"
            :aria-checked="draftMode === 'gateway_managed'"
            :tabindex="draftMode === 'gateway_managed' ? 0 : -1"
            @click="draftMode = 'gateway_managed'"
            @keydown="handleCardKeydown"
        >
            <div class="fct-subscription-mode-card__header">
              <span class="fct-subscription-mode-card__radio" />
              <span class="fct-subscription-mode-card__title">{{ translate('Gateway Billing') }}</span>
              <span class="fct-subscription-mode-recommended">{{ translate('Recommended') }}</span>
            </div>
            <p class="fct-subscription-mode-card__desc">
              {{ translate('Stripe, PayPal, and other subscription-ready gateways charge customers automatically each cycle.') }}
            </p>

            <TransitionAccordion :visible="draftMode === 'gateway_managed'">
              <div class="fct-subscription-mode-card__extras">
                <el-checkbox v-model="draftManualFallback">
                  {{ translate('Allow all payment methods') }}
                </el-checkbox>
                <p class="fct-subscription-mode-note">
                  {{ translate('Gateways without built-in recurring billing (SSLCommerz, Authorize.Net, bank transfer, etc.) can still sell subscriptions. Their renewals are invoiced by email before each due date.') }}
                </p>
              </div>
            </TransitionAccordion>
        </div>

        <!-- Store Billing -->
        <div
            class="fct-subscription-mode-card"
            :class="{ 'is-selected': draftMode === 'store_managed' }"
            role="radio"
            :aria-checked="draftMode === 'store_managed'"
            :tabindex="draftMode === 'store_managed' ? 0 : -1"
            @click="draftMode = 'store_managed'"
            @keydown="handleCardKeydown"
        >
            <div class="fct-subscription-mode-card__header">
              <span class="fct-subscription-mode-card__radio" />
              <span class="fct-subscription-mode-card__title">{{ translate('Store Billing') }}</span>
            </div>
            <p class="fct-subscription-mode-card__desc">
              {{ translate('Your store creates a renewal invoice before each due date. Customers pay via a link in the email.') }}
            </p>

            <TransitionAccordion :visible="draftMode === 'store_managed'">
              <div class="fct-subscription-mode-card__extras">
                <el-checkbox v-model="draftSystemCharge">
                  {{ translate('Auto-charge saved payment methods') }}
                </el-checkbox>
                <p class="fct-subscription-mode-note">
                  {{ translate('When supported, the customer\'s saved payment method is charged automatically on each renewal due date.') }}
                </p>
                <p
                    v-if="systemChargeSupportNote"
                    class="fct-subscription-mode-support"
                >
                  {{ systemChargeSupportNote }}
                </p>
              </div>

              <div v-if="scheduleItems.length" class="fct-subscription-mode-card__schedule-link">
                <el-tooltip placement="bottom-start" effect="dark" popper-class="fct-tooltip fct-schedule-tooltip">
                  <template #content>
                    <p class="fct-schedule-tooltip__title">{{ translate('When are renewal orders created?') }}</p>
                    <ul class="fct-schedule-tooltip__list">
                      <li v-for="item in scheduleItems" :key="item.label">
                        <strong>{{ item.label }}:</strong> {{ item.when }}
                      </li>
                    </ul>
                  </template>
                  <span class="fct-subscription-mode-card__schedule-trigger">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                      <path d="M8 5v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                      <circle cx="8" cy="11" r=".75" fill="currentColor"/>
                    </svg>
                    {{ translate('When are renewal orders created?') }}
                  </span>
                </el-tooltip>
              </div>
            </TransitionAccordion>
        </div>
      </div>

      <div class="fct-subscription-mode-safety-note">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
          <path d="M8 5v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          <circle cx="8" cy="11" r=".75" fill="currentColor"/>
        </svg>
        <span>{{ translate('Only affects new subscriptions. Existing ones keep their current billing method.') }}</span>
      </div>

      <template #footer>
        <el-button :disabled="applying" @click="dialogVisible = false">{{ translate('Cancel') }}</el-button>
        <el-button type="primary" :loading="applying" @click="applyDraft">{{ translate('Apply') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>
