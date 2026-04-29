<template>
  <div class="setting-wrap">
    <SettingsHeader :show-save-button="false">
      <template #heading>
        <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
          <el-breadcrumb-item :to="{ name: 'shipping' }">
            {{ translate("Shipping Zones") }}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            {{ isEdit ? translate('Edit Shipping Zone') : translate('Add Shipping Zone') }}
          </el-breadcrumb-item>
          <el-breadcrumb-item v-if="isEdit">
            {{ zoneForm.name }}
          </el-breadcrumb-item>
        </el-breadcrumb>
      </template>

      <template #action>
        <el-button type="primary" @click="saveZone" :loading="saving" size="small">
          <span v-if="!saving" class="cmd">⌘s</span>
          {{ saving ? translate('Saving') : translate('Save') }}
        </el-button>
      </template>
    </SettingsHeader>


    <div class="setting-wrap-inner">
      <div class="fct-single-shipping-zone-page">
        <div v-if="loading">
          <SingleShippingZoneLoader/>
        </div>
        <NotFound v-else-if="notFound.show"
                  :message="notFound.message"
                  :button-text="notFound.buttonText"
                  :route="notFound.route"
        />
        <div v-else class="fct-single-shipping-zone">
          <CardContainer>
            <CardHeader :title="translate('Zone Details')" border_bottom/>
            <CardBody>
              <el-form :model="zoneForm" :rules="rules" ref="zoneFormRef" label-position="top" require-asterisk-position="right">
                <el-form-item :label="translate('Zone Name')" prop="name">
                  <el-input v-model="zoneForm.name" :placeholder="translate('Enter zone name')"></el-input>
                </el-form-item>

                <el-form-item :label="translate('Coverage')" prop="region" class="mt-2 mb-0" required>
                  <ul class="fct-coverage-selector" role="radiogroup" :aria-label="translate('Coverage')">
                    <li
                        v-for="option in coverageOptions"
                        :key="option.value"
                        :class="{ active: coverageMode === option.value }"
                        role="radio"
                        tabindex="0"
                        :aria-checked="coverageMode === option.value"
                        @click="setCoverageMode(option.value)"
                        @keydown.enter.space.prevent="setCoverageMode(option.value)"
                    >
                      <div class="fct-coverage-selector-content">
                        <span class="fct-coverage-selector-title">{{ option.title }}</span>
                        <span class="fct-coverage-selector-desc">{{ option.desc }}</span>
                      </div>
                      <div class="fct-coverage-selector-dot-wrap">
                        <span class="fct-coverage-selector-dot"></span>
                      </div>
                    </li>
                  </ul>
                </el-form-item>

                <Animation :visible="coverageMode !== 'whole_world'" accordion>
                  <el-form-item
                      :label="translate('Countries')"
                      class="mt-6 mb-0"
                      required
                  >
                    <el-tree-select
                        ref="countryTreeRef"
                        v-model="selectedCountries"
                        :data="countryTreeData"
                        multiple
                        filterable
                        show-checkbox
                        collapse-tags
                        collapse-tags-tooltip
                        :max-collapse-tags="3"
                        :render-after-expand="false"
                        node-key="value"
                        :props="{ label: 'label', children: 'children' }"
                        :placeholder="translate('Type to search countries...')"
                        popper-class="fct-country-tree-popper"
                        :teleported="true"
                        @check="onTreeCheck"
                        @visible-change="onDropdownVisibleChange"
                    />
                    <div v-if="selectedCountries.length > 0" class="fct-coverage-hint" :class="{ 'is-warning': coverageMode === 'excluded' }">
                      {{ coverageMode === 'excluded'
                          ? translate('Ships everywhere except the countries listed above.')
                          : translate('Only ships to the countries listed above.')
                      }}
                    </div>
                  </el-form-item>
                </Animation>
              </el-form>
            </CardBody>
          </CardContainer>

          <ShippingMethods
              v-if="isEdit"
              :zone_id="props.zone_id"
              :methods="zoneShippingMethods"
              @fetchShippingMethods="fetchZoneData"
              :country="zoneForm.region"
          />
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import {ref, computed, onMounted, nextTick} from 'vue';
import {useRouter, onBeforeRouteLeave} from 'vue-router';
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from '@/Bits/Components/Card/Card.js';
import SingleShippingZoneLoader from '@/Modules/Shipping/Components/SingleShippingZoneLoader.vue';
import NotFound from '@/Pages/NotFound.vue';
import Rest from '@/utils/http/Rest';
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";
import Notify from "@/utils/Notify";
import countries from "@/Modules/Customers/countries.json";
import ShippingMethods from "@/Modules/Shipping/ShippingMethods.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";
import Animation from "@/Bits/Components/Animation.vue";


// Props
const props = defineProps({
  zone_id: {
    type: [String, Number],
    required: false
  }
});


// Router
const router = useRouter();

const keyboardShortcuts = useKeyboardShortcuts();
keyboardShortcuts.bind(['mod+s'], (event) => {
  event.preventDefault();
  saveZone();
});

// Refs and State
const zoneFormRef = ref(null);
const countryTreeRef = ref(null);
const loading = ref(false);
const saving = ref(false);

const zoneForm = ref({
  name: '',
  region: '',
  meta: null
});

const rules = ref({
  name: [
    {required: true, message: translate('Please enter a zone name'), trigger: 'blur'}
  ]
});

const zoneShippingMethods = ref([]);
const selectedCountries = ref([]);
const coverageMode = ref('selected');

const coverageOptions = [
  {
    value: 'whole_world',
    title: translate('Whole world'),
    desc: translate('Ship to every country and territory')
  },
  {
    value: 'selected',
    title: translate('Selected countries only'),
    desc: translate('Ship exclusively to the countries you choose')
  },
  {
    value: 'excluded',
    title: translate('All countries except'),
    desc: translate('Ship everywhere, excluding specific countries')
  }
];

const notFound = ref({
  show: false,
  message: '',
  buttonText: '',
  route: ''
});


// Computed
const isEdit = computed(() => {
  return !!props.zone_id;
});

const rawContinents = ref([]);

const countryTreeData = computed(() => {
  return rawContinents.value.map(continent => {
    const childCodes = continent.countries.map(c => c.code);
    const selectedCount = selectedCountries.value.filter(c => childCodes.includes(c)).length;
    const countLabel = selectedCount > 0
        ? `${continent.name} (${selectedCount}/${continent.countries.length})`
        : `${continent.name} (${continent.countries.length})`;
    return {
      value: 'continent_' + continent.code,
      label: countLabel,
      children: continent.countries.map(c => ({
        value: c.code,
        label: c.name
      }))
    };
  });
});

const fetchCountries = () => {
  Rest.get('shipping/zone/countries')
      .then(response => {
        rawContinents.value = (response.continents || []).map(continent => ({
          code: continent.code,
          name: continent.name,
          countries: continent.countries
        }));
      })
      .catch(error => {
        console.error('Error fetching countries:', error);
        rawContinents.value = [{
          code: 'ALL',
          name: '',
          countries: countries.map(c => ({code: c.code2, name: c.name}))
        }];
      });
};

// Methods
const fetchZoneData = () => {
  if (!isEdit.value) return;

  loading.value = true;
  Rest.get(`shipping/zones/${props.zone_id}`)
      .then(response => {
        const zone = response.shipping_zone;
        zoneForm.value.name = zone.name;
        zoneForm.value.region = zone.region || '';
        zoneForm.value.meta = zone.meta || null;
        zoneShippingMethods.value = zone.methods || [];

        // Determine coverage mode from zone data
        if (zone.region === 'all') {
          coverageMode.value = 'whole_world';
          selectedCountries.value = [];
        } else if (zone.region === 'selection' && zone.meta) {
          coverageMode.value = zone.meta.selection_type === 'excluded' ? 'excluded' : 'selected';
          selectedCountries.value = zone.meta.countries || [];
        } else if (zone.region && zone.region !== 'all') {
          // Legacy single country
          coverageMode.value = 'selected';
          selectedCountries.value = [zone.region];
        } else {
          coverageMode.value = 'selected';
          selectedCountries.value = [];
        }
      })
      .catch(error => {
        console.error('Error fetching zone data:', error);
        notFound.value = {
          show: true,
          message: translate('Shipping zone not found'),
          buttonText: translate('Back to Shipping Zones'),
          route: {name: 'all_shipping_zones'}
        };
      })
      .finally(() => {
        loading.value = false;
        nextTick(() => syncTooltipWidth());
      });
};

const setCoverageMode = (mode) => {
  coverageMode.value = mode;
  syncRegionFromSelection();
};

const onTreeCheck = () => {
  // Filter out continent parent nodes — only keep actual country codes
  selectedCountries.value = selectedCountries.value.filter(v => !v.startsWith('continent_'));
  syncRegionFromSelection();
};

const syncTooltipWidth = () => {
  const el = countryTreeRef.value?.$el?.querySelector('.el-select__wrapper') || countryTreeRef.value?.$el;
  if (!el || !el.offsetWidth) return;
  document.documentElement.style.setProperty('--fct-country-input-width', el.offsetWidth + 'px');
};

const onDropdownVisibleChange = () => {
  syncTooltipWidth();
};

// Sync region/meta from the current coverage mode and country selection.
// Single country (included) -> direct country code (backward compat, states work)
// Multiple countries or excluded -> 'selection' with meta
// Whole world -> 'all'
const syncRegionFromSelection = () => {
  if (coverageMode.value === 'whole_world') {
    zoneForm.value.region = 'all';
    zoneForm.value.meta = null;
    return;
  }

  const selected = selectedCountries.value;
  const selectionType = coverageMode.value === 'excluded' ? 'excluded' : 'included';

  if (selected.length === 0) {
    zoneForm.value.region = '';
    zoneForm.value.meta = null;
  } else if (selected.length === 1 && selectionType === 'included') {
    // Single country included — store as direct country code
    zoneForm.value.region = selected[0];
    zoneForm.value.meta = null;
  } else {
    zoneForm.value.region = 'selection';
    zoneForm.value.meta = {
      countries: selected,
      selection_type: selectionType
    };
  }
};

const saveZone = () => {
  zoneFormRef.value.validate(valid => {
    if (!valid) return;

    syncRegionFromSelection();

    saving.value = true;
    const method = isEdit.value ? 'put' : 'post';
    const url = `shipping/zones${isEdit.value ? `/${props.zone_id}` : ''}`;

    Rest[method](url, zoneForm.value)
        .then(response => {
          Notify.success(response.message);
          if (!isEdit.value) {
            router.push({
              name: 'view_shipping_zone',
              params: {zone_id: response.shipping_zone.id}
            });
          } else {
            fetchZoneData();
          }
        })
        .catch(error => {
          if (error && error.status_code == '422') {
            Notify.validationErrors(error);
          } else {
            Notify.error(error?.data?.message || error?.message || translate('Failed to save shipping zone'));
          }
          console.error('Error saving shipping zone:', error);
        })
        .finally(() => {
          saving.value = false;
        });
  });
};

const goBack = () => {
  router.push({name: 'all_shipping_zones'});
};


// Lifecycle
onMounted(() => {
  fetchCountries();
  fetchZoneData();
});
onBeforeRouteLeave(() => {
  keyboardShortcuts.unbind('mod+s');
});
</script>

