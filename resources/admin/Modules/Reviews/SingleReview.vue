<template>
  <UserCan permission="reviews/manage">
    <div class="fct-single-review-page fct-layout-width">
      <template v-if="loading">
        <div class="p-6">
          <el-skeleton :rows="8" animated/>
        </div>
      </template>

      <template v-else-if="review">
        <!-- Header -->
        <div class="single-page-header flex items-start justify-between">
          <div>
            <div class="single-page-header-title-wrap">
              <el-breadcrumb :separator-icon="ArrowRight">
                <el-breadcrumb-item :to="{ name: 'reviews' }">{{ translate('Reviews') }}</el-breadcrumb-item>
                <!-- Same destination as the products table's Reviews column:
                     the reviews list pre-filtered to this product. -->
                <el-breadcrumb-item
                    v-if="review.product"
                    :to="{ name: 'reviews', query: { post_id: review.post_id } }"
                >
                  {{ review.product.post_title }}
                </el-breadcrumb-item>
                <el-breadcrumb-item>
                  <el-tooltip v-if="review.reviewer_email" :content="review.reviewer_email" placement="bottom">
                    <span>{{ review.reviewer_name }}</span>
                  </el-tooltip>
                  <span v-else>{{ review.reviewer_name }}</span>
                  <!-- Compact pill beside the name linking to the customer profile -->
                  <router-link
                      v-if="review.customer"
                      class="fct-goto-customer-pill"
                      :to="{ name: 'view_customer', params: { customer_id: review.customer.id } }"
                  >
                    {{ translate('Go to Customer') }}<span aria-hidden="true">&rarr;</span>
                  </router-link>
                </el-breadcrumb-item>
              </el-breadcrumb>
              <div class="single-page-header-status-wrap">
                <Badge :status="review.status"/>
              </div>
            </div>
          </div>
          <div class="fct-btn-group sm">
            <el-dropdown trigger="click">
              <el-button size="small">
                {{ translate('More Actions') }}
                <el-icon class="el-icon--right"><ArrowDown/></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item
                      v-if="review.status !== 'approved'"
                      :disabled="statusLoading"
                      @click="updateStatus('approved')"
                  >
                    {{ translate('Approve') }}
                  </el-dropdown-item>
                  <el-dropdown-item
                      v-if="review.status !== 'spam'"
                      :disabled="statusLoading"
                      @click="updateStatus('spam')"
                  >
                    {{ translate('Mark as Spam') }}
                  </el-dropdown-item>
                  <el-dropdown-item
                      v-if="review.status !== 'pending'"
                      :disabled="statusLoading"
                      @click="updateStatus('pending')"
                  >
                    {{ translate('Mark as Pending') }}
                  </el-dropdown-item>
                  <el-dropdown-item
                      v-if="review.status !== 'trash'"
                      :disabled="statusLoading"
                      @click="updateStatus('trash')"
                    >
                    {{ translate('Move to Trash') }}
                  </el-dropdown-item>
                  <el-dropdown-item @click="deleteReview" class="fct-dropdown-item--danger">
                    {{ translate('Delete Permanently') }}
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </div>

        <!-- Body -->
        <div class="single-page-body">
          <div class="fct-single-review-layout">
            <!-- Main Column -->
            <div class="fct-single-review-main">
              <!-- Review + Replies Card -->
              <CardContainer>
                <!-- Conversation Section -->
                <CardHeader :title="translate('Review Information')" border_bottom title_size="small">
                  <template #action>
                    <span class="fct-review-rating-inline">
                      <StarRating :rating="review.rating" size="small"/>
                      <span class="fct-review-rating-num">{{ review.rating ? Number(review.rating).toFixed(1) : '—' }}</span>
                    </span>
                  </template>
                </CardHeader>

                <div class="fct-review-thread-container">
                  <div class="fct-review-thread" ref="chatScrollRef">
                    <!-- Customer original review -->
                    <div class="fct-thread-item">
                      <UserAvatar
                          :name="review.reviewer_name"
                          :url="review.photo"
                      />
                      <div class="fct-thread-content">
                        <div class="fct-thread-name-row">
                          <span class="fct-thread-name">{{ review.reviewer_name }}</span>
                          <el-tag size="small" effect="light" class="fct-customer-review-badge">
                            {{ translate('Customer review') }}
                          </el-tag>
                          <el-tag v-if="!review.user_id" size="small" type="warning" effect="light">
                            {{ translate('Guest') }}
                          </el-tag>
                          <el-tag v-if="review.is_verified" size="small" type="success" effect="light">
                            {{ translate('Verified Purchase') }}
                          </el-tag>
                        </div>
                        <div class="fct-thread-meta">
                          <ConvertedTime :date-time="review.created_at" :with-time="true"/>
                        </div>
                        <div v-if="review.title" class="fct-thread-title">{{ review.title }}</div>
                        <div class="fct-thread-text">{{ review.content || translate('No review content.') }}</div>
                        <div v-if="reviewMedia && reviewMedia.length" class="fct-thread-media">
                          <button
                              v-for="(media, index) in reviewMedia"
                              :key="media.id"
                              type="button"
                              class="fct-thread-media-thumb"
                              :aria-label="`${translate('Preview')} ${media.name || translate('media')}`"
                              @click="openPreview(index)"
                          >
                            <img
                                v-if="media.type === 'image'"
                                :src="media.url"
                                :alt="media.name"
                                loading="lazy"
                            />
                            <div v-else class="fct-thread-media-video">
                              <span class="fct-thread-media-play">&#9654;</span>
                            </div>
                          </button>
                        </div>
                      </div>
                    </div>

                    <!-- Store replies -->
                    <div v-if="review.replies && review.replies.length" class="fct-thread-reply-group">
                      <div v-for="reply in review.replies" :key="reply.id" class="fct-thread-reply-card">
                        <UserAvatar
                            :name="reply.reviewer_name || translate('Customer')"
                            :url="reply.photo"
                        />
                        <div class="fct-thread-content">
                          <div class="fct-thread-name-row">
                            <span class="fct-thread-name">{{ reply.reviewer_name || translate('Customer') }}</span>
                            <el-tag v-if="Number(reply.is_admin_reply) === 1" size="small" effect="light" class="fct-admin-reply-badge">
                              {{ translate('Admin') }}
                            </el-tag>
                          </div>
                          <div class="fct-thread-meta">
                            <ConvertedTime :date-time="reply.created_at" :with-time="true"/>
                            <span class="fct-thread-meta-dot" aria-hidden="true">&middot;</span>
                            <!-- The reply's status is the control: the badge itself, with a
                                 caret inside it that turns when the menu is open. The menu
                                 lists the statuses with an icon each, the current one ticked
                                 and tinted in its own colour. -->
                            <el-dropdown
                                trigger="click"
                                :disabled="replyStatusLoading !== null"
                                @command="(status) => updateReplyStatus(reply, status)"
                                @visible-change="(open) => openReplyStatusMenu = open ? reply.id : null"
                            >
                              <button
                                  type="button"
                                  class="fct-thread-status-trigger"
                                  :class="{
                                    'is-open': openReplyStatusMenu === reply.id,
                                    'is-busy': replyStatusLoading === reply.id,
                                  }"
                                  :disabled="replyStatusLoading !== null"
                                  :aria-label="translate('Reply status')"
                                  :aria-expanded="openReplyStatusMenu === reply.id ? 'true' : 'false'"
                                  aria-haspopup="menu"
                              >
                                <Badge :type="reply.status" class="fct-thread-status-pill">
                                  <span>{{ replyStatusLabel(reply.status) }}</span>
                                  <el-icon class="fct-thread-status-caret" aria-hidden="true"><ArrowDown/></el-icon>
                                </Badge>
                              </button>
                              <template #dropdown>
                                <el-dropdown-menu class="fct-thread-status-menu">
                                  <el-dropdown-item
                                      v-for="option in replyStatusOptions"
                                      :key="option.status"
                                      :command="option.status"
                                      :disabled="option.status === reply.status"
                                      :class="[
                                        'fct-thread-status-option',
                                        `fct-thread-status-option--${option.status}`,
                                        { 'is-current': option.status === reply.status },
                                      ]"
                                  >
                                    <DynamicIcon
                                        :name="option.status === reply.status ? 'Check' : option.icon"
                                        class="fct-thread-status-option-icon"
                                    />
                                    <span>{{ option.label }}</span>
                                  </el-dropdown-item>
                                </el-dropdown-menu>
                              </template>
                            </el-dropdown>
                            <el-button
                                type="danger"
                                text
                                size="small"
                                class="fct-thread-delete-btn"
                                :aria-label="translate('Delete reply')"
                                @click="deleteReply(reply.id)"
                            >
                              <DynamicIcon name="TrashIcon"/>
                            </el-button>
                          </div>
                          <div class="fct-thread-text">{{ reply.content }}</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Reply form -->
                  <div v-if="canReply" class="fct-thread-form">
                    <div class="fct-thread-form-box">
                      <el-input
                          v-model="replyContent"
                          type="textarea"
                          :rows="3"
                          :placeholder="translate('Type a message')"
                          :aria-label="translate('Reply to review')"
                          :resize="'none'"
                      />
                      <div class="fct-thread-form-actions">
                        <el-button
                            class="fct-thread-reply-btn"
                            size="small"
                            @click="postReply"
                            :loading="replyLoading"
                            :disabled="replyLoading || !replyContent.trim()"
                        >
                          {{ translate('Reply') }}
                        </el-button>
                      </div>
                    </div>
                  </div>
                </div>
              </CardContainer>

              <!-- Media Preview Dialog -->
              <el-dialog
                  v-model="previewVisible"
                  :title="translate('Review Media')"
                  width="720px"
                  destroy-on-close
                  :aria-label="translate('Review media preview')"
              >
                <div v-if="reviewMedia && reviewMedia[previewIndex]" class="fct-review-media-preview">
                  <img
                      v-if="reviewMedia[previewIndex].type === 'image'"
                      :src="reviewMedia[previewIndex].url"
                      :alt="reviewMedia[previewIndex].name"
                      class="fct-review-media-preview-img"
                  />
                  <video
                      v-else
                      :src="reviewMedia[previewIndex].url"
                      controls
                      class="fct-review-media-preview-video"
                  ></video>
                  <div class="fct-review-media-preview-nav" v-if="reviewMedia.length > 1">
                    <el-button
                        size="small"
                        :disabled="previewIndex === 0"
                        @click="previewIndex--"
                    >
                      &larr; {{ translate('Previous') }}
                    </el-button>
                    <span class="text-sm text-gray-500">{{ previewIndex + 1 }} / {{ reviewMedia.length }}</span>
                    <el-button
                        size="small"
                        :disabled="previewIndex === reviewMedia.length - 1"
                        @click="previewIndex++"
                    >
                      {{ translate('Next') }} &rarr;
                    </el-button>
                  </div>
                </div>
              </el-dialog>
            </div>

            <!-- Sidebar Column -->
            <div class="fct-single-review-aside">
              <div class="fct-admin-sidebar">
                <!-- Review context: what the review is about, who wrote it,
                     and how shoppers reacted — the three facts a moderator
                     checks before deciding. -->
                <CardContainer>
                  <CardHeader
                      :title="translate('About the Reviews')"
                      border_bottom
                      title_size="small"
                  />
                  <CardBody>
                    <div class="fct-review-context">
                      <div v-if="review.product" class="fct-review-context-row">
                        <span class="fct-review-context-thumb" aria-hidden="true">
                          <img :src="productImage()" alt=""/>
                        </span>
                        <span class="fct-review-context-label">
                          {{ review.product.post_title }}
                          <span v-if="review.item_label" class="block text-xs text-gray-500 dark:text-gray-400">{{ review.item_label }}</span>
                        </span>
                        <a
                            v-if="review.product.view_url"
                            class="fct-review-context-link"
                            :href="review.product.view_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="translate('View product')"
                            :title="translate('View product')"
                        >
                          <DynamicIcon name="Redirect"/>
                        </a>
                      </div>

                      <div class="fct-review-context-row">
                        <UserAvatar
                            class="fct-review-context-avatar"
                            :name="review.reviewer_name"
                            :url="review.photo"
                        />
                        <span class="fct-review-context-label">
                          {{ review.customer ? review.reviewer_name : translate('Guest customer') }}
                        </span>
                        <router-link
                            v-if="review.customer"
                            class="fct-review-context-link"
                            :to="{ name: 'view_customer', params: { customer_id: review.customer.id } }"
                            target="_blank"
                            :aria-label="translate('View profile')"
                            :title="translate('View profile')"
                        >
                          <DynamicIcon name="Redirect"/>
                        </router-link>
                        <el-tag v-else type="info" round class="flex-shrink-0">{{ translate('No account') }}</el-tag>
                      </div>
                    </div>

                    <!-- Moderation tiles live in the card, under their own
                         label, per the reference layout. -->
                    <!-- Verified badge is a moderator decision, not only a
                         purchase fact: a review entered by hand or matched to
                         an offline order still deserves the badge. -->
                    <div class="fct-review-verified-row">
                      <div>
                        <div class="fct-review-verified-label">{{ translate('Verified purchase') }}</div>
                        <div class="fct-review-verified-hint">{{ translate('Show the verified badge on this review') }}</div>
                      </div>
                      <el-switch
                          :model-value="!!review.is_verified"
                          :loading="verifiedLoading"
                          :disabled="verifiedLoading"
                          :aria-label="translate('Mark as verified purchase')"
                          @update:model-value="updateVerified"
                      />
                    </div>

                    <div class="fct-review-actions-label">{{ translate('Actions') }}</div>
                    <div class="fct-review-action-buttons">
                      <button
                          v-for="action in moderationActions"
                          :key="action.status"
                          type="button"
                          class="fct-review-action"
                          :class="`fct-review-action--${action.status}`"
                          :disabled="statusLoading"
                          :aria-label="action.aria"
                          :title="action.aria"
                          @click="updateStatus(action.status)"
                      >
                        <DynamicIcon v-if="action.status === 'approved'" class="fct-review-action-icon" name="Check"/>
                        <DynamicIcon v-else-if="action.status === 'spam'" class="fct-review-action-icon" name="FlagIcon"/>
                        <DynamicIcon v-else-if="action.status === 'trash'" class="fct-review-action-icon" name="TrashIcon"/>
                        <DynamicIcon v-else class="fct-review-action-icon" name="Clock"/>
                        <span class="fct-review-action-label">{{ action.label }}</span>
                      </button>
                    </div>
                  </CardBody>
                </CardContainer>

                <!-- Helpful Votes (PRO) -->
                <CardContainer v-if="voteCounts">
                  <CardHeader
                      :title="translate('Helpful Votes')"
                      :text="translate('How shoppers reacted to this review')"
                      title_size="small"
                  />
                  <CardBody>
                    <div class="fct-vote-rows">
                      <div class="fct-vote-row fct-vote-row--helpful">
                        <DynamicIcon name="ThumbUp"/>
                        <span class="fct-vote-row-count">{{ voteCounts.helpful }}</span>
                        <span class="sr-only">{{ translate('Helpful votes') }}</span>
                      </div>
                      <div class="fct-vote-row fct-vote-row--not-helpful">
                        <DynamicIcon name="ThumbDown"/>
                        <span class="fct-vote-row-count">{{ voteCounts.not_helpful }}</span>
                        <span class="sr-only">{{ translate('Not helpful votes') }}</span>
                      </div>
                    </div>
                  </CardBody>
                </CardContainer>

            </div>
            </div>
          </div>
        </div>
      </template>

      <div v-else class="p-6 text-center text-gray-500">
        {{ translate('Review not found') }}
      </div>
    </div>
  </UserCan>
</template>

<script setup>
import {ref, computed, watch, onMounted, onBeforeUnmount, nextTick} from "vue";
import {useRoute, useRouter} from "vue-router";
import {ArrowRight, ArrowDown} from "@element-plus/icons-vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import UserAvatar from "@/Bits/Components/UserAvatar.vue";
import {Container as CardContainer, Header as CardHeader, Body as CardBody} from "@/Bits/Components/Card/Card.js";
import {$confirm} from "@/Bits/common";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import Badge from "@/Bits/Components/Badge.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import StarRating from "@/Bits/Components/StarRating.vue";
import translate from "@/utils/translator/Translator";
import Asset from "@/utils/support/Asset";
import Arr from "@/utils/support/Arr";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

const route = useRoute();
const router = useRouter();

const review = ref(null);
const loading = ref(true);
/**
 * The moderation states this review is not already in — always three of the
 * four, so the row keeps its shape while the actions it offers follow the
 * review's status.
 */

// Product image the same way the products table resolves it — the
// featured media, or the product placeholder illustration.
const productImage = () => {
  return Arr.get(review.value?.product, 'detail.featured_media.url') ?? Asset.productPlaceholder();
};


const moderationActions = computed(() => {
  const all = [
    {status: 'approved', label: translate('Approve'), aria: translate('Approve')},
    {status: 'spam', label: translate('Spam'), aria: translate('Mark as Spam')},
    {status: 'trash', label: translate('Trash'), aria: translate('Move to Trash')},
    {status: 'pending', label: translate('Pending'), aria: translate('Mark as Pending')}
  ];

  // Slice as well as filter: a status outside these four — a legacy
  // post-trashed row, say — would otherwise leave all four and break the
  // three-column row.
  return all.filter((action) => action.status !== review.value?.status).slice(0, 3);
});

const replyContent = ref('');
const replyLoading = ref(false);
const replyStatusLoading = ref(null);

// The statuses a reply can be moved to, each with the icon the menu shows
// beside it. Literal strings, so the translation extractor sees each label.
const replyStatusOptions = [
  {status: 'pending', label: translate('Pending'), icon: 'Clock'},
  {status: 'approved', label: translate('Approved'), icon: 'Check'},
  {status: 'spam', label: translate('Spam'), icon: 'AlertIcon'},
  {status: 'trash', label: translate('Trash'), icon: 'TrashIcon'},
];

const replyStatusLabel = (status) => {
  const option = replyStatusOptions.find((candidate) => candidate.status === status);
  return option ? option.label : status;
};

// Which reply's status menu is open, so its caret can turn.
const openReplyStatusMenu = ref(null);

const updateReplyStatus = (reply, status) => {
  if (replyStatusLoading.value !== null) return;
  replyStatusLoading.value = reply.id;
  Rest.put('reviews/' + reply.id, {status})
      .then((response) => {
        reply.status = status;
        Notify.success(response.message);
      })
      .catch((error) => Notify.error(error.data?.message))
      .finally(() => {
        replyStatusLoading.value = null;
      });
};
const chatScrollRef = ref(null);

const scrollChatToBottom = () => {
    nextTick(() => {
        if (chatScrollRef.value) {
            chatScrollRef.value.scrollTop = chatScrollRef.value.scrollHeight;
        }
    });
};
const statusLoading = ref(false);
const verifiedLoading = ref(false);
const voteCounts = ref(null);
const reviewMedia = ref([]);
const allowMultipleReplies = ref(false);

// One store reply per review unless an extension allows multiple replies —
// hide the reply form once a reply exists (deleting it re-enables the form).
const canReply = computed(() => {
  return allowMultipleReplies.value || !(review.value?.replies || []).length;
});
const previewVisible = ref(false);
const previewIndex = ref(0);

const openPreview = (index) => {
  previewIndex.value = index;
  previewVisible.value = true;
};

const handlePreviewKeydown = (e) => {
  if (!previewVisible.value || !reviewMedia.value.length) return;
  if (e.key === 'ArrowLeft' && previewIndex.value > 0) {
    previewIndex.value--;
  } else if (e.key === 'ArrowRight' && previewIndex.value < reviewMedia.value.length - 1) {
    previewIndex.value++;
  }
};

watch(previewVisible, (visible) => {
  if (visible) {
    document.addEventListener('keydown', handlePreviewKeydown);
  } else {
    document.removeEventListener('keydown', handlePreviewKeydown);
  }
});

const fetchReview = () => {
  loading.value = true;
  Rest.get('reviews/' + route.params.review_id)
      .then((response) => {
        review.value = response.review;
        voteCounts.value = response.vote_counts || null;
        reviewMedia.value = response.review_media || [];
        allowMultipleReplies.value = !!response.allow_multiple_replies;
        scrollChatToBottom();
      })
      .catch((error) => {
        Notify.error(error.data?.message || translate('Review not found'));
      })
      .finally(() => {
        loading.value = false;
      });
};

const updateStatus = (status) => {
  if (statusLoading.value) return;
  statusLoading.value = true;
  Rest.put('reviews/' + review.value.id, {status})
      .then((response) => {
        Notify.success(response.message);
        review.value.status = status;
      })
      .catch((error) => {
        Notify.error(error.data?.message);
      })
      .finally(() => {
        statusLoading.value = false;
      });
};

/**
 * Persist the verified flag. The switch reads straight off the model rather
 * than holding its own copy, and the model is written only after the server
 * confirms — a failed request leaves the switch showing the stored value.
 */
const updateVerified = (isVerified) => {
  if (verifiedLoading.value) return;
  verifiedLoading.value = true;
  Rest.put('reviews/' + review.value.id, {is_verified: isVerified ? 1 : 0})
      .then((response) => {
        Notify.success(response.message);
        review.value.is_verified = isVerified ? 1 : 0;
      })
      .catch((error) => {
        Notify.error(error.data?.message);
      })
      .finally(() => {
        verifiedLoading.value = false;
      });
};

const deleteReview = () => {
  $confirm(
      translate('Are you sure you want to permanently delete this review? This action cannot be undone.'),
      translate('Confirm Delete'),
      {
        confirmButtonText: translate('Yes, Delete!'),
        cancelButtonText: translate('Cancel'),
        type: 'warning',
      }
  ).then(() => {
    Rest.delete('reviews/' + review.value.id)
        .then((response) => {
          Notify.success(response.message);
          router.push({name: 'reviews'});
        })
        .catch((error) => {
          Notify.error(error.data?.message);
        });
  }).catch(() => {});
};

const deleteReply = (replyId) => {
  $confirm(
      translate('Are you sure you want to delete this reply?'),
      translate('Delete Reply'),
      {
        confirmButtonText: translate('Yes, Delete'),
        cancelButtonText: translate('Cancel'),
        type: 'warning',
      }
  ).then(() => {
    Rest.delete('reviews/' + review.value.id + '/replies/' + replyId)
        .then((response) => {
          Notify.success(response.message);

          // Same as posting: the row leaves the thread in place rather than
          // re-fetching the page into its loading state.
          if (Array.isArray(review.value.replies)) {
            review.value.replies = review.value.replies.filter((reply) => reply.id !== replyId);
          }
        })
        .catch((error) => {
          Notify.error(error.data?.message);
        });
  }).catch(() => {});
};

const postReply = () => {
  if (!replyContent.value.trim()) {
    Notify.error(translate('Please write a reply message before sending.'));
    return;
  }
  replyLoading.value = true;
  Rest.post('reviews/' + review.value.id + '/reply', {
    content: replyContent.value,
  })
      .then((response) => {
        Notify.success(response.message);
        replyContent.value = '';

        // The endpoint returns the created reply, so it lands in the thread
        // directly instead of re-fetching the page into its loading state.
        if (response.reply) {
          if (!Array.isArray(review.value.replies)) {
            review.value.replies = [];
          }
          review.value.replies.push(response.reply);
          scrollChatToBottom();
        } else {
          fetchReview();
        }
      })
      .catch((error) => {
        Notify.error(error.data?.message);
      })
      .finally(() => {
        replyLoading.value = false;
      });
};

onMounted(() => {
  fetchReview();
});

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handlePreviewKeydown);
});
</script>
