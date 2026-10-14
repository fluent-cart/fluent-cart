<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate, {pluralizeTranslate} from "@/utils/translator/Translator";
import {computed, h, markRaw, nextTick, onBeforeUnmount, onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import SettingsHeader from "./Parts/SettingsHeader.vue";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
import McIcon from "./MultiCurrency/McIcon.vue";
import {shopperRate, toPrice} from "@/utils/support/CurrencyPricing";
import {rateChange as historyRateChange, useRateHistory} from "./MultiCurrency/rateHistory";
import {dateTimeI18} from "@/utils/Utils";
import Clipboard from "@/utils/Clipboard";
import Animation from "@/Bits/Components/Animation.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Badge from "@/Bits/Components/Badge.vue";
const SWITCHER_SHORTCODE = '[fluent_cart_currency_switcher]';
// Pro's CurrencyDropdown turns any element with this class into the switcher
const SWITCHER_MENU_CLASS = 'fct-currency-switcher-toggle';
const MULTI_CURRENCY_DOCS_URL = 'https://docs.fluentcart.com/guide/settings-configuration/multi-currency-settings';
const SUPPORT_URL = 'https://fluentcart.com/account/support/';
// Split on the placeholder so the link renders without v-html while translators keep the whole sentence
/* translators: %1$s: "our support team" link */
const betaNotice = translate('Multi-currency is in beta. Test it on a staging site before relying on it in your live store, and report any issues you find to %1$s.').split('%1$s');

const loading = ref(true);
// Saving before a successful load would send the defaults below and switch
// multi-currency off with no currencies
const loaded = ref(false);
const loadFailed = ref(false);
const saving = ref(false);
const settings = ref({enabled: 'no', currencies: [], rate_lock_minutes: 0, rate_provider: 'manual', rate_margin: 0});
// The saved hold, not the unsaved counter, decides when a new rate reaches carts
const savedLockMinutes = ref(0);
const now = ref(Date.now());
let nowTimer = null;
const baseCurrency = ref('');
const baseCurrencyLocked = ref(false);
const availableCurrencies = ref([]);
const roundingOptions = ref({});
const charmOptions = ref({});
const rateProviders = ref({});
const rateProviderNotes = ref({});
const isAutomated = computed(() => settings.value.rate_provider && settings.value.rate_provider !== 'manual');
const hasStaleRate = computed(() => Object.values(rates.value).some(rate => rate && rate.is_stale));
const rates = ref({});
const rateDrafts = ref({});
const savingRate = ref('');
const editingRate = ref('');
const newCurrency = ref('');
const justAdded = ref('');
const currencyTable = ref(null);
let justAddedTimer = null;
// Element Plus takes the select arrow as a component
const ArrowDownIcon = markRaw({render: () => h(McIcon, {name: 'arrowDown'})});
const {history, showHistory} = useRateHistory(
    (code) => Rest.get('settings/multi-currency/rates', {currency: code}),
    (errors) => Notify.error(errors)
);

const saveShortcut = useSaveShortcut();
saveShortcut.onSave(() => saveSettings());

const addableCurrencies = computed(() => {
  const enabled = settings.value.currencies.map(row => row.code);
  return availableCurrencies.value.filter(currency => !enabled.includes(currency.code));
});

const currencyName = (code) => availableCurrencies.value.find(currency => currency.code === code)?.label || '';

const applyPayload = (payload) => {
  settings.value = payload.settings;
  savedLockMinutes.value = Number(payload.settings?.rate_lock_minutes) || 0;
  baseCurrency.value = payload.base_currency;
  baseCurrencyLocked.value = payload.base_currency_locked;
  availableCurrencies.value = payload.available_currencies || [];
  roundingOptions.value = payload.rounding_options || {};
  charmOptions.value = payload.charm_options || {};
  rateProviders.value = payload.rate_providers || {};
  rateProviderNotes.value = payload.rate_provider_notes || {};
  rates.value = payload.rates || {};
};

const fetchSettings = () => {
  loading.value = true;
  loadFailed.value = false;
  Rest.get('settings/multi-currency')
      .then(response => {
        applyPayload(response);
        loaded.value = true;
      })
      .catch(errors => {
        loadFailed.value = !loaded.value;
        Notify.error(errors);
      })
      .finally(() => {
        loading.value = false;
      });
};

// Resolves true once the settings are stored, so callers can act only on success
const saveSettings = ({notify = true} = {}) => {
  if (!loaded.value || saving.value) {
    return Promise.resolve(false);
  }
  saving.value = true;
  return Rest.post('settings/multi-currency', {
    enabled: settings.value.enabled,
    currencies: settings.value.currencies,
    rate_lock_minutes: settings.value.rate_lock_minutes,
    rate_provider: settings.value.rate_provider,
    rate_margin: settings.value.rate_margin
  })
      .then(response => {
        applyPayload(response);
        if (notify) {
          Notify.success(response.message);
        }
        return true;
      })
      .catch(errors => {
        Notify.error(errors);
        return false;
      })
      .finally(() => {
        saving.value = false;
      });
};

const savePricing = (code) => {
  saveSettings().then(saved => {
    if (saved && openPricing.value === code) {
      openPricing.value = '';
    }
  });
};

const addCurrency = (code = newCurrency.value) => {
  if (!code || settings.value.currencies.some(row => row.code === code)) {
    return;
  }
  settings.value.currencies.push({code, rounding: 'none', charm: 'none', adjustment: 0});
  newCurrency.value = '';

  // The picker sits above the table, so bring the new row into view and put the caret in its rate field
  justAdded.value = code;
  nextTick(() => {
    currencyTable.value?.querySelector('.is-just-added')?.scrollIntoView({behavior: 'smooth', block: 'center'});
    rateInput(code)?.focus({preventScroll: true});
  });
  clearTimeout(justAddedTimer);
  justAddedTimer = setTimeout(() => {
    justAdded.value = '';
  }, 2500);
};

const removeCurrency = (code) => {
  settings.value.currencies = settings.value.currencies.filter(row => row.code !== code);
  if (openPricing.value === code) {
    openPricing.value = '';
  }
};

const rateInput = (code) => currencyTable.value?.querySelector(`[data-rate-input="${code}"] input`);

const openRateEdit = (code) => {
  editingRate.value = code;
  nextTick(() => {
    rateInput(code)?.focus();
  });
};

const openPricing = ref('');
const togglePricing = (code) => {
  openPricing.value = openPricing.value === code ? '' : code;
};

const inverseRate = (rate) => {
  const value = parseFloat(rate);
  return value > 0 ? trimRate((1 / value).toFixed(4)) : '';
};

const pricingSummary = (row) => {
  const adjustment = parseFloat(row.adjustment) || 0;
  return [
    {label: translate('Adjustment'), value: `${adjustment > 0 ? '+' : ''}${adjustment}%`},
    // "Round up to 1" reads as "Rounding Up to 1" after the label
    {label: translate('Rounding'), value: row.rounding === 'none' ? translate('None') : (roundingOptions.value[row.rounding] || row.rounding).replace(/^Round\s+(\w)/, (match, first) => first.toUpperCase())},
    // Charm labels end with "(take off 0.01)"; the summary only needs "End in .99"
    {label: translate('Charm'), value: row.charm === 'none' ? translate('None') : (charmOptions.value[row.charm] || row.charm).replace(/\s*\(.*\)$/, '')}
  ];
};


const copyText = (text) => Clipboard.copy(text);

const closeRateEdit = (code) => {
  rateDrafts.value = {...rateDrafts.value, [code]: ''};
  editingRate.value = '';
};

const saveRate = (code) => {
  const rate = rateDrafts.value[code];
  if (!rate) {
    return;
  }
  savingRate.value = code;
  Rest.post('settings/multi-currency/rates', {currency: code, rate: String(rate)})
      .then(response => {
        rates.value = {...rates.value, [code]: response.rate};
        rateDrafts.value = {...rateDrafts.value, [code]: ''};
        editingRate.value = '';
        now.value = Date.now();
        // Store the currency list too, so a newly added currency is not lost with its rate
        return saveSettings({notify: false}).then(saved => {
          if (saved) {
            Notify.success(response.message);
            return;
          }
          // The rate is stored but the currency list is not; the row stays on the page for Save Settings
          Notify.notify(
              translate('The rate is saved, but %1$s is not added to your currencies yet. Click Save Settings to keep it.', code),
              translate('Warning'),
              'warning'
          );
        });
      })
      .catch(errors => Notify.error(errors))
      .finally(() => {
        savingRate.value = '';
      });
};

const rateChange = (code) => {
  const current = rates.value[code];
  const draft = parseFloat(rateDrafts.value[code]);
  if (!current || !draft) {
    return '';
  }
  const change = ((draft - parseFloat(current.rate)) / parseFloat(current.rate)) * 100;
  return `${change > 0 ? '+' : ''}${change.toFixed(2)}%`;
};

const trimRate = (rate) => String(rate).replace(/\.?0+$/, '');

// History rows are newest first, so each row compares with the next one
const historyChange = (index) => historyRateChange(history.value.rates[index]?.rate, history.value.rates[index + 1]?.rate);
// Provider names are setting labels; a manual rate reads better as just Manual
const historySource = (source) => (source === 'manual' ? translate('Manual') : (rateProviders.value[source] || source));
const isActiveRate = (row) => rates.value[history.value.currency]?.id === row.id;
// Rows scroll under a fixed header; the cap shrinks so the dialog fits short screens
const historyTableHeight = () => Math.max(240, Math.min(420, window.innerHeight - 260));

// Minutes a cart priced before this rate may still hold the previous one
const heldMinutesLeft = (code) => {
  const rate = rates.value[code];
  if (!rate || !rate.effective_at || savedLockMinutes.value <= 0) {
    return 0;
  }
  const savedAt = Date.parse(String(rate.effective_at).replace(' ', 'T') + 'Z');
  if (Number.isNaN(savedAt)) {
    return 0;
  }
  const left = savedAt + savedLockMinutes.value * 60000 - now.value;
  return left > 0 ? Math.ceil(left / 60000) : 0;
};

// Every row previews the same 100.00 base-currency price
const previewBaseCents = computed(() => 100 * 100);

const formatCents = (cents, decimals) => {
  const digits = decimals === 0 ? 0 : 2;
  return (cents / 100).toLocaleString(undefined, {minimumFractionDigits: digits, maximumFractionDigits: digits});
};

// The example price under the row's rules, at the rate shoppers get
// (store margin included)
const previewPrice = (row) => {
  const rate = shopperRate(rates.value[row.code], settings.value);
  if (!rate) {
    return null;
  }
  const decimals = availableCurrencies.value.find(currency => currency.code === row.code)?.decimals ?? 2;
  return {
    base: formatCents(previewBaseCents.value, 2),
    rate: trimRate(rate.toFixed(6)),
    converted: formatCents(Math.round(previewBaseCents.value * rate), decimals),
    pays: formatCents(toPrice(previewBaseCents.value, {...row, rate, decimals}), decimals)
  };
};

const previews = computed(() => Object.fromEntries(settings.value.currencies.map(row => [row.code, previewPrice(row)])));

const rateNote = computed(() => {
  if (rateProviderNotes.value[settings.value.rate_provider]) {
    return rateProviderNotes.value[settings.value.rate_provider];
  }
  return isAutomated.value ? '' : translate('Edit each rate in the Currencies table below. Rates only change when you edit them.');
});

onMounted(() => {
  fetchSettings();
  nowTimer = setInterval(() => {
    now.value = Date.now();
  }, 30000);
});
onBeforeUnmount(() => {
  clearTimeout(justAddedTimer);
  clearInterval(nowTimer);
});
</script>

<template>
  <div class="setting-wrap">
    <SettingsHeader
        :loading="saving"
        :show-save-button="loaded"
        @on-save="saveSettings"
    >
      <template #heading>
        <h3 class="fct-setting-head-title flex items-center gap-2">
          {{ translate('Multi Currency') }}
          <Badge size="small" status="beta"/>
        </h3>
      </template>
    </SettingsHeader>

    <div class="setting-wrap-inner">
      <Card.Container v-if="loading">
        <Card.Body>
          <el-skeleton animated :rows="6"/>
        </Card.Body>
      </Card.Container>

      <Card.Container v-else-if="loadFailed">
        <Card.Body>
          <div class="flex items-center justify-between gap-4">
            <p class="m-0 text-sm">
              {{ translate('The multi-currency settings could not be loaded, so they cannot be changed right now.') }}
            </p>
            <el-button size="small" @click="fetchSettings">{{ translate('Retry') }}</el-button>
          </div>
        </Card.Body>
      </Card.Container>

      <div v-else class="fct-mc-settings">
        <el-alert class="fct-mc-beta-notice mb-5" type="info" :closable="false" show-icon>
          <template #title>
            {{ betaNotice[0] }}<a :href="SUPPORT_URL" target="_blank" rel="noopener noreferrer" class="fct-text-link">{{ translate('our support team') }}</a>{{ betaNotice[1] }}
          </template>
        </el-alert>

        <Card.Container>
          <Card.Header :title="translate('Sell in more than one currency')" border_bottom/>
          <Card.Body class="fct-mc-body">
            <div class="fct-mc-toggle-row">
              <el-switch v-model="settings.enabled" active-value="yes" inactive-value="no" :aria-label="translate('Enable multi-currency')"/>
              <div class="fct-mc-field-text gap-0.5">
                <span class="fct-mc-field-label">{{ translate('Enable multi-currency') }}</span>
                <span class="fct-mc-field-hint">
                  {{ translate('Prices are kept in the base currency and converted at the rate you set. Each order keeps the rate it was placed at, and renewals keep it too.') }}
                </span>
                <Animation :visible="settings.enabled === 'yes'" accordion>
                  <div class="fct-mc-switcher-line">
                    <span>{{ translate('Shoppers switch currency with the currency switcher. Add it to your header, footer or shop page with') }}</span>
                    <button type="button" class="fct-mc-shortcode" :title="translate('Copy')" @click="copyText(SWITCHER_SHORTCODE)">
                      <McIcon name="copy"/>
                      <code>{{ SWITCHER_SHORTCODE }}</code>
                    </button>
                    <span>{{ translate('or the Currency Switcher block. For a navigation menu, add the class') }}</span>
                    <button type="button" class="fct-mc-shortcode" :title="translate('Copy')" @click="copyText(SWITCHER_MENU_CLASS)">
                      <McIcon name="copy"/>
                      <code>{{ SWITCHER_MENU_CLASS }}</code>
                    </button>
                    <a :href="MULTI_CURRENCY_DOCS_URL" target="_blank" rel="noopener noreferrer" class="fct-text-link">
                      {{ translate('See documentation') }}
                    </a>
                  </div>
                </Animation>
              </div>
            </div>
            <Animation :visible="settings.enabled !== 'yes'" accordion>
              <div class="fct-mc-note">
                <McIcon name="info"/>
                <span>{{ translate('Multi-currency is off. Shoppers only see %1$s. Turn it on to add currencies and set their rates.', baseCurrency) }}</span>
              </div>
            </Animation>
            <div class="fct-mc-divider"></div>
            <div class="fct-mc-base-currency">
              <div class="flex flex-wrap items-center gap-2">
                <span class="fct-mc-field-label">{{ translate('Base currency') }}</span>
                <span class="fct-mc-badge" :title="currencyName(baseCurrency)">{{ baseCurrency }}</span>
                <router-link v-if="!baseCurrencyLocked" :to="{name: 'store_setup'}" class="fct-text-link">
                  {{ translate('Change in Store Settings') }}
                </router-link>
              </div>
              <span v-if="baseCurrencyLocked" class="fct-mc-field-hint">
                {{ translate('Locked while multi-currency is enabled and orders exist.') }}
              </span>
            </div>
          </Card.Body>
        </Card.Container>

        <!-- Currencies, rates and checkout only matter once multi-currency is on -->
        <Animation :visible="settings.enabled === 'yes'" accordion>
          <!-- Hidden for manual rates; a store already on a rate feed keeps it so it can switch back -->
          <Animation :visible="isAutomated" accordion>
            <Card.Container>
              <Card.Header :title="translate('Exchange rates')" border_bottom/>
              <Card.Body class="fct-mc-body">
                <div class="fct-mc-setting-row">
                  <div class="fct-mc-field-text gap-1">
                    <span class="fct-mc-field-label">{{ translate('Exchange rates from') }}</span>
                    <span class="fct-mc-field-hint">{{ translate('Where the rate for each currency comes from.') }}</span>
                  </div>
                  <el-select
                      v-model="settings.rate_provider"
                      class="fct-mc-provider-select"
                      :suffix-icon="ArrowDownIcon"
                      :aria-label="translate('Exchange rates from')"
                  >
                    <el-option v-for="(label, key) in rateProviders" :key="key" :label="label" :value="key"/>
                  </el-select>
                </div>
                <div v-if="rateNote" class="fct-mc-note">
                  <McIcon name="info"/>
                  <span>{{ rateNote }}</span>
                </div>
                <div v-if="isAutomated" class="fct-mc-inline-row">
                  <span>{{ translate('Add a margin of') }}</span>
                  <el-input-number v-model="settings.rate_margin" :min="0" :max="10" :step="0.5" :precision="2" class="fct-mc-counter" :aria-label="translate('Add a margin of')">
                    <template #decrease-icon><McIcon name="subtract"/></template>
                    <template #increase-icon><McIcon name="add"/></template>
                  </el-input-number>
                  <span>{{ translate('% to fetched rates, to cover the spread your payment gateway charges on conversion.') }}</span>
                </div>
                <el-alert
                    v-if="isAutomated && hasStaleRate"
                    type="warning"
                    :closable="false"
                    :title="translate('Some rates have not updated for more than 3 days. Prices keep using the last rate until the next update.')"
                />
              </Card.Body>
            </Card.Container>
          </Animation>

          <Card.Container>
            <Card.Header border_bottom>
              <template #title>
                <div class="flex items-center gap-2">
                  <h2 class="fct-card-header-title">{{ translate('Currencies') }}</h2>
                  <span class="fct-mc-badge">{{ settings.currencies.length }}</span>
                </div>
              </template>
              <template #action>
                <el-select
                    v-model="newCurrency"
                    filterable
                    default-first-option
                    size="small"
                    :placeholder="translate('Add currencies')"
                    :aria-label="translate('Add currencies')"
                    :suffix-icon="ArrowDownIcon"
                    class="fct-mc-add-currency"
                    @change="addCurrency"
                >
                  <template #prefix>
                    <McIcon name="add"/>
                  </template>
                  <el-option
                      v-for="currency in addableCurrencies"
                      :key="currency.code"
                      :label="`${currency.code} — ${currency.label}`"
                      :value="currency.code"
                  />
                </el-select>
              </template>
            </Card.Header>
            <Card.Body class="p-0">

              <div ref="currencyTable" class="fct-mc-table" role="table" :aria-label="translate('Currencies')">
                <div v-if="settings.currencies.length" class="fct-mc-table-head" role="row">
                  <span class="fct-mc-col-currency" role="columnheader">{{ translate('Currency') }}</span>
                  <span class="fct-mc-col-rate" role="columnheader">{{ translate('Rate for 1 %1$s', baseCurrency) }}</span>
                  <span class="fct-mc-col-pricing" role="columnheader">{{ translate('Pricing') }}</span>
                  <span class="fct-mc-col-actions" role="columnheader"><span class="sr-only">{{ translate('Actions') }}</span></span>
                </div>

                <div v-if="!settings.currencies.length" class="fct-mc-table-empty">
                  {{ translate('No additional currencies yet.') }}
                </div>

                <div
                    v-for="row in settings.currencies"
                    :key="row.code"
                    class="fct-mc-table-row"
                    :class="{'is-just-added': row.code === justAdded, 'is-needs-rate': !rates[row.code]}"
                    role="row"
                >
                  <div class="fct-mc-table-cells">
                    <div class="fct-mc-col-currency" role="cell">
                      <span class="fct-mc-code">{{ row.code }}</span>
                      <span class="fct-mc-currency-name">{{ currencyName(row.code) }}</span>
                    </div>

                    <!-- No rate yet: one strip across Rate and Pricing that asks only for the rate -->
                    <div v-if="!rates[row.code]" class="fct-mc-needs-rate" role="cell">
                      <div class="fct-mc-needs-rate-line">
                        <span class="badge warning">{{ translate('Needs a rate') }}</span>
                        <span class="fct-mc-field-hint">{{ translate('Shoppers won\'t see %1$s until it has a rate.', row.code) }}</span>
                      </div>
                      <div class="fct-mc-needs-rate-input">
                        <span class="fct-mc-pricing-unit">{{ translate('1 %1$s =', baseCurrency) }}</span>
                        <el-input
                            v-model="rateDrafts[row.code]"
                            size="small"
                            class="fct-mc-rate-input"
                            :data-rate-input="row.code"
                            :placeholder="translate('Rate')"
                            :aria-label="translate('Rate for 1 %1$s in %2$s', baseCurrency, row.code)"
                            @keyup.enter="saveRate(row.code)"
                        >
                          <template #append>{{ row.code }}</template>
                        </el-input>
                        <el-button size="small" type="primary" :loading="savingRate === row.code" @click="saveRate(row.code)">
                          {{ translate('Set rate') }}
                        </el-button>
                        <span v-if="inverseRate(rateDrafts[row.code])" class="fct-mc-currency-name">
                          {{ translate('1 %1$s = %2$s %3$s', row.code, inverseRate(rateDrafts[row.code]), baseCurrency) }}
                        </span>
                      </div>
                    </div>

                    <template v-else>
                      <div class="fct-mc-col-rate" role="cell">
                        <div class="fct-mc-rate">
                          <span class="fct-mc-rate-value">{{ trimRate(rates[row.code].rate) }}</span>
                          <span class="fct-mc-rate-code">{{ row.code }}</span>
                          <el-tag v-if="rates[row.code].is_stale" type="warning" size="small">{{ translate('Stale') }}</el-tag>
                          <button
                              v-if="editingRate !== row.code"
                              type="button"
                              class="fct-mc-compact-button"
                              :title="translate('Edit rate')"
                              :aria-label="translate('Edit rate')"
                              @click="openRateEdit(row.code)"
                          >
                            <McIcon name="edit"/>
                          </button>
                        </div>
                        <span v-if="editingRate !== row.code" class="fct-mc-currency-name">
                          {{ translate('1 %1$s = %2$s %3$s', row.code, inverseRate(rates[row.code].rate), baseCurrency) }}
                        </span>

                        <Animation :visible="editingRate === row.code" accordion>
                          <div class="fct-mc-rate-edit">
                            <el-input
                                v-model="rateDrafts[row.code]"
                                size="small"
                                class="fct-mc-rate-input"
                                :data-rate-input="row.code"
                                :placeholder="translate('New rate')"
                                :aria-label="translate('New rate')"
                                @keyup.enter="saveRate(row.code)"
                                @keyup.esc="closeRateEdit(row.code)"
                            >
                              <template #append>{{ row.code }}</template>
                            </el-input>
                            <div class="flex items-center gap-2">
                              <el-button size="small" type="primary" :loading="savingRate === row.code" @click="saveRate(row.code)">
                                {{ translate('Set rate') }}
                              </el-button>
                              <el-button size="small" text class="!ml-0" @click="closeRateEdit(row.code)">{{ translate('Cancel') }}</el-button>
                            </div>
                            <span v-if="rateChange(row.code)" class="fct-mc-currency-name">{{ rateChange(row.code) }}</span>
                          </div>
                        </Animation>
                      </div>

                      <div class="fct-mc-col-pricing" role="cell">
                        <div v-if="previews[row.code]" class="fct-mc-example">
                          <span>{{ translate('Example') }}</span>
                          <span class="fct-mc-example-base">{{ previews[row.code].base }} {{ baseCurrency }}</span>
                          <span aria-hidden="true">→</span>
                          <span class="fct-mc-example-price">{{ previews[row.code].pays }} {{ row.code }}</span>
                        </div>
                        <div class="fct-mc-pricing-meta">
                          <span class="fct-mc-currency-name">
                            {{ pricingSummary(row).map(rule => `${rule.label} ${rule.value}`).join(' · ') }}
                          </span>
                          <button
                              type="button"
                              class="fct-mc-compact-button"
                              :class="{'is-active': openPricing === row.code}"
                              :title="translate('Adjust pricing')"
                              :aria-label="translate('Adjust pricing for %1$s', row.code)"
                              :aria-expanded="openPricing === row.code ? 'true' : 'false'"
                              @click="togglePricing(row.code)"
                          >
                            <McIcon name="edit"/>
                          </button>
                        </div>
                      </div>
                    </template>

                    <div class="fct-mc-col-actions" role="cell">
                      <button v-if="rates[row.code]" type="button" class="fct-mc-icon-button" :title="translate('Rate history')" :aria-label="translate('Rate history')" @click="showHistory(row.code)">
                        <McIcon name="history"/>
                      </button>
                      <el-popconfirm
                          :title="translate('Remove %1$s? Shoppers can no longer pick it. Existing orders keep their currency. This applies when you save.', row.code)"
                          :confirm-button-text="translate('Remove')"
                          :cancel-button-text="translate('Cancel')"
                          width="280"
                          popper-class="fct-delete-confirm-popover fct-mc-remove-popover"
                          @confirm="removeCurrency(row.code)"
                      >
                        <template #reference>
                          <button type="button" class="fct-mc-icon-button" :title="translate('Remove')" :aria-label="translate('Remove %1$s', row.code)">
                            <McIcon name="delete"/>
                          </button>
                        </template>
                      </el-popconfirm>
                    </div>
                  </div>

                  <!-- Spans the Rate and Pricing columns so the notice stays on one line -->
                  <Animation :visible="Boolean(rates[row.code]) && editingRate !== row.code && heldMinutesLeft(row.code) > 0" accordion>
                    <p class="fct-mc-rate-hold-note" role="status">
                      <McIcon name="info"/>
                      <span>{{ pluralizeTranslate('Converted prices may still use the previous rate for up to %1$s more minute.', 'Converted prices may still use the previous rate for up to %1$s more minutes.', heldMinutesLeft(row.code)) }}</span>
                    </p>
                  </Animation>

                  <Animation :visible="Boolean(rates[row.code]) && openPricing === row.code" accordion>
                    <div class="fct-mc-pricing-panel">
                      <div class="fct-mc-pricing-panel-head">
                        <div class="fct-mc-field-text gap-0.5">
                          <span class="fct-mc-field-label">{{ translate('Pricing rules for %1$s', row.code) }}</span>
                          <span class="fct-mc-field-hint">{{ translate('Applied to every %1$s price. The example above updates as you change them.', row.code) }}</span>
                        </div>
                        <el-button size="small" :loading="saving" @click="savePricing(row.code)">{{ translate('Save') }}</el-button>
                      </div>
                      <div class="fct-mc-pricing-panel-grid">
                        <div class="fct-mc-pricing-field">
                          <LabelHint
                              :title="translate('Price adjustment')"
                              :content="translate('Raises or lowers every %1$s price by this percentage after conversion. Use a negative number for a discount.', row.code)"
                              placement="right"
                          />
                          <div class="flex items-center gap-2">
                            <el-input-number v-model="row.adjustment" :min="-50" :max="100" :step="1" :precision="2" class="fct-mc-counter" :aria-label="translate('Price adjustment')">
                              <template #decrease-icon><McIcon name="subtract"/></template>
                              <template #increase-icon><McIcon name="add"/></template>
                            </el-input-number>
                            <span class="fct-mc-pricing-unit">{{ translate('% on price') }}</span>
                          </div>
                        </div>
                        <div class="fct-mc-pricing-field">
                          <LabelHint
                              :title="translate('Rounding')"
                              :content="translate('Rounds the converted price up to the step you pick. For example, Round up to 1 turns 91.20 into 92.00.')"
                              placement="right"
                          />
                          <el-select v-model="row.rounding" size="small" :suffix-icon="ArrowDownIcon" class="fct-mc-select" :aria-label="translate('Rounding')">
                            <el-option v-for="(label, key) in roundingOptions" :key="key" :label="key === 'none' ? translate('No rounding') : label" :value="key"/>
                          </el-select>
                        </div>
                        <div class="fct-mc-pricing-field">
                          <LabelHint
                              :title="translate('Charm pricing')"
                              :content="translate('Makes prices end in .99 or .95, which shoppers tend to see as a better deal. It takes 0.01 or 0.05 off the rounded price, so pair it with a whole-number rounding step. For example, 92.00 becomes 91.99.')"
                              placement="right"
                          />
                          <el-select v-model="row.charm" size="small" :suffix-icon="ArrowDownIcon" class="fct-mc-select" :aria-label="translate('Charm pricing')">
                            <el-option v-for="(label, key) in charmOptions" :key="key" :label="label" :value="key"/>
                          </el-select>
                        </div>
                      </div>
                    </div>
                  </Animation>
                </div>
              </div>
            </Card.Body>
          </Card.Container>

          <Card.Container>
            <Card.Header :title="translate('Rate changes')" border_bottom/>
            <Card.Body class="fct-mc-body fct-mc-checkout">
              <span class="fct-mc-field-hint">{{ translate('Keep converted prices steady while customers shop and check out') }}</span>
              <div class="fct-mc-inline-row">
                <span>{{ translate('Keep converted prices at the previous rate for up to') }}</span>
                <el-input-number v-model="settings.rate_lock_minutes" :min="0" :max="1440" class="fct-mc-counter" :aria-label="translate('Minutes converted prices keep the previous rate')">
                  <template #decrease-icon><McIcon name="subtract"/></template>
                  <template #increase-icon><McIcon name="add"/></template>
                </el-input-number>
                <span>{{ translate('minutes after a rate change. Set 0 to use a new rate at once.') }}</span>
              </div>
            </Card.Body>
          </Card.Container>
        </Animation>

        <div class="setting-save-action">
          <el-button type="primary" @click="saveSettings" :loading="saving">
            {{ translate('Save Settings') }}
          </el-button>
        </div>
      </div>
    </div>

    <el-dialog v-model="history.visible" :title="translate('Rate history') + ' — ' + history.currency" width="720px" class="fct-mc-history-dialog" append-to-body>
      <p class="fct-mc-history-intro">{{ translate('Rate for 1 %1$s, newest first', baseCurrency) }}</p>
      <el-skeleton v-if="history.loading" animated class="fct-mc-history-skeleton">
        <template #template>
          <el-skeleton-item class="fct-mc-history-skeleton-head"/>
          <div v-for="i in 3" :key="i" class="fct-mc-history-skeleton-row">
            <el-skeleton-item class="w-1/4 h-4"/>
            <el-skeleton-item class="w-1/6 h-4"/>
            <el-skeleton-item class="w-1/6 h-4"/>
            <el-skeleton-item class="w-1/5 h-4"/>
            <el-skeleton-item class="w-1/4 h-4"/>
          </div>
        </template>
      </el-skeleton>
      <el-table v-else :data="history.rates" class="fct-mc-history-table" :max-height="historyTableHeight()" :empty-text="translate('No rates yet')">
        <el-table-column :label="translate('Rate')" min-width="150">
          <template #default="{row}">
            <div class="fct-mc-history-rate">
              <span class="fct-mc-history-rate-value">{{ trimRate(row.rate) }}</span>
              <span class="fct-mc-rate-code">{{ history.currency }}</span>
              <span v-if="isActiveRate(row)" class="badge success small">{{ translate('Current') }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column :label="translate('Change')" width="120">
          <template #default="{$index}">
            <span v-if="historyChange($index)" class="fct-mc-history-change" :class="'is-' + historyChange($index).direction">
              {{ historyChange($index).direction === 'same' ? translate('No change') : historyChange($index).percent }}
            </span>
            <span v-else class="fct-mc-history-muted">—</span>
          </template>
        </el-table-column>
        <el-table-column :label="translate('Source')" width="120">
          <template #default="{row}">
            <span class="badge info small fct-mc-history-source" :title="historySource(row.source)">{{ historySource(row.source) }}</span>
          </template>
        </el-table-column>
        <el-table-column :label="translate('Effective')" width="140">
          <template #default="{row}">
            <span class="fct-mc-history-date">{{ dateTimeI18(row.effective_at, 'date') }}</span>
            <span class="fct-mc-history-muted">{{ dateTimeI18(row.effective_at, 'time') }}</span>
          </template>
        </el-table-column>
        <el-table-column :label="translate('Set by')" min-width="150" show-overflow-tooltip>
          <template #default="{row}">
            <span v-if="row.created_by">{{ row.created_by }}</span>
            <span v-else class="fct-mc-history-muted">{{ translate('Automatic') }}</span>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>
