<template>
  <div class="setting-wrap fct-review-settings">
    <SettingsHeader
        :heading="translate('Product Reviews')"
        :loading="saving"
        :show-cmnd-icon="true"
        @on-save="saveSettings"
    />

    <div class="setting-wrap-inner">
      <AdminNotice/>

      <CardContainer>
        <CardBody>
          <!-- The switch leads, as it does on the tax page, and the line
               under the title says what the store is doing right now. -->
          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content flex gap-3.5 flex-1">
              <el-switch v-model="settings.active" active-value="yes" inactive-value="no" :disabled="loading"/>
              <div>
                <LabelHint :title="translate('Enable Product Reviews')"/>
                <p class="fct-inline-tip">
                  {{ settings.active === 'yes' ? translate('Customers can leave ratings and reviews on products.') : translate('Customers cannot leave reviews right now.') }}
                </p>
              </div>
            </div><!-- .fct-setting-form-content -->
          </div><!-- .fct-setting-form-row -->

          <Animation :visible="settings.active === 'yes'" accordion>
            <div v-if="loading" class="fct-setting-form-row">
              <el-skeleton animated class="w-full">
                <template #template>
                  <div class="grid gap-3 mb-6">
                    <el-skeleton-item variant="p" class="w-[20%]"/>
                    <el-skeleton-item variant="p"/>
                  </div>
                  <div class="grid gap-3">
                    <el-skeleton-item variant="p" class="w-[20%]"/>
                    <el-skeleton-item variant="p"/>
                  </div>
                </template>
              </el-skeleton>
            </div>

            <div v-if="!loading">
              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Who can leave reviews?')"/>
                  <p class="fct-inline-tip">
                    {{ translate('Who is offered the review form on a product.') }}
                  </p>
                </div><!-- .fct-setting-form-content -->
                <div class="fct-setting-form-fields self-center">
                  <el-radio-group v-model="settings.review_permission_mode" class="fct-review-stacked-radios">
                    <el-radio v-for="opt in permissionOptions" :key="opt.value" :value="opt.value">
                      <div class="flex flex-col">
                        <span class="fct-review-radio-label">{{ opt.label }}</span>
                        <span class="fct-review-radio-text">{{ opt.desc }}</span>
                      </div>
                    </el-radio>
                  </el-radio-group>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Show \'Verified Owner\' Badge')"/>
                  <p class="fct-inline-tip">
                    {{ translate('Display a verified badge on reviews from customers who purchased the product.') }}
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-switch v-model="settings.show_verified_badge" active-value="yes" inactive-value="no"/>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Enable Star Ratings')"/>
                  <p class="fct-inline-tip">
                    {{ translate('Allow customers to rate products with stars.') }}
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-switch v-model="settings.enable_star_rating" active-value="yes" inactive-value="no"/>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

              <div v-if="settings.enable_star_rating === 'yes'" class="fct-setting-form-row is-child">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Star Ratings Required')"/>
                  <p class="fct-inline-tip">
                    {{ translate('When off, customers can submit text-only reviews without a star rating.') }}
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-switch v-model="settings.star_rating_required" active-value="yes" inactive-value="no"/>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Auto-approve Reviews')"/>
                  <p class="fct-inline-tip">
                    {{ translate('Publish new reviews immediately, without moderation.') }}
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-switch v-model="settings.auto_approve_reviews" active-value="yes" inactive-value="no"/>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Reviews Per Page')"/>
                  <p class="fct-inline-tip">
                    {{ translate('How many reviews a product page lists before paging.') }}
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-input-number v-model="settings.reviews_per_page" :min="1" :max="50"/>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

              <!-- Pro: the rows stay visible in free with the control disabled
                   and the note Features & addon puts under its Pro option. -->
              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Photo Reviews')"/>
                  <p class="fct-inline-tip">
                    {{ translate('Let customers attach photos to their reviews.') }}
                  </p>
                  <p v-if="!isProActive" class="fct-inline-tip">
                    {{ translate('This feature is only available in FluentCart Pro.') }}
                    <el-button size="small" tag="a" link :href="upgradeUrl('feature_lock_review_settings')" target="_blank" rel="noopener noreferrer">
                      {{ translate('Upgrade to Pro') }}
                    </el-button>
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-switch v-model="settings.enable_photo_reviews" active-value="yes" inactive-value="no" :disabled="!isProActive"/>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

              <template v-if="isProActive && settings.enable_photo_reviews === 'yes'">
              <div class="fct-setting-form-row is-child">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Max Photos Per Review')"/>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-input-number v-model="settings.max_photos_per_review" :min="1" :max="10"/>
                </div>
              </div><!-- .fct-setting-form-row -->

              <div class="fct-setting-form-row is-child">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Max File Size (MB)')"/>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-input-number v-model="settings.max_photo_size_mb" :min="0.1" :max="10" :step="0.1" :precision="1" controls-position="right"/>
                </div>
              </div><!-- .fct-setting-form-row -->

              <div class="fct-setting-form-row is-child">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Auto-approve Photo Reviews')"/>
                  <p class="fct-inline-tip">
                    {{ translate('Publish reviews with photos immediately. When off, photo reviews always wait for moderation.') }}
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-switch v-model="settings.auto_approve_photo_reviews" active-value="yes" inactive-value="no"/>
                </div>
              </div><!-- .fct-setting-form-row -->

              <div class="fct-setting-form-row is-child">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Allowed File Types')"/>
                </div>
                <div class="fct-setting-form-fields self-center">
                  <el-checkbox-group v-model="settings.allowed_photo_types">
                    <el-checkbox label="jpeg">JPEG</el-checkbox>
                    <el-checkbox label="png">PNG</el-checkbox>
                    <el-checkbox label="gif">GIF</el-checkbox>
                    <el-checkbox label="webp">WebP</el-checkbox>
                  </el-checkbox-group>
                </div>
              </div><!-- .fct-setting-form-row -->
              </template>

              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content">
                  <LabelHint :title="translate('Helpful Votes')"/>
                  <p class="fct-inline-tip">
                    {{ translate('Let visitors mark reviews as helpful.') }}
                  </p>
                  <p v-if="!isProActive" class="fct-inline-tip">
                    {{ translate('This feature is only available in FluentCart Pro.') }}
                    <el-button size="small" tag="a" link :href="upgradeUrl('feature_lock_review_settings')" target="_blank" rel="noopener noreferrer">
                      {{ translate('Upgrade to Pro') }}
                    </el-button>
                  </p>
                </div>
                <div class="fct-setting-form-fields self-center flex justify-end">
                  <el-switch v-model="settings.enable_helpful_votes" active-value="yes" inactive-value="no" :disabled="!isProActive"/>
                </div><!-- .fct-setting-form-fields -->
              </div><!-- .fct-setting-form-row -->

            </div>
          </Animation>
        </CardBody>
      </CardContainer>
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
import * as Card from "@/Bits/Components/Card/Card.js";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Animation from "@/Bits/Components/Animation.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import SettingsHeader from "./Parts/SettingsHeader.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";

defineOptions({
  name: "ReviewSettings",
});

const CardContainer = Card.Container;
const CardBody = Card.Body;

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
