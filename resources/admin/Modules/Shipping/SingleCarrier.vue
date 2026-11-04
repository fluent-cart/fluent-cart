<template>
  <div class="setting-wrap">
    <SaveBar
        v-if="hasFields"
        :is-active="dirty ? 'is-active' : ''"
        :loading="saving"
        :show-cmnd-icon="true"
        @save="saveSettings"
        @discard="discard"
    />

    <SettingsHeader :show-save-button="false">
      <template #heading>
        <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
          <el-breadcrumb-item :to="{ name: 'shipping_carriers' }">
            {{ translate('Carriers') }}
          </el-breadcrumb-item>
          <el-breadcrumb-item v-if="carrierName">{{ carrierName }}</el-breadcrumb-item>
        </el-breadcrumb>
      </template>

      <template #action>
        <span v-if="savedFlash && !dirty" class="fct-carrier-tag is-ok">{{ translate('Saved') }}</span>
        <el-button
            v-if="hasFields"
            type="primary"
            size="small"
            :loading="saving"
            :disabled="saving || !dirty"
            @click="saveSettings"
        >
          <span v-if="!saving" class="cmd" aria-hidden="true">⌘s</span>
          {{ saving ? translate('Saving') : translate('Save') }}
        </el-button>
      </template>
    </SettingsHeader>

    <div class="setting-wrap-inner fct-carrier-page">
      <Card.Container v-if="loading || loadFailed || !carrier">
        <Card.Body>
          <el-skeleton :loading="loading" animated :rows="6" />

          <div v-if="!loading && loadFailed" class="py-2">
            <Alert type="error" class="mb-3" role="alert" :content="translate('Failed to load carrier.')"/>
            <el-button size="small" :loading="loading" :disabled="loading" @click="load">
              {{ translate('Retry') }}
            </el-button>
          </div>

          <div v-else-if="!loading" class="text-sm text-system-mid dark:text-gray-300">
            {{ translate('Carrier not found.') }}
          </div>
        </Card.Body>
      </Card.Container>

      <template v-else>
        <CarrierSetupNotices :carrier="carrier"/>

        <el-form
            v-if="carrier.fields && carrier.fields.length"
            class="fct-carrier-form"
            label-position="top"
            @submit.prevent
        >
          <!-- One card per section, so each part of the setup reads on its own. -->
          <Card.Container
              v-for="group in groups"
              :key="group.key"
              class="fct-carrier-card"
              :class="{ 'is-off': isOff(group.section) }"
          >
            <Card.Header v-if="group.section" border_bottom>
              <template #title>
                <h3 class="fct-carrier-section__title">{{ group.section.label }}</h3>
              </template>
              <template v-if="sectionBadge(group) || group.section.aside" #action>
                <span v-if="sectionBadge(group)" class="fct-carrier-tag" :class="'is-' + (sectionBadge(group).tone || 'mute')">
                  <span v-if="sectionBadge(group).spin" class="fct-carrier-spin" aria-hidden="true"></span>
                  <span v-else-if="sectionBadge(group).dot" class="fct-carrier-dot" aria-hidden="true"></span>
                  {{ sectionBadge(group).text }}
                </span>
                <span v-else class="fct-carrier-hint is-flush">{{ group.section.aside }}</span>
              </template>
            </Card.Header>
            <Card.Body>
              <p v-if="group.section && group.section.hint" class="fct-carrier-section__hint">{{ group.section.hint }}</p>
              <div class="fct-carrier-fields">
                <template v-for="field in group.fields" :key="field.key">
                  <template v-if="field.type === 'webhook_url'">
                    <CarrierWebhookUrl v-if="carrier.webhook_url && isShown(field)" :url="carrier.webhook_url" :hint="webhookHint"/>
                  </template>
                  <div v-else-if="isDisplay(field) && isShown(field)" class="fct-carrier-field">
                    <CarrierDisplayField :field="field" :url="carrier.webhook_url || ''" :running="running" @action="runAction"/>
                  </div>
                  <div
                      v-else-if="isShown(field)"
                      class="fct-carrier-field"
                      :class="{ 'fct-carrier-field--half': field.width === 'half' }"
                  >
                    <CarrierModeField
                        v-if="field.type === 'mode'"
                        v-model="formState[field.key]"
                        :field="field"
                        :active-value="activeMode(field.key)"
                    >
                      <template #default="{ option }">
                        <template v-for="optionField in option.fields" :key="optionField.key">
                          <div v-if="optionField.type === 'section'" class="fct-carrier-section">
                            <h3 class="fct-carrier-section__title">{{ optionField.label }}</h3>
                            <p v-if="optionField.hint" class="fct-carrier-section__hint">{{ optionField.hint }}</p>
                          </div>
                          <template v-else-if="optionField.type === 'webhook_url'">
                            <CarrierWebhookUrl v-if="carrier.webhook_url && isShown(optionField)" :url="carrier.webhook_url" :hint="webhookHint"/>
                          </template>
                          <CarrierSettingField
                              v-else-if="isShown(optionField)"
                              v-model="formState[optionField.key]"
                              :field="optionField"
                              :saved="savedSecret(optionField.key)"
                          />
                        </template>
                      </template>
                    </CarrierModeField>
                    <CarrierSelectField
                        v-else-if="field.type === 'select' && field.groups"
                        v-model="formState[field.key]"
                        :field="field"
                        :running="running"
                        @action="runAction"
                    />
                    <CarrierSettingField
                        v-else
                        v-model="formState[field.key]"
                        :field="field"
                        :saved="savedSecret(field.key)"
                    />
                  </div>
                </template>
              </div>
              <!-- The URL carries the webhook secret; it is only on this store/sensitive payload.
                   A carrier with a webhook_url field places it in the form instead. -->
              <CarrierWebhookUrl
                  v-if="group === groups[groups.length - 1] && carrier.webhook_url && !placesWebhookUrl"
                  class="mt-5 pt-5 border-t border-gray-divider dark:border-dark-500"
                  :url="carrier.webhook_url"
                  :hint="webhookHint"
              />
            </Card.Body>
          </Card.Container>
        </el-form>

        <Card.Container v-else>
          <Card.Body>
            <p class="text-sm text-system-mid dark:text-gray-300 m-0">
              {{ translate('No configurable settings for this carrier.') }}
            </p>
            <CarrierWebhookUrl
                v-if="carrier.webhook_url"
                class="mt-5 pt-5 border-t border-gray-divider dark:border-dark-500"
                :url="carrier.webhook_url"
                :hint="webhookHint"
            />
          </Card.Body>
        </Card.Container>


        <div v-if="hasFields" class="fct-carrier-foot">
          <el-button :disabled="!dirty || saving" @click="discard">{{ translate('Discard') }}</el-button>
          <el-button type="primary" :loading="saving" :disabled="!dirty || saving" @click="saveSettings">{{ translate('Save settings') }}</el-button>
        </div>
      </template>

      <!-- Zones do not depend on the carrier payload, so a failed carrier fetch
           must not take the whole Shipping Zones section down with it. -->
      <CarrierZonesPanel v-if="!loading && isManual" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowRight } from '@element-plus/icons-vue';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import * as Card from '@/Bits/Components/Card/Card.js';
import Alert from '@/Bits/Components/Alert.vue';
import useKeyboardShortcuts from '@/utils/KeyboardShortcut';
import SettingsHeader from '../Settings/Parts/SettingsHeader.vue';
import CarrierZonesPanel from './CarrierZonesPanel.vue';
import CarrierSettingField from './Components/CarrierSettingField.vue';
import CarrierModeField from './Components/CarrierModeField.vue';
import CarrierSetupNotices from './Components/CarrierSetupNotices.vue';
import CarrierWebhookUrl from './Components/CarrierWebhookUrl.vue';
import CarrierDisplayField from './Components/CarrierDisplayField.vue';
import CarrierSelectField from './Components/CarrierSelectField.vue';
import SaveBar from '@/Bits/Components/SaveBar.vue';
import { CARRIER_MANUAL } from '@/utils/orderConstants';
import { fetchCarriers, invalidateCarriersCache } from './useCarriers';

const route = useRoute();

const loading = ref(true);
const loadFailed = ref(false);
const saving = ref(false);
const carrier = ref(null);
const formState = ref({});
// The carrier action in flight (a status button or Refresh list).
const running = ref(null);
const savedFlash = ref(false);

const DISPLAY_TYPES = ['status', 'readonly', 'notice', 'webhook_setup'];
const isDisplay = (field) => DISPLAY_TYPES.includes(field.type);

// Fields before the first section share a card without a header.
const groups = computed(() => {
  const list = [];
  ((carrier.value && carrier.value.fields) || []).forEach((field, index) => {
    if (field.type === 'section') {
      list.push({key: field.key || 'section-' + index, section: field, fields: []});
      return;
    }
    if (!list.length) {
      list.push({key: 'intro', section: null, fields: []});
    }
    list[list.length - 1].fields.push(field);
  });
  return list;
});

// active_if dims a card while the carrier, say, is off; its values stay.
const isOff = (section) => !!(section && section.active_if) && String(formState.value[section.active_if.key]) !== String(section.active_if.value);

const sectionBadge = (group) => {
  const busy = running.value && group.fields.some((field) => (field.actions || []).some((action) => action.action === running.value));
  if (busy) {
    const action = group.fields.flatMap((field) => field.actions || []).find((item) => item.action === running.value);
    return {tone: 'mute', spin: true, text: (action.working && action.working.badge) || translate('Working')};
  }
  return group.section ? group.section.badge || null : null;
};

const dirty = computed(() => {
  if (!carrier.value) {
    return false;
  }
  const saved = carrier.value.settings || {};
  return Object.keys(Object.assign({}, saved, formState.value))
      .some((key) => String(formState.value[key] ?? '') !== String(saved[key] ?? ''));
});

watch(formState, () => {
  savedFlash.value = false;
}, {deep: true});

// A mode whose token is neither saved nor typed cannot be saved.
const missingNote = (option) => {
  if (!option || !option.requires || !option.missing_note) {
    return '';
  }
  const typed = String(formState.value[option.requires] ?? '').trim();
  return !typed && !savedSecret(option.requires) ? option.missing_note : '';
};

const blockingNote = () => {
  const modes = ((carrier.value && carrier.value.fields) || []).filter((field) => field.type === 'mode');
  for (const field of modes) {
    const option = (field.options || []).find((item) => String(item.value) === String(formState.value[field.key]));
    if (missingNote(option)) {
      return missingNote(option);
    }
  }
  return '';
};

const discard = () => {
  formState.value = Object.assign({}, (carrier.value && carrier.value.settings) || {});
};

// Swap in the carrier the server rebuilt, keeping the admin's unsaved edits.
const applyCarrier = (nextCarrier) => {
  const before = (carrier.value && carrier.value.settings) || {};
  const edits = {};
  Object.keys(formState.value).forEach((key) => {
    if (String(formState.value[key] ?? '') !== String(before[key] ?? '')) {
      edits[key] = formState.value[key];
    }
  });
  carrier.value = nextCarrier;
  formState.value = Object.assign({}, nextCarrier.settings || {}, edits);
};

const runAction = (action) => {
  if (running.value) {
    return;
  }
  running.value = action.action;
  const slug = route.params.slug;

  Rest.post('shipping/carriers/' + slug + '/actions/' + action.action, {})
      .then((response) => {
        invalidateCarriersCache();
        if (route.params.slug !== slug) {
          return;
        }
        if (response.carrier) {
          applyCarrier(response.carrier);
        }
        if (response.ok === false) {
          Notify.error(response.message || translate('The carrier did not accept that.'));
        } else if (response.message) {
          Notify.success(response.message);
        }
      })
      .catch((errors) => {
        Notify.error((errors && errors.data && errors.data.message) || (errors && errors.message) || translate('Something went wrong. Try again.'));
      })
      .finally(() => {
        running.value = null;
      });
};

const isManual = computed(() => route.params.slug === CARRIER_MANUAL);

const carrierName = computed(() => (carrier.value ? carrier.value.meta.name : ''));

const hasFields = computed(() => !loading.value && !!carrier.value && Array.isArray(carrier.value.fields) && carrier.value.fields.length > 0);

// Carrier-provided, since only the carrier knows where its dashboard takes the URL.
const webhookHint = computed(() => {
  const hint = carrier.value && carrier.value.meta ? carrier.value.meta.webhook_hint : '';
  return hint || translate('Register this URL with the carrier to receive tracking updates.');
});

// A webhook_url entry at the top level or inside a mode option places the URL.
// webhook_setup shows the URL in its manual steps; a shows_webhook strip handles it itself.
const placesWebhookUrl = computed(() => !!carrier.value && Array.isArray(carrier.value.fields)
    && carrier.value.fields.some((field) => field && (field.type === 'webhook_url' || field.type === 'webhook_setup' || field.shows_webhook === true
        || (field.type === 'mode' && (field.options || []).some((option) => (option.fields || []).some((nested) => nested && nested.type === 'webhook_url'))))));

// What the server holds, as opposed to formState, which has unsaved edits.
const savedValue = (key) => {
  const settings = carrier.value && carrier.value.settings;
  return settings ? settings[key] : undefined;
};

// A disabled carrier, or one without the key for its mode, has no mode in effect.
const activeMode = (key) => (carrier.value && carrier.value.is_enabled ? savedValue(key) : undefined);

// A field with show_if appears only while the named field holds that value; its stored value is kept meanwhile.
// Compared as text, as the mode field does: saved settings come back as strings.
const isShown = (field) => !field.show_if || String(formState.value[field.show_if.key]) === String(field.show_if.value);

const savedSecret = (key) => {
  const secrets = carrier.value && carrier.value.saved_secrets;
  return (secrets && !Array.isArray(secrets) && secrets[key]) || null;
};

const keyboardShortcuts = useKeyboardShortcuts();
keyboardShortcuts.bind(['mod+s'], (event) => {
  event.preventDefault();
  if (hasFields.value) {
    saveSettings();
  }
});

onBeforeUnmount(() => {
  keyboardShortcuts.unbind('mod+s');
});

// Only the newest load may write component state. Two can be in flight at once —
// the slug watcher fires one per carrier change, and Retry fires another — and
// without this an older response still lands: a stale FAILURE would raise
// "Failed to load carrier" over a carrier that loaded perfectly well (the template
// checks loadFailed before carrier, so the error wins and the form never renders),
// and a stale SUCCESS would reset the form out from under whatever the admin is
// currently typing. A slug pin alone is not enough — it misses A → B → A and Retry.
let loadToken = 0;

const load = () => {
  const token = ++loadToken;

  loading.value = true;
  loadFailed.value = false;
  carrier.value = null;
  formState.value = {};

  fetchCarriers({ force: true })
      .then((carrierList) => {
        if (token !== loadToken) {
          return;
        }

        const slug = route.params.slug;
        carrier.value = carrierList.find((carrierItem) => carrierItem.slug === slug) || null;

        if (carrier.value) {
          formState.value = Object.assign({}, carrier.value.settings || {});
        }
      })
      .catch(() => {
        if (token !== loadToken) {
          return;
        }
        // A failed request is not a missing carrier — without this the page would
        // tell the admin the carrier does not exist whenever the network blips.
        loadFailed.value = true;
      })
      .finally(() => {
        // A stale finally would clear `loading` while a live request is still running.
        if (token === loadToken) {
          loading.value = false;
        }
      });
};

onMounted(() => {
  load();
});

// Vue reuses this component when only the :slug param changes, so onMounted does
// not fire again — without this, navigating straight from one carrier's settings
// to another's (a bookmark, an edited URL, browser history) would leave the
// previous carrier's name and fields on screen under the new slug.
watch(() => route.params.slug, (slug) => {
  if (slug) {
    load();
  }
});

const saveSettings = () => {
  // Vue only disables the button on the next tick, so a double-click inside one
  // frame can re-enter this before :loading takes effect — and a duplicate first
  // save is exactly what races two option rows into existence.
  if (saving.value) {
    return;
  }

  const blocked = blockingNote();
  if (blocked) {
    Notify.error(blocked);
    return;
  }

  saving.value = true;

  // Pin the carrier this save belongs to. The component is reused across a slug
  // change (see the watch below), so without this a save for carrier A could
  // resolve after the route moved to carrier B and overwrite B's form with A's
  // settings — which the admin would then save onto B.
  const savingSlug = route.params.slug;

  // A copy, not the reactive object itself: Rest.put stamps a query_timestamp
  // onto whatever it is handed, and that has no business in the form state.
  Rest.put('shipping/carriers/' + savingSlug + '/settings', Object.assign({}, formState.value))
      .then((response) => {
        // The write happened regardless of where the admin navigated, so the
        // cached lists are stale either way.
        invalidateCarriersCache();

        if (route.params.slug !== savingSlug) {
          return;
        }

        Notify.success(response.message || translate('Settings saved.'));
        // The server's carrier, rebuilt after the save: normalized settings, and
        // fields whose options depend on what was just stored.
        if (response.carrier) {
          carrier.value = response.carrier;
        }
        const savedSettings = Object.assign({}, response.settings || formState.value);
        formState.value = savedSettings;
        if (carrier.value) {
          carrier.value.settings = Object.assign({}, savedSettings);
        }
        nextTick(() => {
          savedFlash.value = true;
        });
      })
      .catch((errors) => {
        if (errors && errors.status_code == 422) {
          Notify.validationErrors(errors);
        } else {
          Notify.error((errors && errors.data && errors.data.message) || translate('Failed to save settings.'));
        }
      })
      .finally(() => {
        saving.value = false;
      });
};
</script>
