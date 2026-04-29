<template>
  <el-button size="small" @click="handleAddNewAddressModal()" :aria-label="translate('Add New Address')"
      aria-haspopup="dialog">
    {{ translate('Add New Address') }}
  </el-button>
  <el-dialog
      v-model="addNewAddressModalVisible"
      :append-to-body="true"
      :title="getModalTitle()"
      @closed="resetFormDataAndValidation"
      class="fluent-cart-customer-profile-app fct-customer-root-container"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="addressModalTitleId"
      :aria-describedby="addressFormDescId"
      tabindex="-1"
  >

    <div class="fct-customer-dashboard-add-address-form-wrap">
      <h2 :id="addressModalTitleId" class="sr-only">{{ getModalTitle() }}</h2>

      <form class="fct-compact-form">
        <MaterialInput
            v-for="(field, index) in formFields" :key="index"
            :required="field.required"
            :label="field.label"
            v-model="localAddressData[field.key]"
            :class="validationErrors[field.key] ? 'is-error' : ''"
            :aria-invalid="!!validationErrors[field.key]"
            :aria-describedby="validationErrors[field.key] ? `${field.key}-error` : null"
        />

        <AddressComponent
            use_additional_address_fields
            v-model="localAddressData"
            :validationErrors="validationErrors"
        />
      </form><!-- .fct-compact-form -->

      <div id="addressFormDescId" class="sr-only">
        {{ translate('Form to add or edit address. Required fields are marked with an asterisk.') }}
      </div>

      <div 
          class="form-error-wrap" 
          v-if="Object.keys(validationErrors).length"
          role="alert"
          aria-live="assertive"
          aria-atomic="true"
      >
        <p v-for="error in validationErrors">{{ error.required || error.email || error.max }}</p>
      </div>

    </div>

    <template #footer>
      <div class="dialog-footer">
        <el-button type="info" soft @click="addNewAddressModalVisible = false" :aria-label="translate('Cancel')">
          {{ translate('Cancel') }}
        </el-button>
        <el-button v-if="localIsEditAddress" type="primary" :loading="addAddressLoading" @click="editAddress" :aria-label="translate('Update Address')">
          {{ translate('Update Address') }}
        </el-button>
        <el-button v-else type="primary" :loading="addAddressLoading" @click="addAddress" :aria-label="translate('Add Address')">
          {{ translate('Add Address') }}
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>
<script>
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import AddressComponent from "../../parts/AddressComponent.vue";
import translate from "../../../translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Notify from "@/utils/Notify";
export default {
  name: "AddressModal",
  props: [
    'modalAction',
    'newAddressData',
    'showModal',
    'profileDetails',
    'isEditAddress'
  ],
  emits: ['fetch'],
  components: {DynamicIcon, AddressComponent, MaterialInput},
  data() {
    return {
      addressModalTitleId: 'address-modal-title',
      validationErrors: {},
      addAddressLoading: false,
      addNewAddressModalVisible: false,
      localIsEditAddress: false, // Add local property
      localAddressData: {
        label: '',
        name: '',
        email: '',
        company_name: '',
        phone: '',
        address_1: '',
        address_2: '',
        city: '',
        state: '',
        postcode: '',
        country: ''
      },
      formRules: {
        name: [translate('Name is required.')],
        email: [translate('Email is required.')],
        country: [translate('Country is required.')],
        postcode: [translate('Postcode is required.')],
      },
      formFields: [
        {
          key: 'label',
          label: translate('Address label e.g Home, Office'),
          required: false
        },
        {
          key: 'name',
          label: translate('Name'),
          required: true
        },
        {
          key: 'email',
          label: translate('Email'),
          required: true
        },
        {
          key: 'company_name',
          label: translate('Company Name'),
          required: false
        },
        {
          key: 'phone',
          label: translate('Phone'),
          required: false
        }
      ],
    }
  },
  watch: {
    showModal() {
      this.addNewAddressModalVisible = this.showModal;
    },
    newAddressData: {
      handler(newVal) {
        if (newVal && this.isEditAddress) {
          this.localAddressData = {...newVal};
        }
      },
      deep: true,
      immediate: true
    },
    isEditAddress: {
      handler(newVal) {
        if (!newVal) {
          // Reset form when switching from edit to add mode
          this.resetFormDataAndValidation();
        }
      },
      immediate: true
    }
  },
  methods: {
    translate,
    validateForm() {
      const errors = {};
      Object.keys(this.formRules).forEach((key) => {
        if (!this.localAddressData[key] || this.localAddressData[key].trim() === '') {
          errors[key] = {required: this.formRules[key][0]};
        }
      });
      this.validationErrors = errors;
      return Object.keys(errors).length === 0;
    },
    addAddress() {
      if (this.validateForm()) {
        this.addAddressLoading = true;
        let additionalAddressData = {
          type: this.modalAction,
          customer_id: this.profileDetails.id,
        };

        let completeAddress = {...this.localAddressData, ...additionalAddressData};

        this.$post('customer-profile/create-address', {
          type: completeAddress.type,
          label: completeAddress.label,
          name: completeAddress.name,
          phone: completeAddress.phone,
          email: completeAddress.email,
          country: completeAddress.country,
          state: completeAddress.state,
          address_1: completeAddress.address_1,
          address_2: completeAddress.address_2,
          city: completeAddress.city,
          postcode: completeAddress.postcode,
          company_name: completeAddress.company_name
        }).then(response => {
          Notify.success(response);
          this.closeModal();
          this.addAddressLoading = false;
          this.resetFormDataAndValidation();
          this.$emit('fetch');
        }).catch((errors) => {
          if (errors.status_code == '422') {
            Notify.validationErrors(errors);
          } else {
            Notify.error(errors?.data);
          }
          this.validationErrors = errors?.data;
        }).finally(() => {
          this.addAddressLoading = false;
        });
      } else {
        Notify.error(translate('Please fill in all the required fields.'));
      }
    },
    editAddress() {
      if (this.validateForm()) {
        this.addAddressLoading = true;
        let completeAddress = {...this.localAddressData};
        let customer_id = completeAddress.customer_id;

        this.$post(`customer-profile/edit-address`, {
          id: completeAddress.id,
          label: completeAddress.label,
          name: completeAddress.name,
          phone: completeAddress.phone,
          email: completeAddress.email,
          country: completeAddress.country,
          state: completeAddress.state,
          address_1: completeAddress.address_1,
          address_2: completeAddress.address_2,
          city: completeAddress.city,
          postcode: completeAddress.postcode,
          type: completeAddress.type,
          company_name: completeAddress.company_name
        }).then(response => {
          Notify.success(response);
          this.closeModal();
          this.addAddressLoading = false;
          this.resetFormDataAndValidation();
          this.$emit('fetch');
        }).catch((errors) => {
          Notify.error(errors);
        }).finally(() => {
          this.addAddressLoading = false;
        });
      } else {
        Notify.error(translate('Please fill in all the required fields.'));
      }
    },
    openModal() {
      this.addNewAddressModalVisible = true;
    },
    closeModal() {
      this.addNewAddressModalVisible = false;
    },
    getModalTitle() {
      if (this.localIsEditAddress) {
        return translate('Edit address');
      } else if (this.modalAction === 'billing') {
        return translate('Add new billing address');
      } else if (this.modalAction === 'shipping') {
        return translate('Add new shipping address');
      } else {
        return translate('Add new address');
      }
    },
    handleAddNewAddressModal(type, address = null) {
      if (type === 'edit' && address) {
        this.localAddressData = {...address};
        this.localIsEditAddress = true;
      } else {
        this.resetFormDataAndValidation();
        this.localIsEditAddress = false;
      }

      this.addNewAddressModalVisible = true;
    },
    resetFormDataAndValidation() {
      Object.keys(this.localAddressData).forEach(key => {
        this.localAddressData[key] = '';
      });
      this.validationErrors = {};
    }
  }
}
</script>
