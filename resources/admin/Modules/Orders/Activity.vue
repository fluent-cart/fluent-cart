<template>
    <div class="fluent-cart-order-activity-list">
        <div class="fluent-cart-order-activity" v-for="activity in activities" :key="activity.id">
            <div class="fluent-cart-order-activity-complete-icon">
              <DynamicIcon name="Check"/>
            </div>
            <div class="fluent-cart-order-activity-details-container">
              <div :class="`fluent-cart-order-activity-details fluent-cart-order-activity-status-${activity.status}`">
                <span class="date-value">
                  <ConvertedTime :date-time="activity.created_at"/>
                </span>
                <div class="event-title">
                  <span>{{ $t(activity.title) }}</span>
                    <el-tag type="info">
                        {{ activity.created_by }}
                    </el-tag>
                </div>
                <div class="event-text" v-html="activity.content"></div>
              </div>
              <div class="delete-activity-button" v-if="showManageActivity">
                <IconButton @click="deleteModal(activity)" size="x-small" hover="info">
                  <DynamicIcon name="Delete"/>
                </IconButton>
              </div>
            </div>
        </div>

        <div v-if="!activities.length" class="p-4 text-center">
          {{$t('No activity found.')}}
        </div>

        <el-dialog :title="$t('Delete Activity')" v-model="showModal" :append-to-body="true">
          <div class="fct-delete-activity-content-heading">
            {{$t('Are you sure, you want to delete this activity?')}}
          </div>

          <div class="fct-delete-activity-content">
            <div class="title">
              #{{this.activity.id}} {{ this.activity.title }}
              <span>&nbsp;by&nbsp;{{ activity.created_by }}</span>
            </div>
            <div class="text">{{ this.activity.content }}</div>
          </div>

          <div class="dialog-footer is-border">
            <el-button @click="showModal = false" size="small">{{$t('Cancel')}}</el-button>
            <el-button type="primary" @click="deleteActivity" size="small">{{$t('Yes! Delete')}}</el-button>
          </div>
        </el-dialog>
    </div>
</template>
<script>

    import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
    import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
    import Badge from "@/Bits/Components/Badge.vue";
    import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";

    export default {
      data() {
        return {
          showModal: false,
          activity: {}
        }
      },
      components: {ConvertedTime, DynamicIcon, IconButton, Badge},
      props: [
          'activities',
          'showManageActivity'
      ],
      methods: {
        deleteModal(activity) {
          this.showModal = true;
          this.activity = activity;
        },
        deleteActivity() {
          this.showModal = false;
          this.$del("activity/" + this.activity.id, {})
              .then(res => {
                  this.handleSuccess(res?.data?.message);
                  this.$emit('reload');
              });
        }
      }
    }
</script>
