<x-app-layout>
    @php
        $bots = App\Models\Bots::getSortedBots();
        $sorting_methods = App\Models\Bots::getBotSortingMethods();
        $result = App\Models\LLMs::getLLMs(Auth()->user()->group_id);
    @endphp
    @env('arena')
    @php
        $bots = $bots->where('access_code', '!=', 'feedback');
    @endphp
    @endenv
    <x-chat.functions />
    <x-store.modal.bot-detail :result="$result" />
    @if (request()->user()->hasPerm(['tab_Manage', 'Store_create_community_bot', 'Store_create_group_bot', 'Store_create_private_bot']))
        <x-store.modal.create-bot :result="$result" />
    @endif
    @if (request()->user()->hasPerm('Room_delete_chatroom'))
        <x-room.modal.delete_confirm />
    @endif
    @if (!(request()->route('room_id') || session('llms')))
        <x-room.rooms.drawer :result="$bots" />
    @else
        @if (request()->route('room_id'))
            @if (request()->user()->hasPerm('Room_update_feedback'))
                <x-chat.modals.feedback />
            @endif
            @if (request()->user()->hasPerm('Room_read_export_chat'))
                <x-chat.modals.export_history :name="App\Models\ChatRoom::find(request()->route('room_id'))->name" />
            @endif
        @endif
        @php
            $DC = App\Models\ChatRoom::getChatRoomsWithIdentifiers(Auth::user()->id);

            try {
                if (!session('llms')) {
                    $identifier = collect(Illuminate\Support\Arr::flatten($DC->toarray(), 1))
                        ->where('id', '=', request()->route('room_id'))
                        ->first()['identifier'];
                    $DC = $DC[$identifier];
                    $llms = App\Models\Bots::getBotsFromIds(explode(',', $identifier));
                } else {
                    $llms = App\Models\Bots::getBotsFromIds(session('llms'));
                    $DC = $DC[implode(',', $llms->pluck('id')->toArray())];
                }
            } catch (Exception $e) {
                $llms = App\Models\Bots::getBotsFromIds(session('llms'));
                $DC = null;
            }
        @endphp
        <x-room.rooms.drawer/>
    @endif
    @if (request()->user()->hasPerm('Room_update_new_chat'))
        <x-room.modal.group-chat :result="$bots" :$sorting_methods :$llms />
    @endif
    @if (request()->user()->hasPerm('Room_update_import_chat'))
        <x-chat.modals.import_history :llms="$llms ?? []" />
    @endif
    <div class="flex h-full mx-auto">
        @if (count($llms) == 1 && $llms[0]->type === 'server')
            @php
                $modelfile = json_decode($llms[0]->config)->modelfile;
                $url = null;
                foreach ($modelfile as $item) {
                    if (isset($item->args)) {
                        $parts = explode(' ', $item->args);
                        if (count($parts) >= 2 && trim($parts[0]) === 'redirect_url') {
                            $url = trim($parts[1]);
                            break;
                        }
                    }
                }
            @endphp
            @if ($url)
                <div id="app-window"
                    class="flex-1 h-full flex flex-col w-full bg-gray-200 dark:bg-gray-600 shadow-xl rounded-r-lg overflow-hidden">
                    <iframe src="{{ $url }}" style="height:100%;width:100%" frameborder="0"></iframe>
                </div>
            @endif
        @else
            <div
                class="bg-white dark:bg-gray-800 text-white w-64 hidden sm:flex flex-shrink-0 relative rounded-l-lg overflow-hidden">
                <div class="p-3 flex flex-1 flex-col h-full overflow-y-auto scrollbar">
                    @if ($bots->count() == 0)
                        <div
                            class="flex-1 h-full flex flex-col w-full text-center rounded-r-lg overflow-hidden justify-center items-center text-gray-700 dark:text-white">
                            {!! __('chat.placeholder.no_llms') !!}
                        </div>
                    @else
                        <div class="mb-2">
                            <div class="border border-black dark:border-white border-1 rounded-lg flex overflow-hidden">
                                <a href="{{ route('room.home') }}"
                                    class="flex justify-center transition items-center px-4 cursor-pointer hover:bg-gray-200 text-black dark:text-white dark:hover:bg-gray-500 rounded-l-lg duration-300">←</a>
                                @if (request()->user()->hasPerm('Room_update_new_chat'))
                                    <button data-modal-target="create-model-modal" data-modal-toggle="create-model-modal"
                                        class="cursor-pointer flex w-full border-r border-1 border-black dark:border-white menu-btn flex items-center justify-center h-12 dark:hover:bg-gray-700 hover:bg-gray-200 transition duration-300">

                                        <p class="flex-1 text-center text-gray-700 dark:text-white">
                                            {{ __('room.button.create_room') }}
                                        </p>
                                    </button>
                                @endif
                                @if (request()->user()->hasPerm('Room_update_import_chat'))
                                    <button data-modal-target="importModal" data-modal-toggle="importModal"
                                        class="cursor-pointer bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 {{ request()->user()->hasPerm('Room_update_new_chat') ? 'rounded-r-lg ' : 'rounded-lg w-full' }} flex items-center justify-center transition duration-300">
                                        {{ request()->user()->hasPerm('Room_update_new_chat') ? '' : __('chat.button.header.import') }}
                                        <i class="fas fa-file-import"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                        <x-room.rooms.list/>
                    @endif
                </div>
            </div>
            <div id="histories"
                class="flex-1 h-full flex flex-col w-full bg-gray-200 dark:bg-gray-600 shadow-xl rounded-r-lg overflow-hidden">
                <x-room.header :llms="$llms" />

                <div id="chatroom" class="flex-1 p-4 overflow-y-auto flex flex-col-reverse scrollbar">
                    <div style="display:none;"
                        class="bg-red-100 border border-red-400 mt-2 text-red-700 px-4 py-3 rounded relative"
                        id="error_alert" role="alert">
                        <span class="block sm:inline"></span>
                    </div>
                    @if (!session('llms'))
                        @php
                            $tasks = \Illuminate\Support\Facades\Redis::lrange('usertask_' . Auth::user()->id, 0, -1);
                            $mergedChats = \App\Models\Chatroom::getMergedChats(request()->route('room_id'));
                            $refers = $mergedChats->where('isbot', true);
                        @endphp

                        @env('arena')
                        @php
                            $output = collect();
                            $bufferedBotMessages = [];
                            foreach ($mergedChats as $history) {
                                if ($history->isbot) {
                                    // If the current element is a bot message, buffer it
                                    $bufferedBotMessages[] = $history;
                                } else {
                                    // If the current element is not a bot message, check if there are buffered bot messages
                                    if (!empty($bufferedBotMessages)) {
                                        shuffle($bufferedBotMessages);
                                        // If there are buffered bot messages, push them into the output collection
                                        $output = $output->merge($bufferedBotMessages);

                                        // Reset the buffered bot messages array
                                        $bufferedBotMessages = [];
                                    }

                                    // Push the current non-bot message into the output collection
                                    $output->push($history);
                                }
                            }
                            if (!empty($bufferedBotMessages)) {
                                shuffle($bufferedBotMessages);
                                // If there are buffered bot messages, push them into the output collection
                                $output = $output->merge($bufferedBotMessages);

                                // Reset the buffered bot messages array
                                $bufferedBotMessages = [];
                            }
                            $mergedChats = $output;
                        @endphp
                        <div>
                            @foreach ($mergedChats as $history)
                                <x-chat.message :history="$history" :tasks="$tasks" :refers="$refers"
                                    :anonymous="true" />
                            @endforeach
                        </div>
                    @else
                        <div>
                            @foreach ($mergedChats as $history)
                                <x-chat.message :history="$history" :tasks="$tasks" :refers="$refers" />
                            @endforeach
                        </div>
                        @endenv
                    @endif

                    @if (count($llms) == 1)
                        <div class="text-black dark:text-white p-2 mb-auto">
                            <div class="relative w-[100px] h-[100px] mx-auto">
                                <div class="flex justify-center items-center h-full">
                                    <img class="rounded-full bg-black w-full h-full overflow-hidden"
                                        src="{{ $llms[0]->image ?? $llms[0]->base_image ? asset(Storage::url($llms[0]->image ?? $llms[0]->base_image)) : '/' . config('app.LLM_DEFAULT_IMG') }}">
                                </div>

                                <div class="absolute bottom-0 right-0 z-2 opacity-90">
                                    @if (time() - strtotime($llms[0]->healthy) > 300)
                                        <div class="bg-red-500 rounded-full w-6 h-6"
                                            data-updated-at="{{ $llms[0]->updated_at }}"></div>
                                    @endif
                                </div>
                            </div>
                            <p class="text-center text-sm line-clamp-2 py-1">{{ $llms[0]->name }}</p>
                        </div>
                    @endif
                </div>
                @if (
                    (request()->user()->hasPerm('Room_update_new_chat') && session('llms')) ||
                        (request()->user()->hasPerm('Room_update_send_message') && !session('llms')))
                    <div class="bg-gray-300 dark:bg-gray-500 p-4 flex flex-col overflow-y-hidden">
                        @if (request()->user()->hasPerm('Room_update_new_chat') && session('llms'))
                            <x-room.prompt-area.create :llms="$llms" :tasks="$tasks ?? null" />
                        @elseif (request()->user()->hasPerm('Room_update_send_message') && !session('llms'))
                            <x-room.prompt-area.request :llms="$llms" :tasks="$tasks ?? null" />
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
