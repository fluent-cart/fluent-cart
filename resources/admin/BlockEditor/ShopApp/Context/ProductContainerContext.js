const {createContext, useContext} = wp.element;

export const ProductContainerContext = createContext({
    simulateNoResults: false,
    simulateLoading: false,
});
