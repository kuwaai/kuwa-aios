@props(['history', 'showOnFinished'])

@php
    $niceValue = filter_var($history->nice, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
@endphp

<div class="flex space-x-1{{ $showOnFinished ? ' show-on-finished' : '' }}"
    style="{{ $showOnFinished ? 'display:none;' : '' }}">
    <div id="{{ $history->id }}_react_copy" role="tooltip"
        class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500">
        {{ __('chat.button.copy') }}
        <div class="tooltip-arrow" data-popper-arrow></div>
    </div>
    <button
        class="flex text-black hover:bg-gray-400 p-2 h-[32px] w-[32px] justify-center items-center rounded-lg {{ $history->isbot ? '' : 'text-white' }}"
        data-tooltip-target="{{ $history->id }}_react_copy" data-tooltip-placement="top"
        onclick="copytext(this, histories[{{ $history->id }}])">
        <svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round"
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
        </svg>
    </button>
    @if ($history->isbot)
        @if (in_array('quote', json_decode($history->config)->react_btn ?? []) &&
                request()->user()->hasPerm('Room_update_react_message'))
            <div id="{{ $history->id }}_react_quote" role="tooltip"
                class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500">
                {{ __('chat.button.quote') }}
                <div class="tooltip-arrow" data-popper-arrow></div>
            </div>
            <button data-tooltip-target="{{ $history->id }}_react_quote" data-tooltip-placement="top"
                onclick="quote({{ $history->bot_id }}, {{ $history->id }}, this)"
                class="flex text-black hover:bg-gray-400 p-2 h-[32px] w-[32px] justify-center items-center rounded-lg">
                <svg xmlns="http://www.w3.org/2000/svg" height="8" width="8" viewBox="0 0 512 512">
                    <path
                        d="M464 32H336c-26.5 0-48 21.5-48 48v128c0 26.5 21.5 48 48 48h80v64c0 35.3-28.7 64-64 64h-8c-13.3 0-24 10.7-24 24v48c0 13.3 10.7 24 24 24h8c88.4 0 160-71.6 160-160V80c0-26.5-21.5-48-48-48zm-288 0H48C21.5 32 0 53.5 0 80v128c0 26.5 21.5 48 48 48h80v64c0 35.3-28.7 64-64 64h-8c-13.3 0-24 10.7-24 24v48c0 13.3 10.7 24 24 24h8c88.4 0 160-71.6 160-160V80c0-26.5-21.5-48-48-48z" />
                </svg>
                <svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round"
                    stroke-linejoin="round" class="icon-sm" style="display:none;" height="1em" width="1em"
                    xmlns="http://www.w3.org/2000/svg">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </button>
        @endif
        @if (in_array('feedback', json_decode($history->config)->react_btn ?? []) &&
            request()->user()->hasPerm('ROOM_UPDATE_FEEDBACK'))
            <div id="{{ $history->id }}_react_like" role="tooltip"
                class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500">
                {{ __('chat.button.like') }}
                <div class="tooltip-arrow" data-popper-arrow></div>
            </div>
            <button data-tooltip-target="{{ $history->id }}_react_like" data-tooltip-placement="top"
                class="flex hover:bg-gray-400 p-2 h-[32px] w-[32px] justify-center items-center rounded-lg {{ $niceValue === true ? 'text-green-600' : 'text-black dark:text-white' }}"
                @if (request()->user()->hasPerm('ROOM_UPDATE_DETAIL_FEEDBACK')) data-modal-target="feedback_modal" data-modal-toggle="feedback_modal" @endif
                onclick="feedback({{ $history->id }},1,this,{!! htmlspecialchars(
                    json_encode(['detail' => $history->detail, 'flags' => $history->flags, 'nice' => $niceValue]),
                ) !!});">
                <i class="far fa-thumbs-up"></i>
            </button>
            <div id="{{ $history->id }}_react_dislike" role="tooltip"
                class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500">
                {{ __('chat.button.dislike') }}
                <div class="tooltip-arrow" data-popper-arrow></div>
            </div>
            <button data-tooltip-target="{{ $history->id }}_react_dislike" data-tooltip-placement="top"
                class="flex hover:bg-gray-400 p-2 h-[32px] w-[32px] justify-center items-center rounded-lg {{ $niceValue === false ? 'text-red-600' : 'text-black dark:text-white' }}"
                data-modal-target="feedback_modal" data-modal-toggle="feedback_modal"
                onclick="feedback({{ $history->id }},2,this,{!! htmlspecialchars(
                    json_encode(['detail' => $history->detail, 'flags' => $history->flags, 'nice' => $niceValue]),
                ) !!});">
                <i class="far fa-thumbs-down"></i>
            </button>
        @endif
    @endif
    @if (in_array('other', json_decode($history->config)->react_btn ?? []) &&
            request()->user()->hasPerm('Room_update_react_message'))
        <div id="{{ $history->id }}_react_delete" role="tooltip"
            class="absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500">
            {{ __('chat.button.delete') }}
            <div class="tooltip-arrow" data-popper-arrow></div>
        </div>
        <button data-tooltip-target="{{ $history->id }}_react_delete" data-tooltip-placement="top"
            onclick="delete_msg({{ $history->id }})"
            class="flex {{ $history->isbot ? 'text-black' : 'text-white' }} hover:bg-gray-400 p-2 h-[32px] w-[30px] justify-center items-center rounded-lg translates">
            <i class="far fa-trash-alt"></i>
        </button>
    @endif
</div>
