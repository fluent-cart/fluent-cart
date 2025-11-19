<script setup>
import {computed, defineModel, onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import translate from "../../translator/Translator";
import Animation from "@/Bits/Components/Animation.vue";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import MaterialSelect from "@/Bits/Components/MaterialSelect.vue";

const props = defineProps({
  use_additional_address_fields: {
    type: Boolean,
    required: false,
    default: false
  },
  validationErrors: {
    type: Object,
    default: () => ({})
  }
})

const model = defineModel();
const countries = ref([]);

const fetchCountries = () => {
  Rest.get("address-info/countries")
      .then((response) => {
        countries.value = response.data;
      })
      .catch((errors) => {
        console.log(errors, 'errors');
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
  model.state = '';
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

// add watch on model.value.country
watch(() => model.value.country, (newVal, oldVal) => {
  fetchStates(newVal);
});

onMounted(() => {
  fetchCountries();
  setTimeout(() => {
    if (model.value.country) {
      fetchStates(model.value.country);
    }
  }, 400);
})

</script>

<template>
  <div class="fct-global-address-component">
    <MaterialSelect
        :class="validationErrors.hasOwnProperty('country') ? 'is-error' : ''"
        required
        :options="countries"
        v-model="model.country"
        filterable
        @onChange="(countryCode)=>{
          model.state = '';
          fetchStates(countryCode);
        }"
        :clearable="true"
        :label="translate('Select Country')"
    />

    <Animation :visible="!loadingState && shouldShowStateInput" accordion class="address_locale">
      <MaterialSelect
          filterable
          v-if="isStatesAreOption"
          v-model="model.state"
          :options="currentStates"
          :label="translate(stateLabel)"
          :clearable="true"
      />
      <MaterialInput v-else v-model="model.state" :label="translate(stateLabel)"/>
    </Animation>

    <template v-if="use_additional_address_fields">
      <MaterialInput
          v-model="model.address_1"
          :class="validationErrors.hasOwnProperty('address_1') ? 'is-error' : ''"
          :label="translate('Street address')"/>
      <MaterialInput v-model="model.address_2" :label="translate('Apt, suite, unit')"/>
    </template>


    <div class="fct-global-address-component-row">
      <div class="city">
        <MaterialInput
            v-model="model.city"
            :class="validationErrors.hasOwnProperty('city') ? 'is-error' : ''"
            :label="translate('City/Town')"/>
      </div>
      <div class="postcode">
        <MaterialInput
            :class="validationErrors.hasOwnProperty('postcode') ? 'is-error' : ''"
            required
            v-model="model.postcode"
            :label="translate(zipLabel)"
        />
      </div>
    </div>

  </div>

</template>

<style lang="scss">
.fct-compact-form .fct-global-address-component {
  .setting-fields-inner.city .el-input .el-input__wrapper {
    border-top-left-radius: 0 !important;
    border-top-right-radius: 0 !important;
  }
}
</style>
