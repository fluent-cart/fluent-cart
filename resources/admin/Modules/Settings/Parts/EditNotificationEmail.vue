<template>
  <div>
    <el-form>
      <el-form-item>
        <el-input v-model="notification.title" :placeholder="$t('Title')"></el-input>
      </el-form-item>
      <el-form-item>
        <el-input type="textarea" v-model="notification.content" :placeholder="$t('Content')"></el-input>
      </el-form-item>
    </el-form>
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