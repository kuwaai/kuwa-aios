@props(['llms', 'tasks'])

<form method="post" action="{{ route('room.request') }}" id="prompt_area" enctype="multipart/form-data">
    @foreach ($llms as $llm)
        <input id="chatsTo_{{ $llm->id }}" name="chatsTo[]" value="{{ $llm->id }}" hidden>
    @endforeach
    <div id="recording" class="text-xs mb-[8px] leading-3" style="display:none">
        <div class="w-full h-full py-[8px] bg-blue-600 hover:bg-red-600 rounded-lg text-white text-center">00:00:00
        </div>
    </div>
    <div id="attachment" class="text-xs mb-[8px] mt-[-8px] leading-3" style="display:none">
        <button onclick="event.preventDefault();$('#upload').val(''); $(this).parent().hide();"
            class="w-full h-full py-[8px] bg-blue-600 hover:bg-red-600 rounded-lg dark:text-gray-200 text-center">+
            filename</button>
    </div>
    <div class="flex overflow-hidden">
        @csrf
        <input name="room_id" value="{{ request()->route('room_id') }}" hidden>
        <input id="chained" {{ \Session::get('chained') ?? true ? '' : 'disabled' }} hidden>
        <input id="upload" type="file" name="file" style="display: none;" onchange="uploadcheck()">
        <div class="flex flex-1 items-end justify-end flex-col overflow-hidden">
            @if (count($llms) > 1)
                <div
                    class="flex flex-1 justify-center items-center w-full overflow-hidden dark:text-white mb-2 select-none">
                    <input name="mode_track" value="0" hidden>
                    <div id="send_to_mode"
                        class="cursor-pointer text-black dark:text-white bg-gray-200 hover:bg-gray-400 dark:bg-gray-600 dark:hover:bg-gray-700 px-2 py-1 rounded-lg mr-2"
                        onclick="$(this).prev().val($(this).prev().val() == '0' ? '1' : '0');$(this).next().find('>div').each((e,i)=>{$(i).toggle()}); $(this).text($(this).next().find('>div:eq(0)').attr('style') == '' ? '{{ __('chat.label.multiple_send') }}' : '{{ __('chat.label.direct_send') }}')">
                        {{ __('chat.label.multiple_send') }}</div>
                    <div class="flex flex-1 items-center overflow-hidden">
                        <div class="flex flex-1 mr-auto overflow-auto scrollbar scrollbar-3 sends">
                            @foreach ($llms as $llm)
                                <span
                                    @env('arena')  @else data-tooltip-target="llm_{{ $llm->id }}_toggle" data-tooltip-placement="top" @endenv
                                    id="btn_{{ $llm->id }}_toggle"
                                    onclick="$('#importTo_{{ $llm->id }}').prop('disabled',(i,val)=>{return !val});$('#chatsTo_{{ $llm->id }}').prop('disabled',(i,val)=>{return !val}); $(this).toggleClass('bg-green-500 hover:bg-green-600 bg-red-500 hover:bg-red-600')"
                                    class="cursor-pointer flex py-1 px-2 mx-1 bg-green-500 hover:bg-green-600 rounded-full">
                                    <div
                                        class="inline h-5 w-5 rounded-full border border-gray-400 dark:border-gray-900 bg-black overflow-hidden">

                                        @env('arena')
                                            <div class="h-full w-full bg-black flex justify-center items-center text-white">
                                                ?</div>
                                        @else
                                            <div id="llm_{{ $llm->id }}_toggle" role="tooltip"
                                                class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-600">
                                                {{ $llm->name }}
                                                <div class="tooltip-arrow" data-popper-arrow></div>
                                            </div>
                                            <img
                                                src="{{ $llm->image ?? $llm->base_image ? asset(Storage::url($llm->image ?? $llm->base_image)) : '/' . config('app.LLM_DEFAULT_IMG') }}">
                                        @endenv
                                    </div>
                                </span>
                            @endforeach
                        </div>
                        <div class="flex flex-1 mr-auto overflow-auto scrollbar scrollbar-3" style="display:none">
                            @foreach ($llms as $llm)
                                <span
                                    @env('arena')  @else data-tooltip-target="llm_{{ $llm->id }}_direct_send" data-tooltip-placement="top" @endenv
                                    onclick="$('#prompt_area input[name=\'chatsTo[]\']').prop('disabled',true); $('#prompt_area .sends span').addClass('bg-red-500 hover:bg-red-600').removeClass('bg-green-500 hover:bg-green-600');$('span[data-tooltip-target=llm_{{ $llm->id }}_toggle]').removeClass('bg-red-500 hover:bg-red-600').addClass('bg-green-500 hover:bg-green-600');$('#chatsTo_{{ $llm->id }}').prop('disabled',false);$('#prompt_area').submit()"
                                    class="cursor-pointer flex py-1 px-2 mx-1 bg-blue-500 hover:bg-blue-600 rounded-full">
                                    <div
                                        class="inline h-5 w-5 rounded-full border border-gray-400 dark:border-gray-900 bg-black overflow-hidden">

                                        @env('arena')
                                            <div class="h-full w-full bg-black flex justify-center items-center text-white">
                                                ?</div>
                                        @else
                                            <div id="llm_{{ $llm->id }}_direct_send" role="tooltip"
                                                class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-600">
                                                {{ $llm->name }}
                                                <div class="tooltip-arrow" data-popper-arrow></div>
                                            </div>
                                            <img
                                                src="{{ $llm->image ?? $llm->base_image ? asset(Storage::url($llm->image ?? $llm->base_image)) : '/' . config('app.LLM_DEFAULT_IMG') }}">
                                        @endenv
                                    </div>
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
            <div class="flex w-full items-center relative">
                <div class="flex items-center">
                    <input type="checkbox" id="chain_toggle" class="peer appearance-none hidden" data-setting="chain"
                        name="chain" data-ui-callback="updateChainButtonUI"
                        data-text-on="{{ __('chat.button.chained') }}" data-text-off="{{ __('chat.button.unchain') }}"
                        onchange="handleSettingChange(this)">
                    <label for="chain_toggle"
                        class="whitespace-nowrap flex justify-center items-center h-[40px] text-white bg-green-500 hover:bg-green-600 px-2 py-1 rounded-l-lg cursor-pointer">
                        {{ __('chat.button.chained') }}
                    </label>
                </div>

                <label for="upload" id="upload_btn"
                    class="cursor-pointer bg-blue-600 h-[40px] w-[40px] hover:bg-blue-500 dark:hover:bg-blue-700 text-white flex items-center justify-center">
                    <i class="fas fa-paperclip"></i>
                </label>

                <textarea tabindex="0" data-id="root" placeholder="{{ __('chat.hint.prompt_area') }}" rows="1" max-rows="5"
                    oninput="adjustTextareaRows(this); toggleSendButton(this);" id="chat_input" name="input"
                    class="flex-grow pl-4 pr-8 py-2 text-black scrollbar dark:text-white placeholder-black dark:placeholder-white bg-gray-200 dark:bg-gray-600 border border-gray-300 focus:outline-none shadow-none border-none focus:ring-0 focus:border-transparent resize-none"></textarea>

                <div id="recordButton"
                    class="cursor-pointer bg-blue-600 h-[40px] w-[40px] hover:bg-blue-500 dark:hover:bg-blue-700 text-white flex items-center justify-center">
                    <i id="recordIcon" class="fa fa-microphone"></i>
                </div>

                <button type="submit" id="submit_msg" style="display:none;"
                    class="bg-blue-600 h-[40px] w-[40px] hover:bg-blue-500 dark:hover:bg-blue-700 flex items-center justify-center rounded-r-lg">
                    <i class="fas fa-paper-plane text-white"></i>
                </button>

                <button id='abort_btn' onclick="return abortGenerate()"
                    class="bg-orange-600 h-[40px] w-[40px] hover:bg-orange-500 dark:hover:bg-orange-700 flex items-center justify-center rounded-r-l">
                    <i class="far fa-stop-circle text-white"></i>
                </button>
                <input type="file" id="fileInput" style="display:none;" />
            </div>
        </div>
    </div>
    <p class="text-xs text-center mb-[-8px] mt-[8px] leading-3 dark:text-gray-200">
        {{ \App\Models\SystemSetting::where('key', 'warning_footer')->first()->value ?? '' }}</p>
</form>

<x-room.prompt-area.chat-script :llms="$llms" :tasks="$tasks" />
<script>
    async function sendRoomMessage(form) {
        const roomId = Number(form.elements.room_id.value);
        const message = form.elements.input.value;
        const chatsTo = Array.from(form.querySelectorAll('input[name="chatsTo[]"]:not(:disabled)'))
            .map(input => Number(input.value));
        const chain = form.querySelector('#chain_toggle')?.checked ?? false;
        const file = form.querySelector('#upload')?.files?.[0];

        try {
            let content = message;
            if (file) {
                const uploaded = await client.uploadFile(file);
                content = `${uploaded.result || uploaded.url || ''}\n${content}`.trim();
            }
            await client.sendMessage(roomId, content, chatsTo, [], chain);
        } catch (error) {
            console.error('Failed to send room message:', error);
            showErrorMsg(error.message || '{{ __('chat.placeholder.please_refresh') }}');
            form.reset();
            $('#chat_input').prop('readonly', false).val('');
            $('#submit_msg').show();
        }
    }

    async function abortGenerate() {
        try {
            await client.abortRoom({{ request()->route('room_id') }});
        } catch (error) {
            console.error('Failed to abort room:', error);
        }
        return false;
    }
</script>
