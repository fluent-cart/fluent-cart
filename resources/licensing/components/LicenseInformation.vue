<template>
  <div class="fct-license-info-content">

    <el-form class="fct-license-info-form-hide-on-mobile" :model="license" label-position="top">
      <el-row>
        <el-col :lg="24">
          <el-form-item label="">
            <template #label>
              <div class="flex items-center gap-2">
                {{ translate('License Key') }}
                <CopyToClipboard
                    :text="license.license_key"
                    showMode="basic_copy_btn"
                    :tooltipText="translate('Copy License')"
                />
              </div>
            </template>
            <div class="fct-input-with-action-wrap">
              <el-input v-model="license.license_key" disabled/>
              <div class="fct-input-with-action">
                <el-popconfirm
                    width="250"
                    :confirm-button-text="translate('Confirm')"
                    :cancel-button-text="translate('No, Thanks')"
                    :title="translate('Are you sure to regenerate?')"
                    @confirm="regenerateLicenseKey"
                >
                  <template #reference>
                    <el-button class="el-button--x-small" plain>
                      <DynamicIcon name="Reload"/>
                      {{ translate('Regenerate') }}
                    </el-button>
                  </template>
                </el-popconfirm>
              </div>
            </div>
          </el-form-item>
        </el-col>

        <el-col :lg="24">
          <el-form-item v-if="product != null" :label="translate('Product Name')">
            <div class="fct-license-product">
              <router-link
                  class="link"
                  :to="{
                  name: 'product_edit',
                  params: { product_id: product.ID }
              }">

                {{ product.post_title }}

                <span class="text-xs">
                  &#8211; {{ variations.find(variation => variation.id == license.variation_id)?.variation_title }}
                </span>
              </router-link>

              <!-- <PlanChanger
                  :license="license"
                  :variations="variations"
                  :product_id="product.ID"
                  :variation_id="variation_id"
                  :order="order"
                  :upgrade_path_base="upgrade_path_base"
              /> -->
              <UpgradePlan
                  v-if="variations?.length > 0"
                  :variation_id="variation_id"
                  :product_id="product.ID"
                  :order="order"
                  :props_variations="variations"
                  endpoint_path="products"
                  :has_upgrades="license.has_upgrades && !['expired', 'disabled'].includes(license.status)"
                  :is_admin="true"
              />
            </div>
          </el-form-item>
        </el-col>

        <el-col :lg="12">
          <el-form-item :label="translate('Purchase Date')">
            <span>{{ formatDate(license?.created_at) }}</span>
          </el-form-item>
        </el-col>

        <el-col :lg="12">
          <el-form-item :label="translate('Expirations date')">
            <div class="fct-license-inline-action-wrap">
              <el-date-picker
                  v-if="isExpirationDateEnable"
                  v-model="selectedDate"
                  :placeholder="formatDate(license?.expiration_date) ? formatDate(license?.expiration_date) : translate('Lifetime')"
                  :default-value="license?.expiration_date"
                  type="date"
                  ref="calendar"
                  @change="handleDateChange"
                  class="el-input--x-small mr-2"
              />

              <span v-else class="text">
                {{ license?.expiration_date ? formatDate(license?.expiration_date) : 'Lifetime' }}
              </span>

              <span class="separator" v-if="!isExpirationDateEnable"></span>

              <div class="inline-action-button">
                <a href="#" @click.prevent="handleToggleExpirationDate">
                  {{ isExpirationDateEnable ? translate('Cancel') : translate('Edit') }}
                </a>

                <template v-if="!isExpirationDateEnable && license?.expiration_date">
                  <span class="text">{{ translate('or') }}</span>
                  <a href="#" @click.prevent="handleLifetimeLicense">{{ translate('Mark as lifetime') }}</a>
                </template>
              </div>

            </div>
          </el-form-item>
        </el-col>

        <el-col :lg="12">
          <el-form-item :label="translate('Activation Limit')">
            <div class="fct-license-inline-action-wrap">
              <div v-if="isActivationLimitEnable" class="inline-action-input-group mr-2">
                <el-input-number
                    v-model="limit"
                    :min="0"
                    controls-position="right"
                    @change="handleLimitChange"
                    @keyup="handleLimitChange"
                >
                  <template #decrease-icon>
                    <DynamicIcon class="w-3" name="ChevronDown"/>
                  </template>
                  <template #increase-icon>
                    <DynamicIcon class="w-3" name="ChevronUp"/>
                  </template>
                </el-input-number>

                <div class="fct-license-activation-limit-actions" v-if="isConfirmationIconEnable">
                  <IconButton tag="button" @click.prevent="updateLimit">
                    <DynamicIcon name="Check"/>
                  </IconButton>

                  <IconButton tag="button" @click.prevent="resetLimit">
                    <DynamicIcon name="Cross"/>
                  </IconButton>
                </div>

              </div>

              <span v-if="!isActivationLimitEnable" class="text">
                {{ translateNumber(activations?.length) }} / {{ limit != 0 ? limit : translate('Unlimited') }}
              </span>

              <span class="separator" v-if="!isActivationLimitEnable"></span>

              <div class="inline-action-button">
                <a href="#" @click.prevent="handleToggleActivationLimit">
                  {{ isActivationLimitEnable ? translate('Cancel') : translate('Edit') }}
                </a>

                <template v-if="!isActivationLimitEnable && limit != 0">
                  <span class="text">{{ translate('or') }}</span>
                  <a href="#" @click.prevent="handleActivationLimit">{{ translate('Mark as unlimited') }}</a>
                </template>
              </div>


            </div>
          </el-form-item>
        </el-col>

      </el-row>


    </el-form>

    <!-- mobile view -->
    <div class="fct-license-info-form-show-on-mobile">
      <div class="fct-license-info-mobile-header">
        <Badge :status="license.status" size="small"/>
        <span>{{translate('Purchase Date')}}: {{ formatDate(license?.created_at) }}</span>
      </div><!-- fct-license-info-mobile-header -->

      <div class="fct-license-info-mobile-body">
        <div class="fct-license-product">
          <router-link
              class="link"
              :to="{
                  name: 'product_edit',
                  params: { product_id: product.ID }
              }">

            {{ product.post_title }}

            <span class="text-xs text-system-mid dark:text-gray-300">
              &#8211; {{ variations.find(variation => variation.id == license.variation_id)?.variation_title }}
            </span>
          </router-link>

          <UpgradePlan
              v-if="variations?.length > 0"
              :variation_id="variation_id"
              :product_id="product.ID"
              :order="order"
              :props_variations="variations"
              endpoint_path="products"
              :has_upgrades="license.has_upgrades && !['expired', 'disabled'].includes(license.status)"
              :is_admin="true"
          />
        </div>

        <div class="fct-input-with-action-wrap">
          <el-input v-model="license.license_key" readonly/>
          <div class="fct-input-with-action">
            <el-popconfirm
                width="250"
                :confirm-button-text="translate('Confirm')"
                :cancel-button-text="translate('No, Thanks')"
                :title="translate('Are you sure to regenerate?')"
                @confirm="regenerateLicenseKey"
            >
              <template #reference>
                <div class="fct-input-with-action-icon">
                  <DynamicIcon name="Reload"/>
                </div>
              </template>
            </el-popconfirm>
          </div>
        </div>
      </div><!-- fct-license-info-mobile-body -->

      <div class="fct-license-info-mobile-footer">
        <div class="fct-license-info-mobile-footer-item">
          <div class="title">{{translate('Expirations date')}} </div>
          <div class="fct-license-inline-action-wrap">
            <el-date-picker
                v-if="isExpirationDateEnable"
                v-model="selectedDate"
                :placeholder="formatDate(license?.expiration_date) ? formatDate(license?.expiration_date) : translate('Lifetime')"
                :default-value="license?.expiration_date"
                type="date"
                ref="calendar"
                @change="handleDateChange"
                class="el-input--x-small mr-2"
            />

            <span v-else class="text">
                {{ license?.expiration_date ? formatDate(license?.expiration_date) : 'Lifetime' }}
              </span>

            <span class="separator" v-if="!isExpirationDateEnable"></span>

            <div class="inline-action-button">
              <a href="#" @click.prevent="handleToggleExpirationDate">
                {{ isExpirationDateEnable ? translate('Cancel') : translate('Edit') }}
              </a>

              <template v-if="!isExpirationDateEnable && license?.expiration_date">
                <span class="text">{{ translate('or') }}</span>
                <a href="#" @click.prevent="handleLifetimeLicense">{{ translate('Mark as lifetime') }}</a>
              </template>
            </div>

          </div>
        </div><!-- fct-license-info-mobile-footer-item -->

        <div class="fct-license-info-mobile-footer-item">
          <div class="title">{{translate('Activation Limit')}} </div>
          <div class="fct-license-inline-action-wrap">
            <div v-if="isActivationLimitEnable" class="inline-action-input-group mr-2">
              <el-input-number
                  v-model="limit"
                  :min="0"
                  controls-position="right"
                  @change="handleLimitChange"
                  @keyup="handleLimitChange"
              >
                <template #decrease-icon>
                  <DynamicIcon class="w-3" name="ChevronDown"/>
                </template>
                <template #increase-icon>
                  <DynamicIcon class="w-3" name="ChevronUp"/>
                </template>
              </el-input-number>

              <div class="fct-license-activation-limit-actions" v-if="isConfirmationIconEnable">
                <IconButton tag="button" @click.prevent="updateLimit">
                  <DynamicIcon name="Check"/>
                </IconButton>

                <IconButton tag="button" @click.prevent="resetLimit">
                  <DynamicIcon name="Cross"/>
                </IconButton>
              </div>

            </div>

            <span v-if="!isActivationLimitEnable" class="text">
                {{ activations?.length }} / {{ limit != 0 ? limit : translate('Unlimited') }}
              </span>

            <span class="separator" v-if="!isActivationLimitEnable"></span>

            <div class="inline-action-button">
              <a href="#" @click.prevent="handleToggleActivationLimit">
                {{ isActivationLimitEnable ? translate('Cancel') : translate('Edit') }}
              </a>

              <template v-if="!isActivationLimitEnable && limit != 0">
                <span class="text">{{ translate('or') }}</span>
                <a href="#" @click.prevent="handleActivationLimit">{{ translate('Mark as unlimited') }}</a>
              </template>
            </div>


          </div>
        </div><!-- fct-license-info-mobile-footer-item -->



      </div><!-- fct-license-info-mobile-footer -->

    </div>

    <!-- mobile view -->
  </div>
</template>


<script>
import dayjs from "dayjs";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import PlanChanger from "./PlanChanger.vue";
import UpgradePlan from "@/Modules/Products/UpgradePaths/UpgradePlan.vue";
import {formatDate} from "@/Bits/common";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";

export default {
  name: "LicenseInformation",
  components: {
    Badge,
    CopyToClipboard,
    DynamicIcon,
    IconButton,
    PlanChanger,
    UpgradePlan
  },
  props: {
    license: "license",
    customerId: "customerId",
    license_id: "license_id",
    activations: "activations",
    product: null,
    order: null,
    upgrade_path_base: '',
  },
  data() {
    return {
      isDateSelected: false,
      isCalendarOpen: false,
      selectedDate: null,
      loading: false,
      isConfirmationIconEnable: false,
      limit: this.license.limit,
      variations: [],
      variation_id: this.license.variation_id + '',
      licenseLifetime: false,
      isExpirationDateEnable: false,
      isActivationLimitEnable: false,
    };
  },
  watch: {
    product: {
      handler() {
        if (this.product?.variants) {
          this.variations = this.product.variants
        }
      },
      deep: true,
      immediate: true
    }
  },
  methods: {
    translate,
    formatDate,
    translateNumber,
    handleToggleExpirationDate() {
      this.isExpirationDateEnable = !this.isExpirationDateEnable;
    },
    handleToggleActivationLimit() {
      this.isActivationLimitEnable = !this.isActivationLimitEnable;
    },
    handleLifetimeLicense() {
      this.$confirm(this.$t('Are you sure you want to mark license as lifetime?'), this.$t('Confirm!'), {
        cancelButtonText: this.$t('No,Thanks'),
        confirmButtonText: this.$t('Confirm'),
        type: 'warning'
      }).then(() => {
        this.markAsLifetime();
      }).catch(() => {
        this.licenseLifetime = false;
      });
    },
    handleActivationLimit() {
      this.$confirm(this.$t('Are you sure you want to mark limit as unlimited?'), this.$t('Confirm!'), {
        cancelButtonText: this.$t('No,Thanks'),
        confirmButtonText: this.$t('Confirm'),
        type: 'warning'
      }).then(() => {
        this.markAsUnlimited();
      }).catch(() => {

      });
    },
    handleDateChange() {
      this.newExpirationDate = dayjs(this.selectedDate).format(
          "YYYY-MM-DD HH:mm:ss"
      );
      this.extendValidity();
    },
    handleLimitChange() {
      this.isConfirmationIconEnable = true;
    },
    markAsLifetime() {
      this.licenseLifetime = true;
      this.newExpirationDate = "lifetime";
      this.extendValidity();
    },
    markAsUnlimited() {
      this.activationLimitUnlimited = true;
      this.limit = 'unlimited';
      this.updateLimit();
    },
    regenerateLicenseKey() {
      this.$post("licensing/licenses/" + this.license_id + "/regenerate-key")
          .then((response) => {
            this.$emit("fetchLicense");
            this.handleSuccess(response.message);
          })
          .catch((error) => {
            this.handleError(error);
          })
          .finally(() => {
            this.loading = false;
          });
    },
    extendValidity() {
      this.$post("licensing/licenses/" + this.license_id + "/extend-validity", {
        expiration_date: this.newExpirationDate,
      })
          .then((response) => {
            this.newExpirationDate = null;
            this.$emit("fetchLicense");
            this.handleSuccess(response.message);
            this.selectedDate = null;
            this.licenseLifetime = true;
          })
          .catch((error) => {
            this.handleError(error);
            this.licenseLifetime = false;
          })
          .finally(() => {
            this.loading = false;
          });
    },
    updateLimit() {
      this.$post("licensing/licenses/" + this.license_id + "/update_limit", {
        limit: this.limit,
      })
          .then((response) => {
            this.$emit("fetchLicense");
            this.handleSuccess(response.message);

            this.isConfirmationIconEnable = false;
          })
          .catch((error) => {
            this.handleError(error);
            this.isConfirmationIconEnable = false;
          })
          .finally(() => {
            this.loading = false;
          });
    },
    resetLimit() {
      this.limit = this.license.limit;
      this.isConfirmationIconEnable = false;
    },
  },
  mounted() {
    const inputEl = this.$refs.calendar?.$el.querySelector('input');
    if (inputEl) {
      inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          this.handleDateChange();
        }
      });
    }
  },
};
</script>
