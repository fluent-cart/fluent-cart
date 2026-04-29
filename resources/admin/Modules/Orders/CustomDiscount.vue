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
      <span>- {{ formatNumber(order.manual_discount_total) }}</span>
    </td>
  </tr>


  <el-dialog
      :append-to-body="true"
      width="50%"
      v-model="discountModalIsOpen"
      :title="
            order.manual_discount_total === 0 ? translate('Add discount') : translate('Edit discount')
        "
  >
    <div v-if="discountModalIsOpen">
      <discount-modal
          :discount="discount"
          :totalAmount="order.subtotal"
          @whenDiscountEditIsDone="applyDiscount"
          @emitCancelDiscountModal="discountModalIsOpen = false"
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
  methods: {
    translate,
    getDiscountButtonLabel() {
      if (!this.shouldEnableEditing && this.order.order_items.length > 0) {
        return translate("Discount");
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
  mounted() {
  },
};
</script>
