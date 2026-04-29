<script type="text/babel">
import { compile, h } from 'vue';
import Arr from "@/utils/support/Arr";
import Str from "@/utils/support/Str";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
  name: 'CustomColumnRenderer',
  components: {
    Badge,
    DynamicIcon
  },
  props: {
    data: {
      type: Object,
      required: true
    },
    templateContent: {
      type: String,
      required: false,
      default: ''
    }
  },
  data() {
    return {

    }
  },
  render() {
    if (this.templateContent) {
      const template = `<div>${this.templateContent}</div>`;
      const renderFn = compile(template).bind(this);

      return h(renderFn, {
        data: this.data,
        app: this
      });
    }
    return null;
  }
}
</script>
