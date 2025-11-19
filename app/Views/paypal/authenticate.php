<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FluentCart PayPal Authentication</title>
</head>
<body>
<script>
    window.fluentCartRestVars = <?php echo json_encode($rest); ?>;
    function onboardedCallback(authCode, sharedId) {
        if (!authCode) {
            alert('Error: Invalid or missing authorization code (authCode). Please try connecting again.');
            return;
        }

        if (!sharedId) {
            alert('Error: Invalid or missing shared ID (sharedId). Please try connecting again.');
            return;
        }

        // Get mode from URL
        const urlParams = new URLSearchParams(window.location.search);
        const mode = urlParams.get('mode') || 'test'; // Default to 'test' if not found

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window?.fluentCartRestVars?.url + '/settings/payment-methods/paypal/seller-auth-token', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-WP-Nonce', window?.fluentCartRestVars?.nonce);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status !== 200) {
                    alert("Something went wrong!");
                }
            }
        };

        xhr.onerror = function() {
            alert('Error: Network request failed. Please check your internet connection and try again.');
        };

        xhr.ontimeout = function() {
            alert('Error: Request timed out. Please try again.');
        };

        xhr.send(JSON.stringify({
            authCode: authCode,
            sharedId: sharedId,
            mode: mode
        }));

    }
</script>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Outfit:wght@100..900&display=swap');

    body{
        font-family: 'Inter', sans-serif;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    /* Reset and base */
    * {
        box-sizing: border-box;
        padding: 0;
        margin: 0;
    }
    .fluent_cart_paypal_authenticator_header{
        display: flex;
        padding: 16px 112px;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #D6DAE1;
        background: #fff;
    }
    .fluent_cart_authenticate_mode {
        display: flex;
        padding: 2px 8px;
        justify-content: center;
        align-items: center;
        gap: 2px;
        border-radius: 6px;
        text-transform: capitalize;
    }

    .fluent_cart_authenticate_test {
        background: #EAECF0;
        color: #2F3448;
    }

    .fluent_cart_authenticate_live {
        background: #D1EAE4;
        color: #116A53;
    }


    /* Features container */
    .features {
        display: flex;
        justify-content: space-between;
        margin-bottom: 40px;
        margin-top: 40px;
        flex-wrap: wrap;
        border-top: 1px solid #EAECF0;
        border-bottom: 1px solid #EAECF0;
        padding-top: 20px;
        padding-bottom: 20px;
    }

    /* Each feature block */
    .feature {
        display: flex;
        flex-direction: column;
        align-items: center;
        min-width: 140px;

    }
    .feature .title {
        font-weight: 500;
        font-size: 15px;
        margin-bottom: 4px;
        color: #2F3448;
    }
    .feature .desc {
        font-weight: 400;
        font-size: 13px;
        color: #2F3448;
        line-height: 20px;
    }

    /* Vertical divider between features */
    .feature:not(:last-child) {
        position: relative;
    }
    .feature:not(:last-child)::after {
        content: "";
        position: absolute;
        right: -24px;
        top: 50%;
        transform: translateY(-50%);
        width: 1px;
        height: 42px;
        background: #EAECF0;
    }

    /* Bottom paragraph */
    .text-box {
        font-weight: 400;
        font-size: 14px;
        color: #525866;
        line-height: 20px;
        letter-spacing: -0.084px;
        border-radius: 12px;
        background: #F9FAFB;
        display: flex;
        padding: 16px;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 30px;
    }
    .text-box h6 {
        color: #0E121B;
        font-size: 14px;
        font-weight: 500;
        line-height: 20px;
        letter-spacing: -0.084px;
        margin: 0;
    }

    /* Button */
    .activate_button {
        border-radius: 8px;
        background: #253241;
        color: #fff;
        font-weight: 500;
        font-size: 14px;
        border: none;
        padding: 10px;
        text-decoration: none;
        cursor: pointer;
        transition: background-color 0.3s ease;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        line-height: 20px;
        letter-spacing: -0.084px;
    }
    .activate_button:hover,
    .activate_button:focus {
        background-color: #222;
        outline: none;
    }

    .fct-logo-wrap a{
        display: block;
    }
    .fct-logo-wrap img{
        height: 36px;
    }
    .fluent_cart_paypal_authenticator_content {
        max-width: 600px;
        margin: 0 auto;
        padding-top: 64px;
        padding-bottom: 64px;
    }
    .fluent_cart_paypal_authenticator_back_button a {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #525866;
        font-size: 14px;
        font-weight: 500;
        line-height: 20px;
        letter-spacing: -0.084px;
        text-decoration: none;
        margin-bottom: 20px;
    }
    .fluent_cart_paypal_authenticator_back_button a:hover{
        text-decoration: underline;
        color: #2F3448;
    }
    .fluent_cart_paypal_authenticator_alert {
        display: flex;
        padding: 8px;
        align-items: flex-start;
        gap: 8px;
        align-self: stretch;
        border-radius: 8px;
        background: #FCEBE6;
        color:  #0E121B;
        font-size: 12px;
        font-style: normal;
        font-weight: 400;
        line-height: 16px;
        margin-bottom: 24px;
    }

    /* Responsive */
    @media (max-width: 600px) {
        .features {
            gap: 24px;
        }
        .feature:not(:last-child)::after {
            display: none;
        }
        .features {
            flex-direction: column;
            align-items: center;
        }
        .feature {
            min-width: auto;
        }
        .text-box {
            font-size: 1rem;
            padding: 0 8px;
        }
        button {
            width: 100%;
            padding: 16px 0;
            font-size: 1.125rem;
        }
    }
</style>
<div class="fluent_cart_paypal_authenticator_main">
    <div class="fluent_cart_paypal_authenticator_header">
        <div class="fct-logo-wrap">
            <a href="<?php echo esc_url($admin_url ?? '');?>">
                <img src="<?php echo esc_url($logo ?? '');?>" alt="">
            </a>
        </div>
    </div>

    <div class="fluent_cart_paypal_authenticator_content">
        <div class="fluent_cart_paypal_authenticator_back_button">
            <a href="<?php echo esc_url($admin_url) . 'settings/payments';?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="10" viewBox="0 0 16 10" fill="none">
                    <path d="M2.1665 5L14.6665 4.9998" stroke="#141B34" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M5.49967 0.833984L2.04011 4.29355C1.70678 4.62688 1.54011 4.79354 1.54011 5.00065C1.54011 5.20776 1.70678 5.37442 2.04011 5.70776L5.49967 9.16732" stroke="#141B34" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>

                Go Back to the Store
            </a>
        </div>

        <?php if(!$url) :?>
            <p class="fluent_cart_paypal_authenticator_alert">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M8 14C4.6862 14 2 11.3138 2 8C2 4.6862 4.6862 2 8 2C11.3138 2 14 4.6862 14 8C14 11.3138 11.3138 14 8 14ZM7.4 9.8V11H8.6V9.8H7.4ZM7.4 5V8.6H8.6V5H7.4Z" fill="#F04438"/>
                </svg>

                <?php 
                    echo sprintf(
                            /* translators: %s is the order mode */
                        esc_html__('Connect URL is not available for order mode %s. Please try again or contact FluentCart support.', 'fluent-cart'),
                        '<strong>' . esc_html($mode) . '</strong>'
                    );
                ?>
            </p>
        <?php endif ?>

        <div style="margin-bottom: 16px;">
            <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="130"
                    height="36"
                    aria-hidden="true"
                    focusable="false"
                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 170 48">
                <g clip-path="url(#a)">
                    <path fill="#003087" d="M62.56 28.672a10.111 10.111 0 0 0 9.983-8.56c.78-4.967-3.101-9.303-8.6-9.303H55.08a.689.689 0 0 0-.69.585l-3.95 25.072a.643.643 0 0 0 .634.742h4.69a.689.689 0 0 0 .688-.585l1.162-7.365a.689.689 0 0 1 .689-.586h4.257Zm3.925-8.786c-.29 1.836-1.709 3.189-4.425 3.189h-3.474l1.053-6.68h3.411c2.81.006 3.723 1.663 3.435 3.496v-.005Zm26.378-1.18H88.41a.69.69 0 0 0-.69.585l-.144.924s-3.457-3.775-9.575-1.225c-3.51 1.461-5.194 4.48-5.91 6.69 0 0-2.277 6.718 2.87 10.417 0 0 4.771 3.556 10.145-.22l-.093.589a.642.642 0 0 0 .634.742h4.451a.689.689 0 0 0 .69-.585l2.708-17.175a.643.643 0 0 0-.634-.742Zm-6.547 9.492a4.996 4.996 0 0 1-4.996 4.276 4.513 4.513 0 0 1-1.397-.205c-1.92-.616-3.015-2.462-2.7-4.462a4.996 4.996 0 0 1 5.014-4.277c.474-.005.946.065 1.398.206 1.913.614 3.001 2.46 2.686 4.462h-.005Z"/>
                    <path fill="#0070E0" d="M126.672 28.672a10.115 10.115 0 0 0 9.992-8.56c.779-4.967-3.101-9.303-8.602-9.303h-8.86a.69.69 0 0 0-.689.585l-3.962 25.079a.637.637 0 0 0 .365.683.64.64 0 0 0 .269.06h4.691a.69.69 0 0 0 .689-.586l1.163-7.365a.688.688 0 0 1 .689-.586l4.255-.007Zm3.925-8.786c-.29 1.836-1.709 3.189-4.426 3.189h-3.473l1.054-6.68h3.411c2.808.006 3.723 1.663 3.434 3.496v-.005Zm26.377-1.18h-4.448a.69.69 0 0 0-.689.585l-.146.924s-3.456-3.775-9.574-1.225c-3.509 1.461-5.194 4.48-5.911 6.69 0 0-2.276 6.718 2.87 10.417 0 0 4.772 3.556 10.146-.22l-.093.589a.637.637 0 0 0 .365.683c.084.04.176.06.269.06h4.451a.686.686 0 0 0 .689-.586l2.709-17.175a.657.657 0 0 0-.148-.518.632.632 0 0 0-.49-.224Zm-6.546 9.492a4.986 4.986 0 0 1-4.996 4.276 4.513 4.513 0 0 1-1.399-.205c-1.921-.616-3.017-2.462-2.702-4.462a4.996 4.996 0 0 1 4.996-4.277c.475-.005.947.064 1.399.206 1.933.614 3.024 2.46 2.707 4.462h-.005Z"/>
                    <path fill="#003087" d="m109.205 19.131-5.367 9.059-2.723-8.992a.69.69 0 0 0-.664-.492h-4.842a.516.516 0 0 0-.496.689l4.88 15.146-4.413 7.138a.517.517 0 0 0 .442.794h5.217a.858.858 0 0 0 .741-.418l13.632-22.552a.516.516 0 0 0-.446-.789h-5.215a.858.858 0 0 0-.746.417Z"/>
                    <path fill="#0070E0" d="m161.982 11.387-3.962 25.079a.637.637 0 0 0 .365.683c.084.04.176.06.269.06h4.689a.688.688 0 0 0 .689-.586l3.963-25.079a.637.637 0 0 0-.146-.517.645.645 0 0 0-.488-.225h-4.69a.69.69 0 0 0-.689.585Z"/>
                    <path fill="#001C64" d="M37.146 22.26c-1.006 5.735-5.685 10.07-11.825 10.07h-3.898c-.795 0-1.596.736-1.723 1.55l-1.707 10.835c-.099.617-.388.822-1.013.822h-6.27c-.634 0-.784-.212-.689-.837l.72-7.493-7.526-.389c-.633 0-.862-.345-.772-.977l5.135-32.56c.099-.617.483-.882 1.106-.882h13.023c6.269 0 10.235 4.22 10.72 9.692 3.73 2.52 5.474 5.873 4.72 10.168Z"/>
                    <path fill="#0070E0" d="m12.649 25.075-1.907 12.133-1.206 7.612a1.034 1.034 0 0 0 1.016 1.19h6.622a1.27 1.27 0 0 0 1.253-1.072l1.743-11.06a1.27 1.27 0 0 1 1.253-1.071h3.898A12.46 12.46 0 0 0 37.617 22.26c.675-4.307-1.492-8.228-5.201-10.165a9.96 9.96 0 0 1-.12 1.37 12.461 12.461 0 0 1-12.295 10.54h-6.1a1.268 1.268 0 0 0-1.252 1.07Z"/>
                    <path fill="#003087" d="M10.741 37.208H3.03a1.035 1.035 0 0 1-1.018-1.192L7.208 3.072A1.268 1.268 0 0 1 8.46 2H21.7c6.269 0 10.827 4.562 10.72 10.089a11.567 11.567 0 0 0-5.399-1.287H15.983a1.27 1.27 0 0 0-1.254 1.071l-2.08 13.202-1.908 12.133Z"/>
                </g>
                <defs>
                    <clipPath id="a">
                        <path fill="#fff" d="M0 0h166v44.01H0z" transform="translate(2 2)"/>
                    </clipPath>
                </defs>
            </svg>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
            <h2 style="color: #2F3448; font-size: 20px; font-weight: 600; line-height: 28px;">
                Welcome to PayPal Payments 👋
            </h2>
            <span class="fluent_cart_authenticate_mode fluent_cart_authenticate_<?php echo esc_html($mode); ?>">
                <?php echo esc_html($mode); ?>
            </span>
        </div>

        <p style="color: #565865; font-size: 14px; font-style: normal; font-weight: 400; line-height: 20px;">
            Your all-in-one integration for PayPal checkout solutions that enable buyers to pay via PayPal, Pay Later, and more.
        </p>

        <section class="features" aria-label="Payment features">
            <div class="feature">
                <span class="title">Deposits</span>
                <span class="desc">Instant</span>
            </div>
            <div class="feature">
                <span class="title">Payment Capture</span>
                <span class="desc">Authorize only or Capture</span>
            </div>
            <div class="feature">
                <span class="title">Recurring Payments</span>
                <span class="desc">Supported</span>
            </div>
        </section>

        <div class="text-box">
            <h6>Set Up Your Payment:</h6>
            To complete your payment setup, please log in to PayPal.
            If you don’t have an account yet, don’t worry - we’ll guide you through the easy process of creating one.
        </div>

        <?php if ($url) : ?>
            <a class="activate_button"
               ref="paypal-button"
               data-paypal-onboard-complete="onboardedCallback"
               href="<?php echo esc_url($url ?? ''); ?>"
               data-paypal-button="true"
            >
                Connect PayPal with FluentCart
                <svg xmlns="http://www.w3.org/2000/svg" width="17" height="18" viewBox="0 0 17 18" fill="none">
                    <path d="M7.75019 1.5C4.70881 1.50548 3.11619 1.58014 2.09838 2.59795C1 3.69633 1 5.46415 1 8.99978C0.999999 12.5354 0.999999 14.3032 2.09838 15.4016C3.19676 16.5 4.96458 16.5 8.50022 16.5C12.0359 16.5 13.8037 16.5 14.9021 15.4016C15.9199 14.3838 15.9945 12.7912 16 9.74981" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M7.13759 9.30183C6.84405 9.59407 6.843 10.0689 7.13524 10.3625C7.42749 10.656 7.90236 10.6571 8.1959 10.3648L7.13759 9.30183ZM15.1905 1.68922L15.0597 2.42773L15.1905 1.68922ZM11.8334 0.75C11.4191 0.750036 11.0834 1.08585 11.0834 1.50007C11.0835 1.91428 11.4193 2.25004 11.8335 2.25L11.8334 0.75ZM15.2501 5.66667C15.2501 6.08088 15.5859 6.41667 16.0001 6.41667C16.4143 6.41667 16.7501 6.08088 16.7501 5.66667L15.2501 5.66667ZM15.8109 2.30979L15.0723 2.44045V2.44045L15.8109 2.30979ZM15.6186 1.91667L15.0894 1.38516L7.13759 9.30183L7.66675 9.83333L8.1959 10.3648L16.1477 2.44817L15.6186 1.91667ZM15.1905 1.68922L15.3213 0.950711C14.7274 0.845527 13.8384 0.797519 13.136 0.773998C12.7767 0.761967 12.452 0.755977 12.2171 0.752989C12.0996 0.751494 12.0042 0.750747 11.938 0.750373C11.9049 0.750187 11.8791 0.750093 11.8614 0.750047C11.8525 0.750023 11.8457 0.750012 11.841 0.750006C11.8386 0.750003 11.8368 0.750001 11.8355 0.750001C11.8349 0.75 11.8344 0.75 11.834 0.75C11.8338 0.75 11.8337 0.75 11.8336 0.75C11.8335 0.75 11.8335 0.75 11.8334 0.75C11.8334 0.75 11.8334 0.75 11.8334 1.5C11.8335 2.25 11.8335 2.25 11.8335 2.25C11.8335 2.25 11.8335 2.25 11.8335 2.25C11.8335 2.25 11.8336 2.25 11.8337 2.25C11.8339 2.25 11.8342 2.25 11.8347 2.25C11.8356 2.25 11.8371 2.25 11.8391 2.25C11.8431 2.25001 11.8493 2.25002 11.8574 2.25004C11.8737 2.25008 11.898 2.25017 11.9296 2.25035C11.9926 2.25071 12.0844 2.25142 12.198 2.25287C12.4255 2.25576 12.7395 2.26156 13.0858 2.27316C13.7945 2.29689 14.5841 2.34349 15.0597 2.42773L15.1905 1.68922ZM16.0001 5.66667C16.7501 5.66667 16.7501 5.66663 16.7501 5.66658C16.7501 5.66655 16.7501 5.6665 16.7501 5.66644C16.7501 5.66633 16.7501 5.66619 16.7501 5.66601C16.7501 5.66565 16.7501 5.66515 16.7501 5.66451C16.7501 5.66325 16.7501 5.66143 16.7501 5.65908C16.7501 5.65438 16.7501 5.64754 16.75 5.63868C16.75 5.62096 16.7499 5.59511 16.7497 5.56201C16.7493 5.49581 16.7486 5.4005 16.7471 5.28296C16.7441 5.04812 16.7381 4.72341 16.726 4.36417C16.7025 3.66182 16.6545 2.77295 16.5494 2.17913L15.8109 2.30979L15.0723 2.44045C15.1565 2.91615 15.2031 3.70572 15.2269 4.41443C15.2385 4.76072 15.2443 5.07469 15.2472 5.30215C15.2487 5.41576 15.2494 5.50751 15.2497 5.57058C15.2499 5.60211 15.25 5.62645 15.2501 5.64275C15.2501 5.6509 15.2501 5.65704 15.2501 5.66107C15.2501 5.66308 15.2501 5.66456 15.2501 5.66549C15.2501 5.66596 15.2501 5.6663 15.2501 5.66649C15.2501 5.66659 15.2501 5.66665 15.2501 5.66668C15.2501 5.6667 15.2501 5.66669 15.2501 5.6667C15.2501 5.66669 15.2501 5.66667 16.0001 5.66667ZM15.1905 1.68922L15.0597 2.42773C15.0651 2.42868 15.0687 2.42975 15.071 2.43051C15.0732 2.43127 15.0742 2.43181 15.0743 2.43188C15.0745 2.43195 15.074 2.43171 15.0731 2.43104C15.0723 2.43037 15.0713 2.42951 15.0704 2.4285L15.6186 1.91667L16.1668 1.40483C15.9423 1.16444 15.6467 1.00833 15.3213 0.950711L15.1905 1.68922ZM15.6186 1.91667L15.0704 2.4285C15.0685 2.42652 15.0679 2.42527 15.0683 2.42593C15.0686 2.42661 15.0707 2.43097 15.0723 2.44045L15.8109 2.30979L16.5494 2.17913C16.4979 1.88803 16.3675 1.61984 16.1668 1.40483L15.6186 1.91667Z" fill="white"/>
                </svg>
            </a>
        <?php endif; ?>

        <!--    <hr style="margin-top: 60px;">-->
        <!--    --><?php //require 'manual-connect.php'; ?>
    </div>

</div>
<?php //phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript  ?>
<script id="paypal-js" src="<?php echo esc_html($scriptJs);?>" PayPal-Partner-Attribution-Id="FLUENTCART_SP_PPCP" ></script>
</body>
</html>
