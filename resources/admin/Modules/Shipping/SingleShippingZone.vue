<template>
  <div class="setting-wrap">
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
        <div class="single-page-header flex items-center justify-between">
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

          <div class="fct-page-header__actions">
            <el-button type="primary" @click="saveZone" :loading="saving">
              <span v-if="!saving" class="cmd">⌘s</span>
              {{ saving ? translate('Saving') : translate('Save') }}
            </el-button>
          </div>
        </div>

        <CardContainer>
          <CardHeader :title="translate('Zone Details')" border_bottom/>
          <CardBody>
            <el-form :model="zoneForm" :rules="rules" ref="zoneFormRef" label-position="top" require-asterisk-position="right">
              <el-form-item :label="translate('Zone Name')" prop="name">
                <el-input v-model="zoneForm.name" :placeholder="translate('Enter zone name')"></el-input>
              </el-form-item>

              <el-form-item label="" prop="region" class="mt-6 mb-6">
                <el-checkbox v-model="isWholeWorld" @change="handleWholeWorldToggle">
                    {{ translate('Applies to Whole World') }}
                  </el-checkbox>
              </el-form-item>

              <el-form-item :label="translate('Country')" prop="region" class="mb-0" required>
                  <el-select
                      v-model="zoneForm.region"
                      :disabled="isWholeWorld"
                      filterable
                      default-first-option
                      :placeholder="translate('Select countries')"
                      :reserve-keyword="false"
                      @visible-change="rememberRegion"
                      @change="changeRegion"
                  >
                    <el-option
                        v-for="country in countries"
                        :key="country.code2"
                        :label="country.name"
                        :value="country.code2"
                    />
                  </el-select>
                <div class="form-help-text">
                  {{ translate('Add countries where this shipping zone applies.') }}
                </div>
              </el-form-item>
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
</template>

<script setup>
import {ref, computed, onMounted, markRaw} from 'vue';
import {useRouter, useRoute, onBeforeRouteLeave} from 'vue-router';
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from '@/Bits/Components/Card/Card.js';
import SingleShippingZoneLoader from '@/Modules/Shipping/Components/SingleShippingZoneLoader.vue';
import NotFound from '@/Pages/NotFound.vue';
import Rest from '@/utils/http/Rest';
import translate from "@/utils/translator/Translator";
import {ArrowRight, Delete} from "@element-plus/icons-vue";
import Notify from "@/utils/Notify";
import countries from "@/Modules/Customers/countries.json";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
import ShippingMethods from "@/Modules/Shipping/ShippingMethods.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import {ElMessageBox} from "element-plus";


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
const loading = ref(false);
const saving = ref(false);

const zoneForm = ref({
  name: '',
  region: ''
});

const rules = ref({
  name: [
    {required: true, message: translate('Please enter a zone name'), trigger: 'blur'}
  ]
});

const zoneShippingMethods = ref([]);
const previousRegion = ref(null);
const isWholeWorld = ref(false);

const notFound = ref({
  show: false,
  message: '',
  buttonText: '',
  route: ''
});

// Remember old region when dropdown opens
const rememberRegion = (visible) => {
  if (visible) {
    previousRegion.value = zoneForm.value.region
  }
}

// Computed
const isEdit = computed(() => {
  return !!props.zone_id;
});

// Methods
const fetchZoneData = () => {
  if (!isEdit.value) return;

  loading.value = true;
  Rest.get(`shipping/zones/${props.zone_id}`)
      .then(response => {
        const zone = response.shipping_zone;
        zoneForm.value.name = zone.name;
        zoneForm.value.region = zone.region || '';
        isWholeWorld.value = zoneForm.value.region === 'all';
        zoneShippingMethods.value = zone.methods || [];
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
      });
};

const handleWholeWorldToggle = (checked) => {
  if (checked) {
    // Set region to 'all' and remember previous region
    previousRegion.value = zoneForm.value.region;
    zoneForm.value.region = 'all';
  } else {
    // Restore previous region or empty
    zoneForm.value.region = previousRegion.value || '';
  }
};

const saveZone = () => {
  zoneFormRef.value.validate(valid => {
    if (!valid) return;

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
          if (error.status_code == '422') {
            Notify.validationErrors(error);
          } else {
            Notify.error(error.data?.message);
          }
          console.error('Error saving shipping zone:', error);
          // Notify.error(translate('Failed to save shipping zone'));
        })
        .finally(() => {
          saving.value = false;
        });
  });
};

const goBack = () => {
  router.push({name: 'all_shipping_zones'});
};

const changeRegion = () => {
  if (!isEdit.value) {
    return;
  }

  // add confirmation
  ElMessageBox.confirm(
      'If you change the region, all the shipping methods states will be removed. Do you want to continue?',
      'Confirmation',
      {
        confirmButtonText: 'Yes',
        cancelButtonText: 'No',
        type: 'warning',
      }
  ).then(() => {
  }).catch(() => {
    // restore the region
    zoneForm.value.region = previousRegion.value // restore

  })
}


// Lifecycle
onMounted(() => {
  fetchZoneData();
});
onBeforeRouteLeave(() => {
  keyboardShortcuts.unbind('mod+s');
});
</script>

<style scoped>
.form-help-text {
  font-size: 12px;
  color: #909399;
  margin-top: 5px;
}

.no-methods {
  text-align: center;
  padding: 30px;
}
</style>
