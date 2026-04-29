import SubmitButton from "@/BlockEditor/Checkout/Components/SubmitButton";
import CouponField from "./CouponField";

const {useState, useEffect} = wp.element;
const { RichText } = wp.blockEditor;

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const OrderSummary = ({attributes, setAttributes}) => {
    const items = [
        {
            id: 1,
            image: 'https://placehold.co/50',
            name: blocktranslate('Fluent Forms Pro Add-On: The Fastest & Most Powerful WordPress Form Plugin'),
            variantTitle: blocktranslate('Single Site Lifetime License'),
            price: 10,
            quantity: 1
        },
        {
            id: 2,
            image: 'https://placehold.co/50',
            name: blocktranslate('Fluent Booking – The Ultimate Appointments Scheduling, Events Booking, Events Calendar Solution'),
            variantTitle: blocktranslate('Single Site Lifetime License'),
            price: 10,
            quantity: 2
        },
        {
            id: 3,
            image: 'https://placehold.co/50',
            name: blocktranslate('FluentCRM – The Ultimate CRM, Marketing, & Automation Solution for WordPress'),
            variantTitle: blocktranslate('Single Site Lifetime License'),
            price: 10,
            quantity: 3
        }
    ];

    const maxVisibleItems = attributes.orderSummary.maxVisibleItems;
    const shouldCollapse = items.length > maxVisibleItems;
    const [isOpen, setIsOpen] = useState(() => !shouldCollapse);

    // Dynamically update open state when settings change
    useEffect(() => {
        setIsOpen(!shouldCollapse);
    }, [shouldCollapse]);

    const toggleCartItems = () => {
        setIsOpen(!isOpen)
    }

    const total = items.reduce((acc, item) => acc + item.price * item.quantity, 0);

    return (
        <div className="fluent-cart-checkout-block-editor-card">
            <div className="fluent-cart-checkout-block-editor-card-body">
                <div className="fluent-cart-checkout-block-editor-items-collapsible-head">
                    <h3 className="fluent-cart-checkout-block-editor-card-title">
                        <RichText
                            tagName="span"
                            value={attributes.orderSummary.heading}
                            onChange={(val) => setAttributes({
                                orderSummary: {
                                    ...attributes.orderSummary,
                                    heading: val
                                }
                            })}
                        />
                    </h3>
                    <div className="cart-items-toggle-action" onClick={toggleCartItems}>
                        <span>{attributes.orderSummary.toggleButtonText}</span>

                        <svg style={{transform: isOpen ? 'rotate(-180deg)' : 'rotate(0deg)'}} xmlns="http://www.w3.org/2000/svg" width="10" height="6" viewBox="0 0 10 6" fill="none">
                            <path
                                d="M1 1L4.29289 4.29289C4.62623 4.62623 4.79289 4.79289 5 4.79289C5.20711 4.79289 5.37377 4.62623 5.70711 4.29289L9 1"
                                stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"
                                strokeLinejoin="round"/>
                        </svg>
                    </div>
                </div>

                {(isOpen || !shouldCollapse) && (
                    <div className="fluent-cart-checkout-page-cart-item-list">
                        {items.map((item) => (
                            <div className="fluent-cart-checkout-page-single-cart-item" key={item.id}>
                                <div className="checkout-page-cart-item-left">
                                    <div className="fluent-cart-checkout-page-cart-item-image">
                                        <a href="#">
                                            <img src={item.image} alt=""/>
                                        </a>
                                    </div>
                                    <div className="checkout-page-cart-item-left-content">
                                        <div className="fluent-cart-checkout-page-cart-item-title">
                                            <a href="#">
                                                {item.name}
                                            </a>
                                        </div>
                                        <div className="fluent-cart-checkout-page-cart-item-variation-title">
                                            - {item.variantTitle}
                                        </div>
                                        <div className="fluent-cart-checkout-page-single-cart-item-meta">
                                            <div className="fluent-cart-checkout-page-cart-item-price">
                                                <span>{blocktranslate('Unit Price:')}</span> ${item.price}
                                                 x {item.quantity}
                                            </div>

                                            {attributes.orderSummary.showRemoveButton && (
                                                <div className="fluent-cart-checkout-page-cart-item-delete">
                                                    {attributes.orderSummary.removeButtonText}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="checkout-page-cart-item-right">
                                    <div className="fluent-cart-checkout-page-cart-item-total">
                                        ${item.price * item.quantity}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}


                <ul className="fluent-cart-checkout-page-cart-item-order-summary">
                    <li>
                        <span className="title">{blocktranslate('Subtotal')}</span>
                        <span className="value">${total}</span>
                    </li>

                    <li className="block-li">
                        <CouponField
                            attributes={attributes}
                            setAttributes={setAttributes}
                        />
                    </li>

                    <li className="fluent-cart-checkout-page-order-summary-total-wrap">
                        <span className="title">{attributes.orderSummary.totalText}</span>
                        <span className="value">${total}</span>
                    </li>
                </ul>


            </div>
        </div>
    );
};

export default OrderSummary;
