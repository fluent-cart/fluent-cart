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
                <el-breadcrumb-item
                    v-if="review.product"
                    :to="{ name: 'product_edit', params: { product_id: review.post_id } }"
                >
                  {{ review.product.post_title }}
                </el-breadcrumb-item>
                <el-breadcrumb-item
                    v-if="review.customer"
                    :to="{ name: 'view_customer', params: { customer_id: review.customer.id } }"
                >
                  <el-tooltip v-if="review.reviewer_email" :content="review.reviewer_email" placement="bottom">
                    <span>{{ review.reviewer_name }}</span>
                  </el-tooltip>
                  <span v-else>{{ review.reviewer_name }}</span>
                </el-breadcrumb-item>
                <el-breadcrumb-item v-else>
                  <el-tooltip v-if="review.reviewer_email" :content="review.reviewer_email" placement="bottom">
                    <span>{{ review.reviewer_name }}</span>
                  </el-tooltip>
                  <span v-else>{{ review.reviewer_name }}</span>
                </el-breadcrumb-item>
              </el-breadcrumb>
              <div class="single-page-header-status-wrap">
                <Badge :status="review.status"/>
              </div>
            </div>
          </div>
          <div class="fct-btn-group sm">
            <el-button
                v-if="review.status !== 'approved'"
                size="small"
                type="success"
                :disabled="statusLoading"
                @click="updateStatus('approved')"
            >
              {{ translate('Approve') }}
            </el-button>
            <el-button
                v-if="review.status !== 'trash'"
                size="small"
                class="fct-review-soft-btn fct-review-soft-btn--danger"
                :disabled="statusLoading"
                @click="updateStatus('trash')"
            >
              {{ translate('Trash') }}
            </el-button>
            <el-button
                v-if="review.status !== 'spam'"
                size="small"
                class="fct-review-soft-btn fct-review-soft-btn--warning"
                :disabled="statusLoading"
                @click="updateStatus('spam')"
            >
              {{ translate('Spam') }}
            </el-button>
            <el-dropdown trigger="click">
              <el-button size="small">
                {{ translate('More Action') }}
                <el-icon class="el-icon--right"><ArrowDown/></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item
                      v-if="review.status !== 'pending'"
                      :disabled="statusLoading"
                      @click="updateStatus('pending')"
                  >
                    {{ translate('Mark as Pending') }}
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
                      <div class="fct-thread-avatar fct-thread-avatar--customer">
                        {{ getInitials(review.reviewer_name) }}
                      </div>
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
                        <div class="fct-thread-avatar fct-thread-avatar--store">
                          {{ getInitials(translate('Store owner')) }}
                        </div>
                        <div class="fct-thread-content">
                          <div class="fct-thread-name-row">
                            <span class="fct-thread-name">{{ translate('Store owner') }}</span>
                            <el-tag v-if="Number(reply.is_admin_reply) === 1" size="small" effect="light" class="fct-admin-reply-badge">
                              {{ translate('You') }} &middot; {{ translate('Admin') }}
                            </el-tag>
                          </div>
                          <div class="fct-thread-meta">
                            <ConvertedTime :date-time="reply.created_at" :with-time="true"/>
                            <el-button
                                type="danger"
                                text
                                size="small"
                                class="fct-thread-delete-btn"
                                :aria-label="translate('Delete reply')"
                                @click="deleteReply(reply.id)"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </el-button>
                          </div>
                          <div class="fct-thread-text">{{ reply.content }}</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Reply form -->
                  <div class="fct-thread-form">
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 20h2c.55 0 1-.45 1-1v-9c0-.55-.45-1-1-1H2v11zm19.83-7.12c.11-.25.17-.52.17-.8V11c0-1.1-.9-2-2-2h-5.5l.92-4.65c.05-.22.02-.46-.08-.66a4.8 4.8 0 0 0-.88-1.22L14 2 7.59 8.41C7.21 8.79 7 9.3 7 9.83v7.84A2.34 2.34 0 0 0 9.34 20h8.11c.7 0 1.36-.37 1.72-.97l2.66-6.15z"/></svg>
                        <span class="fct-vote-row-count">{{ voteCounts.helpful }}</span>
                        <span class="sr-only">{{ translate('Helpful votes') }}</span>
                      </div>
                      <div class="fct-vote-row fct-vote-row--not-helpful">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 4h-2c-.55 0-1 .45-1 1v9c0 .55.45 1 1 1h2V4zM2.17 11.12c-.11.25-.17.52-.17.8V13c0 1.1.9 2 2 2h5.5l-.92 4.65c-.05.22-.02.46.08.66.23.45.52.86.88 1.22L10 22l6.41-6.41c.38-.38.59-.89.59-1.42V6.34A2.34 2.34 0 0 0 14.66 4H6.56c-.71 0-1.37.37-1.73.97l-2.66 6.15z"/></svg>
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
import {ref, watch, onMounted, onBeforeUnmount, nextTick} from "vue";
import {useRoute, useRouter} from "vue-router";
import {ArrowRight, ArrowDown} from "@element-plus/icons-vue";
import {Container as CardContainer, Header as CardHeader, Body as CardBody} from "@/Bits/Components/Card/Card.js";
import {$confirm} from "@/Bits/common";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import Badge from "@/Bits/Components/Badge.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import StarRating from "@/Bits/Components/StarRating.vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

const route = useRoute();
const router = useRouter();

const review = ref(null);
const loading = ref(true);
const getInitials = (name) => {
    const parts = (name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    const second = parts.length > 1 ? parts[1].charAt(0) : '';
    return (parts[0].charAt(0) + second).toUpperCase();
};
const replyContent = ref('');
const replyLoading = ref(false);
const chatScrollRef = ref(null);

const scrollChatToBottom = () => {
    nextTick(() => {
        if (chatScrollRef.value) {
            chatScrollRef.value.scrollTop = chatScrollRef.value.scrollHeight;
        }
    });
};
const statusLoading = ref(false);
const voteCounts = ref(null);
const reviewMedia = ref([]);
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
          fetchReview();
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
        fetchReview();
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

