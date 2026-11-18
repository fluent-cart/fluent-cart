<script setup>
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from '@/Bits/Components/Card/Card.js';
import {computed, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Badge from "@/Bits/Components/Badge.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import WeightTiersEditor from "@/Modules/Shipping/Components/WeightTiersEditor.vue";
import AppConfig from "@/utils/Config/AppConfig";

const storeWeightUnit = AppConfig.get('shop.weight_unit') || 'kg';

// Props
const props = defineProps({
  zone_id: {
    type: [String, Number],
    required: true
  },
  methods: {
    type: Array,
    required: false,
    default: () => []
  },
  country: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['fetchShippingMethods'])


// Add these refs after other refs
const showMethodModal = ref(false);
const methodFormRef = ref(null);
const savingMethod = ref(false);
const methodForm = ref({
  zone_id: props.zone_id,
  method_id: 0,
  title: '',
  type: 'flat_rate',
  settings: {
    configure_rate: 'per_item',
    class_aggregation: 'sum_all'
  },
  amount: 0,
  is_enabled: 1,
  states: [],
  meta: {
    description: '',
    tracking_url_pattern: ''
  }
});
const fetchingStateZones = ref(false);
const stateZones = ref([]);
const isWorldZone = computed(() => props.country === 'all' || props.country === 'selection');

const methodRules = ref({
  title: [
    {required: true, message: translate('Please enter a method title'), trigger: 'blur'}
  ],
  type: [
    {required: true, message: translate('Please select a method type'), trigger: 'blur'}
  ]
});
const stateLabel = ref('States');


const addShippingMethod = () => {
  methodForm.value = {
    zone_id: props.zone_id,
    method_id: 0,
    title: '',
    type: 'flat_rate',
    settings: {
      configure_rate: 'per_item',
      class_aggregation: 'sum_all',
      weight_tiers: []
    },
    amount: 0,
    is_enabled: 1,
    states: [],
    meta: {
      description: '',
      tracking_url_pattern: ''
    }
  };
  showMethodModal.value = true;
};


const validateWeightTiers = () => {
  if (methodForm.value.type === 'free_shipping' || methodForm.value.settings.configure_rate !== 'per_weight') {
    return true;
  }
  const tiers = methodForm.value.settings.weight_tiers || [];
  if (!tiers.length) {
    Notify.error(translate('Please add at least one weight tier'));
    return false;
  }
  const hasAllZeroCost = tiers.every(t => !t.cost || t.cost <= 0);
  if (hasAllZeroCost) {
    Notify.error(translate('Weight tiers must have a cost greater than zero'));
    return false;
  }
  return true;
};

const saveErrorMessage = (errors, fallback) => {
  let message = '';
  if (errors.status_code == '422') {
    for (const fieldKey in errors.data) {
      message += Object.values(errors.data[fieldKey])[0] + ' \n';
    }
  }
  return message || fallback;
};

const saveShippingMethod = () => {
  if (!validateWeightTiers()) return;

  savingMethod.value = true;
  Rest.post('shipping/methods', methodForm.value)
      .then(response => {
        Notify.success(response.message);
        showMethodModal.value = false;
        emit('fetchShippingMethods');
      })
      .catch(errors => {
        Notify.error(saveErrorMessage(errors, translate('Failed to save shipping method')));
      })
      .finally(() => {
        savingMethod.value = false;
      });
};

const updateShippingMethod = () => {
  if (!validateWeightTiers()) return;
  savingMethod.value = true;
  Rest.put('shipping/methods', methodForm.value)
      .then(response => {
        Notify.success(response.message);
        showMethodModal.value = false;
        emit('fetchShippingMethods');
      })
      .catch(errors => {
        Notify.error(saveErrorMessage(errors, translate('Failed to update shipping method')));
      })
      .finally(() => {
        savingMethod.value = false;
      });
};

const deleteShippingMethod = (methodId) => {
  Rest.delete('/shipping/methods/' + methodId)
      .then(response => {
        Notify.success(response.message);
        emit('fetchShippingMethods');
      })
      .catch(error => {
        // Error shown via Notify
        Notify.error(translate('Failed to delete shipping method'));
      });
};

const methodTypes = ref([
  {label: translate('Flat Rate'), value: 'flat_rate'},
  {label: translate('Free Shipping'), value: 'free_shipping'},
  {label: translate('Local Pickup'), value: 'local_pickup'}
]);

// handle edit shipping method
const editShippingMethod = (row) => {
  methodForm.value = {
    zone_id: props.zone_id,
    method_id: row.id,
    title: row.title,
    type: row.type,
    settings: {
      configure_rate: 'per_item',
      class_aggregation: 'sum_all',
      weight_tiers: [],
      ...(row.settings || {})
    },
    amount: row.amount,
    is_enabled: row.is_enabled ? 1 : 0,
    states: row.states,
    meta: {
      description: row.meta?.description || '',
      tracking_url_pattern: row.meta?.tracking_url_pattern || ''
    },
  };
  showMethodModal.value = true;
};

const configureRateHelpText = computed(() => {
  if (methodForm.value.settings.configure_rate === 'per_order') {
    return translate('Single charge for the entire order.');
  } else if (methodForm.value.settings.configure_rate === 'per_item') {
    return translate('Charge per item in the cart.');
  } else if (methodForm.value.settings.configure_rate === 'per_price') {
    return translate('Percentage of the total amount of the order');
  } else if (methodForm.value.settings.configure_rate === 'per_weight') {
    return translate('Cost based on total weight of items in the cart.');
  }
});

const classAggregationHelpText = computed(() => {
  if (methodForm.value.settings.class_aggregation === 'sum_all') {
    return translate('Sums of all shipping classes.');
  } else if (methodForm.value.settings.class_aggregation === 'highest_class') {
    return translate('Cost of the highest class among all the classes.');
  }
});

const getConfigureTitle = (rate) => {
  if (rate === 'per_order') {
    return translate('Per Order');
  } else if (rate === 'per_item') {
    return translate('Per Item');
  } else if (rate === 'per_price') {
    return translate('Percentage');
  } else if (rate === 'per_weight') {
    return translate('Per Weight');
  }
};

// Add and Edit share one form, so drop errors left from the last attempt.
const onMethodDrawerOpen = () => {
  methodFormRef.value?.clearValidate();
  fetchStateZones();
};

const fetchStateZones = () => {
  if (!props.country || props.country === 'all' || props.country === 'selection') {
    return;
  }

  fetchingStateZones.value = true;

  Rest.get('/shipping/zone/states', {
    country_code: props.country
  })
      .then(response => {
        stateZones.value = response.data?.states;

        if (response.data?.address_locale?.state?.label) {
          stateLabel.value = response.data?.address_locale?.state?.label;
        }
      })
      .catch(error => {
        Notify.error(translate('Failed to load states'));
      })
      .finally(() => {
        fetchingStateZones.value = false;
      });
};

</script>

<template>
  <div class="fct-shipping-methods-wrap">
    <CardContainer v-if="zone_id" class="mb-4 overflow-hidden">
      <CardHeader :title="translate('Shipping Methods')">
        <template #action>
          <fct-button type="primary" @click="addShippingMethod">
            {{ translate('Add Shipping Method') }}
          </fct-button>
        </template>
      </CardHeader>
      <CardBody>
        <div class="shipping-methods-list">
          <!-- Shipping Methods -->
          <fct-table :data="methods" class="w-full">
            <fct-table-column prop="title" :label="translate('Method Title')"/>
            <fct-table-column :label="translate('States')">
              <template #default="scope">
                <div v-if="scope.row.formatted_states && scope.row.formatted_states.length > 0" class="flex flex-wrap gap-1">
                  <el-tag
                      v-for="(zone, index) in scope.row.formatted_states.slice(0, 3)"
                      :key="index"
                      type="info"
                  >
                    {{ zone }}
                  </el-tag>
                  <el-tag
                      v-if="scope.row.formatted_states.length > 3"
                      type="info"
                  >
                    +{{ scope.row.formatted_states.length - 3 }}
                  </el-tag>
                </div>
                <div v-else class="text-muted">
                  {{ translate('Applied To All States') }}
                </div>
              </template>
            </fct-table-column>
            <fct-table-column prop="type" :label="translate('Method Type')">
              <template #default="scope">
                {{ methodTypes.find(type => type.value === scope.row.type)?.label ?? scope.row.type }}
              </template>
            </fct-table-column>
            <fct-table-column prop="amount" :label="translate('Amount')" width="100px">
              <template #default="scope">
                <span v-if="scope.row.type === 'free_shipping'">
                  {{ translate('Free') }}
                </span>
                <span v-else-if="scope.row.settings?.configure_rate === 'per_weight'">
                  —
                </span>
                <span v-else-if="scope.row.settings?.configure_rate === 'per_price'">
                  {{ scope.row.amount }}%
                </span>
                <span v-else>
                  {{ scope.row.amount }}
                </span>
              </template>
            </fct-table-column>
            <fct-table-column prop="configure_rate" :label="translate('Configure Rate')">
              <template #default="scope">
                <span v-if="scope.row.type === 'free_shipping'">
                  --
                </span>
                <span v-else>
                  {{ getConfigureTitle(scope.row.settings.configure_rate) }}
                </span>
              </template>
            </fct-table-column>
            <fct-table-column prop="class_aggregation" :label="translate('Class Aggregation')" width="200px">
              <template #default="scope">
                 <span v-if="scope.row.type === 'free_shipping'">
                  --
                </span>
                <span v-else>
                  {{
                    scope.row.settings.class_aggregation === 'highest_class' ? translate('Highest Class') : translate('Sum All')
                  }}
                  </span>
              </template>
            </fct-table-column>
            <fct-table-column prop="is_enabled" :label="translate('Enabled')">
              <template #default="scope">
                <Badge :type="scope.row.is_enabled ? 'success' : 'info'">
                  {{ scope.row.is_enabled ? translate('Enabled') : translate('Disabled') }}
                </Badge>
              </template>
            </fct-table-column>
            <fct-table-column :label="translate('Actions')" width="100">
              <template #default="scope">
                <div class="flex items-center gap-1">
                  <IconButton size="small" class="cursor-pointer" hover="primary" bg="transparent"
                               :aria-label="translate('Edit')"
                               @click="editShippingMethod(scope.row)">
                    <DynamicIcon name="Edit"/>
                  </IconButton>
                  <fct-popover confirm
                      :title="translate('Are you sure to delete this shipping method?')"
                      @confirm="deleteShippingMethod(scope.row.id)"
                  >
                    <template #reference>
                      <IconButton size="small" class="cursor-pointer" hover="danger" bg="transparent"
                                   :aria-label="translate('Delete')">
                        <DynamicIcon name="Delete"/>
                      </IconButton>
                    </template>
                  </fct-popover>
                </div>
              </template>
            </fct-table-column>
            <template #empty>
              <div class="no-methods">
                <p>{{
                    translate('Ship_Shipping_Methods')
                  }}</p>
              </div>
            </template>
          </fct-table>
        </div>
      </CardBody>
    </CardContainer>
    <CardContainer v-else>
      <CardBody>
        {{ translate('No Shipping Zone Id provided') }}
      </CardBody>
    </CardContainer>


    <!-- Add this before the closing </div> tag in the template -->
    <fct-dialog
        drawer
        v-model="showMethodModal"
        :title="methodForm.method_id ? translate('Edit Shipping Method') : translate('Add Shipping Method')"
        append-to-body
        width="500px"
        :close-on-click-modal="true"
        class="fct-shipping-method-drawer"
        @open="onMethodDrawerOpen"
    >
      <el-form :model="methodForm" :rules="methodRules" ref="methodFormRef" label-position="top"
               require-asterisk-position="right">

        <el-form-item prop="is_enabled">
          <template #label>
            <span class="el-form-item__label">
              <fct-switch
                  v-model="methodForm.is_enabled"
                  :active-value="1"
                  :inactive-value="0"/>
              {{ translate('Enable Shipping Method') }}
            </span>
          </template>
        </el-form-item>

        <el-form-item :label="translate('Method Title')" prop="title">
          <fct-input v-model="methodForm.title" :placeholder="translate('Enter method title')"/>
        </el-form-item>

        <el-form-item v-if="!isWorldZone && stateZones.length > 0" :label="stateLabel" v-loading="fetchingStateZones">
          <fct-select
              v-model="methodForm.states"
              :options="stateZones.map(zone => ({label: zone.name, value: zone.value}))"
              multiple
              filterable
              clearable
              :placeholder="
                /* translators: %s is the state label */
                translate('Select %s', stateLabel || 'States')
              "
          />
        </el-form-item>

        <div class="fct-shipping-method-grid">
          <div class="fct-shipping-method-col" :class="{'is-half': methodForm.settings.configure_rate != 'per_weight'}">
            <el-form-item :label="translate('Method Type')" prop="type">
            <fct-select
                v-model="methodForm.type"
                :options="methodTypes"
                @change="(value) => {
                  if (value === 'free_shipping') {
                    methodForm.amount = 0;
                    methodForm.settings.configure_rate = '';
                    methodForm.settings.class_aggregation = '';
                  }
                }"/>
          </el-form-item>
          </div>

          <template v-if="methodForm.type !== 'free_shipping'">

            <div v-if="methodForm.settings.configure_rate !== 'per_weight'" class="fct-shipping-method-col is-half">
              <el-form-item :label="translate('Amount')" prop="amount">
                <fct-input type="number" :controls="false" min="0" v-model="methodForm.amount"
                          :placeholder="translate('Enter amount')"/>
              </el-form-item>
            </div>
            <div class="fct-shipping-method-col">
              <el-form-item :label="translate('Configure Rate')">
                <fct-select
                    v-model="methodForm.settings.configure_rate"
                    :options="[
                      {label: translate('Per Order'), value: 'per_order'},
                      {label: translate('Per Item'), value: 'per_item'},
                      {label: translate('Percentage'), value: 'per_price'},
                      {label: translate('Per Weight'), value: 'per_weight'},
                    ]"
                    @change="(value) => {
                  if (value === 'per_weight') {
                    methodForm.amount = 0;
                  }
                }"/>
                <div v-if="methodForm.settings.configure_rate" class="form-help-text mt-1">
                  {{ configureRateHelpText }}
                </div>
              </el-form-item>
            </div>

            <div v-if="methodForm.settings.configure_rate === 'per_weight'" class="fct-shipping-method-col">
              <el-form-item :label="translate('Weight Tiers')">
                <WeightTiersEditor v-model="methodForm.settings.weight_tiers" :weight-unit="storeWeightUnit" />
              </el-form-item>
            </div>

            <div class="fct-shipping-method-col">
              <el-form-item :label="translate('Class Aggregation')">
                <fct-select
                    v-model="methodForm.settings.class_aggregation"
                    :options="[
                      {label: translate('Combined Cost'), value: 'sum_all'},
                      {label: translate('Highest Cost'), value: 'highest_class'},
                    ]"
                />
                <div v-if="methodForm.settings.class_aggregation" class="form-help-text mt-1">
                  {{ classAggregationHelpText }}
                </div>
              </el-form-item>
            </div>
          </template>
          <div class="fct-shipping-method-col">
            <el-form-item :label="translate('Description')">
              <fct-input
                  v-model="methodForm.meta.description" type="textarea" :rows="2"
                        :placeholder="translate('Enter description')"/>
            </el-form-item>
          </div>
          <div class="fct-shipping-method-col">
            <el-form-item :label="translate('Tracking URL Pattern')">
              <fct-input v-model="methodForm.meta.tracking_url_pattern" placeholder="https://"/>
              <div class="form-help-text mt-1">
                {{ translate('Ship_Tracking_Number') }}
              </div>
            </el-form-item>
          </div>
        </div>

      </el-form>

      <!-- Form content remains the same -->
      <template #footer>
        <span class="dialog-footer">
          <fct-button @click="showMethodModal = false">{{ translate('Cancel') }}</fct-button>
          <fct-button v-if="methodForm.method_id" type="primary" @click="updateShippingMethod" :loading="savingMethod">
            {{ translate('Update') }}
          </fct-button>
          <fct-button v-else type="primary" @click="saveShippingMethod" :loading="savingMethod">
            {{ translate('Save') }}
          </fct-button>
        </span>
      </template>
    </fct-dialog>
  </div>
</template>

<style scoped lang="scss">
@import "tailwindcss/theme.css" theme(reference inline);
@config "../../../../tailwind.config.mjs";
.fct-shipping-methods-wrap {
  .fct-card {
    &-header {
      @apply pb-5;
    }

    &-body {
      @apply p-0;
      .no-methods {
        @apply p-5;
        p {
          @apply m-0;
        }
      }
    }
  }
}

</style>
