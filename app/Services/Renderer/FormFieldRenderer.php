<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;

class FormFieldRenderer
{
    public function renderField($fieldData = [])
    {

        if (empty($fieldData) || !is_array($fieldData)) {
            return;
        }

        $type = Arr::get($fieldData, 'type');


        switch ($type) {
            case 'section':
                $this->renderSection($fieldData);
                break;
            case 'sub_section':
                $fields = Arr::get($fieldData, 'fields', []);
                $atts = array_filter([
                    'class' => 'fct_form_sub_section_wrapper ' . Arr::get($fieldData, 'wrapper_class', ''),
                    'id'    => Arr::get($fieldData, 'id', '')
                ]);
                if ($fields) {
                    ?>
                    <div <?php RenderHelper::renderAtts($atts); ?>>
                    <?php
                    foreach ($fields as $field) {
                        $this->renderField($field);
                    }
                    echo '</div>';
                }
                break;
            case 'text':
            case 'email':
            case 'tel':
            case 'number':
                $this->renderSingleLineInputField($fieldData);
                break;
            case 'textarea':
                $this->renderTextareaField($fieldData);
                break;
            case 'checkbox':
                $this->renderCheckboxInputField($fieldData);
                break;
            case 'select':
                $this->renderSelectField($fieldData);
                break;
            case 'address_select':
                $this->renderAddressSelect($fieldData);
                break;
            default:
                do_action('fluent_cart/render_custom_form_field', $fieldData);
                break;
        }
    }

    public function renderAddressSelect($fieldData = [])
    {

        (new AddressSelectRenderer(
            Arr::get($fieldData, 'options', []),
            Arr::get($fieldData, 'primary_address'),
            Arr::get($fieldData, 'requirements_fields'),
            Arr::get($fieldData, 'address_type')
        ))
        ->render();
    }

    public function renderSection($sectionData = [])
    {
        if (empty($sectionData) || !is_array($sectionData)) {
            return;
        }

        $label = Arr::get($sectionData, 'heading', '');
        $fields = Arr::get($sectionData, 'fields', []);
        $sectionId = Arr::get($sectionData, 'id', '');
        $headingId = $sectionId ? $sectionId . '_heading' : '';

        $wrapperAttributes = array_filter([
            'class' => 'fct_checkout_form_section',
            'id'    => $sectionId
        ]);

        if (!empty($label) && $headingId) {
            $wrapperAttributes['role'] = 'region';
            $wrapperAttributes['aria-labelledby'] = $headingId;
        }

        if (!empty($sectionData['wrapper_atts'])) {
            $wrapperAttributes = wp_parse_args($sectionData['wrapper_atts'], $wrapperAttributes);
        }

         if (empty($fields)) {
             return;
         }
        ?>
        <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
            <?php
                if (!empty($fieldData['before_callback'])) {
                    call_user_func($fieldData['before_callback'], $fieldData);
                }
            ?>
            <?php if (!empty($label)) : ?>
                <div class="fct_form_section_header">
                    <h4 class="fct_form_section_header_label" <?php echo $headingId ? 'id="' . esc_attr($headingId) . '"' : ''; ?>>
                        <?php echo wp_kses_post($label) ?>
                    </h4>
                    <?php if (!empty($sectionData['action_callback'])) {
                        call_user_func($sectionData['action_callback'], $sectionData);
                    } ?>
                </div>
            <?php endif; ?>
            <?php if ($fields): ?>
                <div class="fct_form_section_body">
                    <div class="fct_checkout_input_group">
                        <?php
                        foreach ($fields as $field) {
                            $this->renderField($field);
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php
            if (!empty($sectionData['after_callback'])) {
                call_user_func($sectionData['after_callback'], $sectionData);
            }
            ?>
        </div>
        <?php
    }

    private function renderSingleLineInputField($fieldData)
    {
        $type = Arr::get($fieldData, 'type', 'text');
        $fieldId = Arr::get($fieldData, 'id', '');
        $isRequired = Arr::get($fieldData, 'required', '') === 'yes';

        $inputAttributes = array_filter([
            'type'         => $type,
            'class'        => 'fct-input fct-input-' . $type,
            'value'        => Arr::get($fieldData, 'value', ''),
            'placeholder'  => Arr::get($fieldData, 'placeholder', ''),
            'id'           => $fieldId,
            'name'         => Arr::get($fieldData, 'name', ''),
            'autocomplete' => Arr::get($fieldData, 'autocomplete', ''),
            'disabled'     => Arr::get($fieldData, 'disabled', '') === true ? 'disabled' : '',
        ]);

        // Add required and aria-required together
        if ($isRequired) {
            $inputAttributes['required'] = 'required';
            $inputAttributes['aria-required'] = 'true';
        }

        // Only add aria-label if it is not empty
        if (!empty($fieldData['aria-label'])) {
            $inputAttributes['aria-label'] = Arr::get($fieldData, 'aria-label', '');
        }

        if (!empty($fieldData['extra_atts'])) {
            $inputAttributes = wp_parse_args($inputAttributes, $fieldData['extra_atts']);
        }

        $label = Arr::get($fieldData, 'label', '');

        $wrapperAttributes = array_filter([
            'class' => 'fct_input_wrapper fct_input_wrapper_' . $type,
            'id'    => 'fct_wrapper_' . $fieldId
        ]);

        if (!empty($fieldData['wrapper_atts'])) {
            $wrapperAttributes = wp_parse_args($fieldData['wrapper_atts'], $wrapperAttributes);
        }
        ?>
        <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
            <?php
            if (!empty($fieldData['before_callback'])) {
                call_user_func($fieldData['before_callback'], $fieldData);
            }
            ?>
            <?php if (!empty($label)) : ?>
                <label for="<?php echo esc_attr($fieldId) ?>" class="fct_input_label fct_input_label_<?php echo esc_attr($type) ?>">
                    <?php echo wp_kses_post($label) ?>
                </label>
            <?php endif; ?>
            <input <?php RenderHelper::renderAtts($inputAttributes) ?> />
            <?php
            if (!empty($fieldData['after_callback'])) {
                call_user_func($fieldData['after_callback'], $fieldData);
            }
            ?>
        </div>
        <?php
    }

    private function renderTextareaField($fieldData)
    {
        $fieldId = Arr::get($fieldData, 'id', '');
        $isRequired = Arr::get($fieldData, 'required', '') === 'yes';

        $inputAttributes = array_filter([
            'type'         => 'textarea',
            'class'        => 'fct-input fct-input-textarea',
            'placeholder'  => Arr::get($fieldData, 'placeholder', ''),
            'id'           => $fieldId,
            'name'         => Arr::get($fieldData, 'name', ''),
            'autocomplete' => Arr::get($fieldData, 'autocomplete', ''),
            'disabled'     => Arr::get($fieldData, 'disabled', '') === true ? 'disabled' : '',
        ]);

        // Add required and aria-required together
        if ($isRequired) {
            $inputAttributes['required'] = 'required';
            $inputAttributes['aria-required'] = 'true';
        }

        // Only add aria-label if it is not empty
        if (!empty($fieldData['aria-label'])) {
            $inputAttributes['aria-label'] = Arr::get($fieldData, 'aria-label', '');
        }

        if (!empty($fieldData['extra_atts'])) {
            $inputAttributes = wp_parse_args($inputAttributes, $fieldData['extra_atts']);
        }

        $label = Arr::get($fieldData, 'label', '');

        $wrapperAttributes = array_filter([
            'class' => 'fct_input_wrapper fct_input_wrapper_textarea',
            'id'    => 'fct_wrapper_' . $fieldId
        ]);

        if (!empty($fieldData['wrapper_atts'])) {
            $wrapperAttributes = wp_parse_args($fieldData['wrapper_atts'], $wrapperAttributes);
        }
        ?>
        <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
            <?php
            if (!empty($fieldData['before_callback'])) {
                call_user_func($fieldData['before_callback'], $fieldData);
            }
            ?>
            <?php if (!empty($label)) : ?>
                <label for="<?php echo esc_attr($fieldId) ?>"
                       class="fct_input_label fct_input_label_textarea">
                    <?php echo wp_kses_post($label) ?>
                </label>
            <?php endif; ?>
            <textarea <?php RenderHelper::renderAtts($inputAttributes) ?>><?php echo esc_textarea(Arr::get($fieldData, 'value', '')); ?></textarea>
            <?php
            if (!empty($fieldData['after_callback'])) {
                call_user_func($fieldData['after_callback'], $fieldData);
            }
            ?>
        </div>
        <?php
    }

    private function renderCheckboxInputField($fieldData)
    {
        $checkboxValue = Arr::get($fieldData, 'checkbox_value', 'yes');
        $fieldId = Arr::get($fieldData, 'id', '');
        $isRequired = Arr::get($fieldData, 'required', '') === 'yes';

        $inputAttributes = array_filter([
            'type'     => 'checkbox',
            'class'    => 'fct-input fct-input-checkbox',
            'id'       => $fieldId,
            'name'     => Arr::get($fieldData, 'name', ''),
            'value'    => $checkboxValue,
            'checked' => Arr::get($fieldData, 'value', '') == $checkboxValue ? 'checked' : '',
            'disabled' => Arr::get($fieldData, 'disabled', '') === true ? 'disabled' : '',
        ]);

        // Add required and aria-required together
        if ($isRequired) {
            $inputAttributes['required'] = 'required';
            $inputAttributes['aria-required'] = 'true';
        }

        if (!empty($fieldData['extra_atts'])) {
            $inputAttributes = wp_parse_args($inputAttributes, $fieldData['extra_atts']);
        }

        $label = Arr::get($fieldData, 'label', '');

        $wrapperAttributes = array_filter([
            'class' => 'fct_input_wrapper fct_input_wrapper_textarea',
            'id'    => 'fct_wrapper_' . $fieldId
        ]);

        if (!empty($fieldData['wrapper_atts'])) {
            $wrapperAttributes = wp_parse_args($fieldData['wrapper_atts'], $wrapperAttributes);
        }
        ?>
        <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
            <?php
                if (!empty($fieldData['before_callback'])) {
                    call_user_func($fieldData['before_callback'], $fieldData);
                }
            ?>
            <label for="<?php echo esc_attr(Arr::get($fieldData, 'id', '')); ?>" class="fct_input_label fct_input_label_textarea">
                <input <?php RenderHelper::renderAtts($inputAttributes) ?> />
                <?php echo wp_kses_post($label) ?>
            </label>
            <?php
            if (!empty($fieldData['after_callback'])) {
                call_user_func($fieldData['after_callback'], $fieldData);
            }
            ?>
        </div>
        <?php
    }

    private function renderSelectField($fieldData)
    {
        $fieldId = Arr::get($fieldData, 'id', '');
        $isRequired = Arr::get($fieldData, 'required', '') === 'yes';

        $inputAttributes = array_filter([
            'class'    => 'fct-input fct-input-select',
            'id'       => $fieldId,
            'name'     => Arr::get($fieldData, 'name', ''),
            'disabled' => Arr::get($fieldData, 'disabled', '') === true ? 'disabled' : '',
        ]);

        // Add required and aria-required together
        if ($isRequired) {
            $inputAttributes['required'] = 'required';
            $inputAttributes['aria-required'] = 'true';
        }

        if (!empty($fieldData['extra_atts'])) {
            $inputAttributes = wp_parse_args($inputAttributes, $fieldData['extra_atts']);
        }

        $label = Arr::get($fieldData, 'label', '');
        $options = Arr::get($fieldData, 'options', []);

        $wrapperAttributes = array_filter([
            'class' => 'fct_input_wrapper fct_input_wrapper_select',
            'id'    => 'fct_wrapper_' . $fieldId
        ]);

        if (!empty($fieldData['wrapper_atts'])) {
            $wrapperAttributes = wp_parse_args($fieldData['wrapper_atts'], $wrapperAttributes);
        }
        $value = Arr::get($fieldData, 'value', '');
        ?>
        <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
            <?php
                if (!empty($fieldData['before_callback'])) {
                    call_user_func($fieldData['before_callback'], $fieldData);
                }
            ?>
            <?php if (!empty($label)) : ?>
                <label for="<?php echo esc_attr($fieldId) ?>"
                       class="fct_input_label fct_input_label_select">
                    <?php echo wp_kses_post($label) ?>
                </label>
            <?php endif; ?>
            <select <?php RenderHelper::renderAtts($inputAttributes) ?>>
                <?php foreach ($options as $option): ?>
                    <option
                        value="<?php echo esc_attr(Arr::get($option, 'value')); ?>" <?php selected($value, Arr::get($option, 'value')); ?>>
                        <?php echo esc_html(Arr::get($option, 'name')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php
            if (!empty($fieldData['after_callback'])) {
                call_user_func($fieldData['after_callback'], $fieldData);
            }
            ?>
        </div>
        <?php
    }
}
