import InspectorSettings from "@/BlockEditor/ShopApp/Settings/InspectorSettings";
import BlockEditorControl from "@/BlockEditor/Components/BlockEditorControl";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {
    __experimentalRadio: Radio,
    __experimentalRadioGroup: RadioGroup,
    RangeControl
} = wp.components

const PaginatorSettings = (props) => {
    const {attributes, setAttributes, blockEditorData} = props;

    const setPagination = (paginator) => {
        setAttributes({paginator: paginator});
    };


    return (
        <BlockEditorControl title={blocktranslate('Paginator')}>
            <div className="fct-block-editor-control-item">
                <div className="fct-inspector-control-row">
                    <span className="label">{blocktranslate('View')}</span>
                    <div className="actions">
                        <RadioGroup
                            className="fct-block-editor-radio-group"
                            label={blocktranslate('Pagination Type')}
                            onChange={setPagination}
                            checked={attributes.paginator}>
                            <Radio value="scroll">{blocktranslate('Scroll')}</Radio>
                            <Radio value="numbers">{blocktranslate('Numbers')}</Radio>
                        </RadioGroup>
                    </div>
                </div>
            </div>
            <div className="fct-block-editor-control-item">
                <div className="fct-inspector-control-row">
                    <span className="label">{blocktranslate("Per Page/Scroll")}</span>
                    <div className="actions">
                        <RangeControl
                            value={attributes.per_page}
                            onChange={perPage => setAttributes({per_page: perPage})}
                            min={1}
                            max={50}
                        />
                    </div>
                </div>
            </div>
        </BlockEditorControl>

    )
}

export default PaginatorSettings;
