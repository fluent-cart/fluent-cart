<template>

  <UserCan permission="labels/manage">
    <div>
      <Card.Container>
        <Card.Header
            :title="$t('Labels')"
            border_bottom
            title_size="small"
        >
          <template #action>
            <IconButton
                size="x-small"
                bg="transparent"
                tag="button"
                border="transparent"
                @click="openLabelModal"
                v-if="shouldEnableEditing"
            >
              <DynamicIcon name="Edit"/>
            </IconButton>
          </template>
        </Card.Header>
        <Card.Body>
          <div class="fct-admin-sidebar-item">
            <LabelModal
                :bindToType="bindToType"
                :bindToId="bindToId"
                :labelModalIsOpen="labelModalIsOpen"
                :selected_labels="selectedLabels"
                :shouldEnableEditing="shouldEnableEditing"
                @update:update-label="updateLabel"
                @closeLabelModal="labelModalIsOpen = false"
            />
          </div>
        </Card.Body>
      </Card.Container>
    </div>
  </UserCan>

</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
</script>

<script type="text/babel">
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelModal from "./Modal.vue";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

export default {
  name: "Labels",
  props: {
    bindToType: {
      type: String,
    },
    bindToId: {
      type: [String,Number],
    },
    selectedLabels: {
      type: Array,
      default: () => []
    },
    shouldEnableEditing: {
      type: Boolean,
      default: true
    },
  },
  components: {
    IconButton,
    LabelModal,
    DynamicIcon
  },
  data() {
    return {
      labelModalIsOpen: false,
    };
  },
  methods: {
    openLabelModal() {
      this.labelModalIsOpen = true;
    },
    updateLabel(selectedLabels) {
      if (this.bindToId && this.bindToType) {
        this.$post('labels/update-label-selections', {
          bind_to_type: this.bindToType,
          bind_to_id: this.bindToId,
          selectedLabels
        }).then(response => {
          Notify.success(response.message)
        }).catch(errors => {
          Notify.error(errors.message)
        })
      }
      this.$emit('update:update-label', selectedLabels)
    },
    closeLabel() {
      this.labelModalIsOpen = false
    },
  },
  mounted() {

  },
};
</script>
