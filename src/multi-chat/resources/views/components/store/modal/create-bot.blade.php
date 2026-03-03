@props(['result'])

<div id="create-bot-modal" data-modal-backdrop-classes="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed inset-0 z-40"
    data-modal-backdrop="static" tabindex="-1" aria-hidden="true"
    class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-full bg-black/40 dark:bg-black/60 backdrop-blur-[1px]">
    <div class="relative w-full max-w-4xl max-h-full">
        <!-- Modal content -->
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
            <button type="button"
                class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-800 dark:hover:text-white"
                data-modal-hide="create-bot-modal">
                <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"
                    xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                        clip-rule="evenodd"></path>
                </svg>
                <span class="sr-only">Close modal</span>
            </button>
            <!-- Modal header -->
            <div class="pl-6 pr-12 py-2 border-b rounded-t dark:border-gray-600 flex">
                <h3
                    class="text-xl font-semibold text-gray-900 lg:text-xl dark:text-white mr-auto text-center leading-loose">
                    {{ __('store.button.create') }}
                </h3>
            </div>
            <!-- Modal body -->
            <form method="post" action="{{ route('store.create') }}" class="p-6" id="create_room"
                enctype="multipart/form-data" onsubmit="return checkCreateBotForm()">
                @csrf
                <ul class="flex flex-wrap flex-col -mx-3 mb-2 items-center">
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full md:w-1/3 mb-2">
                            <div class="w-full px-3 mb-5">
                                <div class="flex flex-wrap -mx-3">
                                    <div class="w-full px-3 flex flex-col items-center">
                                        <label for="create-bot_image">
                                            <img id="llm_img" class="rounded-full m-auto bg-black" width="50px"
                                                height="50px" src="{{ asset('/' . config('app.LLM_DEFAULT_IMG')) }}">
                                        </label>
                                        <input id="create-bot_image" name="bot_image"
                                            onchange="change_bot_image('#llm_img', '#create-bot_image')" type="file"
                                            accept="image/*" style="display:none">
                                    </div>
                                </div>
                            </div>
                            <div class="w-full flex justify-center items-center">
                                <button id="visibility" data-dropdown-toggle="visibility_list"
                                    class="text-white rounded-l-lg bg-blue-700 hover:bg-blue-800 focus:outline-none focus:ring-blue-300 font-medium text-sm px-5 py-2.5 text-center inline-flex items-center dark:bg-blue-600 dark:hover:bg-blue-700"
                                    type="button">{{ __('store.button.community') }}</button>
                                <div id="visibility_list"
                                    class="z-10 hidden bg-gray-200 divide-y divide-gray-100 rounded-lg shadow w-60 dark:bg-gray-800 dark:divide-gray-600">
                                    <ul class="p-3 space-y-1 text-sm text-gray-700 dark:text-gray-200"
                                        aria-labelledby="visibility">
                                        @php
                                            $radioItems = [];
                                            if (request()->user()->hasPerm('tab_Manage')) {
                                                $radioItems[] = [
                                                    'id' => 'visibility_system_option',
                                                    'title' => __('store.button.system'),
                                                    'description' => __('store.placeholder.button.system'),
                                                    'value' => '0',
                                                    'checked' => request()
                                                        ->user()
                                                        ->hasPerm('Store_create_community_bot')
                                                        ? false
                                                        : true,
                                                    'onchange' =>
                                                        "this.value == 0 ? $('#visibility').text('" .
                                                        __('store.button.system') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ];
                                            }
                                            if (request()->user()->hasPerm('Store_create_community_bot')) {
                                                $radioItems[] = [
                                                    'id' => 'visibility_community_option',
                                                    'title' => __('store.button.community'),
                                                    'description' => __('store.placeholder.button.community'),
                                                    'value' => '1',
                                                    'checked' => true,
                                                    'onchange' =>
                                                        "this.value == 1 ? $('#visibility').text('" .
                                                        __('store.button.community') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ];
                                            }
                                            if (request()->user()->hasPerm('Store_create_group_bot')) {
                                                $radioItems[] = [
                                                    'id' => 'visibility_group_option',
                                                    'title' => __('store.button.groups'),
                                                    'description' => __('store.placeholder.button.groups'),
                                                    'value' => '2',
                                                    'checked' => request()
                                                        ->user()
                                                        ->hasPerm('Store_create_community_bot')
                                                        ? false
                                                        : true,
                                                    'onchange' =>
                                                        "this.value == 2 ? $('#visibility').text('" .
                                                        __('store.button.groups') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ];
                                            }
                                            if (request()->user()->hasPerm('Store_create_private_bot')) {
                                                $radioItems[] = [
                                                    'id' => 'visibility_private_option',
                                                    'title' => __('store.button.private'),
                                                    'description' => __('store.placeholder.button.private'),
                                                    'value' => '3',
                                                    'checked' => request()
                                                        ->user()
                                                        ->hasPerm('Store_create_community_bot')
                                                        ? false
                                                        : true,
                                                    'onchange' =>
                                                        "this.value == 3 ? $('#visibility').text('" .
                                                        __('store.button.private') .
                                                        "') : 1",
                                                    'name' => 'visibility',
                                                ];
                                            }
                                        @endphp

                                        @foreach ($radioItems as $item)
                                            @include('components.radio_item', $item)
                                        @endforeach
                                    </ul>
                                </div>
                                <script>
                                    $('#visibility_list input[checked]:last()').click()
                                </script>
                                <button type="button" disabled
                                    class="server-bot hidden cursor-not-allowed text-gray-400 bg-gray-300 rounded-r-lg font-medium text-sm px-5 py-2.5 text-center inline-flex items-center dark:bg-gray-600 dark:text-gray-400">{{ __('store.bot.react_buttons') }}
                                </button>
                                <button id="react_button" data-dropdown-toggle="react_button_list"
                                    class="prompt-bot agent-bot text-white rounded-r-lg bg-blue-700 hover:bg-blue-800 focus:outline-none focus:ring-blue-300 font-medium text-sm px-5 py-2.5 text-center inline-flex items-center dark:bg-blue-600 dark:hover:bg-blue-700"
                                    type="button">{{ __('store.bot.react_buttons') }}
                                </button>
                                <div id="react_button_list"
                                    class="z-10 hidden bg-gray-200 divide-y divide-gray-100 rounded-lg shadow w-72 dark:bg-gray-800 dark:divide-gray-600">
                                    <ul class="p-3 space-y-1 text-sm text-gray-700 dark:text-gray-200"
                                        aria-labelledby="react_button">

                                        @foreach (['Feedback', 'Quote', 'Other'] as $label)
                                            @php $id = strtolower($label); @endphp
                                            <li>
                                                <div class="flex rounded hover:bg-gray-100 dark:hover:bg-gray-600">
                                                    <label for="{{ $id }}"
                                                        class="inline-flex p-2 items-center w-full cursor-pointer">
                                                        <input checked id="{{ $id }}" name="react_btn[]"
                                                            value="{{ $id }}" type="checkbox"
                                                            class="sr-only peer">
                                                        <div
                                                            class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full rtl:peer-checked:after:translate-x-[-100%] peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-500 peer-checked:bg-blue-600">
                                                        </div>
                                                        <span
                                                            class="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ __('store.bot.react.allow_' . strtolower($label)) }}</span>
                                                    </label>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="w-full md:w-2/3">
                            <div class="w-full">
                                <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2"
                                    for="bot_type">
                                    {{ __('store.bot.type') }}
                                </label>
                                <select name="bot_type" id="bot_type" onchange="showBotConfigLayout()"
                                    autocomplete="off"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                                    <option value="prompt" selected>{{ __('store.bot.type.desc.prompt') }}</option>
                                    <option value="server">{{ __('store.bot.type.desc.server') }}</option>
                                    <option value="agent">{{ __('store.bot.type.desc.agent') }}</option>
                                </select>
                            </div>
                            <div class="w-full mt-2 server-bot hidden">
                                <div class="w-full">
                                    <label for="bot_server"
                                        class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('store.bot.server_url') }}</label>
                                    <input type="text" id="bot_server_url" autocomplete="off"
                                        oninput="alterBotfile('parameter', ['redirect_url', $(this).val()]); adjustTextareaRows(this)"
                                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                        placeholder="{{ __('store.bot.server_url.label') }}">
                                </div>
                            </div>
                            <div class="w-full mt-2 prompt-bot">
                                <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2"
                                    for="llm_name">
                                    {{ __('store.bot.base_model') }}
                                </label>
                                <input type="text" list="llm-list" name="llm_name" autocomplete="off"
                                    id="llm_name"
                                    oninput="change_bot_image('#llm_img', '#create-bot_image', $(this).val())"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                    placeholder="{{ __('store.bot.base_model.label') }}">
                                @once
                                    <datalist id="llm-list">
                                        @foreach ($result as $LLM)
                                            <option
                                                src="{{ $LLM->image ? asset(Storage::url($LLM->image)) : '/' . config('app.LLM_DEFAULT_IMG') }}"
                                                value="{{ $LLM->name }}" data-access-code="{{ $LLM->access_code }}">
                                            </option>
                                        @endforeach
                                    </datalist>
                                @endonce
                            </div>
                        </div>
                    </div>
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full">
                            <label for="bot_name"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('store.bot.name') }}</label>
                            <input type="text" id="bot_name" name="bot_name" autocomplete="off"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                placeholder="{{ __('store.bot.name.label') }}">
                        </div>
                    </div>
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full">
                            <label for="bot_describe"
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('store.bot.description') }}</label>
                            <input type="text" id="bot_describe" name="bot_describe" autocomplete="off"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                placeholder="{{ __('store.bot.description.label') }}">
                        </div>
                    </div>
                    <hr class="agent-bot hidden my-4 border-gray-300 dark:border-gray-600">
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="input-bot">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-input-bot">{{ __('store.bot.input_bot') }}</label>
                            <div class="flex items-center">
                                <input type="text" list="bots-list" autocomplete="off" id="bot-input_bot"
                                    oninput="alterBotfile('input-bot', $(this).val());"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                    placeholder="{{ __('store.bot.input_bot.label') }}">
                            </div>
                        </div>
                    </div>
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="input_prefix">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-input_prefix">{{ __('store.bot.input_prefix') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-input_prefix" type="text"
                                    oninput="alterBotfile('input-prefix', $(this).val()); adjustTextareaRows(this);" rows="1" max-rows="4"
                                    placeholder="{{ __('store.bot.input_prefix.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="input_suffix">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-input_suffix">{{ __('store.bot.input_suffix') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-input_suffix" type="text"
                                    oninput="alterBotfile('input-suffix', $(this).val()); adjustTextareaRows(this);" rows="1" max-rows="4"
                                    placeholder="{{ __('store.bot.input_suffix.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <hr class="agent-bot hidden my-4 border-gray-300 dark:border-gray-600">
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="process-bot">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-process-bot">{{ __('store.bot.process_bot') }}</label>
                            <div class="flex items-center">
                                <input type="text" list="bots-list" autocomplete="off" id="bot-input_bot"
                                    oninput="alterBotfile('from', $(this).val());"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                    placeholder="{{ __('store.bot.process_bot.label') }}">
                            </div>
                        </div>
                    </div>
                    <div
                        class="prompt-bot modelfile-toggle w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-system_prompt">{{ __('store.bot.system_prompt') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-system_prompt" type="text"
                                    oninput="alterBotfile('system', $(this).val()); adjustTextareaRows(this);" rows="1" max-rows="4"
                                    placeholder="{{ __('store.bot.system_prompt.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="prompt-bot agent-bot modelfile-toggle w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="before_prompt">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-before_prompt">{{ __('store.bot.before_prompt') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-before_prompt" type="text"
                                    oninput="alterBotfile('before-prompt', $(this).val()); adjustTextareaRows(this);" rows="1" max-rows="4"
                                    placeholder="{{ __('store.bot.before_prompt.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="prompt-bot agent-bot modelfile-toggle w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="after_prompt">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-after_prompt">{{ __('store.bot.after_prompt') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-after_prompt" type="text"
                                    oninput="alterBotfile('after-prompt', $(this).val()); adjustTextareaRows(this);" rows="1" max-rows="4"
                                    placeholder="{{ __('store.bot.after_prompt.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <hr class="agent-bot hidden my-4 border-gray-300 dark:border-gray-600">
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="output-bot">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-output-bot">{{ __('store.bot.output_bot') }}</label>
                            <div class="flex items-center">
                                <input type="text" list="bots-list" autocomplete="off" id="bot-output_bot"
                                    oninput="alterBotfile('output-bot', $(this).val());"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                    placeholder="{{ __('store.bot.output_bot.label') }}">
                            </div>
                        </div>
                    </div>
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="output_prefix">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-output_prefix">{{ __('store.bot.output_prefix') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-output_prefix" type="text"
                                    oninput="alterBotfile('output-prefix', $(this).val()); adjustTextareaRows(this);" rows="1" max-rows="4"
                                    placeholder="{{ __('store.bot.output_prefix.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="output_suffix">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-output_suffix">{{ __('store.bot.output_suffix') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-output_suffix" type="text"
                                    oninput="alterBotfile('output-suffix', $(this).val()); adjustTextareaRows(this);" rows="1" max-rows="4"
                                    placeholder="{{ __('store.bot.output_suffix.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <hr class="agent-bot hidden my-4 border-gray-300 dark:border-gray-600">
                    <div class="agent-bot modelfile-toggle hidden w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap"
                        id="agent_script">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-script">{{ __('store.bot.script') }}</label>
                            <div class="flex items-center">
                                <textarea id="bot-script" type="text" oninput="alterBotfile('script', $(this).val()); adjustTextareaRows(this);"
                                    rows="1" max-rows="4" placeholder="{{ __('store.bot.script.label') }}"
                                    class="bg-gray-50 border scrollbar border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="prompt-bot w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap modelfile-toggle"
                        id="knowledge">
                        <div class="w-full">
                            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white"
                                for="bot-knowledge">{{ __('store.bot.knowledge') }}</label>
                            <div class="flex items-center">
                                <input type="text" list="knowledge-list" autocomplete="off" id="bot-knowledge"
                                    oninput="alterBotfile('parameter', ['retriever_database', $(this).val()]);"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                                    placeholder="{{ __('store.bot.knowledge.label') }}">
                            </div>
                        </div>
                    </div>
                    <div class="w-full px-3 mt-2 flex justify-center items-center flex-wrap md:flex-nowrap">
                        <div class="w-full">
                            <label
                                class="block mb-2 text-sm font-medium text-gray-900 dark:text-white cursor-pointer bg-gray-200 hover:bg-gray-300 dark:bg-gray-600 dark:hover:bg-gray-800 p-2 rounded-lg"
                                onclick="toggleModelfile()" for="modelfile">{{ __('store.bot.modelfile') }}</label>
                            <div class="botfile prompt-bot server-bot agent-bot flex items-center modelfile-toggle"
                                style="display:none">
                                <textarea name="modelfile" hidden></textarea>
                                <div id="bot-modelfile-editor" class="w-full h-64"></div>
                            </div>
                        </div>
                    </div>
                </ul>
                <div>
                    <div class="border border-black dark:border-white border-1 rounded-lg overflow-hidden">
                        <button type="submit"
                            class="flex menu-btn flex items-center justify-center w-full h-12 dark:hover:bg-gray-500 hover:bg-gray-400 transition duration-300">
                            <p class="flex-1 text-center text-gray-700 dark:text-white">
                                {{ __('store.bot.button.create') }}
                            </p>
                        </button>
                    </div>
                </div>
                <span id="create_error" class="font-medium text-sm text-red-800 rounded-lg dark:text-red-400 hidden"
                    role="alert"></span>
            </form>
        </div>
    </div>
</div>

<datalist id="knowledge-list"></datalist>
<datalist id="bots-list"></datalist>

<script>
    const BOT_TYPES = ['prompt', 'server', 'agent'];

    function getCurrentBotType() {
        return $('#bot_type').val();
    }

    function getBotElements(bot_type) {
        bot_type = typeof bot_type !== "undefined" ? bot_type : getCurrentBotType();
        if (!BOT_TYPES.includes(bot_type)) {
            console.error(`Unsupported bot type "${bot_type}"`);
            return [];
        }
        return $(`.${bot_type}-bot`);
    }

    function toggleModelfile() {
        // To trigger botfile conversion.
        getBotElements().each((i, e) => $(e).find('textarea, input').trigger("input"));

        // Toggle visibility.
        getBotElements().filter('.modelfile-toggle').toggle();
    }

    function showBotConfigLayout() {
        BOT_TYPES.forEach(x => getBotElements(x).hide());
        getBotElements().filter(':not(.botfile)').show();

        let bot_type = getCurrentBotType();
        if (bot_type === "server") {
            $("#llm_name").val("Weblet");
            change_bot_image('#llm_img', '#create-bot_image', "Weblet");
        }
        if (bot_type === "agent") {
            $("#llm_name").val("Agent");
            change_bot_image('#llm_img', '#create-bot_image', "Agent");
        }
    }

    function checkCreateBotForm() {
        let error_messages = {
            bot_name: "{{ __('You must name your bot') }}",
            llm_name: "{{ __('store.placeholder.must_select_base_model') }}",
        }
        if ($("#bot_type").val() === "server") {
            $("#llm_name").val("Weblet");
            error_messages["bot_server_url"] = "{{ __('store.hint.must_enter_server_url') }}";
        }

        if ($("#bot_type").val() === "agent") {
            $("#llm_name").val("Agent");
            error_messages["bot-input_bot"] = "{{ __('store.hint.must_enter_input_bot') }}";
        }

        for (let field_id in error_messages) {
            if (!$(`#${field_id}`).val()) {
                $("#create_error").text(error_messages[field_id]);
                $("#create_error").show().delay(3000).fadeOut();
                return false;
            }
        }

        $('#create-bot-modal textarea[name=modelfile]').val(modelfile_to_string(modelfile_parse(ace.edit(
                'bot-modelfile-editor')
            .getValue())))
        ace.edit('bot-modelfile-editor').setValue(modelfile_to_string(modelfile_parse(ace.edit(
                'bot-modelfile-editor')
            .getValue())))
        ace.edit('bot-modelfile-editor').gotoLine(0);
        return true;
    }

    function alterBotfile(inst, args) {
        /*
         * Change append the line "<inst> <args>" to the botfile.
         * If the instruction existed, replace the existed one.
         * 
         * Arguments:
         *   - inst: The instruction
         *   - args: The arguments of the instruction
         */

        if (typeof args === 'string' || args instanceof String) {
            args = [args];
        }


        let is_parameter = inst.toLowerCase() === "parameter";
        if (args.join(' ').trim() === "" || (is_parameter && args.slice(1).join(' ').trim() === "")) {
            return;
        }


        parsed_modelfile = modelfile_parse(ace.edit('bot-modelfile-editor').getValue());
        new_modelfile = parsed_modelfile.filter(
            (item) =>
            item.name.toLowerCase() !== inst.toLowerCase() ||
            (is_parameter && item.args.toLowerCase().split(' ').find(e => true) !== args.find(e => true)
                .toLowerCase())
        );
        new_modelfile.push({
            name: inst,
            args: args.join(' ').trim()
        });
        ace.edit('bot-modelfile-editor').setValue(modelfile_to_string(new_modelfile));
        ace.edit('modelfile-editor').gotoLine(0);
    }

    function importBotfile(modelfile) {
        modelfile = modelfile_parse(modelfile);
        console.debug(modelfile);
        let get_bot_config = function(modelfile, k) {
            modelfile = modelfile.map((inst) => `${inst['name'].toLowerCase()} ${inst['args']}`);
            let config = modelfile.filter((inst) => inst.startsWith(k));
            if (config.length == 0) return '';
            value = config[config.length - 1].replace(k, '').trim().replace(/^"(.+)"$/, '$1');
            return value;
        }
        const prefix = 'kuwabot'
        let base_access_code = get_bot_config(modelfile, `${prefix} base`);
        let base = $(`#llm-list option[data-access-code='${base_access_code}']`).val()
        $("#create_room input[name='llm_name']").val(base);
        $("#create_room input[name='llm_name']").trigger("input");
        $("#create_room input[name='bot_name']").val(get_bot_config(modelfile, `${prefix} name`));
        $("#create_room input[name='bot_describe']").val(get_bot_config(modelfile, `${prefix} description`));

        modelfile = modelfile.filter((inst) => inst['name'].toLowerCase() != 'kuwabot');
        ace.edit('bot-modelfile-editor').setValue(modelfile_to_string(modelfile))
        ace.edit('bot-modelfile-editor').gotoLine(0);
        $('#bot-modelfile-editor textarea').trigger("change");
    }

    function importBotAvatar(content_type, base64_data) {

        function dataUrl2File(content_type, base64_data) {

            const byte_string = atob(base64_data);

            // write the bytes of the string to an ArrayBuffer
            let buffer = new ArrayBuffer(byte_string.length);
            let buffer_uint8_view = new Uint8Array(buffer);
            for (var i = 0; i < byte_string.length; i++) {
                buffer_uint8_view[i] = byte_string.charCodeAt(i);
            }
            return new File([buffer], {
                type: content_type
            });
        }

        const avatar_file = dataUrl2File(content_type, base64_data);
        const input = $("#create-bot_image")[0];
        const transfer = new DataTransfer();
        transfer.items.add(avatar_file);
        input.files = transfer.files;
        const event = new Event("change", {
            bubbles: !0,
        });
        input.dispatchEvent(event);
    }

    function importBot(files) {
        if (default_visibility == null)
            default_visibility = $("#visibility_list input").last().val()
        const reader = new FileReader()
        const encodeNonAscii = (x) => x.replace(/[^\x00-\x7F]/g, encodeURIComponent);
        const getMimeHeader = (x) => encodeNonAscii(x.substr(0, x.indexOf('\r\n\r\n')));
        const getMimeBody = (x) => x.substr(x.indexOf('\r\n\r\n') + 1);
        const handleFileLoad = function(e) {
            let header = getMimeHeader(e.target.result);
            let body = getMimeBody(e.target.result);
            header = new Headers(header.split('\r\n').map((x) => x.split(': ')));
            console.debug("Header:", header, "Body: ", body);
            const parser = new MultipartRelatedParser(header.get('Content-Type'));
            const parts = parser.read(new TextEncoder().encode(body));
            console.debug(parts);
            if (parts.length == 0) {
                console.warn("Wrong botfile format.");
                return;
            }

            let modelfile = new TextDecoder().decode(parts[0].data);
            let avatar_part = parts.filter((x) => x.headers["Content-Location"] === "/bot-avatar");

            importBotfile(modelfile);

            if (avatar_part.length != 0) {
                console.debug(avatar_part);
                let avatar_type = avatar_part[0].headers["Content-Type"];
                let avatar_base64_data = new TextDecoder().decode(avatar_part[0].data)
                importBotAvatar(avatar_type, avatar_base64_data)
            }

            const create_bot_modal = FlowbiteInstances.getInstance('Modal', 'create-bot-modal');
            $("#visibility_list input").last().click().prop("checked", true)

            $("#bot_type").val($("#llm_name").val() == "Weblet" ? "server" : ($("#llm_name").val() == "Agent" ?
                "agent" : "prompt"))
            showBotConfigLayout()
            create_bot_modal.show();
        }

        reader.onload = handleFileLoad;
        reader.readAsText(files[0]);
    }

    async function updateDataListFromAPI(apiUrl, dataListElementId) {
        try {
            // Fetch data from the API
            const response = await fetch(apiUrl);
            const data = await response.json();

            // Update the data list element
            const dataListElement = document.getElementById(dataListElementId);

            // Clear existing content
            dataListElement.innerHTML = '';

            // Add new data to the list
            data.forEach(item => {
                const listItem = document.createElement('option');
                listItem.textContent = item.name ||
                    item; // Assuming 'name' property or default to the entire item
                listItem.value = item.value || item.name || item;
                dataListElement.appendChild(listItem);
            });

            // dataListElement.focus();

        } catch (error) {
            console.error("Error fetching data:", error);
            // Handle error, display message to the user, etc.
        }
    }
    updateDataListFromAPI("/store/bots", "bots-list");
    updateDataListFromAPI("/store/knowledge", "knowledge-list");

    window.addEventListener('load', function() {
        // To prevent "Blocked aria-hidden on an element because its descendant retained focus."
        initFlowbite();
        const create_bot_modal = FlowbiteInstances.getInstance('Modal', 'create-bot-modal');
        create_bot_modal.updateOnHide(function(e) {
            console.log("model hidden")
            if (document.activeElement) {
                document.activeElement.blur();
            }
        })
    })
    var editor = ace.edit($('#bot-modelfile-editor')[0], {
        mode: "ace/mode/dockerfile",
        selectionStyle: "text"
    })
    editor.setHighlightActiveLine(true);
    // Set the onblur event
    $('#bot-modelfile-editor textarea').on('blur', function() {
        let data = modelfile_parse(ace.edit('bot-modelfile-editor').getValue());
        for (let obj of data) {
            if (obj.name === 'system') {
                $("#bot-system_prompt").val(obj.args)
            }
        }
        ace.edit('bot-modelfile-editor').setValue(modelfile_to_string(data))
        ace.edit('bot-modelfile-editor').gotoLine(0);
    });
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        editor.setTheme("ace/theme/monokai");
    }

    $('#bot-modelfile-editor textarea').on('change', function() {
        let data = modelfile_parse(ace.edit('bot-modelfile-editor').getValue());
        for (let obj of data) {
            if (obj.name === 'system') {
                $("#bot-system_prompt").val(obj.args)
            } else if (obj.name === 'before-prompt') {
                $("#bot-before_prompt").val(obj.args)
            } else if (obj.name === 'after-prompt') {
                $("#bot-after_prompt").val(obj.args)
            }
        }
    });
    @once

    function change_bot_image(bot_image_elem, user_upload_elem, new_base_bot_name) {
        /**
         * Dynamically updates the bot's displayed image based on user interaction.
         *
         * Image selection priority:
         * 1. User-uploaded image (highest)
         * 2. Base bot image (if the bot image hasn't been changed)
         * 3. Original image (lowest)
         */
        const [user_uploaded_image] = $(user_upload_elem)[0].files;
        const follow_base_bot = $(bot_image_elem).data("follow-base-bot") ?? true;
        let bot_image_uri = $(bot_image_elem).attr("src");
        if (user_uploaded_image) {
            bot_image_uri = URL.createObjectURL(user_uploaded_image);
        } else if (follow_base_bot && new_base_bot_name) {
            const fallback_image_uri = "{{ asset('/' . config('app.LLM_DEFAULT_IMG')) }}";
            bot_image_uri = $(`#llm-list option[value="${new_base_bot_name}"]`).attr("src") ?? fallback_image_uri;
        }
        $(bot_image_elem).attr("src", bot_image_uri);
    }
    @endonce
</script>
