<template>
	<div class="fct-note-modal">
		<fct-input
        v-model="notes"
        class="mb-[18px]"
        :autosize="{ minRows: 4 }"
        type="textarea"
        minlength="1" maxlength="5000" show-word-limit
    />

		<div class="dialog-footer">
        <fct-button type="info" soft @click="closeModal">
          {{ $t('Cancel')}}
        </fct-button>
      <fct-button type="primary" @click="saveNote">
          {{ $t('Save')}}
        </fct-button>
		</div>
	</div>
</template>

<script>
import {ref} from "vue";

export default {
	name: "NoteModal",
	props: ['note'],
	data() {
		return {
		  notes : this.note
		}
	},
  // add watch on notes
  watch: {
    note: {
      handler: function (newVal, oldVal) {
        this.notes = newVal;
      },
      deep: true
    },
  },
    mounted() {
        // this.notes = this.note
    },
    methods: {
        saveNote() {
            this.$emit('whenNoteEditIsDone', this.notes, false)
        },
        closeModal(){
        this.$emit('closeModal');
        }
    }
}
</script>
