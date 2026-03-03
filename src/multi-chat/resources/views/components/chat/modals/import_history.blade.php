@props(['llms'])

<!-- resources/views/components/ImportModal.blade.php -->
<div id="importModal" tabindex="-1" aria-hidden="true"
    data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-2xl max-h-full">
        <!-- Modal content -->
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
            <!-- Modal header -->
            <div class="flex items-start justify-between p-4 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                    {{ __('chat.header.import') }}
                </h3>
                <button type="button"
                    class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white"
                    data-modal-hide="importModal">
                    <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>

            <!-- Modal body -->
            <div class="p-6 flex flex-col justify-center">
                <label for="import_file_input"
                    class="mx-auto bg-green-500 hover:bg-green-600 px-3 py-2 rounded cursor-pointer text-white">{{ __('chat.button.import_from_file') }}</label>
                <hr class="my-4 border-black border-gray-300 dark:border-gray-600" />
                <form method="post" action="{{ route('room.import') }}"
                    onsubmit="$('input[name=\'chain\']').prop('checked', $('#chain_toggle').is(':checked'))">
                    @csrf
                    @if ($llms)
                        @foreach ($llms as $llm)
                            <input id="importTo_{{ $llm->id }}" name="llm_ids[]" value="{{ $llm->id }}"
                                hidden>
                        @endforeach
                    @endif
                    @if (request()->route('room_id'))
                        <input name="room_id" value="{{ request()->route('room_id') }}" hidden>
                    @endif
                    <textarea name="history" id="import_json" rows="5" max-rows="15" oninput="adjustTextareaRows(this)"
                        placeholder="{{ __('chat.placeholder.drag_and_drop') }}"
                        class="w-full pl-4 pr-12 py-2 rounded text-black scrollbar dark:text-white placeholder-black dark:placeholder-white bg-gray-200 dark:bg-gray-600 border border-gray-300 focus:outline-none shadow-none border-none focus:ring-0 focus:border-transparent rounded-l-md resize-none"></textarea>
                    <input id="import_file_name" name="import_file_name" type='text' hidden>
                    <input type="checkbox" name="chain" class="hidden">
                </form>
                <input id="import_file_input" type='file' hidden>
            </div>
            <script>
                $(document).ready(function() {
                    // Handle the file input change event
                    $('#import_file_input').on('change', function() {
                        loadFile($(this)[0], '#import_json')
                    });
                    $('#import_json').on('drop', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        loadFile(e.originalEvent.dataTransfer, '#import_json');
                    });
                });

                function loadFile(fileInput, input) {
                    const file = fileInput.files[0];
                    $("#import_file_name").val(file.name.split(".").slice(0, -1).join("."))
                    if (file) {
                        if (file.type === 'text/plain' || file.type === 'application/json') {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                $(input).val(e.target.result);
                                adjustTextareaRows(input);
                            };
                            reader.readAsText(file);
                        } else {
                            alert('Only .txt or .json files are accepted.');
                        }
                    }
                }
            </script>
            <!-- Modal footer -->
            <div class="flex items-center p-6 space-x-2 border-t border-gray-200 rounded-b dark:border-gray-600">
                <button data-modal-hide="importModal" type="button"
                    onclick="$(this).parent().parent().find('form').submit()"
                    class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus:ring-blue-800">{{ __('chat.button.import') }}</button>
                <button data-modal-hide="importModal" type="button"
                    class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">{{ __('chat.button.cancel') }}</button>
            </div>
        </div>
    </div>
</div>
