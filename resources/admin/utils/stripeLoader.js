export const loadStripeScript = () => {
    return new Promise((resolve, reject) => {
        if (window.Stripe) {
            // Stripe is already loaded
            resolve(window.Stripe);
        } else {
            // Stripe is not loaded, load the script dynamically
            const script = document.createElement('script');
            script.src = 'https://js.stripe.com/v3/';
            script.async = true;
            document.head.appendChild(script);

            script.onload = () => {
                resolve(window.Stripe);
            };

            script.onerror = () => {
                reject(new Error('Failed to load Stripe script'));
            };
        }
    });
};
