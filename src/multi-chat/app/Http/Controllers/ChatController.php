<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\App;
use App\Http\Requests\ChatRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\ProfileController;
use App\Models\APIHistories;
use App\Models\Histories;
use App\Jobs\RequestChat;
use App\Models\Feedback;
use GuzzleHttp\Client;
use App\Models\Chats;
use App\Models\LLMs;
use App\Models\User;
use App\Models\Groups;
use App\Models\Bots;
use DB;
use Session;

class ChatController extends Controller
{
    public function translate(Request $request)
    {
        $record = Histories::find($request->route('history_id'));

        if (!$record) {
            return response('Unauthorized', 401);
        }

        $chat = Chats::find($record->chat_id);

        if (!$chat || $chat->user_id !== Auth::user()->id) {
            return response('Unauthorized', 401);
        }

        $access_code = $request->input('model');
        $msg = $record->msg;
        if ($access_code == null && strpos(Groups::find($request->user()->group_id)->describe, '!verilog_translate!') === 0) {
            $access_code = LLMs::findOrFail(Bots::findOrFail($chat->bot_id)->model_id)->access_code;
            $msg = "請將程式碼轉成verilog。\n" . $msg;
        } elseif ($access_code == null) {
            $access_code = LLMs::findOrFail(Bots::findOrFail($chat->bot_id)->model_id)->access_code;
            $msg = "以下提供內容，請幫我翻譯成中文。\n" . $msg;
        }

        $tmp = json_encode([['isbot' => false, 'msg' => $msg]]);
        $history = new APIHistories();
        $history->fill(['input' => $tmp, 'output' => '* ...thinking... *', 'user_id' => Auth::user()->id]);
        $history->save();
        
        Redis::rpush('api_' . Auth::user()->id, $history->id);
        //Redis::expire('api_' . Auth::user()->id, 1200);
        RequestChat::dispatch($tmp, $access_code, Auth::user()->id, $history->id, App::getLocale(), 'api_' . $history->id);

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('charset', 'utf-8');
        $response->headers->set('Connection', 'close');

        $response->setCallback(function() use (&$history) {
            $backend_callback = function ($event, $message){
                if ($event == 'Ended') {
                    echo "\n";
                    ob_flush();
                    flush();
                } elseif ($event == 'New') {
                    echo $message;
                    ob_flush();
                    flush();
                } elseif ($event == 'Error') {
                    throw new \Exception($message);
                }
            };
            ProfileController::read_backend_stream(
                $history->id,
                Auth::user()->id,
                $backend_callback
            );

            ob_flush();
            flush();
        });

        return $response;
    }

    public function update_chain(Request $request)
    {
        $state = ($request->input('switch') ?? true) == 'true';
        Session::put('chained', $state);
    }
    public function feedback(Request $request)
    {
        $history_id = $request->input('history_id');
        if ($history_id) {
            $tmp = Histories::find($history_id);
            if ($tmp) {
                $tmp = Chats::find($tmp->chat_id)->roomID;
                if (($tmp != null && Auth::user()->hasPerm('Room_update_feedback')) || ($tmp == null && Auth::user()->hasPerm('Chat_update_feedback'))) {
                    $nice = $request->input('type') == '1';
                    $detail = $request->input('feedbacks');
                    $flag = $request->input('feedback');
                    $init = $request->input('init');
                    $feedback = new Feedback();
                    if (Feedback::where('history_id', '=', $history_id)->exists()) {
                        $feedback = Feedback::where('history_id', '=', $history_id)->first();
                    }
                    if ($init) {
                        $feedback->fill(['history_id' => $history_id, 'nice' => $nice, 'detail' => null, 'flags' => null]);
                    } else {
                        $feedback->fill(['history_id' => $history_id, 'nice' => $nice, 'detail' => $detail, 'flags' => $flag == null ? null : json_encode($flag)]);
                    }
                    $feedback->save();
                }
            }
        }
        return response()->noContent();
    }

    public function SSE(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $awaiting = $request->input('listening');
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('charset', 'utf-8');
        $response->headers->set('Connection', 'close');
        set_exception_handler(function ($exception) {
            if ($exception->getMessage() != 'Connection closed') {
                Log::error('Uncaught SSE Exception: ' . $exception->getMessage());
            }
        });
        $response->setCallback(function () use ($response, $request, $awaiting) {
            global $listening;
            echo "event: listening\n\n";
            ob_flush();
            flush();
            $listening = Redis::lrange('usertask_' . Auth::user()->id, 0, -1);
            if ($awaiting) {
                $union = array_merge($listening, $awaiting);
                $listening = array_unique($union);
            }
            if (count($listening) > 0) {
                foreach ($listening as $i) {
                    $history = Histories::find($i);
                    if (!$history) {
                        unset($listening[array_search($i, $listening)]);
                    } elseif ($history && $history->msg !== '* ...thinking... *') {
                        echo 'data: ' . json_encode(['history_id' => $i, 'msg' => $history->msg]) . "\n\n";
                        ob_flush();
                        flush();
                        unset($listening[array_search($i, $listening)]);
                    }
                }
                if (count($listening) == 0) {
                    echo "data: finished\n\n";
                    echo "event: close\n\n";
                    ob_flush();
                    flush();
                }

                $client = Redis::connection();
                try {
                    $client->subscribe($listening, function ($message, $raw_history_id) use ($client, $response) {
                        global $listening;
                        [$type, $msg] = explode(' ', $message, 2);
                        $history_id = substr($raw_history_id, strrpos($raw_history_id, '_') + 1);
                        if ($type == 'Ended') {
                            $key = array_search($history_id, $listening);
                            if ($key !== false) {
                                unset($listening[$key]);
                            }
                            if (count($listening) == 0) {
                                echo "data: finished\n\n";
                                echo "event: close\n\n";
                                $client->disconnect();
                            }
                        } elseif ($type == 'New') {
                            echo 'data: ' . json_encode(['history_id' => $history_id, 'msg' => json_decode($msg)->msg]) . "\n\n";
                        } elseif ($type == 'Heartbeat') {
                            echo ": heartbeat\n\n"; // Use comment line to keep the connection alive.
                        }
                        ob_flush();
                        flush();
                    });
                } catch (RedisException) {
                }
            } else {
                echo "data: finished\n\n";
                echo "event: close\n\n";
                ob_flush();
                flush();
            }
        });

        return $response;
    }

    public function compile_verilog(Request $request)
    {
        $verilogCode = $request->input('verilog_code');
        $result = true;
        try {
            $response = Http::post('http://127.0.0.1:13579/compile-verilog', [
                'verilog_code' => $verilogCode,
            ]);
        } catch (\Throwable) {
            $result = false;
        }
        if ($result && $response->successful()) {
            return $response;
        } else {
            return response()->json(['error' => $result ? 'Compile failed' : 'Backend compiler offline'], 200);
        }
    }
}
