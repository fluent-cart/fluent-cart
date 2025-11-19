<template>
	<div class="fct-note-modal">
		<el-form label-position="top">
      <el-form-item>
        <el-input
            v-model="notes"
            :autosize="{ minRows: 4 }"
            type="textarea"
            minlength="1" maxlength="5000" show-word-limit
        />
      </el-form-item>
		</el-form>

		<div class="dialog-footer">
        <el-button type="info" soft @click="closeModal">
          {{ $t('Cancel')}}
        </el-button>
      <el-button type="primary" @click="saveNote">
          {{ $t('Save')}}
        </el-button>
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
    methods: {
        saveNote() {
            this.$emit('whenNoteEditIsDone', this.notes, false)
        },
        closeModal(){
        this.$emit('closeModal');
        }
    },
    mounted() {
        // this.notes = this.note
    }
}
</script>
