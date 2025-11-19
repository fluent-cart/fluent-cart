import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { Component } = wp.element;
class ErrorBoundary extends Component {
    state = { hasError: false, error: null };

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    render() {
        if (this.state.hasError) {
            return <p>
                {
                    /* translators: %s is the error message */
                    blocktranslate('Error in component: %s', this.state.error?.message || blocktranslate("Unknown"))
                }
            </p>;
        }
        return this.props.children;
    }
}

export default ErrorBoundary;
