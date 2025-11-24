class p{constructor(e,t,o){var n;this.form=e,this.data=t,this.paymentArgs=t.payment_args,this.paymentLoader=o,this.$t=this.translate.bind(this),this.submitButton=(n=window.fluentcart_checkout_vars)==null?void 0:n.submit_button}translate(e){var o;return(((o=window.fct_mollie_data)==null?void 0:o.translations)||{})[e]||e}renderPaymentMethods(e){if(!e||!Array.isArray(e)||e.length===0)return'<div class="mollie-payment-info"><p>'+this.$t("Pay securely with Mollie")+"</p></div>";let t='<div class="mollie-payment-methods">';t+='<div class="mollie-payment-methods-grid">',e.forEach(n=>{n.image&&(t+=`<div class="mollie-payment-method">
                    <img src="${n.image}" alt="${n.name||"Payment method"}" title="${n.name||"Payment method"}" />
                </div>`)});let o=this.$t("Available payment methods on Checkout");return t+="</div>",t+='<p class="mollie-payment-description">'+o+"</p>",t+="</div>",t+=`<style>
            .mollie-payment-methods {
                padding: 16px;
                border: 1px solid #e1e5e9;
                border-radius: 8px;
                background: #fff;
                margin-bottom: 16px;
            }
            .mollie-payment-methods-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-bottom: 12px;
            }
            .mollie-payment-method {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 60px;
                height: 40px;
                border: 2px solid #e1e5e9;
                border-radius: 6px;
                background: #fff;
                overflow: hidden;
            }
            .mollie-payment-method img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
                display: block;
            }
            .mollie-payment-description {
                margin: 0;
                font-size: 14px;
                color: #656d76;
                text-align: center;
            }
        </style>`,t}async init(){var r,d,c,a;const e=this,t=(r=window.fluentcart_checkout_vars)==null?void 0:r.submit_button;let o=document.querySelector(".fluent-cart-checkout_embed_payment_container_mollie");if(o){let i=this.$t("Loading payment methods...");o.innerHTML='<div id="fct_loading_payment_processor">'+i+"</div>"}let n=((c=(d=this.data)==null?void 0:d.payment_args)==null?void 0:c.activat_methods)||[];o&&(o.innerHTML=this.renderPaymentMethods(n)),window.dispatchEvent(new CustomEvent("fluent_cart_payment_method_loading_success",{detail:{payment_method:"mollie"}})),(a=this.paymentLoader)==null||a.enableCheckoutButton((t==null?void 0:t.text)||e.$t("Place Order"))}}window.addEventListener("fluent_cart_load_payments_mollie",function(l){var r,d,c;window.dispatchEvent(new CustomEvent("fluent_cart_payment_method_loading",{detail:{payment_method:"mollie"}}));const e=(r=window.fluentcart_checkout_vars)==null?void 0:r.submit_button,t=document.querySelector(".fluent-cart-checkout_embed_payment_container_mollie"),o=((d=window.fct_mollie_data)==null?void 0:d.translations)||{};(c=l.detail.paymentLoader)==null||c.disableCheckoutButton((e==null?void 0:e.text)||"Place Order");function n(a){return o[a]||a}t&&(loadingMessage=n("Loading payment methods..."),t.innerHTML='<div id="fct_loading_payment_processor">'+loadingMessage+"</div>"),fetch(l.detail.paymentInfoUrl,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":l.detail.nonce},credentials:"include"}).then(async a=>{if(a=await a.json(),a.status=="failed"){let i=document.querySelector(".fluent-cart-checkout_embed_payment_container_mollie"),m=(a==null?void 0:a.message)||n("Something went wrong");i&&(i.innerHTML='<div id="fct_loading_payment_processor">'+n(m)+"</div>",i.style.display="block",i.querySelector("#fct_loading_payment_processor").style.color="#dc3545",i.querySelector("#fct_loading_payment_processor").style.fontSize="14px")}else new p(l.detail.form,a,l.detail.paymentLoader).init()}).catch(a=>{var s;let i=document.querySelector(".fluent-cart-checkout_embed_payment_container_mollie"),m=n("Something went wrong");i&&(i.innerHTML='<div id="fct_loading_payment_processor">'+n(m)+"</div>",i.style.display="block",i.querySelector("#fct_loading_payment_processor").style.color="#dc3545",i.querySelector("#fct_loading_payment_processor").style.fontSize="14px",(s=l.detail.paymentLoader)==null||s.enableCheckoutButton((e==null?void 0:e.text)||n("Place Order")))})});
