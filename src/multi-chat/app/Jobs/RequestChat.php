<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Bus\Queueable;
use App\Events\RequestStatus;
use App\Models\Histories;
use GuzzleHttp\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;

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

class WarningMessages
{
    const DEFAULT_ERROR = '[Sorry, something is broken, please try again later!]';
    const NO_EXECUTOR = "[Sorry, There're no machine to process this LLM right now! Please report to Admin or retry later!]";
    const EMPTY_RESPONSE = '[Oops, the LLM returned empty message, please try again later or report to admins!]';
    const KUWA_WARNING = '[Regarding the introduction of Kuwa, please refer to the information on the official kuwaai.org website.]';
    const KUWA_WARNING_ZH = '[有關Kuwa的相關說明，請以 kuwaai.org 官網的資訊為準。]';
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
    public $backoff_sec = 10; # Backoff for 10 seconds when the executors are busy
    public $tries = 100; # Wait 1000 seconds in total
    public $timeout = 99999999; # For the 100th try, 200 seconds limit is given
    public static $kernel_api_version = 'v1.0';

    /**
     * Create a new job instance.
     */

    public function processModelfile($modelfile)
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
        $this->modelfile = $this->processModelfile($modelfile);
        $user = User::find($user_id);
        $this->openai_token = $user->openai_token;
        $this->google_token = $user->google_token;
        $this->nim_token = $user->nim_token;
        $this->third_party_token = $user->third_party_token;
        if ($user->tokens()->where('name', 'API_Token')->count() != 1) {
            $user->tokens()->where('name', 'API_Token')->delete();
            $user->createToken('API_Token', ['access_api']);
        }
        $this->user_token = $user->tokens()->where('name', 'API_Token')->first()->token;
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
        $this->kernel_location = \App\Models\SystemSetting::where('key', 'kernel_location')->first()->value;
        $client = new Client(['connect_timeout' => 0]);
        if ($this->history_id > 0 && $this->app_type == AppType::CHATROOM) {
            if (Histories::find($this->history_id) && Histories::find($this->history_id)->msg != '* ...thinking... *' && $this->preserved_output == '') {
                Log::Debug('Hmmm');
                return;
            }
        }
        Log::channel('analyze')->Info('In:' . $this->access_code . '|' . $this->user_id . '|' . $this->history_id . '|' . strlen(trim($this->input)) . '|' . trim($this->input) . '|' . $this->lang . '|' . $this->modelfile);
        $start = microtime(true);
        $chatroomProcessor = new ChatroomProcessor();
        $executorExitCode = null;
        try {
            $schedulingResult = $this->tryScheduleJob();
            if ($schedulingResult == JobScheduleResult::BUSY) {
                Log::channel('analyze')->Info('BUSY: ' . $this->access_code . ' | ' . $this->history_id . '|' . strlen(trim($this->input)) . '|' . trim($this->input));
                $this->release($this->backoff_sec);
            } elseif ($schedulingResult == JobScheduleResult::NOMACHINE) {
                Log::channel('analyze')->Info('NOMACHINE: ' . $this->access_code . ' | ' . $this->history_id . '|' . strlen(trim($this->input)) . '|' . trim($this->input));
                throw new KuwaKernelException(WarningMessages::NO_EXECUTOR);
            }

            // Early return if job scheduling is not succeed.
            if ($schedulingResult != JobScheduleResult::READY) {
                return;
            }

            $this->input = $chatroomProcessor->rectifyInputMessage($this->input);

            $response = $client->post($this->kernel_location . '/' . self::$kernel_api_version . '/chat/completions', [
                'read_timeout' => 0, // Disable timeout for reading stream chunks
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept-Language' => $this->lang,
                    'X-Kuwa-User-Id' => $this->user_id,
                    'X-Kuwa-Api-Token' => $this->user_token,
                    'X-Kuwa-Api-Base-Urls' => config('app.KUWA_API_BASE_URLS'),
                ],
                'form_params' => [
                    'input' => $this->input,
                    'name' => $this->access_code,
                    'user_id' => $this->user_id,
                    'history_id' => $this->history_id * ($this->channel == $this->history_id ? 1 : -1),
                    'openai_token' => $this->openai_token,
                    'google_token' => $this->google_token,
                    'nim_token' => $this->nim_token,
                    'third_party_token' => $this->third_party_token,
                    'user_token' => $this->user_token,
                    'modelfile' => $this->modelfile,
                ],
                'stream' => true,
            ]);
            $stream = $response->getBody();
            $buffer = new Utf8Buffer();
            $buffer->addChunk($this->preserved_output);
            while (!$stream->eof()) {
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
                                break;
                            case 'exit_code':
                                $executorExitCode = $resp_chunk['exit_code'];
                                break;
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
                    Redis::publish($this->channel, 'New ' . json_encode(['msg' => $outputChunk]));
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
            $this->endStreamWithMessage(msg: $finalOutput, exitCode: $executorExitCode);
        }
    }
    public function failed(\Throwable $exception)
    {
        Log::channel('analyze')->Info('Failed job: ' . $this->channel);
        Log::channel('analyze')->Info($exception->getMessage());
        $this->endStreamWithMessage(WarningMessages::DEFAULT_ERROR);
    }

    private function endStreamWithMessage($msg, $exitCode = null)
    {
        if (!empty($msg)) {
            $history = Histories::find($this->history_id);
            if ($history != null) {
                $history->fill(['msg' => $msg]);
                $history->save();
            }
        }
        $msgTimeInSeconds = Carbon::createFromFormat('Y-m-d H:i:s', $this->msgtime)->timestamp;
        $currentTimeInSeconds = Carbon::now()->timestamp;
        $ExecutionTime = $currentTimeInSeconds - $msgTimeInSeconds;

        if (!empty($msg) || !is_null($exitCode)) {
            Redis::publish($this->channel, 'New ' . json_encode(['msg' => $msg, 'exit_code' => $exitCode]));
        }
        if ($this->exit_when_finish) {
            Redis::publish($this->channel, 'Ended Ended');
            Redis::lrem($this->job_queue_id, 0, $this->history_id);
        }
    }

    private function tryScheduleJob()
    {
        $client = new Client(['read_timeout' => 0, 'connect_timeout' => 0]);
        $response = $client->post($this->kernel_location . '/' . self::$kernel_api_version . '/worker/schedule', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'form_params' => [
                'name' => $this->access_code,
                'history_id' => $this->history_id * ($this->app_type == AppType::CHATROOM ? 1 : -1),
                'user_id' => $this->user_id,
            ],
            'stream' => true,
        ]);
        $state = trim($response->getBody()->getContents());
        return match (strtoupper($state)) {
            'BUSY' => JobScheduleResult::BUSY,
            'NOMACHINE' => JobScheduleResult::NOMACHINE,
            'READY' => JobScheduleResult::READY,
            default => JobScheduleResult::UNKNOWN, // Or throw an exception if the state should be one of the above
        };
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

function try_decode_json(string $jsonString): array
{
    $decodedJson = json_decode($jsonString);

    if ($decodedJson === false && json_last_error() !== JSON_ERROR_NONE) {
        Log::channel('analyze')->Info("JSON decode error.\n" . $jsonString);
        throw new \RuntimeException('JSON decode error.');
    }

    return $decodedJson;
}

class ChatroomProcessor
{
    /**
     * A processor to process the input from chatroom and format the executor response of multi-chat chatroom.
     * It handles input sanitization, warning message extraction, and output formatting for a multi-chat application.
     */
    public static $inputFilters = [WarningMessages::DEFAULT_ERROR, WarningMessages::NO_EXECUTOR, WarningMessages::EMPTY_RESPONSE, WarningMessages::KUWA_WARNING, WarningMessages::KUWA_WARNING_ZH];

    private $bufferEnabled = false;
    private $warningBuffer = '';
    private $fullOutput = '';
    private $kuwaFlag = false;
    private $warningMessages = [];

    /**
     * Rectifies and cleans the input message by:
     * 1. Decoding the JSON string into an array of message records.
     * 2. Iterating through each record and removing predefined filter strings from the message content.
     * 3. Removing warning messages generated by bots (identified by `isbot` flag).
     * 4. Setting the `kuwaFlag` based on the presence of "kuwa" (case-insensitive) in the last user message
     *    and the absence of a safety guard location setting.
     * 5. Encoding the modified array back into a JSON string.
     *
     * @param string $messageString The JSON string containing the messages to rectify.
     * @return string The rectified JSON string.
     */
    public function rectifyInputMessage(string $messageString): string
    {
        $decodedMessages = try_decode_json($messageString);

        foreach ($decodedMessages as $record) {
            foreach (self::$inputFilters as $filter) {
                if (strpos($record->msg, $filter) !== false) {
                    $record->msg = trim(str_replace($filter, '', $record->msg));
                }
            }
            if ($record->isbot) {
                $record->msg = preg_replace('#<<<WARNING>>>.*?<<</WARNING>>>#s', '', $record->msg);
            }
        }
        $lastUserMessage = collect($decodedMessages)->where('isbot', false)->last();
        $this->kuwaFlag = $lastUserMessage !== null && strpos(strtoupper($lastUserMessage->msg), strtoupper('kuwa')) !== false && trim(\App\Models\SystemSetting::where('key', 'safety_guard_location')->first()->value) === '';

        return json_encode($decodedMessages);
    }

    /**
     * Processes a single chunk of the raw output stream from the executor.
     *
     * It appends the chunk to the main output (`$fullOutput`) unless it detects
     * the potential start of a warning tag ('<<<WARNING>>>'). If a warning tag is
     * suspected or being processed, chunks are added to `$warningBuffer` until
     * the closing tag ('<<</WARNING>>>') is found or it's determined not to be a warning.
     * Extracted warnings are stored in `$warningMessages`.
     *
     * @param string $chunk A segment of the executor's output stream.
     * @return string The current state of the processed output, suitable for streaming.
     */
    public function addChunk(string $chunk): string
    {
        if (str_starts_with($chunk, '<') && !$this->bufferEnabled) {
            $this->bufferEnabled = true;
        }

        if (!$this->bufferEnabled) {
            $this->fullOutput .= $chunk;
        } else {
            $this->warningBuffer .= $chunk;
            if (strpos('<<<WARNING>>>', $this->warningBuffer) === false && strpos($this->warningBuffer, '<<<WARNING>>>') === false) {
                $this->fullOutput .= $this->warningBuffer;
                $this->warningBuffer = '';
                $this->bufferEnabled = false;
            } elseif (str_ends_with($this->warningBuffer, '<<</WARNING>>>') || str_ends_with($this->warningBuffer, '<<<\/WARNING>>>')) {
                $this->warningMessages[] = trim(str_replace(['<<<WARNING>>>', '<<</WARNING>>>', '<<<\/WARNING>>>'], '', $this->warningBuffer));
                $this->warningBuffer = '';
                $this->bufferEnabled = false;
            }
        }
        return $this->getOutputChunk();
    }

    /**
     * Gets the current state of the formatted output chunk.
     *
     * This combines the main accumulated output (`$fullOutput`), appends "..." if the stream
     * is not finalized, adds the KUWA warning if the `$kuwaFlag` is set, and appends all
     * extracted warning messages (`$warningMessages`) enclosed in '<<<WARNING>>>' tags.
     *
     * @param bool $finalize If true, indicates this is the final chunk and "..." should not be appended. Defaults to false.
     * @return string The formatted output string ready for display or further processing.
     */
    public function getOutputChunk(bool $finalize = false): string
    {
        $outputChunk = $this->fullOutput . ($finalize ? '' : '...');
        if ($this->kuwaFlag) {
            $outputChunk .= "\n\n" . WarningMessages::KUWA_WARNING;
        }
        if ($this->warningMessages) {
            $outputChunk .= '<<<WARNING>>>' . implode("\n", $this->warningMessages) . '<<</WARNING>>>';
        }
        return $outputChunk;
    }
}