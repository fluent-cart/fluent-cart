<script setup>
import {getCurrentInstance, h, ref} from "vue";
import IconButton from "./IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";

const props = defineProps({
  title: {
    default: translate('Create Page')
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
    content: props.content
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

  <fct-dialog
      v-model="showPageCreationModal"
      :title="
      /* translators: %s - page name */
      translate('Create %s Page', title)
      "
      width="35%"
      center
      :append-to-body="true"
  >
    <!-- novalidate: the required mark is visual only, as on the el-form-item it replaced. -->
    <form novalidate @submit.prevent="createPage">
      <fct-input v-model="pageName" :label="translate('Page Name')" required
                 :placeholder="translate('Page Name')"/>
    </form>
    <template #footer>
      <div class="dialog-footer">
        <fct-button @click="showPageCreationModal = false" type="info" soft>{{ translate('Cancel') }}</fct-button>
        <fct-button type="primary" @click="createPage">{{ translate('Create Page') }}</fct-button>
      </div>
    </template>
  </fct-dialog>

</template>
