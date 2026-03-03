{{-- The "Create Chatroom" popup --}}

@props(['result', 'sorting_methods', 'llms' => null])

<div id="create-model-modal" data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    tabindex="-1" aria-hidden="true"
    class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-xl flex h-full overflow-hidden">
        <!-- Modal content -->
        <div
            class="relative bg-white border border-gray-200 rounded-lg shadow-2xl dark:bg-gray-700 dark:border-gray-600 overflow-hidden max-h-full flex flex-col w-full">
            <button type="button"
                class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-800 dark:hover:text-white"
                data-modal-hide="create-model-modal">
                <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"
                    xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                        clip-rule="evenodd"></path>
                </svg>
                <span class="sr-only">Close modal</span>
            </button>
            <!-- Modal header -->
            <div class="px-6 py-4 border-b rounded-t dark:border-gray-600">
                <h3 class="text-base font-semibold text-gray-900 lg:text-xl dark:text-white">
                    {{ __('room.header.create_room') }}
                </h3>
            </div>
            <!-- Modal body -->
            <form method="post" action="{{ route('room.new') }}" class="p-6 overflow-hidden flex-1 flex-col flex"
                id="create_room" onsubmit="return checkForm()">
                @csrf
                <div class="flex justify-between">
                    <p class="inline-block text-sm font-normal text-gray-500 dark:text-gray-400"
                        style="line-height: 1.5rem;">
                        {{ __('room.placeholder.model') }}
                    </p>

                    <x-sorted-list.control-menu :$sorting_methods
                        btn_class="rounded-md text-sm font-normal text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100" />
                </div>
                <div class="mt-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                    <div class="flex">
                        <div class="w-full">
                            <input type="search"
                                oninput="filterItems($(this).val(), $(this).parent().parent().parent().next(), '> li', '>label >div > div:last() >div', (el) => el.text());"
                                class="p-2.5 w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-r-lg border-l-gray-50 border-l-2 border border-gray-300 dark:bg-gray-700 dark:border-l-gray-700  dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500"
                                placeholder="{{ __('room.label.search_bot') }}" autocomplete="off">
                        </div>
                    </div>
                </div>
                <ul class="mt-2 space-y-1 overflow-auto scrollbar flex-1">
                    @foreach ($result as $LLM)
                        <x-sorted-list.item html_tag="li" :$sorting_methods :record="$LLM">
                            <input type="checkbox" name="llm[]" id="llm_create_check_{{ $LLM->id }}"
                                onchange="sortLists('bots', sorting_method)" value="{{ $LLM->id }}"
                                class="hidden peer">
                            <label for="llm_create_check_{{ $LLM->id }}"
                                class="inline-flex items-center justify-between overflow-hidden w-full p-2 text-gray-400 bg-white border-2 border-gray-200 rounded-lg cursor-pointer dark:hover:text-gray-300 dark:border-gray-700 peer-checked:border-blue-600 dark:peer-checked:border-blue-500 hover:text-gray-600 dark:peer-checked:text-gray-300 peer-checked:text-gray-600 hover:bg-gray-50 dark:text-white dark:bg-gray-600 dark:hover:bg-gray-500">
                                <div class="flex items-center overflow-hidden">
                                    <div
                                        class="flex-shrink-0 h-5 w-5 rounded-full border border-gray-400 dark:border-gray-900 bg-black flex items-center justify-center overflow-hidden">
                                        <img
                                            src="{{ $LLM->image ? asset(Storage::url($LLM->image)) : '/' . config('app.LLM_DEFAULT_IMG') }}">
                                    </div>
                                    <div class="pl-2 overflow-hidden">
                                        {{-- blade-formatter-disable --}}
                                        <div class="w-full text-lg font-semibold leading-none whitespace-pre-line break-words">{{ $LLM->name }}</div>
                                        <div class="w-full text-sm leading-none whitespace-pre-line break-words">{{ $LLM->description ? $LLM->description : __('chat.label.llm_describe_default') }}</div>
                                        {{-- blade-formatter-enable --}}
                                    </div>
                                </div>
                            </label>
                            </x-sorted.list.item>
                    @endforeach
                </ul>
                <div>
                    <div class="mt-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                        <button type="submit"
                            class="flex menu-btn flex items-center justify-center w-full h-12 dark:hover:bg-gray-500 hover:bg-gray-400 transition duration-300">
                            <p class="flex-1 text-center text-gray-700 dark:text-white">{{ __('room.button.create') }}
                            </p>
                        </button>
                    </div>
                </div>
                <span id="create_error" class="font-medium text-sm text-red-800 rounded-lg dark:text-red-400 hidden"
                    role="alert">{{ __('room.placeholder.must_select_a_bot') }}</span>
            </form>
        </div>
    </div>
</div>

<script>
    function checkForm() {
        if ($("#create_room input[name='llm[]']:checked").length > 0) {
            return true;
        } else {
            $("#create_error").show().delay(3000).fadeOut();
            return false;
        }
    }
    @if ($llms)
        $(window).on('load', function() {
            @foreach ($llms->pluck('id') as $bot_id)
                $('#llm_create_check_{{ $bot_id }}').click()
            @endforeach
        });
    @endif
</script>
