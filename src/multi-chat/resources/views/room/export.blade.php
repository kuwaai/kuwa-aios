<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="overflow-hidden h-full bg-gray-200 dark:bg-gray-600 shadow-xl">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <title>{{ App\Models\ChatRoom::findOrFail(request()->route('room_id'))->name }}</title>
    <script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: normal;
            src: url('{{ asset('font/NotoSansTC-Thin.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: bold;
            src: url('{{ asset('font/NotoSansTC-Bold.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: 100;
            src: url('{{ asset('font/NotoSansTC-ExtraLight.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: 900;
            src: url('{{ asset('font/NotoSansTC-Black.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: 300;
            src: url('{{ asset('font/NotoSansTC-Light.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: 800;
            src: url('{{ asset('font/NotoSansTC-ExtraBold.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: 600;
            src: url('{{ asset('font/NotoSansTC-SemiBold.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: 500;
            src: url('{{ asset('font/NotoSansTC-Medium.ttf') }}') format('truetype');
        }

        @font-face {
            font-family: 'NotoSansTC';
            font-style: normal;
            font-weight: normal;
            src: url('{{ asset('font/NotoSansTC-Regular.ttf') }}') format('truetype');
        }

        body,
        html {
            margin: 0;
            width: 100%;
            height: 100%;
        }

        * {
            font-family: NotoSansTC, sans-serif !important;
        }
    </style>
</head>

<body class="antialiased h-full bg-gray-200 dark:bg-gray-600 shadow-xl">
    @php
        $Parsedown = new \Parsedown();
        $result = App\Models\Bots::Join('llms', function ($join) {
            $join->on('llms.id', '=', 'bots.model_id');
        })
            ->where('llms.enabled', '=', true)
            ->select(
                'llms.*',
                'bots.*',
                DB::raw('COALESCE(bots.description, llms.description) as description'),
                DB::raw('COALESCE(bots.config, llms.config) as config'),
                DB::raw('COALESCE(bots.image, llms.image) as image'),
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
    <div class="h-full bg-gray-200 dark:bg-gray-600 shadow-xl">
        <div class="h-full w-full">
            @if (!isset($hide_header))
                <div
                    class="bg-gray-300 dark:bg-gray-900/70 p-2 sm:p-4 text-gray-700 dark:text-white overflow-hidden w-full">
                    <div class="table w-full">
                        <p
                            style="display: table-cell; max-width: calc(100% - 60px); vertical-align: middle; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-left: 5px;">
                            @if (session('llms'))
                                {{ __('room.header.new_room') }}
                            @else
                                {{ App\Models\ChatRoom::findOrFail(request()->route('room_id'))->name }}
                            @endif
                        </p>
                        <div style="display: table-cell; width: 60px; vertical-align: top;">
                            <div class="mr-1">
                                @foreach ($llms as $llm)
                                    <div class="mx-1 h-10 w-10 rounded-full bg-black overflow-hidden">
                                        <img class="h-full w-full"
                                            src="{{ $llm->image ? asset(Storage::url($llm->image)) : '/' . config('app.LLM_DEFAULT_IMG') }}" />
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div id="chatroom" class="p-4 scrollbar bg-gray-200 dark:bg-gray-600 overflow-auto h-full">
                @php
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
                @endenv
                @foreach ($mergedChats as $history)
                    @php
                        $anonymous = App::environment('arena') ? true : false;
                        $readonly = true;
                        $botimgurl = $history->image
                            ? asset(Storage::url($history->image))
                            : '/' . config('app.LLM_DEFAULT_IMG');
                        $message = trim(str_replace(["\r\n"], "\n", $history->msg));
                        $visable = true;
                        if (!$history->isbot && $refers) {
                            foreach ($refers->where('id', '<', $history->id) as $refer) {
                                $referMsg = trim(str_replace(["\r\n"], "\n", $refer->msg));
                                $referMsg = '"""' . $referMsg . '"""';

                                if ($refer->id !== $history->id) {
                                    if ($message === $referMsg) {
                                        $visable = false;
                                        break;
                                    }
                                    $pos = strpos($message, $referMsg);

                                    if ($pos !== false) {
                                        $message = substr_replace(
                                            $message,
                                            "\n##### <%ref-{$refer->id}%>\n",
                                            $pos,
                                            strlen($referMsg),
                                        );
                                    }
                                }
                            }
                        }
                    @endphp
                    <div class="table mt-2 {{ $history->isbot ? '' : 'ml-auto' }} {{ $visable ? '' : 'hidden' }}">
                        @if (isset($same_direction) || $history->isbot)
                            <div class="h-10 w-10 mr-3" style="display: table-cell; vertical-align:top;">
                                @if ($history->isbot)
                                    @if ($anonymous)
                                        <div class="h-full w-full bg-black text-white">
                                            ?</div>
                                    @else
                                        @if (isset($no_bot_img))
                                            <div class="w-10 h-10 bg-gray-300 rounded-full overflow-hidden"
                                                style="justify-items: center; align-content: center;">
                                                {{ $history->name }}
                                            </div>
                                        @else
                                            <img src="{{ $botimgurl }}"
                                                class="h-full w-full rounded-full overflow-hidden" />
                                        @endif
                                    @endif
                                @elseif (isset($same_direction))
                                    <div class="w-10 h-10 bg-gray-300 rounded-full overflow-hidden"
                                        style="justify-items: center; align-content: center;">User</div>
                                @endif
                            </div>
                            <div style="width: 10px;display: table-cell;"></div>
                        @endif
                        <div style="display: table-cell; max-width: calc(100% - 40px);"
                            class="p-3 transition-colors {{ $history->isbot ? 'bg-gray-300 rounded-r-lg rounded-bl-lg' : 'bg-cyan-500 text-white rounded-l-lg rounded-br-lg' }}">
                            {{-- blade-formatter-disable --}}
                                    <div class="text-sm space-y-3 break-words msg-content">{!! $Parsedown->text($message) !!}</div>
                                    {{-- blade-formatter-enable --}}
                        </div>
                        @if (!isset($same_direction) && !$history->isbot)
                            <div style="width: 10px; display: table-cell;"></div>
                            <div class="h-10 w-10 ml-3" style="display: table-cell; vertical-align:top;">
                                <div class="w-10 h-10 bg-gray-300 rounded-full overflow-hidden"
                                    style="justify-items: center; align-content: center;">User</div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <script>
        html2pdf().set({
            filename: "{{ App\Models\ChatRoom::findOrFail(request()->route('room_id'))->name }}.pdf"
        }).from(document.body).save().then(() => {
            setTimeout(() => {
                window.close();
            }, 1000);
        });
    </script>
</body>

</html>