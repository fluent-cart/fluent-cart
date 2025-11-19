<template>
  <div class="fct-license-key-wrap" role="group" :aria-label="translate('License Key')">

    <div class="license-key">
      <span class="block" id="license-key-value">{{ license_key }}</span>
    </div>


    <CopyToClipboard
        :text="license_key"
        :showMode="show_mode"
        :tooltipText="tooltip_text"
        :button-text="translate('Copy')"
        :aria-label="translate('Copy license key to clipboard')"
        :aria-describedby="'license-key-value'"
    />
  </div>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Clipboard from "@/utils/Clipboard";
import CopyToClipboard from "../../../../admin/Bits/Components/CopyToClipboard.vue";
import translate from "../../translator/Translator";

export default {
  name: 'LicenseKey',
  methods: {
    translate
  },
  props: {
    license_key: String,
    show_mode: {
      type: String,
      default: 'icon_with_text'
    },
    tooltip_text: {
      type: String,
      default: 'Copy License Key'
    }
  },
  components: {
    CopyToClipboard,
    IconButton,
    DynamicIcon
  },
  computed: {
    Clipboard() {
      return Clipboard
    },
  },
  data() {
    return {}
  }
}
</script>
