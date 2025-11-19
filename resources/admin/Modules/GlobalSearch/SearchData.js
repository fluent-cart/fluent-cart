import Menu from './Searchables/Menu.js'
import Actions from './Searchables/Actions.js'
import Settings from './Searchables/Settings.js'
import Str from "@/utils/support/Str";

const SearchDataGroup = {
    'Menu': Menu,
    'Actions': Actions,
    'Settings': Settings
};

const SearchData = [];
Object.entries(SearchDataGroup).forEach(([key, items]) => {
    items.forEach((item, index) => {
        SearchData.push({
            ...item,
            id: `${key.toString().toLowerCase()}-${index}`,
            group: [key]
        });
    });
});
export default SearchData;