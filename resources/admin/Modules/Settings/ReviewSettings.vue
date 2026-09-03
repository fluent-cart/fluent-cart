<template>
  <div class="setting-wrap">
    <SettingsHeader
        :heading="translate('Product Reviews')"
        :loading="saving"
        @on-save="saveSettings"
    />

    <div class="setting-wrap-inner">
      <div class="fct-review-settings-card" v-if="loading">
        <el-skeleton :loading="loading" animated>
          <template #template>
            <div class="grid gap-3 mb-6">
              <el-skeleton-item variant="p" class="w-[20%]"/>
              <el-skeleton-item variant="p"/>
            </div>
            <div class="grid gap-3 mb-6">
              <el-skeleton-item variant="p" class="w-[20%]"/>
              <el-skeleton-item variant="p"/>
            </div>
          </template>
        </el-skeleton>
      </div>

      <template v-if="!loading">
        <!-- Enable/Disable Card -->
        <div class="fct-review-settings-card">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="fct-review-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
              </div>
              <div>
                <h4 class="text-[15px] font-semibold m-0 leading-tight fct-review-heading">{{ translate('Enable Product Reviews') }}</h4>
                <p class="text-[13px] fct-review-subtext m-0 mt-0.5 leading-tight">{{ translate('Allow customers to leave ratings and reviews on products') }}</p>
              </div>
            </div>
            <el-switch
                v-model="settings.active"
                active-value="yes"
                inactive-value="no"
            />
          </div>
        </div>

        <!-- General Settings Card -->
        <div v-if="settings.active === 'yes'" class="fct-review-settings-card mt-4">

          <!-- General Settings Header -->
          <div class="fct-review-section-divider">
            <h3 class="text-[15px] font-semibold m-0 fct-review-heading">{{ translate('General Settings') }}</h3>
            <p class="text-[13px] fct-review-subtext m-0 mt-0.5">{{ translate('Configure how reviews work on your store') }}</p>
          </div>

            <!-- Who can leave reviews? -->
            <div class="mb-4 pb-4 border-b border-gray-200">
              <h4 class="text-sm font-semibold mb-1 fct-review-heading">{{ translate('Who can leave reviews?') }}</h4>
              <el-radio-group v-model="settings.review_permission_mode">
                <div class="grid grid-cols-3 gap-3">
                  <label
                      v-for="opt in permissionOptions"
                      :key="opt.value"
                      class="fct-review-radio-card"
                      :class="{ 'is-selected': settings.review_permission_mode === opt.value }"
                      @click="settings.review_permission_mode = opt.value"
                  >
                    <el-radio :value="opt.value" class="fct-review-radio-sr-only"/>
                    <span class="block text-[13px] font-semibold fct-review-heading">{{ opt.label }}</span>
                    <span class="block text-[11.5px] fct-review-subtext mt-0.5 leading-tight">{{ opt.desc }}</span>
                  </label>
                </div>
              </el-radio-group>
            </div>

            <!-- Show Verified Owner Badge -->
            <div class="flex items-center justify-between mb-4 pb-4 border-b border-gray-200">
              <div>
                <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Show "Verified Owner" Badge') }}</h4>
                <p class="text-[13px] fct-review-subtext m-0">{{ translate('Display a verified badge on reviews from customers who purchased the product') }}</p>
              </div>
              <el-switch
                  v-model="settings.show_verified_badge"
                  active-value="yes"
                  inactive-value="no"
              />
            </div>

            <!-- Enable Star Ratings -->
            <div class="pb-4 mb-4 border-b border-gray-200">
              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Enable Star Ratings') }}</h4>
                  <p class="text-[13px] fct-review-subtext m-0">{{ translate('Allow customers to rate products with stars') }}</p>
                </div>
                <el-switch
                    v-model="settings.enable_star_rating"
                    active-value="yes"
                    inactive-value="no"
                />
              </div>
              <template v-if="settings.enable_star_rating === 'yes'">
                <div class="ml-6 pl-4 mt-4 border-l-2 border-indigo-200">
                  <div class="flex items-center justify-between">
                    <div>
                      <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Star Ratings Required') }}</h4>
                      <p class="text-[13px] fct-review-subtext m-0">{{ translate('When disabled, customers can submit text-only reviews without a star rating') }}</p>
                    </div>
                    <el-switch
                        v-model="settings.star_rating_required"
                        active-value="yes"
                        inactive-value="no"
                    />
                  </div>
                </div>
              </template>
            </div>

            <!-- Auto-approve Reviews -->
            <div class="flex items-center justify-between mb-4 pb-4 border-b border-gray-200">
              <div>
                <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Auto-approve Reviews') }}</h4>
                <p class="text-[13px] fct-review-subtext m-0">{{ translate('Automatically approve new reviews without moderation') }}</p>
              </div>
              <el-switch
                  v-model="settings.auto_approve_reviews"
                  active-value="yes"
                  inactive-value="no"
              />
            </div>

            <!-- Reviews per page -->
            <div class="flex items-center justify-between pb-4 border-b border-gray-200">
              <div>
                <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Reviews per page') }}</h4>
                <p class="text-[13px] fct-review-subtext m-0">{{ translate('Number of reviews shown per page on the storefront') }}</p>
              </div>
              <el-input-number
                  v-model="settings.reviews_per_page"
                  :min="1"
                  :max="50"
                  size="default"
                  style="width: 150px;"
              />
            </div>

        </div>

        <!-- PRO Features — visible in free with disabled controls, like other pro settings -->
        <div v-if="settings.active === 'yes'" class="fct-review-settings-card mt-4">
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-[15px] font-semibold m-0 fct-review-heading">{{ translate('Review Enhancements') }}</h3>
            <template v-if="!isProActive">
              <DynamicIcon name="Crown" class="fct-pro-icon"/>
              <span class="badge warning fct-review-pro-pill">{{ translate('Pro') }}</span>
            </template>
          </div>
          <p class="text-[13px] fct-review-subtext m-0" :class="{ 'fct-review-section-divider': isProActive }">{{ translate('Enhance reviews with media and votes') }}</p>
          <p v-if="!isProActive" class="text-[13px] fct-review-subtext m-0 fct-review-pro-note fct-review-section-divider">{{ translate('This is a FluentCart Pro feature.') }}</p>

          <!-- Photo Reviews -->
          <div class="pb-3 mb-3 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <div>
                <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Photo Reviews') }}</h4>
                <p class="text-[13px] fct-review-subtext m-0">{{ translate('Allow customers to upload photos with their reviews') }}</p>
              </div>
              <el-switch v-model="settings.enable_photo_reviews" active-value="yes" inactive-value="no" :disabled="!isProActive"/>
            </div>
            <template v-if="isProActive && settings.enable_photo_reviews === 'yes'">
              <div class="ml-6 pl-4 mt-3 border-l-2 border-indigo-200 space-y-3">
                <div class="flex items-center justify-between">
                  <div>
                    <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Max photos per review') }}</h4>
                  </div>
                  <el-input-number v-model="settings.max_photos_per_review" :min="1" :max="10" size="default" style="width: 150px;"/>
                </div>
                <div class="flex items-center justify-between">
                  <div>
                    <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Max file size (KB)') }}</h4>
                  </div>
                  <el-input-number v-model="settings.max_photo_size_kb" :min="1" :max="2000" :step="50" size="default" controls-position="right" style="width: 150px;"/>
                </div>
                <div class="flex items-center justify-between">
                  <div>
                    <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Auto approve photo reviews') }}</h4>
                    <p class="text-[13px] fct-review-subtext m-0">{{ translate('Publish reviews with photos immediately. When off, photo reviews always wait for moderation.') }}</p>
                  </div>
                  <el-switch v-model="settings.auto_approve_photo_reviews" active-value="yes" inactive-value="no"/>
                </div>
                <div>
                  <h4 class="text-sm font-semibold mb-1 fct-review-heading">{{ translate('Allowed file types') }}</h4>
                  <el-checkbox-group v-model="settings.allowed_photo_types">
                    <el-checkbox label="jpeg">JPEG</el-checkbox>
                    <el-checkbox label="png">PNG</el-checkbox>
                    <el-checkbox label="gif">GIF</el-checkbox>
                    <el-checkbox label="webp">WebP</el-checkbox>
                  </el-checkbox-group>
                </div>
              </div>
            </template>
          </div>

          <!-- Helpful Votes -->
          <div class="flex items-center justify-between pb-3 mb-3 border-b border-gray-200">
            <div>
              <h4 class="text-sm font-semibold mb-0.5 fct-review-heading">{{ translate('Helpful Votes') }}</h4>
              <p class="text-[13px] fct-review-subtext m-0">{{ translate('Let visitors mark reviews as helpful or not helpful') }}</p>
            </div>
            <el-switch v-model="settings.enable_helpful_votes" active-value="yes" inactive-value="no" :disabled="!isProActive"/>
          </div>

          <!-- Upgrade CTA (free only) -->
          <div v-if="!isProActive" class="fct-review-upgrade-cta">
            <el-button type="warning" size="small" tag="a" :href="upgradeUrl('feature_lock_review_settings')" target="_blank">
              <DynamicIcon name="Crown" class="mr-1"/>
              {{ translate('Upgrade to Pro') }}
            </el-button>
          </div>

        </div>

        <!-- Bottom save, same block VueForm renders on the other settings screens -->
        <div class="form-section-save-action fct-review-save-action">
          <el-button @click="saveSettings" type="primary" :loading="saving">
            <span v-if="!saving" class="cmd block leading-none">⌘s</span>
            {{ translate('Save') }}
          </el-button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import {onMounted, onBeforeUnmount, reactive, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import AppConfig from "@/utils/Config/AppConfig";
import {upgradeUrl} from "@/Bits/common";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SettingsHeader from "./Parts/SettingsHeader.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";

defineOptions({
  name: "ReviewSettings",
});

const isProActive = AppConfig.get('app_config.isProActive');
const loading = ref(true);
const saving = ref(false);
let settingsLoaded = false;
const keyboardShortcuts = useKeyboardShortcuts();

const permissionOptions = [
  { value: 'verified_buyers', label: translate('Verified buyers only'), desc: translate('Only customers who purchased the product') },
  { value: 'logged_in', label: translate('Logged-in users'), desc: translate('Any logged-in user can leave a review') },
  { value: 'anyone', label: translate('Anyone'), desc: translate('Guests can also leave reviews') },
];

const settings = reactive({
  active: 'yes',
  review_permission_mode: 'verified_buyers',
  auto_approve_reviews: 'no',
  show_verified_badge: 'yes',
  enable_star_rating: 'yes',
  star_rating_required: 'yes',
  reviews_per_page: 10,
  // PRO fields
  enable_photo_reviews: 'no',
  auto_approve_photo_reviews: 'no',
  max_photos_per_review: 5,
  max_photo_size_kb: 1024,
  allowed_photo_types: ['jpeg', 'png', 'gif', 'webp'],
  enable_helpful_votes: 'yes',
});

// Stores all module settings so save doesn't overwrite other modules
let allModuleSettings = {};

const loadSettings = () => {
  loading.value = true;
  settingsLoaded = false;
  Rest.get('settings/modules', {}).then((response) => {
    allModuleSettings = response.settings || {};
    const reviewSettings = allModuleSettings.reviews || {};
    Object.keys(settings).forEach((key) => {
      if (reviewSettings[key] !== undefined) {
        settings[key] = reviewSettings[key];
      }
    });
    settingsLoaded = true;
  }).catch((errors) => {
    Notify.error(errors?.data?.message || errors?.message || translate('Could not load review settings.'));
  }).finally(() => {
    loading.value = false;
  });
};

const saveSettings = () => {
  if (loading.value || !settingsLoaded) return;
  saving.value = true;
  Rest.post('settings/modules', {
    ...allModuleSettings,
    reviews: {...settings}
  }).then(() => {
    Notify.success(translate('Settings saved successfully'));
  }).catch((errors) => {
    Notify.error(errors?.data?.message || errors?.message || translate('Failed to save settings'));
  }).finally(() => {
    saving.value = false;
  });
};

onMounted(() => {
  loadSettings();
  keyboardShortcuts.bind(['mod+s'], (event) => {
    event.preventDefault();
    saveSettings();
  });
});

onBeforeUnmount(() => {
  keyboardShortcuts.unbind(['mod+s']);
});
</script>
