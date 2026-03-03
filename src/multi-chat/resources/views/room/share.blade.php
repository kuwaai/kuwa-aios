<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="overflow-hidden h-full">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <script>
        (() => {
            const media = window.matchMedia('(prefers-color-scheme: dark)');
            const apply = (isDark) => document.documentElement.classList.toggle('dark', isDark);
            apply(media.matches);
            if (media.addEventListener) {
                media.addEventListener('change', (event) => apply(event.matches));
            } else {
                media.addListener((event) => apply(event.matches));
            }
        })();
    </script>

    <title>{{ App\Models\ChatRoom::findOrFail(request()->route('room_id'))->name }}</title>

    <!-- Fonts -->
    <link href="{{ asset('css/fontBunny.css') }}" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('css/font_awesome..all.min.css') }}" />
    <link href="{{ asset('css/flowbite.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('css/highlight_default.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('css/dracula.css') }}" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="{{ asset('js/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('js/marked.min.js') }}"></script>
    <script src="{{ asset('js/highlight.min.js') }}"></script>
    <script src="{{ asset('js/purify.min.js') }}"></script>
    <script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <style>
        @media print {
            .new-page {
                page-break-after: auto;

                p {
                    color: black;
                }
            }

            #chatroom {
                overflow: unset !important;
            }
        }

        #chatroom {
            overflow-y: auto;
        }
    </style>
</head>

<body class="font-sans antialiased h-full">
    <x-chat.functions />
    @php
        $result = App\Models\Bots::Join('llms', function ($join) {
            $join->on('llms.id', '=', 'bots.model_id');
        })
            ->where('llms.enabled', '=', true)
            ->wherein(
                'bots.model_id',
                DB::table('group_permissions')
                    ->join('permissions', 'group_permissions.perm_id', '=', 'permissions.id')
                    ->select(DB::raw('substring(permissions.name, 7) as model_id'), 'perm_id')
                    ->where('group_permissions.group_id', Auth::user()->group_id)
                    ->where('permissions.name', 'like', 'model_%')
                    ->get()
                    ->pluck('model_id'),
            )
            ->select(
                'llms.*',
                'bots.*',
                DB::raw('COALESCE(bots.description, llms.description) as description'),
                DB::raw('COALESCE(bots.config, llms.config) as config'),
                DB::raw('COALESCE(bots.image, llms.image) as image'),
                'llms.name as llm_name',
            )
            ->orderby('llms.order')
            ->orderby('bots.created_at')
            ->get();
        $DC = App\Models\ChatRoom::leftJoin('chats', 'chatrooms.id', '=', 'chats.roomID')
            ->where('chats.user_id', Auth::user()->id)
            ->select('chatrooms.*', DB::raw('count(chats.id) as counts'))
            ->groupBy('chatrooms.id');

        // Fetch the ordered identifiers based on `bot_id` for each database
        $DC = $DC->selectSub(function ($query) {
            if (config('database.default') == 'sqlite') {
                $query
                    ->from('chats')
                    ->selectRaw("group_concat(bot_id, ',') as identifier")
                    ->whereColumn('roomID', 'chatrooms.id')
                    ->orderByRaw('bot_id');
            } elseif (config('database.default') == 'mysql') {
                $query
                    ->from('chats')
                    ->selectRaw('group_concat(bot_id separator \',\' order by bot_id) as identifier')
                    ->whereColumn('roomID', 'chatrooms.id');
            } elseif (config('database.default') == 'pgsql') {
                $query
                    ->from('chats')
                    ->selectRaw('string_agg(bot_id::text, \',\' order by bot_id) as identifier')
                    ->whereColumn('roomID', 'chatrooms.id');
            }
        }, 'identifier');

        // Get the final result and group by the ordered identifiers
        $DC = $DC->get()->groupBy('identifier');

        try {
            if (!session('llms')) {
                $identifier = collect(Illuminate\Support\Arr::flatten($DC->toarray(), 1))
                    ->where('id', '=', request()->route('room_id'))
                    ->first()['identifier'];
                $DC = $DC[$identifier];
                $llms = App\Models\Bots::whereIn('bots.id', array_map('intval', explode(',', $identifier)))
                    ->join('llms', function ($join) {
                        $join->on('llms.id', '=', 'bots.model_id');
                    })
                    ->select(
                        'llms.*',
                        'bots.*',
                        DB::raw('COALESCE(bots.description, llms.description) as description'),
                        DB::raw('COALESCE(bots.config, llms.config) as config'),
                        DB::raw('COALESCE(bots.image, llms.image) as image'),
                    )
                    ->orderby('bots.id')
                    ->get();
            } else {
                $llms = App\Models\Bots::whereIn('bots.id', session('llms'))
                    ->Join('llms', function ($join) {
                        $join->on('llms.id', '=', 'bots.model_id');
                    })
                    ->select(
                        'llms.*',
                        'bots.*',
                        DB::raw('COALESCE(bots.description, llms.description) as description'),
                        DB::raw('COALESCE(bots.config, llms.config) as config'),
                        DB::raw('COALESCE(bots.image, llms.image) as image'),
                    )
                    ->orderby('bots.id')
                    ->get();
                $DC = $DC[implode(',', $llms->pluck('id')->toArray())];
            }
        } catch (Exception $e) {
            $llms = App\Models\Bots::whereIn('bots.id', session('llms'))
                ->Join('llms', function ($join) {
                    $join->on('llms.id', '=', 'bots.model_id');
                })
                ->select(
                    'llms.*',
                    'bots.*',
                    DB::raw('COALESCE(bots.description, llms.description) as description'),
                    DB::raw('COALESCE(bots.config, llms.config) as config'),
                    DB::raw('COALESCE(bots.image, llms.image) as image'),
                )
                ->orderby('bots.id')
                ->get();
            $DC = null;
        }
    @endphp
    <div class="flex h-full">
        <div id="histories" class="flex-1 h-full flex flex-col w-full bg-gray-200 dark:bg-gray-600 shadow-xl">
            <x-room.header :llms="$llms" :readonly="true" />
            <div id="chatroom" class="flex-1 p-4 flex flex-col-reverse scrollbar bg-gray-200 dark:bg-gray-600">
                <div>
                    @php
                        $tasks = \Illuminate\Support\Facades\Redis::lrange('usertask_' . Auth::user()->id, 0, -1);

                        $roomId = request()->route('room_id');

                        $roomId = Illuminate\Support\Facades\Request::route('room_id');

                        $botChats = App\Models\Chats::join('histories', 'chats.id', '=', 'histories.chat_id')
                            ->leftJoin('feedback', 'history_id', '=', 'histories.id')
                            ->join('bots', 'bots.id', '=', 'chats.bot_id')
                            ->Join('llms', function ($join) {
                                $join->on('llms.id', '=', 'bots.model_id');
                            })
                            ->where('isbot', true)
                            ->whereIn('chats.id', App\Models\Chats::where('roomID', $roomId)->pluck('id'))
                            ->select(
                                'histories.chained as chained',
                                'chats.id as chat_id',
                                'histories.id as id',
                                'chats.bot_id as bot_id',
                                'histories.created_at as created_at',
                                'histories.msg as msg',
                                'histories.isbot as isbot',
                                DB::raw('COALESCE(bots.description, llms.description) as description'),
                                DB::raw('COALESCE(bots.config, llms.config) as config'),
                                DB::raw('COALESCE(bots.image, llms.image) as image'),
                                DB::raw('COALESCE(bots.name, llms.name) as name'),
                                'feedback.nice',
                                'feedback.detail',
                                'feedback.flags',
                                'access_code',
                            );

                        $nonBotChats = App\Models\Chats::join('histories', 'chats.id', '=', 'histories.chat_id')
                            ->leftjoin('bots', 'bots.id', '=', 'chats.bot_id')
                            ->Join('llms', function ($join) {
                                $join->on('llms.id', '=', 'bots.model_id');
                            })
                            ->where('isbot', false)
                            ->whereIn('chats.id', App\Models\Chats::where('roomID', $roomId)->pluck('id'))
                            ->select(
                                'histories.chained as chained',
                                'chats.id as chat_id',
                                'histories.id as id',
                                'chats.bot_id as bot_id',
                                'histories.created_at as created_at',
                                'histories.msg as msg',
                                'histories.isbot as isbot',
                                DB::raw('COALESCE(bots.description, llms.description) as description'),
                                DB::raw('COALESCE(bots.config, llms.config) as config'),
                                DB::raw('COALESCE(bots.image, llms.image) as image'),
                                DB::raw('COALESCE(bots.name, llms.name) as name'),
                                DB::raw('NULL as nice'),
                                DB::raw('NULL as detail'),
                                DB::raw('NULL as flags'),
                                'access_code',
                            );

                        $mergedChats = $botChats
                            ->union($nonBotChats)
                            ->get()
                            ->sortBy(function ($chat) {
                                return [$chat->created_at, $chat->id, $chat->bot_id, -$chat->history_id];
                            });
                        $mergedMessages = [];
                        // Filter and merge the chats based on the condition
                        $filteredChats = $mergedChats->filter(function ($chat) use (&$mergedMessages) {
                            if (!$chat->isbot && !in_array($chat->msg, $mergedMessages)) {
                                // Add the message to the merged messages array
                                $mergedMessages[] = $chat->msg;
                                return true; // Keep this chat in the final result
                            } elseif ($chat->isbot) {
                                $mergedMessages = [];
                                return true; // Keep bot chats in the final result
                            }
                            return false; // Exclude duplicate non-bot chats
                        });

                        // Sort the filtered chats
                        $mergedChats = $filteredChats->sortBy(function ($chat) {
                            return [$chat->created_at, $chat->bot_id, -$chat->id];
                        });
                        $refers = $mergedChats->where('isbot', '=', true);
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
                    @foreach ($mergedChats as $history)
                        <x-chat.message :history="$history" :tasks="$tasks" :refers="$refers" :anonymous="true" />
                    @endforeach
                @else
                    @foreach ($mergedChats as $history)
                        <x-chat.message :history="$history" :tasks="$tasks" :refers="$refers" :readonly="true" />
                    @endforeach
                    @endenv
                </div>
            </div>
        </div>
    </div>
    @if (isset($print))
        <script>
            html2pdf().set({
                filename: "{{ trim(preg_replace('/_+/', '_', preg_replace('/[^a-zA-Z0-9-_]/', '_', App\Models\ChatRoom::findOrFail(request()->route('room_id'))->name)), '_') }}.pdf"
            }).from(document.body).save().then(() => {
                window.close();
            });
        </script>
    @endif
</body>

</html>
