@props(['history', 'tasks' => null, 'refers' => null, 'readonly' => false, 'anonymous' => false])

@php
    $botimgurl = $history->image ? asset(Storage::url($history->image)) : '/' . config('app.LLM_DEFAULT_IMG');
    $message = str_replace(["\r\n"], "\n", $history->msg);
    $visable = true;
    if (!$history->isbot && $refers) {
        foreach ($refers->where('id', '<', $history->id) as $refer) {
            $referMsg = str_replace(["\r\n"], "\n", $refer->msg);
            $referMsg = '"""' . $referMsg . '"""';

            if ($refer->id !== $history->id) {
                if ($message === $referMsg) {
                    $visable = false;
                    break;
                }
                $pos = strpos($message, $referMsg);

                if ($pos !== false) {
                    $message = substr_replace($message, "\n##### <%ref-{$refer->id}%>\n", $pos, strlen($referMsg));
                }
            }
        }
    }
@endphp

@if ($tasks && in_array($history->id, $tasks))
    <div id="history_{{ $history->id }}" class="new-page flex w-full mt-2 space-x-3 {{ $visable ? '' : 'hidden' }}">
        <div class="flex-shrink-0 h-10 w-10 rounded-full bg-black flex items-center justify-center overflow-hidden">
            @if ($anonymous)
                <div class="h-full w-full bg-black flex justify-center items-center text-white">?</div>
            @else
                <div id="{{ $history->id }}_llm_{{ $history->bot_id }}_msg" role="tooltip" access_code="{{ \App\Http\Controllers\ProfileController::BOT_PREFIX . $history->name }}"
                    class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500">
                    {{ $history->name }}
                    <div class="tooltip-arrow" data-popper-arrow></div>
                </div>
                <img data-tooltip-target="{{ $history->id }}_llm_{{ $history->bot_id }}_msg"
                    data-tooltip-placement="top" src="{{ $botimgurl }}"
                    class="h-full w-full {{ $readonly ? '' : 'cursor-pointer' }}"
                    @if (!$readonly) onclick="$('#prompt_area input[name=\'chatsTo[]\']').prop('disabled',true); $('#prompt_area .sends span').addClass('bg-red-500 hover:bg-red-600').removeClass('bg-green-500 hover:bg-green-600');$('span[data-tooltip-target=llm_{{ $history->bot_id }}_toggle]').removeClass('bg-red-500 hover:bg-red-600').addClass('bg-green-500 hover:bg-green-600');$('#chatsTo_{{ $history->bot_id }}').prop('disabled',false);$('#prompt_area').submit()" @endif>
            @endif
        </div>
        <div class="overflow-hidden">
            <div tabindex="0" hidefocus="true" class="transition-colors p-3 bg-gray-300 rounded-r-lg rounded-bl-lg"
                @if (!$anonymous && $history->isbot) onfocus="toggleHighlight(this, true)" onblur="toggleHighlight(this, false)" @endif>
                {{-- blade-formatter-disable --}}
                <div class="text-sm space-y-3 break-words msg-content {{ $history->chained ? ' chain-msg' : '' }}{{ $history->isbot ? ' bot-msg' : '' }}" id="task_{{ $history->id }}">{{ $history->msg == "* ...thinking... *" ? Cache::get("chat_" . $history->id . "_tmp", "<pending holder>") : $history->msg }}</div>
                {{-- blade-formatter-enable --}}
                <x-chat.react-buttons :history="$history" :showOnFinished='true' />
            </div>
        </div>
    </div>
@else
    <div id="history_{{ $history->id }}"
        class="new-page flex w-full mt-2 space-x-3 {{ $history->isbot ? '' : 'ml-auto justify-end' }} {{ $visable ? '' : 'hidden' }}">
        @if ($history->isbot)
            <div class="flex-shrink-0 h-10 w-10 rounded-full bg-black flex items-center justify-center overflow-hidden">
                @if ($anonymous)
                    <div class="h-full w-full bg-black flex justify-center items-center text-white">?</div>
                @else
                    <div id="{{ $history->id }}_llm_{{ $history->bot_id }}_msg" role="tooltip" access_code="{{\App\Http\Controllers\ProfileController::BOT_PREFIX . $history->name }}"
                        class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500">
                        {{ $history->name }}
                        <div class="tooltip-arrow" data-popper-arrow></div>
                    </div>
                    <img data-tooltip-target="{{ $history->id }}_llm_{{ $history->bot_id }}_msg"
                        data-tooltip-placement="top" src="{{ $botimgurl }}"
                        class="h-full w-full {{ $readonly ? '' : 'cursor-pointer' }}"
                        @if (!$readonly) onclick="$('#chatHeader div.bot-{{ $history->bot_id }}').click()" @endif>
                @endif
            </div>
        @endif
        <div class="overflow-hidden">
            <div tabindex="0"
                @if (!$anonymous && $history->isbot) onfocus="toggleHighlight(this, true)" onblur="toggleHighlight(this, false)" @endif
                class="p-3 transition-colors {{ $history->isbot ? 'bg-gray-300 rounded-r-lg rounded-bl-lg' : 'bg-cyan-500 text-white rounded-l-lg rounded-br-lg' }}">
                {{-- blade-formatter-disable --}}
                <div class="text-sm space-y-3 break-words msg-content {{$history->chained ? ' chain-msg' : ''}}{{$history->isbot ? ' bot-msg' : ''}}">{{ $message }}</div>
                {{-- blade-formatter-enable --}}
                @if (!$readonly)
                    <x-chat.react-buttons :history="$history" :showOnFinished='false' />
                @endif
            </div>
        </div>
        @if (!$history->isbot)
            <div
                class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center overflow-hidden">
                {{ $readonly ? 'User' : mb_substr(request()->user()->name, 0, 1, 'UTF-8') }}
            </div>
        @endif
    </div>
@endif
<div id="tmp_{{ $history->id }}" class="hidden">{{ $history->msg }}</div>

<script>
    histories[{{ $history->id }}] = $("#tmp_{{ $history->id }}").text();
    $("#tmp_{{ $history->id }}").remove();
    chatroomFormatter($("#history_{{ $history->id }}"))
</script>
