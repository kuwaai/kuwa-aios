<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Jobs\RequestChat;
use App\Jobs\BatchChat;
use App\Models\Histories;
use App\Jobs\ImportChat;
use App\Models\ChatRoom;
use App\Models\Chats;
use GuzzleHttp\Client;
use App\Models\LLMs;
use App\Models\Bots;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Arr;
use DB;
use Session;
use Carbon\Carbon;

use function Laravel\Prompts\error;

class RoomController extends Controller
{
    public function share(Request $request)
    {
        $chat = ChatRoom::find($request->route('room_id'));
        if ($chat && $chat->user_id == Auth::user()->id) {
            return view('room.share');
        } else {
            return redirect()->route('room.home');
        }
    }

    public function export_to_doc(Request $request)
    {
        $chat = ChatRoom::find($request->route('room_id'));
        if ($chat && $chat->user_id == Auth::user()->id) {
            $html = view('room.export')->with('hide_header', true)->with('no_bot_img', true)->with('same_direction', true)->render();

            // Set headers for Word document
            return response($html)
                ->header('Content-Type', 'application/vnd.ms-word')
                ->header('Expires', '0')
                ->header('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                ->header('Content-Disposition', 'attachment;filename=export_' . trim(preg_replace('/_+/', '_', preg_replace('/[^a-zA-Z0-9-_]/', '_', ChatRoom::find(request()->route('room_id'))->name ?? 'room')), '_') . '.doc');
        } else {
            return redirect()->route('room.home');
        }
    }
    public function export_to_pdf(Request $request)
    {
        $chat = ChatRoom::find($request->route('room_id'));
        if ($chat && $chat->user_id == Auth::user()->id) {
            return view('room.share', ['print' => true]);
        } else {
            return redirect()->route('room.home');
        }
    }

    public function abort(Request $request)
    {
        $chatIDs = Chats::where('roomID', '=', $request->route('room_id'))->pluck('id')->toArray();
        $list = Histories::whereIn('id', \Illuminate\Support\Facades\Redis::lrange('usertask_' . Auth::user()->id, 0, -1))
            ->whereIn('chat_id', $chatIDs)
            ->pluck('id')
            ->toArray();
        $client = new Client(['timeout' => -1]);
        $kernel_location = \App\Models\SystemSetting::where('key', 'kernel_location')->first()->value;
        $response = $client->post($kernel_location . '/' . RequestChat::$kernel_api_version . '/chat/abort', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'form_params' => [
                'history_id' => json_encode($list),
                'user_id' => Auth::user()->id,
            ],
        ]);
        return response('Aborted', 200);
    }
    public function home(Request $request)
    {
        if ($request->session()->exists('llms')) {
            return view('room');
        } else {
            return view('room.home');
        }
    }
    public function chat_room(Request $request)
    {
        $room_id = $request->route('room_id');
        $chat = ChatRoom::find($room_id);
        if ($chat == null || $chat->user_id != Auth::user()->id) {
            return redirect()->route('room.home');
        } else {
            return view('room');
        }
    }

    public function import(Request $request)
    {
        $historys = $request->input('history');
        $bot_ids = $request->input('llm_ids');
        $chained = $request->input('chain','off') == "on";
        $room_id = $request->input('room_id');
        $filename = $request->input('import_file_name');
        if ($historys) {
            $result = Bots::pluck('id')->toarray();
            $historys = json_decode($historys);
            if (is_object($historys) || is_array($historys)) {
                //JSON format
                $historys = $historys->messages;
            } else {
                //TSV Format
                $rows = explode("\n", str_replace("\r\n", "\n", $request->input('history')));
                $historys = [];
                $headers = null;

                foreach ($rows as $index => $row) {
                    // Splitting each row into columns using tabs as delimiter
                    if ($index === 0) {
                        $headers = explode("\t", $row);
                        if (in_array('content', $headers)) {
                            continue;
                        } else {
                            $headers = ['content'];
                        }
                    }
                    if ($headers === null) {
                        break;
                    }
                    if (count($headers) === 1) {
                        $columns = [$row];
                    } else {
                        $columns = explode("\t", $row);
                    }

                    $record = [];
                    foreach ($headers as $columnIndex => $header) {
                        if (!isset($columns[$columnIndex]) || empty($columns[$columnIndex])) {
                            continue;
                        }
                        $value = $columns[$columnIndex];
                        if ($header === 'content') {
                            $value = trim(json_decode('"' . $value . '"'), '"');
                            if ($value === '') {
                                $value = str_replace("\\n", "\n", str_replace("\\t", "\t", $columns[$columnIndex]));
                            }
                        }
                        $record[$header] = $value;
                    }
                    $historys[] = (object) $record;
                }
            }
            if ($historys) {
                //Permission check
                if ($bot_ids == null){
                    $bot_ids = [];
                }
                foreach ($historys as $message) {
                    if (isset($message->role) && is_string($message->role)) {
                        $model = isset($message->model) && is_string($message->model) && str_starts_with($message->model, \App\Http\Controllers\ProfileController::BOT_PREFIX) ? $message->model : null;
                        if ($message->role === 'assistant') {
                            if (!is_null($model)) {
                                $bot = Bots::leftjoin('users', 'users.id', '=', 'bots.owner_id')->where('bots.name', '=', substr($model, strlen(\App\Http\Controllers\ProfileController::BOT_PREFIX)))
                                    ->where(function ($query) {
                                        $query->where(function ($q) {
                                            $q->where('visibility', 3)
                                            ->where('owner_id', Auth::user()->id);
                                        })->orWhere(function ($q) {
                                            $q->where('visibility', 2)
                                            ->where('users.group_id', Auth::user()->group_id);
                                        })->orWhere('visibility', 1)->orwhere('visibility', 0);
                                    })->select("bots.*");

                                if ($bot->exists()) {
                                    $bot = $bot->first();
                                    if (!in_array($model, $bot_ids)) {
                                        $bot_ids[$model] = $bot->id;
                                    }
                                }
                            }
                        }
                    }
                }
                if ( $bot_ids || $room_id) {
                
                    //Filtering
                    $chainValue = null;
                    $data = [];
                    $flag = false;
                    foreach ($historys as $message) {
                        $role = isset($message->role) && is_string($message->role) ? $message->role : null;
                        $hasContent = isset($message->content) && is_string($message->content) && trim($message->content) !== '';
                        if ($role === 'user' || ($role === null && $hasContent)) {
                            if ($flag) {
                                $newMessage = (object) [
                                    'role' => 'assistant',
                                    'model' => '',
                                    'chain' => $chainValue,
                                    'content' => '',
                                ];
                                if ($chainValue === true) {
                                    $newMessage->chain = true;
                                }
                                foreach ($bot_ids as $bot_name => $bot_id) {
                                    $newMessage->model = $bot_name;

                                    $data[] = clone $newMessage;
                                }
                            }
                            $chainValue = isset($message->chain) ? (bool) $message->chain : $chained;
                            if (!isset($message->role)) {
                                $message->role = 'user';
                            }
                            $data[] = $message;
                            $flag = true;
                        } elseif ($role === 'assistant') {
                            $model = isset($message->model) && is_string($message->model) ? $message->model : null;
                            $content = isset($message->content) && is_string($message->content) ? $message->content : '';
                            $message->content = $content;
                            $message->model = $model;
                            if ($chainValue === true) {
                                $message->chain = true;
                            }
                            if (is_null($model)) {
                                $flag = false;
                                
                                foreach ($bot_ids as $bot_name => $bot_id) {
                                    $newMessage = clone $message;
                                    $newMessage->model = $bot_name;

                                    if ($chainValue === true) {
                                        $newMessage->chain = true;
                                    }
                                    $data[] = $newMessage;
                                }
                            } elseif (in_array($model, array_keys($bot_ids))) {
                                $flag = false;
                                $data[] = $message;
                            }
                        }
                    }
                    if ($flag) {
                        $newMessage = (object) [
                            'role' => 'assistant',
                            'model' => '',
                            'chain' => $chainValue,
                            'content' => '',
                        ];
                        if ($chainValue === true) {
                            $newMessage->chain = true;
                        }
                        foreach ($bot_ids as $bot_name => $bot_id) {
                            $newMessage->model = $bot_name;

                            $data[] = clone $newMessage;
                        }
                    }
                    $historys = $data;
                    if (count($historys) > 0) {
                        $chatIds = [];
                        if ($room_id) {
                            $Room = ChatRoom::findorfail($room_id);
                            $chats = Chats::where('roomID', '=', $room_id);
                            foreach ($bot_ids as $bot_name => $bot_id) {
                                if (!$chats->pluck('bot_id')->contains($bot_id)) {
                                    $chat = new Chats();
                                    $chat->fill(['name' => 'Room Chat', 'bot_id' => $id, 'user_id' => Auth::user()->id, 'roomID' => $Room->id]);
                                    $chat->save();
                                }
                            }
                            $chats = Chats::where('roomID', '=', $room_id);
                            $chatIds = $chats->pluck('id')->toarray();
                        } else {
                            $Room = new ChatRoom();
                            $Room->fill(['name' => $filename ?? $historys[0]->content, 'user_id' => $request->user()->id]);
                            $Room->save();
                            foreach ($bot_ids as $bot_name => $bot_id) {
                                $chat = new Chats();
                                $chat->fill(['name' => 'Room Chat', 'bot_id' => $bot_id, 'user_id' => Auth::user()->id, 'roomID' => $Room->id]);
                                $chat->save();
                                $chatIds[$bot_name] = $chat->id;
                            }
                        }
                        $flag = true;
                        $user_msg = null;
                        $appended = [];
                        $ids = [];
                        $deltaTime = count($historys);
                        $lastCreateAt = Histories::whereIn('chat_id', array_values($chatIds))->latest('created_at')->value('created_at');

                        if ($lastCreateAt) {
                            $t = date('Y-m-d H:i:s', strtotime($lastCreateAt . ' +' . $deltaTime . ' second'));
                        } else {
                            $t = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' -' . $deltaTime . ' second'));
                        }
                        if (count($chatIds) > 0) {
                            foreach ($historys as $history) {
                                $history->isbot = $history->role == 'user' ? false : true;
                                if ($history->isbot) {
                                    if ($user_msg != null && !in_array($history->model, $appended)) {
                                        $record = new Histories();
                                        $record->fill(['msg' => $user_msg, 'chat_id' => $chatIds[$history->model], 'isbot' => false, 'chained' => $history->chain, 'created_at' => $t, 'updated_at' => $t]);
                                        $record->save();
                                    }
                                    $appended[] = $history->model;
                                    $t2 = date('Y-m-d H:i:s', strtotime($t . ' +' . array_count_values($appended)[$history->model] . ' second'));
                                    $record = new Histories();
                                    $record->fill(['msg' => $history->content == '' ? '* ...thinking... *' : $history->content, 'chat_id' => $chatIds[$history->model], 'chained' => $history->chain, 'isbot' => true, 'created_at' => $t2, 'updated_at' => $t2]);
                                    $record->save();
                                    if ($history->content == '') {
                                        $ids[] = $record->id;
                                        Redis::rpush('usertask_' . $request->user()->id, $record->id);
                                        //Redis::expire('usertask_' . $request->user()->id, 1200);
                                    }
                                } else {
                                    $user_msg = $history->content;
                                    $t = date('Y-m-d H:i:s', strtotime($t . ' +' . ($appended != [] ? max(array_count_values($appended)) : 1) + 1 . ' second'));
                                    $appended = [];
                                }
                            }
                            ImportChat::dispatch($ids, Auth::user()->id);
                            return Redirect::route('room.chat', $Room->id)->with('selLLMs', array_values($bot_ids));
                        }
                    }
                }
            }
        }
        return redirect()->route('room.home');
    }

    public function upload_file(Request $request)
    {
        if (!$request->file()) {
            return [
                'succeed' => false,
                'url' => null,
                'msg' => 'File not specified.',
            ];
        }
        $verify_uploaded_file = !request()->user()->hasPerm('Room_update_ignore_upload_constraint');
        if (!$verify_uploaded_file) {
            $max_file_size_mb = PHP_INT_MAX;
            $allowed_file_exts = '*';
            $upload_max_file_count = -1;
        } else {
            $max_file_size_mb = \App\Models\SystemSetting::where('key', 'upload_max_size_mb')->first()->value;
            $allowed_file_exts = \App\Models\SystemSetting::where('key', 'upload_allowed_extensions')->first()->value;
            $upload_max_file_count = \App\Models\SystemSetting::where('key', 'upload_max_file_count')->first()->value;
        }
        $max_file_size_kb = strval(intval($max_file_size_mb ?: 20) * 1024);
        $allowed_file_exts = $allowed_file_exts ?: 'pdf,doc,docx,odt,ppt,pptx,odp,xlsx,xls,ods,eml,txt,md,csv,json,jpg,bmp,png,zip,mp3,wav,flac,wma,m4a,aac';
        $upload_max_file_count = intval($upload_max_file_count ?: -1);

        if ($upload_max_file_count == 0) {
            return [
                'succeed' => false,
                'url' => null,
                'msg' => __('chat.placeholder.upload_disabled_by_admin'),
            ];
        }

        Log::channel('analyze')->Debug('max_file_size_kb:' . $max_file_size_kb);
        Log::channel('analyze')->Debug('allowed_file_exts:' . $allowed_file_exts);

        // Check if a file is uploaded
        if ($request->hasFile('file')) {
            // Get the MIME type of the uploaded file
            $mimeType = $request->file('file')->getMimeType();
            Log::channel('analyze')->Debug('uploaded_file_mime_type:' . $mimeType);
        }

        $file_validation_rule = ['file', 'max:' . $max_file_size_kb];
        if ($allowed_file_exts !== '*') {
            array_push($file_validation_rule, 'mimes:' . $allowed_file_exts);
        }

        $validator = Validator::make($request->all(), [
            'file' => $file_validation_rule,
        ]);

        if ($validator->fails()) {
            $errorString = implode(',', $validator->messages()->all());
            Log::channel('analyze')->Debug("validation failed:\n" . $errorString);
            return [
                'succeed' => false,
                'url' => null,
                'msg' => $errorString,
            ];
        }

        $directory = 'root/homes/' . $request->user()->id; // Directory relative to 'public/storage/'
        $storagePath = public_path('storage/' . $directory); // Adjusted path
        $filePathParts = pathinfo($request->file->getClientOriginalName());
        $fileName = sprintf('%s%s', $filePathParts['filename'], isset($filePathParts['extension']) ? '.' . $filePathParts['extension'] : '');
        $filePath = $request->file('file')->storeAs($directory, $fileName, 'public'); // Use 'public' disk

        $files = File::files($storagePath);

        // Auto delete files
        if ($upload_max_file_count >= 0 && count($files) > $upload_max_file_count) {
            usort($files, function ($a, $b) {
                return filectime($a) - filectime($b);
            });

            while (count($files) > $upload_max_file_count) {
                $oldestFile = array_shift($files);
                File::delete($storagePath . '/' . $oldestFile->getFilename());
            }
        }

        $url = url('storage/' . $directory . '/' . rawurlencode($fileName));
        return [
            'succeed' => true,
            'url' => $url,
            'msg' => 'Succeed.',
        ];
    }
    /**
 * @OA\Post(
 *     path="/api/user/create/room",
 *     summary="Create a room with bots",
 *     tags={"Rooms"},
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/CreateRoomRequest")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Room created"
 *     )
 * )
 */
    public function api_create_room(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();
        if ($result) {
            $user = $result;
            if (User::find($user->id)->hasPerm('Room_update_new_chat')) {
                Auth::setUser(User::find($user->id));
                $room_id = $this->create_room($request);
                return response()->json(['status' => 'success', 'result' => $room_id], 200, [], JSON_UNESCAPED_UNICODE);
            } else {
                $errorResponse = [
                    'status' => 'error',
                    'message' => 'You have no permission to use this Kuwa API',
                ];

                return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $errorResponse = [
                'status' => 'error',
                'message' => 'Authentication failed',
            ];

            return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
        }
    }
    /**
 * @OA\Get(
 *     path="/api/user/read/rooms",
 *     summary="List rooms",
 *     tags={"Rooms"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(
 *         response=200,
 *         description="List of rooms"
 *     )
 * )
 */
    public function api_read_rooms(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();
        if ($result) {
            $user = $result;
            if (User::find($user->id)->hasPerm('tab_Room')) {
                return response()->json(['status' => 'success', 'result' => Chatroom::getRawChatRoomData($user->id)], 200, [], JSON_UNESCAPED_UNICODE);
            } else {
                $errorResponse = [
                    'status' => 'error',
                    'message' => 'You have no permission to use this Kuwa API',
                ];

                return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $errorResponse = [
                'status' => 'error',
                'message' => 'Authentication failed',
            ];

            return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
        }
    }
    /**
 * @OA\Delete(
 *     path="/api/user/delete/room/message",
 *     summary="Delete a message",
 *     tags={"Messages"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(
 *         name="id",
 *         in="query",
 *         required=true,
 *         description="Message ID to delete",
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Message deleted"
 *     )
 * )
 */
    public function api_delete_message(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();
        if ($result) {
            $user = $result;
            if (User::find($user->id)->hasPerm('Room_delete_room_message')) {
                Auth::setUser(User::find($user->id));
                $history = Histories::withTrashed()->find($request->input('id'));
                if ($history) {
                    $room_id = Chats::withTrashed()->find($history->chat_id)->room_id;
                    $history->forceDelete();
                    return response()->json(['status' => 'success', 'result' => $room_id], 200, [], JSON_UNESCAPED_UNICODE);
                } else {
                    return response()->json(['status' => 'failed'], 200, [], JSON_UNESCAPED_UNICODE);
                }
            } else {
                $errorResponse = [
                    'status' => 'error',
                    'message' => 'You have no permission to use this Kuwa API',
                ];

                return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $errorResponse = [
                'status' => 'error',
                'message' => 'Authentication failed',
            ];

            return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
        }
    }

    function getWebPageTitle($url)
    {
        // Try to fetch the HTML content of the URL
        $html = @file_get_contents($url);

        // If the URL is not accessible, return an empty string
        if ($html === false) {
            return '';
        }

        // Use regular expressions to extract the title from the HTML
        if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
            return $matches[1];
        } else {
            // If no title is found, return an empty string
            return '';
        }
    }
    function getFilenameFromURL($url)
    {
        $path_parts = pathinfo($url);

        if (isset($path_parts['filename'])) {
            return $path_parts['filename'];
        } else {
            return '';
        }
    }

    public function create(Request $request): RedirectResponse
    {
        $llms = $request->input('llm');
        $selectedLLMs = $request->input('chatsTo');
        $chained = $request->input('chain') == "on";
        if (count($selectedLLMs) > 0 && count($llms) > 0) {
            $result = Bots::wherein(
                'model_id',
                DB::table('group_permissions')
                    ->join('permissions', 'group_permissions.perm_id', '=', 'permissions.id')
                    ->select(DB::raw('substring(permissions.name, 7) as model_id'), 'perm_id')
                    ->where('group_permissions.group_id', Auth::user()->group_id)
                    ->where('permissions.name', 'like', 'model_%')
                    ->get()
                    ->pluck('model_id'),
            )
                ->pluck('bots.id')
                ->toarray();

            foreach ($llms as $i) {
                if (!in_array($i, $result)) {
                    return Redirect::route('room.home');
                }
            }
            # model permission auth done
            foreach ($selectedLLMs as $id) {
                if (!in_array($id, $llms)) {
                    return Redirect::route('room.home');
                }
            }
            $input = $request->input('input');
            if ($request->file()) {
                $upload_result = $this->upload_file($request);
                if ($upload_result['succeed']) {
                    $input = $upload_result['url'] . "\n" . $input;
                } else {
                    return redirect()->route('room.home')->with('errorString', $upload_result['msg']);
                }
            }
            $chatname = $input;
            $first_url = preg_match('/\bhttps?:\/\/\S+/i', $input, $matches);
            $firstUrl = isset($matches[0]) ? $matches[0] : null;
            if ($firstUrl) {
                $raw_chat_title = $this->getWebPageTitle($firstUrl) ?: $this->getFilenameFromURL($firstUrl);
                $chatname = rawurldecode($raw_chat_title);
                $chatname = mb_convert_encoding($chatname, 'UTF-8', 'UTF-8');
            }

            $Room = ChatRoom::find($this->create_room($request));
            $Room->fill(['name' => $chatname, 'user_id' => Auth::user()->id]);
            $Room->save();
            $ct = date('Y-m-d H:i:s');
            $dct = date('Y-m-d H:i:s', strtotime($ct . ' +1 second'));
            $chats = Chats::where('roomID', $Room->id)->get();
            foreach ($chats as $chat) {
                if (in_array($chat->bot_id, $selectedLLMs)) {
                    $bot = Bots::findOrFail($chat->bot_id);
                    $result = $this->processBotConfig($chained, $chat->bot_id, 'auto', $Room->id, str_replace("\r\n", '\n', $input) . "\n");
                    if ($result == null) {
                        $history = new Histories();
                        $history->fill(['msg' => $input, 'chat_id' => $chat->id, 'isbot' => false, 'created_at' => $ct, 'updated_at' => $ct]);
                        $history->save();
                        $access_code = LLMs::findOrFail($bot->model_id)->access_code;
                        if ($chained) {
                            $tmp = Histories::where('chat_id', '=', $chat->id)->select('msg', 'isbot')->orderby('created_at')->orderby('id', 'desc')->get()->toJson();
                        } else {
                            $tmp = json_encode([['msg' => $input, 'isbot' => false]]);
                        }
                        $history = new Histories();
                        $history->fill(['msg' => '* ...thinking... *', 'chained' => $chained, 'chat_id' => $chat->id, 'isbot' => true, 'created_at' => $dct, 'updated_at' => $dct]);
                        $history->save();
                        RequestChat::dispatch($tmp, $access_code, Auth::user()->id, $history->id, App::getLocale(), null, json_decode($bot->config ?? '')->modelfile ?? null);
                        Redis::rpush('usertask_' . Auth::user()->id, $history->id);
                        //Redis::expire('usertask_' . Auth::user()->id, 1200); 
                    }
                }
            }
        }
        return redirect()
            ->route('room.chat', $Room->id)
            ->with('selLLMs', $selectedLLMs)
            ->with('mode_track', request()->input('mode_track'));
    }

    public function create_room(Request $request)
    {
        $llms = $request->input('llm');
        $Room = new ChatRoom();
        $Room->fill(['name' => __('room.header.new_room'), 'user_id' => Auth::user()->id]);
        $Room->save();
        foreach ($llms as $llm) {
            $chat = new Chats();
            $chat->fill(['name' => 'Room Chat', 'bot_id' => $llm, 'user_id' => Auth::user()->id, 'roomID' => $Room->id]);
            $chat->save();
        }
        return $Room->id;
    }

    function processBotConfig($chain, $i, $promptType = 'auto', $roomId = null, $prependMessage = '')
    {
        $startPrompt = $promptType . '-prompts';

        $config = json_decode(Bots::find($i)->config, true)['modelfile'] ?? [];
        $exec_name = array_values(array_filter($config, fn($v) => $v['name'] === $startPrompt))[0]['args'] ?? '';
        $modelfile = array_values(array_filter($config, fn($v) => $v['name'] === 'prompts'));

        if ($exec_name) {
            $args = str_starts_with($exec_name, '@') ? ($filtered = array_values(array_filter($modelfile, fn($v) => str_starts_with($v['name'] . ' @' . $v['args'], 'prompts ' . $exec_name . ' '))))[0]['args'] ?? null : '@ ' . $exec_name;

            if ($args) {
                $prompts = implode(' ', array_slice(explode(' ', $args), 1));
                $prompts = str_starts_with($prompts, '"""') && str_ends_with($prompts, '"""') ? substr($prompts, 3, -3) : $prompts;

                if ($prompts) {
                    $prompts = explode("\n", $prependMessage . $prompts);
                    foreach ($prompts as &$prompt) {
                        $prompt = str_replace('\n', "\n", $prompt);
                    }
                    if ($roomId ?? null) {
                        $Room = ChatRoom::findorfail($roomId);
                        $chat = Chats::where('roomID', '=', $roomId)->where('bot_id', $i)->first();
                    } else {
                        $Room = new ChatRoom();
                        $Room->fill(['name' => $prompts[0], 'user_id' => Auth::user()->id]);
                        $Room->save();
                        $chat = new Chats();
                        $chat->fill(['name' => 'Room Chat', 'bot_id' => $i, 'user_id' => Auth::user()->id, 'roomID' => $Room->id]);
                        $chat->save();
                    }
                    $chatId = $chat->id;

                    $start = date('Y-m-d H:i:s');
                    $deltaStart = date('Y-m-d H:i:s', strtotime($start . ' +1 second'));

                    $record = new Histories();
                    $record->fill(['msg' => $prompts[0], 'chat_id' => $chatId, 'isbot' => false, 'chained' => $chain ?? true, 'created_at' => $start, 'updated_at' => $start]);
                    $record->save();

                    $record = new Histories();
                    $record->fill(['msg' => '* ...thinking... *', 'chat_id' => $chatId, 'chained' => $chain ?? true, 'isbot' => true, 'created_at' => $deltaStart, 'updated_at' => $deltaStart]);
                    $record->save();
                    BatchChat::dispatch($prompts, $record->id);
                    Redis::rpush('usertask_' . Auth::user()->id, $record->id);
                    //Redis::expire('usertask_' . Auth::user()->id, 1200);
                    return Redirect::route('room.chat', $Room->id)->with('selLLMs', [$i]);
                }
            }
        }

        return null;
    }

    public function new(Request $request)
    {
        $llms = $request->input('llm');
        $chained = $request->input('chain') == "on";
        if (!request()->user()->hasPerm('Room_update_new_chat') || count($llms) == 0) {
            return redirect()->route('room.home');
        }
        $result = Bots::wherein(
            'model_id',
            DB::table('group_permissions')
                ->join('permissions', 'group_permissions.perm_id', '=', 'permissions.id')
                ->select(DB::raw('substring(permissions.name, 7) as model_id'), 'perm_id')
                ->where('group_permissions.group_id', Auth::user()->group_id)
                ->where('permissions.name', 'like', 'model_%')
                ->get()
                ->pluck('model_id'),
        )
            ->pluck('bots.id')
            ->toarray();

        foreach ($llms as $i) {
            if (!in_array($i, $result)) {
                return Redirect::route('room.home');
            }
        }

        foreach ($llms as $i) {
            $result = $this->processBotConfig($chained, $i, 'start');
            if ($result != null) {
                return $result;
            }
        }

        return redirect()->route('room.home')->with('llms', $llms);
    }/**
 * @OA\Delete(
 *     path="/api/user/delete/room",
 *     summary="Delete a room",
 *     tags={"Rooms"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(
 *         name="id",
 *         in="query",
 *         required=true,
 *         description="Room ID to delete",
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Room deleted"
 *     )
 * )
 */
    public function api_delete_room(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();

        if ($result) {
            $user = $result;
            if (User::find($user->id)->hasPerm('Room_delete_chatroom')) {
                Auth::setUser(User::find($user->id));
                $ids = $this->delete($request);

                return response()->json(
                    [
                        'status' => session('success') ? 'success' : 'failed',
                        'llms' => $ids,
                    ],
                    200,
                    [],
                    JSON_UNESCAPED_UNICODE,
                );
            } else {
                return response()->json(
                    [
                        'status' => 'error',
                        'message' => 'You have no permission to use this Kuwa API',
                    ],
                    401,
                    [],
                    JSON_UNESCAPED_UNICODE,
                );
            }
        } else {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Authentication failed',
                ],
                401,
                [],
                JSON_UNESCAPED_UNICODE,
            );
        }
    }

    public function delete(Request $request)
    {
        $ids = [];
        $chats = ChatRoom::find($request->input('id'));
        if ($chats) {
            if ($chats->user_id == Auth::user()->id) {
                foreach (Chats::where('roomID', '=', $chats->id)->get() as $chat) {
                    $ids[] = $chat->bot_id;
                    Histories::where('chat_id', '=', $chat->id)->delete();
                }
                Chats::where('roomID', '=', $chats->id)->delete();
                $chats->delete();
                return redirect()->route('room.home')->with('llms', $ids)->with('success', true);
            }
        }
        return redirect()->route('room.home')->with('success', false);
    }

    public function edit(Request $request): RedirectResponse
    {
        try {
            $chat = ChatRoom::findOrFail($request->input('id'));
            $chat->fill(['name' => $request->input('new_name')]);
            $chat->save();
        } catch (ModelNotFoundException $e) {
            Log::error('Chat not found: ' . $request->input('id'));
        }
        return redirect()->route('room.chat', $request->input('id'));
    }

    public function request(Request $request): RedirectResponse
    {
        $roomId = $request->input('room_id');
        $selectedLLMs = $request->input('chatsTo');
        $input = $request->input('input');
        if ($request->file()) {
            $upload_result = $this->upload_file($request);
            if ($upload_result['succeed']) {
                $input = $upload_result['url'] . "\n" . $input;
            } else {
                return redirect()->route('room.chat', $roomId)->with('errorString', $upload_result['msg'])->withInput();
            }
        }

        $chained = $request->input('chain') == "on";
        if (count($selectedLLMs) > 0 && $roomId && $input) {
            $chats = Chats::where('roomID', $roomId)->get();
            $result = Bots::pluck('id')->toarray();

            foreach ($chats->pluck('bot_id')->toarray() as $i) {
                if (!in_array($i, $result)) {
                    return Redirect::route('room.home');
                }
            }
            foreach ($selectedLLMs as $id) {
                if (!in_array($id, $chats->pluck('bot_id')->toarray())) {
                    return Redirect::route('room.home');
                }
            }
            ChatRoom::find($roomId)->update(['updated_at' => Carbon::now()]);
            #Model permission checked
            $start = date('Y-m-d H:i:s');
            $deltaStart = date('Y-m-d H:i:s', strtotime($start . ' +1 second'));
            foreach ($chats as $chat) {
                if (in_array($chat->bot_id, $selectedLLMs)) {
                    $bot = Bots::findOrFail($chat->bot_id);
                    $result = $this->processBotConfig($chained, $chat->bot_id, 'auto', $roomId, str_replace("\r\n", '\n', $input) . "\n");
                    if ($result == null) {
                        $history = new Histories();
                        $history->fill(['msg' => $input, 'chat_id' => $chat->id, 'isbot' => false, 'created_at' => $start, 'updated_at' => $start]);
                        $history->save();
                        $access_code = LLMs::findOrFail($bot->model_id)->access_code;
                        if ($chained) {
                            $tmp = Histories::where('chat_id', '=', $chat->id)->select('msg', 'isbot')->orderby('created_at')->orderby('id', 'desc')->get()->toJson();
                        } else {
                            $tmp = json_encode([['msg' => $input, 'isbot' => false]]);
                        }
                        $history = new Histories();
                        $history->fill(['msg' => '* ...thinking... *', 'chained' => $chained, 'chat_id' => $chat->id, 'isbot' => true, 'created_at' => $deltaStart, 'updated_at' => $deltaStart]);
                        $history->save();
                        RequestChat::dispatch($tmp, $access_code, Auth::user()->id, $history->id, App::getLocale(), null, json_decode($bot->config ?? '')->modelfile ?? null);
                        Redis::rpush('usertask_' . Auth::user()->id, $history->id);
                        //Redis::expire('usertask_' . Auth::user()->id, 1200);
                    }
                }
            }
        }
        return redirect()
            ->route('room.chat', $roomId)
            ->with('selLLMs', $selectedLLMs)
            ->with('mode_track', request()->input('mode_track'));
    }
}
