<template>
  <div class="fct-create-order-wrapper fct-layout-width">
    <SaveBar
        :isActive="changes_made > 0 ? 'is-active' : ''"
        @discard="reloadOrder"
        @save="update"
        :loading="loading"
    />

    <div class="single-page-header">
      <div class="single-page-header-title-wrap">
        <el-breadcrumb :separator-icon="ArrowRight">
          <el-breadcrumb-item :to="{ name: 'orders' }">{{
              $t("Orders")
            }}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            {{ $t("Create Order") }}
          </el-breadcrumb-item>
        </el-breadcrumb>
      </div>
    </div>
    <!-- .single-page-header -->
    <div class="single-page-body">
      <div class="fct-create-order">
        <div class="fct-create-order-main">
          <Card.Container class="overflow-hidden">
            <Card.Header
                :title="$t('Products')"
                title_size="small"
                border_bottom
            >
              <!--                  <template v-slot:action>-->
              <!--                    <order-bulk-actions-->
              <!--                        @reload="reloadOrder()"-->
              <!--                        @addItem="()=>{-->
              <!--                          fetchBySearch();-->
              <!--                        }"-->
              <!--                        @addProduct="()=>{-->
              <!--                          addProduct();-->
              <!--                        }"-->
              <!--                        mode="add"-->
              <!--                        :order="order"-->
              <!--                        :is-editing-item="true"-->
              <!--                        trigger_action="add"-->
              <!--                    />-->
              <!--                  </template>-->
            </Card.Header>
            <Card.Body class="px-0 pb-0">
              <div class="fct-browse-product-form-group pb-5 mx-5">
                <el-input
                    :placeholder="$t('Search products...')"
                    @keyup="fetchBySearch()"
                    v-model="searchQuery"
                >
                  <template #prefix>
                    <DynamicIcon name="Search"/>
                  </template>
                </el-input>

                <el-button
                    @click="handleBrowseProduct"
                    type="info"
                    soft
                    class="el-button--x-small"
                >
                  {{ $t("Browse") }}
                </el-button>
              </div>
              <!-- .fct-browse-product-form-group -->

              <div
                  class="fct-table-wrap fct-table-transparent-header"
                  v-if="order.order_items.length > 0"
              >
                <el-table
                    :data="order.order_items"
                    class="fct-table w-full"
                    row-key="id"
                    default-expand-all
                    v-loading="calculating"
                    :element-loading-text="
                    $t('Recalculating with product latest info...')
                  "
                >
                  <el-table-column
                      #default="scope"
                      min-width="450"
                      :label="$t('Product')"
                  >
                    <div class="fct-product-info-card">
                      <div class="fct-product-info-media">
                        <router-link
                            class="link"
                            :to="{
                              name: 'product_edit',
                              params: { product_id: scope.row.post_id },
                            }"
                        >
                          <img
                              :src="
                              getImageUrl(
                                scope.row.featured_media,
                                appVars.asset_url
                              )
                            "
                              :alt="scope.row.title"
                              :class="getClass(scope.row.featured_media)"
                          />
                        </router-link>
                      </div>
                      <div class="fct-product-info-details">
                        <div class="fct-product-info-name">
                          <router-link
                              class="link"
                              :to="{
                            name: 'product_edit',
                            params: { product_id: scope.row.post_id },
                          }"
                          >
                            {{ scope.row.post_title }}
                            <span class="product-sub-name">- {{ scope.row.title }}</span>
                          </router-link>
                        </div>


                        <div class="mt-1">
                          {{ formatNumber(scope.row.price) }}
                          <span
                              class="line-through mr-3"
                              v-if="
                              parseInt(scope.row.unit_price) >
                              parseInt(scope.row.price)
                            "
                          >
                            {{ formatNumber(scope.row.unit_price) }}
                          </span>
                          <!--{{ formatNumber(scope.row.discount_total) }}-->
                          <div
                              v-if="
                              scope.row.payment_type === 'subscription' &&
                              scope.row?.other_info?.manage_setup_fee === 'yes'
                            "
                          >
                            <span
                            >{{ scope.row.other_info.signup_fee_name }} :
                              {{
                                formatNumber(scope.row.other_info.signup_fee)
                              }}</span
                            >
                            <span
                                v-if="
                                scope.row?.other_info?.setup_fee_per_item ==
                                'yes'
                              "
                            >
                              <span>x</span>
                              <span>{{ scope.row.quantity }}</span>
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </el-table-column>

                  <el-table-column width="120" :label="$t('Quantity')">
                    <template #default="scope">
                      <div class="fct-input-number-wrap">
                        <NumberInput
                            :item="scope.row"
                            @update:value="updateQuantity"
                            :isDisabled="isDisabled(scope.row)"
                            :min="1"
                        />
                        <!--                            <span>{{ $t('Max Stock')}} : {{ ((scope?.row?.manage_stock || scope?.row?.variants?.product_detail?.manage_stock) == 1) ? scope?.row?.available || scope.row?.variants?.available : 'Unlimited'}}</span>-->
                      </div>
                    </template>
                  </el-table-column>

                  <el-table-column
                      :width="120"
                      :label="$t('Total')"
                  >
                    <template #default="scope">
                      <div>
                        {{ formatNumber(scope.row.line_total) }}
                      </div>
                      <div
                          v-if="
                          scope.row.payment_type == 'subscription' &&
                          scope.row?.other_info?.manage_setup_fee == 'yes'
                        "
                      >
                        <span>+</span>
                        <span
                            v-if="
                            scope.row?.other_info?.setup_fee_per_item == 'yes'
                          "
                        >
                          <span
                          >{{
                              formatNumber(
                                  scope.row.other_info.signup_fee *
                                  scope.row.quantity
                              )
                            }}
                          </span>
                        </span>
                        <span v-else>{{
                            formatNumber(scope.row.other_info.signup_fee)
                          }}</span>
                      </div>
                    </template>
                  </el-table-column>

                  <el-table-column :width="50">
                    <template #default="scope">
                      <IconButton
                          bg="transparent"
                          border="transparent"
                          tag="a"
                          size="x-small"
                          @click.prevent="deleteProduct(scope.$index, scope.row)"
                      >
                        <DynamicIcon name="Delete"/>
                      </IconButton>
                    </template>
                  </el-table-column>

                  <!--                      <template #empty>-->
                  <!--                        <Empty icon="OrderIcon" :text="$t('Add a Item to Calculate Total and View Payment Options.')"/>-->
                  <!--                      </template>-->
                </el-table>
              </div>
            </Card.Body>
          </Card.Container>

          <Card.Container>
            <Card.Header
                border_bottom
                :title="$t('Payment')"
                title_size="small"
            />
            <Card.Body>
              <table class="fct-table-border fct-table-order-details">
                <tbody>
                <tr>
                  <td>{{ $t("Subtotal") }}</td>
                  <td>
                      <span v-if="order.order_items.length > 0"
                      >{{ translateNumber(order.order_items.length) }}
                        {{
                          order.order_items.length === 1
                              ? $t("item")
                              : $t("items")
                        }}</span
                      >
                  </td>
                  <td>
                    {{ formatNumber(order.subtotal) }}
                  </td>
                </tr>
                <Coupon
                    v-if="order.order_items.length > 0 && !discount.value"
                    :order="order"
                    :couponsAttributes="[]"
                    :hasCouponAttributes="false"
                    :appliedCouponsAttributes="[]"
                    action="create_order"
                    :shouldManageCoupon="true"
                    @update:coupons="updateCoupons"
                    ref="reApplyCouponRef"
                />
                <CustomDiscount
                    v-if="!hasCoupon"
                    :order="order"
                    :hasCoupon="hasCoupon"
                    @update:custom-discount="updateCustomDiscount"
                />
                <template v-if="false">
                  <Shipping
                      v-if="order.order_items.length && (order.order_items[0].payment_type !== 'subscription' || order.order_items[0].other_info?.payment_type !== 'subscription')"
                      :order="order"
                      :shouldEnableEditing="isEditingItem"
                      @update:shipping="updateShipping"
                      :shippingMethodsProps="shippingMethods"
                      :otherShippingMethodsProps="otherShippingMethods"
                      @getShippingMethods="fetchShippingMethods"
                  />
                </template>
                <tr v-if="order.tax_total">
                  <td>{{ $t("Estimated tax") }}</td>
                  <td>{{ $t("Not Calculated") }}</td>
                  <td>
                    {{ formatNumber(order.tax_total) }}
                  </td>
                </tr>
                </tbody>
                <tfoot>
                <tr>
                  <td>{{ $t("Total") }}</td>
                  <td></td>
                  <td>
                    {{ formatNumber(order.total_amount) }}
                  </td>
                </tr>
                </tfoot>
              </table>
            </Card.Body>
          </Card.Container>
        </div>
        <!-- fct-create-order-main -->

        <div class="fct-create-order-aside">
          <div class="fct-admin-sidebar">
            <notes
                :note="order.note"
                @whenNoteEditIsDone="(note)=>{
                  saveNoteModal(note)
                }"
            />

            <OrderCustomerInformation
                :order="order"
                @onAddressSelected="(address)=>{
                  fetchShippingMethods();
                  if(address === null){
                    selectedCustomerCountry = '';
                    return;
                  }
                  selectedCustomerCountry = address.country;
                  if (address.type === 'shipping') {
                    order.shipping_address_id = address.id;
                  } else {
                    order.billing_address_id = address.id;
                  }
                }"
                @onAddressRemove="() => {
                    selectedCustomerCountry = '';
                    fetchShippingMethods();
                }"
            />
            <Labels
                :selectedLabels="selectedLabels"
                @update:update-label="onChangeLabel"
            />
          </div>
        </div>
        <!-- fct-create-order-aside -->
      </div>
      <!-- fct-create-order -->

      <!-- product modal -->
      <AddProductItemModal
          ref="productItemModal"
          :searchQueryParent="searchQuery"
          :orderParent="order"
          @update:searchQuery="updateSearchQuery"
      />

      <el-dialog
          :append-to-body="true"
          v-model="createProductModal"
          :title="$t('Add New Product')"
      >
        <div v-if="createProductModal">
          <add-product-modal
              :customModal="'order_modal'"
              @update:createProductModal="updateProductModal"
              @process_custom="processCustom"
          />
        </div>
      </el-dialog>

    </div>
    <!-- .single-page-body -->
  </div>
  <!-- .fct-create-order-wrapper -->
</template>

<script setup>
import {ref} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import AddProductItemModal from "./Modals/AddProductItemModal.vue";
import {
  ArrowRight,
} from "@element-plus/icons-vue";
import AddProductModal from "../Products/AddProductModal.vue";
import SaveBar from "@/Bits/Components/SaveBar.vue";
import NumberInput from "@/Bits/Components/Inputs/NumberInput.vue";
import Labels from "@/Modules/Parts/Labels/Label.vue";
import {translateNumber} from "@/utils/translator/Translator";

// import {getCurrentInstance} from "vue";
// import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
// const selfRef = getCurrentInstance().ctx;
// const saveShortcut = useSaveShortcut();
// saveShortcut.onSave(()=>{
//     if(!selfRef.createNewCustomer) {
//         selfRef.update()
//     }
// });

const productItemModal = ref();
const isEditingItem = ref(true);
const fetchBySearch = () => productItemModal.value.fetchBySearch();

const handleBrowseProduct = () => {
  fetchBySearch();
};
</script>

<script type="text/babel">
import OrderBulkActions from "./_OrderBulkActions.vue";
import Transaction from "./_Transaction.vue";
import {ref} from "vue";
import {recalculatePayout} from "@/Bits/cartService";
import Badge from "@/Bits/Components/Badge.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import Notes from '../Parts/Notes/Note.vue';
import Coupon from "./Coupon.vue";
import CustomDiscount from "./CustomDiscount.vue";
import OrderCustomerInformation from "./OrderCustomerInformation.vue";
import Shipping from "./Shipping.vue";
import Arr from "@/utils/support/Arr";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import dayjs from "dayjs";

export default {
  name: "CreateOrder",
  components: {
    OrderBulkActions,
    Transaction,
    Badge,
    IconButton,
    CopyToClipboard,
    Notes,
    Coupon,
    CustomDiscount,
    OrderCustomerInformation,
    Shipping
  },
  data() {
    return {
      loading: false,
      calculating: false,
      customModal: false,
      cartItems: {},
      discount: {},
      shippingSettings: {},
      customItem: {
        title: "",
        price: 0,
        quantity: 1,
        is_physical: "yes",
      },
      timeout: "",
      widgets: [],
      order: {
        applied_coupon: [],
        customer_id: "",
        customer: {
          id: null,
          full_name: "",
          email: "",
          phone: "",
        },
        status: "processing",
        subtotal: 0,
        manual_discount_total: 0,
        shipping_tax: 0,
        shipping_total: 0,
        tax_total: 0,
        total_amount: 0,
        order_items: [],
        payment_status: "pending",
        payment_method: "offline_payment",
        shipping_status: "unshipped",
      },
      searching: false,
      orderSettings: false,
      orderAddModal: false,
      availableProducts: [],
      selectedProducts: [],
      deletedItems: [],
      searchQuery: "",
      change_order_infos: false,
      change_payment_infos: false,
      changes_made: 0,
      createProductModal: false,
      coupons: [],
      appliedCoupons: [],
      hasCoupon: false,
      selectedLabels: [],
      selectedCustomerCountry: '',
      shippingMethods: [],
      otherShippingMethods: []
    };
  },
  watch: {
    // "order.order_items"
    order: {
      handler() {
        this.changes_made++;
      },
      deep: true,
    },
  },
  computed: {
    beforeRouteLeave(to, from, next) {
      this.redirectRouteName = to.name;
      this.changes_made ? (this.outerVisible = true) : next();
    },
  },
  methods: {
    getImageUrl(thumbnail, assetUrl) {
      if (thumbnail && thumbnail !== null && thumbnail !== undefined) {
        return thumbnail;
      } else {
        return assetUrl + "images/empty-image.svg";
      }
    },
    getClass(thumbnail) {
      return {
        "w-full": true,
        "border border-solid border-gray-divider rounded":
            thumbnail && thumbnail !== null && thumbnail !== undefined,
      };
    },
    resetUnsaved() {
      this.changes_made = 0;
      // jQuery('.fct-save-wrap').removeClass('fc_unsaved');
    },
    updateCustomModal(val) {
      this.customModal = val;
    },
    updateProductModal(val) {
      this.createProductModal = val;
    },
    updateSearchQuery(val) {
      this.searchQuery = val;
      this.calculateLine();
    },
    updateQuantity(quantity, updatedStock, item) {
      if (parseInt(updatedStock) < 0) {
        this.handleError(
            `You have exceeded the stock limit! Current stock is :  ${updatedStock}`
        );
      } else {
        item.quantity = quantity;
      }
      item.updated_stock = updatedStock;
      this.calculateLine();
    },
    calculateLine() {
      if (this.hasCoupon) {
        // return this.reapplyCoupon();
        return this.$refs.reApplyCouponRef.reApplyCoupon();
      }
      return recalculatePayout(this.order, this.hasCoupon);
    },
    deleteProduct(index, product) {
      /**
       * If product id is not there then its not saved in database, so we do not need to update the database.
       */
      if (product.id) {
        this.deletedItems.push(product.id);
      }

      this.order.order_items.splice(index, 1);
      this.calculateLine();
    },
    processCustom(customItem) {
      customItem.order_id = this.order_id;
      this.order.order_items.unshift(customItem);
      this.customModal = false;
      this.calculateLine();
    },
    update() {  
      delete this.order.customer.orders;  
      this.loading = true;

      const orderData = {...this.order};
      delete orderData["customer"];

      Rest.post("orders", {
        ...orderData,
        discount: this.discount,
        shipping: this.shippingSettings,
        labels: this.selectedLabels,
        user_tz: dayjs.tz.guess(),
        trigger: "on_admin_create",
      })
          .then((response) => {
            Notify.success(response.message);
            setTimeout(() => {
              this.$router.push({
                name: "view_order",
                params: {order_id: response?.order_id},
              });
            }, 300);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.loading = false;
            this.changes_made = 0;
          });
    },
    reloadOrder() {
      return window.location.reload();
    },
    addProduct() {
      this.createProductModal = !this.createProductModal;
    },
    onChangeLabel(selectedLabels) {
      this.selectedLabels = selectedLabels;
    },
    saveNoteModal(note) {
      this.order.note = note;
    },
    updateCustomDiscount(discount) {
      this.discount = discount;
      this.changes_made++;
    },
    updateCoupons(coupons, appliedCoupons, hasCoupon) {
      this.coupons = coupons;
      this.appliedCoupons = appliedCoupons;
      this.hasCoupon = hasCoupon;
    },
    updateShipping(order_items, shipping_total) {
      // this.shippingSettings = shipping;

      this.order?.order_items.forEach((item, index) => {
        item['shipping_charge'] = Arr.get(order_items, `${item.id}.shipping_charge`);
      });
      this.order.shipping_total = shipping_total;
      this.changes_made++;
    },
    isDisabled(item) {
      return 'subscription' === item?.other_info?.payment_type;
    },
    fetchShippingMethods() {
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

      Rest.get('orders/shipping_methods', {
        country_code: this.selectedCustomerCountry,
        order_items: filteredOrderItems
      }).then(response => {
        this.shippingMethods = response.shipping_methods;
        this.otherShippingMethods = response.other_shipping_methods;
      }).finally(() => {

      });
    }
  },
  mounted() {
    this.fetchShippingMethods();
    window.onbeforeunload = () => {
      if (this.changes_made > 0) {
        return true;
      }
    };
  },
};
</script>
