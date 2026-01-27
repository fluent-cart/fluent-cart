<?php return [
    '_AddProductItemModal.css' => [
        'file' => 'assets/AddProductItemModal.css',
        'src' => '_AddProductItemModal.css'
    ],
    '_AddProductItemModal.js' => [
        'file' => 'chunks/AddProductItemModal.js',
        'name' => 'AddProductItemModal',
        'imports' => [
            '_vue.esm-bundler.js',
            '_DynamicIcon.js',
            'resources/admin/Bits/Components/Icons/CaretRight.vue',
            '_Str.js',
            '_productService.js',
            '_Translator.js',
            '_Model.js',
            '_Rest.js',
            '_Arr.js',
            '_Asset.js'
        ],
        'css' => [
            'assets/AddProductItemModal.css'
        ]
    ],
    '_Arr.js' => [
        'file' => 'chunks/Arr.js',
        'name' => 'Arr'
    ],
    '_Asset.js' => [
        'file' => 'chunks/Asset.js',
        'name' => 'Asset',
        'imports' => [
            '_Arr.js',
            '_Url.js'
        ]
    ],
    '_Badge.css' => [
        'file' => 'assets/Badge.css',
        'src' => '_Badge.css'
    ],
    '_Badge.js' => [
        'file' => 'chunks/Badge.js',
        'name' => 'Badge',
        'imports' => [
            '_Str.js',
            '_Arr.js',
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js',
            '_DynamicIcon.js',
            '_Translator.js'
        ],
        'css' => [
            'assets/Badge.css'
        ]
    ],
    '_BlockEditorTranslator.js' => [
        'file' => 'chunks/BlockEditorTranslator.js',
        'name' => 'BlockEditorTranslator'
    ],
    '_BundleProducts.js' => [
        'file' => 'chunks/BundleProducts.js',
        'name' => 'BundleProducts',
        'imports' => [
            '_DynamicIcon.js',
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_CancelSubscription.js' => [
        'file' => 'chunks/CancelSubscription.js',
        'name' => 'CancelSubscription',
        'imports' => [
            '_vue.esm-bundler.js',
            '_Empty.js',
            '_Badge.js',
            '_Translator.js',
            '_Str.js',
            '_Url.js',
            '_DynamicIcon.js',
            '_RouteCell.js',
            '_productService.js',
            '_OrderCustomerInformation.js',
            '_Rest.js',
            '_Notify.js',
            '_NotFound.js',
            '_index.js',
            '_Arr.js',
            '_common.js',
            '_BundleProducts.js',
            '__plugin-vue_export-helper.js',
            '_Utils.js'
        ]
    ],
    '_ColorPickerField.js' => [
        'file' => 'chunks/ColorPickerField.js',
        'name' => 'ColorPickerField',
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js'
        ]
    ],
    '_CopyToClipboard.css' => [
        'file' => 'assets/CopyToClipboard.css',
        'src' => '_CopyToClipboard.css'
    ],
    '_CopyToClipboard.js' => [
        'file' => 'chunks/CopyToClipboard.js',
        'name' => 'CopyToClipboard',
        'imports' => [
            '_vue.esm-bundler.js',
            '_Rest.js',
            '_Translator.js',
            '__plugin-vue_export-helper.js',
            '_Badge.js',
            '_DynamicIcon.js',
            '_Notify.js',
            '_index.js'
        ],
        'css' => [
            'assets/CopyToClipboard.css'
        ]
    ],
    '_CustomSelect.js' => [
        'file' => 'chunks/CustomSelect.js',
        'name' => 'CustomSelect',
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index5.js'
        ]
    ],
    '_DynamicIcon.js' => [
        'file' => 'chunks/DynamicIcon.js',
        'name' => 'DynamicIcon',
        'imports' => [
            '_vue.esm-bundler.js'
        ],
        'dynamicImports' => [
            'resources/admin/Bits/Components/Icons/Access.vue',
            'resources/admin/Bits/Components/Icons/AddProduct.vue',
            'resources/admin/Bits/Components/Icons/AdvancedFilter.vue',
            'resources/admin/Bits/Components/Icons/AlertIcon.vue',
            'resources/admin/Bits/Components/Icons/All.vue',
            'resources/admin/Bits/Components/Icons/AllOrdersIcon.vue',
            'resources/admin/Bits/Components/Icons/AppsLine.vue',
            'resources/admin/Bits/Components/Icons/Archived.vue',
            'resources/admin/Bits/Components/Icons/ArrowDown.vue',
            'resources/admin/Bits/Components/Icons/ArrowLeft.vue',
            'resources/admin/Bits/Components/Icons/ArrowLeftRight.vue',
            'resources/admin/Bits/Components/Icons/ArrowLongRight.vue',
            'resources/admin/Bits/Components/Icons/ArrowRight.vue',
            'resources/admin/Bits/Components/Icons/ArrowUp.vue',
            'resources/admin/Bits/Components/Icons/ArrowUpDown.vue',
            'resources/admin/Bits/Components/Icons/AtmCard.vue',
            'resources/admin/Bits/Components/Icons/BankCard.vue',
            'resources/admin/Bits/Components/Icons/BarChart.vue',
            'resources/admin/Bits/Components/Icons/Bundle.vue',
            'resources/admin/Bits/Components/Icons/Calendar.vue',
            'resources/admin/Bits/Components/Icons/Camera.vue',
            'resources/admin/Bits/Components/Icons/CaretDown.vue',
            'resources/admin/Bits/Components/Icons/CaretRight.vue',
            'resources/admin/Bits/Components/Icons/CaretUp.vue',
            'resources/admin/Bits/Components/Icons/Cart.vue',
            'resources/admin/Bits/Components/Icons/ChartLine.vue',
            'resources/admin/Bits/Components/Icons/Check.vue',
            'resources/admin/Bits/Components/Icons/CheckCircle.vue',
            'resources/admin/Bits/Components/Icons/CheckCircleFill.vue',
            'resources/admin/Bits/Components/Icons/Checkout.vue',
            'resources/admin/Bits/Components/Icons/ChevronDown.vue',
            'resources/admin/Bits/Components/Icons/ChevronLeft.vue',
            'resources/admin/Bits/Components/Icons/ChevronRight.vue',
            'resources/admin/Bits/Components/Icons/ChevronUp.vue',
            'resources/admin/Bits/Components/Icons/ChevronUpDown.vue',
            'resources/admin/Bits/Components/Icons/CircleClose.vue',
            'resources/admin/Bits/Components/Icons/Clock.vue',
            'resources/admin/Bits/Components/Icons/Close.vue',
            'resources/admin/Bits/Components/Icons/Cloth.vue',
            'resources/admin/Bits/Components/Icons/Code.vue',
            'resources/admin/Bits/Components/Icons/ColorPicker.vue',
            'resources/admin/Bits/Components/Icons/ColumnIcon.vue',
            'resources/admin/Bits/Components/Icons/Condition.vue',
            'resources/admin/Bits/Components/Icons/Configuration.vue',
            'resources/admin/Bits/Components/Icons/Copy.vue',
            'resources/admin/Bits/Components/Icons/Core.vue',
            'resources/admin/Bits/Components/Icons/Coupon.vue',
            'resources/admin/Bits/Components/Icons/CreditCard.vue',
            'resources/admin/Bits/Components/Icons/Crm.vue',
            'resources/admin/Bits/Components/Icons/Cross.vue',
            'resources/admin/Bits/Components/Icons/CrossCircle.vue',
            'resources/admin/Bits/Components/Icons/Crown.vue',
            'resources/admin/Bits/Components/Icons/Currency.vue',
            'resources/admin/Bits/Components/Icons/DarkIcons/Empty/CheckoutAction.vue',
            'resources/admin/Bits/Components/Icons/DarkIcons/Empty/EmailNotification.vue',
            'resources/admin/Bits/Components/Icons/DarkIcons/Empty/ListView.vue',
            'resources/admin/Bits/Components/Icons/DarkIcons/Empty/Order.vue',
            'resources/admin/Bits/Components/Icons/DarkIcons/Empty/RoleAndPermission.vue',
            'resources/admin/Bits/Components/Icons/Database.vue',
            'resources/admin/Bits/Components/Icons/Delete.vue',
            'resources/admin/Bits/Components/Icons/Discount.vue',
            'resources/admin/Bits/Components/Icons/Document.vue',
            'resources/admin/Bits/Components/Icons/Dollar.vue',
            'resources/admin/Bits/Components/Icons/Download.vue',
            'resources/admin/Bits/Components/Icons/Duplicate.vue',
            'resources/admin/Bits/Components/Icons/Edit.vue',
            'resources/admin/Bits/Components/Icons/EditDiscount.vue',
            'resources/admin/Bits/Components/Icons/EditProduct.vue',
            'resources/admin/Bits/Components/Icons/Empty/Chart.vue',
            'resources/admin/Bits/Components/Icons/Empty/CheckoutAction.vue',
            'resources/admin/Bits/Components/Icons/Empty/EmailNotification.vue',
            'resources/admin/Bits/Components/Icons/Empty/GlobalSearch.vue',
            'resources/admin/Bits/Components/Icons/Empty/Integrations.vue',
            'resources/admin/Bits/Components/Icons/Empty/ListView.vue',
            'resources/admin/Bits/Components/Icons/Empty/Order.vue',
            'resources/admin/Bits/Components/Icons/Empty/RoleAndPermission.vue',
            'resources/admin/Bits/Components/Icons/Empty/WebPage.vue',
            'resources/admin/Bits/Components/Icons/Enter.vue',
            'resources/admin/Bits/Components/Icons/EqualizerLine.vue',
            'resources/admin/Bits/Components/Icons/External.vue',
            'resources/admin/Bits/Components/Icons/Eye.vue',
            'resources/admin/Bits/Components/Icons/EyeOff.vue',
            'resources/admin/Bits/Components/Icons/Failed.vue',
            'resources/admin/Bits/Components/Icons/FailedCircle.vue',
            'resources/admin/Bits/Components/Icons/FileWrite.vue',
            'resources/admin/Bits/Components/Icons/Files.vue',
            'resources/admin/Bits/Components/Icons/Filter.vue',
            'resources/admin/Bits/Components/Icons/Folder.vue',
            'resources/admin/Bits/Components/Icons/FolderCloud.vue',
            'resources/admin/Bits/Components/Icons/Frame.vue',
            'resources/admin/Bits/Components/Icons/FullScreen.vue',
            'resources/admin/Bits/Components/Icons/GalleryAdd.vue',
            'resources/admin/Bits/Components/Icons/GearIcon.vue',
            'resources/admin/Bits/Components/Icons/Gift.vue',
            'resources/admin/Bits/Components/Icons/Globe.vue',
            'resources/admin/Bits/Components/Icons/GradientCheckCircle.vue',
            'resources/admin/Bits/Components/Icons/GradientWarningCircle.vue',
            'resources/admin/Bits/Components/Icons/HamBurger.vue',
            'resources/admin/Bits/Components/Icons/HandHold.vue',
            'resources/admin/Bits/Components/Icons/HourGlass.vue',
            'resources/admin/Bits/Components/Icons/InActive.vue',
            'resources/admin/Bits/Components/Icons/Information.vue',
            'resources/admin/Bits/Components/Icons/InformationFill.vue',
            'resources/admin/Bits/Components/Icons/Integrations.vue',
            'resources/admin/Bits/Components/Icons/InventoryFill.vue',
            'resources/admin/Bits/Components/Icons/Invoice.vue',
            'resources/admin/Bits/Components/Icons/LayoutGrid.vue',
            'resources/admin/Bits/Components/Icons/License.vue',
            'resources/admin/Bits/Components/Icons/LicensedInactive.vue',
            'resources/admin/Bits/Components/Icons/LicensedProduct.vue',
            'resources/admin/Bits/Components/Icons/LineChart.vue',
            'resources/admin/Bits/Components/Icons/Link.vue',
            'resources/admin/Bits/Components/Icons/List.vue',
            'resources/admin/Bits/Components/Icons/ListView.vue',
            'resources/admin/Bits/Components/Icons/LiveMode.vue',
            'resources/admin/Bits/Components/Icons/Lms.vue',
            'resources/admin/Bits/Components/Icons/LocationPin.vue',
            'resources/admin/Bits/Components/Icons/Lock.vue',
            'resources/admin/Bits/Components/Icons/Logout.vue',
            'resources/admin/Bits/Components/Icons/Mac.vue',
            'resources/admin/Bits/Components/Icons/MagicPen.vue',
            'resources/admin/Bits/Components/Icons/Menu.vue',
            'resources/admin/Bits/Components/Icons/Message.vue',
            'resources/admin/Bits/Components/Icons/Minus.vue',
            'resources/admin/Bits/Components/Icons/MinusCircle.vue',
            'resources/admin/Bits/Components/Icons/MinusSignCircle.vue',
            'resources/admin/Bits/Components/Icons/Module.vue',
            'resources/admin/Bits/Components/Icons/Money.vue',
            'resources/admin/Bits/Components/Icons/MoneySend.vue',
            'resources/admin/Bits/Components/Icons/Moon.vue',
            'resources/admin/Bits/Components/Icons/MoonIcon.vue',
            'resources/admin/Bits/Components/Icons/More.vue',
            'resources/admin/Bits/Components/Icons/OrderItemsIcon.vue',
            'resources/admin/Bits/Components/Icons/OrderValueIcon.vue',
            'resources/admin/Bits/Components/Icons/Package.vue',
            'resources/admin/Bits/Components/Icons/Page.vue',
            'resources/admin/Bits/Components/Icons/PaidOrdersIcon.vue',
            'resources/admin/Bits/Components/Icons/Palette.vue',
            'resources/admin/Bits/Components/Icons/Party.vue',
            'resources/admin/Bits/Components/Icons/PaymentIcon.vue',
            'resources/admin/Bits/Components/Icons/Pending.vue',
            'resources/admin/Bits/Components/Icons/Plus.vue',
            'resources/admin/Bits/Components/Icons/PlusCircle.vue',
            'resources/admin/Bits/Components/Icons/Print.vue',
            'resources/admin/Bits/Components/Icons/Product.vue',
            'resources/admin/Bits/Components/Icons/Question.vue',
            'resources/admin/Bits/Components/Icons/RadioSelector.vue',
            'resources/admin/Bits/Components/Icons/Receipt.vue',
            'resources/admin/Bits/Components/Icons/Redirect.vue',
            'resources/admin/Bits/Components/Icons/Refresh.vue',
            'resources/admin/Bits/Components/Icons/Reload.vue',
            'resources/admin/Bits/Components/Icons/ReorderDotsVertical.vue',
            'resources/admin/Bits/Components/Icons/Rotate.vue',
            'resources/admin/Bits/Components/Icons/RunningShoe.vue',
            'resources/admin/Bits/Components/Icons/Scratch.vue',
            'resources/admin/Bits/Components/Icons/Screenshot.vue',
            'resources/admin/Bits/Components/Icons/Search.vue',
            'resources/admin/Bits/Components/Icons/SearchAdd.vue',
            'resources/admin/Bits/Components/Icons/SearchV2.vue',
            'resources/admin/Bits/Components/Icons/Send.vue',
            'resources/admin/Bits/Components/Icons/Setting.vue',
            'resources/admin/Bits/Components/Icons/ShieldCheck.vue',
            'resources/admin/Bits/Components/Icons/ShipmentStatus.vue',
            'resources/admin/Bits/Components/Icons/Shipping.vue',
            'resources/admin/Bits/Components/Icons/ShoppingCartIcon.vue',
            'resources/admin/Bits/Components/Icons/SpeedFill.vue',
            'resources/admin/Bits/Components/Icons/Stars.vue',
            'resources/admin/Bits/Components/Icons/Stop.vue',
            'resources/admin/Bits/Components/Icons/StoreIcon.vue',
            'resources/admin/Bits/Components/Icons/Subscription.vue',
            'resources/admin/Bits/Components/Icons/Sun.vue',
            'resources/admin/Bits/Components/Icons/SunIcon.vue',
            'resources/admin/Bits/Components/Icons/Tag.vue',
            'resources/admin/Bits/Components/Icons/Tax.vue',
            'resources/admin/Bits/Components/Icons/TestMode.vue',
            'resources/admin/Bits/Components/Icons/Tools.vue',
            'resources/admin/Bits/Components/Icons/TrashIcon.vue',
            'resources/admin/Bits/Components/Icons/Trigger.vue',
            'resources/admin/Bits/Components/Icons/Truck.vue',
            'resources/admin/Bits/Components/Icons/Unfulfilled.vue',
            'resources/admin/Bits/Components/Icons/Unlink.vue',
            'resources/admin/Bits/Components/Icons/Upload.vue',
            'resources/admin/Bits/Components/Icons/Wallet.vue',
            'resources/admin/Bits/Components/Icons/Warning.vue',
            'resources/admin/Bits/Components/Icons/WarningFill.vue',
            'resources/admin/Bits/Components/Icons/Webhooks.vue'
        ]
    ],
    '_EditorPanel.js' => [
        'file' => 'chunks/EditorPanel.js',
        'name' => 'EditorPanel',
        'imports' => [
            '_BlockEditorTranslator.js'
        ]
    ],
    '_EditorPanelRow.js' => [
        'file' => 'chunks/EditorPanelRow.js',
        'name' => 'EditorPanelRow',
        'imports' => [
            '_BlockEditorTranslator.js'
        ]
    ],
    '_Empty.js' => [
        'file' => 'chunks/Empty.js',
        'name' => 'Empty',
        'imports' => [
            '_vue.esm-bundler.js',
            '_DynamicIcon.js'
        ]
    ],
    '_ErrorBoundary.js' => [
        'file' => 'chunks/ErrorBoundary.js',
        'name' => 'ErrorBoundary',
        'imports' => [
            '_BlockEditorTranslator.js'
        ]
    ],
    '_Icons.js' => [
        'file' => 'chunks/Icons.js',
        'name' => 'Icons',
        'imports' => [
            '_BlockEditorTranslator.js'
        ]
    ],
    '_InspectorSettings.js' => [
        'file' => 'chunks/InspectorSettings.js',
        'name' => 'InspectorSettings',
        'imports' => [
            '_BlockEditorTranslator.js',
            '_SelectVariationModal.js',
            '_Icons.js'
        ]
    ],
    '_Model.js' => [
        'file' => 'chunks/Model.js',
        'name' => 'Model',
        'imports' => [
            '_Rest.js',
            '_vue.esm-bundler.js'
        ]
    ],
    '_NotFound.js' => [
        'file' => 'chunks/NotFound.js',
        'name' => 'NotFound',
        'imports' => [
            '_DynamicIcon.js',
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js',
            '_Asset.js',
            '_Translator.js',
            '_Str.js'
        ]
    ],
    '_Notify.js' => [
        'file' => 'chunks/Notify.js',
        'name' => 'Notify',
        'imports' => [
            '_Str.js',
            '_Translator.js',
            '_index.js'
        ]
    ],
    '_OrderCustomerInformation.js' => [
        'file' => 'chunks/OrderCustomerInformation.js',
        'name' => 'OrderCustomerInformation',
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js',
            '_Badge.js',
            '_Empty.js',
            '_common.js',
            '_CopyToClipboard.js',
            '_DynamicIcon.js',
            '_Translator.js',
            '_RouteCell.js',
            '_Arr.js',
            '_Asset.js',
            '_Notify.js',
            '_Rest.js',
            '_NotFound.js',
            '_index.js',
            '_productService.js'
        ]
    ],
    '_ProductContext.js' => [
        'file' => 'chunks/ProductContext.js',
        'name' => 'ProductContext'
    ],
    '_ProductListItem.js' => [
        'file' => 'chunks/ProductListItem.js',
        'name' => 'ProductListItem',
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js'
        ]
    ],
    '_ProductVariationSelector.js' => [
        'file' => 'chunks/ProductVariationSelector.js',
        'name' => 'ProductVariationSelector',
        'imports' => [
            '_vue.esm-bundler.js',
            '_DynamicIcon.js',
            '_Str.js',
            '_Translator.js',
            '_Utils.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_Rest.js' => [
        'file' => 'chunks/Rest.js',
        'name' => 'Rest'
    ],
    '_RouteCell.js' => [
        'file' => 'chunks/RouteCell.js',
        'name' => 'RouteCell',
        'imports' => [
            '_Model.js',
            '_vue.esm-bundler.js',
            '_Badge.js',
            '_Rest.js',
            '_Str.js',
            '_Url.js',
            '_Translator.js',
            '_dayjs.min.js',
            '_timezone.js',
            '_Notify.js',
            '_DynamicIcon.js',
            '__plugin-vue_export-helper.js',
            '_index.js',
            '_Utils.js',
            '_Arr.js'
        ]
    ],
    '_SelectProductModal.js' => [
        'file' => 'chunks/SelectProductModal.js',
        'name' => 'SelectProductModal',
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js',
            '_index4.js',
            '_ProductListItem.js',
            '_index5.js',
            '_add-query-args.js'
        ]
    ],
    '_SelectVariationModal.js' => [
        'file' => 'chunks/SelectVariationModal.js',
        'name' => 'SelectVariationModal',
        'imports' => [
            '_BlockEditorTranslator.js',
            '_ProductListItem.js',
            '_Icons.js',
            '_index5.js',
            '_index4.js',
            '_add-query-args.js'
        ]
    ],
    '_ServerSidePreview.css' => [
        'file' => 'assets/ServerSidePreview.css',
        'src' => '_ServerSidePreview.css'
    ],
    '_ServerSidePreview.js' => [
        'file' => 'chunks/ServerSidePreview.js',
        'name' => 'ServerSidePreview',
        'imports' => [
            '_vue.esm-bundler.js',
            '_useElementor.js',
            '__plugin-vue_export-helper.js',
            '_add-query-args.js'
        ],
        'css' => [
            'assets/ServerSidePreview.css'
        ]
    ],
    '_SingleProductContext.js' => [
        'file' => 'chunks/SingleProductContext.js',
        'name' => 'SingleProductContext'
    ],
    '_SingleProductContext2.js' => [
        'file' => 'chunks/SingleProductContext2.js',
        'name' => 'SingleProductContext'
    ],
    '_Str.js' => [
        'file' => 'chunks/Str.js',
        'name' => 'Str',
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    '_Translator.js' => [
        'file' => 'chunks/Translator.js',
        'name' => 'Translator',
        'imports' => [
            '_vue.esm-bundler.js',
            '_Arr.js'
        ]
    ],
    '_Url.js' => [
        'file' => 'chunks/Url.js',
        'name' => 'Url',
        'imports' => [
            '_Str.js',
            '_Arr.js'
        ]
    ],
    '_Utils.js' => [
        'file' => 'chunks/Utils.js',
        'name' => 'Utils',
        'imports' => [
            '_Translator.js',
            '_dayjs.min.js',
            '_timezone.js'
        ]
    ],
    '__plugin-vue_export-helper.js' => [
        'file' => 'chunks/_plugin-vue_export-helper.js',
        'name' => '_plugin-vue_export-helper'
    ],
    '_add-query-args.js' => [
        'file' => 'chunks/add-query-args.js',
        'name' => 'add-query-args'
    ],
    '_common.js' => [
        'file' => 'chunks/common.js',
        'name' => 'common',
        'imports' => [
            '_dayjs.min.js',
            '_Translator.js',
            '_Str.js',
            '_timezone.js',
            '_Badge.js',
            '_index.js'
        ]
    ],
    '_countries.js' => [
        'file' => 'chunks/countries.js',
        'name' => 'countries',
        'imports' => [
            '_vue.esm-bundler.js',
            '_Translator.js',
            '_Rest.js',
            '_Notify.js',
            '_NotFound.js'
        ]
    ],
    '_dateShortCuts.js' => [
        'file' => 'chunks/dateShortCuts.js',
        'name' => 'dateShortCuts',
        'imports' => [
            '_vue.esm-bundler.js',
            '_Translator.js',
            '_index3.js',
            '_Badge.js',
            '_DynamicIcon.js',
            '_index.js',
            '_Rest.js',
            '_Arr.js',
            '_RouteCell.js',
            '_dayjs.min.js'
        ]
    ],
    '_dayjs.min.js' => [
        'file' => 'chunks/dayjs.min.js',
        'name' => 'dayjs.min'
    ],
    '_defaults.js' => [
        'file' => 'chunks/defaults.js',
        'name' => 'defaults',
        'imports' => [
            '_index.js',
            '_vue.esm-bundler.js',
            '_index2.js',
            '_dayjs.min.js',
            '_index3.js'
        ]
    ],
    '_index.js' => [
        'file' => 'chunks/index.js',
        'name' => 'index',
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    '_index2.js' => [
        'file' => 'chunks/index2.js',
        'name' => 'index',
        'imports' => [
            '_vue.esm-bundler.js',
            '_index.js',
            '_index3.js',
            '_dayjs.min.js'
        ]
    ],
    '_index3.js' => [
        'file' => 'chunks/index3.js',
        'name' => 'index',
        'imports' => [
            '_vue.esm-bundler.js',
            '_index.js'
        ]
    ],
    '_index4.js' => [
        'file' => 'chunks/index4.js',
        'name' => 'index'
    ],
    '_index5.js' => [
        'file' => 'chunks/index5.js',
        'name' => 'index',
        'imports' => [
            '_BlockEditorTranslator.js'
        ]
    ],
    '_payment-loader.js' => [
        'file' => 'chunks/payment-loader.js',
        'name' => 'payment-loader'
    ],
    '_productService.js' => [
        'file' => 'chunks/productService.js',
        'name' => 'productService',
        'imports' => [
            '_Translator.js',
            '_Arr.js',
            '_dayjs.min.js'
        ]
    ],
    '_timezone.js' => [
        'file' => 'chunks/timezone.js',
        'name' => 'timezone',
        'imports' => [
            '_dayjs.min.js'
        ]
    ],
    '_useElementPlusComponents.js' => [
        'file' => 'chunks/useElementPlusComponents.js',
        'name' => 'useElementPlusComponents',
        'imports' => [
            '_index.js',
            '_index2.js'
        ]
    ],
    '_useElementor.js' => [
        'file' => 'chunks/useElementor.js',
        'name' => 'useElementor',
        'imports' => [
            '_Arr.js'
        ]
    ],
    '_vue.esm-bundler.js' => [
        'file' => 'chunks/vue.esm-bundler.js',
        'name' => 'vue.esm-bundler'
    ],
    'resources/admin/Bits/Components/Form/Components/Affix/Test.vue' => [
        'file' => 'chunks/Test.js',
        'name' => 'Test',
        'src' => 'resources/admin/Bits/Components/Form/Components/Affix/Test.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/Coupon/BuyProducts.vue' => [
        'file' => 'chunks/BuyProducts.js',
        'name' => 'BuyProducts',
        'src' => 'resources/admin/Bits/Components/Form/Components/Coupon/BuyProducts.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/Coupon/Categories.vue' => [
        'file' => 'chunks/Categories.js',
        'name' => 'Categories',
        'src' => 'resources/admin/Bits/Components/Form/Components/Coupon/Categories.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/Coupon/Code.vue' => [
        'file' => 'chunks/Code.js',
        'name' => 'Code',
        'src' => 'resources/admin/Bits/Components/Form/Components/Coupon/Code.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/Coupon/GetProducts.vue' => [
        'file' => 'chunks/GetProducts.js',
        'name' => 'GetProducts',
        'src' => 'resources/admin/Bits/Components/Form/Components/Coupon/GetProducts.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/Coupon/IncludeExclude/Categories.vue' => [
        'file' => 'chunks/Categories2.js',
        'name' => 'Categories',
        'src' => 'resources/admin/Bits/Components/Form/Components/Coupon/IncludeExclude/Categories.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/Coupon/IncludeExclude/Product.vue' => [
        'file' => 'chunks/Product.js',
        'name' => 'Product',
        'src' => 'resources/admin/Bits/Components/Form/Components/Coupon/IncludeExclude/Product.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/Coupon/Products.vue' => [
        'file' => 'chunks/Products.js',
        'name' => 'Products',
        'src' => 'resources/admin/Bits/Components/Form/Components/Coupon/Products.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/ModuleSettings.vue' => [
        'file' => 'chunks/ModuleSettings.js',
        'name' => 'ModuleSettings',
        'src' => 'resources/admin/Bits/Components/Form/Components/ModuleSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_Translator.js',
            '_Badge.js',
            '_Arr.js',
            '_Str.js',
            '__plugin-vue_export-helper.js',
            '_DynamicIcon.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/PaymentView.vue' => [
        'file' => 'chunks/PaymentView.js',
        'name' => 'PaymentView',
        'src' => 'resources/admin/Bits/Components/Form/Components/PaymentView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_Translator.js',
            '_DynamicIcon.js',
            '_Arr.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/StoreSettings/AddressComponent.vue' => [
        'file' => 'chunks/AddressComponent.js',
        'name' => 'AddressComponent',
        'src' => 'resources/admin/Bits/Components/Form/Components/StoreSettings/AddressComponent.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_Rest.js',
            '_Translator.js',
            '_CopyToClipboard.js',
            '_Arr.js',
            '__plugin-vue_export-helper.js',
            '_Badge.js',
            '_Str.js',
            '_DynamicIcon.js',
            '_Notify.js',
            '_index.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/StoreSettings/CreatePageButton.vue' => [
        'file' => 'chunks/CreatePageButton.js',
        'name' => 'CreatePageButton',
        'src' => 'resources/admin/Bits/Components/Form/Components/StoreSettings/CreatePageButton.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_DynamicIcon.js',
            '_Translator.js',
            '_Notify.js',
            '_Rest.js',
            '_Badge.js',
            '_Arr.js',
            '_Str.js',
            '_index.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/StoreSettings/PageSelector.vue' => [
        'file' => 'chunks/PageSelector.js',
        'name' => 'PageSelector',
        'src' => 'resources/admin/Bits/Components/Form/Components/StoreSettings/PageSelector.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_Translator.js',
            '_DynamicIcon.js',
            '_Arr.js',
            'resources/admin/Bits/Components/Form/Components/StoreSettings/CreatePageButton.vue',
            '_Notify.js',
            '_Str.js',
            '_index.js',
            '_Rest.js',
            '_Badge.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Form/Components/TurnstileSettings.vue' => [
        'file' => 'chunks/TurnstileSettings.js',
        'name' => 'TurnstileSettings',
        'src' => 'resources/admin/Bits/Components/Form/Components/TurnstileSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_Translator.js',
            '_Badge.js',
            '_Arr.js',
            '_Str.js',
            '__plugin-vue_export-helper.js',
            '_DynamicIcon.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Access.vue' => [
        'file' => 'chunks/Access.js',
        'name' => 'Access',
        'src' => 'resources/admin/Bits/Components/Icons/Access.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/AddProduct.vue' => [
        'file' => 'chunks/AddProduct.js',
        'name' => 'AddProduct',
        'src' => 'resources/admin/Bits/Components/Icons/AddProduct.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/AdvancedFilter.vue' => [
        'file' => 'chunks/AdvancedFilter.js',
        'name' => 'AdvancedFilter',
        'src' => 'resources/admin/Bits/Components/Icons/AdvancedFilter.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/AlertIcon.vue' => [
        'file' => 'chunks/AlertIcon.js',
        'name' => 'AlertIcon',
        'src' => 'resources/admin/Bits/Components/Icons/AlertIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/All.vue' => [
        'file' => 'chunks/All.js',
        'name' => 'All',
        'src' => 'resources/admin/Bits/Components/Icons/All.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/AllOrdersIcon.vue' => [
        'file' => 'chunks/AllOrdersIcon.js',
        'name' => 'AllOrdersIcon',
        'src' => 'resources/admin/Bits/Components/Icons/AllOrdersIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/AppsLine.vue' => [
        'file' => 'chunks/AppsLine.js',
        'name' => 'AppsLine',
        'src' => 'resources/admin/Bits/Components/Icons/AppsLine.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Archived.vue' => [
        'file' => 'chunks/Archived.js',
        'name' => 'Archived',
        'src' => 'resources/admin/Bits/Components/Icons/Archived.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ArrowDown.vue' => [
        'file' => 'chunks/ArrowDown.js',
        'name' => 'ArrowDown',
        'src' => 'resources/admin/Bits/Components/Icons/ArrowDown.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ArrowLeft.vue' => [
        'file' => 'chunks/ArrowLeft.js',
        'name' => 'ArrowLeft',
        'src' => 'resources/admin/Bits/Components/Icons/ArrowLeft.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ArrowLeftRight.vue' => [
        'file' => 'chunks/ArrowLeftRight.js',
        'name' => 'ArrowLeftRight',
        'src' => 'resources/admin/Bits/Components/Icons/ArrowLeftRight.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ArrowLongRight.vue' => [
        'file' => 'chunks/ArrowLongRight.js',
        'name' => 'ArrowLongRight',
        'src' => 'resources/admin/Bits/Components/Icons/ArrowLongRight.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ArrowRight.vue' => [
        'file' => 'chunks/ArrowRight.js',
        'name' => 'ArrowRight',
        'src' => 'resources/admin/Bits/Components/Icons/ArrowRight.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ArrowUp.vue' => [
        'file' => 'chunks/ArrowUp.js',
        'name' => 'ArrowUp',
        'src' => 'resources/admin/Bits/Components/Icons/ArrowUp.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ArrowUpDown.vue' => [
        'file' => 'chunks/ArrowUpDown.js',
        'name' => 'ArrowUpDown',
        'src' => 'resources/admin/Bits/Components/Icons/ArrowUpDown.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/AtmCard.vue' => [
        'file' => 'chunks/AtmCard.js',
        'name' => 'AtmCard',
        'src' => 'resources/admin/Bits/Components/Icons/AtmCard.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/BankCard.vue' => [
        'file' => 'chunks/BankCard.js',
        'name' => 'BankCard',
        'src' => 'resources/admin/Bits/Components/Icons/BankCard.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/BarChart.vue' => [
        'file' => 'chunks/BarChart.js',
        'name' => 'BarChart',
        'src' => 'resources/admin/Bits/Components/Icons/BarChart.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Bundle.vue' => [
        'file' => 'chunks/Bundle.js',
        'name' => 'Bundle',
        'src' => 'resources/admin/Bits/Components/Icons/Bundle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Calendar.vue' => [
        'file' => 'chunks/Calendar.js',
        'name' => 'Calendar',
        'src' => 'resources/admin/Bits/Components/Icons/Calendar.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Camera.vue' => [
        'file' => 'chunks/Camera.js',
        'name' => 'Camera',
        'src' => 'resources/admin/Bits/Components/Icons/Camera.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CaretDown.vue' => [
        'file' => 'chunks/CaretDown.js',
        'name' => 'CaretDown',
        'src' => 'resources/admin/Bits/Components/Icons/CaretDown.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CaretRight.vue' => [
        'file' => 'chunks/CaretRight.js',
        'name' => 'CaretRight',
        'src' => 'resources/admin/Bits/Components/Icons/CaretRight.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CaretUp.vue' => [
        'file' => 'chunks/CaretUp.js',
        'name' => 'CaretUp',
        'src' => 'resources/admin/Bits/Components/Icons/CaretUp.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Cart.vue' => [
        'file' => 'chunks/Cart.js',
        'name' => 'Cart',
        'src' => 'resources/admin/Bits/Components/Icons/Cart.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ChartLine.vue' => [
        'file' => 'chunks/ChartLine.js',
        'name' => 'ChartLine',
        'src' => 'resources/admin/Bits/Components/Icons/ChartLine.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Check.vue' => [
        'file' => 'chunks/Check.js',
        'name' => 'Check',
        'src' => 'resources/admin/Bits/Components/Icons/Check.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CheckCircle.vue' => [
        'file' => 'chunks/CheckCircle.js',
        'name' => 'CheckCircle',
        'src' => 'resources/admin/Bits/Components/Icons/CheckCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CheckCircleFill.vue' => [
        'file' => 'chunks/CheckCircleFill.js',
        'name' => 'CheckCircleFill',
        'src' => 'resources/admin/Bits/Components/Icons/CheckCircleFill.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Checkout.vue' => [
        'file' => 'chunks/Checkout.js',
        'name' => 'Checkout',
        'src' => 'resources/admin/Bits/Components/Icons/Checkout.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ChevronDown.vue' => [
        'file' => 'chunks/ChevronDown.js',
        'name' => 'ChevronDown',
        'src' => 'resources/admin/Bits/Components/Icons/ChevronDown.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ChevronLeft.vue' => [
        'file' => 'chunks/ChevronLeft.js',
        'name' => 'ChevronLeft',
        'src' => 'resources/admin/Bits/Components/Icons/ChevronLeft.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ChevronRight.vue' => [
        'file' => 'chunks/ChevronRight.js',
        'name' => 'ChevronRight',
        'src' => 'resources/admin/Bits/Components/Icons/ChevronRight.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ChevronUp.vue' => [
        'file' => 'chunks/ChevronUp.js',
        'name' => 'ChevronUp',
        'src' => 'resources/admin/Bits/Components/Icons/ChevronUp.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ChevronUpDown.vue' => [
        'file' => 'chunks/ChevronUpDown.js',
        'name' => 'ChevronUpDown',
        'src' => 'resources/admin/Bits/Components/Icons/ChevronUpDown.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CircleClose.vue' => [
        'file' => 'chunks/CircleClose.js',
        'name' => 'CircleClose',
        'src' => 'resources/admin/Bits/Components/Icons/CircleClose.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Clock.vue' => [
        'file' => 'chunks/Clock.js',
        'name' => 'Clock',
        'src' => 'resources/admin/Bits/Components/Icons/Clock.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Close.vue' => [
        'file' => 'chunks/Close.js',
        'name' => 'Close',
        'src' => 'resources/admin/Bits/Components/Icons/Close.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Cloth.vue' => [
        'file' => 'chunks/Cloth.js',
        'name' => 'Cloth',
        'src' => 'resources/admin/Bits/Components/Icons/Cloth.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Code.vue' => [
        'file' => 'chunks/Code2.js',
        'name' => 'Code',
        'src' => 'resources/admin/Bits/Components/Icons/Code.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ColorPicker.vue' => [
        'file' => 'chunks/ColorPicker.js',
        'name' => 'ColorPicker',
        'src' => 'resources/admin/Bits/Components/Icons/ColorPicker.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ColumnIcon.vue' => [
        'file' => 'chunks/ColumnIcon.js',
        'name' => 'ColumnIcon',
        'src' => 'resources/admin/Bits/Components/Icons/ColumnIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Condition.vue' => [
        'file' => 'chunks/Condition.js',
        'name' => 'Condition',
        'src' => 'resources/admin/Bits/Components/Icons/Condition.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Configuration.vue' => [
        'file' => 'chunks/Configuration.js',
        'name' => 'Configuration',
        'src' => 'resources/admin/Bits/Components/Icons/Configuration.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Copy.vue' => [
        'file' => 'chunks/Copy.js',
        'name' => 'Copy',
        'src' => 'resources/admin/Bits/Components/Icons/Copy.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Core.vue' => [
        'file' => 'chunks/Core.js',
        'name' => 'Core',
        'src' => 'resources/admin/Bits/Components/Icons/Core.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Coupon.vue' => [
        'file' => 'chunks/Coupon.js',
        'name' => 'Coupon',
        'src' => 'resources/admin/Bits/Components/Icons/Coupon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CreditCard.vue' => [
        'file' => 'chunks/CreditCard.js',
        'name' => 'CreditCard',
        'src' => 'resources/admin/Bits/Components/Icons/CreditCard.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Crm.vue' => [
        'file' => 'chunks/Crm.js',
        'name' => 'Crm',
        'src' => 'resources/admin/Bits/Components/Icons/Crm.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Cross.vue' => [
        'file' => 'chunks/Cross.js',
        'name' => 'Cross',
        'src' => 'resources/admin/Bits/Components/Icons/Cross.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/CrossCircle.vue' => [
        'file' => 'chunks/CrossCircle.js',
        'name' => 'CrossCircle',
        'src' => 'resources/admin/Bits/Components/Icons/CrossCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Crown.vue' => [
        'file' => 'chunks/Crown.js',
        'name' => 'Crown',
        'src' => 'resources/admin/Bits/Components/Icons/Crown.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Currency.vue' => [
        'file' => 'chunks/Currency.js',
        'name' => 'Currency',
        'src' => 'resources/admin/Bits/Components/Icons/Currency.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/DarkIcons/Empty/CheckoutAction.vue' => [
        'file' => 'chunks/CheckoutAction.js',
        'name' => 'CheckoutAction',
        'src' => 'resources/admin/Bits/Components/Icons/DarkIcons/Empty/CheckoutAction.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/DarkIcons/Empty/EmailNotification.vue' => [
        'file' => 'chunks/EmailNotification.js',
        'name' => 'EmailNotification',
        'src' => 'resources/admin/Bits/Components/Icons/DarkIcons/Empty/EmailNotification.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/DarkIcons/Empty/ListView.vue' => [
        'file' => 'chunks/ListView.js',
        'name' => 'ListView',
        'src' => 'resources/admin/Bits/Components/Icons/DarkIcons/Empty/ListView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/DarkIcons/Empty/Order.vue' => [
        'file' => 'chunks/Order.js',
        'name' => 'Order',
        'src' => 'resources/admin/Bits/Components/Icons/DarkIcons/Empty/Order.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/DarkIcons/Empty/RoleAndPermission.vue' => [
        'file' => 'chunks/RoleAndPermission.js',
        'name' => 'RoleAndPermission',
        'src' => 'resources/admin/Bits/Components/Icons/DarkIcons/Empty/RoleAndPermission.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Database.vue' => [
        'file' => 'chunks/Database.js',
        'name' => 'Database',
        'src' => 'resources/admin/Bits/Components/Icons/Database.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Delete.vue' => [
        'file' => 'chunks/Delete.js',
        'name' => 'Delete',
        'src' => 'resources/admin/Bits/Components/Icons/Delete.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Discount.vue' => [
        'file' => 'chunks/Discount.js',
        'name' => 'Discount',
        'src' => 'resources/admin/Bits/Components/Icons/Discount.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Document.vue' => [
        'file' => 'chunks/Document.js',
        'name' => 'Document',
        'src' => 'resources/admin/Bits/Components/Icons/Document.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Dollar.vue' => [
        'file' => 'chunks/Dollar.js',
        'name' => 'Dollar',
        'src' => 'resources/admin/Bits/Components/Icons/Dollar.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Download.vue' => [
        'file' => 'chunks/Download.js',
        'name' => 'Download',
        'src' => 'resources/admin/Bits/Components/Icons/Download.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Duplicate.vue' => [
        'file' => 'chunks/Duplicate.js',
        'name' => 'Duplicate',
        'src' => 'resources/admin/Bits/Components/Icons/Duplicate.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Edit.vue' => [
        'file' => 'chunks/Edit.js',
        'name' => 'Edit',
        'src' => 'resources/admin/Bits/Components/Icons/Edit.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/EditDiscount.vue' => [
        'file' => 'chunks/EditDiscount.js',
        'name' => 'EditDiscount',
        'src' => 'resources/admin/Bits/Components/Icons/EditDiscount.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/EditProduct.vue' => [
        'file' => 'chunks/EditProduct.js',
        'name' => 'EditProduct',
        'src' => 'resources/admin/Bits/Components/Icons/EditProduct.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/Chart.vue' => [
        'file' => 'chunks/Chart.js',
        'name' => 'Chart',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/Chart.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/CheckoutAction.vue' => [
        'file' => 'chunks/CheckoutAction2.js',
        'name' => 'CheckoutAction',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/CheckoutAction.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/EmailNotification.vue' => [
        'file' => 'chunks/EmailNotification2.js',
        'name' => 'EmailNotification',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/EmailNotification.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/GlobalSearch.vue' => [
        'file' => 'chunks/GlobalSearch.js',
        'name' => 'GlobalSearch',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/GlobalSearch.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/Integrations.vue' => [
        'file' => 'chunks/Integrations.js',
        'name' => 'Integrations',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/Integrations.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/ListView.vue' => [
        'file' => 'chunks/ListView2.js',
        'name' => 'ListView',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/ListView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/Order.vue' => [
        'file' => 'chunks/Order2.js',
        'name' => 'Order',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/Order.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/RoleAndPermission.vue' => [
        'file' => 'chunks/RoleAndPermission2.js',
        'name' => 'RoleAndPermission',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/RoleAndPermission.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Empty/WebPage.vue' => [
        'file' => 'chunks/WebPage.js',
        'name' => 'WebPage',
        'src' => 'resources/admin/Bits/Components/Icons/Empty/WebPage.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Enter.vue' => [
        'file' => 'chunks/Enter.js',
        'name' => 'Enter',
        'src' => 'resources/admin/Bits/Components/Icons/Enter.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/EqualizerLine.vue' => [
        'file' => 'chunks/EqualizerLine.js',
        'name' => 'EqualizerLine',
        'src' => 'resources/admin/Bits/Components/Icons/EqualizerLine.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/External.vue' => [
        'file' => 'chunks/External.js',
        'name' => 'External',
        'src' => 'resources/admin/Bits/Components/Icons/External.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Eye.vue' => [
        'file' => 'chunks/Eye.js',
        'name' => 'Eye',
        'src' => 'resources/admin/Bits/Components/Icons/Eye.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/EyeOff.vue' => [
        'file' => 'chunks/EyeOff.js',
        'name' => 'EyeOff',
        'src' => 'resources/admin/Bits/Components/Icons/EyeOff.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Failed.vue' => [
        'file' => 'chunks/Failed.js',
        'name' => 'Failed',
        'src' => 'resources/admin/Bits/Components/Icons/Failed.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/FailedCircle.vue' => [
        'file' => 'chunks/FailedCircle.js',
        'name' => 'FailedCircle',
        'src' => 'resources/admin/Bits/Components/Icons/FailedCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/FileWrite.vue' => [
        'file' => 'chunks/FileWrite.js',
        'name' => 'FileWrite',
        'src' => 'resources/admin/Bits/Components/Icons/FileWrite.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Files.vue' => [
        'file' => 'chunks/Files.js',
        'name' => 'Files',
        'src' => 'resources/admin/Bits/Components/Icons/Files.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Filter.vue' => [
        'file' => 'chunks/Filter.js',
        'name' => 'Filter',
        'src' => 'resources/admin/Bits/Components/Icons/Filter.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Folder.vue' => [
        'file' => 'chunks/Folder.js',
        'name' => 'Folder',
        'src' => 'resources/admin/Bits/Components/Icons/Folder.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/FolderCloud.vue' => [
        'file' => 'chunks/FolderCloud.js',
        'name' => 'FolderCloud',
        'src' => 'resources/admin/Bits/Components/Icons/FolderCloud.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Frame.vue' => [
        'file' => 'chunks/Frame.js',
        'name' => 'Frame',
        'src' => 'resources/admin/Bits/Components/Icons/Frame.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/FullScreen.vue' => [
        'file' => 'chunks/FullScreen.js',
        'name' => 'FullScreen',
        'src' => 'resources/admin/Bits/Components/Icons/FullScreen.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/GalleryAdd.vue' => [
        'file' => 'chunks/GalleryAdd.js',
        'name' => 'GalleryAdd',
        'src' => 'resources/admin/Bits/Components/Icons/GalleryAdd.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/GearIcon.vue' => [
        'file' => 'chunks/GearIcon.js',
        'name' => 'GearIcon',
        'src' => 'resources/admin/Bits/Components/Icons/GearIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Gift.vue' => [
        'file' => 'chunks/Gift.js',
        'name' => 'Gift',
        'src' => 'resources/admin/Bits/Components/Icons/Gift.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Globe.vue' => [
        'file' => 'chunks/Globe.js',
        'name' => 'Globe',
        'src' => 'resources/admin/Bits/Components/Icons/Globe.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/GradientCheckCircle.vue' => [
        'file' => 'chunks/GradientCheckCircle.js',
        'name' => 'GradientCheckCircle',
        'src' => 'resources/admin/Bits/Components/Icons/GradientCheckCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/GradientWarningCircle.vue' => [
        'file' => 'chunks/GradientWarningCircle.js',
        'name' => 'GradientWarningCircle',
        'src' => 'resources/admin/Bits/Components/Icons/GradientWarningCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/HamBurger.vue' => [
        'file' => 'chunks/HamBurger.js',
        'name' => 'HamBurger',
        'src' => 'resources/admin/Bits/Components/Icons/HamBurger.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/HandHold.vue' => [
        'file' => 'chunks/HandHold.js',
        'name' => 'HandHold',
        'src' => 'resources/admin/Bits/Components/Icons/HandHold.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/HourGlass.vue' => [
        'file' => 'chunks/HourGlass.js',
        'name' => 'HourGlass',
        'src' => 'resources/admin/Bits/Components/Icons/HourGlass.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/InActive.vue' => [
        'file' => 'chunks/InActive.js',
        'name' => 'InActive',
        'src' => 'resources/admin/Bits/Components/Icons/InActive.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Information.vue' => [
        'file' => 'chunks/Information.js',
        'name' => 'Information',
        'src' => 'resources/admin/Bits/Components/Icons/Information.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/InformationFill.vue' => [
        'file' => 'chunks/InformationFill.js',
        'name' => 'InformationFill',
        'src' => 'resources/admin/Bits/Components/Icons/InformationFill.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Integrations.vue' => [
        'file' => 'chunks/Integrations2.js',
        'name' => 'Integrations',
        'src' => 'resources/admin/Bits/Components/Icons/Integrations.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/InventoryFill.vue' => [
        'file' => 'chunks/InventoryFill.js',
        'name' => 'InventoryFill',
        'src' => 'resources/admin/Bits/Components/Icons/InventoryFill.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Invoice.vue' => [
        'file' => 'chunks/Invoice.js',
        'name' => 'Invoice',
        'src' => 'resources/admin/Bits/Components/Icons/Invoice.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/LayoutGrid.vue' => [
        'file' => 'chunks/LayoutGrid.js',
        'name' => 'LayoutGrid',
        'src' => 'resources/admin/Bits/Components/Icons/LayoutGrid.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/License.vue' => [
        'file' => 'chunks/License.js',
        'name' => 'License',
        'src' => 'resources/admin/Bits/Components/Icons/License.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/LicensedInactive.vue' => [
        'file' => 'chunks/LicensedInactive.js',
        'name' => 'LicensedInactive',
        'src' => 'resources/admin/Bits/Components/Icons/LicensedInactive.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/LicensedProduct.vue' => [
        'file' => 'chunks/LicensedProduct.js',
        'name' => 'LicensedProduct',
        'src' => 'resources/admin/Bits/Components/Icons/LicensedProduct.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/LineChart.vue' => [
        'file' => 'chunks/LineChart.js',
        'name' => 'LineChart',
        'src' => 'resources/admin/Bits/Components/Icons/LineChart.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Link.vue' => [
        'file' => 'chunks/Link.js',
        'name' => 'Link',
        'src' => 'resources/admin/Bits/Components/Icons/Link.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/List.vue' => [
        'file' => 'chunks/List.js',
        'name' => 'List',
        'src' => 'resources/admin/Bits/Components/Icons/List.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ListView.vue' => [
        'file' => 'chunks/ListView3.js',
        'name' => 'ListView',
        'src' => 'resources/admin/Bits/Components/Icons/ListView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/LiveMode.vue' => [
        'file' => 'chunks/LiveMode.js',
        'name' => 'LiveMode',
        'src' => 'resources/admin/Bits/Components/Icons/LiveMode.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Lms.vue' => [
        'file' => 'chunks/Lms.js',
        'name' => 'Lms',
        'src' => 'resources/admin/Bits/Components/Icons/Lms.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/LocationPin.vue' => [
        'file' => 'chunks/LocationPin.js',
        'name' => 'LocationPin',
        'src' => 'resources/admin/Bits/Components/Icons/LocationPin.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Lock.vue' => [
        'file' => 'chunks/Lock.js',
        'name' => 'Lock',
        'src' => 'resources/admin/Bits/Components/Icons/Lock.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Logout.vue' => [
        'file' => 'chunks/Logout.js',
        'name' => 'Logout',
        'src' => 'resources/admin/Bits/Components/Icons/Logout.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Mac.vue' => [
        'file' => 'chunks/Mac.js',
        'name' => 'Mac',
        'src' => 'resources/admin/Bits/Components/Icons/Mac.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/MagicPen.vue' => [
        'file' => 'chunks/MagicPen.js',
        'name' => 'MagicPen',
        'src' => 'resources/admin/Bits/Components/Icons/MagicPen.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Menu.vue' => [
        'file' => 'chunks/Menu.js',
        'name' => 'Menu',
        'src' => 'resources/admin/Bits/Components/Icons/Menu.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Message.vue' => [
        'file' => 'chunks/Message.js',
        'name' => 'Message',
        'src' => 'resources/admin/Bits/Components/Icons/Message.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Minus.vue' => [
        'file' => 'chunks/Minus.js',
        'name' => 'Minus',
        'src' => 'resources/admin/Bits/Components/Icons/Minus.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/MinusCircle.vue' => [
        'file' => 'chunks/MinusCircle.js',
        'name' => 'MinusCircle',
        'src' => 'resources/admin/Bits/Components/Icons/MinusCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/MinusSignCircle.vue' => [
        'file' => 'chunks/MinusSignCircle.js',
        'name' => 'MinusSignCircle',
        'src' => 'resources/admin/Bits/Components/Icons/MinusSignCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Module.vue' => [
        'file' => 'chunks/Module.js',
        'name' => 'Module',
        'src' => 'resources/admin/Bits/Components/Icons/Module.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Money.vue' => [
        'file' => 'chunks/Money.js',
        'name' => 'Money',
        'src' => 'resources/admin/Bits/Components/Icons/Money.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/MoneySend.vue' => [
        'file' => 'chunks/MoneySend.js',
        'name' => 'MoneySend',
        'src' => 'resources/admin/Bits/Components/Icons/MoneySend.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Moon.vue' => [
        'file' => 'chunks/Moon.js',
        'name' => 'Moon',
        'src' => 'resources/admin/Bits/Components/Icons/Moon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/MoonIcon.vue' => [
        'file' => 'chunks/MoonIcon.js',
        'name' => 'MoonIcon',
        'src' => 'resources/admin/Bits/Components/Icons/MoonIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/More.vue' => [
        'file' => 'chunks/More.js',
        'name' => 'More',
        'src' => 'resources/admin/Bits/Components/Icons/More.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/OrderItemsIcon.vue' => [
        'file' => 'chunks/OrderItemsIcon.js',
        'name' => 'OrderItemsIcon',
        'src' => 'resources/admin/Bits/Components/Icons/OrderItemsIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/OrderValueIcon.vue' => [
        'file' => 'chunks/OrderValueIcon.js',
        'name' => 'OrderValueIcon',
        'src' => 'resources/admin/Bits/Components/Icons/OrderValueIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Package.vue' => [
        'file' => 'chunks/Package.js',
        'name' => 'Package',
        'src' => 'resources/admin/Bits/Components/Icons/Package.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Page.vue' => [
        'file' => 'chunks/Page.js',
        'name' => 'Page',
        'src' => 'resources/admin/Bits/Components/Icons/Page.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/PaidOrdersIcon.vue' => [
        'file' => 'chunks/PaidOrdersIcon.js',
        'name' => 'PaidOrdersIcon',
        'src' => 'resources/admin/Bits/Components/Icons/PaidOrdersIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Palette.vue' => [
        'file' => 'chunks/Palette.js',
        'name' => 'Palette',
        'src' => 'resources/admin/Bits/Components/Icons/Palette.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Party.vue' => [
        'file' => 'chunks/Party.js',
        'name' => 'Party',
        'src' => 'resources/admin/Bits/Components/Icons/Party.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/PaymentIcon.vue' => [
        'file' => 'chunks/PaymentIcon.js',
        'name' => 'PaymentIcon',
        'src' => 'resources/admin/Bits/Components/Icons/PaymentIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Pending.vue' => [
        'file' => 'chunks/Pending.js',
        'name' => 'Pending',
        'src' => 'resources/admin/Bits/Components/Icons/Pending.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Plus.vue' => [
        'file' => 'chunks/Plus.js',
        'name' => 'Plus',
        'src' => 'resources/admin/Bits/Components/Icons/Plus.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/PlusCircle.vue' => [
        'file' => 'chunks/PlusCircle.js',
        'name' => 'PlusCircle',
        'src' => 'resources/admin/Bits/Components/Icons/PlusCircle.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Print.vue' => [
        'file' => 'chunks/Print.js',
        'name' => 'Print',
        'src' => 'resources/admin/Bits/Components/Icons/Print.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Product.vue' => [
        'file' => 'chunks/Product2.js',
        'name' => 'Product',
        'src' => 'resources/admin/Bits/Components/Icons/Product.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Question.vue' => [
        'file' => 'chunks/Question.js',
        'name' => 'Question',
        'src' => 'resources/admin/Bits/Components/Icons/Question.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/RadioSelector.vue' => [
        'file' => 'chunks/RadioSelector.js',
        'name' => 'RadioSelector',
        'src' => 'resources/admin/Bits/Components/Icons/RadioSelector.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Receipt.vue' => [
        'file' => 'chunks/Receipt.js',
        'name' => 'Receipt',
        'src' => 'resources/admin/Bits/Components/Icons/Receipt.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Redirect.vue' => [
        'file' => 'chunks/Redirect.js',
        'name' => 'Redirect',
        'src' => 'resources/admin/Bits/Components/Icons/Redirect.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Refresh.vue' => [
        'file' => 'chunks/Refresh.js',
        'name' => 'Refresh',
        'src' => 'resources/admin/Bits/Components/Icons/Refresh.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Reload.vue' => [
        'file' => 'chunks/Reload.js',
        'name' => 'Reload',
        'src' => 'resources/admin/Bits/Components/Icons/Reload.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ReorderDotsVertical.vue' => [
        'file' => 'chunks/ReorderDotsVertical.js',
        'name' => 'ReorderDotsVertical',
        'src' => 'resources/admin/Bits/Components/Icons/ReorderDotsVertical.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Rotate.vue' => [
        'file' => 'chunks/Rotate.js',
        'name' => 'Rotate',
        'src' => 'resources/admin/Bits/Components/Icons/Rotate.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/RunningShoe.vue' => [
        'file' => 'chunks/RunningShoe.js',
        'name' => 'RunningShoe',
        'src' => 'resources/admin/Bits/Components/Icons/RunningShoe.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Scratch.vue' => [
        'file' => 'chunks/Scratch.js',
        'name' => 'Scratch',
        'src' => 'resources/admin/Bits/Components/Icons/Scratch.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Screenshot.vue' => [
        'file' => 'chunks/Screenshot.js',
        'name' => 'Screenshot',
        'src' => 'resources/admin/Bits/Components/Icons/Screenshot.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Search.vue' => [
        'file' => 'chunks/Search.js',
        'name' => 'Search',
        'src' => 'resources/admin/Bits/Components/Icons/Search.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/SearchAdd.vue' => [
        'file' => 'chunks/SearchAdd.js',
        'name' => 'SearchAdd',
        'src' => 'resources/admin/Bits/Components/Icons/SearchAdd.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/SearchV2.vue' => [
        'file' => 'chunks/SearchV2.js',
        'name' => 'SearchV2',
        'src' => 'resources/admin/Bits/Components/Icons/SearchV2.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Send.vue' => [
        'file' => 'chunks/Send.js',
        'name' => 'Send',
        'src' => 'resources/admin/Bits/Components/Icons/Send.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Setting.vue' => [
        'file' => 'chunks/Setting.js',
        'name' => 'Setting',
        'src' => 'resources/admin/Bits/Components/Icons/Setting.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ShieldCheck.vue' => [
        'file' => 'chunks/ShieldCheck.js',
        'name' => 'ShieldCheck',
        'src' => 'resources/admin/Bits/Components/Icons/ShieldCheck.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ShipmentStatus.vue' => [
        'file' => 'chunks/ShipmentStatus.js',
        'name' => 'ShipmentStatus',
        'src' => 'resources/admin/Bits/Components/Icons/ShipmentStatus.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Shipping.vue' => [
        'file' => 'chunks/Shipping.js',
        'name' => 'Shipping',
        'src' => 'resources/admin/Bits/Components/Icons/Shipping.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/ShoppingCartIcon.vue' => [
        'file' => 'chunks/ShoppingCartIcon.js',
        'name' => 'ShoppingCartIcon',
        'src' => 'resources/admin/Bits/Components/Icons/ShoppingCartIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/SpeedFill.vue' => [
        'file' => 'chunks/SpeedFill.js',
        'name' => 'SpeedFill',
        'src' => 'resources/admin/Bits/Components/Icons/SpeedFill.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Stars.vue' => [
        'file' => 'chunks/Stars.js',
        'name' => 'Stars',
        'src' => 'resources/admin/Bits/Components/Icons/Stars.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Stop.vue' => [
        'file' => 'chunks/Stop.js',
        'name' => 'Stop',
        'src' => 'resources/admin/Bits/Components/Icons/Stop.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/StoreIcon.vue' => [
        'file' => 'chunks/StoreIcon.js',
        'name' => 'StoreIcon',
        'src' => 'resources/admin/Bits/Components/Icons/StoreIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Subscription.vue' => [
        'file' => 'chunks/Subscription.js',
        'name' => 'Subscription',
        'src' => 'resources/admin/Bits/Components/Icons/Subscription.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Sun.vue' => [
        'file' => 'chunks/Sun.js',
        'name' => 'Sun',
        'src' => 'resources/admin/Bits/Components/Icons/Sun.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/SunIcon.vue' => [
        'file' => 'chunks/SunIcon.js',
        'name' => 'SunIcon',
        'src' => 'resources/admin/Bits/Components/Icons/SunIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Tag.vue' => [
        'file' => 'chunks/Tag.js',
        'name' => 'Tag',
        'src' => 'resources/admin/Bits/Components/Icons/Tag.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Tax.vue' => [
        'file' => 'chunks/Tax.js',
        'name' => 'Tax',
        'src' => 'resources/admin/Bits/Components/Icons/Tax.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/TestMode.vue' => [
        'file' => 'chunks/TestMode.js',
        'name' => 'TestMode',
        'src' => 'resources/admin/Bits/Components/Icons/TestMode.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Tools.vue' => [
        'file' => 'chunks/Tools.js',
        'name' => 'Tools',
        'src' => 'resources/admin/Bits/Components/Icons/Tools.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/TrashIcon.vue' => [
        'file' => 'chunks/TrashIcon.js',
        'name' => 'TrashIcon',
        'src' => 'resources/admin/Bits/Components/Icons/TrashIcon.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Trigger.vue' => [
        'file' => 'chunks/Trigger.js',
        'name' => 'Trigger',
        'src' => 'resources/admin/Bits/Components/Icons/Trigger.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Truck.vue' => [
        'file' => 'chunks/Truck.js',
        'name' => 'Truck',
        'src' => 'resources/admin/Bits/Components/Icons/Truck.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Unfulfilled.vue' => [
        'file' => 'chunks/Unfulfilled.js',
        'name' => 'Unfulfilled',
        'src' => 'resources/admin/Bits/Components/Icons/Unfulfilled.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Unlink.vue' => [
        'file' => 'chunks/Unlink.js',
        'name' => 'Unlink',
        'src' => 'resources/admin/Bits/Components/Icons/Unlink.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Upload.vue' => [
        'file' => 'chunks/Upload.js',
        'name' => 'Upload',
        'src' => 'resources/admin/Bits/Components/Icons/Upload.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Wallet.vue' => [
        'file' => 'chunks/Wallet.js',
        'name' => 'Wallet',
        'src' => 'resources/admin/Bits/Components/Icons/Wallet.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Warning.vue' => [
        'file' => 'chunks/Warning.js',
        'name' => 'Warning',
        'src' => 'resources/admin/Bits/Components/Icons/Warning.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/WarningFill.vue' => [
        'file' => 'chunks/WarningFill.js',
        'name' => 'WarningFill',
        'src' => 'resources/admin/Bits/Components/Icons/WarningFill.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Bits/Components/Icons/Webhooks.vue' => [
        'file' => 'chunks/Webhooks.js',
        'name' => 'Webhooks',
        'src' => 'resources/admin/Bits/Components/Icons/Webhooks.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/BlockEditor/Buttons/AddToCartButtonBlockEditor.jsx' => [
        'file' => 'AddToCartButtonBlockEditor.js',
        'name' => 'AddToCartButtonBlockEditor',
        'src' => 'resources/admin/BlockEditor/Buttons/AddToCartButtonBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index4.js',
            '_InspectorSettings.js',
            '_SingleProductContext.js',
            '_Icons.js',
            '_add-query-args.js',
            '_SelectVariationModal.js',
            '_ProductListItem.js',
            '_index5.js'
        ]
    ],
    'resources/admin/BlockEditor/Buttons/BuyNowButtonBlockEditor.jsx' => [
        'file' => 'BuyNowButtonBlockEditor.js',
        'name' => 'BuyNowButtonBlockEditor',
        'src' => 'resources/admin/BlockEditor/Buttons/BuyNowButtonBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index4.js',
            '_InspectorSettings.js',
            '_Icons.js',
            '_add-query-args.js',
            '_SelectVariationModal.js',
            '_ProductListItem.js',
            '_index5.js'
        ]
    ],
    'resources/admin/BlockEditor/Buttons/style/button-block-editor.scss' => [
        'file' => 'assets/button-block-editor.css',
        'src' => 'resources/admin/BlockEditor/Buttons/style/button-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/BuySection/BuySection.png' => [
        'file' => 'assets/BuySection.png',
        'src' => 'resources/admin/BlockEditor/BuySection/BuySection.png'
    ],
    'resources/admin/BlockEditor/BuySection/BuySectionBlockEditor.jsx' => [
        'file' => 'BuySectionBlockEditor.js',
        'name' => 'BuySectionBlockEditor',
        'src' => 'resources/admin/BlockEditor/BuySection/BuySectionBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index4.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js',
            '_CustomSelect.js',
            '_SelectProductModal.js',
            '_SingleProductContext2.js',
            '_Icons.js',
            '_add-query-args.js',
            '_index5.js',
            '_ProductListItem.js'
        ],
        'assets' => [
            'assets/BuySection.png'
        ]
    ],
    'resources/admin/BlockEditor/BuySection/style/buy-section-block-editor.scss' => [
        'file' => 'assets/buy-section-block-editor.css',
        'src' => 'resources/admin/BlockEditor/BuySection/style/buy-section-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/Checkout/Checkout.png' => [
        'file' => 'assets/Checkout.png',
        'src' => 'resources/admin/BlockEditor/Checkout/Checkout.png'
    ],
    'resources/admin/BlockEditor/Checkout/CheckoutBlockEditor.jsx' => [
        'file' => 'CheckoutBlockEditor.js',
        'name' => 'CheckoutBlockEditor',
        'src' => 'resources/admin/BlockEditor/Checkout/CheckoutBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_EditorPanel.js',
            '_ColorPickerField.js',
            '_Icons.js'
        ],
        'assets' => [
            'assets/Checkout.png'
        ]
    ],
    'resources/admin/BlockEditor/Checkout/InnerBlocks/InnerBlocks.jsx' => [
        'file' => 'InnerBlocks2.js',
        'name' => 'InnerBlocks',
        'src' => 'resources/admin/BlockEditor/Checkout/InnerBlocks/InnerBlocks.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js'
        ]
    ],
    'resources/admin/BlockEditor/Checkout/style/checkout-block-editor.scss' => [
        'file' => 'assets/checkout-block-editor.css',
        'src' => 'resources/admin/BlockEditor/Checkout/style/checkout-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/Components/style/fct-global-block-editor.scss' => [
        'file' => 'assets/fct-global-block-editor.css',
        'src' => 'resources/admin/BlockEditor/Components/style/fct-global-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/CustomerProfile/CustomerProfile.png' => [
        'file' => 'assets/CustomerProfile.png',
        'src' => 'resources/admin/BlockEditor/CustomerProfile/CustomerProfile.png'
    ],
    'resources/admin/BlockEditor/CustomerProfile/CustomerProfileBlockEditor.jsx' => [
        'file' => 'CustomerProfileBlockEditor.js',
        'name' => 'CustomerProfileBlockEditor',
        'src' => 'resources/admin/BlockEditor/CustomerProfile/CustomerProfileBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js'
        ],
        'assets' => [
            'assets/CustomerProfile.png'
        ]
    ],
    'resources/admin/BlockEditor/CustomerProfile/style/customer-profile-block-editor.scss' => [
        'file' => 'assets/customer-profile-block-editor.css',
        'src' => 'resources/admin/BlockEditor/CustomerProfile/style/customer-profile-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/Excerpt/ExcerptBlockEditor.jsx' => [
        'file' => 'ExcerptBlockEditor.js',
        'name' => 'ExcerptBlockEditor',
        'src' => 'resources/admin/BlockEditor/Excerpt/ExcerptBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index4.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js',
            '_CustomSelect.js',
            '_SelectProductModal.js',
            '_SingleProductContext2.js',
            '_Icons.js',
            '_add-query-args.js',
            '_index5.js',
            '_ProductListItem.js'
        ]
    ],
    'resources/admin/BlockEditor/Excerpt/style/excerpt-block-editor.scss' => [
        'file' => 'assets/excerpt-block-editor.css',
        'src' => 'resources/admin/BlockEditor/Excerpt/style/excerpt-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/PriceRange/PriceRangeBlockEditor.jsx' => [
        'file' => 'PriceRangeBlockEditor.js',
        'name' => 'PriceRangeBlockEditor',
        'src' => 'resources/admin/BlockEditor/PriceRange/PriceRangeBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index4.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js',
            '_CustomSelect.js',
            '_SelectProductModal.js',
            '_SingleProductContext2.js',
            '_Icons.js',
            '_add-query-args.js',
            '_index5.js',
            '_ProductListItem.js'
        ]
    ],
    'resources/admin/BlockEditor/PriceRange/style/price-range-block-editor.scss' => [
        'file' => 'assets/price-range-block-editor.css',
        'src' => 'resources/admin/BlockEditor/PriceRange/style/price-range-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/PricingTable/PricingTable.png' => [
        'file' => 'assets/PricingTable.png',
        'src' => 'resources/admin/BlockEditor/PricingTable/PricingTable.png'
    ],
    'resources/admin/BlockEditor/PricingTable/PricingTableBlockEditor.jsx' => [
        'file' => 'PricingTableBlockEditor.js',
        'name' => 'PricingTableBlockEditor',
        'src' => 'resources/admin/BlockEditor/PricingTable/PricingTableBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index5.js',
            '_Icons.js',
            '_ProductListItem.js',
            '_SelectVariationModal.js',
            '_index4.js',
            '_ColorPickerField.js',
            '_EditorPanel.js',
            '_CustomSelect.js',
            '_add-query-args.js'
        ],
        'assets' => [
            'assets/PricingTable.png'
        ]
    ],
    'resources/admin/BlockEditor/PricingTable/style/pricing-table-block-editor.scss' => [
        'file' => 'assets/pricing-table-block-editor.css',
        'src' => 'resources/admin/BlockEditor/PricingTable/style/pricing-table-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/ProductCard/ProductCard.png' => [
        'file' => 'assets/ProductCard.png',
        'src' => 'resources/admin/BlockEditor/ProductCard/ProductCard.png'
    ],
    'resources/admin/BlockEditor/ProductCard/ProductCardBlockEditor.jsx' => [
        'file' => 'ProductCardBlockEditor.js',
        'name' => 'ProductCardBlockEditor',
        'src' => 'resources/admin/BlockEditor/ProductCard/ProductCardBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js',
            '_index4.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js',
            '_CustomSelect.js',
            '_SelectProductModal.js',
            '_ErrorBoundary.js',
            '_add-query-args.js',
            '_index5.js',
            '_ProductListItem.js'
        ],
        'assets' => [
            'assets/ProductCard.png'
        ]
    ],
    'resources/admin/BlockEditor/ProductCard/style/product-card-block-editor.scss' => [
        'file' => 'assets/product-card-block-editor.css',
        'src' => 'resources/admin/BlockEditor/ProductCard/style/product-card-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/ProductGallery/ProductGallery.png' => [
        'file' => 'assets/ProductGallery.png',
        'src' => 'resources/admin/BlockEditor/ProductGallery/ProductGallery.png'
    ],
    'resources/admin/BlockEditor/ProductGallery/ProductGalleryBlockEditor.jsx' => [
        'file' => 'ProductGalleryBlockEditor.js',
        'name' => 'ProductGalleryBlockEditor',
        'src' => 'resources/admin/BlockEditor/ProductGallery/ProductGalleryBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js',
            '_SelectVariationModal.js',
            '_index4.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js',
            '_CustomSelect.js',
            '_SelectProductModal.js',
            '_SingleProductContext2.js',
            '_add-query-args.js',
            '_ProductListItem.js',
            '_index5.js'
        ],
        'assets' => [
            'assets/ProductGallery.png'
        ]
    ],
    'resources/admin/BlockEditor/ProductGallery/style/product-gallery-block-editor.scss' => [
        'file' => 'assets/product-gallery-block-editor.css',
        'src' => 'resources/admin/BlockEditor/ProductGallery/style/product-gallery-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/ProductInfo/ProductInfo.png' => [
        'file' => 'assets/ProductInfo.png',
        'src' => 'resources/admin/BlockEditor/ProductInfo/ProductInfo.png'
    ],
    'resources/admin/BlockEditor/ProductInfo/ProductInfoBlockEditor.jsx' => [
        'file' => 'ProductInfoBlockEditor.js',
        'name' => 'ProductInfoBlockEditor',
        'src' => 'resources/admin/BlockEditor/ProductInfo/ProductInfoBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js',
            '_index4.js',
            '_SelectProductModal.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js',
            '_CustomSelect.js',
            '_SingleProductContext2.js',
            '_add-query-args.js',
            '_ProductListItem.js',
            '_index5.js'
        ],
        'assets' => [
            'assets/ProductInfo.png'
        ]
    ],
    'resources/admin/BlockEditor/ProductInfo/style/product-info-block-editor.scss' => [
        'file' => 'assets/product-info-block-editor.css',
        'src' => 'resources/admin/BlockEditor/ProductInfo/style/product-info-block-editor.scss',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/ReactSupport.js' => [
        'file' => 'ReactSupport.js',
        'name' => 'ReactSupport',
        'src' => 'resources/admin/BlockEditor/ReactSupport.js',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/SearchBar/ProductSearch.png' => [
        'file' => 'assets/ProductCard.png',
        'src' => 'resources/admin/BlockEditor/ProductCard/ProductCard.png'
    ],
    'resources/admin/BlockEditor/SearchBar/SearchBarBlockEditor.jsx' => [
        'file' => 'SearchBarBlockEditor.js',
        'name' => 'SearchBarBlockEditor',
        'src' => 'resources/admin/BlockEditor/SearchBar/SearchBarBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_index5.js',
            '_Icons.js'
        ],
        'assets' => [
            'assets/ProductCard.png'
        ]
    ],
    'resources/admin/BlockEditor/ShopApp/InnerBlocks/InnerBlocks.jsx' => [
        'file' => 'InnerBlocks.js',
        'name' => 'InnerBlocks',
        'src' => 'resources/admin/BlockEditor/ShopApp/InnerBlocks/InnerBlocks.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_SingleProductContext.js',
            '_ProductContext.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js'
        ]
    ],
    'resources/admin/BlockEditor/ShopApp/Products.png' => [
        'file' => 'assets/Products.png',
        'src' => 'resources/admin/BlockEditor/ShopApp/Products.png'
    ],
    'resources/admin/BlockEditor/ShopApp/ShopAppBlockEditor.jsx' => [
        'file' => 'ShopAppBlockEditor.js',
        'name' => 'ShopAppBlockEditor',
        'src' => 'resources/admin/BlockEditor/ShopApp/ShopAppBlockEditor.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_ColorPickerField.js',
            '_CustomSelect.js',
            '_Icons.js',
            '_ErrorBoundary.js',
            '_ProductContext.js',
            '_index4.js',
            '_index5.js'
        ],
        'assets' => [
            'assets/Products.png'
        ]
    ],
    'resources/admin/BlockEditor/ShopApp/style/shop-app-block-editor.css' => [
        'file' => 'assets/shop-app-block-editor.css',
        'src' => 'resources/admin/BlockEditor/ShopApp/style/shop-app-block-editor.css',
        'isEntry' => true
    ],
    'resources/admin/BlockEditor/Stock/StockBlock.jsx' => [
        'file' => 'StockBlock.js',
        'name' => 'StockBlock',
        'src' => 'resources/admin/BlockEditor/Stock/StockBlock.jsx',
        'isEntry' => true,
        'imports' => [
            '_BlockEditorTranslator.js',
            '_Icons.js',
            '_SelectProductModal.js',
            '_EditorPanel.js',
            '_EditorPanelRow.js',
            '_CustomSelect.js',
            '_index4.js',
            '_SingleProductContext2.js',
            '_add-query-args.js',
            '_ProductListItem.js',
            '_index5.js'
        ]
    ],
    'resources/admin/Modules/Reports/Subscription/Cohort.vue' => [
        'file' => 'chunks/Cohort.js',
        'name' => 'Cohort',
        'src' => 'resources/admin/Modules/Reports/Subscription/Cohort.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_OrderCustomerInformation.js',
            '_DynamicIcon.js',
            '_RouteCell.js',
            '__plugin-vue_export-helper.js',
            '_Empty.js',
            '_Translator.js',
            '_Rest.js',
            '_productService.js',
            'resources/admin/bootstrap/app.js',
            '_common.js',
            '_Badge.js',
            '_Str.js',
            '_Arr.js',
            '_CopyToClipboard.js',
            '_Notify.js',
            '_index.js',
            '_Asset.js',
            '_Url.js',
            '_NotFound.js',
            '_Model.js',
            '_dayjs.min.js',
            '_timezone.js',
            '_Utils.js',
            '_CancelSubscription.js',
            '_BundleProducts.js',
            '_dateShortCuts.js',
            '_index3.js',
            '_index2.js',
            '_ProductVariationSelector.js',
            '_countries.js',
            '_defaults.js',
            'resources/admin/Bits/Components/Icons/Screenshot.vue',
            '_useElementPlusComponents.js'
        ],
        'css' => [
            'assets/Cohort.css'
        ]
    ],
    'resources/admin/Modules/Shipping/shipping.js' => [
        'file' => 'shipping.js',
        'name' => 'shipping',
        'src' => 'resources/admin/Modules/Shipping/shipping.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_RouteCell.js',
            '_Translator.js',
            '_Arr.js',
            '_Rest.js',
            '_Badge.js',
            '_DynamicIcon.js',
            '_Notify.js',
            '__plugin-vue_export-helper.js',
            '_Str.js',
            '_index.js',
            '_NotFound.js',
            '_countries.js',
            '_Model.js',
            '_Url.js',
            '_dayjs.min.js',
            '_timezone.js',
            '_Utils.js',
            '_Asset.js'
        ],
        'css' => [
            'assets/shipping.css'
        ]
    ],
    'resources/admin/Modules/Subscriptions/subscription.js' => [
        'file' => 'subscription.js',
        'name' => 'subscription',
        'src' => 'resources/admin/Modules/Subscriptions/subscription.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_RouteCell.js',
            '_Translator.js',
            '_Arr.js',
            '_CancelSubscription.js',
            '__plugin-vue_export-helper.js',
            '_index.js',
            '_CopyToClipboard.js',
            '_Empty.js',
            '_OrderCustomerInformation.js',
            '_Str.js',
            '_DynamicIcon.js',
            '_NotFound.js',
            '_Badge.js',
            '_common.js',
            '_Notify.js',
            '_Model.js',
            '_Rest.js',
            '_Url.js',
            '_dayjs.min.js',
            '_timezone.js',
            '_Utils.js',
            '_productService.js',
            '_BundleProducts.js',
            '_Asset.js'
        ]
    ],
    'resources/admin/admin_hooks.js' => [
        'file' => 'admin_hooks.js',
        'name' => 'admin_hooks',
        'src' => 'resources/admin/admin_hooks.js',
        'isEntry' => true
    ],
    'resources/admin/bootstrap/app.js' => [
        'file' => 'app.js',
        'name' => 'app',
        'src' => 'resources/admin/bootstrap/app.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_Str.js',
            '_DynamicIcon.js',
            '_RouteCell.js',
            '_Translator.js',
            '_Arr.js',
            '_OrderCustomerInformation.js',
            '_productService.js',
            '_CancelSubscription.js',
            '_Empty.js',
            '__plugin-vue_export-helper.js',
            '_Badge.js',
            '_common.js',
            '_Model.js',
            '_Rest.js',
            '_dayjs.min.js',
            '_index.js',
            '_dateShortCuts.js',
            '_Notify.js',
            '_NotFound.js',
            '_Asset.js',
            '_index2.js',
            '_Url.js',
            '_timezone.js',
            '_Utils.js',
            '_CopyToClipboard.js',
            '_ProductVariationSelector.js',
            '_BundleProducts.js',
            '_countries.js',
            '_defaults.js',
            'resources/admin/Bits/Components/Icons/Screenshot.vue',
            '_useElementPlusComponents.js',
            '_index3.js'
        ],
        'dynamicImports' => [
            'resources/admin/Bits/Components/Form/Components/Affix/Test.vue',
            'resources/admin/Bits/Components/Form/Components/Coupon/BuyProducts.vue',
            'resources/admin/Bits/Components/Form/Components/Coupon/Categories.vue',
            'resources/admin/Bits/Components/Form/Components/Coupon/Code.vue',
            'resources/admin/Bits/Components/Form/Components/Coupon/GetProducts.vue',
            'resources/admin/Bits/Components/Form/Components/Coupon/IncludeExclude/Categories.vue',
            'resources/admin/Bits/Components/Form/Components/Coupon/IncludeExclude/Product.vue',
            'resources/admin/Bits/Components/Form/Components/Coupon/Products.vue',
            'resources/admin/Bits/Components/Form/Components/ModuleSettings.vue',
            'resources/admin/Bits/Components/Form/Components/PaymentView.vue',
            'resources/admin/Bits/Components/Form/Components/StoreSettings/AddressComponent.vue',
            'resources/admin/Bits/Components/Form/Components/StoreSettings/CreatePageButton.vue',
            'resources/admin/Bits/Components/Form/Components/StoreSettings/PageSelector.vue',
            'resources/admin/Bits/Components/Form/Components/TurnstileSettings.vue',
            'resources/admin/Modules/Reports/Subscription/Cohort.vue'
        ],
        'css' => [
            'assets/app.css'
        ]
    ],
    'resources/admin/elementor/AddToCart/Start.js' => [
        'file' => 'Start3.js',
        'name' => 'Start',
        'src' => 'resources/admin/elementor/AddToCart/Start.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_useElementPlusComponents.js',
            '_Translator.js',
            '_AddProductItemModal.js',
            '__plugin-vue_export-helper.js',
            '_index.js',
            '_index2.js',
            '_index3.js',
            '_dayjs.min.js',
            '_Arr.js',
            '_DynamicIcon.js',
            'resources/admin/Bits/Components/Icons/CaretRight.vue',
            '_Str.js',
            '_productService.js',
            '_Model.js',
            '_Rest.js',
            '_Asset.js',
            '_Url.js'
        ],
        'css' => [
            'assets/Start3.css'
        ]
    ],
    'resources/admin/elementor/CustomerProfile/Start.js' => [
        'file' => 'Start7.js',
        'name' => 'Start',
        'src' => 'resources/admin/elementor/CustomerProfile/Start.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_useElementPlusComponents.js',
            '_useElementor.js',
            '_ServerSidePreview.js',
            '_Translator.js',
            '_index.js',
            '_index2.js',
            '_index3.js',
            '_dayjs.min.js',
            '_Arr.js',
            '__plugin-vue_export-helper.js',
            '_add-query-args.js'
        ],
        'css' => [
            'assets/ServerSidePreview.css'
        ]
    ],
    'resources/admin/elementor/DirectCheckout/Start.js' => [
        'file' => 'Start2.js',
        'name' => 'Start',
        'src' => 'resources/admin/elementor/DirectCheckout/Start.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_useElementPlusComponents.js',
            '_AddProductItemModal.js',
            '_Translator.js',
            '_useElementor.js',
            '__plugin-vue_export-helper.js',
            '_index.js',
            '_index2.js',
            '_index3.js',
            '_dayjs.min.js',
            '_DynamicIcon.js',
            'resources/admin/Bits/Components/Icons/CaretRight.vue',
            '_Str.js',
            '_productService.js',
            '_Arr.js',
            '_Model.js',
            '_Rest.js',
            '_Asset.js',
            '_Url.js'
        ],
        'css' => [
            'assets/Start2.css',
            'assets/ServerSidePreview.css'
        ]
    ],
    'resources/admin/elementor/PricingTable/Start.js' => [
        'file' => 'Start4.js',
        'name' => 'Start',
        'src' => 'resources/admin/elementor/PricingTable/Start.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_useElementPlusComponents.js',
            '_AddProductItemModal.js',
            '_Translator.js',
            '_useElementor.js',
            '_Rest.js',
            '_Arr.js',
            '_ServerSidePreview.js',
            '_Notify.js',
            'resources/admin/Bits/Components/Icons/CaretRight.vue',
            'resources/admin/Bits/Components/Icons/Delete.vue',
            '_index.js',
            '_index2.js',
            '_index3.js',
            '_dayjs.min.js',
            '_DynamicIcon.js',
            '_Str.js',
            '_productService.js',
            '_Model.js',
            '_Asset.js',
            '_Url.js',
            '__plugin-vue_export-helper.js',
            '_add-query-args.js'
        ],
        'css' => [
            'assets/ServerSidePreview.css'
        ]
    ],
    'resources/admin/elementor/ProductDetailsButton/Start.js' => [
        'file' => 'Start6.js',
        'name' => 'Start',
        'src' => 'resources/admin/elementor/ProductDetailsButton/Start.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_useElementPlusComponents.js',
            '_Translator.js',
            '_useElementor.js',
            '_Rest.js',
            '_index.js',
            '_index2.js',
            '_index3.js',
            '_dayjs.min.js',
            '_Arr.js'
        ]
    ],
    'resources/admin/elementor/ProductSearchBar/Start.js' => [
        'file' => 'Start5.js',
        'name' => 'Start',
        'src' => 'resources/admin/elementor/ProductSearchBar/Start.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_useElementPlusComponents.js',
            'resources/admin/Bits/Components/Icons/Search.vue',
            '_Translator.js',
            '_index.js',
            '_index2.js',
            '_index3.js',
            '_dayjs.min.js',
            '__plugin-vue_export-helper.js',
            '_Arr.js'
        ]
    ],
    'resources/admin/elementor/ShopAppWidget.js' => [
        'file' => 'ShopAppWidget.js',
        'name' => 'ShopAppWidget',
        'src' => 'resources/admin/elementor/ShopAppWidget.js',
        'isEntry' => true,
        'imports' => [
            '_Arr.js'
        ]
    ],
    'resources/admin/global.js' => [
        'file' => 'global.js',
        'name' => 'global',
        'src' => 'resources/admin/global.js',
        'isEntry' => true
    ],
    'resources/admin/utils/edit-wp-user-global.js' => [
        'file' => 'edit-wp-user-global.js',
        'name' => 'edit-wp-user-global',
        'src' => 'resources/admin/utils/edit-wp-user-global.js',
        'isEntry' => true
    ],
    'resources/images/pro-feature-pattern.png' => [
        'file' => 'assets/pro-feature-pattern.png',
        'src' => 'resources/images/pro-feature-pattern.png'
    ],
    'resources/licensing/license.js' => [
        'file' => 'license.js',
        'name' => 'license',
        'src' => 'resources/licensing/license.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_RouteCell.js',
            '_NotFound.js',
            '_dateShortCuts.js',
            '_Empty.js',
            '_DynamicIcon.js',
            '_Translator.js',
            '_Arr.js',
            '_Badge.js',
            '_index.js',
            '_OrderCustomerInformation.js',
            '__plugin-vue_export-helper.js',
            '_dayjs.min.js',
            '_Model.js',
            '_Rest.js',
            '_common.js',
            '_CopyToClipboard.js',
            '_Str.js',
            '_Url.js',
            '_timezone.js',
            '_Notify.js',
            '_Utils.js',
            '_Asset.js',
            '_index3.js',
            '_productService.js'
        ],
        'css' => [
            'assets/license.css'
        ]
    ],
    'resources/order-bump/order-bump.js' => [
        'file' => 'order-bump.js',
        'name' => 'order-bump',
        'src' => 'resources/order-bump/order-bump.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_Translator.js',
            '_RouteCell.js',
            '_Empty.js',
            '_Badge.js',
            '_DynamicIcon.js',
            '_common.js',
            '_Notify.js',
            '_Rest.js',
            '_ProductVariationSelector.js',
            '_Str.js',
            '_index.js',
            '__plugin-vue_export-helper.js',
            '_Arr.js',
            '_Model.js',
            '_Url.js',
            '_dayjs.min.js',
            '_timezone.js',
            '_Utils.js'
        ]
    ],
    'resources/public/buttons/add-to-cart/style/style.scss' => [
        'file' => 'assets/style3.css',
        'src' => 'resources/public/buttons/add-to-cart/style/style.scss',
        'isEntry' => true
    ],
    'resources/public/buttons/direct-checkout/style/style.scss' => [
        'file' => 'assets/style2.css',
        'src' => 'resources/public/buttons/direct-checkout/style/style.scss',
        'isEntry' => true
    ],
    'resources/public/buttons/product-details/style/style.scss' => [
        'file' => 'assets/style4.css',
        'src' => 'resources/public/buttons/product-details/style/style.scss',
        'isEntry' => true
    ],
    'resources/public/cart-drawer/cart-drawer.scss' => [
        'file' => 'assets/cart-drawer.css',
        'src' => 'resources/public/cart-drawer/cart-drawer.scss',
        'isEntry' => true
    ],
    'resources/public/checkout/FluentCartCheckout.js' => [
        'file' => 'FluentCartCheckout.js',
        'name' => 'FluentCartCheckout',
        'src' => 'resources/public/checkout/FluentCartCheckout.js',
        'isEntry' => true,
        'imports' => [
            '_payment-loader.js',
            '_Url.js',
            '_timezone.js',
            '_dayjs.min.js',
            '_Str.js',
            '_vue.esm-bundler.js',
            '_Arr.js'
        ]
    ],
    'resources/public/checkout/ModalCheckoutForm.js' => [
        'file' => 'ModalCheckoutForm.js',
        'name' => 'ModalCheckoutForm',
        'src' => 'resources/public/checkout/ModalCheckoutForm.js',
        'isEntry' => true
    ],
    'resources/public/checkout/ModalCheckoutHandler.js' => [
        'file' => 'ModalCheckoutHandler.js',
        'name' => 'ModalCheckoutHandler',
        'src' => 'resources/public/checkout/ModalCheckoutHandler.js',
        'isEntry' => true
    ],
    'resources/public/checkout/login.js' => [
        'file' => 'login.js',
        'name' => 'login',
        'src' => 'resources/public/checkout/login.js',
        'isEntry' => true
    ],
    'resources/public/checkout/registration.js' => [
        'file' => 'registration.js',
        'name' => 'registration',
        'src' => 'resources/public/checkout/registration.js',
        'isEntry' => true
    ],
    'resources/public/checkout/style/checkout-iframe.scss' => [
        'file' => 'assets/checkout-iframe.css',
        'src' => 'resources/public/checkout/style/checkout-iframe.scss',
        'isEntry' => true
    ],
    'resources/public/checkout/style/checkout.scss' => [
        'file' => 'assets/checkout.css',
        'src' => 'resources/public/checkout/style/checkout.scss',
        'isEntry' => true
    ],
    'resources/public/checkout/style/confirmation.scss' => [
        'file' => 'assets/confirmation.css',
        'src' => 'resources/public/checkout/style/confirmation.scss',
        'isEntry' => true
    ],
    'resources/public/checkout/style/login.scss' => [
        'file' => 'assets/login.css',
        'src' => 'resources/public/checkout/style/login.scss',
        'isEntry' => true
    ],
    'resources/public/checkout/style/modal-checkout.scss' => [
        'file' => 'assets/modal-checkout.css',
        'src' => 'resources/public/checkout/style/modal-checkout.scss',
        'isEntry' => true
    ],
    'resources/public/checkout/style/registration.scss' => [
        'file' => 'assets/registration.css',
        'src' => 'resources/public/checkout/style/registration.scss',
        'isEntry' => true
    ],
    'resources/public/components/select/style/style.scss' => [
        'file' => 'assets/style6.css',
        'src' => 'resources/public/components/select/style/style.scss',
        'isEntry' => true
    ],
    'resources/public/customer-profile/Start.js' => [
        'file' => 'Start.js',
        'name' => 'Start',
        'src' => 'resources/public/customer-profile/Start.js',
        'isEntry' => true,
        'imports' => [
            '_vue.esm-bundler.js',
            '_dayjs.min.js',
            '_timezone.js',
            '_DynamicIcon.js',
            '_Badge.js',
            '_Str.js',
            '_BundleProducts.js',
            '__plugin-vue_export-helper.js',
            '_common.js',
            '_CopyToClipboard.js',
            '_index.js',
            'resources/admin/Bits/Components/Icons/ArrowDown.vue',
            'resources/admin/Bits/Components/Icons/ArrowUp.vue',
            '_Rest.js',
            '_Notify.js',
            '_index2.js',
            '_defaults.js',
            '_Arr.js',
            '_Translator.js',
            '_index3.js'
        ],
        'css' => [
            'assets/Start.css'
        ]
    ],
    'resources/public/customer-profile/style/customer-profile-global.scss' => [
        'file' => 'assets/customer-profile-global.css',
        'src' => 'resources/public/customer-profile/style/customer-profile-global.scss',
        'isEntry' => true
    ],
    'resources/public/customer-profile/style/customer-profile.scss' => [
        'file' => 'assets/customer-profile.css',
        'src' => 'resources/public/customer-profile/style/customer-profile.scss',
        'isEntry' => true
    ],
    'resources/public/fonts/Inter-Bold.woff' => [
        'file' => 'assets/Inter-Bold.woff',
        'src' => 'resources/public/fonts/Inter-Bold.woff'
    ],
    'resources/public/fonts/Inter-Medium.woff' => [
        'file' => 'assets/Inter-Medium.woff',
        'src' => 'resources/public/fonts/Inter-Medium.woff'
    ],
    'resources/public/fonts/Inter-Regular.woff' => [
        'file' => 'assets/Inter-Regular.woff',
        'src' => 'resources/public/fonts/Inter-Regular.woff'
    ],
    'resources/public/fonts/Inter-SemiBold.woff' => [
        'file' => 'assets/Inter-SemiBold.woff',
        'src' => 'resources/public/fonts/Inter-SemiBold.woff'
    ],
    'resources/public/globals/FluentCartApp.js' => [
        'file' => 'FluentCartApp.js',
        'name' => 'FluentCartApp',
        'src' => 'resources/public/globals/FluentCartApp.js',
        'isEntry' => true,
        'imports' => [
            'resources/public/checkout/ModalCheckoutHandler.js'
        ]
    ],
    'resources/public/gutenberg/gutenberg.js' => [
        'file' => 'gutenberg.js',
        'name' => 'gutenberg',
        'src' => 'resources/public/gutenberg/gutenberg.js',
        'isEntry' => true
    ],
    'resources/public/orderbump/orderbump.js' => [
        'file' => 'orderbump.js',
        'name' => 'orderbump',
        'src' => 'resources/public/orderbump/orderbump.js',
        'isEntry' => true,
        'imports' => [
            '_Url.js',
            '_Str.js',
            '_vue.esm-bundler.js',
            '_Arr.js'
        ]
    ],
    'resources/public/payment-methods/cod-checkout.js' => [
        'file' => 'cod-checkout.js',
        'name' => 'cod-checkout',
        'src' => 'resources/public/payment-methods/cod-checkout.js',
        'isEntry' => true
    ],
    'resources/public/payment-methods/paypal-checkout.js' => [
        'file' => 'paypal-checkout.js',
        'name' => 'paypal-checkout',
        'src' => 'resources/public/payment-methods/paypal-checkout.js',
        'isEntry' => true
    ],
    'resources/public/payment-methods/stripe-checkout.js' => [
        'file' => 'stripe-checkout.js',
        'name' => 'stripe-checkout',
        'src' => 'resources/public/payment-methods/stripe-checkout.js',
        'isEntry' => true
    ],
    'resources/public/payment-methods/stripe-hosted-checkout.js' => [
        'file' => 'stripe-hosted-checkout.js',
        'name' => 'stripe-hosted-checkout',
        'src' => 'resources/public/payment-methods/stripe-hosted-checkout.js',
        'isEntry' => true
    ],
    'resources/public/payments/custom-payment-page.js' => [
        'file' => 'custom-payment-page.js',
        'name' => 'custom-payment-page',
        'src' => 'resources/public/payments/custom-payment-page.js',
        'isEntry' => true,
        'imports' => [
            '_payment-loader.js'
        ]
    ],
    'resources/public/payments/custom-payment-page.scss' => [
        'file' => 'assets/custom-payment-page.css',
        'src' => 'resources/public/payments/custom-payment-page.scss',
        'isEntry' => true
    ],
    'resources/public/pricing-table/PricingTable.js' => [
        'file' => 'PricingTable.js',
        'name' => 'PricingTable',
        'src' => 'resources/public/pricing-table/PricingTable.js',
        'isEntry' => true,
        'imports' => [
            'resources/public/pricing-table/tab/Tab.js'
        ]
    ],
    'resources/public/pricing-table/pricing-table.scss' => [
        'file' => 'assets/pricing-table.css',
        'src' => 'resources/public/pricing-table/pricing-table.scss',
        'isEntry' => true
    ],
    'resources/public/pricing-table/tab/Tab.js' => [
        'file' => 'Tab.js',
        'name' => 'Tab',
        'src' => 'resources/public/pricing-table/tab/Tab.js',
        'isEntry' => true
    ],
    'resources/public/print/Print.js' => [
        'file' => 'Print.js',
        'name' => 'Print',
        'src' => 'resources/public/print/Print.js',
        'isEntry' => true
    ],
    'resources/public/product-card/product-card.js' => [
        'file' => 'product-card2.js',
        'name' => 'product-card',
        'src' => 'resources/public/product-card/product-card.js',
        'isEntry' => true
    ],
    'resources/public/product-card/style/product-card.scss' => [
        'file' => 'assets/product-card.css',
        'src' => 'resources/public/product-card/style/product-card.scss',
        'isEntry' => true
    ],
    'resources/public/product-page/ShopApp.js' => [
        'file' => 'ShopApp.js',
        'name' => 'ShopApp',
        'src' => 'resources/public/product-page/ShopApp.js',
        'isEntry' => true
    ],
    'resources/public/product-page/style/shop-app.scss' => [
        'file' => 'assets/shop-app.css',
        'src' => 'resources/public/product-page/style/shop-app.scss',
        'isEntry' => true
    ],
    'resources/public/receipt/style/thank_you.scss' => [
        'file' => 'assets/thank_you.css',
        'src' => 'resources/public/receipt/style/thank_you.scss',
        'isEntry' => true
    ],
    'resources/public/search-bar-app/SearchBarApp.js' => [
        'file' => 'SearchBarApp.js',
        'name' => 'SearchBarApp',
        'src' => 'resources/public/search-bar-app/SearchBarApp.js',
        'isEntry' => true,
        'imports' => [
            '_Utils.js',
            '_Translator.js',
            '_vue.esm-bundler.js',
            '_Arr.js',
            '_dayjs.min.js',
            '_timezone.js'
        ]
    ],
    'resources/public/search-bar-app/style/style.scss' => [
        'file' => 'assets/style5.css',
        'src' => 'resources/public/search-bar-app/style/style.scss',
        'isEntry' => true
    ],
    'resources/public/single-product/SingleProduct.js' => [
        'file' => 'SingleProduct.js',
        'name' => 'SingleProduct',
        'src' => 'resources/public/single-product/SingleProduct.js',
        'isEntry' => true
    ],
    'resources/public/single-product/similar-product.scss' => [
        'file' => 'assets/similar-product.css',
        'src' => 'resources/public/single-product/similar-product.scss',
        'isEntry' => true
    ],
    'resources/public/single-product/single-product.scss' => [
        'file' => 'assets/single-product.css',
        'src' => 'resources/public/single-product/single-product.scss',
        'isEntry' => true
    ],
    'resources/public/single-product/xzoom/xzoom.css' => [
        'file' => 'assets/xzoom.css',
        'src' => 'resources/public/single-product/xzoom/xzoom.css',
        'isEntry' => true
    ],
    'resources/public/single-product/xzoom/xzoom.js' => [
        'file' => 'xzoom.js',
        'name' => 'xzoom',
        'src' => 'resources/public/single-product/xzoom/xzoom.js',
        'isEntry' => true
    ],
    'resources/styles/tailwind/style.css' => [
        'file' => 'assets/style.css',
        'src' => 'resources/styles/tailwind/style.css',
        'isEntry' => true
    ],
    'resources/styles/tailwind/taxonomy.scss' => [
        'file' => 'assets/taxonomy.css',
        'src' => 'resources/styles/tailwind/taxonomy.scss',
        'isEntry' => true
    ]
];