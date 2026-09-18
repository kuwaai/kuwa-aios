<?php

namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\App;
use Illuminate\Bus\Queueable;
use App\Models\Histories;
use App\Models\Chats;
use GuzzleHttp\Client;
use App\Models\User;
use App\Models\LLMs;
use App\Models\Bots;
use Carbon\Carbon;
use DB;

class ImportChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    private $ids, $access_code, $user_id;
    public $tries = 1; # Believe if it fails, it always failed
    public $timeout = 6000; # Shouldn't takes longer than 100 mins
    public $kernel_api_version = 'v1.0';
    /**
     * Create a new job instance.
     */
    public function __construct($ids, $user_id)
    {
        $this->ids = $ids;
        $this->user_id = $user_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $dispatchedAccessCodes = [];
        $dispatchedids = [];
        foreach ($this->ids as $id) {
            $history = Histories::find($id);
            $bot = Bots::find(Chats::find($history->chat_id)->bot_id);
            $access_code = LLMs::find($bot->model_id)->access_code;
            $modelfile = json_decode($bot->config ?? '')->modelfile ?? null;
            if (in_array($access_code, $dispatchedAccessCodes)) {
                while (count($dispatchedAccessCodes) > 0) {
                    // Retrieve the data from Redis
                    $redisData = Redis::lrange('usertask_' . $this->user_id, 0, -1);
                    // Filter the data based on $dispatchedAccessCodes
                    $filteredData = array_filter($dispatchedids, function ($history_id) use ($redisData) {
                        // Assuming $item is a JSON-encoded string, you may need to decode it if it's a different format
                        return !in_array($history_id, $redisData);
                    });
                    foreach ($filteredData as $id2){
                        $access_code2 = LLMs::find(Bots::find(Chats::find(Histories::find($id2)->chat_id)->bot_id)->model_id)->access_code;
                        unset($dispatchedAccessCodes[array_search($access_code2, $dispatchedAccessCodes)]);
                        unset($dispatchedids[array_search($id2, $dispatchedids)]);
                    }
                    sleep(1);
                }
            }
            $dispatchedAccessCodes[] = $access_code;
            $dispatchedids[] = $id;
            // get new record
            $history = Histories::find($id);
            $input = Histories::where('chat_id', '=', $history->chat_id)
                ->where('id', '<', $id)
                ->select('msg', 'isbot');
            if (Histories::find($id)->chained) {
                $input = $input->orderby('created_at')->orderby('id', 'desc');
            } else {
                $input = $input
                    ->orderby('created_at', 'desc')
                    ->orderby('id')
                    ->limit(1);
            }
            $input = $input->get()->toJson();
            RequestChat::dispatch($input, $access_code, $this->user_id, $id, App::getLocale(), null, $modelfile);
        }
    }
    public function failed(\Throwable $exception)
    {
        Log::channel('analyze')->Info('Failed import job');

        Redis::ltrim('usertask_' . $this->user_id, 1, 0);
    }
}
