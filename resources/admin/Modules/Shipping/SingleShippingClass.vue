<template>
  <div class="setting-wrap fct-single-shipping-class-page">
    <SettingsHeader
        :save-button-text="translate('Save Shipping Class')"
        :loading-text="translate('Saving Shipping Class')"
        :loading="saving"
        @onSave="saveClass"
    >
      <template #heading>
        <el-breadcrumb :separator-icon="ArrowRight">
          <el-breadcrumb-item :to="{ name: 'shipping_classes' }">
            {{ translate("Shipping Classes") }}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            {{ isEdit ? translate('Edit Shipping Class') : translate('Add Shipping Class') }}
          </el-breadcrumb-item>
          <el-breadcrumb-item v-if="isEdit">
            {{ classForm.name }}
          </el-breadcrumb-item>
        </el-breadcrumb>
      </template>

    </SettingsHeader>

    <div class="setting-wrap-inner">
      <div v-if="loading">
        <SingleShippingClassLoader/>
      </div>
      <NotFound v-else-if="notFound.show"
                :message="notFound.message"
                :button-text="notFound.buttonText"
                :route="notFound.route"
      />
      <div v-else class="fct-single-shipping-class">
        <CardContainer>
          <CardHeader :title="translate('Class Details')" border_bottom title_size="small"/>
          <CardBody>
            <el-form :model="classForm" :rules="rules" ref="classFormRef" label-position="top">
              <el-form-item :label="translate('Class Name')" prop="name">
                <el-input v-model="classForm.name" :placeholder="translate('Enter class name')"></el-input>
              </el-form-item>

              <el-form-item :label="translate('Description')">
                <el-input v-model="classForm.description" type="textarea" :rows="2" :placeholder="translate('Enter description (optional)')"></el-input>
              </el-form-item>

              <el-form-item :label="translate('Cost')" prop="cost">
                <el-input-number v-model="classForm.cost" :precision="2" :min="0" :step="1" class="w-full max-w-[200px]"></el-input-number>
                <div class="form-help-text w-full">
                  {{ translate('Additional cost for products in this shipping class') }}
                </div>
              </el-form-item>

              <el-form-item :label="translate('Cost Type')" prop="type">
                <el-select v-model="classForm.type" style="width: 100%">
                  <el-option :label="translate('Fixed Amount')" value="fixed"></el-option>
                  <el-option :label="translate('Percentage')" value="percentage"></el-option>
                </el-select>
                <div class="form-help-text">
                  {{ translate('How this cost should be applied to the shipping rate') }}
                </div>
              </el-form-item>
            </el-form>
          </CardBody>
        </CardContainer>

        <!-- Shipping Profile: Zones & Methods for this class -->
        <div v-if="isEdit" class="mt-4">
          <CardContainer>
            <CardHeader :title="translate('Shipping Profile')" border_bottom title_size="small">
              <template #action>
                <el-button type="primary" size="small" @click="addZoneForClass">
                  {{ translate('Add Zone') }}
                </el-button>
              </template>
            </CardHeader>
            <CardBody>
              <p class="text-gray-500 text-sm mb-3">
                {{ translate('Define shipping zones and methods specific to this class. Products with this class will use these rates instead of the general shipping zones.') }}
              </p>

              <div v-if="profileLoading" v-loading="true" class="py-8"></div>

              <div v-else-if="profileZones.length === 0" class="text-center py-4 text-gray-400">
                {{ translate('No class-specific zones yet. Products in this class will use the general shipping zones.') }}
              </div>

              <div v-else>
                <div v-for="zone in profileZones" :key="zone.id" class="mb-4 border rounded-md overflow-hidden">
                  <div class="flex items-center justify-between px-4 py-2 bg-gray-50 dark:bg-gray-800">
                    <div>
                      <strong>{{ zone.name }}</strong>
                      <span class="text-gray-500 ml-2">({{ zone.formatted_region }})</span>
                    </div>
                    <div class="flex items-center gap-1">
                      <el-popconfirm
                          :title="translate('Delete this zone and its methods?')"
                          @confirm="deleteProfileZone(zone.id)"
                      >
                        <template #reference>
                          <el-button size="small" type="danger" text>
                            {{ translate('Delete Zone') }}
                          </el-button>
                        </template>
                      </el-popconfirm>
                    </div>
                  </div>
                  <div class="p-0">
                    <ShippingMethods
                        :zone_id="zone.id"
                        :methods="zone.methods || []"
                        :country="zone.region"
                        @fetchShippingMethods="fetchProfile"
                    />
                  </div>
                </div>
              </div>
            </CardBody>
          </CardContainer>
        </div>

        <!-- Zone creation drawer for this class -->
        <el-drawer
            v-model="showZoneDrawer"
            :title="translate('Add Zone to Shipping Class')"
            :aria-label="translate('Add Zone to Shipping Class')"
            width="500px"
            append-to-body
        >
          <el-form :model="zoneForm" ref="zoneFormRef" label-position="top">
            <el-form-item :label="translate('Zone Name')" prop="name" :rules="[{ required: true, message: translate('Zone name is required'), trigger: 'blur' }]">
              <el-input v-model="zoneForm.name" :placeholder="translate('Enter zone name')"></el-input>
            </el-form-item>
            <el-form-item :label="translate('Region')">
              <el-select v-model="zoneForm.region" filterable :placeholder="translate('Select country or Whole World')">
                <el-option :label="translate('Whole World')" value="all"/>
                <el-option
                    v-for="country in countries"
                    :key="country.code2"
                    :label="country.name"
                    :value="country.code2"
                />
              </el-select>
            </el-form-item>
          </el-form>
          <template #footer>
            <el-button @click="showZoneDrawer = false">{{ translate('Cancel') }}</el-button>
            <el-button type="primary" @click="saveZoneForClass" :loading="savingZone" :disabled="savingZone">{{ translate('Save') }}</el-button>
          </template>
        </el-drawer>
      </div>
    </div>
  </div>
</template>

<script setup>
import {ref, computed, onMounted} from 'vue';
import {useRouter} from 'vue-router';
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from '@/Bits/Components/Card/Card.js';
import SingleShippingClassLoader from '@/Modules/Shipping/Components/SingleShippingClassLoader.vue';
import ShippingMethods from '@/Modules/Shipping/ShippingMethods.vue';
import NotFound from '@/Pages/NotFound.vue';
import Rest from '@/utils/http/Rest';
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";
import Notify from "@/utils/Notify";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";
import countriesList from "@/Modules/Customers/countries.json";

const saveShortcut = useSaveShortcut();

// Props
const props = defineProps({
  class_id: {
    type: [String, Number],
    required: false
  }
});

// Router
const router = useRouter();

saveShortcut.onSave(() => {
  saveClass();
});

// Refs and State
const classFormRef = ref(null);
const loading = ref(false);
const saving = ref(false);

const classForm = ref({
  name: '',
  description: '',
  cost: 0,
  type: 'fixed'
});

// Profile state
const profileLoading = ref(false);
const profileZones = ref([]);
const showZoneDrawer = ref(false);
const savingZone = ref(false);
const zoneFormRef = ref(null);
const zoneForm = ref({
  name: '',
  region: 'all'
});
const countries = countriesList;

const rules = ref({
  name: [
    {required: true, message: translate('Please enter a class name'), trigger: 'blur'}
  ],
  cost: [
    {required: true, message: translate('Please enter a cost'), trigger: 'blur'}
  ],
  type: [
    {required: true, message: translate('Please select a cost type'), trigger: 'blur'}
  ]
});

const notFound = ref({
  show: false,
  message: '',
  buttonText: '',
  route: ''
});

// Computed
const isEdit = computed(() => {
  return !!props.class_id;
});

// Methods
const fetchClassData = () => {
  if (!isEdit.value) {
    loading.value = false;
    return;
  }

  loading.value = true;
  Rest.get(`/shipping/classes/${props.class_id}`)
    .then(response => {
      const shippingClass = response.shipping_class;
      classForm.value = {
        name: shippingClass.name,
        description: shippingClass.description || '',
        cost: shippingClass.cost,
        type: shippingClass.type
      };
      if (shippingClass.zones) {
        profileZones.value = shippingClass.zones;
      }
    })
    .catch(error => {
      // Error handled below via notFound state
      notFound.value = {
        show: true,
        message: translate('The shipping class you are looking for does not exist or has been deleted.'),
        buttonText: translate('Go to Shipping Classes'),
        route: { name: 'shipping_classes' }
      };
    })
    .finally(() => {
      loading.value = false;
    });
};

const saveClass = () => {
  classFormRef.value.validate(valid => {
    if (!valid) return;

    saving.value = true;
    const method = isEdit.value ? 'put' : 'post';
    const url = isEdit.value ? `/shipping/classes/${props.class_id}` : '/shipping/classes';

    Rest[method](url, classForm.value)
      .then(response => {
        Notify.success(response.message);
        if (!isEdit.value) {
          router.push({
            name: 'view_shipping_class',
            params: {class_id: response.shipping_class.id}
          });
        }
      })
      .catch(error => {
        // Error shown via Notify
        Notify.error(translate('Failed to save shipping class'));
      })
      .finally(() => {
        saving.value = false;
      });
  });
};

// Profile methods
const fetchProfile = () => {
  if (!isEdit.value) return;

  profileLoading.value = true;
  Rest.get(`/shipping/classes/${props.class_id}/profile`)
    .then(response => {
      profileZones.value = response.shipping_class?.zones || [];
    })
    .catch(error => {
      Notify.error(translate('Failed to load shipping profile'));
    })
    .finally(() => {
      profileLoading.value = false;
    });
};

const addZoneForClass = () => {
  zoneForm.value = { name: '', region: 'all' };
  showZoneDrawer.value = true;
};

const saveZoneForClass = () => {
  zoneFormRef.value.validate(valid => {
    if (!valid) return;

    savingZone.value = true;
    Rest.post('/shipping/zones', {
      ...zoneForm.value,
      shipping_class_id: props.class_id
    })
      .then(response => {
        Notify.success(response.message);
        showZoneDrawer.value = false;
        fetchProfile();
      })
      .catch(error => {
        // Error shown via Notify
        Notify.error(translate('Failed to save shipping zone'));
      })
      .finally(() => {
        savingZone.value = false;
      });
  });
};

const deleteProfileZone = (zoneId) => {
  Rest.delete(`/shipping/zones/${zoneId}`)
    .then(response => {
      Notify.success(response.message);
      fetchProfile();
    })
    .catch(error => {
      // Error shown via Notify
      Notify.error(translate('Failed to delete shipping zone'));
    });
};

// Lifecycle
onMounted(() => {
  fetchClassData();
});
</script>
