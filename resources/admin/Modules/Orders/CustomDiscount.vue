<template>
  <tr v-if="order.manual_discount_total > 0 || (order.manual_discount_total === 0 && shouldEnableEditing)">
    <td>
      <a
          href="#"
          @click.prevent="manageDiscount()"
          v-if="shouldEnableEditing && !hasCoupon && order.order_items.length > 0"
      >
        {{ getDiscountButtonLabel() }}
        <DynamicIcon name="External"/>
      </a>
      <span v-else>
                {{ getDiscountButtonLabel() }}
            </span>
      <span v-if="discount.label">{{ discount.label }}</span>
      <template v-if="order.manual_discount_total && shouldEnableEditing">
        |
        <a
            href="#"
            @click.prevent="removeDiscount()">
          {{ translate('Remove Discount') }}
        </a>
      </template>
    </td>
    <td>
      {{ discount.reason ? discount.reason : "__" }}
    </td>
    <td>
      <span>- {{ formatNumberForOrder(order.manual_discount_total, order) }}</span>
    </td>
  </tr>

  <!-- Breakdown rows only when the discount is a mix; when the prorate credit IS the
       whole discount the parent row is labeled "Prorate Credit" directly instead. -->
  <template v-if="order.config && order.config.prorate_credit > 0 && order.manual_discount_total - order.config.prorate_credit > 0">
    <tr style="font-size:12px;color:rgb(107,114,128);">
      <td style="padding-left:16px;">{{ translate('Upgrade Discount') }}</td>
      <td></td>
      <td>{{ formatNumberForOrder(order.manual_discount_total - order.config.prorate_credit, order) }}</td>
    </tr>
    <tr style="font-size:12px;color:rgb(107,114,128);">
      <td style="padding-left:16px;">{{ translate('Prorate Credit') }}</td>
      <td></td>
      <td>{{ formatNumberForOrder(order.config.prorate_credit, order) }}</td>
    </tr>
  </template>

  <el-dialog
      :append-to-body="true"
      width="50%"
      v-model="discountModalIsOpen"
      :title="
            order.manual_discount_total === 0 ? translate('Add discount') : translate('Edit discount')
        "
  >
    <div v-if="discountModalIsOpen">
      <DiscountModal
          :discount="discount"
          :totalAmount="order.subtotal"
          @when-discount-edit-is-done="applyDiscount"
          @emit-cancel-discount-modal="discountModalIsOpen = false"
      />
    </div>
  </el-dialog>
</template>

<script setup>

</script>

<script type="text/babel">
import {adjustTotalBasedOnDiscountChange} from "@/Bits/cartService";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import DiscountModal from './Modals/DiscountModal.vue';
import translate from "@/utils/translator/Translator";

export default {
  name: "CustomDiscount",
  components: {
    IconButton,
    DynamicIcon,
    DiscountModal,
  },
  props: {
    order: {
      type: Object,
      default: () => ({})
    },
    discountAttributes: {
      type: Object,
      default: () => ({
        type: "amount",
        label: "",
        reason: "",
      })
    },
    hasCoupon: {
      type: Boolean,
      default: false
    },
    shouldEnableEditing: {
      type: Boolean,
      default: true
    },
  },
  data() {
    return {
      discountModalIsOpen: false,
      discount: this.discountAttributes,
    };
  },
  mounted() {
  },
  methods: {
    translate,
    isOnlyProrateCredit() {
      const prorateCredit = this.order.config ? this.order.config.prorate_credit || 0 : 0;
      return prorateCredit > 0 && this.order.manual_discount_total - prorateCredit <= 0;
    },
    getDiscountButtonLabel() {
      if (!this.shouldEnableEditing && this.order.order_items.length > 0) {
        return this.isOnlyProrateCredit() ? translate("Prorate Credit") : translate("Discount");
      } else if (this.order.manual_discount_total !== 0) {
        return translate("Edit Discount");
      } else {
        return translate("Add Discount");
      }
    },
    manageDiscount() {
      this.discount.action = parseInt(this.order.manual_discount_total) > 0 ? "edit" : "add";
      if (this.shouldEnableEditing && !this.hasCoupon && this.order.order_items.length > 0) {
        this.discountModalIsOpen = true;
      }
    },
    removeDiscount() {
      this.discount = {};
      adjustTotalBasedOnDiscountChange(this.order, this.discount);
      this.$emit('update:custom-discount', this.discount);
    },
    applyDiscount(showModal, updatedDiscount) {
      this.discount = updatedDiscount;
      adjustTotalBasedOnDiscountChange(this.order, this.discount);
      this.discountModalIsOpen = showModal;
      this.$emit('update:custom-discount', this.discount);
    },
  },
};
</script>
