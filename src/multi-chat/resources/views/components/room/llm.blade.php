@props(['result', 'extra' => ''])
<div id='groupingSelector' class="flex items-center justify-center mb-2" style='display:none;'>
    <!-- Group by Model Radio -->
    <div class="relative w-full">
        <input onchange='refreshRoom($(this).val());' value='groupByIdentifier' type="radio"
            id="{{ $extra }}-groupByModel" name="room_group_selector" class="hidden peer" />
        <label for="{{ $extra }}-groupByModel"
            class="block px-4 py-2 text-center text-xs font-medium text-gray-600 bg-gray-200 dark:text-gray-300 dark:bg-gray-700 rounded-tl-lg rounded-bl-lg cursor-pointer peer-checked:bg-blue-600 dark:peer-checked:bg-blue-600 peer-checked:text-white dark:peer-checked:text-white peer-checked:font-semibold peer-checked:rounded-l-lg transition-all">
            {{ __('room.button.group_by_bot') }}
        </label>
    </div>

    <!-- Group by Time Radio -->
    <div class="relative w-full">
        <input type="radio" onchange='refreshRoom($(this).val());' value='groupByTime'
            id="{{ $extra }}-groupByTime" name="room_group_selector" class="hidden peer" />
        <label for="{{ $extra }}-groupByTime"
            class="block px-4 py-2 text-center text-xs font-medium text-gray-600 bg-gray-200 dark:text-gray-300 dark:bg-gray-700 rounded-tr-lg rounded-br-lg cursor-pointer peer-checked:bg-blue-600 dark:peer-checked:bg-blue-600 peer-checked:text-white dark:peer-checked:text-white peer-checked:font-semibold peer-checked:rounded-r-lg transition-all">
            {{ __('room.button.group_by_time') }}
        </label>
    </div>
</div>

<div class="flex flex-col overflow-y-auto scrollbar roomlist flex-1">
</div>
<div class="absolute inset-0 flex items-center justify-center" style='display:none;'>
    <button class='bg-gray-500 dark:bg-gray-700 px-2 py-1 rounded-lg hover:dark:bg-gray-600 hover:bg-gray-600'
        onclick='refreshRoom(selectedGroup);'>{{ __('room.button.reload') }}</button>
</div>

@once
    <script>
        function generateDropdown(data) {
            const dropdownDiv = $('<div>', {
                id: 'chatroom_info_dropdown',
                class: 'z-50 bg-gray-200 border border-black dark:border-white divide-y divide-gray-100 rounded-lg shadow w-44 dark:bg-gray-700 hidden',
                'data-popper-escaped': '',
                'data-popper-placement': 'bottom'
            }).append(
                $('<ul>', {
                    class: 'py-2 text-sm text-gray-700 dark:text-gray-200',
                    'aria-labelledby': 'dropdownDefaultButton'
                }).append(
                    $('<li>', {
                        class: 'flex px-4'
                    }).append(
                        $.map(data, (item, id) => $('<div>', {
                            class: 'relative mx-1 flex-shrink-0 h-5 w-5 rounded-full bg-black flex items-center justify-center'
                        }).append(
                            $('<div>', {
                                'data-tooltip-target': `llm_${id}_dropdown`,
                                'data-tooltip-placement': 'top',
                                class: 'flex justify-center items-center h-full'
                            }).append(
                                $('<img>', {
                                    class: 'rounded-full bg-black w-full h-full',
                                    src: item.image || item.base_image || '/{{ config('app.LLM_DEFAULT_IMG') }}'
                                }),
                                $('<div>', {
                                    class: `absolute bottom-0 right-0 z-2 opacity-90 ${item.healthy ? 'hidden' : ''}`
                                }).append($('<div>', {
                                    class: 'bg-red-500 rounded-full w-2 h-2',
                                    'data-updated-at': item.updated_at
                                }))
                            ),
                            $('<div>', {
                                id: `llm_${id}_dropdown`,
                                role: 'tooltip',
                                class: 'absolute z-10 invisible inline-block px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm opacity-0 tooltip dark:bg-gray-500',
                                text: item.name
                            }),
                        ))
                    ),
                    $('<li>').append($('<a>', {
                        target: 'new',
                        class: 'block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 dark:hover:text-white !text-green-500 hover:!text-green-600',
                        text: '{{ __("room.button.share_link") }}'
                    })),
                    $('<li>').append($('<a>', {
                        href: '#',
                        class: 'block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 dark:hover:text-white !text-red-500 hover:!text-red-600',
                        text: '{{ __("chat.button.delete") }}'
                    }).on('click', function(e) {
                        e.preventDefault();
                        $('#delete_chat_modal').removeClass('hidden');
                    }))
                )
            );

            $('body').append(dropdownDiv);
            $('#chatroom_info_dropdown [data-tooltip-target]').each(function() {
                new Tooltip($('#' + $(this).attr('data-tooltip-target'))[0], $('#' + $(this).attr(
                    'data-tooltip-target')).prev()[0]).init()
            });
        }

        let currentButton = null;

        function toggleDropdown() {
            const $button = $(this);
            const $menu = $('#chatroom_info_dropdown');
            if (currentButton === $button[0]) {
                $menu.addClass('hidden').removeClass('block').css({});
                currentButton = null;
                return;
            }
            $menu.find('>ul >li:nth-child(2) >a').attr('href', $(this).attr('shareUrl'))
            $('#delete_chat_modal input[name=id]').val($(this).attr('id'));
            $('#delete_chat_modal h3 span:eq(1)').text(`<${$(this).attr('name')}>`);
            $menu.find('>ul >li:first() >div').hide()

            const $ids = $button.attr('identifier').match(/\d+/g).map(Number);
            const targetSelector = $ids.map(id => `[data-tooltip-target="llm_${id}_dropdown"]`).join(',');
            $menu.find(`>ul >li:first >div >div${targetSelector}`).parent().show();

            const buttonRect = this.getBoundingClientRect();
            const translateX = buttonRect.right;
            const translateY = buttonRect.bottom;

            const position = {
                position: 'absolute',
                inset: '0px auto auto 0px',
                margin: '0px',
                transform: `translate3d(${translateX}px, ${translateY}px, 0px)`
            };

            $menu.removeClass('hidden').css(position).addClass('block');

            const menuRect = $menu[0].getBoundingClientRect();
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;

            if (menuRect.right > viewportWidth) {
                const overflowX = menuRect.right - viewportWidth;
                $menu.css('transform', `translate3d(${translateX - overflowX}px, ${translateY}px, 0px)`);
            }

            if (menuRect.bottom > viewportHeight) {
                const overflowY = menuRect.bottom - viewportHeight;
                $menu.css('transform', `translate3d(${translateX}px, ${translateY - overflowY}px, 0px)`);
            }

            currentButton = $button[0];

            $(document).on('click.dropdown', function(e) {
                if (!$(e.target).closest($('.flex.flex-col.overflow-y-auto.scrollbar.roomlist.flex-1 >div'))
                    .length) {

                    $menu.addClass('hidden').removeClass('block').css({});
                    $(document).off('click.dropdown');
                    currentButton = null;
                }
            });
        }

        function refreshChatRoomList(groupedChatRooms, canDelete, botData, byTime) {
            const container = $(".flex.flex-col.overflow-y-auto.scrollbar.roomlist.flex-1").empty();
            if (selected) container.addClass('border border-black dark:border-white border-1 rounded-lg overflow-hidden')
            const currentPath = window.location.pathname;
            let sortedGroups = sortGroupsByRecentUpdate(groupedChatRooms);
            var roomIdx = 0
            if (selected && Object.keys(groupedChatRooms).length == 0) {
                groupedChatRooms = {
                    selected: []
                }
                sortedGroups = [selected]
            }
            sortedGroups.forEach(group => {
                if (byTime) {
                    container.append(createGroupHeader(group));
                } else {
                    container.append(createNewRoomForm(roomIdx, group, selected == ''));
                    if (selected) container.append($('<div>', {
                        class: 'm-2 border border-green-400 border-1 rounded-lg h-12 overflow-hidden'
                    }).append($('<form>', {
                        method: 'post',
                        action: '{{ route('room.new') }}'
                    }).append($('<div>', {
                        class: 'flex items-end justify-end'
                    }).append($('<input>', {
                        name: '_token',
                        value: "{{ csrf_token() }}",
                        type: 'hidden'
                    }), $('<button>', {
                        class: `${currentPath.endsWith('/room') ? 'bg-gray-200 dark:bg-gray-700' : ''} flex menu-btn flex text-green-400 w-full overflow-y-auto scrollbar dark:hover:bg-gray-700 hover:bg-gray-200 transition duration-300`
                    }).append($('<p>', {
                        class: 'flex-1 flex items-center h-12 my-auto justify-center text-center leading-none self-baseline'
                    }).text('{{ __('room.header.new_room') }}')), group.match(/\d+/g).map(
                        Number).map(id => $('<input>', {
                        name: 'llm[]',
                        value: id,
                        type: 'hidden'
                    }))))))
                    roomIdx += 1;
                }
                const chatRooms = groupedChatRooms[group];
                if (!chatRooms || chatRooms.length == 0) return;

                const sortedRooms = sortRoomsByUpdateTime(chatRooms);

                sortedRooms.forEach(dc => {
                    container.append(createRoomElement(dc, currentPath));
                });
            });
        }

        function sortGroupsByRecentUpdate(groupedChatRooms) {
            return Object.keys(groupedChatRooms).sort((a, b) => {
                const getLatestUpdatedAt = (group) => {
                    const latestRoom = groupedChatRooms[group].reduce((latest, room) =>
                        new Date(room.updated_at) > new Date(latest.updated_at) ? room : latest
                    );
                    return latestRoom.updated_at;
                };
                return new Date(getLatestUpdatedAt(b)) - new Date(getLatestUpdatedAt(a));
            });
        }

        function sortRoomsByUpdateTime(chatRooms) {
            return chatRooms.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
        }

        function createGroupHeader(group) {
            return $("<h3>")
                .addClass("my-1 font-bold text-xs text-gray-800 dark:text-gray-300 text-center")
                .text(group);
        }

        function createNewRoomForm(roomIdx, group, clickable) {
            const $ids = group.match(/\d+/g).map(Number);
            result = $(selected ? '<div>' : '<form>', selected ? {
                class: 'flex items-center px-2 scrollbar scrollbar-3 overflow-x-auto overflow-y-hidden py-3 border-b border-black dark:border-white'
            } : {
                method: 'post',
                action: '{{ route('room.new') }}'
            }).append(
                $('<input>', {
                    name: '_token',
                    value: "{{ csrf_token() }}",
                    type: 'hidden'
                }),
                selected ? $ids.map(id => createRoomClone(roomIdx, id)) : $('<button>', {
                    class: 'flex items-center px-2 scrollbar rounded-t-lg w-full hover:bg-gray-300 dark:hover:bg-gray-700 py-3 border-b border-black dark:border-white'
                }).append(
                    $ids.map(id => createRoomClone(roomIdx, id))
                )
            );
            if (result.find(selected ? ">div" : '>button >div').length == 1) {
                (selected ? result : result.children())
                .append($('<span>', {
                    class: 'text-center w-full line-clamp-1 text-black dark:text-white'
                }).text(result.find('[role=tooltip]').text()))
            }
            return result
        }

        function createRoomClone(roomIdx, id) {
            const clonedElement = $(
                    `#chatroom_info_dropdown >ul >li:first >div >div[data-tooltip-target="llm_${id}_dropdown"]`)
                .parent().clone();
            clonedElement.each(function() {
                $(this).attr('class',
                    'mx-1 flex-shrink-0 h-5 w-5 rounded-full border border-gray-400 dark:border-gray-900 bg-black flex items-center justify-center'
                ).show();
                const base = clonedElement.find(`#llm_${id}_dropdown`);
                base.attr('id', `${roomIdx}-llm_${id}`);
                const trigger = base.prev().addClass('relative');
                trigger.attr('data-tooltip-target', `${roomIdx}-llm_${id}`);
                new Tooltip(base[0], trigger[0]).init();
            });
            return clonedElement.append($('<input>', {
                name: 'llm[]',
                value: id,
                type: 'hidden'
            }));
        }

        function createRoomElement(dc, currentPath) {
            const chatUrl = `/room/${dc.id}`;
            const isActive = currentPath === chatUrl;

            return $("<div>").addClass("rounded-lg").append(
                $("<div>").addClass("max-h-[182px] overflow-y-auto scrollbar").append(
                    $("<div>").addClass(
                        `overflow-hidden rounded mb-1 flex dark:hover:bg-gray-700 hover:bg-gray-200 ${isActive ? "bg-gray-200 dark:bg-gray-700" : ""}`
                    )
                    .append(
                        $("<a>").addClass(
                            "menu-btn flex-1 text-gray-700 px-2 dark:text-white w-full flex justify-start items-center overflow-hidden transition duration-300"
                        )
                        .attr("href", chatUrl)
                        .append($("<p>").addClass(
                            "px-4 m-auto text-center leading-none truncate-text overflow-ellipsis overflow-hidden max-h-4"
                        ).text(
                            dc.name)),
                        $("<button>").addClass(
                            `p-1 m-auto h-[32px] text-black hover:text-black dark:text-white dark:hover:text-gray-300 ${isActive ? "bg-gray-200 dark:bg-gray-700" : ""}`
                        )
                        .attr({
                            shareUrl: `/room/share/${dc.id}`,
                            identifier: dc.identifier,
                            id: dc.id,
                            name: dc.name
                        })
                        .on('click', toggleDropdown)
                        .append($("<div>").addClass('text-[4px] tracking-[3px] m-auto').text('●●●'))
                    )
                )
            );
        }

        function validateIdentifiers(arr, selected) {
            const botKeys = Object.keys(botData).map(Number);
            result = arr.filter(item => {
                const digits = item.identifier.split(",").map(Number);
                return digits.every(id => botKeys.includes(id));
            });
            if (selected != '') {
                result = result.filter(item => {
                    return item.identifier == selected
                })
            }
            return result
        }

        function groupByIdentifier(arr) {
            return arr.reduce(function(acc, item) {
                const key = item.identifier;
                if (!acc[key]) {
                    acc[key] = [];
                }
                acc[key].push(item);
                return acc;
            }, {});
        }

        function groupByTime(arr) {
            const now = new Date();

            arr.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));

            const grouped = arr.reduce((acc, item) => {
                const updatedAt = new Date(item.updated_at);
                const category = getDateCategory(updatedAt, now);

                if (!acc[category]) acc[category] = [];
                acc[category].push(item);
                return acc;
            }, {});

            const categoryOrder = [
                '{{ __('messages.category.today') }}',
                '{{ __('messages.category.yesterday') }}',
                '{{ __('messages.category.this_week') }}',
                '{{ __('messages.category.this_month') }}'
            ];

            const dynamicCategories = Object.keys(grouped).filter(c => !categoryOrder.includes(c))
                .sort((a, b) => new Date(b.split(' ')[1], new Date(`${b} 1`).getMonth()) - new Date(a.split(' ')[1],
                    new Date(`${a} 1`).getMonth()));

            return [...categoryOrder, ...dynamicCategories].reduce((acc, category) => {
                if (grouped[category]) acc[category] = grouped[category];
                return acc;
            }, {});
        }

        function getDateCategory(date, now) {
            const startOfWeek = new Date(now);
            startOfWeek.setDate(now.getDate() - now.getDay());

            if (isSameDay(date, now)) return '{{ __('messages.category.today') }}';
            if (isSameDay(date, new Date(now.setDate(now.getDate() - 1))))
                return '{{ __('messages.category.yesterday') }}';
            if (date >= startOfWeek) return '{{ __('messages.category.this_week') }}';
            if (date.getMonth() === now.getMonth() && date.getFullYear() === now.getFullYear())
                return '{{ __('messages.category.this_month') }}';
            return `${date.toLocaleString('default', { month: 'long' })} ${date.getFullYear()}`;
        }

        function isSameDay(d1, d2) {
            return d1.getDate() === d2.getDate() &&
                d1.getMonth() === d2.getMonth() &&
                d1.getFullYear() === d2.getFullYear();
        }

        function addLoadingIndicator() {
            var $outerDiv = $('<div>', {
                role: 'status',
                class: 'space-y-3 rounded animate-pulse dark:divide-gray-700 p-2 mt-2 dark:border-gray-700'
            });

            var numberOfBars = Math.floor(Math.random() * (15 - 5 + 1)) + 5;

            for (var i = 0; i < numberOfBars; i++) {
                var $flexDiv = $('<div>', {
                    class: 'flex items-center justify-between' + (i > 0 ? ' pt-2' : '')
                });

                var $leftDiv = $('<div>');

                var randomWidth = Math.floor(Math.random() * (150 - 20 + 1)) + 20;

                $('<div>', {
                    class: 'h-2.5 bg-gray-300 rounded-full dark:bg-gray-600',
                    css: {
                        width: randomWidth + 'px'
                    }
                }).appendTo($leftDiv);

                $leftDiv.appendTo($flexDiv);

                $('<div>', {
                    class: 'h-2.5 bg-gray-300 rounded-full dark:bg-gray-700 w-10'
                }).appendTo($flexDiv);

                $flexDiv.appendTo($outerDiv);
            }

            $('<span>', {
                class: 'sr-only',
                text: 'Loading...'
            }).appendTo($outerDiv);

            $(".flex.flex-col.overflow-y-auto.scrollbar.roomlist.flex-1").append($outerDiv);
        }

        let botData = {};
        let selected = '';

        function refreshRoom(method) {
            localStorage.setItem('kuwa-room_group_selector', method);
            $('#groupingSelector input').attr('readonly', true).attr('disabled', true)
            base = $(".flex.flex-col.overflow-y-auto.scrollbar.roomlist.flex-1")
            base.empty()
            base.next().hide()
            addLoadingIndicator();
            client.listBots()
                .then(bots => {
                    botData = bots.result.reduce((acc, bot) => {
                        const {
                            id,
                            ...rest
                        } = bot;
                        acc[id] = rest;
                        return acc;
                    }, {});

                    client.listRooms()
                        .then(rooms => {
                            const roomItems = Array.isArray(rooms?.result) ? rooms.result : [];
                            generateDropdown(botData)
                            if (selected != '') method = groupByIdentifier
                            else if (method == 'groupByIdentifier') method = groupByIdentifier
                            else if (method == 'groupByTime') method = groupByTime
                            refreshChatRoomList(method(validateIdentifiers(roomItems, selected)),
                                {{ request()->user()->hasPerm('Room_delete_chatroom') }}, botData, method ==
                                groupByTime);
                            $('#groupingSelector input').attr('readonly', false).attr('disabled', false)
                        })
                        .catch(error => {
                            console.error('Error:', error)
                            $('#groupingSelector input').attr('readonly', false).attr('disabled', false)
                            base.next().show()
                        })
                })
                .catch(error => {
                    console.error('Error:', error)
                    $('#groupingSelector input').attr('readonly', false).attr('disabled', false)
                    base.next().show()
                });
        }
        let selectedGroup = null
        $(document).ready(function() {
            selectedGroup = localStorage.getItem('kuwa-room_group_selector') || 'groupByTime';
            selected = $('input[name="chatsTo[]"]').map((_, el) => el.value).get().sort((a, b) => a - b).join(',');
            if (selected == '') {
                $('div[id=groupingSelector]').show()
            }
            $('input[name="room_group_selector"][value="' + selectedGroup + '"]').prop('checked', true);
            refreshRoom(selectedGroup)
        });
    </script>
@endonce
