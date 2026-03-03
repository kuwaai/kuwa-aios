@props(['name'])

<div id="exportModal" tabindex="-1" aria-hidden="true"
    data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-2xl max-h-full">
        <!-- Modal content -->
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
            <!-- Modal header -->
            <div class="flex items-start justify-between p-4 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                    {{ __('chat.header.export') }}
                </h3>
                <button type="button"
                    class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white"
                    data-modal-hide="exportModal">
                    <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <!-- Modal body -->
            <div class="p-6">
                <div class="flex items-center space-x-2">
                    <label class="block text-black dark:text-white" for="link">
                        {{ __('room.label.share_link') }}
                    </label>
                    <p id="copiedAlert" class="text-sm text-gray-600 dark:text-green-400" style="display: none;">
                        {{ __('chat.hint.copied') }}</p>
                </div>
                <div class="flex">
                    <input name="link" onclick="copyShare($(this), $('#copiedAlert'))"
                        class="block w-full text-black dark:text-white bg-gray-200 transition-colors dark:bg-gray-600 border border-gray-200 rounded-l-lg py-3 px-4 leading-tight focus:outline-none border-0"
                        id="link"
                        value="{{ request()->routeIs('chat.*') ? route('chat.share', request()->route('chat_id')) : route('room.share', request()->route('room_id')) }}"
                        autocomplete="off" readonly>
                    <a data-modal-hide="exportModal"
                        href="{{ request()->routeIs('chat.*') ? route('chat.share', request()->route('chat_id')) : route('room.share', request()->route('room_id')) }}"
                        target="_blank"
                        class="px-4 flex justify-center items-center rounded-r-lg bg-green-500 hover:bg-green-600 text-white">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
                <label class="text-black dark:text-white" for="export_json">{{ __('chat.label.JSON') }}</label>
                <textarea id="export_json" rows="5" readonly
                    class="w-full pl-4 pr-12 py-2 rounded text-black scrollbar dark:text-white placeholder-black dark:placeholder-white bg-gray-200 dark:bg-gray-600 border border-gray-300 focus:outline-none shadow-none border-none focus:ring-0 focus:border-transparent rounded-l-md resize-none"></textarea>

                <label class="text-black dark:text-white" for="export_tsv">{{ __('chat.label.TSV') }}</label>
                <textarea id="export_tsv" rows="5" readonly
                    class="w-full pl-4 pr-12 py-2 rounded text-black scrollbar dark:text-white placeholder-black dark:placeholder-white bg-gray-200 dark:bg-gray-600 border border-gray-300 focus:outline-none shadow-none border-none focus:ring-0 focus:border-transparent rounded-l-md resize-none"></textarea>
                <a id="download_holder_json" style="display:none;" download="{{ $name . '.json' }}"></a>
                <a id="download_holder_tsv" style="display:none;" download="{{ $name . '.txt' }}"></a>
            </div>
            <!-- Modal footer -->
            <div class="flex items-center p-6 space-x-2 border-t border-gray-200 rounded-b dark:border-gray-600">
                <div id="export_json_btn" role="tooltip"
                    class="absolute z-10 inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm tooltip dark:bg-gray-600 opacity-0 invisible"
                    style="position: absolute; inset: auto auto 0px 0px; margin: 0px; transform: translate3d(352.8px, -108px, 0px);"
                    data-popper-placement="top">
                    {{ __('chat.tooltip.json_btn') }}
                    <div class="tooltip-arrow" data-popper-arrow=""
                        style="position: absolute; left: 0px; transform: translate3d(89.6px, 0px, 0px);"></div>
                </div>
                <button data-modal-hide="exportModal" data-tooltip-target="export_json_btn"
                    onclick='$("#download_holder_json").attr("href",window.URL.createObjectURL(new Blob([$("#export_json").val()], { type: "text/plain" }))); $("#download_holder_json")[0].click();'
                    class="bg-green-500 hover:bg-green-600 px-3 py-2 rounded cursor-pointer text-white">{{ __('chat.button.download.JSON') }}</button>
                <div id="export_txt_btn" role="tooltip"
                    class="absolute z-10 inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm tooltip dark:bg-gray-600 opacity-0 invisible"
                    style="position: absolute; inset: auto auto 0px 0px; margin: 0px; transform: translate3d(352.8px, -108px, 0px);"
                    data-popper-placement="top">
                    {{ __('chat.tooltip.TSV') }}
                    <div class="tooltip-arrow" data-popper-arrow=""
                        style="position: absolute; left: 0px; transform: translate3d(89.6px, 0px, 0px);"></div>
                </div>
                <button data-modal-hide="exportModal" data-tooltip-target="export_txt_btn"
                    onclick='$("#download_holder_tsv").attr("href",window.URL.createObjectURL(new Blob([$("#export_tsv").val()], { type: "text/plain" }))); $("#download_holder_tsv")[0].click();'
                    class="bg-green-500 hover:bg-green-600 px-3 py-2 rounded cursor-pointer text-white">{{ __('chat.button.download.TXT') }}</button>
                <a data-tooltip-target="export_pdf_btn" target="_blank"
                    href="{{ route('room.export_pdf', request()->route('room_id')) }}"
                    class="bg-green-500 hover:bg-green-600 px-3 py-2 rounded cursor-pointer text-white">{{ __('chat.button.download.PDF') }}</a>
                <div id="export_pdf_btn" role="tooltip"
                    class="absolute z-10 inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm tooltip dark:bg-gray-600 opacity-0 invisible"
                    style="position: absolute; inset: auto auto 0px 0px; margin: 0px; transform: translate3d(352.8px, -108px, 0px);"
                    data-popper-placement="top">
                    {{ __('chat.tooltip.PDF') }}
                    <div class="tooltip-arrow" data-popper-arrow=""
                        style="position: absolute; left: 0px; transform: translate3d(89.6px, 0px, 0px);"></div>
                </div>
                <a data-tooltip-target="export_doc_btn" target="_blank"
                    href="{{ route('room.export_doc', request()->route('room_id')) }}"
                    class="bg-green-500 hover:bg-green-600 px-3 py-2 rounded cursor-pointer text-white">{{ __('chat.button.download.DOC') }}</a>
                <div id="export_doc_btn" role="tooltip"
                    class="absolute z-10 inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm tooltip dark:bg-gray-600 opacity-0 invisible"
                    style="position: absolute; inset: auto auto 0px 0px; margin: 0px; transform: translate3d(352.8px, -108px, 0px);"
                    data-popper-placement="top">
                    {{ __('chat.tooltip.DOC') }}
                    <div class="tooltip-arrow" data-popper-arrow=""
                        style="position: absolute; left: 0px; transform: translate3d(89.6px, 0px, 0px);"></div>
                </div>
            </div>
        </div>
    </div>
</div>
