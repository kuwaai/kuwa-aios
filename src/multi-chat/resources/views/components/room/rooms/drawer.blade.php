{{-- This component will only appear in the mobile view --}}
@props(['result' => null])

<div id="chatlist_drawer"
    class="fixed sm:hidden top-0 left-0 z-40 h-screen p-4 overflow-hidden transition-transform -translate-x-full bg-white w-80 dark:bg-gray-800 overflow-y-auto scrollbar"
    tabindex="-1">
    <div class="flex flex-col h-full">
        <div class="mb-2">
            <div class="border border-black dark:border-white border-1 rounded-lg flex overflow-hidden">
                @if (request()->route('room_id') || session('llms'))
                    <a href="{{ route('room.home') }}"
                        class="flex justify-center transition items-center px-4 cursor-pointer hover:bg-gray-200 text-black dark:text-white dark:hover:bg-gray-500 rounded-l-lg duration-300">←</a>
                @endif
                @if (request()->user()->hasPerm('Room_update_new_chat'))
                    <button data-modal-target="create-model-modal" data-modal-toggle="create-model-modal"
                        class="cursor-pointer flex w-full {{request()->route('room_id') || session('llms') ? 'border-x' : 'border-r' }} border-1 border-black dark:border-white menu-btn flex items-center justify-center h-12 dark:hover:bg-gray-700 hover:bg-gray-200 transition duration-300">

                        <p class="flex-1 text-center text-gray-700 dark:text-white">
                            {{ __('room.button.create_room') }}
                        </p>
                    </button>
                @endif
                @if (request()->user()->hasPerm('Room_update_import_chat'))
                    <button data-modal-target="importModal" data-modal-toggle="importModal"
                        class="cursor-pointer bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 {{ request()->user()->hasPerm('Room_update_new_chat') ? 'rounded-r-lg ' : 'rounded-lg w-full' }} flex items-center justify-center transition duration-300">
                        {{ request()->user()->hasPerm('Room_update_new_chat') ? '' : __('chat.button.header.import') }}
                        <i class="fas fa-file-import"></i>
                    </button>
                @endif
            </div>
        </div>
        <x-room.rooms.list :result="$result" :extra="'drawer-'" />
    </div>
</div>
