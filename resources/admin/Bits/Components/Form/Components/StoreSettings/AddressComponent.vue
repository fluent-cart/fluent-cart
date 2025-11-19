<script setup>
import {computed, defineModel, onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import MaterialSelect from "@/Bits/Components/MaterialSelect.vue";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";


const props = defineProps({
  name: {
    type: String,
    required: true
  },

  field: {
    type: Object
  },

  fieldKey: {
    type: String
  },
  value: {
    required: true
  },
  variant: {
    type: String
  },
  nesting: {
    type: Boolean,
    default: false
  },
  statePath: {
    type: String
  },
  form: {
    type: Object,
    required: true
  },
  callback: {
    type: Function,
    required: true
  },
  label: {
    type: String
  },
  attribute: {
    required: true
  }
})

const countries = ref([]);

const fetchCountries = () => {
  Rest.get("address-info/countries")
      .then((response) => {
        countries.value = response.data;
      })
      .catch((errors) => {
      })
      .finally(() => {
      });
}

const cachedStates = ref({});

const loadingState = ref(false);

const isStatesAreOption = ref(true);
const shouldShowStateInput = ref(true);
const zipLabel = ref(translate('Zip Code'));
const stateLabel = ref(translate('State'));

const fetchStates = (countryCode) => {
  //props.form.data.values.store_state = '';
  loadingState.value = true;
  if (!countryCode) {
    loadingState.value = false;
    return;
  }
  if (cachedStates.value[countryCode]) {
    afterCountryInfoRetrieved(cachedStates.value[countryCode]);
    loadingState.value = false;
    return;
  }

  Rest.get("address-info/get-country-info", {
    country_code: countryCode
  })
      .then((response) => {
        cachedStates.value[countryCode] = response;
        afterCountryInfoRetrieved(response);
      })
      .catch((errors) => {
        console.log(errors, 'errors');
      })
      .finally(() => {
        loadingState.value = false;
      });
}


const currentStates = ref([]);
const afterCountryInfoRetrieved = (response) => {
  currentStates.value = response?.states || [];
  shouldShowStateInput.value = response?.address_locale?.state?.hidden !== true;
  isStatesAreOption.value = currentStates.value.length > 1;
  zipLabel.value = response?.address_locale?.postcode?.label || translate('Zip Code');
  stateLabel.value = response?.address_locale?.state?.label || translate('State');

}

onMounted(() => {
  fetchCountries();
  if (props.form.data.values.store_country) {
    fetchStates(props.form.data.values.store_country);
  }
})

</script>

<template>

  <div class="fct-global-address-component">
    <div class="setting-fields-inner country">
      <MaterialSelect
          v-model="form.data.values.store_country"
          :label="translate('Select Country')"
          filterable
          :options="countries"
          @onChange="(countryCode)=>{
          form.data.values.store_state = '';
          fetchStates(countryCode);
        }"
      />
    </div>

    <div v-if="!loadingState && shouldShowStateInput">
      <MaterialSelect
          v-if="isStatesAreOption"
          v-model="form.data.values.store_state"
          :label="stateLabel"
          filterable
          :options="currentStates"
      />
      <MaterialInput id="store_state" v-else v-model="form.data.values.store_state" :label="stateLabel"/>
    </div>
    <MaterialInput v-model="form.data.values.store_address1" :label="translate('Street address')"/>
    <MaterialInput v-model="form.data.values.store_address2" :label="translate('Apt, suite, unit')"/>
    <div class="grid lg:grid-cols-2">
      <div class="setting-fields-inner city">
        <MaterialInput v-model="form.data.values.store_city" :label="translate('City/Town')"/>
      </div>
      <div class="setting-fields-inner postcode">
        <MaterialInput v-model="form.data.values.store_postcode" :label="zipLabel"/>
      </div>
    </div>

    <div v-if="form.data.validationErrors.hasOwnProperty('store_country')">
      <span class="fluent-cart-admin-validation-error" v-for="(error, errorName) in form.data.validationErrors"
            :key="error">
        <span> {{Object.values(error)[0]}}</span>
      </span>
    </div>

  </div>

</template>
