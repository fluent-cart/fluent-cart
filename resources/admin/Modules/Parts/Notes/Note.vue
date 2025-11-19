<template>
  <div>
    <Card.Container v-if="orderId">
      <Card.Header
          :title="$t('Notes')"
          border_bottom
          title_size="small"
      >
        <template #action>
          <IconButton
              size="x-small"
              bg="transparent"
              tag="button"
              border="transparent"
              @click="noteModalIsOpen = true"
              v-if="shouldEnableEditing"
          >
            <DynamicIcon name="Edit"/>
          </IconButton>
        </template>
      </Card.Header>
      <Card.Body :class="noteNeedsCollapse ? 'pb-0' : ''">
        <div class="fct-admin-sidebar-item">
          <el-skeleton v-if="loading" animated>
            <template #template>
              <el-skeleton-item variant="p" />
            </template>
          </el-skeleton>
          <template v-else>
            <span>{{
                noteCopy ? noteTextToDisplay : $t("No notes")
              }}</span>
            <el-button
                class="fct-show-more-button"
                v-if="noteNeedsCollapse"
                @click="toggleNoteText"
                text
            >
              <template v-if="isNoteExpanded">
                {{ $t("Show less") }}
                <DynamicIcon name="ChevronUp"/>
              </template>
              <template v-else >{{ $t("Show more") }}
                <DynamicIcon name="ChevronDown" />
              </template>
            </el-button>
          </template>
        </div>
      </Card.Body>
    </Card.Container>
    <el-dialog
        :append-to-body="true"
        width="45%"
        v-model="noteModalIsOpen"
        :title="noteCopy ? $t('Edit Note') : $t('Add Note')"
    >
      <div v-if="noteModalIsOpen">
        <note-modal
            :note="noteCopy"
            @whenNoteEditIsDone="saveNoteModal"
            @closeModal="noteModalIsOpen = false"
        />
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
</script>

<script type="text/babel">
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import NoteModal from "./Modal.vue";
import Notify from "@/utils/Notify";

export default {
  name: "Notes",
  props: {
    note: {
      type: String,
      default: ''
    },
    shouldEnableEditing: {
      type: Boolean,
      default: true
    },
    orderId: {
      type: [String,Number],
    },
  },
  components: {
    IconButton,
    NoteModal,
    DynamicIcon
  },
  data() {
    return {
      noteModalIsOpen: false,
      isNoteExpanded: false,
      noteCopy: this.note,
      loading: false
    };
  },
  watch: {
    note: {
      handler(newVal, oldVal) {
        this.noteCopy = newVal;
      },
      deep: true,
      immediate: true
    },
  },
  computed: {
    noteNeedsCollapse() {
      return this.noteCopy?.length > 355;
    },
    noteTextToDisplay() {
      if (this.isNoteExpanded || !this.noteNeedsCollapse) {
        return this.noteCopy;
      } else {
        return this.noteCopy.substring(0, 355) + "...";
      }
    },
  },
  methods: {
    saveNoteModal(note, showModal) {
      this.loading = true;
      this.noteModalIsOpen = showModal;

      this.$emit('whenNoteEditIsDone', note)

      if (this.orderId) {
        this.$post('notes/attach', {
          order_id: this.orderId,
          note: note
        }).then(response => {
          Notify.success(response.message);
          this.noteCopy = note;
        }).catch(errors => {
          Notify.error(errors.message)
        }).finally(() => {
          this.loading = false;
        });
      }


    },
    toggleNoteText() {
      this.isNoteExpanded = !this.isNoteExpanded;
    },
  },
  mounted() {
    // this.noteCopy = this.note;
  },
};
</script>
