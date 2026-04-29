<template>
  <div class="fct-add-edit-coupon-wrapper fct-layout-width">
    <SaveBar
        :isActive="changes_made > 0 ? 'is-active' : ''"
        :loading="coupon_id ? updating : creating"
        @save="coupon_id ? updateCoupon() : addCoupon()"
        @discard="reloadPage"
        :show-cmnd-icon="true"
        :saveButtonText="coupon_id ? translate('Update') : translate('Create')" 
        :loadingText="coupon_id ? translate('Updating') : translate('Creating')"
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
              <el-form :model="coupon" label-position="top" require-asterisk-position="right">
                <CardContainer>
                  <CardHeader :title="translate('Basic Information')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                    <el-row :gutter="20">
                        <el-col :lg="24">
                          <el-form-item :label="translate('Title')" required>
                            <el-input
                                v-model="coupon.title"
                                :placeholder="translate('Enter title')"
                                :class="
                                  validationErrors?.hasOwnProperty('title') ? 'is-error' : ''
                                "
                                maxlength="50"
                                id="title"
                            ></el-input>

                            <validation-error
                                v-if="validationErrors?.hasOwnProperty('title')"
                                :validation-errors="validationErrors"
                                field-key="title"
                            />
                          </el-form-item>
                        </el-col>

                        <el-col :lg="12">
                          <el-form-item>
                            <div class="flex gap-2 items-center justify-between w-full mb-2.5">
                              <LabelHint :title="translate('Code')" required/>
                              <span
                                  class="text-system-dark text-xs cursor-pointer hover:underline dark:text-gray-50 underline"
                                  @click="generateRandomCouponCode">
                                {{ translate("Generate Random Code") }}
                              </span>
                            </div>

                            <el-input
                                v-model="coupon.code"
                                :placeholder="translate('Eg. COUPON10')"
                                :class="
                                  validationErrors?.hasOwnProperty('code') ? 'is-error' : ''
                                "
                                maxlength="20"
                            ></el-input>

                            <validation-error
                                v-if="validationErrors?.hasOwnProperty('code')"
                                :validation-errors="validationErrors"
                                field-key="code"
                            />
                            <div class="form-note">
                              <p>{{ translate("Customers must enter this code at checkout.") }}</p>
                            </div>
                          </el-form-item>
                        </el-col>

                        <el-col :lg="12">
                          <el-form-item>
                            <template #label>
                              <LabelHint
                                  :title="translate('Coupon Priority')"
                                  :content="
                                  translate(
                                    'Coupons will be calculated based on their priority. The lower the number the higher the priority is.'
                                  )
                                "
                              />
                            </template>
                            <el-input
                                v-model="coupon.priority"
                                type="number"
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
                            ></el-input>

                            <validation-error
                                v-if="validationErrors?.hasOwnProperty('priority')"
                                :validation-errors="validationErrors"
                                field-key="priority"
                            />
                          </el-form-item>
                        </el-col>
                    </el-row>
                  </CardBody>
                </CardContainer>
                
                
                <CardContainer>
                  <CardHeader :title="translate('Discount Details')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                    <el-row :gutter="20">
                        <el-col :lg="8">
                          <el-form-item :label="translate('Type')" required>
                            <el-select
                                v-model="coupon.type"
                                :placeholder="translate('Select coupon type')"
                                :class="
                                  validationErrors?.hasOwnProperty('type')
                                    ? 'is-error'
                                    : ''
                                "
                                default-first-option
                            >
                              <el-option
                                  :label="translate('Fixed')"
                                  value="fixed"
                              ></el-option>
                              <el-option
                                  :label="translate('Percentage')"
                                  value="percentage"
                              ></el-option>
                            </el-select>
                          </el-form-item>
                        </el-col>

                        <template v-if="coupon.type === 'fixed' || coupon.type === 'percentage'">
                            <el-col :lg="8">
                              <el-form-item
                                  :label="
                                    coupon.type === 'percentage'
                                      ?
                                      /* translators: %s - discount percentage */
                                      translate('Discount Percentage (%)')
                                      : translate('Discount Amount')
                                  "
                                  required
                              >
                                <el-input
                                    v-model="coupon.amount"
                                    type="number"
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
                                >
                                  <template #prefix>
                                      <span
                                          v-if="coupon.type === 'fixed'"
                                          v-html="appVars.shop.currency_sign"
                                      ></span>

                                      <span v-else>%</span>
                                  </template>
                                </el-input>

                                <validation-error
                                    v-if="validationErrors?.hasOwnProperty('amount')"
                                    :validation-errors="validationErrors"
                                    field-key="amount"
                                />
                              </el-form-item>
                            </el-col>

                            <el-col :lg="8" v-if="coupon.type === 'percentage'">
                                <el-form-item :label="translate('Max Discount Amount')">
                                    <el-input
                                        v-model="coupon.conditions.max_discount_amount"
                                        type="number"
                                        :placeholder="translate('leave empty or 0 for no max limit')"
                                        :class="validationErrors?.hasOwnProperty('max_discount_amount') ? 'is-error' : ''"
                                    >
                                        <template #prefix>
                                            <span v-html="appVars.shop.currency_sign"></span>
                                        </template>
                                    </el-input>

                                    <validation-error
                                        :validation-errors="validationErrors"
                                        field-key="max_discount_amount"
                                    />
                                </el-form-item>
                            </el-col>
                        </template>
                      </el-row>
                  </CardBody>
                </CardContainer>
                
                
                <CardContainer>
                  <CardHeader :title="translate('Usage Restrictions')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                    <el-row :gutter="20">
                        <el-col :lg="12">
                            <el-form-item>
                                <template #label>
                                    <LabelHint
                                        :title="translate('Min Spend Amount')"
                                        :content="translate('Minimum spend (subtotal) allowed to use the coupon.')"
                                    />
                                </template>
                                <el-input
                                    v-model="coupon.conditions.min_purchase_amount"
                                    type="number"
                                    placeholder="0.00"
                                    :class="
                                      validationErrors?.hasOwnProperty('min_purchase_amount')
                                        ? 'is-error'
                                        : ''
                                    "
                                >
                                    <template #prefix>
                                        <span v-html="appVars.shop.currency_sign"></span>
                                    </template>
                                </el-input>
                                <validation-error
                                    v-if="
                                      validationErrors?.hasOwnProperty('min_purchase_amount')
                                    "
                                    :validation-errors="validationErrors"
                                    field-key="min_purchase_amount"
                                />
                            </el-form-item>
                        </el-col>

                        <el-col :lg="12">
                            <el-form-item>
                                <template #label>
                                    <LabelHint
                                        :title="translate('Max Spend Amount')"
                                        :content="translate('Set the maximum spend (subtotal) allowed when using the coupon.')"
                                    />
                                </template>
                                <el-input
                                    v-model="coupon.conditions.max_purchase_amount"
                                    type="number"
                                    :placeholder="translate('leave empty or 0 for no max limit')"
                                    :class="
                                      validationErrors?.hasOwnProperty('max_purchase_amount')
                                        ? 'is-error'
                                        : ''
                                    "
                                >
                                    <template #prefix>
                                        <span v-html="appVars.shop.currency_sign"></span>
                                    </template>
                                </el-input>
                                <validation-error
                                    v-if="
                                      validationErrors?.hasOwnProperty('max_purchase_amount')
                                    "
                                    :validation-errors="validationErrors"
                                    field-key="max_purchase_amount"
                                />
                            </el-form-item>
                        </el-col>

                        <el-col :md="12">
                            <el-form-item
                                :label="translate('Include Categories')"
                            >
                                <el-select
                                    v-model="coupon.conditions.included_categories"
                                    multiple
                                    :placeholder="translate('Any Category')"
                                    filterable
                                    remote
                                    :reserve-keyword="false"
                                >
                                    <el-option
                                        v-for="category in categories"
                                        :key="category.value"
                                        :label="category.label"
                                        :value="category.value"
                                    ></el-option>
                                </el-select>
                            </el-form-item>
                        </el-col>

                        <el-col :md="12">
                            <el-form-item
                                :label="translate('Exclude Categories')"
                            >
                                <el-select
                                    v-model="coupon.conditions.excluded_categories"
                                    multiple
                                    :placeholder="translate('No Categories')"
                                    filterable
                                    remote
                                    :reserve-keyword="false"
                                >
                                    <el-option
                                        v-for="category in categories"
                                        :key="category.value"
                                        :label="category.label"
                                        :value="category.value"
                                    ></el-option>
                                </el-select>
                            </el-form-item>
                        </el-col>

                        <el-col :md="24">
                            <el-form-item :label="translate('Include Products')">
                                <ProductVariationSelector v-model="coupon.conditions.included_products" />
                            </el-form-item>
                        </el-col>

                        <el-col :md="24">
                            <el-form-item :label="translate('Excluded Products')">
                                <ProductVariationSelector v-model="coupon.conditions.excluded_products" />
                            </el-form-item>
                        </el-col>

                        <el-col :md="24">
                            <el-form-item>
                                <template #label>
                                    <LabelHint
                                        :title="translate('By Email Address')"
                                        :content="translate('List of email addresses that can use the coupon. Separate multiple emails with commas. You may also use regular expressions.')"
                                    />
                                </template>
                                <el-input
                                    autocomplete="off"
                                    v-model="coupon.conditions.email_restrictions"
                                    type="text"
                                    :placeholder="translate('email1@domain.com, *@special.com, ... or regex pattern')"
                                >
                                </el-input>
                                <validation-error
                                    v-if="
                                      validationErrors?.hasOwnProperty('email_restrictions')
                                    "
                                    :validation-errors="validationErrors"
                                    field-key="email_restrictions"
                                />
                            </el-form-item>
                        </el-col>

                        <el-col :md="24">
                          <el-form-item>
                            <template #label>
                              <LabelHint
                                  :title="translate('Recurring Coupon')"
                                  :content="
                                  translate(
                                    'Mark this coupon as recurring to apply it automatically to every future subscription order. This discount will automatically apply on every renewal. However, the coupon will not be applied if it reduces the payable amount to zero.'
                                  )
                                "
                              />
                            </template>
                            <el-checkbox
                                v-model="coupon.conditions.is_recurring"
                                :label="translate('Make this coupon recurring')"
                                true-value="yes"
                                false-value="no"
                            />
                          </el-form-item>
                          <validation-error
                              v-if="validationErrors?.hasOwnProperty('is_recurring')"
                              :validation-errors="validationErrors"
                              field-key="is_recurring"
                          />
                        </el-col>


                    </el-row>
                    
                  </CardBody>
                </CardContainer>
                
                <CardContainer>
                  <CardHeader :title="translate('Maximum Discount Uses')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                      <el-row :gutter="20">
                        <el-col :lg="12">
                          <el-form-item>
                            <template #label>
                              <LabelHint
                                  :title="translate('Total Limit')"
                                  :content="
                                  translate(
                                    'Total number of times this coupon can be redeemed across the entire store.'
                                  )
                                "
                              />
                            </template>
                            <el-input
                                v-model="coupon.conditions.max_uses"
                                type="number"
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
                            ></el-input>

                            <validation-error
                              v-if="validationErrors?.hasOwnProperty('max_uses')"
                              :validation-errors="validationErrors"
                              field-key="max_uses"
                            />
                          </el-form-item>
                        </el-col>

                        <el-col :lg="12">
                          <el-form-item>
                            <template #label>
                              <LabelHint
                                  :title="translate('Per User Limit')"
                                  :content="
                                  translate('Maximum an user can use. 0 means unlimited')
                                "
                              />
                            </template>
                            <el-input
                                v-model="coupon.conditions.max_per_customer"
                                type="number"
                                :placeholder="translate('Max limit per user')"
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
                            ></el-input>
                            <validation-error
                                :validation-errors="validationErrors"
                                field-key="max_per_customer"
                            />
                          </el-form-item>
                        </el-col>
                      </el-row>
                  </CardBody>
                </CardContainer>
                
                <CardContainer>
                  <CardHeader :title="translate('Additional Information')" border_bottom title_size="small"/>
                  <CardBody class="pb-1.5">
                      <el-row :gutter="20">
                        <el-col :lg="24">
                          <el-form-item :label="translate('Status')">
                              <el-radio-group v-model="coupon.status">
                                <el-radio value="active" :label='translate("Active")' />
                                <el-radio value="disabled" :label='translate("Disabled")' />
                                <el-radio disabled value="expired" :label='translate("Expired")' />
                              </el-radio-group>

                            <validation-error
                                :validation-errors="validationErrors"
                                field-key="status"
                            />
                          </el-form-item>
                        </el-col>

                        <el-col :lg="24">
                          <el-form-item :label="translate('Internal Notes')">
                            <el-input
                                v-model="coupon.notes"
                                type="textarea"
                                :placeholder="translate('Enter note')"
                                :class="
                                validationErrors?.hasOwnProperty('notes')
                                  ? 'is-error'
                                  : ''
                              "
                            ></el-input>

                            <validation-error
                                :validation-errors="validationErrors"
                                field-key="notes"
                            />
                          </el-form-item>
                        </el-col>

                        <el-col :lg="24">
                          <el-form-item>
                            <template #label>
                              <LabelHint
                                  :title="translate('Stackable')"
                                  :content="
                                  translate(
                                    'Yes means the coupon can be applied with other coupons'
                                  )
                                "
                              />
                            </template>
                            <el-radio-group v-model="coupon.stackable">
                              <el-radio value="yes">{{ translate("Yes") }}</el-radio>
                              <el-radio value="no">{{ translate("No") }}</el-radio>
                            </el-radio-group>
                          </el-form-item>
                          <validation-error
                              v-if="validationErrors?.hasOwnProperty('stackable')"
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

                        </el-col>
                      </el-row>
                  </CardBody>
                </CardContainer>





              </el-form>
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
                      <el-form label-position="top">
                        <el-row :gutter="20">
                          <el-col :lg="24">
                            <el-form-item :label="translate('Start Date & Time')">
                              <el-date-picker
                                  v-model="coupon.start_date"
                                  type="datetime"
                                  value-format="YYYY-MM-DDTHH:mm:ssZ"
                                  :placeholder="translate('Start date & time')"
                                  @change="setDefaultStatus"
                                  :disabled-date="disabledStartDate"
                              ></el-date-picker>
                            </el-form-item>
                          </el-col>

                          <el-col :lg="24">
                            <el-form-item>
                              <el-checkbox
                                  v-model="isEndDateEnabled"
                                  :label="translate('Set end date & time')"
                                  @change="
                                  (value) => {
                                    // changes_made += coupon.end_date ? 1 : 0;
                                    if (!value) {
                                      coupon.end_date = null;
                                    }
                                  }
                                "
                              />
                            </el-form-item>
                          </el-col>
                          <template v-if="isEndDateEnabled">
                            <el-col :lg="24">
                              <el-form-item :label="translate('End Date & Time')">
                                <el-date-picker
                                    v-model="coupon.end_date"
                                    type="datetime"
                                    value-format="YYYY-MM-DDTHH:mm:ssZ"
                                    :placeholder="translate('End date & time')"
                                    @change="setDefaultStatus"
                                    :disabled-date="disabledEndDate"
                                ></el-date-picker>
                                <validation-error
                                    v-if="validationErrors.hasOwnProperty('end_date')"
                                    :validation-errors="validationErrors"
                                    field-key="end_date"
                                />
                              </el-form-item>
                            </el-col>
                          </template>
                        </el-row>
                      </el-form>
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
import translate from "@/utils/translator/Translator";
import { Container as CardContainer, Header as CardHeader, Body as CardBody } from '@/Bits/Components/Card/Card.js';
import LabelHint from "@/Bits/Components/LabelHint.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import Rest from "@/utils/http/Rest";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import DynamicTemplates from "@/Bits/Components/DynamicTemplates/DynamicTemplates.vue";
import dayjs from "dayjs";
import Notify from "@/utils/Notify";
import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";
import dayjs_plugin_utc from "dayjs/plugin/utc";
import dayjs_plugin_timezone from "dayjs/plugin/timezone";
import {formatNumber} from "@/Bits/productService";
import SingleCouponLoader from "@/Modules/Coupons/SingleCouponLoader.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import Activity from "@/Modules/Orders/Activity.vue";


dayjs.extend(dayjs_plugin_utc);
dayjs.extend(dayjs_plugin_timezone);

export default {
  name: "AddOrEditCoupon",
  props: {
    coupon_id: {
      type: [String, Number],
      required: true
    }
  },
  components: {
    CardContainer,
    CardHeader,
    CardBody,
    LabelHint,
    ValidationError,
    CopyToClipboard,
    DynamicTemplates,
    SaveBar,
    ProductVariationSelector,
    SingleCouponLoader
    , Activity
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
  methods: {
    translate,
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
          ? new Date(this.coupon.start_date)
          : null;
      const endDate = this.coupon.end_date
          ? new Date(this.coupon.end_date)
          : null;

      if (this.coupon.status === "disabled") {
        return;
      }

      if (endDate === null) {
        this.isEndDateEnabled = false;
      }

      if (!startDate && !endDate) {
        this.coupon.status = "active";
      } else if (startDate && currentDate < startDate) {
        this.coupon.status = "scheduled";
      } else if (endDate && currentDate > endDate) {
        this.coupon.status = "expired";
      } else {
        this.coupon.status = "active";
      }
    },
    disabledStartDate(date){
      // return date < new Date();
      const today = new Date();
      today.setHours(0, 0, 0, 0); // Set time to midnight to only compare dates
      return date && date.getTime() < today.getTime();
    },
    disabledEndDate(date) {
      // return date < this.coupon.start_date;
      const today = this.coupon.start_date
          ? new Date(this.coupon.start_date)
          : new Date();
      today.setHours(0, 0, 0, 0); // Set time to midnight to only compare dates
      return date && date.getTime() < today.getTime();
    },
    formatDate(date){
      if (!date || date === "0000-00-00 00:00:00") {
        return null;
      }
      return dayjs(date).format("YYYY-MM-DD HH:mm:ssZ");
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
      return window.location.reload();
    },
    validateAndFormatDate(date){
      let formattedDate = this.formatDate(date);
      return formattedDate !== null && formattedDate !== "Invalid date"
          ? formattedDate
          : null;
    },
    validateDate(){
      this.coupon.start_date = this.validateAndFormatDate(this.coupon.start_date);
      this.coupon.end_date = this.validateAndFormatDate(this.coupon.end_date);
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

            const startDateRaw = response?.coupon?.start_date;
            const endDateRaw = response?.coupon?.end_date;


            if (startDateRaw && '0000-00-00 00:00:00' !== startDateRaw) {
              response.coupon.start_date = dayjs.utc(startDateRaw).tz(dayjs.tz.guess()).format("YYYY-MM-DD HH:mm:ss");
            }

            if (endDateRaw && '0000-00-00 00:00:00' !== endDateRaw) {
              response.coupon.end_date = dayjs.utc(endDateRaw).tz(dayjs.tz.guess()).format("YYYY-MM-DD HH:mm:ss")
            }


            response = response.coupon;
            this.coupon = response;
            this.coupon.start_date = this.formatDate(this.coupon.start_date);
            this.coupon.end_date = this.formatDate(this.coupon.end_date);

            if (this.coupon.end_date !== null) {
              this.isEndDateEnabled = true;
            }

            this.coupon.end_date =
                this.coupon.end_date !== null &&
                this.coupon.end_date !== "Invalid date"
                    ? this.coupon.end_date
                    : null;

            // Parse excluded and included products
            this.coupon.conditions.excluded_products = this.parseProductIds(
                response.conditions.excluded_products
            );

            this.coupon.conditions.included_products = this.parseProductIds(
                response.conditions.included_products
            );

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
            console.error('Error fetching coupon: ', error);
          })
          .finally(() => {
            this.fetchingCoupon = false;
            this.isInitialLoading = false;
            this.changes_made = 0;
          });
    },
    updateCoupon() {
      this.updating = true;
      this.validateDate();

      this.$refs.dynamic_templates?.beforeUpdatingData({
        data: {
          coupon: this.coupon,
        },
      })

      Rest.put(`coupons/${this.coupon_id}`, {
        ...{
          ...this.coupon,
          metaValue: this.$refs.dynamic_templates?.getFormStates()
        }
      })
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
      this.validateDate();

      this.$refs.dynamic_templates?.beforeUpdatingData({
        data: {
          coupon: this.coupon,
        },
      })

      Rest.post('coupons', {
        ...{
          ...this.coupon,
          metaValue: this.$refs.dynamic_templates?.getFormStates()
        }
      })
          .then((response) => {
            this.$refs.dynamic_templates?.afterUpdatingData({
              coupon: this.coupon,
              response: response,
            });

            Notify.success(response.message);
            
            this.$router.push({
              name: 'coupons'
            });

            this.validationErrors = {};
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
  beforeRouteLeave(to, from, next) {
    if (this.keyboardShortcuts?.unbind) {
      this.keyboardShortcuts.unbind("mod+s");
    }

    next();
  }
}
</script>
