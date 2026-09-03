import Model from "@/utils/model/Model";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Confirmation from "@/utils/Confirmation";
import Rest from "@/utils/http/Rest.js";
import {getVariantLabel, getProductGroupOrder} from "@/utils/variantLabel";


class ProductDownloadableModel extends Model {

    data = {
        downloadableFiles: {},
        sourceProduct: null,
        addModal: {
            files: [],
            isModalOpen: false
        },
        editModal: {
            file: {},
            isCurrentlyEditing: false,
            states: {},
            currentIndex: -1
        },
        validationErrors: {},
        saving: false
    };

    setDownloadableFiles(files, sourceProduct = null) {
        files = files.map(file => {

            if (typeof file.settings === 'string') {
                try {
                    file.settings = JSON.parse(file.settings)
                } catch (e) {

                }
            }
            return file;
        })
        this.data.downloadableFiles = files;
        if (sourceProduct) {
            this.data.sourceProduct = sourceProduct;
        }
    }
    
    syncToSourceProduct() {
        if (this.data.sourceProduct && Array.isArray(this.data.downloadableFiles)) {
            this.data.sourceProduct.downloadable_files = [...this.data.downloadableFiles];
        }
    }

    get downloadableFiles() {
        return this.data.downloadableFiles
    }

    get saving() {
        return this.data.saving;
    }

    setSaving(saving) {
        this.data.saving = saving;
    }

    hasDownloadableFiles() {
        return Array.isArray(this.data.downloadableFiles) && this.data.downloadableFiles.length > 0;
    }

    generateDownloadableVariationName = (jsonVariationIds, product) => {

        if (!jsonVariationIds) {
            return translate('For all variants');
        }

        let ids = [];
        //Check if the ids are Json string
        if (typeof jsonVariationIds === 'string') {
            try {
                ids = JSON.parse(jsonVariationIds);
            } catch (e) {
            }
        } else if (typeof jsonVariationIds === 'object') {
            ids = Object.values(jsonVariationIds)
        }

        if (ids.length === 0) {
            return translate('For all variants');
        }

        // Build labels via getVariantLabel so advanced-variation rows surface
        // their attribute combination ("Red / Cotton") rather than the parent
        // product title shared by every variant. Group order pulled from the
        // product's saved attribute_config so the join order matches the
        // variant table's grouping.
        const groupOrder = getProductGroupOrder(product);
        const mappedVariations = product.variants.reduce((acc, item) => {
            acc[item.id] = getVariantLabel(item, groupOrder);
            return acc;
        }, {});

        const variationNames = ids.map(id => mappedVariations[id]).filter(Boolean);

        if (variationNames.length === 0) return '--';
        if (variationNames.length === 1) return variationNames[0];

        return `${variationNames[0]} +${variationNames.length - 1}`;
    }

    getHiddenVariationNames(jsonVariationIds, product) {
        if (!jsonVariationIds) return [];

        let ids = [];
        if (typeof jsonVariationIds === 'string') {
            try {
                ids = JSON.parse(jsonVariationIds);
            } catch (e) {
                return [];
            }
        } else if (typeof jsonVariationIds === 'object') {
            ids = Object.values(jsonVariationIds);
        }

        const groupOrder = getProductGroupOrder(product);
        const mappedVariations = product.variants.reduce((acc, item) => {
            acc[item.id] = getVariantLabel(item, groupOrder);
            return acc;
        }, {});

        const variationNames = ids.map(id => mappedVariations[id]).filter(Boolean);

        // Return all except the first one (these go inside popover)
        return variationNames.slice(1);
    }

    deleteDownloadableFile = (id, index) => {
        Confirmation.ofDelete(
            translate("Deleting this asset will break the download link in existing purchase emails. To update the file, edit the asset instead of deleting it.")
        ).then(() => {
            Rest.delete(`products/${id}/delete`).then(() => {
                Notify.success(translate('Downloadable File Deleted Successfully'))
                this.downloadableFiles.splice(index, 1)
            }).catch(error => {
                Notify.error(translate('Failed To Delete'))
            })
        }).catch(() => {

        });
    }

    generateVariantOptions(product) {
        const selectedVariants = product.variants.filter(variant => variant.created_at);
        return [...selectedVariants];
    }

    hasVariationOption(product) {
        return Object.values(this.generateVariantOptions(product)).length > 0;
    }

    get downloadableFileSchema() {
        return {
            product_variation_id: [],
            title: '',
            type: '',
            driver: '',
            file_name: '',
            file_path: '',
            file_url: '',
            file_size: '', // size in bytes
            settings: {
                download_limit: '',
                download_expiry: '',
                bucket: '',
            },
            serial: ''
        };
    }

    setInsertableFiles(files) {
        this.data.addModal.files = files;
    }

    get insertableFiles() {
        return this.data.addModal.files
    }

    addDummyInsertableFile(variationId = null) {
        const scheme = this.downloadableFileSchema;
        if (variationId) {
            scheme['product_variation_id'] = [variationId];
        } else {
            //scheme['product_variation_id'] = [-1];
        }
        this.data.addModal.files.push(scheme);
    }

    addDummyDownloadableFileForAllVariant = (product) => {

        this.data.addModal.files = this.data.addModal.files.filter(obj =>
            Object.values(obj).every(value => value !== null && value !== undefined && value !== '')
        );

        Object.values(this.generateVariantOptions(product)).forEach(variantOption => {
            if ((variantOption.id) > 0) {
                this.addDummyInsertableFile(variantOption.id)
            }
        });
    }

    deleteInsertableFile = (index) => {
        this.insertableFiles.splice(index, 1);
    }

    clearInsertableFile = () => {
        this.data.addModal.files = [];
    }

    attachInsertableFiles = (productId) => {
        this.setSaving(true);

        Rest.post(`products/${productId}/sync-downloadable-files`, {
            downloadable_files: this.insertableFiles
        }).then((response) => {
            this.setDownloadableFiles(response.downloadable_files);
            this.syncToSourceProduct();
            this.closeAddModal()
            Notify.success(translate('Variations Synced Successfully'));
        }).catch((error) => {
            this.setSaving(false);
            if (error.status_code == '422') {
                this.setValidationError(error.data);
            } else {
                Notify.error(translate('Please fill up all the fields'))
            }
        }).finally(() => {
            this.setSaving(false);
        });
    }

    getCurrentEditableIndex() {
        return this.data.editModal.currentIndex;
    }

    getCurrentEditableFile() {
        return this.data.editModal.states[this.getCurrentEditableIndex()]?.file ?? null;
    }


    updateDownloadableFile() {
        const file = this.getCurrentEditableFile();
        const index = this.getCurrentEditableIndex();

        if (!(file) || index < 0) {
            return;
        }

        this.setSaving(true)

        Rest.put('products/' + file.id + '/update', file).then((response) => {
            this.closeEditModal(index)
            Notify.success(response.message);
            this.downloadableFiles[index] = {
                ...file
            };
            this.syncToSourceProduct();
        }).catch(error => {
            if (error.hasOwnProperty('message')) {
                Notify.error(error.message)
            } else {
                Notify.error(translate('Failed To Update'))
            }
        })
        .finally(() => {
            this.setSaving(false)
        })
    }

    resetValidationError() {
        this.data.validationErrors = {};
    }

    openAddModal = () => {
        this.data.addModal.isModalOpen = true
    }

    closeAddModal = () => {
        this.data.addModal.isModalOpen = false;
        this.resetValidationError();
    }

    isAddModalOpen() {
        return this.data.addModal.isModalOpen
    }

    openEditModal = (index, file) => {
        this.data.editModal.isCurrentlyEditing = true;
        this.data.editModal.states[index]['visible'] = true;
        this.data.editModal.states[index]['file'] = file;
        this.data.editModal.currentIndex = index;
    }

    closeEditModal = (index) => {
        this.data.editModal.isCurrentlyEditing = false;
        this.data.editModal.states[index]['visible'] = false;
        this.data.editModal.states[index]['file'] = null;
        this.data.editModal.currentIndex = -1;
        this.resetValidationError();
    }

    isEditModalOpen = () => {
        return this.data.editModal.isCurrentlyEditing === true;
    }


    setValidationError = (errors) => {
        this.data.validationErrors = errors
    }

    hasValidationError = (fieldKey) => {
        return this.data.validationErrors.hasOwnProperty(fieldKey);
    }
    clearValidationError = () => {
        this.data.validationErrors = {}
    }

    deleteValidationError = (key) => {
        if (this.data.validationErrors.hasOwnProperty(key)) {
            delete this.data.validationErrors[key];
        }
    }


}

/**
 * @return {ProductDownloadableModel}
 */
export function useProductDownloadableModel() {
    return ProductDownloadableModel.init();
}
