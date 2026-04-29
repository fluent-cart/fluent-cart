<template>
  <div class="fluent-cart-admin-pages">
    <el-dialog
        class="fct-add-product-item-modal"
        ref="fc_product_search_container"
        @close="searchQuery= ''"
        v-model="orderAddModal"
        :title="$t('Add Items')"
    >
      <div class="-mx-5 pb-5 border border-solid border-t-0 border-x-0 border-gray-divider dark:border-dark-400">
        <div class="px-5">
          <el-input
              :placeholder="$t('Search products')"
              autofocus
              ref="fc_product_search_new"
              @input="fetchBySearch()"
              v-model="searchQuery"
              clearable
          >
            <template #prefix>
              <DynamicIcon name="Search"/>
            </template>
          </el-input>
        </div>
      </div>

      <div class="fc_product_search_table" ref="fc_product_search_table">
        <el-skeleton class="px-5 py-4" :loading="loading" :rows="5" animated/>

        <template v-if="!loading">
          <ul class="fct-collapsible-list">
            <li class="fct-collapsible-list-item" v-for="(product, productIndex) in availableProducts"
                :key="productIndex">

              <div class="fct-collapsible-list-item-inner">
                <div class="fct-collapsible-item">
                  <div class="fct-collapsible-content-wrapper">
                    <div v-if="product?.children?.length > 0" class="content-collapsible-btn"
                         @click="handleToggleCollapse(productIndex)"
                         :class="{ 'is-collapsed': collapsedStates[productIndex] }">
                      <DynamicIcon name="CaretRight" class="w-4 h-5"/>
                    </div>
                    <div class="content-indent" v-if="!product?.children?.length > 0"></div>
                    <div class="content-checkbox">
                      <el-checkbox v-model="product.checked" @change="handleCheckboxChange(productIndex, product)"
                                   :disabled="product.disable === true || isAlreadyAdded(product.object_id)"/>
                    </div>
                    <div class="content-img">
                      <img
                          :src="product.featured_media != null ? product.featured_media : Asset.getUrl('images/placeholder-small.svg')"
                          :alt="product.title"/>
                    </div>
                    <div class="content-title">
                      <div class="title">{{ product.post_title }}</div>
                      <small v-if="product?.other_info?.payment_type === 'subscription' && product.other_info?.billing_summary !== ''" class="text-gray-500 dark:text-gray-400">
                        {{product.other_info?.billing_summary}}
                      </small>
                      <div class="text" v-if="isAlreadyAdded(product.object_id)">
                        {{ $t('Item already added') }}
                      </div>
                    </div>
                  </div>
                  <div class="fct-collapsible-stock-wrapper" v-if="product?.children?.length == 0">
                    <span v-if="product.stockStatus == 'in-stock'">
                      {{
                        product.available !== Infinity ? product.available + translate(' Available') : translate('Unlimited')
                      }}
                    </span>
                    <span v-if="product.stockStatus === 'out-of-stock'" class="text-red-500">
                      {{ formatCapitalized(product.stockStatus) }}
                    </span>
                  </div>
                  <div class="fct-collapsible-value-wrapper">
                    <span v-if="product?.children?.length > 0">
                      {{
                        product.min_price != product.max_price ? formatNumber(product.min_price) + ' - ' + formatNumber(product.max_price) : formatNumber(product.min_price)
                      }}
                    </span>
                    <span v-else>
                      {{ formatNumber(product.price) }}
                    </span>
                  </div>
                </div><!-- .fct-collapsible-item -->

                <div class="fct-collapsible-item-child-container"
                     :class="{ 'is-collapsed': collapsedStates[productIndex] }"
                     v-if="product?.children?.length > 0"></div>

                <div class="fct-collapsible-item-child-container"
                     :class="{ 'is-collapsed': collapsedStates[productIndex] }" v-if="product?.children?.length > 0">
                  <ul class="fct-collapsible-item-child-inner">
                    <li v-for="(productChildren, productChildrenIndex) in product.children" :key="productChildrenIndex">
                      <div class="fct-collapsible-item">
                        <div class="fct-collapsible-content-wrapper">
                          <div class="fct-collapsible-content-group">
                            <div class="content-checkbox">
                              <el-checkbox v-model="productChildren.checked"
                                           @change="handleCheckboxChange(productChildrenIndex, productChildren)"
                                           :disabled="productChildren.disable === true || isAlreadyAdded(productChildren.object_id)"/>
                            </div>
                            <div class="content-img">
                              <img
                                  :src="productChildren.featured_media != null ? productChildren.featured_media : appVars.asset_url + 'images/placeholder-small.svg' "
                                  :alt="productChildren.title"/>
                            </div>
                          </div>

                          <div class="content-title">
                            <div class="title">{{ productChildren.title }}</div>
                            <small v-if="productChildren?.other_info?.payment_type === 'subscription' && productChildren.other_info?.billing_summary !== ''" class="text">
                              {{CurrencyFormatter.currencySign}} {{productChildren.other_info?.billing_summary}}

                              <span v-if="productChildren.other_info?.trial_days > 0">
                                <br>
                                {{translate('Trial Days')}}: {{productChildren.other_info?.trial_days}}
                              </span>
                            </small>
                            <div class="text already-added" v-if="isAlreadyAdded(productChildren.object_id)">
                              {{ translate('Item already added') }}
                            </div>

                            <BundleProducts
                                v-if="productChildren.bundle_items.length > 0"
                                :product="productChildren"
                                title-key="variation_title"
                            />
                          </div>
                        </div>
                        <div class="fct-collapsible-stock-wrapper">
                          <span v-if="productChildren.stockStatus == 'in-stock'">
                            {{
                              productChildren.available !== Infinity ? translateNumber(productChildren.available) + translate(' Available') : translate('Unlimited')
                            }}
                          </span>
                          <span v-if="productChildren.stockStatus == 'out-of-stock'" class="text-red-500">
                             {{ formatCapitalized(productChildren.stockStatus) }}
                          </span>
                        </div>
                        <div class="fct-collapsible-value-wrapper">
                          {{ formatNumber(productChildren.price) }}
                        </div>
                      </div><!-- .fct-collapsible-item -->
                    </li>
                  </ul>
                </div>
              </div><!-- .fct-collapsible-list-item-inner -->
            </li>
          </ul>

          <Empty v-if="availableProducts.length === 0" icon="Empty/ListView"
                 :text="translate('We could\'t find any products matching your search.')"/>
        </template>
      </div>

      <div class="-mx-5 -mb-5 fc_product_search_pagination_wrap">
        <pagination
            :pagerSizes="false"
            :hide_on_single="false"
            :pagination="paginate"
            @fetch="doSearch"
            @current-page-changed="onCurrentPageChanged"
            @on-per-page-changed="onPerPageChanged"
        />
      </div>

      <div class="dialog-footer">
        <el-button @click="orderAddModal = false">
          {{ translate('Cancel') }}
        </el-button>
        <el-button :disabled="!this.selectedProducts.length" type="primary" @click="processProducts">
          {{ translate('Add Items') }}
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>


<script>
import {prepareProductList, formatCapitalized} from "@/Bits/productService";
import {nextTick} from "vue";
import Pagination from "@/Bits/Components/Pagination.vue";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Asset from "@/utils/support/Asset";
import translate, {translateNumber} from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import BundleProducts from "@/Bits/Components/BundleProducts.vue";

export default {
  components: {BundleProducts, Empty, Pagination, Badge, DynamicIcon},
  props: ['searchQueryParent', 'orderParent', 'order_id'],
  data() {
    return {
      loading: false,
      searching: false,
      orderAddModal: false,
      availableProducts: [],
      selectedProducts: [],
      cartItems: [],
      paginate: {
        per_page: 10,
        current_page: 1,
        total: 0,
        last_page: 1
      },
      collapsedStates: null
    }
  },
  computed: {
    CurrencyFormatter() {
      return CurrencyFormatter
    },
    Asset() {
      return Asset
    },
    order: {
      get() {
        return this.orderParent;
      },
      set(val) {
        this.$emit('update:order', val)
      }
    },
    searchQuery: {
      get() {
        return this.searchQueryParent;
      },
      set(val) {
        this.$emit('update:searchQuery', val)
      },
    }
  },
  created() {
    this.initializeCollapsedStates();
  },
  methods: {
    translate,
    translateNumber,
    initializeCollapsedStates() {
      // Initialize a collapsedStates array with false for all items
      this.collapsedStates = Array.from({length: this.availableProducts.length}, () => false);
    },
    handleToggleCollapse(index) {
      // Toggle the collapse state of the clicked item
      this.collapsedStates[index] = !this.collapsedStates[index];

      // Collapse all other items except the clicked one
      for (let i = 0; i < this.collapsedStates.length; i++) {
        if (i !== index) {
          this.collapsedStates[i] = false;
        }
      }
    },
    hasSubscriptionProduct(items, payment_type) {
      const subscriptionProducts = items.filter(item => {
        if (item.variants && item.variants.other_info && item.variants.other_info.payment_type === payment_type) {
          return true;
        } else if (item.other_info.payment_type && item.other_info.payment_type === payment_type) {
          return true;
        }
        return false;
      });

      return subscriptionProducts.length > 0;
    },

    validateShouldAddProduct(row) {
      const variationIdsInOrderItems = this.order.order_items.map(item => item.object_id);
      const variationIdsInSelectedProducts = this.selectedProducts.map(item => item.object_id);

      const existingPaymentType = row?.other_info?.payment_type === 'onetime' ? 'subscription' : 'onetime';
      const isOrderItemsEmpty = this.order.order_items.length === 0;

      if (isOrderItemsEmpty && this.selectedProducts.length === 0) {
        return true;
      } else if (isOrderItemsEmpty && variationIdsInSelectedProducts.includes(row.object_id)) {
        return true;
      } else if (!isOrderItemsEmpty && variationIdsInOrderItems.includes(row.object_id)) {
        return true;
      } else if (isOrderItemsEmpty && !this.hasSubscriptionProduct(this.selectedProducts, existingPaymentType)) {
        if (!this.hasSubscriptionProduct(this.selectedProducts, 'subscription')) {
          return true;
        }
      } else if (!isOrderItemsEmpty && !this.hasSubscriptionProduct(this.order.order_items, existingPaymentType)) {
        if (!this.hasSubscriptionProduct(this.order.order_items, 'subscription')) {
          return true;
        }
      }

      return false;
    },

    handleCheckboxChange(index, row) {
      if (row.checked) {
        if (!this.validateShouldAddProduct(row)) {
          row.checked = false;
          return Notify.error(translate('You can purchase one subscription at a time.'));
        } else {
          this.selectedProducts.push(row);
        }
      } else {
        // Remove the item from selectedProducts if the checkbox is unchecked
        const indexToRemove = this.selectedProducts.findIndex(
            (product) => product.id === row.id
        );
        if (indexToRemove !== -1) {
          return this.selectedProducts.splice(indexToRemove, 1);
        }
      }
    },

    onCurrentPageChanged({page, currentPageCount}) {
      this.paginate.current_page = page;
      this.doSearch()
    },

    onPerPageChanged(perPage) {
      this.paginate.per_page = perPage;
      this.doSearch()
    },

    isAlreadyAdded(productId) {
      if (this.order.order_items) {
        return this.order.order_items.some(item => parseInt(item.object_id) === parseInt(productId));
      }

      return false;
    },

    isNotParentVariant(row) {
      return !row.children || row.children.length < 1;
    },

    handleSelectionChanges(selected) {
      this.selectedProducts = selected;
    },

    processProducts() {

      let itIsEncountered = {};

      if (this.order.order_items && this.order.order_items.length) {
        this.order.order_items.forEach((vl, idx) => {
          itIsEncountered[vl.object_id] = vl;
        })
      }

      if (this.selectedProducts) {

        this.selectedProducts.forEach((vl, idx) => {

          if (itIsEncountered[vl.object_id]) {

            itIsEncountered[vl.object_id].quantity++;

          } else {

            vl.tax_amount = parseInt(vl.tax_amount) || 0;
            vl.shipping_charge = parseInt(vl.shipping_charge) || 0;
            vl.discount_total = parseInt(vl.discount_total) || 0;
            vl.quantity = parseInt(vl.quantity) || 1;

            itIsEncountered[vl.object_id] = vl
          }

          itIsEncountered[vl.object_id].updated_stock = parseInt(vl.available);
          itIsEncountered[vl.object_id].total = parseInt(itIsEncountered[vl.object_id].price) * parseInt(itIsEncountered[vl.object_id].quantity);
          itIsEncountered[vl.object_id].line_total = parseInt(itIsEncountered[vl.object_id].total) - parseInt(itIsEncountered[vl.object_id].discount_total) + parseInt(itIsEncountered[vl.object_id].tax_amount);
        })
      }

      this.order.order_items = [];

      let subTotal = 0;

      for (const ky in itIsEncountered) {
        this.order.order_items.push(itIsEncountered[ky]);

        subTotal += parseInt(itIsEncountered[ky].line_total);
        if (itIsEncountered[ky].payment_type === 'subscription' && itIsEncountered[ky].other_info.manage_setup_fee === 'yes') {
          let signupFee = parseInt(itIsEncountered[ky].other_info.signup_fee);
          if (itIsEncountered[ky].other_info.setup_fee_per_item === 'yes') {
            signupFee = signupFee * itIsEncountered[ky].quantity
          }
          subTotal += signupFee;
        }
      }

      this.order.subtotal = subTotal;
      this.order.total_amount = subTotal + (parseInt(this.order.tax_total)) + (parseInt(this.order.shipping_total)) - parseInt(this.order.manual_discount_total);


      /**
       * closing the modal
       * @type {boolean}
       */
      this.orderAddModal = false;
      this.searchQuery = '';
      this.selectedProducts = []

      return true;
    },

    fetchBySearch() {
      this.loading = true;
      this.searching = true;
      this.orderAddModal = true;
      let ref = this;
      nextTick(() => {
        setTimeout(() => {
          ref.$refs.fc_product_search_new.ref.focus()
        }, 50)
      })
      clearTimeout(this.timeout);
      const self = this;
      this.timeout = setTimeout(function () {
        self.doSearch();
      }, 700);
    },

    doSearch() {
      this.loading = true;
      let queryParams = {
        "active_view": 'publish',
        "per_page": this.paginate.per_page,
        "page": this.paginate.current_page,
        "search": this.searchQuery,
        'filter_type': 'simple',
        'sort_by': 'ID',
        'with': ['detail.variants.media','detail.variants.bundleChildren', 'categories']
      };
      Rest.get('products/', {
        ...queryParams
      })
          .then(response => {


            let res = {
              ...response.products
            }

            this.availableProducts = prepareProductList(res.data, this.order_id);
            delete res['data'];
            this.paginate = res;

            this.searching = false;
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
          });
    },

    formatCapitalized(input) {
      return formatCapitalized(input);
    },
  }
}

</script>

