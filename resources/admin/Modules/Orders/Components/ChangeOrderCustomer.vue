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
const localizedData = window.fluentCartAdminApp || {};

const editableCustomer = ref({
  id: '',
  email: '',
  full_name: '',
  first_name: '',
  last_name: '',
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
        Notify.error(errors.data?.message);
      })
      .finally(() => {
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
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
          validationErrors.value = errors.data;
        } else {
          Notify.error(errors.data?.message);
        }
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
      <fct-button text style="margin-top: 0;">
        <DynamicIcon name="ArrowLeft" class="fct-address-back-icon"/>
        {{ translate('Back') }}
      </fct-button>
    </div>

    <div v-if="!showCreateCustomerForm" class="fct-form-group">
      <label class="label">
        {{ translate('Select Customer') }}
        <fct-button text class="fct-create-customer-btn" @click.prevent="handleShowCreateCustomerForm">
          <DynamicIcon name="Plus"/>
          {{ translate('Add New Customer') }}
        </fct-button>
      </label>
      <div style="display: flex; align-items: center; gap: 10px;">
        <fct-select
            v-model="selectedUser"
            :remote="true"
            :remote-method="searchUsers"
            :loading="loadingUsers"
            filterable
            clearable
            :placeholder="translate('Type to search user')"
            style="width: 100%"
            :options="customers.map((customer) => ({label: customer.full_name, value: customer.id, email: customer.email}))"
        >
          <template #option="{option}">
            <div class="select-use-option">
              <span>{{ option.label }}</span>
              <span>{{ option.email }}</span>
            </div>
          </template>
        </fct-select>
      </div>
    </div>

    <template v-if="showCreateCustomerForm">
      <div class="fct-compact-form">
        <MaterialInput
            v-if="localizedData.is_full_name_required"
            :label="translate('Full Name *')"
            v-model="editableCustomer.full_name"
            :class="validationErrors['full_name'] ? 'is-error' : ''"
        />

        <template v-else>
          <MaterialInput
              :label="translate('First Name *')"
              v-model="editableCustomer.first_name"
              :class="validationErrors['first_name'] ? 'is-error' : ''"
          />

          <MaterialInput
              :label="translate('Last Name')"
              v-model="editableCustomer.last_name"
              :class="validationErrors['last_name'] ? 'is-error' : ''"
          />
        </template>

        <MaterialInput
            :label="translate('Email *')"
            v-model="editableCustomer.email"
            :class="validationErrors['email'] ? 'is-error' : ''"
        />
      </div>

      <h4 class="form-heading">{{translate('Address')}}</h4>

      <div class="fct-compact-form fct-customer-address-section">
        <AddressComponent
            v-model="editableCustomer"
        />

        <div style="margin-top: 20px">
          <fct-choice
              true-value="yes"
              false-value="no"
              v-model="editableCustomer.wp_user"
          >
            <LabelHint :title="translate('Create as wordpress user')"
                       :content="translate('Order_Create_New')"/>
          </fct-choice>
        </div>

        <div class="form-error-wrap">
          <p v-for="error in validationErrors" class="form-error-text">{{ error.required }}</p>
        </div>

      </div>

      <div class="dialog-footer">
        <fct-button @click="$emit('close')">
          {{ translate("Cancel") }}
        </fct-button>
        <fct-button
            type="primary"
            @click="createCustomer"
        >
          {{ translate('Create & Attach Customer') }}
        </fct-button>
      </div>
    </template>

    <div v-if="!showCreateCustomerForm" class="dialog-footer">
      <fct-button type="primary" @click="changeCustomer">
        {{ translate('Create & Attach Customer') }}
      </fct-button>
    </div>
  </div>
</template>
