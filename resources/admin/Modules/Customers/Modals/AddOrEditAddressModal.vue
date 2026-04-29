<template>
  <div class="fct_edit_customer_popup">

    <el-form label-position="top" class="fct-compact-form" require-asterisk-position="right">
      <MaterialInput
          :label="translate('Address Label')"
          :class="validationErrors.hasOwnProperty('label') ? 'is-error' : ''"
          v-model="editableAddress.label"
      />

      <MaterialInput
          :label="translate('Name')"
          required
          :class="validationErrors.hasOwnProperty('name') ? 'is-error' : ''"
          v-model="editableAddress.name"
      />

      <MaterialInput
          :label="translate('Email')"
          required
          :class="validationErrors.hasOwnProperty('email') ? 'is-error' : ''"
          v-model="editableAddress.email"
      />

      <MaterialInput
          :label="translate('Company Name')"
          required
          :class="validationErrors.hasOwnProperty('company_name') ? 'is-error' : ''"
          v-model="editableAddress.company_name"
      />

      <MaterialInput
          :label="translate('Phone')"
          required
          :class="validationErrors.hasOwnProperty('phone') ? 'is-error' : ''"
          v-model="editableAddress.phone"
      />


      <AddressComponent
          use_additional_address_fields
          v-model="editableAddress"
          :validationErrors="validationErrors"
      />

    </el-form>

    <div class="form-error-wrap">
      <p class="form-error-text" v-html="hasError(validationErrors)"></p>
    </div>

    <div class="dialog-footer" >
      <el-checkbox

          v-model="shouldSetAsAlso"
          v-if="showSetAsAlsoCheckbox && false"
          style="margin-right: 16px"
      >
        {{ translate("Set as") }}
        {{
          modalAction.type === "billing"
              ? translate("shipping address also")
              : translate("billing address also")
        }}
      </el-checkbox>
      <el-button
          v-if="address.id"
          type="primary"
          @click="updateAddress()"
          :loading="updatingAddress"
          :disabled="updatingAddress"
      >
        {{ translate("Update Address") }}
      </el-button>
      <el-button
          v-else
          type="primary"
          @click="addAddress()"
          :loading="addingAddress"
          :disabled="addingAddress"
      >
        {{ translate("Add Address") }}
      </el-button>
    </div>
  </div>
</template>

<script setup>
import {ref, getCurrentInstance, watch} from "vue";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import translate from "@/utils/translator/Translator";
import AddressComponent from "@/Bits/Components/Address/AddressComponent.vue";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";

// Props
const props = defineProps([
  "customer_id",
  "modalAction",
  "address",
  "showAddressModal",
  "order_id",
  "customerAddressModalInfos",
  "showSetAsAlsoCheckbox",
  "closing_modal"
]);

// Emits
const emit = defineEmits(["close-modal", "update:refresh-address"]);

// Reactive data
const billingAddress = ref([]);
const loading = ref(false);
const validationErrors = ref({});
const addingAddress = ref(false);
const updatingAddress = ref(false);
const shouldSetAsAlso = ref(false);
const focusedField = ref(null);
const editableAddress = ref({
  ...(props.address ?? {})
});

// Get current instance for accessing context
const selfRef = getCurrentInstance().ctx;

// Setup save shortcut
const saveShortcut = useSaveShortcut();
saveShortcut.onSave(() => {
  if (
      props.showAddressModal &&
      ["billing", "shipping"].includes(props.modalAction.type)
  ) {
    if (props.modalAction.action === "edit" && selfRef.address.id !== "") {
      updateAddress();
    } else if (props.modalAction.action === "create") {
      addAddress();
    }
  }
});

// Methods
const setFocus = (field) => {
  focusedField.value = field;
};

const clearFocus = () => {
  focusedField.value = null;
};

const getFocusClass = (field) => {
  return {
    'is-focused': focusedField.value === field || (props.address && props.address[field])
  };
};

const handleCloseModal = () => {
  emit("close-modal");
};


const hasError = (errors) => {
  let message = '';
  for (const fieldKey in errors) {
    const errorData = errors[fieldKey];
    const firstError = Object.values(errorData);
    message += firstError + ' </br>';
  }
  return message;
};

const addAddress = () => {
  addingAddress.value = true;
  const getAddress = editableAddress.value;
  let others = {
    type: props.modalAction.type,
    customer_id: props.customer_id,
    order_id: props.order_id ?? null,
  };
  let address = {...getAddress, ...others};
  address['sync'] = shouldSetAsAlso.value

  Rest.post("customers/" + props.customer_id + "/address", {
    ...address,
  })
      .then((response) => {
        emit("update:refresh-address", false, response.data);
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
        addingAddress.value = false;
      });
};

const updateAddress = () => {
  updatingAddress.value = true;
  const getAddress = props.address;
  let others = {
    type: props.modalAction.type,
    customer_id: props.customer_id,
    is_primary: props.address.is_primary,
    id: props.address.id,
    order_id:
        props.order_id && props.address.is_primary == 1 ? props.order_id : null,
  };
  let address = {...others, ...editableAddress.value};

  Rest.put("customers/" + props.customer_id + "/address", {
    ...address,
  })
      .then((response) => {
        if (shouldSetAsAlso.value) {
          //setAsAlso();
        }
        emit("update:refresh-address", false, response.data);
        Notify.success(response.message);
        validationErrors.value = {};
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
          validationErrors.value = errors;
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        loading.value = false;
        updatingAddress.value = false;
      });
};

const getAddresses = (type = "billing") => {
  loading.value = true;
  Rest.get("customers/" + props.customer_id + "/address", {
    type: type,
  })
      .then((addresses) => {
        billingAddress.value = addresses;
        // Note: This line seems incorrect in the original as it overwraps props
        // address.value = addresses;
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        loading.value = false;
      });
};

const setAsAlso = () => {
  const addressType = props.modalAction.type === "billing" ? "shipping" : "billing";

  Rest.post("customers/" + props.customer_id + "/address", {
    type: addressType,
    ...props.address,
    ...editableAddress,
  })
      .then((response) => {
        Notify.success(response.message);
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
          validationErrors.value = errors;
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        loading.value = false;
        addingAddress.value = false;
      });
};


watch(() => props.closing_modal, (newVal, oldVal) => {
  if (newVal) {
    validationErrors.value = {};
  }
});

// Define component name for debugging
defineOptions({
  name: "AddOrEditAddressModal"
});
</script>
