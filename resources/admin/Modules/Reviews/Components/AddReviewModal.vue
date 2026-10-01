<template>
  <el-dialog
      v-model="visible"
      class="fct-add-review-modal"
      :title="translate('Add Review')"
      width="560px"
      align-center
      append-to-body
      destroy-on-close
      :close-on-click-modal="!saving"
      :close-on-press-escape="!saving"
      @opened="focusFirstField"
      @closed="resetForm"
  >
    <!-- Same dismissal as the other admin dialogs: a click on the backdrop
         or Escape closes it, except while a save is in flight. Enter inside
         the textarea is a newline, so the keyboard submit is Ctrl/Cmd+Enter. -->
    <div
        ref="formRef"
        class="fct-add-review-form"
        @keydown.ctrl.enter.prevent="submit"
        @keydown.meta.enter.prevent="submit"
    >
      <!-- A server reply that names no visible field. Same component as the
           per-field messages, so it reads the same. -->
      <ValidationError :validation-errors="validationErrors" field-key="general"/>

      <!-- Product picker only when the caller did not name one. Callers that
           already sit on a product (the product page's Reviews tab) pass
           product-id and get the variation row below instead.

           A product with variations is a group, and its variations are the
           options: what a review is about is the variation, not the product,
           so the product itself is not selectable there. A simple product has
           nothing to tell apart and is one plain option. That is the whole
           rule — the shape of the list says which products have items, with
           no second control to keep in step. -->
      <div v-if="!productId" class="fct-add-review-field">
        <label class="fct-add-review-label" for="fct_add_review_product">
          {{ translate('Select Product') }} <span class="fct-add-review-required">*</span>
        </label>
        <el-select
            id="fct_add_review_product"
            v-model="selectionKey"
            class="w-full"
            :class="hasError('post_id') || hasError('item_id') ? 'is-error' : ''"
            :aria-invalid="hasError('post_id') || hasError('item_id') ? 'true' : undefined"
            filterable
            remote
            :reserve-keyword="false"
            :remote-method="searchProducts"
            :loading="productsLoading"
            :placeholder="translate('Search a product')"
            popper-class="fct-add-review-product-options"
            @focus="onProductFocus"
            @change="onSelectionChanged"
        >
          <template v-for="product in products" :key="product.id">
            <el-option-group v-if="product.variations.length" :label="product.title">
              <el-option
                  v-for="variation in product.variations"
                  :key="variation.id"
                  :label="variation.title"
                  :value="variationKey(product, variation)"
              />
            </el-option-group>
            <el-option
                v-else
                :label="product.title"
                :value="productKey(product)"
            />
          </template>
        </el-select>
        <ValidationError :validation-errors="validationErrors" field-key="post_id"/>
        <ValidationError :validation-errors="validationErrors" field-key="item_id"/>
      </div>

      <!-- The caller named the product, so only the variation is still open.
           Shown once that product turns out to have variations worth telling
           apart; a simple product never grows a row that offers one choice.
           Optional either way: leaving it empty files the review against the
           product as a whole, which is what every review filed before this
           existed did. -->
      <div v-else-if="variations.length" class="fct-add-review-field">
        <label class="fct-add-review-label" for="fct_add_review_variation">
          {{ translate('Variation') }}
        </label>
        <el-select
            id="fct_add_review_variation"
            v-model="form.item_id"
            class="w-full"
            :class="hasError('item_id') ? 'is-error' : ''"
            :aria-invalid="hasError('item_id') ? 'true' : undefined"
            clearable
            filterable
            :placeholder="translate('Whole product (no specific variation)')"
            @focus="clearValidationError('item_id')"
        >
          <el-option
              v-for="variation in variations"
              :key="variation.id"
              :label="variation.title"
              :value="variation.id"
          />
        </el-select>
        <ValidationError :validation-errors="validationErrors" field-key="item_id"/>
      </div>

      <div v-if="starRatingEnabled" class="fct-add-review-field">
        <span class="fct-add-review-label">
          {{ translate('How would you rate this product?') }}
          <span v-if="starRatingRequired" class="fct-add-review-required">*</span>
        </span>
        <StarRatingInput
            v-model="form.rating"
            :class="hasError('rating') ? 'is-error' : ''"
            :label="translate('Review rating')"
            @update:model-value="clearValidationError('rating')"
        />
        <ValidationError :validation-errors="validationErrors" field-key="rating"/>
      </div>

      <div class="fct-add-review-field">
        <label class="fct-add-review-label" for="fct_add_review_title">{{ translate('Review title') }}</label>
        <el-input
            id="fct_add_review_title"
            v-model="form.title"
            maxlength="192"
            :class="hasError('title') ? 'is-error' : ''"
            :placeholder="translate('Summarize your experience')"
            :aria-invalid="hasError('title') ? 'true' : undefined"
            @focus="clearValidationError('title')"
        />
        <ValidationError :validation-errors="validationErrors" field-key="title"/>
      </div>

      <div class="fct-add-review-field">
        <label class="fct-add-review-label" for="fct_add_review_content">
          {{ translate('Your review') }} <span class="fct-add-review-required">*</span>
        </label>
        <el-input
            id="fct_add_review_content"
            v-model="form.content"
            type="textarea"
            :rows="4"
            maxlength="5000"
            show-word-limit
            :class="hasError('content') ? 'is-error' : ''"
            :placeholder="translate('What did you like or dislike? How did you use this product?')"
            :aria-invalid="hasError('content') ? 'true' : undefined"
            @focus="clearValidationError('content')"
        />
        <ValidationError :validation-errors="validationErrors" field-key="content"/>
      </div>

      <div class="fct-add-review-row">
        <div class="fct-add-review-field">
          <label class="fct-add-review-label" for="fct_add_review_name">
            {{ translate('Reviewer name') }} <span class="fct-add-review-required">*</span>
          </label>
          <el-input
              id="fct_add_review_name"
              v-model="form.reviewer_name"
              maxlength="100"
              :class="hasError('reviewer_name') ? 'is-error' : ''"
              :placeholder="translate('Name shown on the review')"
              :aria-invalid="hasError('reviewer_name') ? 'true' : undefined"
            @focus="clearValidationError('reviewer_name')"
          />
          <ValidationError :validation-errors="validationErrors" field-key="reviewer_name"/>
        </div>

        <div class="fct-add-review-field">
          <label class="fct-add-review-label" for="fct_add_review_email">{{ translate('Reviewer email') }}</label>
          <el-input
              id="fct_add_review_email"
              v-model="form.reviewer_email"
              type="email"
              maxlength="192"
              :class="hasError('reviewer_email') ? 'is-error' : ''"
              :placeholder="translate('name@example.com (optional)')"
              :aria-invalid="hasError('reviewer_email') ? 'true' : undefined"
            @focus="clearValidationError('reviewer_email')"
          />
          <ValidationError :validation-errors="validationErrors" field-key="reviewer_email"/>
        </div>
      </div>

      <!-- Two columns only while both controls are here. With the verified
           toggle hidden, a half-width Status select would sit beside an empty
           cell, reading as a field that lost its neighbour rather than a row
           with one thing in it. -->
      <div class="fct-add-review-row" :class="{'fct-add-review-row-single': !showVerifiedBadge}">
        <div class="fct-add-review-field">
          <label class="fct-add-review-label" for="fct_add_review_status">{{ translate('Status') }}</label>
          <el-select id="fct_add_review_status" v-model="form.status" class="w-full">
            <el-option :label="translate('Approved')" value="approved"/>
            <el-option :label="translate('Pending')" value="pending"/>
          </el-select>
        </div>

        <!-- No label of its own: the toggle's own text says what it does,
             on one line, and the row sits level with the Status select
             beside it instead of pushing the dialog taller.

             Hidden when the store does not show the Verified Owner badge:
             the flag's only effect is that badge, so with it off this would
             set something no shopper ever sees. -->
        <div v-if="showVerifiedBadge" class="fct-add-review-field fct-add-review-field-inline">
          <div class="fct-add-review-switch">
            <el-switch v-model="form.is_verified" :aria-label="translate('Mark as verified purchase')"/>
            <span class="fct-add-review-label">{{ translate('Verified purchase') }}</span>
          </div>
        </div>
      </div>

      <!-- Attachments. Pro declares the capability and its limits through
           admin_app_data and owns the upload endpoint; the picker itself
           lives here because this modal owns the form.

           Two different absences, two different treatments. Photo Reviews
           switched OFF in store settings removes the row entirely — the store
           has made a decision, and a locked upsell for something they have
           already chosen not to use is noise. No Pro (or a Pro too old to
           declare the capability) keeps the row as a locked affordance, so the
           feature stays discoverable to a store that has not met it yet. -->
      <div v-if="photoReviewsEnabled" class="fct-add-review-field">
        <span class="fct-add-review-label">{{ translate('Attachments') }}</span>

        <template v-if="canAttach">
          <!-- The product gallery's look: square tiles with a remove control
               on hover, and a dashed add tile — full width while empty, the
               last cell of the grid once photos are in. -->
          <ul v-if="form.media.length" class="fct-add-review-media">
            <li v-for="item in form.media" :key="item.key" class="fct-add-review-media-item">
              <img :src="item.url" :alt="item.name || ''"/>
              <button
                  type="button"
                  class="fct-add-review-media-remove"
                  :aria-label="translate('Remove attachment')"
                  @click="removeMedia(item.key)"
              >&times;</button>
            </li>
            <li v-if="form.media.length < maxPhotos" class="fct-add-review-media-item is-add">
              <button
                  type="button"
                  class="fct-add-review-media-add"
                  :aria-label="translate('Add attachments')"
                  @click="pickFiles"
              >
                <DynamicIcon name="GalleryAdd"/>
              </button>
            </li>
          </ul>

          <button
              v-else
              type="button"
              class="fct-add-review-media-add is-empty"
              @click="pickFiles"
          >
            <DynamicIcon name="GalleryAdd"/>
            <span>{{ translate('Add attachments') }}</span>
          </button>

          <span class="fct-add-review-hint">{{ attachHint }}</span>

          <input
              ref="fileInputRef"
              type="file"
              multiple
              class="fct-add-review-file-input"
              :accept="mediaConfig.accept"
              @change="onFilesPicked"
          />
        </template>

        <div v-else class="fct-add-review-locked">
          <el-button disabled class="fct-add-review-locked-btn">
            <DynamicIcon name="Crown"/>
            {{ translate('Add attachment') }}
          </el-button>
          <span class="fct-add-review-hint">
            {{ lockedHint }}
            <a
                v-if="!isProActive && upgradeLink"
                class="fct-add-review-upgrade"
                :href="upgradeLink"
                target="_blank"
                rel="noopener noreferrer"
            >
              {{ translate('Upgrade to Pro') }}
            </a>
          </span>
        </div>
      </div>

    </div>

    <template #footer>
      <el-button :disabled="saving" @click="visible = false">{{ translate('Cancel') }}</el-button>
      <el-button type="primary" :loading="saving" :disabled="saving" @click="submit">
        {{ translate('Add Review') }}
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup>
import {computed, onBeforeUnmount, ref, watch} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import StarRatingInput from "@/Bits/Components/StarRatingInput.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import AppConfig from "@/utils/Config/AppConfig";
import {upgradeUrl} from "@/Bits/common";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  // When given, the review is attached to this product and the picker is
  // hidden. Left null on the reviews list page, where the admin chooses.
  productId: {
    type: Number,
    default: null
  }
});

const emit = defineEmits(['update:modelValue', 'created', 'update:saving']);

const visible = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
});

// The store's review settings, read from the payload the admin app is already
// given. This form offers what the store has switched on and nothing else: a
// control for a disabled feature is a promise the rest of the product does not
// keep — the storefront hides it, the API may refuse it, and a moderator would
// be setting something no one ever sees.
const reviewSettings = AppConfig.get('modules_settings.reviews') || {};
const starRatingEnabled = reviewSettings.enable_star_rating === 'yes';
const starRatingRequired = starRatingEnabled && reviewSettings.star_rating_required === 'yes';
// Absent reads as on, matching ProductReviewRenderer's storefront default.
const showVerifiedBadge = reviewSettings.show_verified_badge === undefined
    || reviewSettings.show_verified_badge === 'yes';
// Photos are off unless switched on — the same direction the setting defaults
// in, and the one that cannot offer an upload the handler would refuse.
const photoReviewsEnabled = reviewSettings.enable_photo_reviews === 'yes';

const emptyForm = () => ({
  post_id: props.productId || null,
  // The variation being reviewed. null is the product-level slot — the same
  // meaning item_id NULL carries on the row.
  item_id: null,
  // No star chosen until the admin chooses one: a form that opens on five
  // stars files a five-star review for anyone who never looked at the row.
  rating: 0,
  title: '',
  content: '',
  reviewer_name: '',
  reviewer_email: '',
  status: 'approved',
  is_verified: false,
  // Uploaded-but-not-yet-attached photos: {id, url, name}. Pro claims them
  // by id when the review saves; anything abandoned here is swept by its
  // orphaned-media cron.
  media: []
});

const form = ref(emptyForm());
const saving = ref(false);
const formRef = ref(null);

// Validation comes from ReviewCreateRequest as {field: {rule: message}};
// the form only outlines the field and prints the message under it.
const validationErrors = ref({});
const hasError = (field) => Object.prototype.hasOwnProperty.call(validationErrors.value, field);
// The product editor's behaviour (ProductEditModel.clearValidationError):
// a field's error goes the moment it is touched, so a message never sits
// under a field that is already being fixed. Each field clears its own;
// the rest stand until the next submit answers for them.
const clearValidationError = (field) => {
  if (Object.prototype.hasOwnProperty.call(validationErrors.value, field)) {
    delete validationErrors.value[field];
  }
};

// Put the keyboard where the work starts once the dialog has rendered: the
// product search when the admin has to pick one, otherwise the first star
// (or the title, with ratings off). Element Plus focuses the dialog itself,
// which leaves a keyboard user tabbing to reach the first field.
const focusFirstField = () => {
  const root = formRef.value;
  if (!root) {
    return;
  }
  // Element Plus puts the select's id on its inner input, but that has
  // varied between versions — accept the id on the input or on a wrapper.
  const product = root.querySelector('#fct_add_review_product');
  const productInput = product
      ? (product.matches('input') ? product : product.querySelector('input'))
      : null;
  const target = productInput
      || root.querySelector('.fct-star-rating-input__star[tabindex="0"]')
      || root.querySelector('#fct_add_review_title');
  if (target) {
    target.focus();
  }
};

// Reported to the opener so it can lock its trigger: closing the dialog does
// not cancel the request, and a fresh form opened underneath would be closed
// by the first response.
watch(saving, (value) => emit('update:saving', value));

// One option value covers a choice at either level, so a single select can
// offer products and variations together: "12" is the product, "12:34" the
// variation. Two separate ids in the control would need code to clear one when
// the other changed, which is the bug this shape does not have.
const productKey = (product) => String(product.id);
const variationKey = (product, variation) => `${product.id}:${variation.id}`;

const products = ref([]);
const productsLoading = ref(false);
// What the select holds, as its composite key. form.post_id / form.item_id stay
// the record that is submitted; this is the control's view of them.
const selectionKey = ref(null);

/**
 * Pro publishes this block through fluent_cart/admin_app_data when review
 * photos are switched on and the current user may moderate reviews. Its
 * absence is the locked state — no Pro, photos disabled, or no permission.
 */
const mediaConfig = AppConfig.get('review_media') || null;
// Both have to hold: Pro publishes the block only when photo reviews are on,
// and this side checks the switch itself rather than inferring it from the
// block's absence — which is also how "no Pro" looks.
const canAttach = !!(mediaConfig && mediaConfig.enabled && photoReviewsEnabled);
const maxPhotos = Number(mediaConfig?.max_photos) || 0;
const maxSizeMb = Number(mediaConfig?.max_size_mb) || 0;

// The store's Allowed file types, as the MIME list Pro resolved them to.
const allowedMimeTypes = String(mediaConfig?.accept || '')
    .split(',')
    .map((type) => type.trim())
    .filter(Boolean);

const upgradeLink = upgradeUrl('review_attachments', 'reviews');
const isProActive = Boolean(AppConfig.get('app_config.isProActive'));

// Two reasons the button can be inert, and they need different words:
// without Pro the feature is a purchase away, with Pro installed but not
// publishing the capability (module off, or a version predating it) it is an
// update away. Telling someone who already owns Pro to "upgrade" sends them
// somewhere that cannot help.
//
// The store's enable_photo_reviews switch is a third case, handled in the
// template: it removes the row outright rather than locking it.
const lockedHint = isProActive
    ? translate('Update FluentCart Pro to attach photos to a review')
    : translate('Reviews with photos are available in FluentCart Pro');

const onProductFocus = () => {
  clearValidationError('post_id');
  clearValidationError('item_id');
};

// The select is not clearable, so a change always names an option: the errors
// are already gone by now — picking one means the control was focused first.
const onSelectionChanged = (key) => {
  const [postId, itemId] = String(key).split(':');
  form.value.post_id = Number(postId);
  form.value.item_id = itemId ? Number(itemId) : null;
};

// The caller's product's variations, for the variation-only row. Not needed on
// the search path, where the variations arrive inside the search answer.
const variations = ref([]);

// Keep post_id in step with the prop — the same modal instance is reused
// across product rows, and a stale id would file the review under the
// previously opened product. Anything resolved about the previous product
// goes with it.

let searchTimer = null;
// Every request carries a sequence number. Responses come back in whatever
// order the network decides, so without this a slow request for "sh" can land
// after a fast one for "shirt" and repaint the list with products the admin is
// no longer looking at — and its finally() would clear the spinner while the
// newer request is still running. Only the newest request may touch results,
// errors or loading state.
let searchSeq = 0;
let searchAbort = null;

const abortPendingSearch = () => {
  if (searchAbort) {
    searchAbort.abort();
    searchAbort = null;
  }
};

/**
 * One call for both rows.
 *
 * The reviews module's own remote-options key — see
 * ReviewModule::registerProductPickerOptions(). It answers with products, each
 * carrying its variations, readable with reviews/manage alone, which the
 * products endpoint is not. `include_ids` asks about products already known,
 * which is how the variation-only row gets the caller's product.
 */
const fetchProducts = (query, seq) => {
  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  searchAbort = controller;

  return Rest.get('advance_filter/get-filter-options', {
    remote_data_key: 'review_products',
    limit: 20,
    ...query
  }, null, controller ? {signal: controller.signal} : {})
      .then((response) => {
        if (seq !== searchSeq) {
          return null;
        }
        return (response.options?.data || []).map((product) => ({
          id: Number(product.id),
          title: product.title,
          variations: (product.variations || []).map((variation) => ({
            id: Number(variation.id),
            title: variation.title
          }))
        }));
      });
};

const searchProducts = (name) => {
  clearTimeout(searchTimer);

  // Supersede the moment the query changes, not when the debounce fires.
  // Bumping inside the timer left a 400ms window in which the previous
  // request could resolve, still match the current sequence, and repaint the
  // list — and clear the spinner — for a query the admin had already moved on
  // from. The sequence taken here is the one the debounced request is checked
  // against.
  const seq = ++searchSeq;
  abortPendingSearch();

  if (!name) {
    products.value = [];
    productsLoading.value = false;
    return;
  }

  productsLoading.value = true;
  searchTimer = setTimeout(() => {
    fetchProducts({search: name}, seq)
        .then((rows) => {
          if (rows === null) {
            return;
          }
          products.value = rows;
        })
        .catch((errors) => {
          // A superseded request lands here too once aborted; staying quiet
          // keeps a cancelled search from raising an error the admin never
          // caused.
          if (seq !== searchSeq) {
            return;
          }
          products.value = [];
          Notify.error(errors?.data?.message || translate('Could not load products. Please try again.'));
        })
        .finally(() => {
          if (seq !== searchSeq) {
            return;
          }
          searchAbort = null;
          productsLoading.value = false;
        });
  }, 400);
};

/**
 * The caller's product's variations, for the variation-only row.
 *
 * An empty answer is the honest one for a simple product: it has no items to
 * tell apart, and the server's productHasItems() is the single place that
 * decides so — the same one ProductReviewService::resolveReviewItem() asks
 * when the review is written, so the row and the write path can never disagree
 * about which products have items.
 */
const loadVariations = (postId) => {
  const seq = ++searchSeq;
  abortPendingSearch();

  fetchProducts({include_ids: [postId], limit: 1}, seq)
      .then((rows) => {
        if (rows === null) {
          return;
        }
        const match = rows.find((row) => row.id === Number(postId));
        variations.value = match ? match.variations : [];
      })
      .catch((errors) => {
        if (seq !== searchSeq) {
          return;
        }
        variations.value = [];
        // Said out loud rather than swallowed: an empty list is also how a
        // simple product answers, so a failure that stayed quiet would look
        // like "this product has no variations" and the review would be filed
        // at product level by someone who meant to name one.
        Notify.error(errors?.data?.message || translate('Could not load this product\'s variations.'));
      });
};

watch(() => props.productId, (productId) => {
  form.value.post_id = productId || null;
  form.value.item_id = null;
  selectionKey.value = null;
  variations.value = [];

  if (productId) {
    loadVariations(productId);
  }
}, {immediate: true});

onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  abortPendingSearch();
  form.value.media.forEach(releasePreview);
});

const fileInputRef = ref(null);
// Object URLs need a stable key of their own — the same file can legitimately
// be added twice, and there is no server id to key on any more.
let mediaKeySeq = 0;

const attachHint = computed(() => {
  if (form.value.media.length >= maxPhotos) {
    /* translators: %1$s: the maximum number of photos allowed on one review */
    return translate('Photo limit reached (%1$s)', maxPhotos);
  }
  /* translators: %1$s: max number of photos, %2$s: max size of each in MB */
  return translate('Up to %1$s photos, %2$s MB each', maxPhotos, maxSizeMb);
});

const pickFiles = () => fileInputRef.value?.click();

/**
 * Nothing is uploaded here. The files are held until save and sent with the
 * review itself, so the preview is a local object URL rather than a round
 * trip. That is what lets the whole thing be one request — and why an
 * abandoned modal leaves nothing behind on the server.
 */
const onFilesPicked = (event) => {
  const picked = Array.from(event.target.files || []);
  // Clearing lets the same file be chosen again after a removal.
  event.target.value = '';

  if (!picked.length) {
    return;
  }

  const room = maxPhotos - form.value.media.length;
  if (room <= 0) {
    return;
  }

  if (picked.length > room) {
    /* translators: %1$s: number of photos skipped, %2$s: the per-review photo limit */
    Notify.info(translate('%1$s photo(s) were skipped — the limit is %2$s per review', picked.length - room, maxPhotos));
  }

  picked.slice(0, room).forEach((file) => {
    if (maxSizeMb && file.size > maxSizeMb * 1024 * 1024) {
      /* translators: %1$s: the rejected file's name */
      Notify.error(translate('%1$s is too large', file.name));
      return;
    }

    // The store's Allowed file types. The accept attribute already filters the
    // file dialog, but it is a hint, not a gate: a drag or a paste walks past
    // it. Said here so the admin is told now rather than watching the photo sit
    // in the form and then not be there after saving — the server sniffs the
    // file's own content and refuses it either way.
    if (allowedMimeTypes.length && file.type && !allowedMimeTypes.includes(file.type)) {
      /* translators: %1$s: the rejected file's name */
      Notify.error(translate('%1$s is not an allowed file type', file.name));
      return;
    }

    form.value.media.push({
      key: `${file.name}-${file.size}-${file.lastModified}-${mediaKeySeq++}`,
      name: file.name,
      url: URL.createObjectURL(file),
      file,
    });
  });
};

const releasePreview = (item) => {
  if (item?.url) {
    URL.revokeObjectURL(item.url);
  }
};

const removeMedia = (key) => {
  const item = form.value.media.find((entry) => entry.key === key);
  releasePreview(item);
  form.value.media = form.value.media.filter((entry) => entry.key !== key);
};

const resetForm = () => {
  // Object URLs live until revoked; dropping the array alone leaks them.
  form.value.media.forEach(releasePreview);
  form.value = emptyForm();
  products.value = [];
  selectionKey.value = null;
  // variations is deliberately NOT cleared. It belongs to the caller's product,
  // which the reset puts straight back, and the watcher that would reload it
  // never fires for an unchanged prop — clearing it here would make the
  // variation row vanish the second time the dialog was opened.
  validationErrors.value = {};
};

const submit = () => {
  if (saving.value) {
    return;
  }

  validationErrors.value = {};
  saving.value = true;

  // Multipart rather than JSON: the attachments travel with the review, so
  // the whole thing is one request and the server never holds a half-made
  // review or an unclaimed upload.
  const payload = new FormData();
  payload.append('post_id', form.value.post_id);
  // Omitted entirely at product level: an empty string would reach the server
  // as a 0 the resolver has to interpret, and there is nothing to say.
  if (form.value.item_id) {
    payload.append('item_id', form.value.item_id);
  }
  payload.append('rating', form.value.rating);
  payload.append('title', form.value.title);
  payload.append('content', form.value.content);
  payload.append('reviewer_name', form.value.reviewer_name);
  payload.append('reviewer_email', form.value.reviewer_email);
  payload.append('status', form.value.status);
  payload.append('is_verified', form.value.is_verified ? 1 : 0);
  form.value.media.forEach((item) => payload.append('files[]', item.file, item.name));

  Rest.upload('reviews', payload)
      .then((response) => {
        if (response.media_rejected?.length) {
          Notify.error(response.message, translate('Photos not saved'), 8000);
        } else {
          Notify.success(response.message);
        }
        // Success-only: the dialog stays open on failure so the typed review
        // is not lost, and the list only refetches once a row exists.
        emit('created', response.review);
        visible.value = false;
      })
      .catch((error) => {
        // Rest.upload rejects with the parsed body itself. A 422 body is the
        // field map, {field: {rule: message}}; a WP_Error carries a message;
        // a request that never got an answer rejects with {status, statusText}
        // and no message at all.
        const isFieldMap = error && typeof error === 'object' && !error.message
            && Object.values(error).some((value) => value && typeof value === 'object');
        validationErrors.value = isFieldMap
            ? error
            : {general: {server: error?.message || translate('Could not add the review. Please try again.')}};
      })
      .finally(() => {
        saving.value = false;
      });
};
</script>
