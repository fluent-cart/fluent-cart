import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {Crown} from "@/BlockEditor/Icons";

const {Dashicon} = wp.components;
const {useLayoutEffect, useRef, useState} = wp.element;

/**
 * A block icon for a Pro feature while FluentCart Pro is inactive. WordPress draws one
 * icon everywhere, but only the inserter's tile gets the admin's amber Pro crown; the
 * list view, toolbar and block card keep the plain icon.
 *
 * @param {string|JSX.Element} icon A dashicon slug or an icon element.
 */
const ProBlockIcon = ({icon}) => {
    const iconRef = useRef(null);
    const [isInserterTile, setIsInserterTile] = useState(false);

    useLayoutEffect(() => {
        setIsInserterTile(!!iconRef.current?.closest('.block-editor-block-types-list__item'));
    }, []);

    return (
        <span className="fct-pro-block-icon" ref={iconRef}>
            {typeof icon === 'string' ? <Dashicon icon={icon}/> : icon}
            {isInserterTile && (
                <span className="fct-pro-block-icon-crown" role="img" aria-label={blocktranslate('Pro')}>
                    <Crown/>
                </span>
            )}
        </span>
    );
};

export default ProBlockIcon;
