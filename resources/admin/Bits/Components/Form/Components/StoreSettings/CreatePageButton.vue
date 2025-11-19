<script setup>
import {getCurrentInstance, h, ref} from "vue";

import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

const props = defineProps({
  title: {
    default: 'Create Page'
  },
  content: {
    required: true,
    type: String
  }
})

const emit = defineEmits(['onPageCreated'])

const showPageCreationModal = ref(false)
const pageName = ref(props.title)

const createPage = () => {

  Rest.post('onboarding/create-page', {
    page_name: pageName.value,
    content: props.content,
    save_settings: true
  })
      .then(response => {
        emit('onPageCreated', {
          value: response.page_id,
          label: `${pageName.value}( ${response.page_id})`,
          page_link: response.link
        });
        pageName.value = '';
        showPageCreationModal.value = false;
        Notify.success(translate('The page created successfully.'))
      })
      .catch((response) => {
        if (response && response.hasOwnProperty('page_name')) {
          const errorCount = Object.entries(response.page_name).length;
          let errorMessage = '';
          let index = 0;
          for (const messageId in response.page_name) {
            errorMessage += response.page_name[messageId]
            if (index !== errorCount) {
              errorMessage += '\n'
            }
            index++;
          }
          Notify.error(errorMessage)
        } else {
          Notify.error(translate('Sorry, Unable To Create Page!!'))
        }
      })
}
</script>

<template>
  <IconButton @click.prevent="showPageCreationModal = true" tag="button" bg="info" outline>
    <DynamicIcon name="Plus"/>
  </IconButton>

  <el-dialog
      v-model="showPageCreationModal"
      :title="
      /* translators: %s - page name */
      translate('Create %s Page',title)
      "
      width="35%"
      center
      :append-to-body="true"
  >
    <el-form require-asterisk-position="right" label-position="top"
             @submit.prevent="createPage">
      <el-form-item :label="translate('Page Name')" required>
        <el-input v-model="pageName"
                  :placeholder="translate('Page Name')"/>
      </el-form-item>
    </el-form>
    <template #footer>
      <div class="dialog-footer">
        <el-button @click="showPageCreationModal = false" type="info" soft>{{ translate('Cancel') }}</el-button>
        <el-button type="primary" @click="createPage">{{ translate('Create Page') }}</el-button>
      </div>
    </template>
  </el-dialog>

</template>
