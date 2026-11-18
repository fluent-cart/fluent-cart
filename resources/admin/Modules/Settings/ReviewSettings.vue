<template>
  <SettingsPage
      class="fct-review-settings"
      :heading="translate('Product Reviews')"
      :loading="saving"
      :show-cmnd-icon="true"
      @on-save="saveSettings"
  >
    <AdminNotice/>

    <Section>
      <SettingRow
          layout="toggle"
          :label="translate('Enable Product Reviews')"
          :description="settings.active === 'yes' ? translate('Customers can leave ratings and reviews on products.') : translate('Customers cannot leave reviews right now.')"
          v-slot="{id}"
      >
        <fct-switch :id="id" v-model="settings.active" active-value="yes" inactive-value="no" :disabled="loading"/>
      </SettingRow>

      <Animation :visible="settings.active === 'yes'" accordion>
        <div v-if="loading" class="fct-setting-form-row">
          <fct-skeleton animated class="w-full">
            <div class="grid gap-3 mb-6">
              <fct-skeleton variant="p" class="w-[20%]"/>
              <fct-skeleton variant="p"/>
            </div>
            <div class="grid gap-3">
              <fct-skeleton variant="p" class="w-[20%]"/>
              <fct-skeleton variant="p"/>
            </div>
          </fct-skeleton>
        </div>

        <div v-if="!loading" class="fct-review-settings__fields">
          <SettingRow group :label="translate('Who can leave reviews?')" :description="translate('Who is offered the review form on a product.')">
            <fct-choice v-model="settings.review_permission_mode" type="radio" :options="permissionOptions" class="fct-review-stacked-radios">
              <template #option="{option}">
                <div class="flex flex-col">
                  <span class="fct-review-radio-label">{{ option.label }}</span>
                  <span class="fct-review-radio-text">{{ option.desc }}</span>
                </div>
              </template>
            </fct-choice>
          </SettingRow>

          <SettingRow layout="inline" :label="translate('Show \'Verified Owner\' Badge')" :description="translate('Display a verified badge on reviews from customers who purchased the product.')" v-slot="{id}">
            <fct-switch :id="id" v-model="settings.show_verified_badge" active-value="yes" inactive-value="no"/>
          </SettingRow>

          <SettingRow layout="inline" :label="translate('Enable Star Ratings')" :description="translate('Allow customers to rate products with stars.')" v-slot="{id}">
            <fct-switch :id="id" v-model="settings.enable_star_rating" active-value="yes" inactive-value="no"/>
          </SettingRow>

          <SettingRow v-if="settings.enable_star_rating === 'yes'" class="is-child" layout="inline" :label="translate('Star Ratings Required')" :description="translate('When off, customers can submit text-only reviews without a star rating.')" v-slot="{id}">
            <fct-switch :id="id" v-model="settings.star_rating_required" active-value="yes" inactive-value="no"/>
          </SettingRow>

          <SettingRow layout="inline" :label="translate('Auto-approve Reviews')" :description="translate('Publish new reviews immediately, without moderation.')" v-slot="{id}">
            <fct-switch :id="id" v-model="settings.auto_approve_reviews" active-value="yes" inactive-value="no"/>
          </SettingRow>

          <SettingRow layout="inline" :label="translate('Reviews Per Page')" :description="translate('How many reviews a product page lists before paging.')" v-slot="{id}">
            <fct-input type="number" :id="id" v-model="settings.reviews_per_page" :min="1" :max="50"/>
          </SettingRow>

          <!-- Pro: the rows stay visible in free with the control disabled
               and the note Features & addon puts under its Pro option. -->
          <SettingRow layout="inline" :label="translate('Photo Reviews')">
            <template #description>
              {{ translate('Let customers attach photos to their reviews.') }}
              <span v-if="!isProActive" class="block">
                {{ translate('This feature is only available in FluentCart Pro.') }}
                <fct-button size="small" tag="a" link :href="upgradeUrl('feature_lock_review_settings')" target="_blank" rel="noopener noreferrer">
                  {{ translate('Upgrade to Pro') }}
                </fct-button>
              </span>
            </template>
            <template #default="{id}">
              <fct-switch :id="id" v-model="settings.enable_photo_reviews" active-value="yes" inactive-value="no" :disabled="!isProActive"/>
            </template>
          </SettingRow>

          <template v-if="isProActive && settings.enable_photo_reviews === 'yes'">
            <SettingRow class="is-child" layout="inline" :label="translate('Max Photos Per Review')" v-slot="{id}">
              <fct-input type="number" :id="id" v-model="settings.max_photos_per_review" :min="1" :max="10"/>
            </SettingRow>

            <SettingRow class="is-child" layout="inline" :label="translate('Max File Size (MB)')" v-slot="{id}">
              <fct-input type="number" :id="id" v-model="settings.max_photo_size_mb" :min="0.1" :max="10" :step="0.1" :precision="1" controls-position="right"/>
            </SettingRow>

            <SettingRow class="is-child" layout="inline" :label="translate('Auto-approve Photo Reviews')" :description="translate('Settings_Publish_Reviews')" v-slot="{id}">
              <fct-switch :id="id" v-model="settings.auto_approve_photo_reviews" active-value="yes" inactive-value="no"/>
            </SettingRow>

            <SettingRow class="is-child" group :label="translate('Allowed File Types')">
              <fct-choice
                  v-model="settings.allowed_photo_types"
                  :options="[{label: 'JPEG', value: 'jpeg'}, {label: 'PNG', value: 'png'}, {label: 'GIF', value: 'gif'}, {label: 'WebP', value: 'webp'}]"
              />
            </SettingRow>
          </template>

          <SettingRow layout="inline" :label="translate('Helpful Votes')">
            <template #description>
              {{ translate('Let visitors mark reviews as helpful.') }}
              <span v-if="!isProActive" class="block">
                {{ translate('This feature is only available in FluentCart Pro.') }}
                <fct-button size="small" tag="a" link :href="upgradeUrl('feature_lock_review_settings')" target="_blank" rel="noopener noreferrer">
                  {{ translate('Upgrade to Pro') }}
                </fct-button>
              </span>
            </template>
            <template #default="{id}">
              <fct-switch :id="id" v-model="settings.enable_helpful_votes" active-value="yes" inactive-value="no" :disabled="!isProActive"/>
            </template>
          </SettingRow>
        </div>
      </Animation>
    </Section>
  </SettingsPage>
</template>

<script setup>
import {onMounted, onBeforeUnmount, reactive, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import AppConfig from "@/utils/Config/AppConfig";
import {upgradeUrl} from "@/Bits/common";
import Animation from "@/Bits/Components/Animation.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import SettingsPage from "./Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import SettingRow from "@/Bits/Components/Layout/SettingRow.vue";
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
  { value: 'verified_buyers', label: translate('Verified buyers only'), desc: translate('Only customers who purchased the product.') },
  { value: 'logged_in', label: translate('Logged-in users'), desc: translate('Any logged-in user can leave a review.') },
  { value: 'anyone', label: translate('Anyone'), desc: translate('Guests can leave reviews too.') },
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
  max_photo_size_mb: 1,
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
