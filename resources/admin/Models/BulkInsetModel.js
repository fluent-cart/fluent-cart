import ProductBaseModel from "@/Models/Product/ProductBaseModel";

class BulkInsertModel extends ProductBaseModel {

    beforeInit() {
        this.data['products'] = [];
        this.data.dummies['product'] = {
            ID: '',
            post_title: '',
            post_name: '',
            post_content: '',
            post_excerpt: '',
            post_status: 'publish',
            post_date: new Date(),
            comment_status: 'close',
            variants: false,
        };
    }

    populateDummyProduct() {

        const product = {
            ...this.data.dummies.product
        };

        //This is important, or this will keep the object reference
        product['detail'] = {
            ...this.data.dummies.productDetail
        };

        // product['gallery'] = {
        //     ...this.data.dummies.gallery
        // };

        product['gallery'] = [];

        product['downloadable_files'] = {
            ...this.data.dummies.downloadableFile
        };


        this.data.products.push(product)
    }

    clearProducts() {
        this.data.products = [];
    }
}

export default BulkInsertModel.init();
