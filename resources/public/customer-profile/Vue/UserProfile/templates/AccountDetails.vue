<template>
  <div class="fct-form-container" role="region" aria-labelledby="account-details-heading">
    <div class="form-left">
      <h3 id="account-details-heading" class="form-heading">{{ translate('Account Details') }}</h3>
      <p :id="accountDetailsDescId" class="form-desc">
        {{ translate('Manage and update your personal information.') }}
      </p>
    </div>

    <div class="form-right">
      <span class="sr-only">{{ translate('Account Details') }}</span>

      <div class="fct-compact-form" id="account-details">
        <MaterialInput 
          :aria-describedby="accountDetailsDescId" 
          :label="translate('First Name')" 
          v-model="profileDetails.first_name"
        />

        <MaterialInput 
          :aria-describedby="accountDetailsDescId" 
          :label="translate('Last Name')" 
          v-model="profileDetails.last_name"
        />

        <MaterialInput 
          :aria-describedby="accountDetailsDescId" 
          :label="translate('Email')" 
          disabled 
          v-model="profileDetails.email"
        />
      </div><!-- .fct-compact-form -->
    </div>
  </div><!-- .fct-form-container -->
</template>
<script>
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import translate from "../../../translator/Translator";

export default {
  name: "AccountDetails.vue",
  props: [
    'profileDetails'
  ],
  components: {MaterialInput},
  data() {
    return {
      accountDetailsDescId: 'account-details-desc'
    }
  },
  methods : {
    translate
  }
}
</script>
