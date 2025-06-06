<div class="h-full p-4 rounded-lg overflow-hidden">
    <!-- User Authentication Section -->
    <div class="bg-white dark:bg-gray-700 p-4 rounded-lg shadow-md flex flex-col h-full overflow-hidden space-y-2">
        <div class="flex flex-col" id='hf_status'>
            <div id="hf-msg" class="mb-2 text-center rounded-lg"></div>
            <div class="hidden">
                <div class="flex items-center justify-between">
                    <h2 class="text-2xl font-semibold text-gray-900 dark:text-white" id="userGreeting"></h2>
                    <button id="logoutButton"
                        class="bg-red-500 hover:bg-red-600 text-white px-6 py-2 rounded shadow-md dark:bg-red-700 dark:hover:bg-red-800 flex items-center"
                        onclick="handleLogoutButtonClick()">
                        <i id="stop-icon-logout" class="fa fa-spinner fa-spin hidden text-lg mr-2"
                            aria-hidden="true"></i>
                        <span id="logoutText">{{ __('hub.button.logout') }}</span>
                    </button>
                </div>
            </div>

            <div class="flex items-center">
                <input type="text" id="token" placeholder="{{ __('hub.label.enter_your_token') }}"
                    class="border border-gray-300 rounded-l-md p-3 flex-1 text-gray-900 dark:text-gray-100 dark:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 h-12" />
                <button id="loginButton"
                    class="bg-blue-500 hover:bg-blue-600 text-white px-6 rounded-r-md shadow-md dark:bg-blue-700 dark:hover:bg-blue-800 h-12"
                    onclick="handleLoginButtonClick()">
                    <i id="stop-icon-login" class="fa fa-spinner fa-spin hidden text-lg mr-2" aria-hidden="true"></i>
                    <span id="loginText">{{ __('hub.button.login') }}</span>
                </button>
            </div>
        </div>
        <div class="flex items-center">
            <input id="searchInput" type="text" placeholder="{{ __('hub.label.search_for_model') }}"
                class="border border-gray-300 rounded-l-md p-3 flex-1 text-gray-900 dark:text-gray-100 dark:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 h-12" />
            <button id="searchButton"
                class="bg-blue-600 text-white py-2 px-4 rounded-r-md transition duration-200 hover:bg-blue-700 dark:bg-blue-700 dark:hover:bg-blue-600 h-12 flex items-center">
                {{ __('hub.button.search') }}
            </button>
        </div>
        <div id="message" class="mt-4 text-gray-300 hidden"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 overflow-y-auto scrollbar overflow-x-hidden flex-1"
            id="results"></div>

        <script>
            checkLoginStatus(formatUsername);

            function formatUsername(data) {
                if (!data.logged_in) return;

                const {
                    username
                } = data;
                const name = username.split('[1morgs: [0m')[0];

                $('#hf_status > div').toggleClass('hidden');
                $('#userGreeting').text(name.trim());
            }

            function performRequest(url, data = {}, onSuccess, onError) {
                $.ajax({
                    type: 'POST',
                    url: url,
                    data: {
                        _token: "{{ csrf_token() }}",
                        ...data
                    },
                    success: onSuccess,
                    error: onError
                });
            }

            function checkLoginStatus(onSuccess, onError) {
                const url = "{{ route('manage.kernel.storage.hf_login') }}";
                performRequest(url, {}, onSuccess, onError);
            }

            function handleLogin(userToken, onSuccess, onError) {
                const url = "{{ route('manage.kernel.storage.hf_login') }}";
                performRequest(url, {
                    token: userToken
                }, onSuccess, onError);
            }

            function handleLogout(onSuccess, onError) {
                const url = "{{ route('manage.kernel.storage.hf_logout') }}";
                performRequest(url, {}, onSuccess, onError);
            }

            function toggleButtonState(button, isLoading) {
                button.prop('disabled', isLoading).toggleClass('cursor-not-allowed opacity-50', isLoading);
                button.find('#stop-icon-logout, #stop-icon-login').toggleClass('hidden', !isLoading);
                button.find('#logoutText, #loginText').toggleClass('opacity-50', isLoading);
            }

            function handleLogoutButtonClick() {
                const button = $('#logoutButton');
                toggleButtonState(button, true);

                handleLogout(() => {
                    toggleButtonState(button, false);
                    $('#hf_status > div').toggleClass('hidden');
                    appendMessage("{{ __('hub.hint.logout_success') }}", true, '#hf-msg')
                }, (error) => {
                    console.error(error);
                    toggleButtonState(button, false);
                    appendMessage(error.statusText, false, '#hf-msg')
                });
            }

            function handleLoginButtonClick() {
                const button = $('#loginButton');
                toggleButtonState(button, true);

                handleLogin($('#token').val(), (data) => {
                    if (data && data.logged_in) {
                        checkLoginStatus((userData) => {
                            toggleButtonState(button, false);
                            formatUsername(userData);
                            appendMessage("{{ __('hub.hint.login_success') }}", true, '#hf-msg')
                        });
                    } else {
                        appendMessage("{{ __('hub.hint.login_failed') }}", false, '#hf-msg')
                        toggleButtonState(button, false);
                    }
                }, (error) => {
                    console.error(error);
                    appendMessage(error.statusText, false, '#hf-msg')
                    toggleButtonState(button, false);
                });
            }
        </script>
    </div>
</div>
<div id="gatedModal" class="fixed inset-0 flex items-center justify-center hidden z-50 bg-black bg-opacity-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-11/12 sm:w-3/4 lg:w-1/2">
        <h3 class="text-xl font-bold mb-2 text-gray-900 dark:text-white">Access Restricted</h3>
        <p class="text-gray-700 dark:text-gray-300" id="gatedMessage">{{ __('hub.hint.model_gated') }}</p>
        <a target='new'
            class="bg-blue-500 homepage_link text-white py-2 px-4 rounded hover:bg-blue-600 dark:bg-blue-600 dark:hover:bg-blue-500 mb-2">{{ __('hub.button.homepage') }}</a>
        <button onclick='$(this).parent().parent().addClass("hidden")'
            class="bg-red-500 text-white py-2 px-4 rounded hover:bg-red-600 dark:bg-red-700 dark:hover:bg-red-600 mt-4">
            Close
        </button>
    </div>
</div>

<!-- Loader Element -->
<div id="loader" class="hidden mt-4" role="status">
    <svg aria-hidden="true" class="inline w-8 h-8 text-gray-200 animate-spin dark:text-gray-600 fill-green-500"
        viewBox="0 0 100 101" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path
            d="M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z"
            fill="currentColor" />
        <path
            d="M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z"
            fill="currentFill" />
    </svg>
    <span class="sr-only">Loading...</span>
</div>

<div id="modelModal" class="fixed inset-0 flex items-center justify-center hidden z-25 bg-black bg-opacity-50">
    <div
        class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-11/12 sm:w-3/4 lg:w-1/2 max-h-[80vh] overflow-y-auto">
        <h3 class="text-xl font-bold mb-2 text-gray-900 dark:text-white" id="modalTitle"></h3>
        <div id="gatedIndicator" class="hidden mb-2 text-yellow-600 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 2v4m0 4v8m0 0H8m4 0h4m-2-18a2 2 0 012 2v2a2 2 0 01-2 2H8a2 2 0 01-2-2V4a2 2 0 012-2h8z" />
            </svg>
            {{ __('hub.hint.model_gated') }}
        </div>
        <h4 class="font-semibold mb-1 text-gray-900 dark:text-white">Files:</h4>
        <ul id="modalFiles" class="list-disc list-inside mb-4 space-y-2 max-h-40 overflow-y-auto scrollbar">
        </ul>
        <button id="downloadButton"
            class="bg-green-500 text-white py-2 px-4 rounded hover:bg-green-600 dark:bg-green-600 dark:hover:bg-green-500 mb-2">{{ __('hub.button.download') }}</button>
        <a target='new'
            class="bg-blue-500 homepage_link text-white py-2 px-4 rounded hover:bg-blue-600 dark:bg-blue-600 dark:hover:bg-blue-500 mb-2">{{ __('hub.button.homepage') }}</a>
        <button id="closeModal"
            class="bg-red-500 text-white py-2 px-4 rounded hover:bg-red-600 dark:bg-red-700 dark:hover:bg-red-600 mb-4">Close</button>
        <div id="responseText" class="hidden text-gray-800 dark:text-white mt-2 overflow-y-auto max-h-32 scrollbar">
        </div>
    </div>
</div>

<script>
    const apiUrl = "https://huggingface.co/api/models";
    $(document).ready(function() {
        $('#searchButton').click(() => {
            const searchTerm = $('#searchInput').val().trim();
            if (searchTerm) fetchModels(searchTerm);
        });
        $('#searchInput').keypress(e => {
            if (e.which === 13) $('#searchButton').click();
        });
        $('#closeModal').click(() => $('#modelModal').addClass('hidden'));
        $(window).click(event => {
            if ($(event.target).is('#modelModal')) $('#modelModal').addClass('hidden');
        });

        $('#downloadButton').click(() => {
            const modelName = $('#modalTitle').text().trim();
            const downloadUrl =
                `{{ route('manage.kernel.storage.download') }}?model_name=${encodeURIComponent(modelName)}`;

            $('#responseText').removeClass('hidden').text('Downloading...');
            fetch(downloadUrl)
                .then(response => response.text())
                .then(text => {
                    if (text.includes(
                            `Access to model ${modelName} is restricted and you are not in the authorized list.`
                        )) {
                        $('#gatedModal').removeClass('hidden')
                        $('#responseText').text('');
                        removeModel(modelName)
                        return;
                    }
                    const reader = new Response(text).body.getReader(),
                        decoder = new TextDecoder("utf-8");

                    (function push() {
                        reader.read().then(({
                            done,
                            value
                        }) => {
                            if (done) return $('#responseText').append(
                                '<br/>Download complete.');
                            $('#responseText').append(decoder.decode(value, {
                                stream: true
                            }));
                            push();
                        });
                    })();
                })
                .catch(error => alert('Error: ' + error.message));
        });


        $('#closeLoginModal').click(() => $('#loginModal').addClass('hidden'));
    });

    function fetchModels(searchTerm) {
        const params = new URLSearchParams({
            search: searchTerm,
            sort: 'downloads',
            limit: 15
        });
        fetch(`${apiUrl}?${params.toString()}`)
            .then(response => response.json())
            .then(data => displayResults(data))
            .catch(error => console.error('Error fetching models:', error));
    }

    function fetchModelFiles(modelId) {
        $('#modalFiles').empty();
        $('#responseText').addClass('hidden');
        fetch(`${apiUrl}/${modelId}`)
            .then(response => response.json())
            .then(data => {
                $('#modalTitle').text(modelId);
                $('#gatedIndicator').toggleClass('hidden', !data.gated);
                data.siblings.forEach(file => {
                    $('#modalFiles').append(
                        `<li class="flex justify-between items-center text-gray-800 dark:text-gray-200"><span class="font-medium">${file.rfilename}</span></li>`
                    );
                });
                $('.homepage_link').attr('href', 'https://huggingface.co/' + modelId)
                $('#modelModal').removeClass('hidden');
            })
            .catch(error => console.error('Error fetching model files:', error));
    }

    function displayResults(models) {
        const resultsGrid = $('#results').empty();

        if (!models.length) {
            resultsGrid.append($('<div>').addClass('col-span-full text-red-500').text('No models found.'));
            return;
        }

        models.forEach(model => {
            resultsGrid.append(
                $('<div>').addClass(
                    'border my-2 ml-2 mr-4 rounded-lg shadow-md bg-white dark:bg-gray-800 p-4 transition transform hover:scale-105 relative cursor-pointer'
                )
                .attr('onclick', `fetchModelFiles('${model.modelId}')`)
                .append(
                    $('<h2>').addClass('text-lg font-semibold text-gray-900 dark:text-gray-100')
                    .attr('title', model.modelId).css('word-wrap', 'break-word').text(model.modelId),
                    $('<p>').addClass('text-gray-700 dark:text-gray-300').html(
                        `<strong>Downloads:</strong> ${model.downloads.toLocaleString()}`),
                    $('<p>').addClass('text-gray-700 dark:text-gray-300').html(
                        `<strong>Likes:</strong> ${model.likes.toLocaleString()}`),
                    $('<button>').addClass(
                        'view-tags-button bg-blue-500 text-white py-1 px-2 rounded mt-2 hover:bg-blue-600 dark:bg-blue-700 dark:hover:bg-blue-600'
                    ).text('View Tags'),
                    $('<div>').addClass('tags hidden mt-2 flex flex-wrap').append(
                        model.tags.map(tag => $('<span>').addClass(
                            'bg-blue-100 text-blue-600 text-xs font-medium mr-1 mb-1 px-2.5 py-0.5 rounded-full dark:bg-blue-700 dark:text-blue-300'
                        ).text(tag))
                    )
                )
            );
        });

        $('.view-tags-button').click(function(event) {
            event.stopPropagation();
            const tagsDiv = $(this).next('.tags').toggleClass('hidden');
            $(this).text(tagsDiv.hasClass('hidden') ? 'View Tags' : 'Hide Tags');
        });
    }
</script>
