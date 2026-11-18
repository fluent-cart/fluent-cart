<template>
	<div class="fct-edit-term-modal">
		<div>
			<fct-input
				type="text"
				v-model="localTitle"
				:label="groupType === 'color' ? translate('Color Name') : translate('Title')"
				:placeholder="groupType === 'color' ? translate('e.g. Navy Blue') : translate('Title')"
				:class="validationErrors['title'] ? 'is-error' : ''"
				:validation-errors="validationErrors"
				field-key="title"
			/>

			<Field v-if="groupType === 'color'" :label="translate('Color')">
				<ColorPicker v-model="settingsColor" :with-presets="true"/>
			</Field>

			<Field v-if="groupType === 'image'" :label="translate('Image URL')">
				<fct-input v-model="settingsImage" :placeholder="translate('https://...')">
					<template #append>
						<fct-button @click="openMediaUploader">{{ translate('Upload') }}</fct-button>
					</template>
				</fct-input>
				<div v-if="settingsImage" class="mt-2">
					<img :src="settingsImage" alt="" class="rounded [border-width:1px] border-gray-200 dark:border-dark-400" style="width: 60px; height: 60px; object-fit: contain;"/>
				</div>
			</Field>
		</div>

		<div class="dialog-footer">
			<fct-button :disabled="!localTitle || loading" :loading="loading" @click="updateTerm" type="primary">
				{{ translate('Save changes') }}
			</fct-button>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch } from "vue";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import { handleSuccess } from "@/Bits/common";
import Field from "@/Bits/Components/Fct/Field.vue";
import ColorPicker from "@/Bits/Components/Form/Components/Base/Inputs/ColorPicker.vue";
import Notify from "@/utils/Notify";
import { getSuggestedColor } from "@/utils/colorPresets";

const props = defineProps({
	groupId: { type: [String, Number], required: true },
	editing: { type: Object, required: true },
	group: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['isTermEditingDone']);

const loading = ref(false);
const validationErrors = ref({});

const groupType = computed(() => {
	const settings = props.group && props.group.settings;
	return settings && settings.type ? settings.type : 'options';
});

// Snapshot prop fields into local refs to avoid prop mutation and enable rollback on failure
const localTitle = ref(props.editing.title || '');

const existingSettings = (props.editing.settings && typeof props.editing.settings === 'object')
	? props.editing.settings
	: {};
const settingsColor = ref(existingSettings.color || '');
const settingsImage = ref(existingSettings.image || '');

watch(localTitle, (newTitle) => {
	if (groupType.value === 'color' && !settingsColor.value) {
		const suggested = getSuggestedColor(newTitle);
		if (suggested) settingsColor.value = suggested;
	}
});

const openMediaUploader = () => {
	if (window.wp && window.wp.media) {
		const frame = window.wp.media({
			title: 'Select Image',
			multiple: false,
			library: { type: 'image' },
		});
		frame.on('select', () => {
			const attachment = frame.state().get('selection').first().toJSON();
			settingsImage.value = attachment.url;
		});
		frame.open();
	}
};

const updateTerm = () => {
	loading.value = true;
	validationErrors.value = {};
	Rest.post('options/attr/group/' + props.groupId + '/term/' + props.editing.id, {
		title: localTitle.value,
		settings: { color: settingsColor.value, image: settingsImage.value },
	}).then(response => {
		handleSuccess(response.message);
		emit('isTermEditingDone');
	}).catch(errors => {
		// Canonical pattern (ProductEditModel.js:354): 422 → field map in errors.data.
		if (errors?.status_code == 422 && errors.data) {
			validationErrors.value = errors.data;
		} else {
			// A 422 without a field map (errors.data is null) still carries a
			// human-readable message at the top level — e.g. a duplicate-slug
			// conflict. Fall back to errors.message so the user actually sees it.
			Notify.error(errors?.data?.message || errors?.message || translate('Failed to update the term.'));
		}
	}).finally(() => {
		loading.value = false;
	});
};
</script>
