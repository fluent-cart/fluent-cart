<template>
  <tr>
    <td>
      <a
          href="#"
          @click.prevent="showShippingModal()"
          v-if="shouldEnableEditing && order.order_items.length > 0"
      >
        {{ getShippingButtonLabel() }}
        <DynamicIcon name="External"/>
      </a>
      <span v-else>{{ getShippingButtonLabel() }}</span>
    </td>
    <td>
      {{ getShippingTitle() }}
    </td>
    <td>
    <span v-if="hasFreeShipping()">
        <del>{{ formatNumber(order.shipping_total) }}</del>
        {{ translate("Free") }}
    </span>
      <span v-if="shouldEnableEditing && !hasFreeShipping()" class="max-w-[120px] block ml-auto text-right">
      <el-input v-model="total_shipping" @change="value => {order.shipping_total = value * 100}" type="number">
        <template #prefix>
          {{ currency }}
        </template>
      </el-input>
    </span>
      <span v-else>{{ formatNumber(order.shipping_total) }}</span>
    </td>
  </tr>

  <el-dialog
      :append-to-body="true"
      width="50%"
      v-model="shippingModalIsOpen"
      :title="$t('Shipping and delivery options')"
  >
    <div v-if="shippingModalIsOpen">
      <!--            <shipping-modal-->
      <!--                :shipping="shipping"-->
      <!--                :shipping_type="'custom'"-->
      <!--                @whenShippingEditIsDone="updateShipping"-->
      <!--            />-->
      <template v-if="shippingMethods.length || otherShippingMethods.length">
        <div v-if="shippingMethods.length" class="fct-shipping-methods-list-wrap">
          <h3>{{ translate('Shipping Methods') }}</h3>
          <el-radio-group v-model="shipping.id" class="fct-shipping-methods-radio">
            <el-radio v-for="(method, index) in shippingMethods" :label="method.id" :key="index">
              {{ method.title }}
              <span v-if="method.shipping_charge" class="charge">{{ formatNumber(method.shipping_charge) }}</span>
            </el-radio>
          </el-radio-group>
        </div>

        <div v-if="otherShippingMethods.length" class="fct-other-shipping-methods-list-wrap">
          <h3>{{ translate('Other Shipping Methods') }}</h3>
          <el-radio-group v-model="shipping.id" class="fct-shipping-methods-radio">
            <el-radio v-for="(method, index) in otherShippingMethods" :label="method.id" :key="index">
              {{ method.title }}
              <span v-if="method.shipping_charge" class="charge">{{ formatNumber(method.shipping_charge) }}</span>
            </el-radio>
          </el-radio-group>
        </div>
      </template>
      <div v-else class="fct-no-shipping-methods-found">
        <span v-html="getShippingMethodsNotFoundMessage()"></span>
      </div>
    </div>
    <template #footer v-if="shippingMethods.length || otherShippingMethods.length">
      <el-button @click="shippingModalIsOpen = false">{{ translate('Cancel') }}</el-button>
      <el-button type="primary" @click="updateShipping">{{ translate('Apply') }}</el-button>
    </template>
  </el-dialog>
</template>

<script type="text/babel">
import {adjustShippingTotal} from "@/Bits/cartService";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ShippingModal from "./Modals/ShippingModal.vue";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import {formatNumber} from "../../Bits/productService";
import Notify from "@/utils/Notify";
import AppConfig from "@/utils/Config/AppConfig";

export default {
  name: "Shipping",
  components: {
    IconButton,
    DynamicIcon,
    ShippingModal,
  },
  props: {
    order: {
      type: Object,
      default: () => ({})
    },
    shippingAttributes: {
      type: Object,
      default: () => ({
        id: "",
      })
    },
    shouldEnableEditing: {
      type: Boolean,
      default: true
    },
    shippingMethodsProps: {
      type: Array,
      default: () => []
    },
    otherShippingMethodsProps: {
      type: Array,
      default: () => []
    }
  },
  data() {
    return {
      shippingModalIsOpen: false,
      shipping: this.shippingAttributes,
      currency: AppConfig.get('shop.currency_sign'),
      shippingMethods: this.shippingMethodsProps,
      otherShippingMethods: this.otherShippingMethodsProps,
      total_shipping: this.order.shipping_total / 100,
    };
  },
  watch: {
    shippingMethodsProps: {
      handler: function (newVal, oldVal) {
        this.shippingMethods = newVal;
      },
      deep: true
    },
    otherShippingMethodsProps: {
      handler: function (newVal, oldVal) {
        this.otherShippingMethods = newVal;
      },
      deep: true
    },
    order: {
      handler: function (newVal, oldVal) {
        this.total_shipping = newVal.shipping_total / 100;
      },
      deep: true
    }
  },
  methods: {
    formatNumber,
    translate,
    showShippingModal() {
      this.shippingModalIsOpen = true;
    },
    hasFreeShipping() {
      return Object.keys(this.shipping).length > 0 && parseInt(this.shipping.custom_price) == 0;
    },
    getShippingButtonLabel() {
      if (!this.shouldEnableEditing && this.order.order_items.length > 0) {
        return translate("Shipping");
      } else if (this.shipping.id !== 0) {
        return translate("Edit Shipping");
      } else {
        return translate("Add Shipping");
      }
    },
    getShippingTitle() {
      if (this.shipping.id == 0) {
        return '__';
      }
      const method = this.shippingMethods.find(method => method.id == this.shipping.id);
      if (method) {
        return method.title;
      }
      return '__';
    },
    updateShipping() {
      //adjustShippingTotal(this.order, this.shipping);
      this.shippingModalIsOpen = false;
      const filteredOrderItems = [];
      if (this.order.order_items.length > 0) {
        this.order.order_items.forEach((item) => {
          filteredOrderItems.push({
            id: item.object_id,
            quantity: item.quantity,
            unit_price: item.unit_price,
            discount_total: item.discount_total
          });
        });
      }

      // this.$emit('update:shipping', this.shipping);
      // add request to update shipping
      Rest.post('orders/calculate-shipping', {
        shipping_id: this.shipping.id,
        order_items: filteredOrderItems
      })
          .then(response => {
            this.order.shipping_total = response.shipping_charge;

            const data = {
              order_items: response.order_items,
              shipping_total: response.shipping_charge
            }
            this.total_shipping = this.order.shipping_total / 100;
            //this.handleSuccess(response.message);
            this.$emit('update:shipping', data.order_items, data.shipping_total);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.shippingModalIsOpen = false;
          });
    },
    getShippingMethodsNotFoundMessage() {
      const link = this.$router.resolve({ name: 'shipping' }).href;
      /* translators: %1$s is the opening tag, %2$s is the closing tag */
      return translate(
          'No shipping methods found. You can add shipping methods from %1$s here %2$s',
          `<a href="${link}" target="_blank" class="link-text">`, '</a>');
    }
  },
  mounted() {

  },
};
</script>

<style lang="scss">
.fct-shipping-methods-radio {
  @apply flex-col w-full gap-2;
  .el-radio {
    @apply m-0 w-full;
    &__label {
      @apply flex items-center justify-between flex-1;
    }
  }
}

</style>
