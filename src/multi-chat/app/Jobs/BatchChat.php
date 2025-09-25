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
use App\Models\APIHistories;
use GuzzleHttp\Client;
use App\Models\User;
use App\Models\LLMs;
use App\Models\Bots;
use Carbon\Carbon;
use DB;

class BatchChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    private $prompts, $history_id, $user_id;
    public $tries = 1; # Believe if it fails, it always failed
    public $timeout = 6000; # Shouldn't takes longer than 100 mins
    /**
     * Create a new job instance.
     */
    public function __construct($prompts, $history_id)
    {
        $this->prompts = $prompts;
        $this->history_id = $history_id;
        $this->user_id = Chats::find(Histories::find($history_id)->chat_id)->user_id;
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
        $history = Histories::find($this->history_id);
        $chat_id = $history->chat_id;
        $bot = Bots::find(Chats::find($chat_id)->bot_id);
        $access_code = LLMs::find($bot->model_id)->access_code;
        $modelfile = json_decode($bot->config ?? '')->modelfile ?? null;
        $buffer = [];
        $input = Histories::where('chat_id', '=', $chat_id)->where('id', '<', $this->history_id)->select('msg', 'isbot');
        if ($history->chained) {
            $input = $input->orderby('created_at')->orderby('id', 'desc');
        } else {
            $input = $input->orderby('created_at', 'desc')->orderby('id')->limit(1);
        }
        $input = $input->get()->toArray();
        $dispatchedAccessCodes[] = $access_code;
        $dispatchedids[] = $this->history_id;
        foreach ($this->prompts as $index => $prompt) {
            // get new record
            $new_input = implode("\n", array_column(array_filter($buffer, fn($item) => $item['isbot']), 'msg'));
            if ($index != 0) {
                $buffer[] = ['msg' => $prompt, 'isbot' => false];
                
                Redis::rpush('usertask_' . $this->user_id, $this->history_id);
                //Redis::expire('usertask_' . $this->user_id, 1200);
            }

            RequestChat::dispatch(json_encode($history->chained ? array_merge($input, $buffer) : [['msg' => $prompt, 'isbot' => false]]), $access_code, $this->user_id, $this->history_id, App::getLocale(), $this->history_id, $modelfile, $new_input . "\n", $index === count($this->prompts) - 1);

            while (true) {
                $record = Histories::find($this->history_id);
            
                if ($record) {
                    $record = $record->fresh();
                    
                    if ($record->msg != '* ...thinking... *') {
                        $buffer[] = ['msg' => trim(mb_substr($record->msg, mb_strlen($new_input, 'UTF-8'), null, 'UTF-8')), 'isbot' => true];
                        if ($index === count($this->prompts) - 1) {
                            return;
                        }
                        $record->msg = '* ...thinking... *';
                        $record->save();
                        break;
                    }
                }
            
                sleep(0.5);
            }
            
        }
    }
    public function failed(\Throwable $exception)
    {
        Log::channel('analyze')->Info('Failed import job');
        Log::channel('analyze')->Info($exception);

        Redis::ltrim('usertask_' . $this->user_id, 1, 0);
    }
}
