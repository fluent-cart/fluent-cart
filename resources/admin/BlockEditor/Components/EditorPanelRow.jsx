const EditorPanelRow = (props) => {
    const { children, className } = props;

    return (
        <div className={`fct-inspector-control-row ${className ?? ''}`}>
            {children}
        </div>
    );
};

export default EditorPanelRow;
