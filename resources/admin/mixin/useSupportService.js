import AppConfig from "@/utils/Config/AppConfig";


export function useSupportService() {

    class SupportService {
        #config = AppConfig.get('support_config');

        isConfigValidated() {
            const requiredKeys = [
                'api',
                'api_user_name',
                'api_password',
                'product_id'
            ];
            let isValidated = true;
            requiredKeys.forEach((key) => {
                if (this.#config[key] === undefined || this.#config[key].length < 1) {
                    isValidated = false;
                }
            });
            return isValidated;
        }

        getConfig(key) {

            if (key !== undefined) {
                return this.#config[key];
            }
            return this.#config;
        }

        getTickets(paginate, onSuccess, onError) {
            const url = this.getConfig('api') + 'tickets?sorting[sortBy]=id&sorting[sortType]=asc&filters[product_id]=' + this.getConfig('product_id');
            return this.#makeApiRequest(url, "GET", {}, onSuccess, onError);
        }

        getTicket(id, onSuccess, onError) {
            const url = this.getConfig('api') + 'tickets/' + id;
            return this.#makeApiRequest(url, "GET", {}, onSuccess, onError);
        }

        createTicket(data, onSuccess, onError) {
            const url = this.getConfig('api') + 'tickets/';
            return this.#makeApiRequest(url, "POST", data, onSuccess, onError);
        }

        #makeApiRequest(url, method, data, onSuccess, onError) {

            let formData = new FormData();
            if (data instanceof FormData) {
                formData = data;
            } else if (typeof data === 'object') {
                Object.keys(data).forEach(key => {
                    formData.append(key, data[key])
                })
            }
            formData.append('product_id', this.getConfig('product_id'));

            return new Promise((resolve, reject) => {
                if (!this.isConfigValidated()) {
                    reject("Invalid Configuration");
                }
                window.jQuery.ajax({
                    url: url,
                    type: method,
                    data: formData, //enctype: 'multipart/form-data',
                    headers: this.#getHeader(),
                    contentType: false,
                    cache: false,
                    processData: false
                })
                    .then(response => {
                        if (typeof onSuccess === 'function') {
                            onSuccess(response);
                        }
                        resolve(response)
                    })
                    .fail((response) => {
                        if (typeof onError === 'function') {
                            onError(response);
                        }
                        reject(response)
                    });
            });
        }

        #getHeader() {
            return {
                "Authorization": "Basic " + btoa(this.getConfig('api_user_name') + `:` + this.getConfig('api_password')),
            };
        }
    }

    return new SupportService;
}
