import React from 'react';
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useState, useRef, useEffect } = wp.element;

function CustomSelect({
                          options = [],
                          defaultValue = [],
                          onChange,
                          customKeys = { key: 'value', label: 'label' },
                          isMulti = false
                      }) {
    const [isOpen, setIsOpen] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const inputRef = useRef(null);
    const inputWrapper = useRef(null);
    const wrapperRef = useRef(null);
    const selectedItemsRef = useRef(null);
    const [wrapHeight, setWrapHeight] = useState('auto');

    // CLOSE on outside click
    useEffect(() => {
        selectedItemsHeight();

        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    const [selected, setSelected] = useState(
        isMulti
            ? options.filter(opt => defaultValue.includes(opt[customKeys.key]))
            : options.find(opt => opt[customKeys.key] === defaultValue) || null
    );

    const filteredOptions = options.filter(opt =>
        opt[customKeys.label].toLowerCase().includes(searchTerm.toLowerCase())
    );

    const isSelected = (option) => {
        if (!selected) return false;

        return isMulti
            ? selected.some(sel => sel[customKeys.key] === option[customKeys.key])
            : selected[customKeys.key] === option[customKeys.key];
    };

    const toggleOption = (option) => {
        setIsOpen(false);
        inputRef.current?.blur(); // 👈 remove focus from input
        if (isMulti) {
            const exists = selected.some(sel => sel[customKeys.key] === option[customKeys.key]);
            const updated = exists
                ? selected.filter(sel => sel[customKeys.key] !== option[customKeys.key])
                : [...selected, option];
            setSelected(updated);
            onChange(updated.map(opt => opt[customKeys.key]));
        } else {
            setSelected(option);
            onChange(option[customKeys.key]);
        }
        setSearchTerm('');


        // update wrap height after DOM updates
        setTimeout(() => {
            selectedItemsHeight();
        }, 0);
    };

    const selectedItemsHeight = () => {
        if (!isMulti || !selectedItemsRef.current) return;

        const { height, width } = selectedItemsRef.current.getBoundingClientRect();

        if (selectedItemsRef.current) {
            if (width < 80) {
                inputRef.current.style.paddingLeft = `${width+5}px`;
                inputWrapper.current.style.paddingTop = `0`;
                inputRef.current.style.paddingTop = `4px`;
                inputRef.current.style.height = `42px`;
            } else {
                inputRef.current.style.paddingLeft = `4px`;
            }
            if (height >= 24 && width > 80) {
                inputRef.current.style.paddingTop = `${height+5}px`;
                inputRef.current.style.height = `${height+30}px`;
            }
        }
    }

    const removeOption = (option) => {
        if (!isMulti) return;

        const updated = selected.filter(sel => sel[customKeys.key] !== option[customKeys.key]);
        setSelected(updated);
        onChange(updated.map(opt => opt[customKeys.key]));

        // update wrap height after DOM updates
        setTimeout(() => {
            selectedItemsHeight();
        }, 0);
    };

    const handleKeyDown = (e) => {
        if (isMulti && e.key === 'Backspace' && searchTerm === '' && selected.length) {
            removeOption(selected[selected.length - 1]);
        }
    };

    // const visibleOptions = filteredOptions.filter(option => {
    //     if (isMulti) {
    //         return !selected.some(sel => sel[customKeys.key] === option[customKeys.key]);
    //     }
    //     return true;
    // });


    return (
        <div className="fct-custom-select-wrap"
             ref={wrapperRef}>
            <div
                className="fct-custom-select-input-wrap"
                onClick={() => {
                    setIsOpen(prev => !prev); // 🔁 toggle dropdown open/close
                    // setIsOpen(true)
                    if (!isOpen) {
                        inputRef.current.focus();
                    } else {
                        inputRef.current?.blur();
                    }
                }}
                ref={inputWrapper}
                style={{ height: wrapHeight }}
            >
                <div className={'fct-custom-select-selected-items ' + (isMulti ? ' multi' : 'single')}
                     ref={selectedItemsRef}>
                    {isMulti
                        ? selected.map(opt => (
                            <span key={opt[customKeys.key]}>
                                {opt[customKeys.label]}
                                <button
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        removeOption(opt);
                                    }}
                                >
                                &times;
                              </button>
                            </span>
                        ))
                        : selected && (
                        <span className="fct-custom-select-selected-single-item" key={selected[customKeys.key]} onClick={() => {

                        }}>
                            {selected[customKeys.label]}

                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setSelected(null);
                                    onChange(null);

                                    // update wrap height after DOM updates
                                    setTimeout(() => {
                                        selectedItemsHeight();
                                    }, 0);
                                }}
                            >
                            &times;
                          </button>
                        </span>
                    )}
                </div>
                <input
                    ref={inputRef}
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    onKeyDown={handleKeyDown}
                    onClick={() =>{
                        // setIsOpen(true)
                    }}

                    placeholder={isMulti && selected.length ? '' : blocktranslate('Select...')}
                />
            </div>
            {isOpen && (
                <ul className="fct-custom-select-result-list">
                    {filteredOptions.length > 0 ? (
                        filteredOptions.map((option) => (
                            <li
                                key={option[customKeys.key]}
                                onClick={() => toggleOption(option)}
                                className={`rs-option ${isSelected(option) ? 'selected' : ''}`}
                            >
                                {option[customKeys.label]}
                            </li>
                        ))
                    ) : (
                        <li className="empty-options">
                            {blocktranslate('No options found')}
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}

export default CustomSelect;
