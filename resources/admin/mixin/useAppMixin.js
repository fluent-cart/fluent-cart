import Rest from "@/utils/http/Rest";
import Storage from "@/utils/Storage";
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import Message from "@/utils/Message";
import Clipboard from "@/utils/Clipboard";
import {pluralizeTranslate} from "@/utils/translator/Translator";
import Permission from "@/utils/permission/Permission";
import Asset from "@/utils/support/Asset";
import {parseAddress} from "@/utils/Utils";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import AppConfig from "@/utils/Config/AppConfig";

let onceRegistered = false;

export function useAppMixin(app) {

    return {
        data: function () {
            return {
                appVars: window.fluentCartAdminApp
                //Storage:Storage
            }
        },
        methods: {
            $get: Rest.get,
            $post: Rest.post,
            $put: Rest.put,
            $del: Rest.delete,
            $t: translate,
            $n: pluralizeTranslate,
            formatMoney(amount, currency, hideEmpty = false) {
                if (!amount && hideEmpty) {
                    return '';
                }
                if (!amount) {
                    amount = '0.00';
                }
                return AppConfig.get('shop.currency_sign') + ' ' + amount;
            },
            formatCents(amount, currency, hideEmpty = false) {
                if (!amount && hideEmpty) {
                    return '';
                }

                if (!amount) {
                    amount = '0.00';
                } else {
                    amount = (amount * 100).toFixed(2);
                }

                if (!currency) {
                    return amount;
                }

                amount = new Intl.NumberFormat('en-US').format(amount);

                return AppConfig.get('shop.currency_sign') + ' ' + amount;
            },
            formatNumber(amount, withCurrency = true, hideEmpty = false, currency) {
                return CurrencyFormatter.formatNumber(amount, withCurrency, hideEmpty, currency);
            },
            getAddress(object, type = 'billing') {
                let address = [
                    object[type + '_address_1'],
                    object[type + '_address_2'],
                    object[type + '_city'],
                    object[type + '_city'],
                    object[type + '_state'],
                    object[type + '_zip'],
                    object[type + '_country'],
                ];
                address = address.filter((item) => {
                    return !!item;
                }).join(', ');
                return address || 'no ' + type + ' address';
            },
            getOrderAddress(object, type = 'billing', shouldExcludeName = false) {
                return parseAddress(object, type, shouldExcludeName);
            },
            changeTitle(title) {
                jQuery('head title').text(title + ' - FluentCart');
            },
            handleError(response) {
                if (!response) {
                    return;
                }
                let errorMessage = '';
                if (typeof response === 'string') {
                    errorMessage = response;
                } else if (response && response.message) {
                    errorMessage = response.message;
                } else {
                    errorMessage = response;
                }

                if(typeof response === 'object'){
                    errorMessage = this.convertToText(response);
                }

                if (!errorMessage) {
                    errorMessage = 'Something is wrong!';
                }

                this.$notify({
                    type: 'error',
                    title: 'Error',
                    message: errorMessage,
                    dangerouslyUseHTMLString: true
                });
            },
            convertToText(obj) {
                const string = [];
                if (typeof (obj) === 'object' && (obj.join === undefined)) {
                    for (const prop in obj) {
                        string.push(this.convertToText(obj[prop]));
                    }
                } else if (typeof (obj) === 'object' && !(obj.join === undefined)) {
                    for (const prop in obj) {
                        string.push(this.convertToText(obj[prop]));
                    }
                } else if (typeof (obj) === 'function') {

                } else if (typeof (obj) === 'string') {
                    string.push(obj)
                }

                return string.join('<br />')
            },
            handleSuccess: (message) => {
                Notify.success(message)
            },
            handleMessage: (message, type = 'info', offset = 40, duration = 1000) => {
                Message.show(message, type, offset, duration)
            },
            Storage: () => Storage,

            buildFormRendererValue(schema, value) {
                let data = {};
                Object.keys(schema).forEach((key) => {
                    let field = schema[key];
                    if (['grid'].includes(field.type ?? '')) {
                        data[key] = this.buildFormRendererValue(field.schema ?? {}, value[key] ?? {})
                    } else {
                        data[key] = value[key] ?? '';
                    }
                })
                return data;
            },
            registerCopyAction() {

                if (onceRegistered) {
                    return
                }
                onceRegistered = true;
                document.addEventListener('click', (e) => {
                    if (e.target.matches('.copyable-content')) {
                        Clipboard.copy(e.target.textContent);
                    }
                });
            },
            currencySign() {
                return AppConfig.get('shop.currency_sign');
            },
            getPlaceholderImage() {
                return Asset.getUrl('images/placeholder.svg')
            },
            saveLocal(key, value) {
                Storage.set(key, value);
            },
            getLocal(key) {
                return Storage.get(key);
            },
            hasPermission(permission) {
                return Permission.hasPermission(permission);
            },
        }
    }
}
