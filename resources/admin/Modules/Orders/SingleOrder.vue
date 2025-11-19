<template>
  <NotFound
      v-if="notFound.show"
      :button-text="notFound.buttonText"
      :message="notFound.message"
      :route="notFound.route"
  />

  <div class="fct-single-order-page" v-if="notFound.show === false">
    <SaveBar
        :isActive="changes_made >= 1 && !showRefundModal ? 'is-active' : ''"
        @discard="reloadOrder"
        @save="update"
        :loading="loading"
        :saveButtonText="translate('Update')"
        :loadingText="translate('Updating')"
    >
    </SaveBar>

    <SingleOrderLoader v-if="loading"/>
    <div v-if="order" class="fct-single-order-wrapper fct-layout-width">
      <template v-if="!loading && order">
        <div class="single-page-header flex items-start justify-between">
          <div>
            <div class="single-page-header-title-wrap">
              <el-breadcrumb :separator-icon="ArrowRight">
                <el-breadcrumb-item :to="{ name: 'orders' }">
                  {{ translate("Orders") }}
                </el-breadcrumb-item>
                <el-breadcrumb-item
                    v-if="order?.parent_id && order.parent_id != '0'"
                    :to="{ name: 'view_order', params: {} }"
                >
                  <a @click="getOrderUrl(order?.parent_id)">
                    #{{ order.parent_id }}
                  </a>
                </el-breadcrumb-item>
                <el-breadcrumb-item> #{{ order_id }}</el-breadcrumb-item>
              </el-breadcrumb>
              <div class="single-page-header-status-wrap">
                <Badge :status="order.status"/>
                <OrderUpDownIndicator :order="order"/>
              </div>
            </div>
            <div class="single-page-header-text">
              <ConvertedTime :date-time="order.created_at" with-time/>
              <span class="fct_separator"></span>
              <template v-if="order.invoice_no">
                <span>
                    {{ order.invoice_no }}
                </span>
                <span class="fct_separator"></span>
              </template>
              <span class="capitalize">{{ order.type }}</span>
            </div>
          </div>
          <div class="fct-btn-group sm">
            <el-button
                v-if="shouldShowRefund()"
                @click="handlePaymentActions('refund')"
                class="bulk-action-hide-only-mobile"
            >
              {{ translate("Refund") }}
            </el-button>
            <el-button
                v-if="!isEditingItem"
                @click="enableItemEditing"
                :disabled="shouldDisableEditing"
                class="bulk-action-hide-only-mobile"
            >
              <template v-if="shouldDisableEditing">
                <el-tooltip
                    placement="top"
                    popper-class="fct-tooltip fct-disabled-edit-order-tooltip"
                >
                  <template #content>
                    {{ warningMessage }}
                  </template>
                  {{ translate("Edit") }}
                </el-tooltip>
              </template>
              <template v-else>
                {{ translate("Edit") }}
              </template>
            </el-button>
            <el-button v-else @click="disableItemEditing" class="bulk-action-hide-only-mobile">
              {{ translate("Disable Editing") }}
            </el-button>
            <order-bulk-actions
                @reload="reloadOrder()"
                @addItem="fetchBySearch"
                @addProduct="addProduct"
                @enableItemEditing="enableItemEditing"
                @disableItemEditing="disableItemEditing"
                @handlePaymentActions="handlePaymentActions"
                :is-editing-item="isEditingItem"
                :order="order"
                :triggerMode="!isMobile ? 'button' : ''"
                :shouldShowRefund="shouldShowRefund()"
                :warningMessage="warningMessage"
            />
          </div>
        </div>
        <!-- .single-page-header -->

        <div class="single-page-body">
          <Alert
              class="mb-5"
              type="error"
              v-for="activity in actionableActivities"
              :key="activity.id"
              :content="(activity?.content)"
              closable
              @close="clearActivityAction(activity)"
          />

          <div class="fct-single-order">
            <div class="fct-single-order-main">
              <Card.Container class="overflow-hidden fct-order-items-card">
                <Card.Header border_bottom>
                  <template #title>
                    <h2 class="fct-card-header-title is-small">
                      {{ translate("Order items") }}
                      <Badge
                          v-if="order?.fulfillment_type === 'physical'"
                          :status="order?.shipping_status"
                      >
                        {{ order?.shipping_status }}
                      </Badge>
                    </h2>
                  </template>
                </Card.Header>
                <Card.Body>
                  <div
                      class="fct-browse-product-form-group pb-5"
                      v-if="isEditingItem"
                  >
                    <el-input
                        :placeholder="translate('Search products...')"
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
                      {{ translate("Browse") }}
                    </el-button>
                  </div>
                  <!-- .fct-browse-product-form-group -->

                  <div class="fct-ordered-product-list-container">
                    <div
                        class="fct-ordered-product-list-wrapper"
                        v-if="order.order_items.length > 0"
                    >
                      <div class="fct-media-content-list -mt-4">
                        <template
                            v-for="(product, productIndex) in order.order_items"
                            :key="productIndex"
                        >
                          <div
                              class="fct-media-content-list-item px-0"
                              v-if="
                              product.payment_type !== 'signup_fee' &&
                              product?.other_info?.item_status !== 'adjusted' &&
                              shouldSkipAdjustmentItem(product)
                            "
                          >
                            <div class="product-thumbnail">
                              <router-link
                                  class="link"
                                  :to="{
                                  name: 'product_edit',
                                  params: { product_id: product?.post_id },
                                }"
                              >
                                <img
                                    :src="
                                    product.featured_media != null
                                      ? product.featured_media
                                      : getImageUrl(product)
                                  "
                                    :alt="product.title"
                                    :class="getClass(product)"
                                />
                              </router-link>
                            </div>
                            <!-- .product-thumbnail -->
                            <div class="product-details">
                              <div class="product-details-content-row">
                                <div
                                    class="product-details-content-first flex flex-col"
                                >
                                  <div class="product-title m-0">
                                    <router-link
                                        class="link"
                                        :to="{
                                        name: 'product_edit',
                                        params: {
                                          product_id: product?.post_id
                                        }
                                      }"
                                    >
                                      {{ product?.post_title }}
                                    </router-link>
                                  </div>

                                  <div class="product-variation-title m-0">
                                    &#8211; {{ product?.title }}
                                  </div>

                                  <div
                                      class="product-subscription-info"
                                      v-if="
                                      product?.payment_type === 'subscription'
                                    "
                                  >
                                    <span v-html="product?.payment_info"></span>
                                    <span
                                        v-html="product?.setup_info"
                                        v-if="
                                        product?.other_info
                                          ?.manage_setup_fee === 'yes'
                                      "
                                    ></span>
                                  </div>

                                  <div
                                      class="product-details-quantity"
                                      v-if="isEditingItem"
                                  >
                                    <span>{{
                                        formatNumber(product.unit_price)
                                      }}</span>
                                    <span>x</span>
                                    <span>{{ product.quantity }}</span>
                                  </div><!-- .product-details-quantity -->

                                  <div class="product-details-price-mobile" v-if="!isEditingItem">
                                    <div class="product-details-quantity">
                                    <span
                                        class="del"
                                        v-if="product?.variants?.length > 0"
                                    >
                                      {{
                                        formatNumber(
                                            product.variants.compare_price
                                        )
                                      }}
                                    </span>
                                      <span>{{
                                          formatNumber(product.unit_price)
                                        }}</span>
                                      <span>x</span>
                                      <span>{{ product.quantity }}</span>
                                    </div><!-- .product-details-quantity -->
                                  </div><!-- .product-details-price-mobile -->



                                </div>

                                <div
                                    class="product-details-content-second shrink-0 grow-0 basis-[90px]"
                                    v-if="!isEditingItem"
                                >
                                  <div class="product-details-quantity">
                                    <span
                                        class="del"
                                        v-if="product?.variants?.length > 0"
                                    >
                                      {{
                                        formatNumber(
                                            product.variants.compare_price
                                        )
                                      }}
                                    </span>
                                    <span>{{
                                        formatNumber(product.unit_price)
                                      }}</span>
                                    <span>x</span>
                                    <span>{{ product.quantity }}</span>
                                  </div>
                                  <!-- .product-details-quantity -->
                                </div>

                                <div
                                    class="product-details-content-third shrink-0 grow-0 flex flex-row justify-end gap-2 basis-[100px]"
                                >
                                  <span
                                      v-if="
                                      product?.other_info?.item_status !==
                                      'adjusted'
                                    "
                                  >
                                    {{ formatNumber(product.line_total) }}
                                  </span>

                                  <div
                                      v-if="
                                      product.payment_type === 'subscription' &&
                                      product?.other_info?.manage_setup_fee ===
                                        'yes'
                                    "
                                  >
                                    <span>+ </span>
                                    <span
                                        v-if="
                                        product?.other_info
                                          ?.setup_fee_per_item === 'yes'
                                      "
                                    >
                                      <span
                                      >{{
                                          formatNumber(
                                              product.other_info.signup_fee *
                                              product.quantity
                                          )
                                        }}
                                      </span>
                                    </span>
                                    <span v-else>{{
                                        formatNumber(
                                            product.other_info.signup_fee
                                        )
                                      }}</span>
                                  </div>
                                </div>
                              </div>

                              <div
                                  class="product-details-action-row"
                                  v-if="isEditingItem"
                              >
                                <a
                                    href="#"
                                    @click.prevent="
                                    handleAdjustQuantityModal(product)
                                  "
                                >
                                  {{ translate("Adjust Quantity") }}
                                </a>
                                <a
                                    href="#"
                                    @click.prevent="
                                    deleteProduct(productIndex, product)
                                  "
                                >
                                  {{ translate("Remove Item") }}
                                </a>
                              </div>
                            </div>
                            <!-- .product-details -->
                          </div>
                        </template>
                      </div><!-- .fct-ordered-product-list -->


                    </div>
                    <!-- .fct-ordered-product-list-wrapper -->
                    <p
                        v-if="order.order_items.length == 0"
                        class="m-0 text-center"
                    >
                      {{ translate("No items found.") }}
                    </p>
                  </div>
                </Card.Body>
              </Card.Container>

              <Card.Container class="fct-order-payment-card">
                <Card.Header border_bottom>
                  <template v-slot:title>
                    <h2 class="fct-card-header-title is-small">
                      <span>{{ translate("Payment") }} </span>
                      <Badge :status="order.payment_status" size="small"/>
                    </h2>
                  </template>
                </Card.Header>
                <Card.Body>
                  <table class="fct-table-border fct-table-order-details">
                    <tbody>
                    <tr>
                      <td>{{ translate("Subtotal") }}</td>
                      <td>
                          <span v-if="order.order_items.length > 0"
                          >{{ translateNumber(uniqueItemsCount) }}
                            {{
                              pluralizeTranslate('item', 'items', uniqueItemsCount)
                            }}</span>
                      </td>
                      <td>{{ formatNumber(order.subtotal) }}</td>
                    </tr>
                    <ShippingComponent
                        :order="order"
                        :shippingAttributes="shippingSettings"
                        :shouldEnableEditing="isEditingItem"
                        @update:shipping="updateShipping"
                        :shippingMethodsProps="shippingMethods"
                        :otherShippingMethodsProps="otherShippingMethods"
                    />
                    <Coupon
                        v-if="
                          order.order_items.length > 0 &&
                          (isEditingItem || hasCoupon)
                        "
                        :order="order"
                        :couponsAttributes="coupons"
                        :hasCouponAttributes="hasCoupon"
                        :appliedCouponsAttributes="appliedCoupons"
                        action="edit_order"
                        :shouldManageCoupon="isEditingItem"
                        @update:coupons="updateCoupons"
                        ref="reApplyCouponRef"
                    />
                    <CustomDiscount
                        v-if="!hasCoupon"
                        :order="order"
                        :discountAttributes="discount"
                        :hasCoupon="hasCoupon"
                        :shouldEnableEditing="isEditingItem"
                        @update:custom-discount="updateCustomDiscount"
                    />
                    <tr v-if="order.tax_total">
                      <td>
                        {{ translate("Tax") }}
                        {{ parseInt(order.tax_behavior) == 2 ?
                          translate('(Included)') : translate('(Excluded)')
                        }}
                      </td>
                      <td></td>
                      <td>{{ formatNumber(order.tax_total) }} </td>
                    </tr>
                    <tr v-if="order.shipping_tax">
                      <td>{{ translate("Shipping Tax") }} {{ parseInt(order.tax_behavior) == 2 ?  translate('(Included)') : translate('(Excluded)')}}</td>
                      <td></td>
                      <td>{{ formatNumber(order.shipping_tax) }}</td>
                    </tr>
                    <tr class="total-amount-tr">
                      <td>{{ translate("Total") }}</td>
                      <td></td>
                      <td>
                        {{ formatNumber(order.total_amount) }}
                      </td>
                    </tr>
                    <tr class="payment-tr">
                      <td>{{ translate("Total Paid") }}</td>
                      <td></td>
                      <td>{{ formatNumber(order?.total_paid) }}</td>
                    </tr>
                    <tr class="payment-tr" v-if="order?.total_refund > 0">
                      <td>{{ translate("Total Refund") }}</td>
                      <td></td>
                      <td>- {{ formatNumber(order?.total_refund) }}</td>
                    </tr>
                    <tr
                        class="payment-tr"
                        v-if="order.total_paid - order?.total_refund != 0"
                    >
                      <td>{{ translate("Net Payment") }}</td>
                      <td></td>
                      <td class="font-semibold">
                        {{ getNetPayment() }}
                      </td>
                    </tr>
                    <tr
                        class="payment-tr"
                        v-if="order.total_amount - order?.total_paid > 0"
                    >
                      <td>{{ translate("Total Due") }}</td>
                      <td></td>
                      <td class="font-semibold">
                        {{ getTotalDue() }}
                      </td>
                    </tr>
                    <tr
                        class="payment-tr total-refund-owed-tr"
                        v-if="order.total_amount - (order?.total_paid  - order?.total_refund)< 0"
                    >
                      <td>
                        <p class="flex flex-end m-0 items-center">
                          <WarnTriangleFilled
                              class="w-6 text-amber-500 mr-2"
                          />
                          {{ translate("Total Refund Owed") }}
                        </p>
                      </td>
                      <td></td>
                      <td class="font-semibold">
                        {{ getTotalDue() }}
                      </td>
                    </tr>
                    </tbody>
                  </table>
                </Card.Body>
              </Card.Container>

              <Card.Container
                  v-if="order.transactions && order.transactions.length"
                  v-loading="canceling_subscription"
                  element-loading-text="Cancelling in progress..."
                  class="overflow-hidden"
              >
                <Card.Header
                    :title="translate('Transaction Details')"
                    border_bottom
                    title_size="small"
                >
                  <template #action>
                    <el-dropdown
                        v-if="order.total_amount - order?.total_paid > 0 && order.status !== 'canceled' && markingOrderAsPaid=== false"
                        trigger="click"
                        class="fct-more-option-wrap"
                        popper-class="fct-dropdown"
                        @command="handleCommand"
                    >
                      <el-button size="small">
                        {{ translate("Collect Payments") }}
                        <DynamicIcon name="ChevronDown"/>
                      </el-button>
                      <DynamicIcon name="More"/>
                      <template #dropdown>
                        <el-dropdown-menu>
                          <el-dropdown-item command="custom-payment-link">
                            {{ translate("Custom Payment Link") }}

                            <el-tooltip
                                effect="dark"
                                :content="
                                  translate(
                                    'Due amount is remaining, Get a custom payment link to send to the customer.'
                                  )
                                "
                                placement="top"
                                popper-class="fct-tooltip"
                            >
                              <el-icon>
                                <InfoFilled/>
                              </el-icon>
                            </el-tooltip>
                          </el-dropdown-item>

                          <el-dropdown-item command="mark-order-paid">
                            {{ translate("Mark order as paid") }}
                          </el-dropdown-item>
                        </el-dropdown-menu>
                      </template>
                    </el-dropdown>
                  </template>
                </Card.Header>

                <Card.Body class="p-0">
                  <Transaction
                      @reload="reloadOrder()"
                      @triggerRefundModal="triggerRefundModal"
                      :transactions="order.transactions"
                      :order_id="order.id"
                  />

                  <TransactionMobile
                      @reload="reloadOrder()"
                      :transactions="order.transactions"
                      :order_id="order.id"
                  />
                </Card.Body>
              </Card.Container>

              <!--              mark as paid dialogue-->
              <el-dialog
                  :title="translate('Mark as paid')"
                  v-model="transactionMedium"
              >
                <div>
                  <el-form label-position="top">
                    <el-form-item
                        label-position="top"
                        :label="translate('Payment method')"
                    >
                      <el-select v-model="otherData.payment_method">
                        <el-option
                            v-for="method in paymentMethodOption"
                            :key="method.value"
                            :label="method.name"
                            :value="method.value"
                        />
                      </el-select>
                    </el-form-item>
                    <el-form-item
                        label-position="top"
                        :label="translate('Vendor charge id')"
                    >
                      <el-input v-model="otherData.vendor_charge_id"/>
                    </el-form-item>
                    <el-form-item
                        label-position="top"
                        :label="translate('Notes')"
                    >
                      <el-input
                          :rows="2"
                          type="textarea"
                          :placeholder="
                          translate('Please enter the payment note...')
                        "
                          v-model="otherData.mark_paid_note"
                      >
                      </el-input>
                    </el-form-item>
                  </el-form>
                </div>

                <template #footer>
                  <el-button plan type="primary" @click="markOrderAsPaid" :disabled="markingOrderAsPaid"
                             v-loading="markingOrderAsPaid">
                    {{ translate("Mark Paid") }}
                  </el-button>
                </template>
              </el-dialog>

              <Card.Container v-if="order.licenses" class="overflow-hidden">
                <Card.Header
                    :title="translate('Licenses')"
                    title_size="small"
                >
                </Card.Header>

                <Card.Body class="px-0 pb-0">
                  <license-table
                      :licenses="order.licenses"
                      :columns="['product']"
                  />
                </Card.Body>
              </Card.Container>

              <Card.Container class="overflow-hidden mb-0">
                <Card.Header
                    :title="translate('Activity')"
                    border_bottom
                    title_size="small"
                >
                  <!--                  <el-icon-->
                  <!--                      class="cursor-pointer"-->
                  <!--                      @click="showManageActivity = !showManageActivity"-->
                  <!--                  >-->
                  <!--                    <MoreFilled/>-->
                  <!--                  </el-icon>-->
                </Card.Header>

                <Card.Body>
                  <Activity
                      @reload="reloadOrder"
                      :activities="order.activities || []"
                      :showManageActivity="showManageActivity"
                  />
                </Card.Body>
              </Card.Container>
            </div>
            <!-- .fct-single-order-main -->

            <div class="fct-single-order-aside">
              <div class="fct-admin-sidebar">
                <SubscriptionPlan
                    v-for="(subscription, index) in order.subscriptions"
                    :key="subscription.id"
                    :subscription="subscription"
                    :order="order"
                    :orderId="order_id"
                    :index="index"
                    @fetchOrder="$emit('fetch')"
                    @reload="reloadOrder()"
                    :getOrderUrl="getOrderUrl"
                />

                <OrderCustomerInformation
                    v-if="order.customer"
                    :order="order"
                    :shouldEnableEditing="true"
                />

                <notes
                    :orderId="order.id"
                    :note="order.note"
                    :shouldEnableEditing="true"
                />

                <Labels
                    :bind-to-id="order.id"
                    bind-to-type="Order"
                    :selectedLabels="selectedLabels"
                    :shouldEnableEditing="true"
                    @update:update-label="
                    () => {
                      //onChangeLabel
                    }
                  "
                />

                <utm-details
                    v-if="order?.order_operation"
                    :order_operation="order?.order_operation"
                />

                <TaxInformationWidget :taxId="taxId" />

              </div>

              <div v-if="order">
                <DynamicTemplates
                    filter="single_order_page"
                    :widgets-query="{
                      'order_id': order.uuid
                    }"
                    :data="{ order }"
                />
              </div>

              <!-- .fct-admin-sidebar -->
            </div>
            <!-- .fct-single-order-aside -->
          </div>
          <!-- .fct-single-order -->

          <el-dialog
              v-model="adjustQuantityModal"
              :title="translate('Adjust Quantity')"
              width="35%"
              align-center
              :append-to-body="true"
              class="fct-adjust-quantity-modal"
              @close="
              () => {
                adjustQuantityModal = false;
                adjustItem = {};
                tmpAdjustedItem = {};
              }
            "
          >
            <div class="fct-adjust-quantity-modal-content">
              <div class="title">
                Adjust the quantity for <strong>{{ adjustItem.title }}</strong>
              </div>
              <div class="fct-form-group">
                <label>{{ translate("Quantity") }}</label>
                <NumberInput
                    :item="adjustItem"
                    @update:value="updateQuantity"
                    v-if="isEditingItem"
                />
              </div>
            </div>

            <template #footer>
              <div class="dialog-footer">
                <el-button @click="adjustQuantityModal = false" type="info" soft>
                  {{ translate("Cancel") }}
                </el-button>
                <el-button
                    type="primary"
                    @click="saveAdjustedProductQuantity(tmpAdjustedItem)"
                >
                  {{ translate("Done") }}
                </el-button>
              </div>
            </template>
          </el-dialog>

          <!-- product modal -->
          <AddProductItemModal
              ref="productItemModal"
              :order_id="order_id"
              :searchQueryParent="searchQuery"
              :orderParent="order"
              @update:searchQuery="updateSearchQuery"
          />

          <el-dialog
              :append-to-body="true"
              width="40%"
              v-model="createProductModal"
              :title="translate('Add New Product')"
          >
            <div v-if="createProductModal">
              <add-product-modal
                  :customModal="'order_modal'"
                  @update:createProductModal="updateProductModal"
                  @process_custom="processCustom"
              />
            </div>
          </el-dialog>

          <el-dialog
              :append-to-body="true"
              v-model="showRefundModal"
              :title="translate('Refund Transaction')"
              @close="
              () => {
                changes_made = 0;
                showRefundModal = false;
                fetch();
              }
            "
          >
            <Refund
                v-loading="processing_refund"
                :element-loading-text="translate('Refund initiating on remote...')"
                :order_id="order_id"
                :order="order"
                :showRefundModal="showRefundModal"
                @processing_refund="(state) => (processing_refund = state)"
                :subscription="subscription"
                :is_subscription="isSubscription"
                :subscription_status="subscriptionStatus"
            />
          </el-dialog>

          <el-skeleton animated v-if="loading">
            <el-skeleton-item></el-skeleton-item>
            <el-skeleton-item></el-skeleton-item>
            <el-skeleton-item></el-skeleton-item>
          </el-skeleton>

          <el-dialog
              v-model="nextInvoiceModal"
              :title="translate('Custom payment link')"
          >
            <payment-link
                :order_id="order_id"
                :order="order"
                @reload="fetch"
            ></payment-link>
          </el-dialog>
        </div>
        <!-- .single-page-body -->
      </template>
    </div>
  </div>
</template>

<script setup>
import {ref} from "vue";
import AddProductItemModal from "./Modals/AddProductItemModal.vue";
import Refund from "./Modals/Refund.vue";
import {
  ArrowRight,
  InfoFilled,
  MoreFilled,
  WarnTriangleFilled,
} from "@element-plus/icons-vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import AddProductModal from "../Products/AddProductModal.vue";
import SaveBar from "@/Bits/Components/SaveBar.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import NumberInput from "@/Bits/Components/Inputs/NumberInput.vue";
import PaymentLink from "@/Modules/Orders/Modals/PaymentLink.vue";
import Labels from "@/Modules/Parts/Labels/Label.vue";
import Activity from "@/Modules/Orders/Activity.vue";
import Alert from "@/Bits/Components/Alert.vue";
import DynamicTemplates from "@/Bits/Components/DynamicTemplates/DynamicTemplates.vue";
import LicenseTable from "../../../licensing/components/_LicenseTable.vue";
import SingleOrderLoader from "@/Modules/Orders/Components/SingleOrderLoader.vue";
import NotFound from "@/Pages/NotFound.vue";
import SubscriptionPlan from "@/Modules/Subscriptions/SubscriptionPlan.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import {pluralizeTranslate} from "@/utils/translator/Translator";
import TaxInformationWidget from "@/Modules/Orders/Components/TaxInformationWidget.vue";

const productItemModal = ref();
const fetchBySearch = () => productItemModal.value.fetchBySearch();

const handleBrowseProduct = () => {
  fetchBySearch();
};
</script>

<script type="text/babel">
import OrderBulkActions from "./_OrderBulkActions.vue";
import Transaction from "./_Transaction.vue";
import Plans from "./_Plans.vue";
import {markRaw, nextTick} from "vue";
import {recalculatePayout, discountLabel} from "@/Bits/cartService";
import {
  getUniqueOrderItemCount
} from "../../Bits/productService";
import Badge from "@/Bits/Components/Badge.vue";
import {Refresh} from "@element-plus/icons-vue";
import {ElMessageBox} from "element-plus";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import Notes from "../Parts/Notes/Note.vue";
import Coupon from "./Coupon.vue";
import CustomDiscount from "./CustomDiscount.vue";
import OrderCustomerInformation from "./OrderCustomerInformation.vue";
import ShippingComponent from "./Shipping.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import Arr from "@/utils/support/Arr";
import Alert from "@/Bits/Components/Alert.vue";
import Rest from "@/utils/http/Rest";
import UtmDetails from "@/Modules/Orders/Components/UtmDetails.vue";
import OrderUpDownIndicator from "@/Bits/Components/OrderUpDownIndicator.vue";
import TransactionMobile from "./_TransactionMobile.vue";
import AppConfig from "@/utils/Config/AppConfig";

export default {
  name: "SingleOrder",
  props: ["order_id"],
  components: {
    OrderBulkActions,
    Alert,
    Transaction,
    Plans,
    Badge,
    Refresh,
    ElMessageBox,
    CopyToClipboard,
    Notes,
    Coupon,
    CustomDiscount,
    OrderCustomerInformation,
    ShippingComponent,
    UtmDetails,
    OrderUpDownIndicator,
    TransactionMobile
  },
  data() {
    return {
      notFound: {
        show: false,
        message: "",
        buttonText: "",
        route: "",
      },
      warningMessage: "",
      loading: false,
      calculating: false,
      customModal: false,
      canceling_subscription: false,
      reactivate_subscription: false,
      fetching_subscription: false,
      pausing_subscription: false,
      resuming_subscription: false,
      processing_refund: false,
      nextInvoiceModal: false,
      cartItems: {},
      discount: {},
      shippingSettings: {},
      customItem: {
        item_name: "",
        item_price: 0,
        quantity: 1,
        is_physical: "yes",
      },
      transactionMedium: false,
      otherData: {
        payment_method: "offline_payment",
        mark_paid_note: "",
        vendor_charge_id: ""
      },
      paymentMethodOption: [
        { name: "Others", value: "others" }
      ],
      timeout: "",
      widgets: [],
      order: false,
      searching: false,
      orderSettings: false,
      orderAddModal: false,
      availableProducts: [],
      selectedProducts: [],
      deletedItems: [],
      searchQuery: "",
      actionableActivities: [],
      isSubscription: false,
      subscriptionStatus: "",
      showManageActivity: false,
      change_order_infos: false,
      change_payment_infos: false,
      changes_made: 0,
      createProductModal: false,
      showRefundModal: false,
      isEditingItem: false,
      shouldDisableEditing: false,
      adjustQuantityModal: false,
      adjustItem: [],
      selectedLabels: [],
      tmpAdjustedItem: {},
      coupons: [],
      appliedCoupons: [],
      hasCoupon: false,
      shippingMethods: [],
      otherShippingMethods: [],
      uniqueItemsCount: 0,
      markingOrderAsPaid: false,
      isMobile: window.innerWidth < 768,
      taxId: 0
    };
  },
  watch: {
    order: {
      handler(newVal, oldVal) {
        this.changes_made++;
      },
      deep: true,
    },
    order_id: {
      handler(newVal, oldVal) {
        this.changeTitle("Order #" + newVal);
        this.changes_made = 0;
        this.fetch();
      },
    },
  },
  computed: {
    transactionType() {
      // basically subscription item will be only item in an order if it is a subscription order
      return this.order.order_items.some(
          (item) => item.payment_type === "subscription"
      )
          ? "subscription"
          : "charge";
    },
    placeholderImage() {
      return `${AppConfig.get('asset_url')}images/empty-image.svg`;
    },
  },
  methods: {
    handleResize() {
      this.isMobile = window.innerWidth < 768;
    },
    getDueRefund() {
      return this.formatNumber(
          Math.abs(
              parseFloat(this.order.total_amount - this.order?.total_paid) +
              parseFloat(this.order.total_refund)
          )
      );
    },
    triggerRefundModal(){
      this.showRefundModal = true;
    },
    getNetPayment() {
      return this.formatNumber(
          this.order.total_paid - this.order?.total_refund
      );
    },
    getTotalDue() {
      return this.formatNumber(
          Math.abs(this.order.total_amount - this.order?.total_paid)
      );
    },
    markOrderAsPaid() {
      if(this.markingOrderAsPaid){
        return;
      }
      this.markingOrderAsPaid = true;
      Rest.post("orders/" + this.order_id + "/mark-as-paid", {
        ...this.otherData,
        transaction_type: this.transactionType,
      })
          .then(() => {
            this.markingOrderAsPaid = false;
            this.transactionMedium = false;
            this.reloadOrder();
          })
          .catch((errors) => {
            this.markingOrderAsPaid = false;
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          });
    },
    handleCommand(command) {
      if (command === "custom-payment-link") {
        this.nextInvoiceModal = true;
      } else {
        this.transactionMedium = true;
      }
    },
    // Check for 'adjustment' item and skip subscription item if 'adjustment' item exists
    shouldSkipAdjustmentItem(item) {

      // Flag to track if an 'adjustment' item exists
      const hasSubscriptionItem = this.order.order_items.some(
          (i) => i.payment_type === "subscription"
      );

      // If the current item is 'subscription' item and 'adjustment' item also exists, then skip it
      if (hasSubscriptionItem && item.payment_type === "adjustment") {
        return false;
      }

      // Otherwise, display the item
      return true;
    },
    handleAdjustQuantityModal(product) {
      this.adjustItem = {};
      this.adjustQuantityModal = true;
      this.adjustItem = product;
    },
    getImageUrl(product) {
      const media = product?.variants?.media;
      if (
          media &&
          Array.isArray(media.meta_value) &&
          media.meta_value[0]?.url
      ) {
        return media.meta_value[0].url;
      }

      const featuredMedia = product?.variants?.product_detail?.featured_media;
      if (
          !media &&
          featuredMedia &&
          typeof featuredMedia === "object" &&
          featuredMedia !== null
      ) {
        return featuredMedia.url;
      }

      return this.placeholderImage;
    },
    getClass(product) {
      let thumbnail = null;
      if (product.featured_media != null) {
        thumbnail = this.getImageUrl(product);
      }
      return {
        "w-full": true,
        "border border-solid border-gray-divider rounded":
            thumbnail && thumbnail !== null && thumbnail !== undefined,
      };
    },
    shouldShowRefund() {
      if (!this.order) {
        return false;
      }

      if (this.order.config?.upgraded_to) {
        return false;
      }
      const totalPaid = parseInt(this.order.total_paid / 100);
      return (
          this.order.payment_status !== "refunded" &&
          totalPaid > 0 &&
          totalPaid >= parseInt(this.order.total_refund / 100)
      );
    },
    enableItemEditing() {
      this.isEditingItem = true;
      this.fetchShippingMethods();
    },
    disableItemEditing() {
      this.isEditingItem = false;
    },

    handlePaymentActions(action) {
      this.changes_made = 0;
      if (action === "refund") {
        this.showRefundModal = true;
      } else if (action === "create_next_invoice") {
        this.nextInvoiceModal = true;
      }
    },

    getOrderUrl(parentId) {
      const url =
          AppConfig.get('dashboard_url') +
          "/orders/" +
          parentId +
          "/view";
      window.location = url;
      // return window.location.reload();
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
      this.tmpAdjustedItem = {
        quantity: quantity,
        updatedStock: updatedStock,
        item: item,
      };
    },
    saveAdjustedProductQuantity(tmpAdjustedItem) {
      this.adjustQuantityModal = false;
      if (Object.keys(tmpAdjustedItem).length > 0) {
        if (parseInt(tmpAdjustedItem.updatedStock) < 0) {
          /* translators: %s is the available stock */
          Notify.error(translate('You have exceeded the stock limit! Current stock is : %s', tmpAdjustedItem.updatedStock));
        } else {
          tmpAdjustedItem.item.quantity = tmpAdjustedItem.quantity;
        }
        tmpAdjustedItem.item.updated_stock = tmpAdjustedItem.updatedStock;
        this.calculateLine();
      }
    },
    calculateLine() {
      if (this.hasCoupon) {
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

      //save custom items
      /*this.$post('orders/' + this.order_id + '/create-custom', {
                      product: customItem,
                  })
                      .then(product => {
                          this.order.order_items.unshift(product);
                          this.customModal = false;
                          this.calculateLine();
                      })
                      .catch((errors) => {
                          this.handleError(errors);
                      })
                      .finally(() => {
                          this.loading = false;
                      });*/
    },
    update() {
      this.loading = true;

      const orderData = {...this.order};

      delete orderData["note"];
      Rest.post("orders/" + this.order_id, {
        ...orderData,
        deletedItems: this.deletedItems,
        discount: this.discount,
        shipping: this.shippingSettings,
      })
          .then((response) => {
            Notify.success(response.message);
            this.fetch();
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
    fetch() {
      this.notFound.show = false;
      this.loading = true;
      Rest.get("orders/" + this.order_id, {
        with: ["widgets"], //.....
      })
          .then((response) => {
            this.coupons = response.order.applied_coupons || [];
            this.hasCoupon = this.coupons.length > 0 ? true : false;
            this.appliedCoupons = this.coupons.map((obj) => obj.coupon_id);
            this.order = response.order;

            this.isSubscription =
                this.order?.subscriptions && this.order.subscriptions.length > 0;
            if (this.isSubscription) {
              this.subscriptionStatus = this.order.subscriptions[0]?.status;
            }
            this.shouldDisableEditing =
                ["completed", "archived", "canceled"].includes(this.order.status) ||
                this.isSubscription || this.order.payment_status === "paid";

            if (
                ["completed", "archived", "canceled"].includes(this.order.status)
            ) {
              /* translators: %s is the order status */
              this.warningMessage = translate(
                  "Order cannot be edited once it is %s",
                  this.order.status
              );
            }
            if (this.order.payment_status === "paid") {
              this.warningMessage = translate(
                  "Order cannot be edited once paid."
              );
            }
            if (this.isSubscription) {
              this.warningMessage = translate(
                  "Subscription Order cannot be edited."
              );
            }

            this.orderSettings = response.orderSettings;
            this.widgets = response.widgets;
            this.selectedProducts = this.availableProducts = [];
            this.discount.value = response.discount_meta?.value || 0;
            this.discount.reason = response.discount_meta?.reason || "";
            this.discount.type = response.discount_meta?.type || "amount";
            this.selectedLabels = response.selected_labels || [];
            this.uniqueItemsCount = getUniqueOrderItemCount(response.order);

            if (response.shipping_meta && response.shipping_meta.type) {
              this.shippingSettings = response.shipping_meta;
            }

            this.taxId = response.tax_id;

            discountLabel(this.discount);

            // Find the subscription item
            const subscriptionItem = this.order.order_items.find(
                (item) => item.payment_type === "subscription"
            );

            this.order.order_items = this.order.order_items.map((item) => {
              return {
                ...item,
                quantity: Number(item.quantity), // Convert quantity to numbers
                updated_stock:
                    parseInt(item?.available || item?.variants?.available) +
                    parseInt(item.quantity),
              };
            });

            this.resetUnsaved();
            this.renderActionables();

            //This is important
            nextTick(() => {
              this.changes_made = 0;
            });
          })
          .catch((errors) => {
            if (errors.code === "fluent_cart_entity_not_found") {
              this.notFound.show = true;
              this.notFound.buttonText = Arr.get(errors, "data.buttonText");
              this.notFound.message = Arr.get(errors, "data.message");
              this.notFound.route = Arr.get(errors, "data.route");
            } else {
              if (errors.status_code == '422') {
                Notify.validationErrors(errors);
              } else {
                Notify.error(errors.data?.message);
              }
            }
          })
          .finally(() => {
            this.loading = false;
            if (
                ["completed", "archived", "canceled"].includes(this.order.status) ||
                ["unshippable"].includes(this.order.shipping_status)
            ) {
              this.disableItemEditing();
            }
          });
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
    updateShipping(shipping) {
      this.shippingSettings = shipping;
      this.changes_made++;
    },

    reloadOrder() {
      return window.location.reload();
    },
    addProduct() {
      this.createProductModal = !this.createProductModal;
    },
    clearActivityAction(activity) {
      Rest.put("activity/" + activity.id + "/mark-read", {
        id: activity.id,
        status: "read",
      })
          .then((res) => {
            this.actionableActivities = this.actionableActivities.filter(
                (act) => act.id !== activity.id
            );
            this.handleSuccess(res?.message);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          });
    },
    renderActionables() {
      let activities = this?.order?.activities;
      const filterableStatus = ["warning", "error"];
      this.actionableActivities = activities?.filter(
          (activity) =>
              activity?.read_status === "unread" &&
              filterableStatus.includes(activity?.status)
      );
    },
    getCustomerTotalOrders(purchase_count) {
      return purchase_count > 0
          ? purchase_count +
          " " +
          (purchase_count == 1 ? this.$t("order") : this.$t("orders"))
          : this.$t("No orders");
    },
    onChangeLabel(selectedLabels) {
      this.selectedLabels = selectedLabels;
      this.changes_made++;
    },
    saveNoteModal(note) {
      this.order.note = note;
    },
    hasSubscriptionProduct(items, payment_type) {
      const subscriptionProducts = items.filter((item) => {
        if (
            item.variants &&
            item.variants.other_info &&
            item.variants.other_info.payment_type === payment_type
        ) {
          return true;
        } else if (item.payment_type && item.payment_type === payment_type) {
          return true;
        }
        return false;
      });

      return subscriptionProducts.length > 0;
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
        country_code: this.order.customer.country,
        order_items: filteredOrderItems
      }).then(response => {
        this.shippingMethods = response.shipping_methods;
        this.otherShippingMethods = response.other_shipping_methods;
      }).finally(() => {

      });
    }
  },
  mounted() {
    this.changeTitle("Order #" + this.order_id);
    this.fetch();
    window.onbeforeunload = () => {
      if (this.changes_made > 1) {
        return true;
      }
    };

    const paymentMethods = (AppConfig.get('payment_routes') ?? []).filter(
      method => !method?.upcoming
    );
    paymentMethods.forEach(method => {
       this.paymentMethodOption.unshift({
        name: method?.meta?.title,
        value: method?.path,
      });
    })

    window.addEventListener("resize", this.handleResize);
  },
  beforeUnmount() {
    window.removeEventListener("resize", this.handleResize);
  },
};
</script>
