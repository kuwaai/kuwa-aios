<x-app-layout>
    <div class="h-full">
        <div class="mx-auto h-full">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm h-full">
                <div class="text-gray-900 dark:text-gray-100 h-full">
                    <script>
                        $groups = {}
                    </script>
                    <section class="flex flex-col h-full overflow-hidden">
                        <div class="flex-1 flex overflow-hidden flex-col">
                            <div>
                                <ul class="flex flex-wrap -mb-px text-sm font-medium text-center"
                                    data-tabs-toggle="#Contents" role="tablist">
                                    @if (Auth::user()->hasPerm('Dashboard_read_statistics') && false)
                                        <li class="mr-2" role="presentation">
                                            <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="statistics-tab"
                                                data-tabs-target="#statistics" type="button" role="tab"
                                                aria-controls="statistics">{{ __('dashboard.tab.statistics') }}</button>
                                        </li>
                                    @endif
                                    @if (Auth::user()->hasPerm('Dashboard_read_blacklist') && false)
                                        <li class="mr-2" role="presentation">
                                            <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="blacklist-tab"
                                                data-tabs-target="#blacklist" type="button" role="tab"
                                                aria-controls="blacklist">{{ __('dashboard.tab.blacklist') }}</button>
                                        </li>
                                    @endif
                                    @if (Auth::user()->hasPerm('Dashboard_read_feedbacks'))
                                        <li class="mr-2" role="presentation">
                                            <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="feedbacks-tab"
                                                data-tabs-target="#feedbacks" type="button" role="tab"
                                                aria-controls="feedbacks">{{ __('dashboard.tab.feedbacks') }}</button>
                                        </li>
                                    @endif
                                    @if (Auth::user()->hasPerm('Dashboard_read_logs'))
                                        <li class="mr-2" role="presentation">
                                            <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="logs-tab"
                                                data-tabs-target="#logs" type="button" role="tab"
                                                aria-controls="logs">{{ __('dashboard.tab.logs') }}</button>
                                        </li>
                                    @endif
                                    @if (Auth::user()->hasPerm('Dashboard_read_safetyguard') && false)
                                        <li class="mr-2" role="presentation">
                                            <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300"
                                                id="safetyguard-tab" data-tabs-target="#safetyguard" type="button"
                                                role="tab" aria-controls="safetyguard">{{ __('dashboard.tab.safetyguard') }}</button>
                                        </li>
                                    @endif
                                    @if (Auth::user()->hasPerm('Dashboard_read_inspect') && false)
                                        <li class="mr-2" role="presentation">
                                            <button class="inline-block p-4 border-b-2 rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300" id="inspect-tab"
                                                data-tabs-target="#inspect" type="button" role="tab"
                                                aria-controls="inspect">{{ __('dashboard.tab.inspect') }}</button>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                            <div id="Contents" class="flex flex-1 overflow-hidden">
                                @if (Auth::user()->hasPerm('Dashboard_read_statistics') && false)
                                    <div class="{{ session('last_tab') ? 'hidden' : '' }} bg-slate-200 flex flex-1 dark:bg-slate-700"
                                        id="statistics" role="tabpanel" aria-labelledby="statistics-tab">
                                        @include('dashboard.tabs.statistics')
                                    </div>
                                @endif
                                @if (Auth::user()->hasPerm('Dashboard_read_blacklist') && false)
                                    <div class="{{ session('last_tab') == 'blacklist' ? '' : 'hidden' }} bg-slate-200 flex flex-1 dark:bg-slate-700"
                                        id="blacklist" role="tabpanel" aria-labelledby="blacklist-tab">
                                        @include('dashboard.tabs.blacklist')
                                    </div>
                                @endif
                                @if (Auth::user()->hasPerm('Dashboard_read_feedbacks'))
                                    <div class="{{ session('last_tab') == 'feedbacks' ? '' : 'hidden' }} bg-slate-200 flex flex-1 dark:bg-slate-700"
                                        id="feedbacks" role="tabpanel" aria-labelledby="feedbacks-tab">
                                        @include('dashboard.tabs.feedbacks')
                                    </div>
                                @endif
                                @if (Auth::user()->hasPerm('Dashboard_read_logs'))
                                    <div class="{{ session('last_tab') == 'logs' ? '' : 'hidden' }} bg-slate-200 flex flex-1 dark:bg-slate-700 overflow-x-hidden"
                                        id="logs" role="tabpanel" aria-labelledby="logs-tab">
                                        @include('dashboard.tabs.logs')
                                    </div>
                                @endif
                                @if (Auth::user()->hasPerm('Dashboard_read_safetyguard') && false)
                                    <div class="{{ session('last_tab') == 'safetyguard' ? '' : 'hidden' }} bg-slate-200 flex flex-1 dark:bg-slate-700 overflow-x-hidden"
                                        id="safetyguard" role="tabpanel" aria-labelledby="safetyguard-tab">
                                        @include('dashboard.tabs.safetyguard')
                                    </div>
                                @endif
                                @if (Auth::user()->hasPerm('Dashboard_read_inspect') && false)
                                    <div class="{{ session('last_tab') == 'inspect' ? '' : 'hidden' }} bg-slate-200 flex flex-1 dark:bg-slate-700 overflow-x-hidden"
                                        id="inspect" role="tabpanel" aria-labelledby="inspect-tab">
                                        @include('dashboard.tabs.inspect')
                                    </div>
                                @endif
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
