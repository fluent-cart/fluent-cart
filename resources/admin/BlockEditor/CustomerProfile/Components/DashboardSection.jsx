import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { RichText } = wp.blockEditor;

const DashboardSection = ({ title, value, onChange, children }) => {
    return (
        <div className="fct-customer-dashboard-item">
            <div className="fct-customer-dashboard-header">
                <RichText
                    tagName="h2"
                    className="fct-customer-dashboard-title"
                    value={value}
                    onChange={onChange}
                    placeholder={
                        /* translators: %s - section title */
                    blocktranslate("Enter %s Title", title)
                }
                />
                <div className="actions">
                    <a href="#" className="is-link">{blocktranslate("View All")}</a>
                </div>
            </div>
            <div className="fct-customer-dashboard-table">
                {children}
            </div>
        </div>
    );
};

export default DashboardSection;
