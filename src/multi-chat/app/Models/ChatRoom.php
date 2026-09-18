<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ChatRoom extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $table = 'chatrooms';
    protected $fillable = ['name', 'user_id', 'updated_at', 'status'];

    public function getStatusEntries(): array
    {
        $entries = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $this->status) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['bot_id'])) {
                $entries[$decoded['bot_id']] = $decoded;
            }
        }
        return $entries;
    }

    public function setBotStatus(int $botId, string $status, ?string $timestamp = null, bool $save = true): array
    {
        $entries = $this->getStatusEntries();
        $entries[$botId] = [
            'status' => $status,
            'timestamp' => $timestamp ?? now()->toDateTimeString(),
            'bot_id' => $botId,
        ];
        $this->status = implode("\n", array_map(
            fn (array $entry): string => json_encode($entry, JSON_UNESCAPED_UNICODE),
            $entries
        ));
        if ($save) {
            $this->save();
        }
        return $entries;
    }
    public static function getChatRoomsWithIdentifiers(int $userId)
    {
        $query = self::leftJoin('chats', 'chatrooms.id', '=', 'chats.roomID')->where('chats.user_id', $userId)->select('chatrooms.*', DB::raw('count(chats.id) as counts'))->groupBy('chatrooms.id')->selectSub(self::buildIdentifierSubquery(), 'identifier');

        return $query->get()->groupBy('identifier')->reverse();
    }

    public static function getRawChatRoomData(int $userId)
    {
        $query = self::leftJoin('chats', 'chatrooms.id', '=', 'chats.roomID')->where('chats.user_id', $userId)->select('chatrooms.*', DB::raw('count(chats.id) as counts'))->groupBy('chatrooms.id')->orderby('chatrooms.updated_at', 'desc')->selectSub(self::buildIdentifierSubquery(), 'identifier');

        return $query->get();
    }
    // Get chats based on whether they are bot chats or non-bot chats
    public static function getChats($roomId, $isBot)
    {
        return Chats::join('histories', 'chats.id', '=', 'histories.chat_id')
            ->leftJoin('feedback', 'history_id', '=', 'histories.id')
            ->join('bots', 'bots.id', '=', 'chats.bot_id')
            ->Join('llms', function ($join) {
                $join->on('llms.id', '=', 'bots.model_id');
            })
            ->where('isbot', $isBot)
            ->whereIn('chats.id', Chats::where('roomID', $roomId)->pluck('id'))
            ->select('histories.chained as chained', 'chats.id as chat_id', 'histories.id as id', 'chats.bot_id as bot_id', 'histories.created_at as created_at', 'histories.msg as msg', 'histories.isbot as isbot', DB::raw('COALESCE(bots.description, llms.description) as description'), DB::raw('COALESCE(bots.config, llms.config) as config'), DB::raw('COALESCE(bots.image, llms.image) as image'), DB::raw('COALESCE(bots.name, llms.name) as name'), 'feedback.nice', 'feedback.detail', 'feedback.flags', 'access_code');
    }
    // Merge and filter chats
    public static function getMergedChats($roomId)
    {
        $botChats = self::getChats($roomId, true); // Fetch bot chats
        $nonBotChats = self::getChats($roomId, false); // Fetch non-bot chats
        $mergedChats = $botChats
            ->union($nonBotChats)
            ->get()
            ->sortBy(function ($chat) {
                return [$chat->created_at, $chat->id, $chat->bot_id, -$chat->id];
            });

        return self::filterMergedChats($mergedChats);
    } // Filter merged chats to avoid duplicates
    private static function filterMergedChats($mergedChats)
    {
        $mergedMessages = [];
        return $mergedChats
            ->filter(function ($chat) use (&$mergedMessages) {
                if (!$chat->isbot && !in_array($chat->msg, $mergedMessages)) {
                    $mergedMessages[] = $chat->msg;
                    return true;
                } elseif ($chat->isbot) {
                    $mergedMessages = []; // Reset merged messages on bot chat
                    return true;
                }
                return false; // Exclude duplicate non-bot chats
            })
            ->sortBy(function ($chat) {
                return [$chat->created_at, $chat->bot_id, -$chat->id];
            });
    }

    private static function buildIdentifierSubquery()
    {
        return function ($query) {
            $db = config('database.default');

            if ($db === 'sqlite') {
                $query->from('chats')->selectRaw("group_concat(bot_id, ',') as identifier")->whereColumn('roomID', 'chatrooms.id')->orderBy('bot_id');
            } elseif ($db === 'mysql') {
                $query->from('chats')->selectRaw("group_concat(bot_id order by bot_id separator ',') as identifier")->whereColumn('roomID', 'chatrooms.id');
            } elseif ($db === 'pgsql') {
                $query->from('chats')->selectRaw("string_agg(bot_id::text, ',' order by bot_id) as identifier")->whereColumn('roomID', 'chatrooms.id');
            }
        };
    }
}
