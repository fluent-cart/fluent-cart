<template>
  <div class="fct-payment-link grid gap-5" v-loading="fetchLink"
       :element-loading-text="translate('Please wait, Generating Payment link...')">

    <div class="fct-payment-link-block">

      <CopyToClipboard v-if="paymentUrl" :text="paymentUrl" :tooltipText="translate('Copy Link')"
                       :placeholder="translate('No payment link available,Please setup custom checkout page!')"/>
      <el-button target="_blank" v-else link tag="router-link" :to="{ name: 'pages_setup' }">
        {{ translate('Create a custom checkout page to generate custom links.') }}
      </el-button>

    </div>
<!--Vendor checkout will be test and add later-->
<!--    <div class="fct-payment-link-block">-->
<!--      <div class="fct-payment-link-block-inline flex items-center gap-2">-->
<!--        <el-checkbox @change="updatePaymentLink" v-model="use_vendor_checkout">-->
<!--          {{ translate('Use vendor checkout') }}-->
<!--        </el-checkbox>-->

<!--        <el-tooltip-->
<!--            :content="translate('Vendor checkout will redirect to vendor payment page instead of default payment page!')"-->
<!--            placement="top" popper-class="fct-tooltip">-->
<!--          <div class="w-4">-->
<!--            <DynamicIcon name="InformationFill"/>-->
<!--          </div>-->
<!--        </el-tooltip>-->
<!--      </div>-->
<!--    </div>-->

<!--    <el-row :gutter="20" class="el-form&#45;&#45;label-top" v-if="use_vendor_checkout">-->
<!--      <el-col :md="24" :lg="12">-->
<!--        <el-form-item :label="translate('Payment Method')" class="!mb-0">-->
<!--          <el-select-->
<!--              v-model="selectedMethod"-->
<!--              :placeholder="translate('Select')"-->
<!--              @change="searchOldUrl"-->
<!--          >-->
<!--            <el-option-->
<!--                v-for="item in paymentMethods"-->
<!--                :key="item.value"-->
<!--                :label="item.label"-->
<!--                :value="item.value"-->
<!--            />-->
<!--          </el-select>-->
<!--        </el-form-item>-->
<!--      </el-col>-->
<!--    </el-row>-->
    <el-row v-if="'paypal' === selectedMethod && use_vendor_checkout" class="el-form--label-top" :gutter="20">
      <el-col :md="24" :lg="11">
        <el-form-item :label="translate('PayPal Business/Seller Email')">
          <el-input v-model="business_email" placeholder="Enter your PayPal business/seller email"/>
        </el-form-item>
      </el-col>
      <el-col :md="24" :lg="11">
        <el-form-item :label="translate('PayPal Buyer Email (optional)')">
          <el-input v-model="buyer_email" placeholder="Enter The buyer email"/>
        </el-form-item>
      </el-col>
    </el-row>
    <div class="fct-payment-link-block" v-if="use_vendor_checkout">
      <el-button type="primary" @click="getPaymentLink">
        {{ translate('Create new transaction & link') }}
      </el-button>
    </div>
  </div>
</template>
<script>
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {InfoFilled} from "@element-plus/icons-vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

export default {
  name: "PaymentLink",
  props: ['order', 'order_id'],
  data() {
    return {
      fetchLink: false,
      selectedMethod: 'stripe',
      business_email: '',
      buyer_email: '',
      selectedTransaction: '0',
      use_vendor_checkout: false,
      vendor_url: null,
      transactionIds: [],
      actionName: 'copy',
      paymentUrl: '',
      paymentMethods: [
        {
          value: 'stripe',
          label: 'Stripe'
        },
        {
          value: 'paypal',
          label: 'PayPal'
        }
      ]
    }
  },
  computed: {
    DynamicIcon() {
      return DynamicIcon
    },
    adminUrl() {
      return AppConfig.get('admin_url');
    }
  },
  components: {
    IconButton,
    DynamicIcon,
    CopyToClipboard,
    InfoFilled
  },
  methods: {
    translate,
    updatePaymentLink(value) {
      if (value) {
        this.searchOldUrl();
      } else {
        this.paymentUrl = this.order.custom_checkout_url;
      }
    },
    getPaymentLink() {
      this.fetchLink = true;
      this.$get('orders/' + this.order_id + '/transactions/get_payment_link', {
        data: {
          method: this.selectedMethod,
          transaction: 0,
          use_vendor_checkout: this.use_vendor_checkout,
          business_email: this.business_email, //PayPal
          buyer_email: this.buyer_email //PayPal
        }
      }).then((res) => {
        this.vendor_url = res?.data?.redirect_to;
        this.paymentUrl = res?.data?.redirect_to;
        this.fetchLink = false;
      }).catch(errors => {
        this.handleError(errors);
        this.fetchLink = false;
        this.$emit('reload');
      })
    },
    makeSelectableId() {
      this.transactionIds = [];
      this.order.transactions.forEach(item => {
        if (item.status !== 'paid') {
          this.transactionIds.push({
            'label': item.id,
            'value': item.id
          });
        }
      })
      // this.transactionIds.push({'label': 'create transaction', 'value' : '0'});
    },
    searchOldUrl() {
      this.selectedTransaction = this.transactionIds[this.transactionIds.length - 1].value;
      const selected = this.order.transactions.filter(res => res.id == this.selectedTransaction && res.payment_method == this.selectedMethod);
      this.paymentUrl = selected[0]?.meta?.payment_link;
    },
    generatePaymentUrl() {
      //to-do should implement from localization
      this.paymentUrl = this.order.custom_checkout_url;
    }
  },
  mounted() {
    this.makeSelectableId();
    this.generatePaymentUrl()
  }
}
</script>

<style scoped>

</style>
