import Query from "@/Bits/Components/Table/BaseComponents/Query/Query";

/**
 * Static method to create a new instance of the class.
 * @return {Query} A new instance of BaseInput.
 */
export default function useQueryState(context = 'list') {
    return Query.init(context);
}
