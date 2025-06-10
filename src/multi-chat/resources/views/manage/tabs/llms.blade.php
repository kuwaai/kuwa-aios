<script>
    $llms = []
</script>
<div class="flex flex-1 h-full mx-auto">
    <div class="flex flex-col bg-white dark:bg-gray-700 p-2 text-white w-72 flex-shrink-0 relative overflow-hidden">
        <div class="mb-2 border border-black dark:border-white border-1 rounded-lg overflow-hidden">
            <button onclick="CreateRow()" id="new_llm_btn"
                class="flex menu-btn flex items-center justify-center w-full h-12 bg-green-400 hover:bg-green-500 dark:bg-green-600 dark:hover:bg-green-700 transition duration-300">
                <p class="flex-1 text-center text-white">{{ __('models.button.new_model') }}</p>
            </button>
        </div>
        <form id="del_LLM_by_ID" method="post" action="{{ route('manage.llms.delete') }}" style="display:none">
            @csrf
            @method('delete')
            <input name="id">
        </form>
        <div class="flex-1 overflow-y-auto scrollbar pr-2 text-black dark:text-white">
            <p>{{ __('models.label.enabled_models') }}</p>
            <hr class="mb-2 border-black dark:border-white">
            @foreach (App\Models\LLMs::orderby('order')->orderby('order')->where('enabled', '=', true)->get() as $LLM)
                <div id="edit_llm_btns"
                    class="mb-2 border border-black dark:border-white border-1 rounded-l-full overflow-hidden">
                    <script>
                        $llms[{{ $LLM->id }}] = {!! json_encode(
                            [
                                $LLM->image ? asset(Storage::url($LLM->image)) : '/' . config('app.LLM_DEFAULT_IMG'),
                                $LLM->name,
                                $LLM->order,
                                $LLM->access_code,
                                $LLM->id,
                                $LLM->description,
                                $LLM->enabled,
                                json_decode($LLM->config),
                            ],
                            JSON_HEX_APOS,
                        ) !!}
                    </script>
                    <button onclick='edit_llm({{ $LLM->id }})' id="edit_llm_btn_{{ $LLM->id }}"
                        class="flex menu-btn items-center justify-center w-full dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">
                        <div class="flex flex-1">
                            <div class="flex m-auto w-[48px] h-[48px] justify-center items-center"><img width="48px"
                                    height="48px" class="rounded-full border-2 border border-white bg-black"
                                    src="{{ $LLM->image ? asset(Storage::url($LLM->image)) : '/' . config('app.LLM_DEFAULT_IMG') }}" />
                            </div>
                            <div class="flex flex-1 flex-col  h-[48px]">
                                <span
                                    class="overflow-x-auto scrollbar break-all flex flex-col flex-1 items-center justify-center">{{ $LLM->name }}</span>
                            </div>
                        </div>
                    </button>
                </div>
            @endforeach
            <p>{{ __('models.label.disabled_models') }}</p>
            <hr class="mb-2">
            @foreach (App\Models\LLMs::orderby('order')->orderby('order')->where('enabled', '=', false)->get() as $LLM)
                <div id="edit_llm_btns"
                    class="mb-2 border border-black dark:border-white border-1 rounded-l-full overflow-hidden">
                    <script>
                        $llms[{{ $LLM->id }}] = {!! json_encode(
                            [
                                $LLM->image ? asset(Storage::url($LLM->image)) : '/' . config('app.LLM_DEFAULT_IMG') ,
                                $LLM->name,
                                $LLM->order,
                                $LLM->access_code,
                                $LLM->id,
                                $LLM->description,
                                $LLM->enabled,
                            ],
                            JSON_HEX_APOS,
                        ) !!}
                    </script>
                    <button onclick='edit_llm({{ $LLM->id }})' id="edit_llm_btn_{{ $LLM->id }}"
                        class="flex menu-btn items-center justify-center w-full dark:hover:bg-gray-600 hover:bg-gray-200 transition duration-300">
                        <div class="flex flex-1">
                            <div class="flex m-auto w-[48px] h-[48px] justify-center items-center"><img width="48px"
                                    height="48px" class="rounded-full border-2 border border-white bg-black"
                                    src="{{ $LLM->image ? asset(Storage::url($LLM->image)) : '/' . config('app.LLM_DEFAULT_IMG') }}" />
                            </div>
                            <div class="flex flex-1 flex-col  h-[48px]">
                                <span
                                    class="overflow-x-auto scrollbar break-all flex flex-col flex-1 items-center justify-center">{{ $LLM->name }}</span>
                            </div>
                        </div>
                    </button>
                </div>
            @endforeach
        </div>
    </div>
    <div id="edit_llm" style="display:none;"
        class="flex-1 h-full flex flex-col w-full bg-gray-200 dark:bg-gray-600 shadow-xl overflow-hidden justify-center items-center text-gray-700 dark:text-white">
        <h3 class="my-4 text-xl font-medium text-gray-900 dark:text-white"></h3>
        <form id="update_LLM_by_ID" method="post" enctype="multipart/form-data" autocomplete="off"
            action="{{ route('manage.llms.update') }}"
            class="w-full max-w-2xl p-4 overflow-y-auto scrollbar overflow-x-hidden">
            @csrf
            @method('patch')
            <input id="update_img" name="image" type="file" style="display:none">
            <input name="id" style="display:none">
            <div class="flex flex-wrap -mx-3 mb-2 items-center">
                <div class="w-full md:w-1/3 px-3 mb-6 md:mb-0">
                    <div class="flex flex-wrap -mx-3">
                        <div class="w-full px-3 flex flex-col items-center">
                            <label for="update_img">
                                <span class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2">
                                    {{ __('models.label.model_image') }}
                                </span>
                                <img id="image"
                                    class="rounded-full border border-gray-400 dark:border-gray-900 m-auto bg-black"
                                    width="50px" height="50px" class="m-auto" src="/{{config('app.LLM_DEFAULT_IMG')}}" />
                            </label>
                        </div>
                    </div>
                </div>
                <div class="w-full md:w-2/3 px-3 flex justify-center items-center flex-wrap md:flex-nowrap">
                    <div class="w-full md:w-5/6">
                        <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2"
                            for="llm_name">
                            {{ __('models.label.model_name') }}
                        </label>
                        <input name="name" required
                            class="appearance-none block w-full text-gray-700 border border-gray-200 rounded py-3 px-4 mb-3 leading-tight focus:outline-none focus:bg-white focus:border-gray-500"
                            id="llm_name" placeholder="{{ __('models.label.model_name') }}">
                    </div>
                    <div class="md:w-1/6 pl-3">
                        <a id="toggle_llm_btn" class="text-white font-bold py-3 px-4 rounded margin-t-auto">
                            <i class="fas fa-power-off"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap -mx-3 mb-2">
                <div class="w-full md:w-1/2 px-3 mb-6 md:mb-0">
                    <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2"
                        for="access_code">
                        {{ __('models.label.access_code') }}
                    </label>
                    <input name="access_code" required
                        class="appearance-none block w-full text-gray-700 border border-gray-200 rounded py-3 px-4 leading-tight focus:outline-none focus:bg-white focus:border-gray-500"
                        id="access_code" type="text" placeholder="{{ __('models.label.access_code') }}">
                </div>
                <div class="w-full md:w-1/2 px-3 mb-6 md:mb-0">
                    <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2" for="order">
                        {{ __('models.label.order') }}
                    </label>
                    <input name="order"
                        class="appearance-none block w-full text-gray-700 border border-gray-200 rounded py-3 px-4 leading-tight focus:outline-none focus:bg-white focus:border-gray-500"
                        id="order" type="text" placeholder="{{ __('models.label.order') }}">
                </div>
            </div>
            <div class="flex flex-wrap -mx-3 mb-2">
                <div class="w-full px-3">
                    <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2"
                        for="description">
                        {{ __('models.label.description') }}
                    </label>
                    <input name="description"
                        class="appearance-none block w-full text-gray-700 border border-gray-200 rounded py-3 px-4 mb-3 leading-tight focus:outline-none focus:bg-white focus:border-gray-500"
                        id="description" placeholder="{{ __('models.placeholder.description') }}">
                </div>
            </div>

            <!--<div class="flex flex-wrap -mx-3 mb-2">
                <div class="w-full px-3">
                    <label class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2"
                        for="system_prompt">
                        {{ __('System prompt') }}
                    </label>
                    <input name="system_prompt"
                        class="appearance-none block w-full text-gray-700 border border-gray-200 rounded py-3 px-4 mb-3 leading-tight focus:outline-none focus:bg-white focus:border-gray-500"
                        id="system_prompt" placeholder="{{ __('System Prompt for the model') }}">
                </div>
            </div>-->
            <div class="space-y-2">
                <p class="block uppercase tracking-wide dark:text-white text-xs font-bold mb-2">
                    {{ __('store.bot.react_buttons') }}
                </p>

                @foreach (['Feedback', 'Quote', "Other"] as $label)
                    @php $id = strtolower($label); @endphp
                    <div class="flex items-center">
                        <input id="{{ $id }}" name="react_btn[]" value="{{ $id }}" type="checkbox"
                            class="w-4 h-4 text-teal-600 bg-gray-100 border-gray-300 rounded focus:ring-teal-500 dark:focus:ring-teal-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                        <label for="{{ $id }}"
                            class="ml-2 ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                            {{ __('store.bot.react.allow_' . $id) }}
                        </label>
                    </div>
                @endforeach
            </div>

            <div class="text-center">
                <button type="button" data-modal-target="popup-modal2" data-modal-toggle="popup-modal2"
                    class="bg-green-500 hover:bg-green-600 text-white focus:ring-4 focus:outline-none focus:ring-red-300 dark:focus:ring-red-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                    {{ __('models.button.save') }}
                </button>
                <div id="popup-modal2" data-modal-backdrop="static" tabindex="-2"
                    class="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed top-0 left-0 right-0 z-50 hidden p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
                    <div class="relative w-full max-w-md max-h-full">
                        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
                            <button type="button"
                                class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-800 dark:hover:text-white"
                                data-modal-hide="popup-modal2">
                                <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd"
                                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                        clip-rule="evenodd"></path>
                                </svg>
                                <span class="sr-only">Close modal</span>
                            </button>
                            <div class="p-6 text-center">
                                <svg aria-hidden="true"
                                    class="mx-auto mb-4 text-gray-400 w-14 h-14 dark:text-gray-200" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <h3 class="mb-5 text-lg font-normal text-gray-500 dark:text-gray-400">
                                    {{ __('models.header.update_model') }}</h3>
                                <button data-modal-hide="popup-modal2" type="submit"
                                    class="text-white bg-green-500 hover:bg-green-600 focus:ring-4 focus:outline-none focus:ring-teal-300 dark:focus:ring-teal-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                                    {{ __('models.button.yes') }}
                                </button>
                                <button data-modal-hide="popup-modal2" type="button"
                                    class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">{{ __('models.button.no') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
                <button data-modal-target="popup-modal" data-modal-toggle="popup-modal" type="button"
                    id="delete_button"
                    class="bg-red-500 hover:bg-red-600 text-white focus:ring-4 focus:outline-none focus:ring-red-300 dark:focus:ring-red-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                    {{ __('models.button.delete') }}
                </button>
                <div id="popup-modal" data-modal-backdrop="static" tabindex="-2"
                    class="bg-gray-900 bg-opacity-50 dark:bg-opacity-80 fixed top-0 left-0 right-0 z-50 hidden p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
                    <div class="relative w-full max-w-md max-h-full">
                        <div class="relative bg-white rounded-lg shadow dark:bg-gray-700">
                            <button type="button"
                                class="absolute top-3 right-2.5 text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-800 dark:hover:text-white"
                                data-modal-hide="popup-modal">
                                <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd"
                                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                        clip-rule="evenodd"></path>
                                </svg>
                                <span class="sr-only">Close modal</span>
                            </button>
                            <div class="p-6 text-center">
                                <svg aria-hidden="true"
                                    class="mx-auto mb-4 text-gray-400 w-14 h-14 dark:text-gray-200" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <h3 class="mb-5 text-lg font-normal text-gray-500 dark:text-gray-400">
                                    {{ __('models.modal.delete_model.header') }}</h3>
                                <button id="delete_llm" data-modal-hide="popup-modal" type="button"
                                    class="text-white bg-red-600 hover:bg-red-800 focus:ring-4 focus:outline-none focus:ring-red-300 dark:focus:ring-red-800 font-medium rounded-lg text-sm inline-flex items-center px-5 py-2.5 text-center mr-2">
                                    {{ __('models.button.yes') }}
                                </button>
                                <button data-modal-hide="popup-modal" type="button"
                                    class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900 focus:z-10 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-500 dark:hover:text-white dark:hover:bg-gray-600 dark:focus:ring-gray-600">{{ __('models.button.no') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function edit_llm(data) {
        $("#update_LLM_by_ID img").attr("src", $llms[data][0])
        $("#update_LLM_by_ID input[name='_method']").prop("disabled", false)
        $("#update_LLM_by_ID input[name='name']").val($llms[data][1])
        $("#update_LLM_by_ID input[name='order']").val($llms[data][2])
        $("#update_LLM_by_ID input[name='access_code']").val($llms[data][3])
        $("#update_LLM_by_ID input[name='id']").val($llms[data][4])
        $("#update_LLM_by_ID input[name='description']").val($llms[data][5])
        $("#update_LLM_by_ID input[name='system_prompt']").val($llms[data][7] && $llms[data][7].startup_prompt?.[0]
            ?.message || "")
        $("#update_LLM_by_ID input[name='react_btn[]']").prop("checked", false);
        if ($llms[data][7] && $llms[data][7]["react_btn"]) {
            $llms[data][7]["react_btn"].forEach((a) => {
                $(`#update_LLM_by_ID input[value='${a}']`).prop("checked", true);
            });
        }
        $("#edit_llm form").attr("action", "{{ route('manage.llms.update') }}")
        $("#delete_llm").off('click').on('click', function() {
            DeleteRow($llms[data][4]);
        });
        $("#edit_llm h3:eq(0)").text("{{ __('models.header.update_model') }}")
        $("#edit_llm h3:eq(1)").text("{{ __('models.header.update_model') }}")
        $("#edit_llm h3:eq(2)").text("{{ __('models.modal.delete_model.header') }}")
        $("#delete_button").show()
        $("#edit_llm_btns > button").removeClass("bg-gray-200 dark:bg-gray-600")
        $("#edit_llm_btn_" + $llms[data][4]).addClass("bg-gray-200 dark:bg-gray-600")
        $("#new_llm_btn").addClass("bg-green-400 dark:bg-green-600")
        $("#new_llm_btn").removeClass("dark:bg-green-700 bg-green-500")
        $("#toggle_llm_btn").attr("href", "{{ route('manage.llms.toggle', '') }}" + "/" + $llms[data][4])
        $("#toggle_llm_btn").removeClass("bg-green-500 hover:bg-green-600 bg-red-500 hover:bg-red-600")
        $("#toggle_llm_btn").addClass($llms[data][6] ? "bg-green-500 hover:bg-green-600" :
            "bg-red-500 hover:bg-red-600")
        $("#toggle_llm_btn").show()
        $("#edit_llm").show();
    }

    function CreateRow() {
        $("#edit_llm form").attr("action", "{{ route('manage.llms.create') }}");
        $("#update_LLM_by_ID img").attr("src", "/{{config('app.LLM_DEFAULT_IMG')}}")
        $("#update_LLM_by_ID input:eq(1)").prop("disabled", true)
        $("#update_LLM_by_ID input[name='name']").val("")
        $("#update_LLM_by_ID input[name='order']").val("")
        $("#update_LLM_by_ID input[name='access_code']").val("")
        $("#update_LLM_by_ID input[name='id']").val("")
        $("#update_LLM_by_ID input[name='description']").val("")
        $("#update_LLM_by_ID input[name='system_prompt']").val("")
        $("#update_LLM_by_ID input[name='react_btn[]']").prop("checked", true);
        $("#edit_llm h3:eq(0)").text("{{ __('models.header.create_model') }}")
        $("#edit_llm h3:eq(1)").text("{{ __('models.header.create_model') }}")
        $("#edit_llm_btns > button").removeClass("bg-gray-600")
        $("#new_llm_btn").removeClass("bg-green-400 dark:bg-green-600")
        $("#new_llm_btn").addClass("bg-green-500 dark:bg-green-700")
        $("#toggle_llm_btn").hide()
        $("#delete_button").hide()
    }
    CreateRow()
    $("#edit_llm").show();

    function DeleteRow(id) {
        $("#del_LLM_by_ID input:eq(2)").val(id);
        $("#del_LLM_by_ID").submit();
    }
    $('#update_img').on('change', function(event) {
        var input = event.target;
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                $("#update_LLM_by_ID img").attr("src", e.target.result)
            };
            reader.readAsDataURL(input.files[0]);
        }
    });
    @if (session('last_llm_id') !== null)
        $("#edit_llm_btn_{{ session('last_llm_id') }}").click();
    @endif
</script>
