const { Panel, PanelBody } = wp.components;


const EditorPanel = (props) => {
    const {title, children} = props;

    return (
        <div className="fct-block-editor-panel">
            <Panel>
                <PanelBody title={title} initialOpen={false}>
                    <div className="fct-block-editor-panel-body">
                        <div className="fct-inspector-control-settings">
                            {children}
                        </div>
                    </div>
                </PanelBody>
            </Panel>
        </div>
    )
}

export default EditorPanel;
