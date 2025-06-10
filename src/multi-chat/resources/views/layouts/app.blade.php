<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="overflow-hidden h-full">
@php
    $languages = config('app.LANGUAGES');
@endphp

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link href="{{ asset('css/fontBunny.css') }}" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('css/font_awesome..all.min.css') }}" />
    <link href="{{ asset('css/highlight_default.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('css/dracula.css') }}" rel="stylesheet" />
    <link href="{{ asset('css/jquery-ui.css') }}" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="{{ asset('js/skyscope_api.js') }}"></script>
    <script src="{{ asset('js/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('js/marked.min.js') }}"></script>
    <script src="{{ asset('js/highlight.min.js') }}"></script>
    <script src="{{ asset('js/purify.min.js') }}"></script>
    <script src="{{ asset('js/ace/ace.js') }}"></script>
    <script src="{{ asset('js/jquery-ui.min.js') }}"></script>

    <script>
        function chain_toggle() {
            $.get("{{ route('room.chain') }}", {
                switch: $('#chained').prop('disabled')
            }, function() {
                $('#chained').prop('disabled', !$('#chained').prop('disabled'));
                $('#chain_btn').toggleClass('bg-green-500 hover:bg-green-600 bg-red-600 hover:bg-red-700');
                $('#chain_btn').text($('#chained').prop('disabled') ? '{{ __('chat.button.unchain') }}' :
                    '{{ __('chat.button.chained') }}')
            })
        }

        function appendMessage(message, isSuccess, container_id) {
            const messageDiv = $('<div></div>').text(message).addClass(
                'mb-2 text-center p-2 rounded-lg border-2').css({
                'background-color': isSuccess ? '#d4edda' : '#f8d7da',
                'color': isSuccess ? '#155724' : '#721c24',
                'border-color': isSuccess ? '#c3e6cb' : '#f5c6cb'
            }).prependTo(container_id).hide().fadeIn();
            setTimeout(() => messageDiv.fadeOut(400, () => messageDiv.remove()), 5000);
        }

        function adjustTextareaRows(obj) {
            obj = $(obj)
            if (obj.length) {
                const textarea = obj;
                const maxRows = parseInt(textarea.attr('max-rows')) || 5;
                const lineHeight = parseInt(textarea.css('line-height'));

                textarea.attr('rows', 1);
                const contentHeight = textarea[0].scrollHeight;
                const rowsToDisplay = Math.floor(contentHeight / lineHeight);

                textarea.attr('rows', Math.min(maxRows, rowsToDisplay));
            }
        }

        // Function to remove a model by name
        function removeModel(folder_name) {
            return $.ajax({
                url: "{{ route('manage.kernel.storage.remove') }}",
                type: 'POST',
                data: {
                    folder_name: folder_name,
                    _token: "{{ csrf_token() }}"
                }
            });
        }

        // Function to remove a model by name
        function startModel(model_path) {
            return $.ajax({
                url: "{{ route('manage.kernel.storage.start') }}",
                type: 'POST',
                data: {
                    model_path: model_path,
                    _token: "{{ csrf_token() }}"
                }
            });
        }

        function modelfile_parse(data) {
            const commands = [];
            let currentCommand = {
                name: '',
                args: ''
            };
            let flags = {
                system: false,
                beforePrompt: false,
                afterPrompt: false
            };

            // Split the input data into lines
            const lines = data.trim().split('\n');

            // Iterate over each line
            lines.forEach(line => {
                // Trim whitespace from the beginning and end of the line
                line = line.trim();

                // Array of command keywords
                const commandKeywords = [
                    'FROM', 'PROCESS-BOT', 'ADAPTER', 'LICENSE', 'TEMPLATE', 'SYSTEM', 'PARAMETER', 'MESSAGE',
                    'BEFORE-PROMPT', 'AFTER-PROMPT', 'KUWABOT', 'KUWAPARAM', 'WELCOME', 'PROMPTS', 'WELCOME', 'AUTO-PROMPTS', 'START-PROMPTS',
                    'INPUT-BOT', 'INPUT-PREFIX', 'INPUT-SUFFIX',
                    'OUTPUT-BOT', 'OUTPUT-PREFIX', 'OUTPUT-SUFFIX',
                    'SCRIPT'
                ];

                // Check if the line starts with a command keyword
                if (line.startsWith('#')) {
                    // If a command is already being accumulated, push it to the commands array
                    if (currentCommand.name !== '') {
                        commands.push(currentCommand);
                    }
                    currentCommand = {
                        name: line,
                        args: ''
                    };
                } else if (commandKeywords.some(keyword => line.toUpperCase().startsWith(keyword))) {
                    // If a command is already being accumulated, push it to the commands array
                    if (currentCommand.name !== '') {
                        commands.push(currentCommand);
                    }
                    // Start a new command
                    currentCommand = {
                        name: '',
                        args: ''
                    };

                    // Split the line into command type and arguments
                    let [commandType, commandArgs] = line.split(/\s+(.+)/);
                    if (!commandArgs) commandArgs = '';

                    // Set the current command's name and arguments
                    currentCommand.name = commandType.toLowerCase();
                    currentCommand.args = commandArgs.trim();

                    if (currentCommand.name === 'system' && flags.system ||
                        currentCommand.name === 'before-prompt' && flags.beforePrompt ||
                        currentCommand.name === 'after-prompt' && flags.afterPrompt) {
                        currentCommand = {
                            name: '',
                            args: ''
                        };
                    } else {
                        // Set the flag for the current command
                        flags[currentCommand.name] = true;
                    }
                } else {
                    // If the line does not start with a command keyword, append it to the current command's arguments
                    if (currentCommand.name.startsWith('#') || currentCommand.args.length > 6 && currentCommand.args
                        .endsWith('"""') && currentCommand.args
                        .startsWith('"""')) {
                        commands.push(currentCommand);
                        // Start a new command
                        currentCommand = {
                            name: '',
                            args: ''
                        };
                        // Split the line into command type and arguments
                        let [commandType, commandArgs] = line.split(/\s+(.+)/);
                        if (!commandArgs) commandArgs = '';

                        // Set the current command's name and arguments
                        currentCommand.name = commandType.toLowerCase();
                        currentCommand.args = commandArgs.trim();
                        if (line == '') commands.push(currentCommand);
                    } else if (line == '' && currentCommand.name == '' && currentCommand.args == '') {
                        commands.push({
                            name: '',
                            args: ''
                        });
                    } else if (currentCommand.name != '') {
                        currentCommand.args += '\n' + line;
                    }

                }
            });

            // Push the last command to the commands array
            if (currentCommand.name !== '') {
                commands.push(currentCommand);
            }
            return commands;
        }

        function modelfile_to_string(array) {
            const singleArgCmdKeywords = [
                'FROM', 'PROCESS-BOT', 'ADAPTER', 'LICENSE', 'TEMPLATE', 'SYSTEM',
                'BEFORE-PROMPT', 'AFTER-PROMPT',
                'INPUT-BOT', 'INPUT-PREFIX', 'INPUT-SUFFIX',
                'OUTPUT-BOT', 'OUTPUT-PREFIX', 'OUTPUT-SUFFIX',
                'SCRIPT'
            ];

            return array.map(item => {
                if (!item) {
                    return "";
                }

                let {
                    name,
                    args
                } = item;

                if (name.startsWith('#')) {
                    return name;
                }

                name = name.trim().toUpperCase();
                args = args.trim();

                if (singleArgCmdKeywords.includes(name) && args.includes('\n')) {
                    const multi_line_quote = '"""';
                    if (!args.startsWith(multi_line_quote)) {
                        args = multi_line_quote + args;
                    }

                    if (args.substring(multi_line_quote.length).indexOf(multi_line_quote) === -1) {
                        let comment_regexp = new RegExp('(?<non_comment>[^#]*)(?<comment>#.*)?', 's');
                        let {
                            non_comment,
                            comment
                        } = comment_regexp.exec(args).groups;
                        comment ??= '';
                        args = non_comment + multi_line_quote + comment;
                    }
                }

                args = args === '' ? '' : ` ${args}`;
                return `${name}${args}`;
            }).join('\n');
        }
    </script>
</head>

<body class="font-sans antialiased h-full">
    <script id="remove-once" type="text/javascript">
        const client = new SkyscopeClient("{{ Auth::user()->tokens()->where('name', 'API_Token')->first()->token ?? '' }}",
            "{{ url('/') }}");

        $(document).ready(function() {
            $('#remove-once').remove();
        });
    </script>
    <div id="start-workers-modal"
        class="fixed inset-0 flex items-center justify-center hidden bg-black bg-opacity-50 z-50">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
            <h2 class="text-lg font-bold mb-4 dark:text-white">{{ __('workers.modal.start.title') }}</h2>
            <label for="modal-worker-count-input"
                class="block text-gray-700 dark:text-gray-300 mb-2">{{ __('workers.modal.start.label') }}</label>
            <input id="modal-worker-count-input" type="number" min="1" value='10'
                class="w-full px-4 py-2 border dark:border-gray-700 rounded shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 dark:bg-gray-700 dark:text-gray-300" />
            <div class="flex justify-end mt-4">
                <button id="confirm-start-workers"
                    class="bg-teal-500 hover:bg-teal-600 text-white px-4 py-2 rounded shadow-md">{{ __('workers.button.confirm') }}</button>
                <button id="cancel-start-workers"
                    class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded shadow-md ml-2">{{ __('workers.button.cancel') }}</button>
            </div>
        </div>
    </div>
    <script>
        $('#cancel-start-workers, #cancel-stop-workers').click(() => $(
            '#start-workers-modal, #stop-workers-modal').addClass('hidden'));
    </script>
    @if (Auth::user()->term_accepted)
        <div data-modal-target="tos_modal"></div>
    @endif
    @if (Auth::user()->announced)
        <div data-modal-target="system_announcement_modal"></div>
    @endif
    @if (\App\Models\SystemSetting::where('key', 'announcement')->first()->value != '')
        <div id="system_announcement_modal" data-modal-backdrop="static" tabindex="-1" aria-hidden="true"
            class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
            <div class="relative w-full max-w-2xl max-h-full">
                <!-- Modal content -->
                <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
                    <!-- Modal header -->
                    <div class="flex items-start justify-between p-4 border-b rounded-t dark:border-gray-600">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                            {{ __('settings.label.anno') }}
                        </h3>
                        <button type="button" onclick="$modal1.hide();"
                            class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white"
                            data-modal-hide="system_announcement_modal">
                            <svg class="w-3 h-3" aria-hidden="true" fill="none" viewBox="0 0 14 14">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                            </svg>
                            <span class="sr-only">Close modal</span>
                        </button>
                    </div>
                    <!-- Modal body -->
                    {{-- blade-formatter-disable --}}
                    <div class="content p-4 text-base leading-relaxed text-gray-500 dark:text-gray-400">{{ \App\Models\SystemSetting::where('key', 'announcement')->first()->value }}</div>
                    {{-- blade-formatter-enable --}}
                    <!-- Modal footer -->
                    <div
                        class="flex items-center p-4 space-x-2 border-t border-gray-200 rounded-b dark:border-gray-600">
                        <button data-modal-hide="system_announcement_modal" type="button" onclick="$modal1.hide();"
                            class="text-white bg-teal-700 hover:bg-teal-800 focus:ring-4 focus:outline-none focus:ring-teal-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-teal-600 dark:hover:bg-teal-700 dark:focus:ring-teal-800">{{ __('settings.button.close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if (\App\Models\SystemSetting::where('key', 'tos')->first()->value != '')
        <div id="tos_modal" data-modal-backdrop="static" tabindex="-1" aria-hidden="true"
            class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
            <div class="relative w-full max-w-2xl max-h-full">
                <!-- Modal content -->
                <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
                    <!-- Modal header -->
                    <div class="flex items-start justify-between p-4 border-b rounded-t dark:border-gray-600">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                            {{ __('settings.label.tos') }}
                        </h3>
                    </div>
                    <!-- Modal body -->
                    {{-- blade-formatter-disable --}}
                    <div class="content p-4 text-base leading-relaxed text-gray-500 dark:text-gray-400">{{ \App\Models\SystemSetting::where('key', 'tos')->first()->value }}</div>
                    {{-- blade-formatter-enable --}}
                    <!-- Modal footer -->
                    <div
                        class="flex items-center p-4 space-x-2 border-t border-gray-200 rounded-b dark:border-gray-600">
                        <button data-modal-hide="tos_modal" type="button" onclick="$modal2.hide();"
                            class="text-white bg-teal-700 hover:bg-teal-800 focus:ring-4 focus:outline-none focus:ring-teal-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center dark:bg-teal-600 dark:hover:bg-teal-700 dark:focus:ring-teal-800">{{ __('settings.button.accept') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
    <div class="flex flex-col h-full bg-slate-100 dark:bg-slate-900">
        @include('layouts.navigation')

        <!-- Page Heading -->
        @if (isset($header))
            <header class="bg-white dark:bg-gray-800 shadow">
                <div class="mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endif
        <!-- Page Content -->
        <main
            class="flex-1 overflow-y-{{ request()->routeIs('dashboard.*') || request()->routeIs('profile.edit') ? 'auto' : 'hidden' }} scrollbar">
            {{ $slot }}
        </main>
    </div>
    @if (Auth::user()->hasPerm('tab_Manage'))
        <div id="confirmUpdateModal" class="hidden fixed z-20 inset-0 overflow-y-auto bg-gray-800 bg-opacity-75">
            <div class="flex items-center justify-center min-h-screen">
                <div class="bg-white dark:bg-gray-800 rounded-lg p-6 shadow-lg max-w-md w-full">
                    <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white">
                        {{ __('settings.header.confirmUpdate') }}
                    </h2>
                    <p class="text-gray-700 dark:text-gray-300 mb-4">
                        {{ __('settings.label.reloginWarning') }}
                    </p>
                    <div class="flex justify-end">
                        <div id="cancelUpdate"
                            class="mr-2 cursor-pointer inline-block bg-gray-500 text-white py-2 px-4 rounded hover:bg-gray-600 focus:outline-none">
                            {{ __('settings.button.cancel') }}
                        </div>
                        <div id="confirmUpdate"
                            class="cursor-pointer inline-block bg-teal-500 text-white py-2 px-4 rounded hover:bg-teal-600 focus:outline-none">
                            {{ __('settings.button.confirm') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="outputModal" class="hidden fixed z-10 inset-0 overflow-y-auto bg-gray-800 bg-opacity-75">
            <div class="flex items-center justify-center min-h-screen">
                <div class="bg-white dark:bg-gray-800 rounded-lg p-6 shadow-lg max-w-3xl w-full">
                    <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white">
                        {{ __('settings.header.updateWeb') }}
                    </h2>
                    <div id="commandOutput"
                        class="bg-gray-100 scrollbar-y-auto scrollbar dark:bg-gray-700 p-4 rounded-lg text-sm h-96 overflow-x-hidden text-gray-900 dark:text-gray-200 whitespace-normal">
                    </div>
                    <div id="refreshPage" onclick='location.reload()'
                        class="mt-4 cursor-pointer hidden inline-block bg-teal-500 text-white py-2 px-4 rounded hover:bg-teal-600 focus:outline-none">
                        {{ __('settings.button.refresh') }}
                    </div>
                </div>
            </div>
        </div>
    @endif
    <script>
        @if (Auth::user()->hasPerm('tab_Manage'))
            function updateWeb() {
                $('#confirmUpdateModal').removeClass('hidden');
            }

            $('#cancelUpdate').click(function() {
                $('#confirmUpdateModal').addClass('hidden');
            });

            $('#confirmUpdate').click(function() {
                $('#confirmUpdateModal').addClass('hidden');
                $('#commandOutput').empty().append(
                    $('<pre class="whitespace-normal text-blue-500 font-semibold"></pre>').text('Updating, please wait...')
                );

                $('#outputModal').removeClass('hidden');

                const eventSource = new EventSource("{{ route('manage.setting.updateWeb') }}");
                let lastMessage = '';

                eventSource.onmessage = function(event) {
                    const response = JSON.parse(event.data);

                    lastMessage = response.output;

                    const preElement = $('<pre class="whitespace-normal"></pre>').text(response.output);
                    $('#commandOutput').append(preElement);
                };

                eventSource.onerror = function(event) {
                    eventSource.close();

                    paintLastMessage();
                };

                eventSource.onclose = function() {
                    if (!isError) {
                        paintLastMessage();
                    }
                };

                function paintLastMessage() {
                    const preElement = $('<pre class="whitespace-normal"></pre>').text(lastMessage);

                    if (lastMessage !== 'Update completed successfully!') {
                        preElement.addClass('text-red-500');
                    } else {
                        preElement.addClass('text-green-500');
                    }
                    $('#commandOutput pre:last').remove();
                    $('#commandOutput').append(preElement);
                    $("#refreshPage").removeClass('hidden');
                }

                $('#closeModal').click(function() {
                    $('#outputModal').addClass('hidden');
                    eventSource.close();
                });
            });
        @endif

        function chatroom_filter(filter, container) {
            container.find('> div').toggle(!filter);

            if (filter) {
                container.find('> div').each(function() {
                    const group = $(this);
                    const match = group.find('>form >button > div, > div > div > a > p')
                        .filter((_, chat) => $(chat).text().toLowerCase().trim().includes(filter.toLowerCase()))
                        .length > 0;
                    group.toggle(match);
                });
            }
        }

        function filterItems(filter, container, itemSelector, matchSelector, textExtractor) {
            container.find(itemSelector).toggle(!filter);

            if (filter) {
                container.find(itemSelector).each(function() {
                    const group = $(this);
                    const match = group.find(matchSelector)
                        .filter((_, element) => textExtractor($(element)).toLowerCase().trim().includes(filter
                            .toLowerCase()))
                        .length > 0;
                    group.toggle(match);
                });
            }
        }

        function markdown(node) {
            $(node).html(marked.parse(DOMPurify.sanitize(node[0], {
                ALLOWED_TAGS: [],
                ALLOWED_ATTR: []
            })));

            $(node).find('table').addClass('table-auto');
            $(node).find('table *').addClass(
                'border border-2 border-gray-500 border-solid p-1');
            $(node).find('ul').addClass('list-inside list-disc');
            $(node).find('ol').addClass('list-inside list-decimal');
            $(node).find('> p').addClass('whitespace-pre-wrap');
            $(node).find('a').addClass(
                'text-blue-700 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-500').prop('target',
                '_blank');
            $(node).find('pre code').each(function() {
                $(this).html(this.textContent)
                hljs.highlightElement($(this)[0]);
            });
            $(node).find('pre code').addClass(
                "scrollbar scrollbar-3 rounded-b-lg")
            $(node).find('pre').each(function() {
                let languageClass = '';
                $(this).children("code")[0].classList.forEach(cName => {
                    if (cName.startsWith('language-')) {
                        languageClass = cName.replace('language-', '');
                        return;
                    }
                })
                $(this).prepend(
                    `<div class="flex items-center text-gray-200 bg-gray-800 px-4 py-2 rounded-t-lg">
<span class="mr-auto">${languageClass}</span>
<button onclick="copytext(this, $(this).parent().parent().children('code').text().trim())"
class="flex items-center"><svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round"
stroke-linejoin="round" class="icon-sm" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg">
<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2">
</path>
<rect x="8" y="2" width="8" height="4" rx="1" ry="1">
</rect>
</svg>
<svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round"
stroke-linejoin="round" class="icon-sm" style="display:none;" height="1em" width="1em"
xmlns="http://www.w3.org/2000/svg">
<polyline points="20 6 9 17 4 12"></polyline>
</svg><span class="ml-2">{{ __('Copy') }}</span></button></div>`
                )
            })

            $(node).find("h5").each(function() {
                var pattern = /<%ref-(\d+)%>/;
                var match = DOMPurify.sanitize(this).replaceAll("&lt;", "<").replaceAll("&gt;", ">").match(pattern);
                if (match) {
                    var refNumber = match[1];
                    $msg = DOMPurify.sanitize($("#history_" + refNumber).find("div:eq(1) div div")[
                        0], {
                        ALLOWED_TAGS: [],
                        ALLOWED_ATTR: []
                    }).trim()
                    var $button = $("<button>")
                        .addClass("bg-gray-700 rounded p-2 hover:bg-gray-800")
                        .data("tooltip-target", "ref-tooltip")
                        .data("tooltip-placement", "top")
                        .attr("onmouseover", "refToolTip(" + refNumber + ")")
                        .attr("onclick", "scrollToRef(" + refNumber + ")");
                    $button.text($msg.substring(0, 30) + ($msg.length < 30 ? "" : "..."));

                    $(this).empty().append($button);
                }
            });
        }

        $(document).ready(function() {
            @if (\App\Models\SystemSetting::where('key', 'announcement')->first()->value != '')
                $modal1 = new Modal(document.getElementById('system_announcement_modal'), {
                    backdrop: 'static',
                    closable: true,
                    onHide: () => {
                        $.get("{{ route('announcement') }}")
                        $modal1 = new Modal(document.getElementById('system_announcement_modal'), {
                            backdrop: 'static',
                            closable: true,
                            onHide: () => {}
                        });
                    }
                });
            @endif
            @if (\App\Models\SystemSetting::where('key', 'tos')->first()->value != '')
                $modal2 = new Modal(document.getElementById('tos_modal'), {
                    backdrop: 'static',
                    closable: true,
                    onHide: () => {
                        $.get("{{ route('tos') }}")
                        @if (\App\Models\SystemSetting::where('key', 'announcement')->first()->value != '' && !Auth::user()->announced)
                            $modal1.show();
                        @endif
                        $modal2 = new Modal(document.getElementById('tos_modal'), {
                            backdrop: 'static',
                            closable: true,
                            onHide: () => {}
                        });
                    }
                });
            @endif
            @if (\App\Models\SystemSetting::where('key', 'tos')->first()->value != '' && !Auth::user()->term_accepted)
                $modal2.show();
            @endif
            @if (\App\Models\SystemSetting::where('key', 'announcement')->first()->value != '' && !Auth::user()->announced)
                $modal1.show();
            @endif

            markdown($("#system_announcement_modal .content"))
            markdown($("#tos_modal .content"))
        });
    </script>
</body>

</html>
