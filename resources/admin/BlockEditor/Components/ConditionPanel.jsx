import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {PanelBody, ToggleControl, SelectControl, TextControl, Button, Popover} = wp.components;
const {useState, useMemo, useRef, useEffect} = wp.element;

const PRESETS = window.fctConditionPresets || [];

const CONDITIONS = [
    {label: 'Is Not Empty', value: 'not_empty'},
    {label: 'Is Empty', value: 'empty'},
    {label: 'Equals', value: 'equal'},
    {label: 'Not Equals', value: 'not_equal'},
    {label: 'Greater Than', value: 'greater_than'},
    {label: 'Smaller Than', value: 'smaller_than'},
];

const COMPARE_VALUE_CONDITIONS = ['equal', 'not_equal', 'greater_than', 'smaller_than'];

const proEnabled = !(window.fctEditorBoot || {}).disableProTemplates;

/* ── ConditionSelect ─────────────────────────────────────────────── */

const ConditionSelect = ({value, onChange}) => {
    const [open, setOpen] = useState(false);
    const wrapRef = useRef();

    const selected = value
        ? (PRESETS.find(p => p.id === value) || (value === '__custom' ? {id: '__custom', label: blocktranslate('Custom (Advanced)'), hint: ''} : null))
        : null;

    useEffect(() => {
        if (!open) return;
        const onClickOutside = (e) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onClickOutside);
        return () => document.removeEventListener('mousedown', onClickOutside);
    }, [open]);

    return (
        <div className="fct-condition-select" ref={wrapRef}>
            <label className="fct-condition-select__label">{blocktranslate('Condition')}</label>
            <button
                type="button"
                className={'fct-condition-select__trigger' + (open ? ' is-open' : '')}
                onClick={() => setOpen(!open)}
            >
                <span className="fct-condition-select__trigger-text">
                    {selected ? selected.label : blocktranslate('— Select a condition —')}
                </span>
                <svg className="fct-condition-select__arrow" viewBox="0 0 20 20" width="16" height="16">
                    <path d="M5 7.5L10 12.5L15 7.5" fill="none" stroke="currentColor" strokeWidth="1.5"/>
                </svg>
            </button>
            {selected && selected.hint && (
                <p className="fct-condition-select__hint">{selected.hint}</p>
            )}
            {open && (
                <div className="fct-condition-select__dropdown">
                    <button
                        type="button"
                        className={'fct-condition-select__option' + (!value ? ' is-active' : '')}
                        onClick={() => { onChange(''); setOpen(false); }}
                    >
                        <span className="fct-condition-select__option-label">{blocktranslate('— Select a condition —')}</span>
                    </button>
                    {PRESETS.map(p => (
                        <button
                            key={p.id}
                            type="button"
                            className={'fct-condition-select__option' + (value === p.id ? ' is-active' : '')}
                            onClick={() => { onChange(p.id); setOpen(false); }}
                        >
                            <span className="fct-condition-select__option-label">{p.label}</span>
                            {p.hint && <span className="fct-condition-select__option-hint">{p.hint}</span>}
                        </button>
                    ))}
                    {proEnabled && (
                        <button
                            type="button"
                            className={'fct-condition-select__option fct-condition-select__option--advanced' + (value === '__custom' ? ' is-active' : '')}
                            onClick={() => { onChange('__custom'); setOpen(false); }}
                        >
                            <span className="fct-condition-select__option-label">{blocktranslate('Custom (Advanced)')}</span>
                            <span className="fct-condition-select__option-hint">{blocktranslate('Write your own shortcode condition.')}</span>
                        </button>
                    )}
                </div>
            )}
        </div>
    );
};

/* ── SmartcodesPopover ───────────────────────────────────────────── */

const SmartcodesPopover = ({onSelect, onClose}) => {
    const groups = window.fctSmartCodes || [];
    const [activeGroup, setActiveGroup] = useState(groups.length ? groups[0].key : '');
    const [search, setSearch] = useState('');

    const activeGroupData = useMemo(() => {
        return groups.find(g => g.key === activeGroup) || null;
    }, [activeGroup, groups]);

    const filteredCodes = useMemo(() => {
        if (!activeGroupData || !activeGroupData.shortcodes) return [];
        const entries = Object.entries(activeGroupData.shortcodes);
        if (!search) return entries;
        const q = search.toLowerCase();
        return entries.filter(([code, label]) =>
            label.toLowerCase().includes(q) || code.toLowerCase().includes(q)
        );
    }, [activeGroupData, search]);

    return (
        <Popover
            placement="left-start"
            onClose={onClose}
            shift={true}
            className="fct-smartcodes-popover"
        >
            <div className="fct-smartcodes-popover__layout">
                <div className="fct-smartcodes-popover__sidebar">
                    <div className="fct-smartcodes-popover__sidebar-title">
                        {blocktranslate('SMARTCODES')}
                    </div>
                    {groups.map(group => (
                        <button
                            key={group.key}
                            className={
                                'fct-smartcodes-popover__tab' +
                                (activeGroup === group.key ? ' is-active' : '')
                            }
                            onClick={() => {
                                setActiveGroup(group.key);
                                setSearch('');
                            }}
                        >
                            {group.title}
                        </button>
                    ))}
                </div>
                <div className="fct-smartcodes-popover__body">
                    <input
                        type="text"
                        className="fct-smartcodes-popover__search"
                        placeholder={blocktranslate('Search smartcodes...')}
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        autoFocus
                    />
                    <div className="fct-smartcodes-popover__list">
                        {filteredCodes.map(([code, label]) => (
                            <button
                                key={code}
                                className="fct-smartcodes-popover__item"
                                onClick={() => {
                                    onSelect(code);
                                    onClose();
                                }}
                            >
                                <span className="fct-smartcodes-popover__item-label">{label}</span>
                                <span className="fct-smartcodes-popover__item-code">{code}</span>
                            </button>
                        ))}
                        {filteredCodes.length === 0 && (
                            <div className="fct-smartcodes-popover__empty">
                                {blocktranslate('No matching smartcodes.')}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </Popover>
    );
};

/* ── ConditionPanel ──────────────────────────────────────────────── */

/**
 * Shared condition panel for email blocks.
 *
 * Supports preset conditions (resolved server-side) and custom/advanced mode.
 *
 *   <ConditionPanel attributes={attributes} setAttributes={setAttributes} />
 */
const ConditionPanel = ({attributes, setAttributes}) => {
    const {
        conditionEnabled = false,
        conditionPreset = '',
        conditionShortcode = '',
        conditionType = 'not_empty',
        conditionCompareValue = '',
    } = attributes;

    const [isPopoverOpen, setIsPopoverOpen] = useState(false);
    const buttonRef = useRef();

    const isCustom = conditionPreset === '__custom';

    function onPresetChange(val) {
        if (val === '__custom') {
            setAttributes({
                conditionPreset: '__custom',
                conditionShortcode: '',
                conditionType: 'not_empty',
                conditionCompareValue: '',
            });
        } else {
            setAttributes({
                conditionPreset: val,
                conditionShortcode: '',
                conditionType: 'not_empty',
                conditionCompareValue: '',
            });
        }
    }

    return (
        <PanelBody
            title={blocktranslate('Visibility Condition')}
            initialOpen={false}
            icon={conditionEnabled ? (
                <span style={{color: '#0ea5e9', fontSize: '12px', fontWeight: 600}}>ON</span>
            ) : undefined}
        >
            <ToggleControl
                label={blocktranslate('Enable Condition')}
                help={blocktranslate('Only show this block when the condition is met.')}
                checked={conditionEnabled}
                onChange={(val) => setAttributes({conditionEnabled: val})}
            />
            {conditionEnabled && (
                <>
                    <ConditionSelect value={conditionPreset} onChange={onPresetChange} />

                    {isCustom && (
                        <>
                            <TextControl
                                label={blocktranslate('Shortcode')}
                                value={conditionShortcode}
                                onChange={(val) => setAttributes({conditionShortcode: val})}
                                placeholder="{{order.some_field}}"
                                help={blocktranslate('The smartcode value to evaluate.')}
                            />
                            <div ref={buttonRef} style={{position: 'relative', marginBottom: '16px'}}>
                                <Button
                                    variant="secondary"
                                    isSmall
                                    onClick={() => setIsPopoverOpen(!isPopoverOpen)}
                                    icon={
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                             width="20" height="20" fill="none" stroke="currentColor"
                                             strokeWidth="2">
                                            <path d="M7 7h10v10"/>
                                            <path d="M7 17 17 7"/>
                                        </svg>
                                    }
                                >
                                    {blocktranslate('Browse Smartcodes')}
                                </Button>
                                {isPopoverOpen && (
                                    <SmartcodesPopover
                                        onSelect={(code) => setAttributes({conditionShortcode: code})}
                                        onClose={() => setIsPopoverOpen(false)}
                                    />
                                )}
                            </div>
                            <SelectControl
                                label={blocktranslate('Compare Type')}
                                value={conditionType}
                                options={CONDITIONS}
                                onChange={(val) => setAttributes({conditionType: val})}
                            />
                            {COMPARE_VALUE_CONDITIONS.includes(conditionType) && (
                                <TextControl
                                    label={blocktranslate('Compare Value')}
                                    value={conditionCompareValue}
                                    onChange={(val) => setAttributes({conditionCompareValue: val})}
                                    placeholder={blocktranslate('Value to compare against')}
                                />
                            )}
                        </>
                    )}
                </>
            )}
        </PanelBody>
    );
};

/**
 * Condition attribute definitions — spread into your block's `attributes` object.
 */
export const CONDITION_ATTRIBUTES = {
    conditionEnabled: {
        type: 'boolean',
        default: false,
    },
    conditionPreset: {
        type: 'string',
        default: '',
    },
    conditionShortcode: {
        type: 'string',
        default: '',
    },
    conditionType: {
        type: 'string',
        default: 'not_empty',
    },
    conditionCompareValue: {
        type: 'string',
        default: '',
    },
};

export {ConditionSelect};
export default ConditionPanel;
