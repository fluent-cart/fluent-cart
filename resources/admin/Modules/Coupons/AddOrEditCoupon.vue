<template>
  <div class="fct-add-edit-coupon-wrapper fct-layout-width">
    <SaveBar
        :isActive="changes_made > 0 ? 'is-active' : ''"
        :loading="coupon_id ? updating : creating"
        @save="coupon_id ? updateCoupon() : addCoupon()"
        @discard="reloadPage"
        :show-cmnd-icon="true"
        :saveButtonText="coupon_id ? translate('Update') : translate('Create')"
      />

      <SingleCouponLoader v-if="fetchingCoupon"/>

      <template v-if="!fetchingCoupon">
        <div class="single-page-header">
          <el-breadcrumb :separator-icon="ArrowRight">
            <el-breadcrumb-item :to="{ name: 'coupons' }">
              {{ translate("Coupons") }}
            </el-breadcrumb-item>

            <el-breadcrumb-item>
              {{ coupon_id ? translate("Edit Coupon") : translate("Add Coupon") }}
            </el-breadcrumb-item>

            <el-breadcrumb-item v-if="coupon_id">
              {{ coupon.title }}
            </el-breadcrumb-item>
          </el-breadcrumb>
        </div><!-- .single-page-header -->


        <div class="single-page-body">
          <div class="fct-add-edit-coupon">
            <div class="fct-add-edit-coupon-main">
              <div>
                <CardContainer>
                  <CardHeader :title="translate('Basic Information')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                    <div class="grid grid-cols-12 gap-x-5">
                        <div class="col-span-12">
                          <fct-input
                              v-model="coupon.title"
                              :label="translate('Title')"
                              required
                              :placeholder="translate('Enter title')"
                              :class="
                                validationErrors?.hasOwnProperty('title') ? 'is-error' : ''
                              "
                              maxlength="50"
                              id="title"
                              :validation-errors="validationErrors"
                              field-key="title"
                          />
                        </div>

                        <div class="col-span-12 min-[1200px]:col-span-6">
                          <div class="fct-form-field">
                            <div class="flex gap-2 items-center justify-between w-full mb-2.5">
                              <LabelHint :title="translate('Code')" required/>
                              <span
                                  class="text-system-dark text-xs cursor-pointer hover:underline dark:text-gray-50 underline"
                                  @click="generateRandomCouponCode">
                                {{ translate("Generate Random Code") }}
                              </span>
                            </div>

                            <fct-input
                                v-model="coupon.code"
                                :placeholder="translate('Eg. COUPON10')"
                                :class="
                                  validationErrors?.hasOwnProperty('code') ? 'is-error' : ''
                                "
                                maxlength="20"
                            />

                            <ValidationError
                                v-if="validationErrors?.hasOwnProperty('code')"
                                :validation-errors="validationErrors"
                                field-key="code"
                            />
                            <div class="form-note">
                              <p>{{ translate("Customers must enter this code at checkout.") }}</p>
                            </div>
                          </div>
                        </div>

                        <div class="col-span-12 min-[1200px]:col-span-6">
                          <fct-input
                              v-model="coupon.priority"
                              type="number"
                              :controls="false"
                              :label="translate('Coupon Priority')"
                              :tooltip="translate('Coupon_Coupons_Calculated')"
                              :placeholder="translate('Enter priority')"
                              :class="
                                validationErrors?.hasOwnProperty('priority') ? 'is-error' : ''
                              "
                              min="0"
                              max="100"
                              @input="
                                (value) => {
                                  onChangeCouponInput('priority', value);
                                }
                              "
                              @keypress="preventInvalidChars"
                              :validation-errors="validationErrors"
                              field-key="priority"
                          />
                        </div>
                    </div>
                  </CardBody>
                </CardContainer>
                
                
                <CardContainer>
                  <CardHeader :title="translate('Discount Details')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                    <div class="grid grid-cols-12 gap-x-5">
                        <div class="col-span-12 min-[1200px]:col-span-4">
                          <fct-select
                              v-model="coupon.type"
                              :label="translate('Type')"
                              required
                              :placeholder="translate('Select coupon type')"
                              :class="
                                validationErrors?.hasOwnProperty('type')
                                  ? 'is-error'
                                  : ''
                              "
                              default-first-option
                              :options="[
                                {label: translate('Fixed'), value: 'fixed'},
                                {label: translate('Percentage'), value: 'percentage'}
                              ]"
                          />
                        </div>

                        <template v-if="coupon.type === 'fixed' || coupon.type === 'percentage'">
                            <div class="col-span-12 min-[1200px]:col-span-4">
                              <fct-input
                                  v-model="coupon.amount"
                                  type="number"
                                  :controls="false"
                                  :label="
                                    coupon.type === 'percentage'
                                      ?
                                      /* translators: %s - discount percentage */
                                      translate('Discount Percentage (%)')
                                      : translate('Discount Amount')
                                  "
                                  required
                                  placeholder="0.00"
                                  :min="minDiscountAmount"
                                  :max="maxDiscountAmount"
                                  step="0.01"
                                  @input="
                                    (value) => {
                                      onChangeCouponInput('amount', value);
                                    }
                                  "
                                  @keypress="(event) => preventInvalidChars(event, true)"
                                  :validation-errors="validationErrors"
                                  field-key="amount"
                              >
                                <template #prefix>
                                    <span
                                        v-if="coupon.type === 'fixed'"
                                        v-html="appVars.shop.currency_sign"
                                    ></span>

                                    <span v-else>%</span>
                                </template>
                              </fct-input>
                            </div>

                            <div class="col-span-12 min-[1200px]:col-span-4" v-if="coupon.type === 'percentage'">
                                <fct-input
                                    v-model="coupon.conditions.max_discount_amount"
                                    type="number"
                                    :controls="false"
                                    :label="translate('Max Discount Amount')"
                                    :placeholder="translate('leave empty or 0 for no max limit')"
                                    :class="validationErrors?.hasOwnProperty('max_discount_amount') ? 'is-error' : ''"
                                    :validation-errors="validationErrors"
                                    field-key="max_discount_amount"
                                >
                                    <template #prefix>
                                        <span v-html="appVars.shop.currency_sign"></span>
                                    </template>
                                </fct-input>
                            </div>
                        </template>
                      </div>
                  </CardBody>
                </CardContainer>
                
                
                <CardContainer>
                  <CardHeader :title="translate('Usage Restrictions')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                    <div class="grid grid-cols-12 gap-x-5">
                        <div class="col-span-12 min-[1200px]:col-span-6">
                            <fct-input
                                v-model="coupon.conditions.min_purchase_amount"
                                type="number"
                                :controls="false"
                                :label="translate('Min Spend Amount')"
                                :tooltip="translate('Minimum spend (subtotal) allowed to use the coupon.')"
                                placeholder="0.00"
                                :class="
                                  validationErrors?.hasOwnProperty('min_purchase_amount')
                                    ? 'is-error'
                                    : ''
                                "
                                :validation-errors="validationErrors"
                                field-key="min_purchase_amount"
                            >
                                <template #prefix>
                                    <span v-html="appVars.shop.currency_sign"></span>
                                </template>
                            </fct-input>
                        </div>

                        <div class="col-span-12 min-[1200px]:col-span-6">
                            <fct-input
                                v-model="coupon.conditions.max_purchase_amount"
                                type="number"
                                :controls="false"
                                :label="translate('Max Spend Amount')"
                                :tooltip="translate('Set the maximum spend (subtotal) allowed when using the coupon.')"
                                :placeholder="translate('leave empty or 0 for no max limit')"
                                :class="
                                  validationErrors?.hasOwnProperty('max_purchase_amount')
                                    ? 'is-error'
                                    : ''
                                "
                                :validation-errors="validationErrors"
                                field-key="max_purchase_amount"
                            >
                                <template #prefix>
                                    <span v-html="appVars.shop.currency_sign"></span>
                                </template>
                            </fct-input>
                        </div>

                        <div class="col-span-12">
                            <fct-choice
                                v-model="coupon.conditions.min_amount_basis"
                                type="radio"
                                :label="translate('Spend Amount Based On')"
                                :tooltip="translate('Coupon_Choose_Min')"
                                :options="[
                                  {label: translate('Subtotal (items only)'), value: 'subtotal'},
                                  {label: translate('Total (includes shipping & fees)'), value: 'total'}
                                ]"
                            />
                        </div>

                        <div class="col-span-12 min-[992px]:col-span-6">
                            <fct-select
                                v-model="coupon.conditions.included_categories"
                                :label="translate('Include Categories')"
                                multiple
                                :placeholder="translate('Any Category')"
                                filterable
                                remote
                                :reserve-keyword="false"
                                :options="categories"
                            />
                        </div>

                        <div class="col-span-12 min-[992px]:col-span-6">
                            <fct-select
                                v-model="coupon.conditions.excluded_categories"
                                :label="translate('Exclude Categories')"
                                multiple
                                :placeholder="translate('No Categories')"
                                filterable
                                remote
                                :reserve-keyword="false"
                                :options="categories"
                            />
                        </div>

                        <div class="col-span-12">
                            <Field :label="translate('Include Products')">
                                <ProductVariationSelector v-model="coupon.conditions.included_products" />
                            </Field>
                        </div>

                        <div class="col-span-12">
                            <Field :label="translate('Excluded Products')">
                                <ProductVariationSelector v-model="coupon.conditions.excluded_products" />
                            </Field>
                        </div>

                        <div class="col-span-12">
                            <fct-input
                                autocomplete="off"
                                v-model="coupon.conditions.email_restrictions"
                                type="text"
                                :label="translate('By Email Address')"
                                :tooltip="translate('Coupon_List_Email')"
                                :placeholder="translate('email1@domain.com, *@special.com, ... or regex pattern')"
                                :validation-errors="validationErrors"
                                field-key="email_restrictions"
                            />
                        </div>

                        <div class="col-span-12">
                          <fct-choice
                              v-model="coupon.conditions.is_recurring"
                              :label="translate('Recurring Coupon')"
                              :tooltip="translate('Coupon_Mark_Coupon')"
                              true-value="yes"
                              false-value="no"
                              :validation-errors="validationErrors"
                              field-key="is_recurring"
                          >{{ translate('Make this coupon recurring') }}</fct-choice>
                        </div>


                    </div>
                    
                  </CardBody>
                </CardContainer>
                
                <CardContainer>
                  <CardHeader :title="translate('Maximum Discount Uses')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                      <div class="grid grid-cols-12 gap-x-5">
                        <div class="col-span-12 min-[1200px]:col-span-6">
                          <fct-input
                              v-model="coupon.conditions.max_uses"
                              type="number"
                              :controls="false"
                              :label="translate('Total Limit')"
                              :tooltip="translate('Total number of times this coupon can be redeemed across the entire store.')"
                              :placeholder="translate('Total limit')"
                              :class="
                                validationErrors?.hasOwnProperty('max_uses')
                                  ? 'is-error'
                                  : '' || usageError
                              "
                              :min="minLimit"
                              :max="maxLimit"
                              @input="
                                (value) => {
                                  onChangeCouponInput('max_uses', value);
                                }
                              "
                              @keypress="preventInvalidChars"
                              :validation-errors="validationErrors"
                              field-key="max_uses"
                          />
                        </div>

                        <div class="col-span-12 min-[1200px]:col-span-6">
                          <fct-input
                              v-model="coupon.conditions.max_per_customer"
                              type="number"
                              :controls="false"
                              :label="translate('Per Logged in User Limit')"
                              :tooltip="translate('Coupon_Maximum_Logged')"
                              :placeholder="translate('Max limit per logged in user')"
                              :class="
                              validationErrors.hasOwnProperty('max_per_customer')
                                ? 'is-error'
                                : ''
                            "
                              :min="minLimit"
                              :max="maxLimit"
                              @input="
                              (value) => {
                                onChangeCouponInput('max_per_customer', value);
                              }
                            "
                              @keypress="preventInvalidChars"
                              :validation-errors="validationErrors"
                              field-key="max_per_customer"
                          />
                        </div>
                      </div>
                  </CardBody>
                </CardContainer>
                
                <CardContainer>
                  <CardHeader :title="translate('Additional Information')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                      <div class="grid grid-cols-12 gap-x-5">
                        <div class="col-span-12">
                          <fct-choice
                              v-model="coupon.status"
                              type="radio"
                              :label="translate('Status')"
                              :options="[
                                {label: _x('Active', 'Coupon status'), value: 'active'},
                                {label: _x('Disabled', 'Coupon status'), value: 'disabled'},
                                {label: _x('Expired', 'Coupon status'), value: 'expired', disabled: true}
                              ]"
                              :validation-errors="validationErrors"
                              field-key="status"
                          />
                        </div>

                        <div class="col-span-12">
                          <fct-input
                              v-model="coupon.notes"
                              type="textarea"
                              :label="translate('Internal Notes')"
                              :placeholder="translate('Enter note')"
                              :class="
                              validationErrors?.hasOwnProperty('notes')
                                ? 'is-error'
                                : ''
                            "
                              :validation-errors="validationErrors"
                              field-key="notes"
                          />
                        </div>

                        <div class="col-span-12">
                          <fct-choice
                              v-model="coupon.stackable"
                              type="radio"
                              :label="translate('Stackable')"
                              :tooltip="translate('Yes means the coupon can be applied with other coupons')"
                              :options="[
                                {label: translate('Yes'), value: 'yes'},
                                {label: translate('No'), value: 'no'}
                              ]"
                              :validation-errors="validationErrors"
                              field-key="stackable"
                          />
                          <!--Do not remove the below code, will use it later-->
                          <!-- <el-form-item>
                            <template #label>
                              <LabelHint
                                :title="translate('Show on checkout?')"
                                :content="
                                  translate(
                                    'Checked means the coupon code will be displayed on the checkout to the users'
                                  )
                                "
                              />
                            </template>
                            <el-checkbox
                              v-model="coupon.show_on_checkout"
                              :label="translate('Show on checkout?')"
                              true-value="yes"
                              false-value="no"
                            />
                          </el-form-item>
                          <validation-error
                            v-if="validationErrors?.hasOwnProperty('show_on_checkout')"
                            :validation-errors="validationErrors"
                            field-key="show_on_checkout"
                          /> -->

                        </div>
                      </div>
                  </CardBody>
                </CardContainer>





              </div>
            </div><!-- .coupon main -->

            <div class="fct-add-edit-coupon-aside">
                <div class="fct-admin-sidebar">
                  <CardContainer>
                    <CardHeader
                        :title="translate('Summary')"
                        border_bottom
                        title_size="small"
                    />
                    <CardBody>
                      <div class="fct-admin-sidebar-item">
                        <div class="flex items-center gap-1" v-if="coupon.code">
                          <span class="text-sm font-medium text-system-dark dark:text-gray-300">
                            {{ translate('Coupon code') }}: {{ coupon.code }}
                          </span>

                          <CopyToClipboard
                              :text="coupon.code"
                              showMode="basic_copy_btn"
                              :tooltipText="translate('Copy discount code')"
                          />
                        </div>
                        <div v-else>{{ translate("No discount code yet") }}</div>
                      </div>

                      <div class="fct-admin-sidebar-item mt-5 hidden">
                        <h4 class="sidebar-title mb-3">{{ translate("Type & Method") }}</h4>
                        <ul class="fct-bullet-lists">
                          <li>
                            <span>{{ translate("Amount off Products") }}</span>
                          </li>
                          <li>
                            <span>{{ translate("Code") }}</span>
                          </li>
                        </ul>
                      </div>

                      <div class="fct-admin-sidebar-item mt-5 hidden">
                        <h4 class="sidebar-title mb-3">{{ translate("Details") }}</h4>
                        <ul class="fct-bullet-lists">
                          <li>
                            <span>{{ translate("For Online Store") }}</span>
                          </li>
                          <li>
                            <span>{{ translate("No Min Purchase Requirement") }}</span>
                          </li>
                          <li>
                            <span>{{ translate("All Customers") }}</span>
                          </li>
                        </ul>
                      </div>
                    </CardBody>
                  </CardContainer>

                  <CardContainer>
                    <CardHeader
                        :title="translate('Schedule')"
                        border_bottom
                        title_size="small"
                    />
                    <CardBody class="pb-1.5">
                      <div>
                          <div>
                            <fct-date-picker
                                v-model="coupon.start_date"
                                type="datetime"
                                value-format="YYYY-MM-DD HH:mm:ss"
                                :label="translate('Start Date & Time')"
                                :placeholder="translate('Start date & time')"
                                @change="setDefaultStatus"
                                :disabled-date="disabledStartDate"
                            />
                          </div>

                          <div class="mb-[18px] flex">
                            <fct-choice
                                v-model="isEndDateEnabled"
                                @change="
                                (value) => {
                                  // changes_made += coupon.end_date ? 1 : 0;
                                  if (!value) {
                                    coupon.end_date = null;
                                  }
                                }
                              "
                            >{{ translate('Set end date & time') }}</fct-choice>
                          </div>
                          <template v-if="isEndDateEnabled">
                            <div>
                              <fct-date-picker
                                  v-model="coupon.end_date"
                                  type="datetime"
                                  value-format="YYYY-MM-DD HH:mm:ss"
                                  :label="translate('End Date & Time')"
                                  :placeholder="translate('End date & time')"
                                  @change="setDefaultStatus"
                                  :disabled-date="disabledEndDate"
                                  :validation-errors="validationErrors"
                                  field-key="end_date"
                              />
                            </div>
                          </template>
                      </div>
                    </CardBody>
                  </CardContainer>
                </div>

                
                <div v-if="showDynamicTemplates">
                  <DynamicTemplates
                      filter="fluent_cart_single_coupon_page"
                      ref="dynamic_templates"
                      :data="{ coupon }"
                      :widgetsQuery="{
                        coupon_id: coupon.id
                      }"
                      @change="handleChangesMade"
                  />
                </div>
                
                <CardContainer>
                  <CardHeader
                      :title="translate('Activity')"
                      border_bottom
                      title_size="small"
                  />
                  <CardBody>
                    <Activity
                        @reload="fetchCoupon"
                        :activities="coupon.activities || []"
                        :showManageActivity="showManageActivity"
                    />
                  </CardBody>
                </CardContainer>
            </div><!-- .coupon aside -->

          </div>
        </div><!-- .single-page-body -->
      </template>




  </div>
</template>

<script>
import SaveBar from "@/Bits/Components/SaveBar.vue";
import { ArrowRight } from "@element-plus/icons-vue";
import translate, {_x} from "@/utils/translator/Translator";
import { Container as CardContainer, Header as CardHeader, Body as CardBody } from '@/Bits/Components/Card/Card.js';
import LabelHint from "@/Bits/Components/LabelHint.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import Field from "@/Bits/Components/Fct/Field.vue";
import Rest from "@/utils/http/Rest";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import DynamicTemplates from "@/Bits/Components/DynamicTemplates/DynamicTemplates.vue";
import Notify from "@/utils/Notify";
import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";
import {storeNow, storeWallToUtc, toStoreTimezone} from "@/utils/dateFormats";
import {formatNumber} from "@/Bits/productService";
import SingleCouponLoader from "@/Modules/Coupons/SingleCouponLoader.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import Activity from "@/Modules/Orders/Activity.vue";
import {couponStatusForDates} from "@/Modules/Coupons/couponStatus";

// The pickers hold zone-less text in the store timezone; the API takes UTC.
const DATE_FORMAT = "YYYY-MM-DD HH:mm:ss";

// The picker hands over a browser-local Date for the calendar day.
const toDay = (date) => String(date.getFullYear()).padStart(4, "0") + "-" +
    String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");

export default {
  name: "AddOrEditCoupon",
  components: {
    CardContainer,
    CardHeader,
    CardBody,
    LabelHint,
    ValidationError,
    Field,
    CopyToClipboard,
    DynamicTemplates,
    SaveBar,
    ProductVariationSelector,
    SingleCouponLoader
    , Activity
  },
  beforeRouteLeave(to, from, next) {
    if (this.keyboardShortcuts?.unbind) {
      this.keyboardShortcuts.unbind("mod+s");
    }

    next();
  },
  props: {
    coupon_id: {
      type: [String, Number],
      required: true
    }
  },
  data() {
    return {
      changes_made: 0,
      creating: false,
      updating: false,
      keyboardShortcuts: null,
      coupon: {
        type: "fixed",
        status: "active",
        stackable: "no",
        show_on_checkout: "no",
        title: "",
        code: "",
        priority: 0,
        amount: 0,
        start_date: "",
        end_date: "",
        notes: "",
        conditions: {
          buy_quantity: 1,
          get_quantity: 1,
          excluded_categories: [],
          included_categories: [],
          excluded_products: [],
          included_products: [],
          min_purchase_amount: '',
          max_purchase_amount: '',
          min_amount_basis: 'subtotal',
          allowed_user_ids: [],
          allowed_user_roles: [],
          apply_to_quantity: "no",
          apply_to_whole_cart: "yes",
          max_per_customer: '',
          max_discount_amount: '',
          max_uses: '',
          buy_products: [],
          get_products: [],
          is_recurring: "no",
        },
      },
      validationErrors: {},
      minQuantity: 1,
      maxQuantity: 100,
      minPurchaseAmount: 0,
      maxPurchaseAmount: 10000,
      minDiscountAmount: 0,
      minLimit: 0,
      maxLimit: 1000,
      categories: [],
      usageError: false,
      isEndDateEnabled: false,
      fetchingCoupon: false,
      isInitialLoading: false,
      showDynamicTemplates: false
      , showManageActivity: false
    }
  },
  computed: {
    ArrowRight() {
      return ArrowRight
    },
    maxDiscountAmount() {
      return this.coupon.type === "percentage" ? 100 : 999999;
    }
  },
  watch: {
    coupon: {
      handler() {
        this.handleChangesMade();
      },
      deep: true
    }
  },
  mounted() {
    this.keyboardShortcuts = useKeyboardShortcuts();
    this.fetchTerms();

    if(this.coupon_id) {
      this.fetchCoupon();
      
      this.keyboardShortcuts.bind(["mod+s"], (event) => {
        event.preventDefault();
        this.updateCoupon();
      });

      window.onbeforeunload = () => {
        if (this.changes_made > 1) {
          return true;
        }
      };
      
    }else {
      this.keyboardShortcuts.bind(['mod+s'], (event) => {
        event.preventDefault();
        this.addCoupon();
      });
    }
  },
  methods: {
    translate,
    _x,
    generateRandomCouponCode(){
      const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
      let couponCode = "";

      for (let i = 0; i < 8; i++) {
        couponCode += chars.charAt(Math.floor(Math.random() * chars.length));
      }

      this.coupon.code = couponCode;
    },
    onChangeCouponInput(name, value) {
      value = parseInt(value);

      if (isNaN(value) || value === "") {
        this.coupon[name] = "";
        return;
      }

      if (name === "priority") {
        if (value < 0 || value > 100) {
          this.coupon[name] = 0; // set min value to 0
          return;
        }
      }

      if (name === "buy_quantity" || name === "get_quantity") {
        if (value < this.minQuantity || value > this.maxQuantity) {
          this.coupon[name] = this.minQuantity; // set min value to 1
          return;
        }
      }

      if (name === "min_purchase_amount" || name === "max_discount_amount") {
        if (value < this.minPurchaseAmount || value > this.maxPurchaseAmount) {
          this.coupon.conditions[name] = this.minPurchaseAmount; // set min value to 1
          return;
        }
      }

      if (name === "amount") {
        if (
            this.coupon.type === "fixed" &&
            (value < this.minDiscountAmount || value > this.maxDiscountAmount)
        ) {
          this.coupon[name] = this.minDiscountAmount; // set min value to 0
          return;
        }
        if (
            this.coupon.type === "percentage" &&
            (value < this.minDiscountAmount || value > this.maxDiscountAmount)
        ) {
          this.coupon[name] = this.minDiscountAmount; // set min value to 0
          return;
        }
      }

      if (name === "max_uses" || name === "max_per_customer") {
        if (value < this.minLimit || value > this.maxLimit) {
          this.coupon[name] = this.minLimit; // set min value to 0
          return;
        }
      }
    },
    preventInvalidChars(event, shouldExcludeDecimal = false){
      // Define characters to prevent
      let invalidChars = ["-", ".", "+"];
      if (shouldExcludeDecimal) {
        invalidChars = ["-", "+"];
      }
      if (invalidChars.includes(event.key)) {
        event.preventDefault(); // Prevent the character from being entered
      }
    },
    handleChangesMade(){
      this.changes_made++;
    },
    setDefaultStatus(){
      const currentDate = new Date();
      const startDate = this.coupon.start_date
          ? storeWallToUtc(this.coupon.start_date).toDate()
          : null;
      const endDate = this.coupon.end_date
          ? storeWallToUtc(this.coupon.end_date).toDate()
          : null;

      // A turned-off coupon stays off; an "inactive" one shows and saves as Disabled.
      const wasOff = this.coupon.status === "disabled" || this.coupon.status === "inactive";
      this.coupon.status = couponStatusForDates(this.coupon.status, startDate, endDate, currentDate);
      if (wasOff) {
        return;
      }

      if (endDate === null) {
        this.isEndDateEnabled = false;
      }
    },
    disabledStartDate(date){
      return !!date && toDay(date) < storeNow().format("YYYY-MM-DD");
    },
    disabledEndDate(date) {
      const from = this.coupon.start_date
          ? String(this.coupon.start_date).slice(0, 10)
          : storeNow().format("YYYY-MM-DD");
      return !!date && toDay(date) < from;
    },
    toUtcString(wall){
      if (!wall || wall === "0000-00-00 00:00:00") {
        return null;
      }
      const utc = storeWallToUtc(wall);
      return utc.isValid() ? utc.format(DATE_FORMAT) : null;
    },
    toWallString(utcString){
      if (!utcString || utcString === "0000-00-00 00:00:00") {
        return null;
      }
      return toStoreTimezone(utcString).format(DATE_FORMAT);
    },
    formatAmount(){
      if (this.coupon.amount === null) {
        return;
      }

      if (this.coupon.type !== "percentage") {
        this.coupon.amount = formatNumber(this.coupon.amount, false);
      }

      if (this.coupon.conditions.min_purchase_amount !== null) {

          if(!this.coupon.conditions.min_purchase_amount) {
              this.coupon.conditions.min_purchase_amount = '';
          } else {
              this.coupon.conditions.min_purchase_amount = formatNumber(
                  this.coupon.conditions.min_purchase_amount,
                  false
              );
          }
      }

      if (this.coupon.conditions.max_discount_amount !== null) {
          if(!this.coupon.conditions.max_discount_amount) {
              this.coupon.conditions.max_discount_amount = '';
          } else {
              this.coupon.conditions.max_discount_amount = formatNumber(
                  this.coupon.conditions.max_discount_amount,
                  false
              );
          }
      }

      if(!this.coupon.conditions.max_purchase_amount) {
          this.coupon.conditions.max_purchase_amount = '';
      }

      if(!this.coupon.conditions.max_uses) {
          this.coupon.conditions.max_uses = '';
      }

        if(!this.coupon.conditions.max_per_customer) {
            this.coupon.conditions.max_per_customer = '';
        }

        if (!this.coupon.conditions.min_purchase_amount) {
            this.coupon.conditions.min_purchase_amount = '';
        }

    },
    parseProductIds(productIds){
      if (Array.isArray(productIds)) {
        return productIds.map((productId) => parseInt(productId, 10));
      }
      return [];
    },
    reloadPage(){
      this.changes_made = 0;
      return window.location.reload();
    },
    // Dates go out as UTC in the payload only; the form keeps its store-timezone text.
    couponPayload(){
      return {
        ...this.coupon,
        start_date: this.toUtcString(this.coupon.start_date),
        end_date: this.toUtcString(this.coupon.end_date),
        metaValue: this.$refs.dynamic_templates?.getFormStates()
      };
    },
    fetchTerms(){
      Rest.get("products/fetch-term")
        .then((response) => {
          this.categories = response.taxonomies["product-categories"].terms;
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
    fetchCoupon(){
      this.fetchingCoupon = true;
      this.isInitialLoading = true;

      Rest.get(`coupons/${this.coupon_id}`, {})
          .then((response) => {
            response = response.coupon;
            this.coupon = response;
            this.coupon.start_date = this.toWallString(this.coupon.start_date);
            this.coupon.end_date = this.toWallString(this.coupon.end_date);

            if (this.coupon.end_date !== null) {
              this.isEndDateEnabled = true;
            }

            // Parse excluded and included products
            this.coupon.conditions.excluded_products = this.parseProductIds(
                response.conditions.excluded_products
            );

            this.coupon.conditions.included_products = this.parseProductIds(
                response.conditions.included_products
            );

            // Older coupons predate the spend-basis setting and historically compared against the
            // order total, so show 'total' for them to reflect (and preserve) their actual behavior.
            if (!this.coupon.conditions.min_amount_basis) {
                this.coupon.conditions.min_amount_basis = 'total';
            }

            this.formatAmount();

            // Change the status according to the start_date and end_date
            this.setDefaultStatus();
            this.showDynamicTemplates = true;
          })
          .catch((error) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.fetchingCoupon = false;
            this.isInitialLoading = false;
            this.changes_made = 0;
          });
    },
    updateCoupon() {
      this.updating = true;

      this.$refs.dynamic_templates?.beforeUpdatingData({
        data: {
          coupon: this.coupon,
        },
      })

      Rest.put(`coupons/${this.coupon_id}`, this.couponPayload())
          .then((response) => {
            this.$refs.dynamic_templates?.afterUpdatingData({
              coupon: this.coupon,
              response: response,
            });

            Notify.success(response.message);

            this.validationErrors = {};

            this.setDefaultStatus();

            this.changes_made = 0;
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
              this.validationErrors = errors;
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.updating = false;
            this.changes_made = 0;
          });
      
    },
    addCoupon(){
      this.creating = true;

      this.$refs.dynamic_templates?.beforeUpdatingData({
        data: {
          coupon: this.coupon,
        },
      })

      Rest.post('coupons', this.couponPayload())
          .then(async (response) => {
            this.$refs.dynamic_templates?.afterUpdatingData({
              coupon: this.coupon,
              response: response,
            });

            Notify.success(response.message);

            this.validationErrors = {};
            this.changes_made = 0;
            await this.$nextTick();

            await this.$router.push({
              name: 'coupons'
            });
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
              this.validationErrors = errors;
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.creating = false;
          });
    }
  }
}
</script>
