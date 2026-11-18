const {FormToggle} = wp.components;

// A setting's title (and an optional example) on the left, its toggle on the right, as in the swatches design.
// The title is the toggle's label, so clicking it flips the setting.
const SettingSwitch = ({id, title, description, checked, onChange, large = false}) => (
    <div className="fct-swatch-switch">
        <div className="fct-swatch-switch-text">
            <label htmlFor={id} className={'fct-swatch-switch-title' + (large ? ' is-large' : '')}>{title}</label>
            {description && <span id={id + '-description'} className="fct-swatch-switch-description">{description}</span>}
        </div>
        <FormToggle
            id={id}
            checked={!!checked}
            onChange={(event) => onChange(event.target.checked)}
            aria-describedby={description ? id + '-description' : undefined}
        />
    </div>
);

// Puts one value in bold inside a translated sentence, e.g. "e.g. <b>Color</b> above the row."
// Splitting on %s keeps typed text (a row name) plain text instead of parsing it as markup.
export const withBoldValue = (sentence, value) => {
    // A translation may number its placeholder (%1$s).
    const [before, after = ''] = sentence.split(/%(?:1\$)?s/);
    return <>{before}<b>{value}</b>{after}</>;
};

export default SettingSwitch;
