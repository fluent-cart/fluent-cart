import {Tag} from "@/BlockEditor/Icons";
const {useState, useEffect} = wp.element;
const { TextControl } = wp.components;

const CouponField = ({attributes, setAttributes}) => {
    const { coupons } = attributes;

    const [isOpen, setIsOpen] = useState(!coupons.collapsible);

    useEffect(() => {
        // Update state if collapsible changes
        setIsOpen(!coupons.collapsible);
    }, [coupons.collapsible]);

    const toggleCouponField = (e) => {
        e.preventDefault();
        if (coupons.collapsible) {
            setIsOpen((prev) => !prev);
        }
    };

    return (
        <div className={`fluent-cart-checkout-page-coupon-fields ${!attributes.coupons.iconVisibility ? 'hide-input-icon' : ''}`}>
            <div className="coupon-field-toggle-button-wrap">
                <a href="#" className="coupon-field-toggle-button" onClick={toggleCouponField}>{attributes.coupons.label} </a>
            </div>

            {isOpen && (
                <div className="fluent-cart-checkout-page-coupon-fields-group">
                    {attributes.coupons.iconVisibility && (
                        <span className="icon">
                            <Tag/>
                        </span>
                    )}

                    <TextControl
                        placeholder={attributes.coupons.placeholder}
                    />

                    <button type="button">{attributes.coupons.applyButton}</button>
                </div>
            )}

        </div>
    );
};

export default CouponField;
