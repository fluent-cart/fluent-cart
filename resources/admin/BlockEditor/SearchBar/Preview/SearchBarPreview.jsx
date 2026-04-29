import React from "react";
import {Search} from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const SearchBarPreview = ({ attributes }) => {
  return (
    <div className="flex">
      {attributes && (
        <div className="mr-4">
          <select
            name="termId"
            className="border border-gray-300 rounded-md text-lg bg-white"
          >
            <option selected disabled>
              {blocktranslate('Category')}
            </option>
          </select>
        </div>
      )}
      <div className="fct-searchbar-block-search-wrap w-full">
          <div className="fct-input-group">
              <div className="input-icon">
                  <Search/>
              </div>
              <input
                  className="fct-input text-sm w-full bg-white !shadow-none rounded border border-solid border-gray-outline py-2 px-3.5 m-0 leading-[1.43] placeholder:text-system-light placeholder:text-[13px] focus:border-primary-500"
                  type="text"
                  placeholder={blocktranslate('Search Products...')}
              />
          </div>
      </div>
    </div>
  );
};

export default SearchBarPreview;

