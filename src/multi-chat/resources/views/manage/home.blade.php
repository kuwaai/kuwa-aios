<x-app-layout>
    <div class="h-full">
        <div class="mx-auto h-full">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm h-full">
                <div class="text-gray-900 dark:text-gray-100 h-full">
                    <script>
                        $groups = {};
                    </script>
                    <section class="flex flex-col h-full overflow-hidden">
                        <div class="flex-1 flex overflow-hidden flex-col">
                            <div id='manage_tab'>
                                <ul class="flex flex-wrap -mb-px text-sm font-medium text-center"
                                    data-tabs-toggle="#Contents" role="tablist">
                                    <li class="mr-2" role="presentation">
                                        <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="groups-tab"
                                            data-tabs-target="#groups" type="button" role="tab"
                                            aria-controls="groups"
                                            aria-selected="{{ session('last_tab') ? 'false' : 'true' }}">{{ __('manage.tab.groups') }}</button>
                                    </li>
                                    <li class="mr-2" role="presentation">
                                        <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="users-tab"
                                            data-tabs-target="#users" type="button" role="tab"
                                            aria-controls="users"
                                            aria-selected="{{ session('last_tab') == 'users' ? 'true' : 'false' }}">{{ __('manage.tab.users') }}</button>
                                    </li>
                                    <li class="mr-2" role="presentation">
                                        <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="llms-tab"
                                            data-tabs-target="#llms" type="button" role="tab" aria-controls="llms"
                                            aria-selected="{{ session('last_tab') == 'llms' ? 'true' : 'false' }}">{{ __('manage.tab.llms') }}</button>
                                    </li>
                                    <li class="mr-2" role="presentation">
                                        <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="kernel-tab"
                                            data-tabs-target="#kernel" type="button" role="tab"
                                            aria-controls="kernel"
                                            aria-selected="{{ session('last_tab') == 'kernel' ? 'true' : 'false' }}">{{ __('manage.tab.kernel') }}</button>
                                    </li>
                                    <li class="mr-2" role="presentation">
                                        <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="workers-tab"
                                            data-tabs-target="#workers" type="button" role="tab"
                                            aria-controls="workers"
                                            aria-selected="{{ session('last_tab') == 'workers' ? 'true' : 'false' }}">{{ __('manage.tab.workers') }}</button>
                                    </li>
                                    <li class="mr-2" role="presentation">
                                        <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="settings-tab"
                                            data-tabs-target="#settings" type="button" role="tab"
                                            aria-controls="settings"
                                            aria-selected="{{ session('last_tab') == 'settings' ? 'true' : 'false' }}">{{ __('manage.tab.settings') }}</button>
                                    </li>
                                </ul>
                            </div>
                            <div id="Contents" class="flex flex-1 overflow-hidden">
                                <div class="{{ session('last_tab') ? 'hidden' : '' }} bg-slate-50 flex flex-1 dark:bg-slate-800"
                                    id="groups" role="tabpanel" aria-labelledby="groups-tab">
                                    @include('manage.tabs.groups')
                                </div>
                                <div class="{{ session('last_tab') == 'users' ? '' : 'hidden' }} bg-slate-50 flex flex-1 dark:bg-slate-800"
                                    id="users" role="tabpanel" aria-labelledby="users-tab">
                                    @include('manage.tabs.users')
                                </div>
                                <div class="{{ session('last_tab') == 'llms' ? '' : 'hidden' }} bg-slate-50 flex flex-1 dark:bg-slate-800"
                                    id="llms" role="tabpanel" aria-labelledby="llms-tab">
                                    @include('manage.tabs.llms')
                                </div>
                                <div class="{{ session('last_tab') == 'kernel' ? '' : 'hidden' }} bg-slate-50 flex flex-1 dark:bg-slate-800"
                                    id="kernel" role="tabpanel" aria-labelledby="kernel-tab">
                                    @include('manage.tabs.kernel.home')
                                </div>
                                <div class="{{ session('last_tab') == 'workers' ? '' : 'hidden' }} bg-slate-50 flex flex-1 dark:bg-slate-800"
                                    id="workers" role="tabpanel" aria-labelledby="workers-tab">
                                    @include('manage.tabs.workers')
                                </div>
                                <div class="{{ session('last_tab') == 'settings' ? '' : 'hidden' }} bg-slate-50 flex flex-1 dark:bg-slate-800"
                                    id="settings" role="tabpanel" aria-labelledby="settings-tab">
                                    @include('manage.tabs.settings')
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
    <script>
        function onTabActivated(tabId) {
            if (tabId === "workers-tab") {
                workerCountInterval = setInterval(fetchWorkerCount, 6000);
                fetchWorkerCount();
                lastRefreshInterval = setInterval(updateLastRefreshTime, 1000);
            } else if (tabId === "kernel-tab") {
                fetchDataInterval = setInterval(fetchData, 3000);
                fetchStorageDataInterval = setInterval(fetchStorageData, 5000);
                fetchJobsDataInterval = setInterval(fetchJobsData, 1000);
                fetchData();
                fetchStorageData();
                fetchJobsData();
            } else if (tabId === 'settings-tab') {
                setTimeout(function() {
                    adjustTextareaRows($("#announcement"));
                }, 100);

                setTimeout(function() {
                    adjustTextareaRows($("#tos"));
                }, 100);
            }
        }

        function onTabDeactivated(tabId) {
            if (tabId === "workers-tab") {
                if (typeof workerCountInterval !== 'undefined') {
                    clearInterval(workerCountInterval);
                }
                if (typeof lastRefreshInterval !== 'undefined') {
                    clearInterval(lastRefreshInterval);
                }
            } else if (tabId === "kernel-tab") {
                if (typeof fetchDataInterval !== 'undefined') {
                    clearInterval(fetchDataInterval);
                }
                if (typeof fetchStorageDataInterval !== 'undefined') {
                    clearInterval(fetchStorageDataInterval);
                }
                if (typeof fetchJobsDataInterval !== 'undefined') {
                    clearInterval(fetchJobsDataInterval);
                }
            }
        }
        let currentTabId = $(".text-sm.font-medium button[aria-selected='true']").attr('id');

        $("#manage_tab ul[data-tabs-toggle] button").on("click", function() {
            const newTabId = $(this).attr("id");

            if (currentTabId !== newTabId) {
                if (currentTabId) {
                    onTabDeactivated(currentTabId);
                }

                currentTabId = newTabId;

                onTabActivated(currentTabId);
            }
        });
    </script>
</x-app-layout>
