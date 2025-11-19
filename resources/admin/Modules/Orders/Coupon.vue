<template>
  <!-- Start Add Coupon-->
  <tr>
    <td colspan="3">
      <div class="fct-add-coupon-wrap">
        <div class="fct-add-coupon-input-label" v-if="action === 'create_order'">
          {{ $t("Have a Coupon?") }}
        </div>
        <div class="fct-add-coupon-input-label" v-else>
          {{
            shouldManageCoupon
                ? $t("Have a Coupon?")
                : hasCoupon ? $t("Coupons") : $t("")
          }}
        </div>
        <div class="fct-add-coupon-input-wrap" v-if="shouldManageCoupon">
          <el-autocomplete
              v-model="newCoupon"
              :fetch-suggestions="searchCoupon"
              :trigger-on-focus="false"
              clearable
              class="w-full"
              :placeholder="$t('Enter coupon code')"
          />

          <el-button
              @click="applyCoupon"
              type="info"
              soft
              class="el-button--x-small"
          >
            {{ $t("Apply") }}
          </el-button>
        </div>
        <el-alert
            v-if="!hasCoupon && shouldManageCoupon"
            :title="$t( 'If you apply coupon, regular discounts for this order will be removed.')"
            type="warning"
            show-icon
        />
        <span></span>
      </div>
    </td>
  </tr>
  <!--End Add Coupon-->

  <!--Start Coupon List-->
  <tr
      v-for="coupon in coupons"
      :key="coupon.id"
      class="fct-coupons-applied-row"
  >
    <td>
      <div class="flex gap-0.5 items-center">
        <span>{{ coupon.code }}</span>
        <IconButton
            v-if="action === 'create_order'"
            bg="transparent"
            border="transparent"
            tag="a"
            size="x-small"
            @click.prevent="cancelCoupon(coupon.id, coupon)"
            class="coupon-delete-btn"
        >
          <DynamicIcon name="Delete"/>
        </IconButton>

        <el-popconfirm
            v-if="action === 'edit_order'"
            icon="InfoFilled"
            icon-color="#626AEF"
            :title="translate('Are you sure to cancel this coupon? This action is not recoverable')"
            @confirm="cancelCoupon(coupon.id, coupon)"
            width="250"
        >
          <template #reference>
            <IconButton
                v-if="shouldManageCoupon"
                bg="transparent"
                border="transparent"
                tag="a"
                size="x-small"
                class="coupon-delete-btn"
            >
              <DynamicIcon name="Delete"/>
            </IconButton>
          </template>
        </el-popconfirm>
      </div>
    </td>
    <td></td>
    <td>- {{ formatNumber(coupon.amount) }}</td>
  </tr>
  <!--End Coupon List-->
</template>

<script type="text/babel">
import {recalculatePayout} from "@/Bits/cartService";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";

export default {
  name: "Coupon",
  components: {
    IconButton,
    DynamicIcon,
  },
  props: {
    order: {
      type: Object,
      default: () => ({})
    },
    appliedCouponsAttributes: {
      type: Array,
      default: () => []
    },
    hasCouponAttributes: {
      type: Boolean,
      default: false
    },
    couponsAttributes: {
      type: Array,
      default: () => []
    },
    action: {
      type: String,
      default: ''
    },
    shouldManageCoupon: {
      type: Boolean,
      default: true
    },
  },
  data() {
    return {
      appliedCoupons: [...this.appliedCouponsAttributes],
      hasCoupon: this.hasCouponAttributes,
      availableCoupons: [],
      newCoupon: "",
      coupons: [...this.couponsAttributes],
    };
  },
  methods: {
    translate,
    searchCoupon(query, cb) {
      this.fetchCoupon(query, cb);

      // const filteredCoupons = this.availableCoupons
      //     .filter((coupon) => coupon.toLowerCase().includes(query.toLowerCase()))
      //     .map((coupon) => ({value: coupon}));
      // cb(filteredCoupons);
    },
    fetchCoupon(query = '', cb = () => {
    }) {
      this.$get("coupons", {
        search: query,
        select: 'code',
        scopes: ['active']
      })
          .then((response) => {
            const coupons = response.coupons?.data;
            this.availableCoupons = coupons.map(coupon => coupon.code);

            const filteredCoupons = this.availableCoupons
                .filter((coupon) => coupon.toLowerCase().includes(query.toLowerCase()))
                .map((coupon) => ({value: coupon}));
            cb(filteredCoupons);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
          });
    },
    applyCoupon() {
      if (this.coupons) {
        if (this.coupons.find(coupon => coupon.code === this.newCoupon)) {
          Notify.error(this.translate('Coupon already applied'));
          return;
        }
      }

      this.$post("coupons/apply", {
        coupon_code: this.newCoupon,
        order_items: this.order.order_items,
        order_uuid: this.order.uuid,
        applied_coupons: this.appliedCoupons
      })
          .then((response) => {

            this.processCoupon(response);
            this.newCoupon = '';
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          });
    },
    cancelCoupon(id, coupon) {
      this.$post("coupons/cancel", {
        id: coupon.id,
        coupon_code: coupon.code,
        order_uuid: this.order.uuid,
        applied_coupons: this.appliedCoupons,
        order_items: this.order.order_items,
      })
          .then((response) => {
            this.processCoupon(response);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
          });
    },
    reApplyCoupon() {
      this.$post("coupons/re-apply", {
        order_items: this.order.order_items,
        order_uuid: this.order.uuid,
        applied_coupons: this.appliedCoupons
      })
          .then((response) => {
            this.processCoupon(response);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
          });
    },
    processCoupon(response) {


      this.coupons = [];
      this.appliedCoupons = [];

      for (let code in response.applied_coupons) {

        this.coupons.push({
          id: response.applied_coupons[code].id,
          code: code,
          amount: response.applied_coupons[code].discount,
        });

        this.appliedCoupons.push(response.applied_coupons[code].id)
      }

      let totalAmount = 0;
      let discountTotal = 0;

      const responseItems = Object.values(response.calculated_items);

      this.order.order_items.forEach((order_item, index) => {
        // TODO: item.object_id is not present in the response items, so we have to use the id. need to fix the apply coupon return data to fix this
        const orderItem = responseItems.find(item => item.id == order_item['object_id']);

        if (orderItem != null) {
          this.order.order_items[index]['price'] = orderItem['price'];
          this.order.order_items[index]['unit_price'] = orderItem['unit_price'];
          this.order.order_items[index]['line_total'] = (orderItem['unit_price'] * orderItem['quantity']) - orderItem['discount_total'];
          totalAmount += this.order.order_items[index]['line_total'];
          discountTotal += orderItem['discount_total'];
          this.order.order_items[index]['line_total_formatted'] = orderItem['line_total_formatted'];
          this.order.order_items[index]['discount_total'] = orderItem['discount_total'];
        }
      });

      this.order.applied_coupon = response.applied_coupons;
      this.hasCoupon = this.coupons.length > 0 ? true : false;

      recalculatePayout(this.order, this.hasCoupon, discountTotal);

      this.$emit('update:coupons', this.coupons, this.appliedCoupons, this.hasCoupon);
    }
  },
  mounted() {
    // this.fetchCoupon();
  },
};
</script>
