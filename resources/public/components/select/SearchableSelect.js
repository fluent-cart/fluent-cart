class SearchableSelect {
    constructor(element, options = {}) {
        this.element = element;
        this.disabled = false;

        // Parse existing select element if it exists
        const parsedOptions = this.parseSelectElement();
        this.options = options.options || parsedOptions || [];

        const disablePlaceholder = options.placeholder === false;
        this.searchPlaceholder = '';
        this.placeholder = '';

        if (!disablePlaceholder) {
            this.placeholder = options.placeholder || this.getPlaceholderFromSelect() || 'Select an option...';
            this.searchPlaceholder = options.searchPlaceholder || 'Type to search...';
        }
        
        this.clearable = options.clearable !== false;
        this.onChange = options.onChange || (() => {
        });
        this.onSearch = options.onSearch || (() => {
        });

        // Get initial selected value from select element
        const initialSelection = this.getInitialSelection();
        this.selectedValue = initialSelection.value;
        this.selectedText = initialSelection.text;

        this.filteredOptions = [...this.options];
        this.highlightedIndex = -1;
        this.isOpen = false;
        this.searchTerm = '';

        this.init();
    }

    init() {
        this.createElements();
        this.bindEvents();
        this.render();

        // Set initial value if one was selected
        if (this.selectedValue) {
            this.input.value = this.selectedText;
        }
    }

    parseSelectElement() {
        if (this.element.tagName !== 'SELECT') return null;

        const options = [];
        const selectOptions = this.element.querySelectorAll('option');

        selectOptions.forEach(option => {
            // Skip empty options (often used as placeholders)
            if (option.value || option.textContent.trim()) {
                options.push({
                    value: option.value || option.textContent.trim(),
                    text: option.textContent.trim()
                });
            }
        });

        return options;
    }

    getPlaceholderFromSelect() {
        if (this.element.tagName !== 'SELECT') return null;

        // Check for placeholder attribute
        if (this.element.hasAttribute('data-placeholder')) {
            return this.element.getAttribute('data-placeholder');
        }

        // Check for first empty option as placeholder
        const firstOption = this.element.querySelector('option');
        if (firstOption && !firstOption.value && firstOption.textContent.trim()) {
            return firstOption.textContent.trim();
        }

        return null;
    }

    getInitialSelection() {
        if (this.element.tagName !== 'SELECT') {
            return {value: null, text: ''};
        }

        const selectedOption = this.element.querySelector('option[selected]') ||
            this.element.options[this.element.selectedIndex];

        if (selectedOption && selectedOption.value) {
            return {
                value: selectedOption.value,
                text: selectedOption.textContent.trim()
            };
        }

        return {value: null, text: ''};
    }

    createElements() {
        const isSelect = this.element.tagName === 'SELECT';
        let wrapper;

        // Check if select is already inside a fct-custom-searchable-select div
        let existingWrapper = isSelect && this.element.parentNode.classList.contains('fct-custom-searchable-select');

        let existingHiddenInput = null;
        if (existingWrapper) {
            // Use the existing wrapper
            wrapper = this.element.parentNode;

            // Remove any existing fct-custom-searchable-select-container
            const existingContainer = wrapper.querySelector('.fct-custom-searchable-select-container');
            if (existingContainer) {
                wrapper.removeChild(existingContainer);
            }
            existingHiddenInput = wrapper.querySelector('input');

        } else {
            // Create a new wrapper div
            wrapper = document.createElement('div');
            wrapper.className = 'fct-custom-searchable-select';

            if (isSelect) {
                // Replace the original select with the wrapper
                this.element.parentNode.insertBefore(wrapper, this.element);
                wrapper.appendChild(this.element);
            } else {
                // If not a select, use the element itself as wrapper
                wrapper.classList.add('custom-select-wrapper');
                this.element.appendChild(wrapper);
            }
        }

        // Store original attributes and select element
        if (isSelect) {
            this.originalElement = this.element.cloneNode(true);
            this.element.style.display = 'none'; // Hide original select
        } else {
            this.originalElement = null;
        }

        this.selectContainer = document.createElement('div');
        this.selectContainer.className = 'fct-custom-searchable-select-container';

        this.input = document.createElement('input');
        this.input.className = 'select-input';
        this.input.type = 'text';
        this.input.placeholder = this.placeholder + '*';

        this.input.addEventListener('keypress', (e) => {
            if (!this.isOpen) {
                e.preventDefault();
            }
        });

        if (this.originalElement) {
            if (this.originalElement.disabled) {
                this.disable()
            }
            if (this.originalElement.required) {
                this.input.required = true;
            }
            if (this.originalElement.name) {
                // Create a hidden input for form submission
                if (existingHiddenInput) {
                    this.hiddenInput = existingHiddenInput;
                } else {
                    this.hiddenInput = document.createElement('input');
                    wrapper.appendChild(this.hiddenInput);
                }
                this.hiddenInput.type = 'hidden';
                this.hiddenInput.name = this.originalElement.name;
            }
        }

        this.arrow = document.createElement('div');
        this.arrow.className = 'select-arrow';

        this.clearBtn = document.createElement('button');
        this.clearBtn.className = 'clear-btn';
        this.clearBtn.innerHTML = '×';
        this.clearBtn.type = 'button';

        this.dropdown = document.createElement('div');
        this.dropdown.className = 'select-dropdown';

        this.selectContainer.appendChild(this.input);
        if (this.clearable) {
            this.selectContainer.appendChild(this.clearBtn);
        }
        this.selectContainer.appendChild(this.arrow);
        this.selectContainer.appendChild(this.dropdown);

        wrapper.appendChild(this.selectContainer);

        // If we created a hidden input for form submission, sync its value
        if (this.hiddenInput) {
            this.hiddenInput.value = this.selectedValue || '';
        }

        // Keep a reference to the wrapper as the main container
        this.wrapper = wrapper;
    }


    bindEvents() {
        // Toggle dropdown on container click
        this.selectContainer.addEventListener('click', (e) => {
            if (e.target === this.clearBtn) return;
            this.toggle();
        });

        // Clear button
        this.clearBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.clear();
        });

        // Input events
        this.input.addEventListener('focus', () => {
            if (!this.isOpen) this.open();
        });

        this.input.addEventListener('input', (e) => {
            this.searchTerm = e.target.value;
            this.filter();
            this.onSearch(this.searchTerm);
        });

        this.input.addEventListener('keydown', (e) => {
            this.handleKeydown(e);
        });

        // Dropdown events
        this.dropdown.addEventListener('click', (e) => {
            const option = e.target.closest('.select-option');
            if (option && !option.classList.contains('no-options')) {
                const value = option.dataset.value;
                const text = option.textContent;
                this.select(value, text);
                this.close();
            }
        });

        // Close on outside click
        this.outsideClickHandler = (e) => {
            if (!this.element.contains(e.target)) {
                this.close();
            }
        };
        document.addEventListener('click', this.outsideClickHandler);
    }

    handleKeydown(e) {
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.highlightNext();
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.highlightPrev();
                break;
            case 'Enter':
                e.preventDefault();
                if (this.highlightedIndex >= 0 && this.filteredOptions[this.highlightedIndex]) {
                    const option = this.filteredOptions[this.highlightedIndex];
                    this.select(option.value, option.text);
                }
                break;
            case 'Escape':
                this.close();
                break;
            case 'Backspace':
                if (this.selectedValue) {
                    this.clear();
                }
                break;
        }
    }

    open() {
        this.isOpen = true;
        this.selectContainer.classList.add('open');
        this.input.placeholder = this.searchPlaceholder;
        this.highlightedIndex = -1;
        this.input.value = '';
        this.filter();

        setTimeout(() => this.input.focus(), 0);
    }

    close() {
        this.isOpen = false;
        this.selectContainer.classList.remove('open');
        this.input.placeholder = this.placeholder;
        this.searchTerm = '';

        this.input.value = this.selectedText;
        this.highlightedIndex = -1;
    }

    toggle() {
        this.isOpen ? this.close() : this.open();
    }

    filter() {
        const term = this.searchTerm.toLowerCase();
        this.filteredOptions = this.options.filter(option =>
            option.text.toLowerCase().includes(term)
        );
        this.highlightedIndex = -1;
        this.renderOptions();
    }

    highlightNext() {
        if (this.filteredOptions.length === 0) return;
        this.highlightedIndex = Math.min(this.highlightedIndex + 1, this.filteredOptions.length - 1);
        this.updateHighlight();
    }

    highlightPrev() {
        if (this.filteredOptions.length === 0) return;
        this.highlightedIndex = Math.max(this.highlightedIndex - 1, 0);
        this.updateHighlight();
    }

    updateHighlight() {
        const options = this.dropdown.querySelectorAll('.select-option:not(.no-options)');
        options.forEach((option, index) => {
            option.classList.toggle('highlighted', index === this.highlightedIndex);
        });

        if (this.highlightedIndex >= 0) {
            options[this.highlightedIndex]?.scrollIntoView({
                block: 'nearest'
            });
        }
    }

    select(value, text) {
        this.selectedValue = value;
        this.selectedText = text;
        this.input.value = text;

        if (this.hiddenInput) {
            this.hiddenInput.value = value || '';
        }
        if (this.originalElement) {
            this.originalElement.value = value || ''; // set selected option
        }


        setTimeout(() => {
            this.onChange(value, text);
            this.close()
        }, 10);

    }

    // Public API methods
    clear() {
        this.select(null, '');
        return this;
    }

    addOption(value, text) {
        const option = {value, text};
        this.options.push(option);
        if (!this.isOpen || !this.searchTerm) {
            this.filteredOptions.push(option);
        }
        this.render();
        return this;
    }

    removeOption(value) {
        this.options = this.options.filter(opt => opt.value !== value);
        this.filteredOptions = this.filteredOptions.filter(opt => opt.value !== value);

        if (this.selectedValue === value) {
            this.clear();
        }

        this.render();
        return this;
    }

    setOptions(newOptions, nameAccessor = null, valueAccessor = null) {

        if (nameAccessor || valueAccessor) {
            valueAccessor = valueAccessor ?? 'value';
            nameAccessor = nameAccessor ?? 'text';
            newOptions = newOptions.map(option => {
                return {
                    value: option[valueAccessor],
                    text: option[nameAccessor]
                };
            });
        }
        this.options = newOptions || [];
        this.filteredOptions = [...this.options];

        if (this.selectedValue && !this.options.find(opt => opt.value === this.selectedValue)) {
            this.clear();
        }

        this.render();
        return this;
    }

    getValue() {
        return this.selectedValue;
    }

    getText() {
        return this.selectedText;
    }

    getSelected() {
        return {
            value: this.selectedValue,
            text: this.selectedText
        };
    }

    setValue(value) {
        const option = this.options.find(opt => opt.value === value);
        if (option) {
            this.select(option.value, option.text);
        } else {
            this.clear();
        }
        return this;
    }

    disable() {
        this.input.disabled = true;
        this.disabled = true;
        this.selectContainer.classList.add('disabled');
        this.element.classList.add('disabled');
        return this;
    }

    enable() {
        this.input.disabled = false;
        this.disabled = false;
        this.selectContainer.classList.remove('disabled');
        this.element.classList.remove('disabled');
        return this;
    }

    render() {
        this.renderOptions();
    }

    renderOptions() {
        this.dropdown.innerHTML = '';

        if (this.filteredOptions.length === 0) {
            const noOptions = document.createElement('div');
            noOptions.className = 'select-option no-options';
            noOptions.textContent = this.searchTerm ? 'No matches found' : 'No options available';
            this.dropdown.appendChild(noOptions);
            return;
        }

        this.filteredOptions.forEach((option, index) => {
            const optionEl = document.createElement('div');
            optionEl.className = 'select-option';
            optionEl.dataset.value = option.value;
            optionEl.textContent = option.text;

            if (option.value === this.selectedValue) {
                optionEl.classList.add('selected');
            }

            this.dropdown.appendChild(optionEl);
        });
    }

    destroy() {
        document.removeEventListener('click', this.outsideClickHandler);
        this.element.innerHTML = '';
        this.selectContainer.remove();
    }
}

// Factory function for the API you requested
const customSelect = {
    init: function (element, options = {}) {
        const selectElement = typeof element === 'string' ? document.querySelector(element) : element;

        if (!selectElement) {
            throw new Error('Element not found');
        }


        return new SearchableSelect(selectElement, options);
    }
};

// Export for different module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = customSelect;
} else if (typeof define === 'function' && define.amd) {
    define(function () {
        return customSelect;
    });
} else {
    window.customSelect = customSelect;
}

export default customSelect;
