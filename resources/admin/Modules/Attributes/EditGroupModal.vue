<template>
	<div class="fct-attr-group-edit-modal">
		<div>
			<Field :label="$t('Group Title')">
				<fct-input ref="titleInputRef" type="text" v-model="group.title" :placeholder="$t('Group Title')" @keyup="handleTitleKeyup"/>
				<template v-if="validationErrors['title']">
					<span class="error" v-for="(error, index) in validationErrors['title']" :key="index">{{error}}</span>
				</template>
			</Field>
			<div class="fct-attr-group-modal-row">
				<fct-select
					v-model="groupSettings.type"
					:label="$t('Attribute Type')"
					style="width: 100%"
					:options="[
						{label: $t('Options'), value: 'options'},
						{label: $t('Color'), value: 'color'},
						{label: $t('Image'), value: 'image'}
					]"
				/>
				<fct-select
					v-model="groupSettings.styling"
					:label="$t('Styling')"
					clearable
					style="width: 100%"
					:options="[
						{label: $t('Dropdown'), value: 'dropdown'},
						{label: $t('Button'), value: 'button'}
					]"
				/>
			</div>
		</div>
		<div class="dialog-footer">
			<fct-button
				:disabled="!group.title || loading"
				:loading="is_group_loading"
				@click="updateGroup()"
				type="primary"
			> 
				{{ $t('Save changes')}}
			</fct-button>
		</div>
	</div>
</template>

<script>

import {ref, reactive} from "vue";
import Rest from "@/utils/http/Rest";
import {handleSuccess, handleError} from "@/Bits/common";
import Field from "@/Bits/Components/Fct/Field.vue";

export default {
	name: "EditGroupModal",
	components: {Field},
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
