const {Button} = wp.components;


const SubmitButton = ({ attributes }) => {
    return (
        <div className={`fluent-cart-submit-button ${attributes.submitButton.alignment}`}>
            <Button className={`fluent-cart-checkout-page-checkout-button ${attributes.submitButton.size} ${!attributes.submitButton.full ? 'not-full-width' : ''}`} isPrimary>
                {attributes.submitButton.text}
            </Button>
        </div>
    );
};

export default SubmitButton;
