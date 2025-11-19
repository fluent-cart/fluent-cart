<script setup>
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from '@/Bits/Components/Card/Card.js';
import {computed, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Badge from "@/Bits/Components/Badge.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

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
    description: ''
  }
});
const fetchingStateZones = ref(false);
const stateZones = ref([]);
const isWorldZone = computed(() => props.country === 'all');

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
      class_aggregation: 'sum_all'
    },
    amount: 0,
    is_enabled: 1,
    states: [],
    meta: {
      description: ''
    }
  };
  showMethodModal.value = true;
};


// Add this method after other methods
const saveShippingMethod = () => {

  savingMethod.value = true;
  Rest.post('shipping/methods', methodForm.value)
      .then(response => {
        Notify.success(response.message);
        showMethodModal.value = false;
        emit('fetchShippingMethods');
      })
      .catch(errors => {
        let message = '';
        if (errors.status_code == '422') {
          for (const fieldKey in errors.data) {
            const errorData = errors.data[fieldKey];
            const firstError = Object.values(errorData)[0];
            message += firstError + ' \n';
          }
        }
        if (!message) {
          message = translate('Failed to save shipping method');
        }
        console.error('Error saving shipping method:', errors);
        Notify.error(message);
      })
      .finally(() => {
        savingMethod.value = false;
      });
};

const updateShippingMethod = () => {
  savingMethod.value = true;
  Rest.put('shipping/methods', methodForm.value)
      .then(response => {
        Notify.success(response.message);
        showMethodModal.value = false;
        emit('fetchShippingMethods');
      })
      .catch(error => {
        console.error('Error updating shipping method:', error);
        Notify.error(translate('Failed to update shipping method'));
      })
      .finally(() => {
      });
};

const deleteShippingMethod = (methodId) => {
  Rest.delete('/shipping/methods/' + methodId)
      .then(response => {
        Notify.success(response.message);
        emit('fetchShippingMethods');
      })
      .catch(error => {
        console.error('Error deleting shipping method:', error);
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
    settings: row.settings || {
      configure_rate: 'per_item',
      class_aggregation: 'sum_all'
    },
    amount: row.amount,
    is_enabled: row.is_enabled ? 1 : 0,
    states: row.states,
    meta: {
      description: row.meta?.description || ''
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
    return translate('Per Price');
  }
};

const fetchStateZones = () => {
  if (!props.country || props.country === 'all') {
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
        console.error('Error fetching state zones:', error);
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
          <el-button type="primary" @click="addShippingMethod">
            {{ translate('Add Shipping Method') }}
          </el-button>
        </template>
      </CardHeader>
      <CardBody>
        <div class="shipping-methods-list">
          <!-- Shipping Methods -->
          <el-table :data="methods" class="w-full">
            <el-table-column prop="title" :label="translate('Method Title')"/>
            <el-table-column :label="translate('States')">
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
            </el-table-column>
            <el-table-column prop="type" :label="translate('Method Type')">
              <template #default="scope">
                {{ methodTypes.find(type => type.value === scope.row.type).label }}
              </template>
            </el-table-column>
            <el-table-column prop="amount" :label="translate('Amount')" width="100px">
              <template #default="scope">
                <span v-if="scope.row.type === 'free_shipping'">
                  {{ translate('Free') }}
                </span>
                <span v-else>
                  {{ scope.row.amount }}
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="is_enabled" :label="translate('Enabled')">
              <template #default="scope">
                <Badge :type="scope.row.is_enabled ? 'success' : 'info'">
                  {{ scope.row.is_enabled ? translate('Enabled') : translate('Disabled') }}
                </Badge>
              </template>
            </el-table-column>
            <el-table-column prop="configure_rate" :label="translate('Configure Rate')">
              <template #default="scope">
                <span v-if="scope.row.type === 'free_shipping'">
                  --
                </span>
                <span v-else>
                  {{ getConfigureTitle(scope.row.settings.configure_rate) }}
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="class_aggregation" :label="translate('Class Aggregation')" width="200px">
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
            </el-table-column>
            <el-table-column :label="translate('Actions')" width="100">
              <template #default="scope">
                <div class="flex items-center gap-1">
                  <icon-button size="small" class="cursor-pointer" hover="primary" bg="transparent"
                               @click="editShippingMethod(scope.row)">
                    <DynamicIcon name="Edit"/>
                  </icon-button>
                  <el-popconfirm
                      :title="translate('Are you sure to delete this shipping method?')"
                      @confirm="deleteShippingMethod(scope.row.id)"
                  >
                    <template #reference>
                      <icon-button size="small" class="cursor-pointer" hover="danger" bg="transparent">
                        <DynamicIcon name="Delete"/>
                      </icon-button>
                    </template>
                  </el-popconfirm>
                </div>
              </template>
            </el-table-column>
            <template #empty>
              <div class="no-methods">
                <p>{{
                    translate('No shipping methods found for this zone. Add a shipping method to define how products are shipped in this zone.')
                  }}</p>
              </div>
            </template>
          </el-table>
        </div>
      </CardBody>
    </CardContainer>
    <CardContainer v-else>
      <CardBody>
        {{ translate('No Shipping Zone Id provided') }}
      </CardBody>
    </CardContainer>


    <!-- Add this before the closing </div> tag in the template -->
    <el-drawer
        v-model="showMethodModal"
        :title="translate('Add Shipping Method')"
        width="500px"
        append-to-body
        :close-on-click-modal="true"
        class="fct-shipping-method-drawer"
        @open="fetchStateZones"
    >
      <el-form :model="methodForm" :rules="methodRules" ref="methodFormRef" label-position="top"
               require-asterisk-position="right">

        <el-form-item prop="is_enabled">
          <template #label>
            <span class="el-form-item__label">
              <el-switch
                  v-model="methodForm.is_enabled"
                  :active-value="1"
                  :inactive-value="0"/>
              {{ translate('Enable Shipping Method') }}
            </span>
          </template>
        </el-form-item>

        <el-form-item :label="translate('Method Title')" prop="title">
          <el-input v-model="methodForm.title" :placeholder="translate('Enter method title')"></el-input>
        </el-form-item>

        <el-form-item v-if="!isWorldZone && stateZones.length > 0" :label="stateLabel" v-loading="fetchingStateZones">
          <el-select
              v-model="methodForm.states"
              multiple
              filterable
              clearable
              :placeholder="
                /* translators: %s is the state label */
                translate('Select %s', stateLabel || 'States')
              "
          >
            <el-option
                v-for="(zone, i) in stateZones"
                :key="i"
                :label="zone.name"
                :value="zone.value"
            />
          </el-select>
        </el-form-item>

        <el-row :gutter="12">
          <el-col :lg="12">
            <el-form-item :label="translate('Method Type')" prop="type">
            <el-select
                v-model="methodForm.type"
                @change="(value) => {
                  if (value === 'free_shipping') {
                    methodForm.amount = 0;
                    methodForm.settings.configure_rate = '';
                    methodForm.settings.class_aggregation = '';
                  }
                }">
              <el-option v-for="type in methodTypes" :key="type.value" :label="type.label" :value="type.value"/>
            </el-select>
          </el-form-item>
          </el-col>

          <template v-if="methodForm.type !== 'free_shipping'">

            <el-col :lg="12">
              <el-form-item :label="translate('Amount')" prop="amount">
                <el-input type="number" min="0" v-model="methodForm.amount"
                          :placeholder="translate('Enter amount')"></el-input>
              </el-form-item>
            </el-col>
            <el-col :lg="12">
              <el-form-item :label="translate('Configure Rate')">
                <el-select v-model="methodForm.settings.configure_rate">
                  <el-option :label="translate('Per Order')" value="per_order"></el-option>
                  <el-option :label="translate('Per Item')" value="per_item"></el-option>
                  <el-option :label="translate('Percentage')" value="per_price"></el-option>
                </el-select>
                <div v-if="methodForm.settings.configure_rate" class="form-help-text mt-1">
                  {{ configureRateHelpText }}
                </div>
              </el-form-item>
            </el-col>

            <el-col :lg="12">
              <el-form-item :label="translate('Class Aggregation')">
                <el-select v-model="methodForm.settings.class_aggregation">
                  <el-option :label="translate('Combined Cost')" value="sum_all"></el-option>
                  <el-option :label="translate('Highest Cost')" value="highest_class"></el-option>
                </el-select>
                <div v-if="methodForm.settings.class_aggregation" class="form-help-text mt-1">
                  {{ classAggregationHelpText }}
                </div>
              </el-form-item>
            </el-col>
          </template>
          <el-col :lg="24">
<!--            add description input-->
            <el-form-item :label="translate('Description')">
              <el-input
                  v-model="methodForm.meta.description" type="textarea" :rows="2"
                        :placeholder="translate('Enter description')"></el-input>
            </el-form-item>
          </el-col>
        </el-row>

      </el-form>

      <!-- Form content remains the same -->
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="showMethodModal = false">{{ translate('Cancel') }}</el-button>
          <el-button v-if="methodForm.method_id" type="primary" @click="updateShippingMethod" :loading="savingMethod">
            {{ translate('Update') }}
          </el-button>
          <el-button v-else type="primary" @click="saveShippingMethod" :loading="savingMethod">
            {{ translate('Save') }}
          </el-button>
        </span>
      </template>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
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
