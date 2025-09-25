<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\APIHistories;
use App\Models\Histories;
use Illuminate\View\View;
use App\Jobs\RequestChat;
use GuzzleHttp\Client;
use App\Models\LLMs;
use App\Models\User;
use App\Models\Groups;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BotController;
use App\Models\Bots;
use DB;
use Crypt;
use Net_IPv4;
use Net_IPv6;

class ProfileController extends Controller
{

    public const CHAT_COMPLETION_PREFIX = 'chatcmpl-';
    public const BOT_PREFIX = ".bot/";

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        if ($request->user()->tokens()->where('name', 'API_Token')->count() != 1) {
            $request->user()->tokens()->where('name', 'API_Token')->delete();
            $request->user()->createToken('API_Token', ['access_api']);
        }

        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }
    /**
 * @OA\Post(
 *     path="/api/user/upload/file",
 *     summary="Upload a file",
 *     tags={"Cloud"},
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 type="object",
 *                 required={"file"},
 *                 @OA\Property(
 *                     property="file",
 *                     type="string",
 *                     format="binary"
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="File uploaded"
 *     )
 * )
 */
    public function api_upload_file(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();
        if ($result) {
            $user = $result;
            Auth::setUser(User::find($user->id));
            if (User::find($user->id)->hasPerm('Cloud_update_upload_files')) {
                $controller = new RoomController();
                $upload_result = $controller->upload_file($request);
                if ($upload_result['succeed']){
                    return response()->json(['status' => 'success', 'result' => $upload_result['url']], 200, [], JSON_UNESCAPED_UNICODE);
                }else{
                    return response()->json(['status' => 'failed', 'result' => $upload_result['msg']], 422, [], JSON_UNESCAPED_UNICODE);
                }
            } else {
                $errorResponse = [
                    'status' => 'error',
                    'message' => 'You have no permission to use Chat API',
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
    public static function isIPInCIDRList($ipAddress, $cidrList)
    {
        $ipVersion = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : null);

        // Filter CIDR list based on IP version
        $filteredCIDRList = array_filter($cidrList, function ($cidr) use ($ipVersion) {
            return strpos($cidr, '/') !== false && filter_var(explode('/', $cidr)[0], FILTER_VALIDATE_IP, $ipVersion === 4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) !== false;
        });

        foreach ($filteredCIDRList as $cidr) {
            [$subnet, $mask] = explode('/', $cidr);
            $mask = (int) $mask;

            if ($ipVersion === 4) {
                $subnetLong = ip2long($subnet);
                $ipLong = ip2long($ipAddress);
                $network = $subnetLong & (-1 << 32 - $mask);
                $ipNetwork = $ipLong & (-1 << 32 - $mask);

                if ($network === $ipNetwork) {
                    return true;
                }
            } elseif ($ipVersion === 6) {
                $subnetBinary = inet_pton($subnet);
                $ipBinary = inet_pton($ipAddress);
                $subnetChunks = unpack('n*', $subnetBinary);
                $ipChunks = unpack('n*', $ipBinary);

                for ($i = 1; $i <= $mask / 16; $i++) {
                    $networkChunk = $subnetChunks[$i] & (-1 << 16 - min($mask - ($i - 1) * 16, 16));
                    $ipNetworkChunk = $ipChunks[$i] & (-1 << 16 - min($mask - ($i - 1) * 16, 16));

                    if ($networkChunk !== $ipNetworkChunk) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    public function api_register(Request $request)
    {
        $lockoutCount = RateLimiter::attempts($request->ip());
        $seconds = $this->ensureIsNotRateLimited($request->ip());
        if ($seconds) {
            return response()->json(['error' => 'Too many attempts, Action freezed until ' . floor($seconds / 60) . ':' . $seconds % 60], 400, [], JSON_UNESCAPED_UNICODE);
        }
        $encryptedData = $request->input('data');
        $path = 'keys/' . $encryptedData[0];
        if ($encryptedData && $encryptedData[0] && Storage::exists($path)) {
            $allowlist = $path . '/allowlist.txt';
            $allowlist = Storage::exists($allowlist) ? explode("\n", Storage::get($allowlist)) : null;

            if ($allowlist ? $this->isIPInCIDRList($request->ip(), $allowlist) : true) {
                if (isset($encryptedData[3])) {
                    $privKey = Storage::get($path . '/priv.pem');
                    $pubKey = Storage::get($path . '/pub.pem');
                    $invite_code = Storage::get($path . '/invite_code.txt');
                    if ($privKey && $pubKey) {
                        function decrypt($data, $privKey, $pubKey)
                        {
                            $result = openssl_private_decrypt(base64_decode($data), $decryptedData, $privKey);
                            if ($result) {
                                // Attempt verification using the corresponding public key
                                $result = openssl_public_decrypt($decryptedData, $data, $pubKey);
                                if ($result) {
                                    return $data;
                                }
                            }
                            throw new \Illuminate\Contracts\Encryption\DecryptException();
                        }
                        try {
                            // Attempt decryption using the current private key
                            $accData = (object) ['name' => decrypt($encryptedData[1], $privKey, $pubKey), 'email' => decrypt($encryptedData[2], $privKey, $pubKey), 'password' => decrypt($encryptedData[3], $privKey, $pubKey)];
                            RateLimiter::clear(Str::transliterate($request->ip()));
                            $validator = Validator::make((array) $accData, [
                                'email' => 'bail|required|string|email|max:240|unique:users',
                                'name' => 'required|string|max:240',
                                'password' => [
                                    'required',
                                    'string',
                                    function ($attribute, $value, $fail) {
                                        if (!preg_match('/^\$2a\$10\$/', $value) && !preg_match('/^\$2y\$10\$/', $value)) {
                                            $fail('The password must be hashed using bcrypt.');
                                        }
                                    },
                                ],
                            ]);
                            if ($validator->fails()) {
                                return response()->json(['errors' => $validator->errors()], 422, [], JSON_UNESCAPED_UNICODE);
                            }

                            if ($invite_code) {
                                $group_id = Groups::where('invite_token', '=', trim($invite_code))->first()->id ?? null;
                            } else {
                                $group_id = null;
                            }

                            $user = new User();
                            $user->name = $accData->name;
                            $user->email = $accData->email;
                            $user->password = $accData->password;
                            if ($group_id) {
                                $user->group_id = $group_id;
                            }
                            $user->detail = json_encode(['Origin' => basename($encryptedData[0])]);

                            $user->save();
                            $user->markEmailAsVerified();

                            return response()->json(['message' => __('auth.placeholder.user_created_success')], 201, [], JSON_UNESCAPED_UNICODE);
                        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                        }
                    }
                }
            }
        }
        RateLimiter::hit(Str::transliterate($request->ip()), 60 * 60);
        return response()->json(['error' => 'No permission to access this route.'], 400, [], JSON_UNESCAPED_UNICODE);
    }

    public function ensureIsNotRateLimited($ip)
    {
        $ip = Str::transliterate($ip);
        if (!RateLimiter::tooManyAttempts($ip, 5)) {
            return false;
        }

        $seconds = RateLimiter::availableIn($ip);

        return $seconds;
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $status = [];
        $validatedData = $request->validated();

        if (!empty($validatedData)) {
            $user = $request->user();

            if (isset($validatedData['name']) && $user->hasPerm('Profile_update_name')) {
                $user->name = $validatedData['name'];
            }

            if (isset($validatedData['email']) && $user->hasPerm('Profile_update_email')) {
                $user->email = $validatedData['email'];
            }

            if ($user->isDirty('email') || $user->isDirty('name')) {
                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }
                $user->save();
                return Redirect::route('profile.edit')->with('status', 'profile-updated');
            }
        }

        return Redirect::route('profile.edit');
    }

    public function openai_update(Request $request)
    {
        $request
            ->user()
            ->fill(['openai_token' => $request->input('openai_token')])
            ->save();
        return Redirect::route('profile.edit')->with('status', 'chatgpt-token-updated');
    }

    public function google_update(Request $request)
    {
        $request
            ->user()
            ->fill(['google_token' => $request->input('google_token')])
            ->save();
        return Redirect::route('profile.edit')->with('status', 'google-token-updated');
    }
    
    public function nim_update(Request $request)
    {
        $request
            ->user()
            ->fill(['nim_token' => $request->input('nim_token')])
            ->save();
        return Redirect::route('profile.edit')->with('status', 'nim-token-updated');
    }
    
    public function third_party_update(Request $request)
    {
        $request
            ->user()
            ->fill(['third_party_token' => $request->input('third_party_token')])
            ->save();
        return Redirect::route('profile.edit')->with('status', 'third-party-token-updated');
    }


    /**
     * Renew the user's API Token.
     */
    public function renew(Request $request): RedirectResponse
    {
        $request->user()->tokens()->where('name', 'API_Token')->delete();
        $request->user()->createToken('API_Token', ['access_api']);

        return Redirect::route('profile.edit')->with('status', 'apiToken-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current-password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->tokens()->delete();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
/**
 * @OA\Post(
 *     path="/v1.0/chat/completions",
 *     summary="Complete a chat (streaming or non-streaming)",
 *     tags={"Chat"},
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"model", "messages"},
 *             @OA\Property(property="model", type="string", example="gpt-4"),
 *             @OA\Property(
 *                 property="messages",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     required={"role", "content"},
 *                     @OA\Property(property="role", type="string", example="user"),
 *                     @OA\Property(property="content", type="string", example="Hello")
 *                 )
 *             ),
 *             @OA\Property(property="stream", type="boolean", example=false, description="Enable streaming mode"),
 *             @OA\Property(
 *                 property="options",
 *                 type="object",
 *                 description="Additional options for the completion",
 *                 additionalProperties={}
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Chat completion response (streamed or full)",
 *         @OA\JsonContent(
 *             @OA\Property(
 *                 property="choices",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     oneOf={
 *                         @OA\Schema(
 *                             @OA\Property(
 *                                 property="delta",
 *                                 type="object",
 *                                 @OA\Property(property="content", type="string", example="Hello, how can I assist?")
 *                             )
 *                         ),
 *                         @OA\Schema(
 *                             @OA\Property(
 *                                 property="message",
 *                                 type="object",
 *                                 @OA\Property(property="role", type="string", example="assistant"),
 *                                 @OA\Property(property="content", type="string", example="Hello, how can I assist you?")
 *                             )
 *                         )
 *                     }
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request"
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Unauthorized"
 *     )
 * )
 */

    public function api_auth(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $jsonData = $request->json()->all();
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name', 'openai_token')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')));
        if (!$result->exists()) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'Authentication failed',
            ];
            return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
        }

        $user = $result->first();
        if (
            !User::find($user->id)->hasPerm('Room_read_access_to_api') &&
            (config('app.API_Key') == null || config('app.API_Key') != $request->input('key'))
        ) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'Use of the chat completion API is not authorized.',
            ];
            return response()->json($errorResponse, 403, [], JSON_UNESCAPED_UNICODE);
        }

        if (!isset($jsonData['messages']) || !isset($jsonData['model'])) {
            // Handle the case where 'messages' and 'model' are not present in $jsonData
            $errorResponse = [
                'status' => 'error',
                'message' => 'The request is missing the "message" or "model" field.',
            ];
            return response()->json($errorResponse, 400, [], JSON_UNESCAPED_UNICODE);
        }

        $is_calling_bot = str_starts_with($jsonData['model'], self::BOT_PREFIX);
        $bot_name = substr($jsonData['model'], strlen(self::BOT_PREFIX));
        
        $llm = LLMs::where('access_code', '=', $jsonData['model']);
        $bot = Bots::where('bots.name', '=', $is_calling_bot ? $bot_name : '');
        
        // Default bot
        if($is_calling_bot && ($bot_name == ".def" || $bot_name == ".default")){
            $is_calling_bot = false;
            $llm = LLMs::select('*')->orderBy('order');
        }
        if (!((!$is_calling_bot && $llm->exists()) || ($is_calling_bot && $bot->exists()))) {
            // Handle the case where the specified model doesn't exist
            $errorResponse = [
                'status' => 'error',
                'message' => 'The specified model does not exist.',
            ];
            return response()->json($errorResponse, 404, [], JSON_UNESCAPED_UNICODE);
        }

        $llm = $llm->exists() ? $llm->first() : LLMs::query()
        ->join('bots', 'llms.id', '=', 'bots.model_id')
        ->where('bots.name', '=', $is_calling_bot ? $bot_name : '')
        ->select('llms.*')
        ->first();
        $botFile = $bot->exists() ? (json_decode($bot->first()->config ?? '')->modelfile ?? null) : null;
        if(isset($jsonData['botfile'])){
            $botController = new BotController();
            $botFile = $botController->modelfile_parse($jsonData['botfile']);
            $botFile = array_map(function($item) {
                return (object) $item;
            }, $botFile);
        }

        $jsonData['messages'] = array_map(function($x){
            return [
                'isbot' => $x['role'] === 'user' ? false : true,
                'msg' => $x['content']
            ];
        },$jsonData['messages']);
        $messages_json = json_encode($jsonData['messages']);
        $lang = $jsonData['lang'] ?? key(config('app.LANGUAGES'));

        if ($messages_json === false && json_last_error() !== JSON_ERROR_NONE) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'The "message" field is not formatted correctly.',
            ];
            return response()->json($errorResponse, 400, [], JSON_UNESCAPED_UNICODE);
        }

        // Input is a valid JSON string
        $history = new APIHistories();
        $history->fill(['input' => $messages_json, 'output' => '* ...thinking... *', 'user_id' => $user->id]);
        $history->save();

        Redis::rpush('api_' . $user->tokenable_id, $history->id);
        //Redis::expire('api_' . $user->tokenable_id, 1200);
        RequestChat::dispatch($messages_json, $llm->access_code, $user->id, $history->id, $lang, 'api_' . $history->id, $botFile);

        if (isset($jsonData['stream']) ? boolval($jsonData['stream']) : false){
            return $this->streaming_response($user, $history, $llm);
        } else {
            return $this->nonstreaming_response($user, $history, $llm);
        }
    }
    
    private function nonstreaming_response(&$user, &$history, &$llm)
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $bot_output = "";
        $executor_exit_code = null;
        $backend_callback = function ($event, $chunk) use (&$history, &$llm, &$bot_output, &$executor_exit_code){
            
            if ($event == 'Error') {
                throw new \Exception($chunk);
            }
            if ($event == 'New') {
                $bot_output .= $chunk->msg;
                if(isset($chunk->exit_code)){
                    $executor_exit_code = $chunk->exit_code;
                }
            }
            $history->fill(['output' => $bot_output]);
            $history->save();
        };
        $this->read_backend_stream(
            $history->id,
            $user->tokenable_id,
            $backend_callback
        );

        $resp = [
            "choices" => [
                [
                    "index" => 0,
                    "message" => [
                        "role" => "assistant",
                        "content" => $bot_output,
                    ],
                    "logprobs" => null,
                    "finish_reason" => "stop",
                    "exit_code" => $executor_exit_code,
                ]
            ],
            'created' => time(),
            'id' => self::CHAT_COMPLETION_PREFIX . $history->id,
            'model' => $llm->access_code,
            'object' => 'chat.completion',
            'usage' => (object)[],
        ];

        return response()->json($resp);
    }

    private function streaming_response(&$user, &$history, &$llm)
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('charset', 'utf-8');
        $response->headers->set('Connection', 'close');
        $response->headers->set('X-Request-ID', self::CHAT_COMPLETION_PREFIX . $history->id);
        
        $response->setCallback(function() use (&$user, &$history, &$llm) {
            $bot_output = "";
            $executor_exit_code = null;
            $backend_callback = function ($event, $chunk) use (&$history, &$llm, &$bot_output, &$executor_exit_code){
                if ($event == 'Error') {
                    throw new \Exception($chunk);
                }

                $resp = [
                    'choices' => [
                        [
                            'delta' => [
                                'content' => '',
                                'role' => "assistant",
                            ],
                            'logprobs' => null,
                            'finish_reason' => null,
                            'exit_code' => null,
                        ],
                    ],
                    'created' => time(),
                    'id' => self::CHAT_COMPLETION_PREFIX . $history->id,
                    'model' => $llm->access_code,
                    'object' => 'chat.completion.chunk',
                ];
                
                $sendNewChunk = false;
                if ($event == 'Ended') {
                    $resp['choices'][0]['delta'] = (object) null;
                    $resp['choices'][0]['finish_reason'] = 'stop';
                    $resp['choices'][0]['exit_code'] = $executor_exit_code;
                    $sendNewChunk = true;
                } elseif ($event == 'New') {
                    if (!empty($chunk->msg)){
                        $resp['choices'][0]['delta']['content'] = $chunk->msg;
                        $bot_output .= $chunk->msg;
                        $sendNewChunk = true;
                    }
                    if(isset($chunk->exit_code)){
                        $executor_exit_code = $chunk->exit_code;
                    }
                }
                if($sendNewChunk){
                    echo 'data: ' . json_encode($resp) . "\n\n";
                }

                if ($event == 'Ended') {
                    echo "data: [DONE]\n\n";
                }
                ob_flush();
                flush();

                $history->fill(['output' => $bot_output]);
                $history->save();
            };
            $this->read_backend_stream(
                $history->id,
                $user->tokenable_id,
                $backend_callback
            );
        });

        return $response;
    }

    public function api_abort(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name', 'openai_token')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')));
        if (!$result->exists()) {
             $errorResponse = [
                'status' => 'error',
                'message' => 'Authentication failed',
            ];
            return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
        }

        $user = $result->first();
        
        if (!User::find($user->id)->hasPerm('Room_read_access_to_api') && 
            (config('app.API_Key') == null || config('app.API_Key') != $request->input('key'))) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'You have no permission to use Chat API',
            ];
            return response()->json($errorResponse, 403, [], JSON_UNESCAPED_UNICODE);
        }

        $user_active_history_ids = Redis::lrange('api_' . $user->tokenable_id, 0, -1);
        $user_active_history_ids = array_map(function ($el) {
            return is_string($el) ? -((int) $el) : null;
        }, $user_active_history_ids);
        $user_active_history_ids = array_filter($user_active_history_ids, function ($el) {
            return $el !== null;
        });

        $request_param = $request->json()->all();
        $requested_history_ids = [];
        if (!isset($request_param['ids'])) {
            $requested_history_ids = $user_active_history_ids;
        } else {
            $requested_history_ids = $request_param['ids'];
            $requested_history_ids = array_map(function ($el) {
                return -((int) str_replace(self::CHAT_COMPLETION_PREFIX, '', $el));
            }, $requested_history_ids);
        }

        $history_ids_to_abort = array_intersect($user_active_history_ids, $requested_history_ids);

        $kernel_location = \App\Models\SystemSetting::where('key', 'kernel_location')->first()->value;
        $client = new Client(['timeout' => -1]);
        $msg = $client->post($kernel_location . '/' . RequestChat::$kernel_api_version . '/chat/abort', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'form_params' => [
                'history_id' => json_encode($history_ids_to_abort),
                'user_id' => $user->tokenable_id,
            ],
        ]);
        $response = [
            'status' => 'success',
            'message' => 'Aborted',
            'tokenable_id' => $user->tokenable_id,
            'name' => $user->name,
        ];

        return response()->json($response, 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function api_stream(Request $request)
    {
        ignore_user_abort(true);
        set_time_limit(0);
        if (config('app.API_Key') == null || config('app.API_Key') != $request->input('key')) {
            throw new \Exception("API key doesn't match app.API_Key.");
        }
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('charset', 'utf-8');
        $response->headers->set('Connection', 'close');
        
        $response->setCallback(function() use (&$request) {
            $backend_callback = function ($event, $chunk){
                if ($event == 'Ended') {
                    echo "event: close\n\n";
                    ob_flush();
                    flush();
                } elseif ($event == 'New') {
                    echo 'data: ' . $chunk->msg . "\n";
                    ob_flush();
                    flush();
                } elseif ($event == 'Error') {
                    throw new \Exception($chunk);
                }
            };
            $this->read_backend_stream(
                $request->input('history_id'),
                $request->input('user_id'),
                $backend_callback
            );

            ob_flush();
            flush();
        });
        return $response;
    }
    public static function read_backend_stream($history_id, $user_id, $callback){
        ignore_user_abort(true);
        set_time_limit(0);
        /**
         * Read from the backend redis message queue.
         * The new result will pass to the callback function.
         * This function will block until all message is received.
         */
        
        // Prevent PHP socket timeout
        ini_set('max_execution_time', 0);
        ini_set('default_socket_timeout', -1);
        
        if (!$history_id || !$user_id) {
            $callback("Error", "Missing history_id or user_id.");
            return;
        }
        if (!in_array($history_id, Redis::lrange('api_' . $user_id, 0, -1))) {
            $callback("Error", "No activated session related to the user_id.");
            return;
        }
        try {
            $client = Redis::connection();
            $channel = 'api_' . $history_id;
            // The subscribe loop will block until the channel is unsubscribed or the client is disconnected.
            $client->subscribe($channel, function ($message, $channel) use (&$client, &$callback) {
                [$event, $chunk] = explode(' ', $message, 2);
                if ($event == 'New') {
                    $chunk = json_decode($chunk, false, JSON_INVALID_UTF8_IGNORE);
                }
                $callback($event, $chunk);
                if ($event == 'Ended') {
                    // Terminate the subscribe loop
                    $client->disconnect();
                }
            });
        } catch (\RedisException $e) {
            if ($e->getMessage() != 'Connection closed') {
                Log::error('Caught exception when reading backend stream: ' . $e->getMessage());
            }
        }
    }
}
