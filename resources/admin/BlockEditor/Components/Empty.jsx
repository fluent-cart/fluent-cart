import {Empty} from "@/BlockEditor/Icons";

export default function EmptyBlock({text}) {
    return (
        <div className="fct-pricing-block-editor-empty-block">
            <Empty/>
            <p>{text}</p>
        </div>
    );
}
