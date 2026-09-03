<template>
  <div class="fct-license-key-wrap" role="group" :aria-label="translate('License Key')">

    <div class="license-key">
      <span class="block" id="license-key-value">{{ license_key }}</span>
    </div>


    <CopyToClipboard
        :text="license_key"
        :showMode="show_mode"
        :tooltipText="tooltip_text || translate('Copy License Key')"
        :button-text="translate('Copy')"
        :aria-label="translate('Copy license key to clipboard')"
        :aria-describedby="'license-key-value'"
    />
  </div>
</template>

<script>
import CopyToClipboard from "./CopyToClipboard.vue";
import translate from "../../translator/Translator";

export default {
  name: 'LicenseKey',
  components: {
    CopyToClipboard,
  },
  props: {
    license_key: String,
    show_mode: {
      type: String,
      default: 'icon_with_text'
    },
    tooltip_text: {
      type: String,
      default: ''
    }
  },
  methods: {
    translate
  }
}
</script>
