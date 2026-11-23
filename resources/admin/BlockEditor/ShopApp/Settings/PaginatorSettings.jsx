import BlockEditorControl from "@/BlockEditor/Components/BlockEditorControl";
import SegmentedToggle from "@/BlockEditor/Components/SegmentedToggle";
import NumberStepper from "@/BlockEditor/Components/NumberStepper";
import blocktranslate, {_x} from "@/BlockEditor/BlockEditorTranslator";

const PaginatorSettings = ({attributes, setAttributes}) => {
    return (
        <BlockEditorControl title={blocktranslate('Paginator')}>
            <div className="fct-sidebar-stack">
                <SegmentedToggle
                    label={_x('View', 'Pagination display mode')}
                    options={[
                        {value: 'scroll', label: blocktranslate('Scroll')},
                        {value: 'numbers', label: blocktranslate('Numbers')},
                    ]}
                    value={attributes.paginator}
                    onChange={(paginator) => setAttributes({paginator})}
                />

                <NumberStepper
                    label={blocktranslate("Per Page/Scroll")}
                    value={Number(attributes.per_page)}
                    min={1}
                    max={50}
                    onChange={(perPage) => setAttributes({per_page: perPage})}
                />
            </div>
        </BlockEditorControl>
    );
};

export default PaginatorSettings;
