import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";
import Asset from "@/utils/support/Asset";

class SelectorModel extends Model {
    data = {
        search: '',
        loading: false,
        products: [],
        selectedVariations: {},
        showModal: false,
        isMultiple: false,
        selectedVariationIds: [],
        lastManagedVariationId: null,
        selectionStates: {}
    };

    openModal() {
        this.data.showModal = true;
        this.fetchProducts();
    }

    closeModal() {
        this.data.showModal = false;
        this.data.selectedVariationIds = [];
    }

    toggleModal() {
        this.data.showModal = !this.data.showModal;
    }

    setPreselectedVariations(ids) {
        this.data.selectedVariationIds = ids;
        ids.forEach(id => {
            this.data.selectionStates[id] = true;
        });
    }

    fetchProducts(scopes = []) {
        this.loading = true;
        let queryParams = {
            "active_view": 'publish',
            "per_page": 10,
            "page": 1,
            "search": this.data.search,
            'filter_type': 'simple',
            'sort_by': 'ID',
            'with': ['detail.variants.media', 'categories', 'variants.media']
        };
        if (scopes) {
            queryParams['scopes'] = scopes;
        }

        return new Promise((resolve, reject) => {
            Rest.get('products', queryParams)
                .then(response => {
                    this.data.products = Arr.get(response, 'products.data', []);
                    this.data.selectionStates = {};
                    resolve(response);
                })
                .catch((errors) => {
                    console.log(errors);
                    reject(errors);
                })
                .finally(() => {
                    this.loading = false;
                });
        });
    }

    get loading() {
        return this.data.loading;
    }

    getProductTitle(product) {
        return product.post_title;
    }

    getProductThumbnail(product) {
        return product.detail.featured_media?.url || Asset.getUrl('images/placeholder-small.svg');
    }

    getVariantThumbnail(variant) {
        return variant.thumbnail || Asset.getUrl('images/placeholder-small.svg');
    }

    onVariationSelected(variant, checked) {
        if (this.data.isMultiple) {
            if (checked) {
                this.data.selectedVariationIds.push(variant.id);
            } else {
                this.data.selectedVariationIds = this.data.selectedVariationIds.filter(id => id !== variant.id);
            }
        } else {
            this.data.selectedVariationIds = [variant.id];
            if (this.data.lastManagedVariationId !== variant.id) {
                this.data.selectionStates[
                    this.data.lastManagedVariationId
                    ] = false;
            }
        }

        this.data.lastManagedVariationId = variant.id;
    }

    isChecked(variant) {
        return this.data.selectedVariationIds.includes(variant.id);
    }
}

export default function useSelectorModel() {
    return SelectorModel.init();
}
