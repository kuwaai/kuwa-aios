<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Bus\Queueable;
use App\Events\RequestStatus;
use App\Models\Histories;
use App\Models\ExecutorLog;
use App\Models\Chats;
use App\Models\ChatRoom;
use GuzzleHttp\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use PDO;
use RuntimeException;

enum JobScheduleResult
{
    case BUSY; // The executor is current busy.
    case NOMACHINE; // There's no executor to serve the request.
    case READY; // The executor is allocated and ready to process the request.
    case UNKNOWN;
}

enum AppType
{
    case API; // Kuwa API
    case CHATROOM; // Multi-Chat Chatroom
}

class KuwaKernelException extends \Exception
{
    public function __construct($message, $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    public function __toString()
    {
        return $this->message;
    }
}

class RequestChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    private $input, $access_code, $msgtime, $history_id, $user_id;
    private $channel, $job_queue_id, $app_type;
    private $lang, $modelfile, $openai_token, $google_token, $third_party_token, $user_token, $nim_token;
    private $preserved_output, $exit_when_finish;
    private $kernel_location, $client;
    private $base_url;
    private $pendingExecutorLogs = [];
    private $chatContext = false; // false = not resolved yet, null = resolved to "no context"
    public $backoff_sec = 10;
    public $tries = 100;
    public $retry_after = 86400;
    public $timeout = 86400;
    public static $kernel_api_version = 'v1.0';
    private static $db_retry_attempts = 3;

    /**
     * Retry a callback that requires a DB connection.
     * Non-blocking: disconnect and immediately retry without sleeping,
     * so PHP workers are never held idle.
     */
    private function withDbRetry(callable $callback, bool $throwOnFailure = true)
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= self::$db_retry_attempts; $attempt++) {
            try {
                return $callback();
            } catch (\PDOException $e) {
                $lastException = $e;
                Log::warning("DB attempt {$attempt}/" . self::$db_retry_attempts . " failed: " . $e->getMessage());
                DB::disconnect();
            }
        }
        if ($throwOnFailure) {
            throw $lastException;
        }
        Log::error('All DB retry attempts exhausted, giving up: ' . $lastException->getMessage());
        return null;
    }

    /**
     * Resolve the ['bot_id' => int, 'room_id' => int] this job's target
     * message (`$this->history_id`) belongs to, via Histories -> Chats.
     * Cached after the first (successful or failed) lookup. Returns null
     * when this job isn't a chatroom bot message (e.g. API app type).
     */
    private function getChatContext(): ?array
    {
        if ($this->chatContext !== false) {
            return $this->chatContext;
        }
        $this->chatContext = null;
        if ($this->app_type == AppType::CHATROOM && $this->history_id > 0) {
            $this->withDbRetry(function () {
                $history = Histories::find($this->history_id);
                $chat = $history ? Chats::find($history->chat_id) : null;
                if ($chat) {
                    $this->chatContext = ['bot_id' => $chat->bot_id, 'room_id' => $chat->roomID];
                }
            }, throwOnFailure: false);
        }
        return $this->chatContext;
    }

    /**
     * Parse a `<<<status>>>...<<</status>>>` block's inner JSON and persist
     * it into the chatroom's JSONL `status` column, replacing only the
     * entry belonging to this job's bot_id (other bots' entries are kept
     * untouched).
     */
    private function updateChatroomStatus(string $statusJson): void
    {
        $context = $this->getChatContext();
        if ($context === null) {
            return;
        }
        $decoded = json_decode($statusJson, true);
        if (!is_array($decoded) || !isset($decoded['status'])) {
            Log::warning('Malformed <<<status>>> tag content for room ' . $context['room_id'] . ': ' . $statusJson);
            return;
        }
        $this->withDbRetry(function () use ($context, $decoded) {
            $room = ChatRoom::find($context['room_id']);
            if ($room) {
                $room->setBotStatus($context['bot_id'], (string) $decoded['status'], $decoded['timestamp'] ?? null);
            }
        }, throwOnFailure: false);
    }

    /**
     * Create a new job instance.
     */

    public static function processModelfile($modelfile)
    {
        $excludedNames = ['prompts', 'start-prompts', 'auto-prompts', 'welcome'];

        return $modelfile
            ? json_encode(
                array_values(
                    array_filter(
                        array_map(function ($entry) use ($excludedNames) {
                            if (!in_array($entry->name, $excludedNames, true) && !empty($entry->name) && $entry->name[0] !== '#') {
                                return $entry;
                            }
                        }, $modelfile),
                    ),
                ),
            )
            : null;
    }

    public function __construct($input, $access_code, $user_id, $history_id, $lang, $channel = null, $modelfile = null, $preserved_output = '', $exit_when_finish = true)
    {
        $this->input = json_encode(json_decode($input), JSON_UNESCAPED_UNICODE);
        $this->msgtime = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +1 second'));
        $this->access_code = $access_code;
        $this->user_id = $user_id;
        $this->lang = $lang;
        $this->history_id = $history_id;
        $this->exit_when_finish = $exit_when_finish;
        $this->preserved_output = $preserved_output;
        $this->channel = $channel == null || $channel == '' ? strval($history_id) : $channel;
        $this->app_type = match (strtoupper(explode('_', $channel)[0])) {
            'API' => AppType::API,
            'USERTASK' => AppType::CHATROOM,
            default => AppType::CHATROOM,
        };
        $this->job_queue_id = match ($this->app_type) {
            AppType::API => 'api_' . $user_id,
            AppType::CHATROOM => 'usertask_' . $user_id,
        };
        $this->modelfile = self::processModelfile($modelfile);

        // Capture the public web URL the user is connecting to *now*, while we
        // still have HTTP request context. Falls back to config('app.url') when
        // dispatched from CLI / another queue job (no Request available).
        $request = request();
        $this->base_url = (! App::runningInConsole() && $request && $request->getHost() !== '')
            ? $request->getSchemeAndHttpHost()
            : config('app.url');

        $user = User::find($user_id);
        $this->openai_token = $user->openai_token;
        $this->google_token = $user->google_token;
        $this->nim_token = $user->nim_token;
        $this->third_party_token = $user->third_party_token;
        
        // Check if user has other jobs scheduled or running
        $hasOtherJobs = Redis::llen($this->job_queue_id) > 0;
        
        if ($hasOtherJobs) {
            // Reuse existing backend_token if user has other jobs
            $existingToken = $user->tokens()->where('name', 'backend_token')->first();
            if ($existingToken) {
                $this->user_token = $existingToken->token;
            } else {
                // Fallback: create new token if somehow missing
                $user->createToken('backend_token', ['access_api']);
                $this->user_token = $user->tokens()->where('name', 'backend_token')->first()->token;
            }
        } else {
            // No other jobs: refresh backend_token
            $user->tokens()->where('name', 'backend_token')->delete();
            $user->createToken('backend_token', ['access_api']);
            $this->user_token = $user->tokens()->where('name', 'backend_token')->first()->token;
        }

        // Free the DB connection immediately so the PHP-FPM worker that
        // dispatched this job can return to the pool and serve new requests.
        DB::disconnect();
    }

    /**
     * Read an GuzzleHttp stream.
     *
     * [Deprecated] Since 0.4.0, we use SSE stream with JSON payload to handle internal response.
     * Reading raw byte stream is deprecated and should be removed in the future.
     */
    private function read_stream(&$stream, $timeout_sec = 0.1)
    {
        $buffer = '';
        $start_time = microtime(true);
        while (!$stream->eof() && microtime(true) - $start_time < $timeout_sec) {
            $chunk = $stream->read(1);
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        // Wrap initial DB queries with retry — these run right after the worker
        // picks up the job and may hit the connection limit.
        $shouldReturn = false;
        $this->withDbRetry(function () use (&$shouldReturn) {
            $this->kernel_location = \App\Models\SystemSetting::where('key', 'kernel_location')->first()->value;
            if ($this->history_id > 0 && $this->app_type == AppType::CHATROOM) {
                $history = Histories::find($this->history_id);
                if ($history && $history->msg != '* ...thinking... *' && $this->preserved_output == '') {
                    Log::Debug('Hmmm');
                    $shouldReturn = true;
                }
            }
        });
        if ($shouldReturn) {
            DB::disconnect();
            return;
        }
        $client = new Client(['timeout' => -1]);
        Log::channel('analyze')->Info('In:' . $this->access_code . '|' . $this->user_id . '|' . $this->history_id . '|' . strlen(trim($this->input)) . '|' . trim($this->input) . '|' . $this->lang . '|' . $this->modelfile);
        $start = microtime(true);
        $chatroomProcessor = new ChatroomProcessor();
        $executorExitCode = null;
        try {
            $this->input = $chatroomProcessor->rectifyInputMessage($this->input);
            $this->input = self::validateAndFixStorageUrls($this->input, $this->user_id);

            // Release the DB connection before the long-running stream to avoid
            // exhausting PostgreSQL max_connections. Laravel will auto-reconnect
            // when a DB query is needed again (e.g. in the finally block).
            DB::disconnect();

            $response = $client->post($this->kernel_location . '/' . self::$kernel_api_version . '/chat/completions', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept-Language' => $this->lang,
                    'X-Kuwa-User-Token' => $this->user_token,
                    'X-Kuwa-Api-Base-Urls' => config('app.KUWA_API_BASE_URLS'),
                    'X-Kuwa-Openai-Token' => $this->openai_token,
                    'X-Kuwa-Google-Token' => $this->google_token,
                    'X-Kuwa-Nim-Token' => $this->nim_token,
                    'X-Kuwa-Third-Party-Token' => $this->third_party_token,
                ],
                'form_params' => [
                    'input' => $this->input,
                    'name' => $this->access_code,
                    'user_id' => $this->user_id,
                    'history_id' => $this->history_id * ($this->channel == $this->history_id ? 1 : -1),
                    'modelfile' => $this->modelfile,
                ],
                'stream' => true,
            ]);
            $stream = $response->getBody();
            $buffer = new Utf8Buffer();
            $buffer->addChunk($this->preserved_output);
            while (!$stream->eof()) {
                try {
                    $chunk = \GuzzleHttp\Psr7\Utils::readLine($stream);
                    // Extract text response from SSE data
                    if (str_starts_with($chunk, 'data: ')) {
                        $json = substr($chunk, strlen('data: '));
                        $resp = json_decode($json, true);
                        $resp_chunks = $resp['delta'] ?? [];
                        $chunk = '';
                        foreach ($resp_chunks as $resp_chunk) {
                            $type = $resp_chunk['type'] ?? null;
                            switch ($type) {
                                case 'text':
                                    $chunk .= $resp_chunk['text']['value'] ?? '';
                                    break;
                                case 'log':
                                    $chunk .= "\n[" . ($resp_chunk['log']['level'] ?? '') . '] ' . ($resp_chunk['log']['text'] ?? '');
                                    $this->pendingExecutorLogs[] = [
                                        'tag'     => $resp_chunk['log']['tag'] ?? null,
                                        'level'   => $resp_chunk['log']['level'] ?? null,
                                        'message' => $resp_chunk['log']['text'] ?? null,
                                    ];
                                    break;
                                case 'exit_code':
                                    $executorExitCode = $resp_chunk['exit_code'];
                                    break;
                                case 'status':
                                    // Store status to database but don't render to user
                                    $statusJson = json_encode($resp_chunk['status']);
                                    Log::channel('analyze')->Info($this->history_id . " status: " . $statusJson);
                                    $this->updateChatroomStatus($statusJson);
                                    break;
                                case 'heartbeat':
                                    Redis::publish($this->channel, 'Heartbeat {}');
                                default:
                                    break;
                            }
                        }
                    }
                    $buffer->addChunk($chunk);
                    $message = $buffer->processBuffer();
                    if ($message === '') {
                        continue;
                    }
                    $outputChunk = $chatroomProcessor->addChunk($message);
                    if ($this->app_type == AppType::API) {
                        Redis::publish($this->channel, 'New ' . json_encode(['msg' => $message]));
                    } elseif ($this->app_type == AppType::CHATROOM) {
                        Cache::put("chat_".$this->channel."_tmp", $outputChunk, now()->addMinutes(180));
                        Redis::publish($this->channel, 'New ' . json_encode(['msg' => $outputChunk]));
                    }
                } catch (RuntimeException $e) {
                    // Log the error and break the loop, as the stream is dead.
                    Log::warning('Stream connection lost for job ' . $this->channel . ': ' . $e->getMessage());
                    break;
                }
            }

            if (trim($chatroomProcessor->getOutputChunk(finalize: true)) == '') {
                $chatroomProcessor->addChunk(WarningMessages::EMPTY_RESPONSE);
            }
        } catch (KuwaKernelException $e) {
            $this->endStreamWithMessage($e->getMessage());
        } finally {
            if ($this->job->isReleased()) {
                Log::channel('analyze')->Info('Job ' . $this->channel . ' was released back into the queue.');
                return;
            }
            $end = microtime(true);
            $elapsed = $end - $start;
            $fullOutput = $chatroomProcessor->getOutputChunk(finalize: true);
            Log::channel('analyze')->Info('Out:' . $this->access_code . '|' . $this->user_id . '|' . $this->history_id . '|' . $elapsed . '|' . strlen(trim($fullOutput)) . '|' . Carbon::createFromFormat('Y-m-d H:i:s', $this->msgtime)->diffInSeconds(Carbon::now()) . '|' . $fullOutput);

            $finalOutput = '';
            if ($this->app_type == AppType::CHATROOM) {
                $finalOutput = $fullOutput;
            }
            $this->flushExecutorLogs();
            $this->endStreamWithMessage(msg: $finalOutput, exitCode: $executorExitCode);
            DB::disconnect();
        }
    }
    /**
     * Flush buffered executor log entries into the database with a single INSERT query.
     */
    private function flushExecutorLogs(): void
    {
        if (empty($this->pendingExecutorLogs)) {
            return;
        }

        $now = now();
        $records = array_map(fn(array $entry): array => [
            'tag'         => $entry['tag'],
            'level'       => $entry['level'],
            'message'     => $entry['message'],
            'user_id'     => $this->user_id,
            'base_url'    => $this->base_url ?: config('app.url'),
            'access_code' => $this->access_code,
            'history_id'  => $this->history_id,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $this->pendingExecutorLogs);

        $this->withDbRetry(function () use ($records): void {
            ExecutorLog::insert($records);
        }, throwOnFailure: false);
    }

    public function failed(\Throwable $exception)
    {
        Log::channel('analyze')->Info('Failed job: ' . $this->channel);
        Log::channel('analyze')->Info($exception->getMessage());
        $this->endStreamWithMessage(WarningMessages::DEFAULT_ERROR);
        DB::disconnect();
    }

    private function endStreamWithMessage($msg, $exitCode = null)
    {
        if ($msg !== null && $msg !== '') {
            // throwOnFailure: false — the user already received the streamed
            // response via Redis, so a DB save failure is not fatal.
            $this->withDbRetry(function () use ($msg) {
                $history = Histories::find($this->history_id);
                if ($history != null) {
                    $history->fill(['msg' => $msg]);
                    $history->save();
                    Cache::forget("chat_".$this->history_id."_tmp");
                }
            }, throwOnFailure: false);
        }
        $msgTimeInSeconds = Carbon::createFromFormat('Y-m-d H:i:s', $this->msgtime)->timestamp;
        $currentTimeInSeconds = Carbon::now()->timestamp;
        $ExecutionTime = $currentTimeInSeconds - $msgTimeInSeconds;

        if (($msg !== null && $msg !== '') || !is_null($exitCode)) {
            Redis::publish($this->channel, 'New ' . json_encode(['msg' => $msg, 'exit_code' => $exitCode]));
        }
        if ($this->exit_when_finish) {
            Redis::publish($this->channel, 'Ended Ended');
            Redis::lrem($this->job_queue_id, 0, $this->history_id);
        }
    }

    /**
     * Validates and fixes storage URLs in the input message.
     * 
     * Finds all URLs matching the pattern http(s)://[host]/storage/root/[path] and:
     * 1. Extracts the path
     * 2. Generates OTP for the file
     * 3. Appends ?otp={otp} to URL
     *
     * @param string $inputJson The JSON-encoded message history
     * @param int $userId The user ID for OTP generation
     * @return string The modified JSON with fixed URLs
     */
    public static function validateAndFixStorageUrls(string $inputJson, int $userId): string
    {
        try {
            $decodedMessages = json_decode($inputJson, true);
            
            if (!is_array($decodedMessages)) {
                return $inputJson;
            }
            
            foreach ($decodedMessages as &$record) {
                if (is_array($record)) {
                    // Patch URLs stored in real attachment objects as well as plain text.
                    if (isset($record['attachments']) && is_array($record['attachments'])) {
                        foreach ($record['attachments'] as &$attachment) {
                            if (is_array($attachment) && isset($attachment['url']) && is_string($attachment['url'])) {
                                $attachment['url'] = self::normalizeStorageUrl($attachment['url'], $userId);
                            }
                        }
                        unset($attachment);
                    }

                    $contentKey = isset($record['content']) ? 'content' : (isset($record['msg']) ? 'msg' : null);
                    if ($contentKey !== null && is_string($record[$contentKey])) {
                        $record[$contentKey] = self::processMessageUrls($record[$contentKey], $userId);
                    }
                }
            }
            unset($record);
            
            return json_encode($decodedMessages, JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::warning('Error validating storage URLs: ' . $e->getMessage());
            return $inputJson;
        }
    }

    /**
     * Process all storage URLs in a message, replacing them one by one.
     * 
     * @param string $message The message containing URLs
     * @param int $userId The user ID for OTP generation
     * @return string The message with OTP-modified URLs
     */
    private static function normalizeStorageUrl(string $url, int $userId): string
    {
        // Keep already-durable token URLs unchanged.
        $existingQuery = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($existingQuery, $existingParams);
        if (isset($existingParams['token'])) {
            return $url;
        }

        if (stripos($url, '/storage/root/') === false) {
            return $url;
        }

        $baseUrl = strtok($url, '?');
        $fixedUrl = self::checkAndFixStorageUrl($baseUrl, $userId);
        return $fixedUrl !== $baseUrl ? $fixedUrl : $url;
    }

    private static function processMessageUrls(string $message, int $userId): string
    {
        // Pattern to match storage URL with optional query parameters
        // We exclude characters that typically terminate a URL in common contexts (quotes, angle brackets, spaces, parentheses, brackets, braces)
        $urlPattern = '#https?://[^\s?\'\"<>\(\)\[\]\{\}]+/storage/root/[^\s?\'\"<>\(\)\[\]\{\}]+(\?[^\s\'\"<>\(\)\[\]\{\}]*)?#i';
        
        // Find all URLs with their exact positions
        if (!preg_match_all($urlPattern, $message, $matches, PREG_OFFSET_CAPTURE)) {
            return $message;
        }
        
        // Collect URLs with their positions, and generate OTP for each unique base URL
        $urlReplacements = [];
        $generatedOtps = [];  // Cache OTPs by base URL
        
        foreach ($matches[0] as $match) {
            $fullUrl = $match[0];
            $position = $match[1];
            
            // Extract base URL (without any existing OTP parameters)
            $baseUrl = strtok($fullUrl, '?');

            // A URL that already carries a durable "?token=" (e.g. one the
            // generate_document tool or an earlier attachment already
            // patched in) must be left alone — regenerating an "otp" here
            // would silently discard that valid, reusable credential and
            // replace it with a one-time token the user's link then
            // consumes/expires after a single click.
            $existingQuery = parse_url($fullUrl, PHP_URL_QUERY) ?? '';
            parse_str($existingQuery, $existingParams);
            if (isset($existingParams['token'])) {
                continue;
            }
            
            // Generate OTP if we haven't already for this base URL
            if (!isset($generatedOtps[$baseUrl])) {
                $fixedUrl = self::checkAndFixStorageUrl($baseUrl, $userId);
                $generatedOtps[$baseUrl] = $fixedUrl;
            } else {
                $fixedUrl = $generatedOtps[$baseUrl];
            }
            
            // Add to replacements if URL changed
            if ($fixedUrl !== $baseUrl) {
                $urlReplacements[] = [
                    'replacement' => $fixedUrl,
                    'position' => $position,
                    'length' => strlen($fullUrl)
                ];
            }
        }
        
        // Process replacements in reverse order to maintain position accuracy
        usort($urlReplacements, function($a, $b) {
            return $b['position'] - $a['position'];
        });
        
        foreach ($urlReplacements as $replacement) {
            $message = substr_replace(
                $message,
                $replacement['replacement'],
                $replacement['position'],
                $replacement['length']
            );
        }
        
        return $message;
    }

    /**
     * Checks a storage URL and generates OTP directly without external requests.
     * 
     * Extracts the file path from the URL, calculates the file hash,
     * and generates an OTP token directly. No HEAD requests needed.
     * Returns the URL with ?otp={otp} parameter.
     *
     * @param string $url The URL to check (e.g., http://127.0.0.1/storage/root/homes/1/file.txt)
     * @param int $userId The user ID for OTP generation
     * @return string The URL with OTP parameter appended
     */
    private static function checkAndFixStorageUrl(string $baseUrl, int $userId): string
    {
        try {
            // Extract the path after /storage/root/
            if (!preg_match('#/storage/root/(.+)$#i', $baseUrl, $matches)) {
                return $baseUrl;
            }
            
            $pathWithEncoding = $matches[1];
            // URL decode the path to get the actual file path
            $filePath = urldecode($pathWithEncoding);
            
            // Get the user
            $user = User::find($userId);
            if (!$user) {
                return $baseUrl;
            }
            
            // Construct the full disk path to verify file exists and get hash
            // filePath is like: homes/1/test folder/hello.txt
            $path = self::resolvePath('/' . $filePath);
            $disk = self::getDiskForPath($path);
            
            // Build full path for storage lookup
            $fullPath = storage_path('app/' . $disk . '/root/' . $filePath);
            
            // Check if file exists
            if (!file_exists($fullPath)) {
                return $baseUrl;
            }
            
            // Calculate file hash
            $fileHash = hash_file('sha256', $fullPath);
            
            // Normalize the file path (remove leading slashes) before hashing for consistency with CloudController
            $normalizedFilePath = ltrim($filePath, '/');
            
            // Generate OTP directly using the same method as CloudController
            $otpToken = self::generateFileAccessToken($user, $normalizedFilePath, $fileHash);
            
            if (empty($otpToken)) {
                return $baseUrl;
            }
            
            // Append full OTP parameter (ID|TOKEN format) to base URL
            $urlWithOtp = $baseUrl . '?otp=' . urlencode($otpToken);
            
            return $urlWithOtp;
            
        } catch (\Exception $e) {
            Log::warning('Error checking storage URL: ' . $e->getMessage());
            return $baseUrl;
        }
    }

    /**
     * Helper method to resolve path components (copied from CloudController)
     */
    private static function resolvePath($path)
    {
        return array_merge(
            array_values(array_filter(explode('/', trim($path, '/')))),
            (strlen($path) > 1 && substr($path, -1) === '/') ? [''] : []
        );
    }

    /**
     * Helper method to determine which disk to use (copied from CloudController)
     */
    private static function getDiskForPath($path): string
    {
        $pathStr = is_array($path) ? implode('/', $path) : $path;
        $pathStr = ltrim($pathStr, '/');
        
        if (strpos($pathStr, 'homes') === 0 || $pathStr === 'license.json') {
            return 'protected';
        }
        
        return 'public';
    }

    /**
     * Generate a one-time file access token (copied from CloudController pattern)
     */
    private static function generateFileAccessToken(User $user, string $filePath, string $fileHash): string
    {
        try {
            $filePathHash = hash('sha256', $filePath);
            $fileContentHash = hash('sha256', $fileHash);
            $expectedAbility = 'file:' . $filePathHash . ':' . $fileContentHash;
            
            // Always create new OTP token for each file access
            // This ensures fresh tokens that match the current file state
            $tokenModel = $user->createToken('otp-' . $filePathHash . '-' . uniqid(), ['access_api']);
            $plainTextToken = $tokenModel->plainTextToken;
            
            // Store abilities with file-specific permission and plaintext token for reuse
            $abilitiesData = [
                'abilities' => [$expectedAbility],
                'plaintext_token' => $plainTextToken,
            ];
            
            DB::table('personal_access_tokens')
                ->where('id', $tokenModel->accessToken->id)
                ->update([
                    'abilities' => json_encode($abilitiesData),
                    'expires_at' => now()->addHour(),
                ]);
            
            // Return the FULL plainTextToken (includes ID|TOKEN format)
            return $plainTextToken;
            
        } catch (\Exception $e) {
            Log::warning('Failed to generate file access token: ' . $e->getMessage());
            return '';
        }
    }
}

class Utf8Buffer
{
    private $buffer = '';

    /**
     * Adds a chunk of data to the buffer.
     *
     * @param string $chunk The data chunk to add.
     */
    public function addChunk(string $chunk): void
    {
        $this->buffer .= $chunk;
    }

    /**
     * Processes the buffer to extract and return complete UTF-8 messages.
     *  Returns null if no complete message is found.
     *
     * @return string|null The UTF-8 message, or null if none is found.
     */
    public function processBuffer(): ?string
    {
        $bufferLength = mb_strlen($this->buffer, '8bit');

        for ($i = $bufferLength; $i > 0; $i--) {
            $message = mb_substr($this->buffer, 0, $i, '8bit');

            // UTF-8 encoding check.
            if (mb_check_encoding($message, 'UTF-8')) {
                $this->buffer = mb_substr($this->buffer, $i, $bufferLength - $i, '8bit'); // remove the processed message from the buffer
                return $message;
            }
        }

        return ''; // No complete UTF-8 message found
    }

    /**
     * Gets the remaining unprocessed buffer.
     * @return string
     */
    public function getRemainingBuffer(): string
    {
        return $this->buffer;
    }
}

