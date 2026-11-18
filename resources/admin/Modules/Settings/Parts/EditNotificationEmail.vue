<template>
  <div>
    <div>
      <fct-input class="mb-[18px]" v-model="notification.title" :placeholder="$t('Title')"/>
      <fct-input class="mb-[18px]" type="textarea" v-model="notification.content" :placeholder="$t('Content')"/>
    </div>
  </div>
</template>

<script>
export default {
  name: 'EditNotificationSettings',
  props: {
    notification: {
      type: Object,
      default: () => {
        return {
          title: '',
          content: '',
        }
      }
    },
    smart_codes: Array
  },
}

</script>