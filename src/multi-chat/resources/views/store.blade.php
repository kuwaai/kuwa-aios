<x-app-layout>
    <div class="flex h-full mx-auto">
        <div
            class="flex flex-1 h-full flex flex-col w-full bg-gray-200 dark:bg-gray-600 shadow-xl overflow-y-auto scrollbar text-gray-700 dark:text-white">

            @php
                $result = App\Models\LLMs::getLLMs(Auth()->user()->group_id);
                $bots = App\Models\Bots::getBots(Auth()->user()->group_id);
                if (!request()->user()->hasPerm('Store_read_any_modelfile')) {
                    $bots = $bots->map(function ($item) {
                        if ($item->owner_id != Auth::user()->id) {
                            $item->config = '';
                        }
                        return $item;
                    });
                }
            @endphp
            @if (request()->user()->hasPerm(['tab_Manage', 'Store_create_community_bot', 'Store_create_group_bot', 'Store_create_private_bot']))
                <x-store.modal.create-bot :result="$result" />
            @endif
            <x-store.modal.bot-detail :result="$result" />
            @if (request()->user()->hasPerm(['tab_Manage', 'Store_create_community_bot', 'Store_create_group_bot', 'Store_create_private_bot']))
                <div class="mb-2 mt-8 mx-auto h-[50px] flex">
                    <a href="https://repository.kuwaai.org" target="_blank"
                        class="flex menu-btn flex items-center px-4 py-3 justify-center text-green-500 h-12 dark:hover:bg-gray-700 border border-green-500 border-1 rounded-l-lg hover:bg-gray-300 transition duration-300 overflow-hidden"><i
                            class="fas fa-globe"></i></a>
                    <button data-modal-target="create-bot-modal" data-modal-toggle="create-bot-modal" onclick="create_bot()"
                        class="create-bot-btn bg-green-500 hover:bg-green-600 flex menu-btn flex items-center justify-center w-auto px-3 h-12 transition duration-300 overflow-hidden">
                        <p class="flex-1 text-center text-white">{{ __('store.button.create') }}</p>
                    </button>
                    <label for="upload_bot_modelfile" 
                        class="text-green-500 cursor-pointer h-12 font-bold py-3 px-4 rounded-r-lg flex items-center dark:hover:bg-gray-700 border border-green-500 border-1 justify-center hover:bg-gray-300 transition duration-300">
                        <i class="fas fa-file-import"></i>
                    </label>
                    <input type="file" accept=".bot" class="hidden" id="upload_bot_modelfile"
                        onchange="importBot($(this)[0].files)" />
                </div>
            @endif
            @php
                $all = collect(); // Initialize $all as a collection

                // Get sorted system bots if the user has the "Store_read_discover_system_bots" permission
                if (request()->user()->hasPerm('Store_read_discover_system_bots')) {
                    $system_bots = App\Models\Bots::sortUserBots($bots->where('visibility', '=', 0));
                    $all = $all->merge($system_bots); // Add system bots to $all
                }

                // Get sorted community bots if the user has the "Store_read_discover_community_bots" permission
                if (request()->user()->hasPerm('Store_read_discover_community_bots')) {
                    $community_bots = App\Models\Bots::sortUserBots($bots->where('visibility', '=', 1));
                    $all = $all->merge($community_bots); // Add community bots to $all
                }

                // Get sorted group bots if the user has the "Store_read_discover_group_bots" permission
                if (request()->user()->hasPerm('Store_read_discover_group_bots')) {
                    $group_bots = $bots->where('visibility', '=', 2);

                    // Limit group bots to the user's group if they don't have the "tab_Manage" permission
                    if (!request()->user()->hasPerm('tab_Manage')) {
                        $group_bots = $group_bots->where('group_id', '=', request()->user()->group_id);
                    }

                    $group_bots = App\Models\Bots::sortUserBots($group_bots);
                    $all = $all->merge($group_bots); // Add group bots to $all
                }

                // Get sorted private bots if the user has the "Store_read_discover_private_bots" permission
                if (request()->user()->hasPerm('Store_read_discover_private_bots')) {
                    $private_bots = $bots->where('visibility', '=', 3)->where('owner_id', '=', request()->user()->id);
                    $private_bots = App\Models\Bots::sortUserBots($private_bots);
                    $all = $all->merge($private_bots); // Add private bots to $all
                }
                $all = App\Models\Bots::sortUserBots($all);
            @endphp

            <div class="flex-1 flex overflow-hidden flex-col rounded">
                <div class="w-full">
                    <ul class="flex w-full text-sm font-medium text-center" data-tabs-toggle="#BotContents"
                        role="tablist">
                        <li class="flex-1" role="presentation">
                            <button class="w-full p-4 text-gray-700 dark:text-gray-200 bg-transparent" id="all-tab"
                                data-tabs-target="#all" type="button" role="tab" aria-controls="all"
                                aria-selected="{{ session('last_bot_tab') ? 'false' : 'true' }}">
                                {{ __('store.label.all_bots') }}
                            </button>
                        </li>
                        @if (request()->user()->hasPerm('Store_read_discover_system_bots'))
                            <li class="flex-1" role="presentation">
                                <button class="w-full p-4 text-gray-700 dark:text-gray-200 bg-transparent"
                                    id="system-tab" data-tabs-target="#system" type="button" role="tab"
                                    aria-controls="system"
                                    aria-selected="{{ session('last_bot_tab') == 'system' ? 'true' : 'false' }}">
                                    {{ __('store.label.system_bots') }}
                                </button>
                            </li>
                        @endif
                        @if (request()->user()->hasPerm('Store_read_discover_private_bots'))
                            <li class="flex-1" role="presentation">
                                <button class="w-full p-4 text-gray-700 dark:text-gray-200 bg-transparent"
                                    id="private-tab" data-tabs-target="#private" type="button" role="tab"
                                    aria-controls="private"
                                    aria-selected="{{ session('last_bot_tab') == 'private' ? 'true' : 'false' }}">
                                    {{ __('store.label.private') }}
                                </button>
                            </li>
                        @endif
                        @if (request()->user()->hasPerm('Store_read_discover_group_bots'))
                            <li class="flex-1" role="presentation">
                                <button class="w-full p-4 text-gray-700 dark:text-gray-200 bg-transparent"
                                    id="group-tab" data-tabs-target="#group" type="button" role="tab"
                                    aria-controls="group"
                                    aria-selected="{{ session('last_bot_tab') == 'group' ? 'true' : 'false' }}">
                                    {{ __('store.label.groups_bots') }}
                                </button>
                            </li>
                        @endif
                        @if (request()->user()->hasPerm('Store_read_discover_community_bots'))
                            <li class="flex-1" role="presentation">
                                <button class="w-full p-4 text-gray-700 dark:text-gray-200 bg-transparent"
                                    id="community-tab" data-tabs-target="#community" type="button" role="tab"
                                    aria-controls="community"
                                    aria-selected="{{ session('last_bot_tab') == 'community' ? 'true' : 'false' }}">
                                    {{ __('store.label.community_bots') }}
                                </button>
                            </li>
                        @endif
                    </ul>
                </div>

                <div id="BotContents" class="flex flex-1 overflow-hidden px-4 mb-2">
                    <!-- All Bots Tab Content -->
                    <div class="{{ session('last_bot_tab') ? 'hidden' : '' }} flex flex-1" id="all"
                        role="tabpanel" aria-labelledby="all-tab">
                        @if ($all->count() > 0)
                            <x-store.bot-showcase :bots="$all" :extra="'all_bots-'" />
                        @endif
                    </div>
                    <!-- System Bots Tab Content -->
                    @if (request()->user()->hasPerm('Store_read_discover_system_bots'))
                        <div class="{{ session('last_bot_tab') == 'system' ? '' : 'hidden' }} flex flex-1"
                            id="system" role="tabpanel" aria-labelledby="system-tab">
                            @if ($system_bots->count() > 0)
                                <x-store.bot-showcase :bots="$system_bots" :extra="'official_bots-'" />
                            @endif
                        </div>
                    @endif

                    <!-- Private Bots Tab Content -->
                    @if (request()->user()->hasPerm('Store_read_discover_private_bots'))
                        <div class="{{ session('last_bot_tab') == 'private' ? '' : 'hidden' }} flex flex-1"
                            id="private" role="tabpanel" aria-labelledby="private-tab">
                            @if ($private_bots->count() > 0)
                                <x-store.bot-showcase :bots="$private_bots" :extra="'my_bots-'" />
                            @endif
                        </div>
                    @endif

                    <!-- Group Bots Tab Content -->
                    @if (request()->user()->hasPerm('Store_read_discover_group_bots'))
                        <div class="{{ session('last_bot_tab') == 'group' ? '' : 'hidden' }} flex flex-1"
                            id="group" role="tabpanel" aria-labelledby="group-tab">
                            @if ($group_bots->count() > 0)
                                <x-store.bot-showcase :bots="$group_bots" :extra="'group_bots-'" />
                            @endif
                        </div>
                    @endif

                    <!-- Community Bots Tab Content -->
                    @if (request()->user()->hasPerm('Store_read_discover_community_bots'))
                        <div class="{{ session('last_bot_tab') == 'community' ? '' : 'hidden' }} flex flex-1"
                            id="community" role="tabpanel" aria-labelledby="community-tab">
                            @if ($community_bots->count() > 0)
                                <x-store.bot-showcase :bots="$community_bots" :extra="'community_bots-'" />
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
