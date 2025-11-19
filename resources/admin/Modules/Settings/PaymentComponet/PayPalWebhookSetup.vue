<template>
  <div class="fluent_cart_paypal_webhook_setup" v-if="isConnected">
    <p class="fluent_cart_paypal_webhook_setup_title">PayPal {{ mode }} webhook setup</p>
    <div v-loading="checkingWebhook" element-loading-text="Checking webhook..." v-if="webhook_info" v-html="webhook_info"></div>
    <div v-else>
      <p class="fluent_cart_paypal_webhook_setup_description">
        Configure PayPal webhooks to receive real-time payment notifications
      </p>
      <br>
      <el-button :type="text" :loading="isLoading"
                 @click="setupWebhook"
                 :disabled="isLoading"
                 class="setup-button" size="small"
      >
        <span>Setup Webhook</span>
      </el-button>
    </div>
  </div>
</template>
<script>
import Notify from "@/utils/Notify";
export default {
  name: "PayPalWebhookSetup",
  data() {
    return {
      isLoading: false,
      setupStatus: null,
      checkingWebhook: false
    }
  },
  props: ['mode', 'webhook_info', 'testConnect', 'liveConnect'],
  watch: {
    testConnect() {
      this.checkWebhookStatus();
    },
    liveConnect() {
      this.checkWebhookStatus();
    }
  },
  computed: {
    isConnected() {
      return (this.mode === 'test' && this.testConnect) || (this.mode === 'live' && this.liveConnect);
    }
  },
  components: {
    Notify
  },
  methods: {
    checkWebhookStatus() {
      if (this.isConnected) {
        this.checkingWebhook = true;
        this.$get('settings/payment-methods/paypal/webhook/check', {
          mode: this.mode
        }).then(response => {
          this.checkingWebhook = false;
        })
            .catch(error => {
              this.checkingWebhook = false;
            })
      }
    },
    setupWebhook() {
      this.isLoading = true;
      this.$post('settings/payment-methods/paypal/webhook/setup', {
        mode: this.mode
      })
      .then(response => {
        this.isLoading = false;
        this.setupStatus = 'success';
        Notify.success(response.message);
        this.$emit('reload_settings', true);
      })
      .catch(error => {
        this.isLoading = false;
        this.setupStatus = 'error';
        Notify.error(error.message);
        setTimeout(() => {
          window.location.reload();
        }, 2000);
      })
    }
  },
  mounted() {
    this.checkWebhookStatus();
  }
}
</script>

<style lang="scss" scoped>
.fluent_cart_paypal_webhook_setup {
  padding:10px;
  &_title {
    font-weight: bold;
  }
}

</style>