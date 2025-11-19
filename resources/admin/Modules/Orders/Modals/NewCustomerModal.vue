<template>
  <el-dialog
      v-model="customerModalInfos.showModal"
      :title="customerModalInfos.title"
      @close="() => {
        validationErrors = {};
      }"
  >
    <div class="fct-customer-edit-modal">
      <div class="fct-compact-form">
        <MaterialInput
            :label="translate('Full Name *')"
            v-model="editableCustomer.full_name"
            :class="validationErrors['full_name'] ? 'is-error' : ''"
        />

        <MaterialInput
            type="email"
            :label="translate('Email *')"
            v-model="editableCustomer.email"
            :disabled="customer.user_id && customer.user_id != 0"
            :class="validationErrors['email'] ? 'is-error' : ''"
        />
      </div>

      <h4 class="form-heading">{{translate('Address')}}</h4>

      <div class="fct-compact-form fct-customer-address-section">
        <AddressComponent
            v-model="editableCustomer"
            :validationErrors="validationErrors"
        />

        <div v-if="customerModalInfos.action === 'create'" class="mt-5">
          <el-checkbox
              true-value="yes"
              false-value="no"
              v-model="customerModalInfos.manageWpUser"
          >
            <LabelHint :title="getWpUserCheckboxLabel" :content="translate('We will create a new user for this customer. If a user with the same email already exists, we will associate the customer with that existing user.')" />
          </el-checkbox>
        </div>

        <div class="form-error-wrap" v-if="Object.keys(validationErrors).length">
          <p v-for="(msgs, field) in normalizedErrors" :key="field">
            <span v-for="msg in msgs" :key="msg">{{ msg }}</span>
          </p>
        </div>
      </div>

      <div class="dialog-footer">
        <el-button @click="handleCloseModal">
          {{ translate("Cancel") }}
        </el-button>
        <el-button
            v-if="customerModalInfos.action === 'edit'"
            :loading="loading"
            type="primary"
            @click="updateCustomer()"
        >
          {{ getActionButtonLabel }}
        </el-button>
        <el-button
            v-else
            type="primary"
            :loading="loading"
            @click="createCustomer()"
        >
          {{ getActionButtonLabel }}
        </el-button>
      </div>
    </div>
  </el-dialog>
</template>

<script setup>
import {getCurrentInstance, computed, ref, onMounted, watch} from "vue";
import AddressComponent from "@/Bits/Components/Address/AddressComponent.vue";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";

const emit = defineEmits(['close-modal', 'select-new-customer', 'update:customerModal']);

const props = defineProps({
  customer_id: {
    type: [String, Number],
    default: null,
  },
  customer: {
    type: Object,
    default: () => ({}),
  },
  customerModalInfos: {
    type: Object,
    default: () => ({}),
  },
});

const editableCustomer = ref({});
const loading = ref(false);
const validationErrors = ref({});

const handleCloseModal = () => {
  emit("close-modal");
};

const createCustomer = () => {
  loading.value = true;
  editableCustomer.value.wp_user = props.customerModalInfos.manageWpUser;
  Rest.post("customers/", {
    ...editableCustomer.value,
  })
      .then((response) => {
        emit("select-new-customer", response.data);
        emit("update:customerModal", false);
        Notify.success(response.message);
        validationErrors.value = {};
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
          validationErrors.value = errors.data;
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        loading.value = false;
      });
};

const updateCustomer = () => {
  loading.value = true;
  editableCustomer.value.wp_user = props.customerModalInfos.manageWpUser;
  Rest.put("customers/" + props.customer_id, {
    ...editableCustomer.value,
  })
      .then((response) => {
        emit("select-new-customer", response.data);
        emit("update:customerModal", false);
        Notify.success(response.message);
        validationErrors.value = {};
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
          validationErrors.value = errors.data;
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        loading.value = false;
      });
};

// Computed properties
const getWpUserCheckboxLabel = computed(() => {
  return props.customerModalInfos.action === "create"
      ? translate("Create as wordpress user")
      : translate("Update wordpress user profile");
});

const getActionButtonLabel = computed(() => {

  return loading.value
      ? (props.customerModalInfos.action === "edit" ? translate("Updating") : translate("Creating"))
      : (props.customerModalInfos.action === "edit" ? translate("Update Customer") : translate("Create Customer"));
});

const normalizedErrors = computed(() => {
    const result = {};

    for (const field in validationErrors.value) {
      const value = validationErrors.value[field];
      // if value is an object with numeric keys, convert to array
      result[field] = Array.isArray(value) ? value : Object.values(value);
    }

    return result;
});


// Add a watch to keep editableCustomer in sync with props.customer
watch(() => props.customer, (newCustomer) => {
  editableCustomer.value = {
    id: newCustomer.id,
    email: newCustomer.email,
    full_name: newCustomer.full_name,
    state: newCustomer.state,
    city: newCustomer.city,
    country: newCustomer.country,
    postcode: newCustomer.postcode,
    country_name: newCustomer.country_name,
    contact_id: newCustomer.contact_id,
    user_id: newCustomer.user_id,
    wp_user: newCustomer.wp_user,
  };
}, { deep: true });


onMounted(() => {
  // {...props.customer};
  editableCustomer.value = {
    'id': props.customer.id,
    'email': props.customer.email,
    'full_name': props.customer.full_name,
    'state': props.customer.state,
    'city': props.customer.city,
    'country': props.customer.country,
    'postcode': props.customer.postcode,
    'country_name': props.customer.country_name,
    'contact_id': props.customer.contact_id,
    'user_id': props.customer.user_id,
    'wp_user': props.customer.wp_user,
  };
});
</script>

