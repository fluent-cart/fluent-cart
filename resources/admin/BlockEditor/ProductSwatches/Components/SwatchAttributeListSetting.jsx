const {useState} = wp.element;

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

// Blocks saved before the checkbox existed show every attribute when none is picked.
export const showsOtherGroups = (attributes) => (
    typeof attributes.show_other_groups === 'boolean' ? attributes.show_other_groups : !(attributes.group_ids || []).length
);

// A short hint of each attribute's values, from the store's real count.
const valueCount = (group) => {
    const count = typeof group.term_count === 'number' ? group.term_count : (group.terms || []).length;
    if (count === 0) {
        return blocktranslate('No values');
    }
    if (count === 1) {
        return blocktranslate('1 value');
    }
    // translators: %s is how many values an attribute has
    return blocktranslate('%s values', count);
};

const ArrowIcon = ({up}) => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d={up ? 'M7 14l5-5 5 5' : 'M7 10l5 5 5-5'}/>
    </svg>
);

// Saved ids the sidebar does not list (it loads at most 100 attributes) keep their positions; the
// listed ones are laid back into the other slots in their new order, extra ones at the end.
export const mergeKeepingUnlisted = (saved, nextListed, isListed) => {
    const queue = [...nextListed];
    const merged = [];
    saved.forEach((id) => {
        if (!isListed(id)) {
            merged.push(id);
        } else if (queue.length) {
            merged.push(queue.shift());
        }
    });
    return [...merged, ...queue];
};

// Which attributes to show: tick to show, arrows to reorder, as in the swatches design. The ticked
// rows, in their saved order, are group_ids, so undo shows what the cards show. Unticked rows are
// hidden_group_ids: they stay in the list, below the ticked ones, and stay off the cards even when
// "Also show attributes not listed here" adds the product's other attributes.
const SwatchAttributeListSetting = ({attributes, setAttributes, attributeGroups}) => {
    const groups = new Map(attributeGroups.map((group) => [Number(group.id), group]));
    const isListed = (id) => groups.has(id);
    const saved = (attributes.group_ids || []).map(Number);
    const savedHidden = (attributes.hidden_group_ids || []).map(Number);
    const picked = saved.filter(isListed);
    const unticked = savedHidden.filter((id) => isListed(id) && !picked.includes(id));
    const [adding, setAdding] = useState(false);
    const rows = [...picked, ...unticked];
    const other = showsOtherGroups(attributes);
    const pool = attributeGroups.filter((group) => !rows.includes(Number(group.id)));

    // Saving the checkbox with every change keeps "show every attribute" from flipping off on the first pick.
    const save = (nextPicked, nextUnticked = unticked, nextOther = other) => {
        setAttributes({
            group_ids: mergeKeepingUnlisted(saved, nextPicked, isListed),
            hidden_group_ids: mergeKeepingUnlisted(savedHidden, nextUnticked, isListed),
            show_other_groups: nextOther,
        });
    };
    const focusLater = (id) => requestAnimationFrame(() => document.getElementById(id)?.focus());
    const toggle = (id) => {
        if (picked.includes(id)) {
            save(picked.filter((item) => item !== id), [id, ...unticked.filter((item) => item !== id)]);
        } else {
            save([...picked, id], unticked.filter((item) => item !== id));
        }
    };
    // Only ticked rows reorder: their order is what the cards show.
    const move = (index, step, direction) => {
        const next = [...picked];
        [next[index], next[index + step]] = [next[index + step], next[index]];
        save(next);
        // Keep focus on the same arrow of the moved row, or its checkbox once it reached an end.
        const lastIndex = index + step;
        const atEnd = direction === 'up' ? lastIndex === 0 : lastIndex === picked.length - 1;
        focusLater((atEnd ? 'fct-swatch-attribute-' : 'fct-swatch-attribute-' + direction + '-') + picked[index]);
    };
    const add = (id) => {
        setAdding(false);
        save([...picked, id], unticked.filter((item) => item !== id));
        focusLater('fct-swatch-attribute-' + id);
    };
    const closePool = (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            setAdding(false);
            focusLater('fct-swatch-attribute-add-toggle');
        }
    };

    return (
        <section className="fct-swatch-section">
            <div className="fct-swatch-switch-text">
                <span id="fct-swatch-attribute-list-title" className="fct-swatch-switch-title">{blocktranslate('Which attributes to show')}</span>
                <span className="fct-swatch-switch-description">{blocktranslate('Tick to show, arrows to reorder.')}</span>
            </div>

            {rows.length > 0 && (
                <ul className="fct-swatch-attribute-list" aria-labelledby="fct-swatch-attribute-list-title">
                    {rows.map((id, index) => {
                        const group = groups.get(id);
                        const checked = picked.includes(id);
                        const last = picked.length - 1;
                        return (
                            <li key={id} className="fct-swatch-attribute-row">
                                <input
                                    id={'fct-swatch-attribute-' + id}
                                    type="checkbox"
                                    checked={checked}
                                    onChange={() => toggle(id)}
                                />
                                <label htmlFor={'fct-swatch-attribute-' + id} className={checked ? '' : 'is-off'}>{group.title}</label>
                                <span className="fct-swatch-attribute-sample" aria-hidden="true">{valueCount(group)}</span>
                                <button
                                    id={'fct-swatch-attribute-up-' + id}
                                    type="button"
                                    className="fct-swatch-attribute-move"
                                    /* translators: %s is an attribute name, e.g. Color */
                                    aria-label={blocktranslate('Move %s up', group.title)}
                                    disabled={!checked || index === 0}
                                    onClick={() => move(index, -1, 'up')}
                                >
                                    <ArrowIcon up/>
                                </button>
                                <button
                                    id={'fct-swatch-attribute-down-' + id}
                                    type="button"
                                    className="fct-swatch-attribute-move"
                                    /* translators: %s is an attribute name, e.g. Color */
                                    aria-label={blocktranslate('Move %s down', group.title)}
                                    disabled={!checked || index === last}
                                    onClick={() => move(index, 1, 'down')}
                                >
                                    <ArrowIcon/>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="fct-swatch-attribute-add">
                <button
                    id="fct-swatch-attribute-add-toggle"
                    type="button"
                    className="fct-swatch-attribute-add-toggle"
                    aria-expanded={adding}
                    aria-controls="fct-swatch-attribute-pool"
                    onClick={() => setAdding(!adding)}
                >
                    {blocktranslate('+ Add another attribute')}
                </button>
                {adding && (
                    <div
                        id="fct-swatch-attribute-pool"
                        className="fct-swatch-attribute-pool"
                        role="group"
                        aria-label={blocktranslate('Your store\'s attributes')}
                        onKeyDown={closePool}
                    >
                        <span className="fct-swatch-attribute-pool-title" aria-hidden="true">{blocktranslate('Your store\'s attributes')}</span>
                        {pool.length ? pool.map((group) => (
                            <button key={group.id} type="button" onClick={() => add(Number(group.id))}>
                                <span>{group.title}</span>
                                <span className="fct-swatch-attribute-sample">{valueCount(group)}</span>
                            </button>
                        )) : (
                            <span className="fct-swatch-attribute-pool-empty">{blocktranslate('All store attributes are already in the list.')}</span>
                        )}
                    </div>
                )}
            </div>

            <div className="fct-swatch-attribute-other">
                <input
                    id="fct-swatch-attribute-other"
                    type="checkbox"
                    checked={other}
                    aria-describedby="fct-swatch-attribute-other-help"
                    onChange={(event) => save(picked, unticked, event.target.checked)}
                />
                <div className="fct-swatch-switch-text">
                    <label htmlFor="fct-swatch-attribute-other">{blocktranslate('Also show attributes not listed here')}</label>
                    <span id="fct-swatch-attribute-other-help" className="fct-swatch-section-help">
                        {blocktranslate('Any other attribute a product has shows after the ones above.')}
                    </span>
                </div>
            </div>

            {/* Always in the page, so a screen reader announces the warning when it appears. */}
            <span role="status" className="fct-swatch-attribute-warning">
                {!saved.length && !other && blocktranslate('Tick at least one, or cards will show no options.')}
            </span>
        </section>
    );
};

export default SwatchAttributeListSetting;
