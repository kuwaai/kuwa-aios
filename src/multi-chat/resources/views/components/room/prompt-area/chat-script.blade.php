@props(['llms', 'tasks'])

<div id="connection_indicator"
    class="fixed center left-0 right-0 top-0 bottom-0 justify-center items-end flex inset-0 pointer-events-none pb-10">

    <div class="w-64 hidden sm:block"></div>
    <div class="flex flex-col pb-20">
        <div class="flex justify-center items-center"><i
                class="fas fa-hourglass-end animate-spin text-white animate-pulse bg-green-500 rounded-full p-4"></i></div>
        <span class="text-black dark:text-white animate-bounce pt-2">{{ __('room.placeholder.connecting') }}</span>
    </div>
</div>

@php
    $verify_uploaded_file = !request()->user()->hasPerm('Room_update_ignore_upload_constraint');
    if (!$verify_uploaded_file) {
        $upload_max_size_mb = PHP_INT_MAX;
        $upload_allowed_extensions = '*';
        $upload_max_file_count = -1;
    } else {
        $upload_max_size_mb = App\Models\SystemSetting::where('key', 'upload_max_size_mb')->first()->value;
        $upload_allowed_extensions = App\Models\SystemSetting::where('key', 'upload_allowed_extensions')->first()
            ->value;
        $upload_max_file_count = App\Models\SystemSetting::where('key', 'upload_max_file_count')->first()->value;
    }
@endphp
<script>
    @if (session('errorString'))
        showErrorMsg("{{ session('errorString') }}")
    @endif

    function showErrorMsg(msg) {
        $("#error_alert >span").text(msg)
        $("#error_alert").fadeIn();
        $("#upload_btn").toggleClass("bg-green-500 hover:bg-green-600 bg-red-600 hover:bg-red-700")
        $("#upload").val("");
        $("#attachment").hide();
        setTimeout(function() {
            $("#error_alert").fadeOut();
            $("#upload_btn").toggleClass("bg-green-500 hover:bg-green-600 bg-red-600 hover:bg-red-700")
        }, 3000);
    }

    function uploadcheck() {
        if (!$("#upload")[0].files || $("#upload")[0].files[0].length <= 0) return;
        if ({{ $upload_max_file_count == '0' ? 'true' : 'false' }}) {
            showErrorMsg("{{ __('chat.placeholder.upload_disabled_by_admin') }}");
            return;
        }

        if ($("#upload")[0].files[0].size > {{ $upload_max_size_mb * 2 ** 20 }}) {
            showErrorMsg("{{ __('chat.placeholder.upload_file_too_large') }}");
            return;
        }
        @if ($upload_allowed_extensions === '*')
            file_regex = /.*/;
        @else
            file_regex = /\.({{ str_replace(',', '|', $upload_allowed_extensions) }})$/;
        @endif
        if (!$("#upload")[0].files[0].name.match(file_regex)) {
            showErrorMsg("{{ __('chat.placeholder.upload_not_allowed_ext') }}");
            return;
        }
        $("#attachment").show();
        $("#attachment button").text($("#upload")[0].files[0].name)
    }
    if ($("#chat_input")) {
        $("#chat_input").prop("readonly", true)
        $("#chat_input").val("{{ __('chat.placeholder.processing') }}")
        $("#submit_msg").hide()
        if ($("#upload_btn")) $("#upload_btn").hide()
        if ($("#abort_btn")) $("#abort_btn").hide()
        if ($('#recordButton')) $("#recordButton").hide();
        $chattable = false
    }
    if ($("#prompt_area")) {
        $("#prompt_area").submit(function(event) {
            event.preventDefault();
            var allDisabled = true;
            $('input[name="chatsTo[]"]').each(function() {
                if (!$(this).prop('disabled')) {
                    allDisabled = false;
                    return false; // exit the loop if at least one input is not disabled
                }
            });

            if ($chattable && !allDisabled && (($("#chat_input").val().trim() != "") || quoted.length != 0)) {
                tmp = ""
                for (var i in quoted) {
                    @if (App::environment('arena') || $llms->count() == 1)
                        tmp += `"""${histories[quoted[i][1]]}"""\n`
                    @else
                        if (quoted.length == 1) {
                            tmp += `"""${histories[quoted[i][1]]}"""\n`
                        } else {
                            tmp +=
                                `${$("#llm_" + quoted[i][0] + "_chat").text().trim()}:"""${histories[quoted[i][1]]}"""\n`
                        }
                    @endenv
                }
                tmp = tmp.trim()
                $("#chat_input").val($("#chat_input").val().trim() + "\n" + tmp)
                this.submit();
                $chattable = false
                $("#submit_msg").hide()
                if ($("#upload_btn")) $("#upload_btn").hide()
                if (!isMac) {
                    $("#chat_input").val("{{__('chat.placeholder.processing')}}")
                }
                $("#chat_input").prop("readonly", true)
            } else if ($("#upload")[0].files.length > 0) {
                this.submit();
                $chattable = false
                $("#submit_msg").hide()
                if ($("#upload_btn")) $("#upload_btn").hide()
                if (!isMac) {
                    $("#chat_input").val("{{ __('chat.placeholder.processing') }}")
                }
                $("#chat_input").prop("readonly", true)
            } else {
                if ($("#chat_input").val().trim() == "") {
                    $("#error_alert >span").text(
                        "{{ __('chat.placeholder.send.empty') }}")
                } else if (!$chattable) {
                    $("#error_alert >span").text(
                        "{{ __('chat.placeholder.send.still_processing') }}")
                } else if (allDisabled) {
                    $("#error_alert >span").text(
                        "{{ __('chat.placeholder.must_select_llm') }}")
                } else {
                    $("#error_alert >span").text("{{ __('chat.placeholder.please_refresh') }}")
                }
                $("#error_alert").fadeIn();
                setTimeout(function() {
                    $("#error_alert").fadeOut();
                }, 3000);
            }
        })
    }
    let finsihed = false;

    function connect() {
        if ($("#abort_btn")) $("#abort_btn").show();
        if ($("#upload_btn")) $("#upload_btn").hide()
        if ($('#recordButton')) $("#recordButton").hide();
        const task = new EventSource("{!! $tasks ? route('room.sse', ['listening' => $tasks]) : route('room.sse') !!}", {
            withCredentials: false
        });
        task.addEventListener('open', () => {
            setTimeout(() => {
                if (finsihed || task.readyState === EventSource.OPEN) {
                    console.log('Connected')
                    $('#connection_indicator span').text('{{ __('room.placeholder.connected') }}')
                    $('#connection_indicator').fadeOut();
                }
            }, 1);
        });


        task.addEventListener('error', error => {
            console.error("Connection timeouted...");
            $('#connection_indicator').fadeIn();
            task.close();

            setTimeout(() => {
                if (!finsihed && task.readyState !== EventSource.OPEN) {
                    console.log(`Retrying connection`);
                    connect()
                } else {
                    $('#connection_indicator span').text('Connected!')
                    $('#connection_indicator').fadeOut();
                    console.log('Connected')
                }
            }, 1);
        });

        task.addEventListener('message', event => {
            if (event.data == "finished" && $("#submit_msg")) {
                finsihed = true;
                $chattable = true
                $("#submit_msg").show()
                if ($("#abort_btn")) $("#abort_btn").hide();
                if ($("#upload_btn")) $("#upload_btn").show();
                if (location.protocol !== 'https:') $("#recordButton").remove();
                if ($('#recordButton')) $("#recordButton").show();
                $("#chat_input").prop("readonly", false)
                $("#chat_input").val("")
                adjustTextareaRows($("#chat_input"))
                $(".show-on-finished").attr("style", "")
                hljs.configure({
                    languages: hljs.listLanguages()
                }); //enable auto detect
                $('#chatroom div.msg-content pre > div').remove()
                $('#chatroom div.msg-content pre code.language-undefined').each(function() {
                    $(this).text($(this).text())
                    $(this)[0].dataset.highlighted = '';
                    $(this)[0].classList = ""
                    hljs.highlightElement($(this)[0]);
                });
                $('#chatroom div.msg-content pre').each(function() {
                    let languageClass = '';
                    $(this).children("code")[0].classList.forEach(cName => {
                        if (cName.startsWith('language-')) {
                            languageClass = cName.replace('language-', '');
                            return;
                        }
                    })
                    verilog = languageClass == "verilog" ?
                        `<button onclick="compileVerilog(this)" class="flex items-center hover:bg-gray-900 px-2 py-2 "><span>{{ __('chat.button.verilog_compile_test') }}</span></button>` :
                        ``
                    $(this).prepend(
                        `<div class="flex items-center text-gray-200 bg-gray-800 rounded-t-lg overflow-hidden">
<span class="pl-4 py-2 mr-auto"><input class="bg-gray-900" list="language_list" oninput="switchLang(this)" value="${languageClass}"></span>
${verilog}
<button onclick="copytext(this, $(this).parent().parent().children('code').text().trim())"
class="flex items-center px-2 py-2 hover:bg-gray-900"><svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round"
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
</svg><span>{{ __('Copy') }}</span></button></div>`
                    )
                })
                task.close();
            } else {
                data = JSON.parse(event.data)
                number = parseInt(data["history_id"]);
                $('#task_' + number).text(data["msg"]);
                histories[number] = $("#history_" + number + " div.msg-content")
                    .text()
                hljs.configure({
                    languages: []
                }); // disable auto detect
                chatroomFormatter($("#history_" + data["history_id"])[0]);
            }
        });

    }
    let mediaRecorder, audioChunks = [],
        isRecording = false,
        startTime, intervalId;
    const MAX_RECORD_TIME = 43200;

    $('#recordButton').on('click', async function() {
        const recordIcon = $('#recordIcon');
        isRecording = !$(this).data('isRecording');
        $(this).data('isRecording', isRecording);

        if (isRecording) {
            try {
                if (!navigator.mediaDevices?.getUserMedia || location.protocol !== 'https:') {
                    isRecording = !$(this).data('isRecording');
                    $(this).data('isRecording', isRecording);
                    $('#recordButton').remove();
                    return alert(
                        "{{ __('room.hint.recording_not_supported') }}");
                }

                const stream = await navigator.mediaDevices.getUserMedia({
                    audio: true
                });
                recordIcon.toggleClass("fa-circle fa-stop-circle");

                const mimeType = ['audio/webm', 'audio/ogg', 'audio/wav'].find(type => MediaRecorder
                    .isTypeSupported(type));
                if (!mimeType) return alert("{{ __('room.hint.recording_no_supported_format') }}");

                mediaRecorder = new MediaRecorder(stream, {
                    mimeType
                });
                audioChunks = [];
                mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
                mediaRecorder.onstop = () => handleRecordingStop(mimeType, stream);

                mediaRecorder.start();
                startTime = Date.now();
                $('#recording').show().find('div').text(formatDuration(0));
                intervalId = setInterval(updateRecordingTime, 1000);
            } catch (error) {
                recordIcon.toggleClass("fa-stop-circle fa-circle");
                $(this).data('isRecording', false);
                alert(handleError(error));
            }
        } else {
            stopRecording();
        }
    });

    function handleRecordingStop(mimeType, stream) {
        const audioBlob = new Blob(audioChunks, {
            type: mimeType
        });
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
        const file = new File([audioBlob], `recorded_audio_${timestamp}.${mimeType.split('/')[1]}`, {
            type: mimeType
        });

        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        $('#upload').prop('files', dataTransfer.files);
        $("#attachment").show().find("button").text(file.name);

        clearInterval(intervalId);
        $('#recording').hide();
        stream.getTracks().forEach(track => track.stop());
    }

    function updateRecordingTime() {
        const elapsedTime = Math.floor((Date.now() - startTime) / 1000);
        if (elapsedTime >= MAX_RECORD_TIME) {
            mediaRecorder.stop();
            alert("{{ __('room.hint.recording_duration_reach_limit') }}");
        }
        $('#recording > div').text(formatDuration(elapsedTime));
    }

    function stopRecording() {
        if (mediaRecorder?.state === "recording") mediaRecorder.stop();
        $('#recordIcon').toggleClass("fa-stop-circle fa-circle");
        mediaRecorder?.stream.getTracks().forEach(track => track.stop());
    }

    function handleError(error) {
        switch (error.name) {
            case "NotAllowedError":
                return "{{ __('room.hint.recording_failed_permission') }}";
            default:
                return "{{ __('room.hint.recording_no_microphone') }}";
        }
    }

    function formatDuration(seconds) {
        const hours = String(Math.floor(seconds / 3600)).padStart(2, '0');
        const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
        const secs = String(seconds % 60).padStart(2, '0');
        return `${hours}:${minutes}:${secs}`;
    }

    connect();

    if ($("#chat_input")) {
        $("#chat_input").focus();
        $("#chat_input").on("keydown", function(event) {
            if (!isMac && event.key === "Enter" && !event.shiftKey) {
                event.preventDefault();

                $("#prompt_area").submit();
            } else if (event.key === "Enter" && event.shiftKey) {
                event.preventDefault();
                var cursorPosition = this.selectionStart;
                $(this).val($(this).val().substring(0, cursorPosition) + "\n" + $(this).val().substring(
                    cursorPosition));
                this.selectionStart = this.selectionEnd = cursorPosition + 1;
            }
            adjustTextareaRows($("#chat_input"));
        });
        adjustTextareaRows($("#chat_input"));
    }

    @if (session('selLLMs'))
        @foreach (array_diff($llms->pluck('id')->toarray(), session('selLLMs')) as $id)
            $(`#btn_{{ $id }}_toggle`).click()
        @endforeach
    @endif
    @if (session('mode_track') == 1)
        $('#send_to_mode').click()
    @endif
</script>
