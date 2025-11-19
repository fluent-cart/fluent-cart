<script setup>

import Rest from "@/utils/http/Rest";
import {ref, watch} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import translate from "@/utils/translator/Translator";
import AddressComponent from "@/Bits/Components/Address/AddressComponent.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Notify from "@/utils/Notify";

const props = defineProps({
  orderId: {
    type: [String, Number],
    default: null,
    required: true
  }
});


const selectedUser = ref(null);
const customers = ref([]);
const loadingUsers = ref(false);
const showCreateCustomerForm = ref(false);
const validationErrors = ref({});

const editableCustomer = ref({
  id: '',
  email: '',
  full_name: '',
  state: '',
  city: '',
  country: '',
  postcode: '',
  country_name: '',
  contact_id: '',
  user_id: '',
  wp_user: '',
});


const searchUsers = (query) => {

  if (!query) {
    customers.value = [];
    return;
  }
  Rest.get('customers/', {search: query}).then(response => {
    customers.value = response.customers.data;
  }).finally(() => {

  });
}
const changeCustomer = () => {
  if (!selectedUser.value) {
    Notify.error(translate('Please select a customer'));
    return;
  }
  Rest.post('orders/' + props.orderId + '/change-customer', {
    customer_id: selectedUser.value,
  })
      .then((response) => {
        Notify.success(response.message);
        setTimeout(() => {
          window.location.reload();
        }, 300);
      })
      .catch((errors) => {
        if (errors.message) {
          return Notify.error(errors.message);
        }
        console.log('errors', errors);
      })
      .finally(() => {
        console.log('finally');
      });
}

const createCustomer = () => {
  Rest.post('orders/' + props.orderId + '/create-and-change-customer', editableCustomer.value)
      .then((response) => {
        Notify.success(response.message);
        setTimeout(() => {
          window.location.reload();
        }, 300);
        validationErrors.value = {};
        showCreateCustomerForm.value = false;
      })
      .catch((errors) => {
        if (errors.message) {
          return Notify.error(errors.message);
        }
        validationErrors.value = errors;
      })
      .finally(() => {
      });
}

const handleShowCreateCustomerForm = () => {
  showCreateCustomerForm.value = true;
}

const handleBack = () => {
  showCreateCustomerForm.value = false;
}


</script>

<template>
  <div class="fct-customer-edit-modal">

    <div v-if="showCreateCustomerForm" @click="handleBack" style="margin-bottom: 10px">
      <el-button text style="margin-top: 0;">
        <DynamicIcon name="ArrowLeft"/>
        {{ translate('Back') }}
      </el-button>
    </div>

    <div v-if="!showCreateCustomerForm" class="fct-form-group">
      <label class="label">
        {{ translate('Select Customer') }}
        <el-button text class="fct-create-customer-btn" @click.prevent="handleShowCreateCustomerForm">
          <DynamicIcon name="Plus"/>
          {{ translate('Add New Customer') }}
        </el-button>
      </label>
      <div style="display: flex; align-items: center; gap: 10px;">
        <el-select
            v-model="selectedUser"
            :remote="true"
            :remote-method="searchUsers"
            :loading="loadingUsers"
            filterable
            clearable
            :placeholder="translate('Type to search user')"
            style="width: 100%"

        >
          <el-option
              v-for="customer in customers"
              :key="customer.id"
              :label="customer.full_name"
              :value="customer.id"
          >
            <div class="select-use-option">
              <span>{{ customer.full_name }}</span>
              <span>{{ customer.email }}</span>
            </div>
          </el-option>
        </el-select>
      </div>
    </div>

    <template v-if="showCreateCustomerForm">
      <div class="fct-compact-form">
        <MaterialInput
            :label="translate('Full Name')"
            v-model="editableCustomer.full_name"
        />

        <MaterialInput
            :label="translate('Email')"
            v-model="editableCustomer.email"
        />
      </div>

      <h4 class="form-heading">{{translate('Address')}}</h4>

      <div class="fct-compact-form fct-customer-address-section">
        <AddressComponent
            v-model="editableCustomer"
        />

        <div style="margin-top: 20px">
          <el-checkbox
              true-value="yes"
              false-value="no"
              v-model="editableCustomer.wp_user"
          >
            <LabelHint :title="translate('Create as wordpress user')"
                       :content="translate('We will create a new user for this customer. If a user with the same email already exists, we will associate the customer with that existing user.')"/>
          </el-checkbox>
        </div>

        <div class="form-error-wrap">
          <p v-for="error in validationErrors" class="form-error-text">{{ error.required }}</p>
        </div>

      </div>

      <div class="dialog-footer">
        <el-button @click="$emit('close')">
          {{ translate("Cancel") }}
        </el-button>
        <el-button
            type="primary"
            @click="createCustomer"
        >
          {{ translate('Create & Attach Customer') }}
        </el-button>
      </div>
    </template>

    <div v-if="!showCreateCustomerForm" class="dialog-footer">
      <el-button type="primary" @click="changeCustomer">
        {{ translate('Create & Attach Customer') }}
      </el-button>
    </div>
  </div>
</template>
