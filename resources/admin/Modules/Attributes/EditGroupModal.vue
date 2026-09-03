<template>
	<div class="fct-attr-group-edit-modal">
		<el-form label-position='top' :data="group">
			<el-row :gutter="24">
				<el-col :span="24">
					<el-form-item :label="$t('Group Title')">
						<el-input ref="titleInputRef" type="text" v-model="group.title" :placeholder="$t('Group Title')" @keyup="handleTitleKeyup"/>
						<template v-if="validationErrors['title']">
							<span class="error" v-for="(error, index) in validationErrors['title']" :key="index">{{error}}</span>
						</template>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item :label="$t('Attribute Type')">
						<el-select v-model="groupSettings.type" style="width: 100%">
							<el-option :label="$t('Options')" value="options"/>
							<el-option :label="$t('Color')" value="color"/>
							<el-option :label="$t('Image')" value="image"/>
						</el-select>
					</el-form-item>
				</el-col>
				<el-col :span="12">
					<el-form-item :label="$t('Styling')">
						<el-select v-model="groupSettings.styling" clearable style="width: 100%">
							<el-option :label="$t('Dropdown')" value="dropdown"/>
							<el-option :label="$t('Button')" value="button"/>
						</el-select>
					</el-form-item>
				</el-col>
			</el-row>
		</el-form>
		<div class="dialog-footer">
			<el-button
				:disabled="!group.title || loading"
				:loading="is_group_loading"
				@click="updateGroup()"
				type="primary"
			> 
				{{ $t('Save changes')}}
			</el-button>
		</div>
	</div>
</template>

<script>

import {ref, reactive} from "vue";
import Rest from "@/utils/http/Rest";
import {handleSuccess, handleError} from "@/Bits/common";

export default {
	name: "EditGroupModal",
	props: ['group', 'groupId'],
	setup(props, ctx) {

		const loading = ref(false)
		const is_group_loading = ref(false)
		const validationErrors = ref({})
		const titleInputRef = ref(null)

		// Exposed for the parent to call from el-dialog's @opened event so
		// the Group Title input gets focus AFTER the dialog's open
		// transition completes — same pattern AddGroupModal uses.
		const focusTitle = () => {
			titleInputRef.value?.focus?.();
		}
		ctx.expose({ focusTitle })

		// Initialize settings from group data (handle legacy groups with no settings)
		const existingSettings = props.group.settings || {};
		const groupSettings = reactive({
			type: existingSettings.type || 'options',
			styling: existingSettings.styling || 'button',
		});

		const updateGroup = () => {

			loading.value = true;
  			validationErrors.value = {}

			const updatedSettings = { ...groupSettings };

			Rest.put('options/attr/group/' + props.groupId, {
				title: props.group.title,
				settings: updatedSettings,
			}).then(response => {
				handleSuccess(response.message);
				props.group.settings = updatedSettings;
				ctx.emit('whenGroupEditIsDone', props.group);

			}).catch(errors => {
				if(errors.message) {
					return handleError(errors);
				}
				if (typeof errors === 'object') {
					return validationErrors.value = errors
				}
			}).finally(() => {
				loading.value = false;
			});
		}

		// Keyboard submit shortcut — Enter inside the title input
		// mirrors the Save changes button. Inline key check instead
		// of Vue's @keyup.enter modifier because the modifier
		// (_withKeys helper) fails to resolve when el-input forwards
		// keyboard events in this Vue / Element Plus combo. Disabled-
		// state guard mirrors the button.
		const handleTitleKeyup = (event) => {
			if (event.key !== 'Enter') return;
			if (loading.value || !props.group.title) return;
			updateGroup();
		}

		return {
			loading,
			is_group_loading,
			updateGroup,
			handleTitleKeyup,
			validationErrors,
			groupSettings,
			titleInputRef
		}
	}
}
</script>

<style scoped>

</style>
