export const loadSubscriptionPaypalScript = () => {
    return new Promise((resolve, reject) => {
        if (window.paypal) {
            resolve(window.paypal);
        } else {
            // PayPal is not loaded, load the script dynamically
            const script = document.createElement('script');
            script.src = `https://www.paypal.com/sdk/js?client-id=${window.fluentcart_customer_profile_vars?.paypal_client_id}&vault=true&intent=subscription`;
            script.async = true;
            script.setAttribute('data-partner-attribution-id', 'FLUENTCART_SP_PPCP');
            document.head.appendChild(script);

            script.onload = () => {
                resolve(window.paypal);
            };

            script.onerror = () => {
                reject(new Error('Failed to load PayPal script'));
            };
        }
    });
};

export const loadPaypalScript = () => {
    return new Promise((resolve, reject) => {
        if (window.paypal) {
            resolve(window.paypal);
        } else {    
            const script = document.createElement('script');
            script.src = `https://www.paypal.com/sdk/js?client-id=${window.fluentcart_customer_profile_vars?.paypal_client_id}' data-partner-attribution-id="FLUENTCART_SP_PPCP"'`;
            script.async = true;
            document.head.appendChild(script);

            script.onload = () => {
                resolve(window.paypal); 
            };

            script.onerror = () => {
                reject(new Error('Failed to load PayPal script'));
            };
        }
    }); 
};
