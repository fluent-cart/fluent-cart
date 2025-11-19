const { Panel, PanelBody } = wp.components;

const BlockEditorControl = (props) => {
    const {title, children, initialOpen = false} = props;

    return (
        <div className="fct-block-editor-control">
            <Panel>
                <PanelBody title={title ? title : null} initialOpen={initialOpen}>
                    <div className="fct-block-editor-control-body">
                        {children}
                    </div>
                </PanelBody>
            </Panel>
        </div>
    )
}

export default BlockEditorControl;
