<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

function try_decode_json(string $jsonString): array
{
    $decodedJson = json_decode($jsonString);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedJson)) {
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

    const BEGIN_WARNING_TAG = '<<<warning>>>';
    const END_WARNING_TAG = '<<</warning>>>';
    const BEGIN_THINKING_TAG = '<<<thinking>>>';
    const END_THINKING_TAG = '<<</thinking>>>';
    const BEGIN_AGENT_TAG = '<<<AGENT>>>';
    const END_AGENT_TAG = '<<</AGENT>>>';
    const BEGIN_STATUS_TAG = '<<<status>>>';
    const END_STATUS_TAG = '<<</status>>>';

    private $tagDetected = false;
    private $buffer = '';
    private $fullOutput = '';
    private $kuwaFlag = false;
    private $warningMessages = [];
    private $thinkingMessages = [];
    private $agentMessages = [];
    private $statusMessages = [];
    private $consumedStatusCount = 0;

    /**
     * Rectifies and cleans the input message by:
     * 1. Decoding the JSON string into an array of message records.
     * 2. Normalizing legacy `isbot`/`msg` records into the OpenAI-compatible `role`/`content` format.
     * 3. Iterating through each record and removing predefined filter strings from the message content.
     * 4. Removing internal tags (warning, thinking, agent) from assistant messages.
     * 5. Setting the `kuwaFlag` based on the presence of "kuwa" (case-insensitive) in the last user message
     *    and the absence of a Turu Guardrail location setting.
     * 6. Capping the message history to prevent overly large requests.
     * 7. Encoding the modified array back into a JSON string.
     *
     * @param string $messageString The JSON string containing the messages to rectify.
     * @return string The rectified JSON string in OpenAI-compatible role/content format.
     */
    public function rectifyInputMessage(string $messageString): string
    {
        $decodedMessages = try_decode_json($messageString);

        $lastUserRecord = null;
        foreach ($decodedMessages as $record) {
            // Normalize to OpenAI-compatible role/content format
            if (!(isset($record->role) && isset($record->content))) {
                $record->content = $record->msg ?? '';
                $record->role = (bool) ($record->isbot ?? false) ? 'assistant' : 'user';
                unset($record->msg);
                unset($record->isbot);
            }

            foreach (self::$inputFilters as $filter) {
                if (strpos($record->content, $filter) !== false) {
                    $record->content = trim(str_replace($filter, '', $record->content));
                }
            }
            if ($record->role === 'assistant') {
                $record->content = preg_replace('#'.self::BEGIN_WARNING_TAG.'.*?'.self::END_WARNING_TAG.'#si', '', $record->content);
                $record->content = preg_replace('#'.self::BEGIN_THINKING_TAG.'.*?'.self::END_THINKING_TAG.'#si', '', $record->content);
                $record->content = preg_replace('#'.self::BEGIN_AGENT_TAG.'.*?'.self::END_AGENT_TAG.'#si', '', $record->content);
            } else {
                $lastUserRecord = $record;
            }
        }

        $lastUserMessageText = null;
        if ($lastUserRecord !== null) {
            $lastUserMessageText = $lastUserRecord->content;
        }
        $this->kuwaFlag = $lastUserMessageText !== null && strpos(strtoupper($lastUserMessageText), strtoupper('kuwa')) !== false && trim(\App\Models\SystemSetting::where('key', 'safety_guard_location')->first()->value) === '';

        $decodedMessages = self::capMessageHistory($decodedMessages);

        return json_encode($decodedMessages);
    }

    /** Maximum number of messages fed to the kernel in one request. */
    const MAX_HISTORY_MESSAGES = 10;
    /** Total character budget across all fed messages' content. */
    const MAX_HISTORY_CHARS = 30000;
    /** Never trim below this many messages, even if still over budget. */
    const MIN_KEPT_MESSAGES = 2;

    /**
     * Cap the message history sent to the kernel so a very long-running
     * chatroom never balloons the request: at most
     * ``MAX_HISTORY_MESSAGES`` messages are kept (oldest dropped first),
     * and once within that count the oldest messages keep being dropped
     * while the combined content length exceeds ``MAX_HISTORY_CHARS`` -
     * except it will never trim below ``MIN_KEPT_MESSAGES`` (the user's
     * current message and the bot's last response), even if that pair
     * alone is still over the char budget.
     *
     * @param array $decodedMessages Oldest-first array of message records
     *   (either legacy `isbot`/`msg` or OpenAI-style `role`/`content`).
     * @return array The (possibly trimmed) message array.
     */
    public static function capMessageHistory(array $decodedMessages): array
    {
        if (count($decodedMessages) > self::MAX_HISTORY_MESSAGES) {
            $decodedMessages = array_slice($decodedMessages, -self::MAX_HISTORY_MESSAGES);
        }

        $totalChars = function (array $messages): int {
            $sum = 0;
            foreach ($messages as $m) {
                $sum += mb_strlen((string) ($m->content ?? ''));
            }
            return $sum;
        };

        while (count($decodedMessages) > self::MIN_KEPT_MESSAGES && $totalChars($decodedMessages) > self::MAX_HISTORY_CHARS) {
            array_shift($decodedMessages);
        }

        return array_values($decodedMessages);
    }

    /**
     * Processes a single chunk of the raw output stream from the executor.
     *
     * It appends the chunk to the main output (`$fullOutput`) unless it detects
     * the potential start of a warning tag ('<<<warning>>>'). If a warning tag is
     * suspected or being processed, chunks are added to `$warningBuffer` until
     * the closing tag ('<<</warning>>>') is found or it's determined not to be a warning.
     * Extracted warnings are stored in `$warningMessages`.
     *
     * @param string $chunk A segment of the executor's output stream.
     * @return string The current state of the processed output, suitable for streaming.
     */
    public function addChunk(string $chunk): string
    {
        $this->buffer .= $chunk;
        // If buffer is not begin with desired tag, disable buffering.
        $this->tagDetected = $this->isBufferContainTag(self::BEGIN_WARNING_TAG) ||
                             $this->isBufferContainTag(self::BEGIN_THINKING_TAG) ||
                             $this->isBufferContainTag(self::BEGIN_AGENT_TAG) ||
                             $this->isBufferContainTag(self::BEGIN_STATUS_TAG);

        if (!$this->tagDetected) {
            $this->fullOutput .= $this->buffer;
            $this->buffer = '';
        } else {
            $this->captureTaggedMessage(
                beginTag: self::BEGIN_WARNING_TAG,
                endTag: self::END_WARNING_TAG,
                destination: $this->warningMessages
            );
            $this->captureTaggedMessage(
                beginTag: self::BEGIN_THINKING_TAG,
                endTag: self::END_THINKING_TAG,
                destination: $this->thinkingMessages
            );
            $this->captureTaggedMessage(
                beginTag: self::BEGIN_AGENT_TAG,
                endTag: self::END_AGENT_TAG,
                destination: $this->agentMessages
            );
            $this->captureTaggedMessage(
                beginTag: self::BEGIN_STATUS_TAG,
                endTag: self::END_STATUS_TAG,
                destination: $this->statusMessages
            );
        }
        return $this->getOutputChunk();
    }


    /**
     * Detect whether the buffer containing part of tag.
     * Return true when buffer containing part of tag.
     */
    private function isBufferContainTag(string $tag): bool
    {
        return str_starts_with($tag, $this->buffer) || str_starts_with($this->buffer, $tag);
    }

    /**
     * Capture the message between $beginTag and $endTag from buffer.
     * Then store into the last element of $destination array.
     */
    private function captureTaggedMessage(string $beginTag, string $endTag, array &$destination): void
    {
        // Capture won't start until the begin tag is seen.
        if (!$this->tagDetected || !$this->isBufferContainTag($beginTag)) {
            return;
        }

        // Append unfinished message to the last element of destination
        $message = $this->buffer;
        $lastMessage = end($destination);
        if (str_starts_with($message, $lastMessage)) {
            array_pop($destination);
        }
        $destination[] = $message;

        // Finish capturing when detected ending tag.
        if (strpos($this->buffer, $endTag) !== false) {
            $parts = explode($endTag, $this->buffer, 2);
            $message = $parts[0] . $endTag;
            array_pop($destination);
            $destination[] = $message;
            // Put the remain part back to the buffer.
            $this->buffer = $parts[1];
        }
    }

    private function formatTaggedMessage(array $source, string $beginTag, string $endTag): string
    {
        $filteredMessage = array_filter(
            $source,
            fn(string $x): bool => str_starts_with(trim($x), $beginTag)
        );
        $cleanedMessage = array_map(
            fn(string $x): string => trim(str_replace([$beginTag, $endTag], '', $x)),
            $filteredMessage
        );

        $result = '';
        if (count($cleanedMessage) != 0){
            $result = $beginTag . implode("\n", $cleanedMessage) . $endTag;
        }
        return $result;
    }

    /**
     * Gets the current state of the formatted output chunk.
     *
     * This combines the main accumulated output (`$fullOutput`), appends "..." if the stream
     * is not finalized, adds the KUWA warning if the `$kuwaFlag` is set, and appends all
     * extracted warning messages (`$warningMessages`) enclosed in '<<<warning>>>' tags.
     *
     * @param bool $finalize If true, indicates this is the final chunk and "..." should not be appended. Defaults to false.
     * @return string The formatted output string ready for display or further processing.
     */
    public function getOutputChunk(bool $finalize = false): string
    {
        $outputChunk = $this->formatTaggedMessage(
            source: $this->agentMessages,
            beginTag: self::BEGIN_AGENT_TAG,
            endTag: self::END_AGENT_TAG
        );
        $outputChunk .= $this->formatTaggedMessage(
            source: $this->thinkingMessages,
            beginTag: self::BEGIN_THINKING_TAG,
            endTag: self::END_THINKING_TAG
        );
        $outputChunk .= $this->fullOutput . ($finalize ? '' : '...');
        if ($this->kuwaFlag) {
            $outputChunk .= "\n\n" . WarningMessages::KUWA_WARNING;
        }
        $outputChunk .= $this->formatTaggedMessage(
            source: $this->warningMessages,
            beginTag: self::BEGIN_WARNING_TAG,
            endTag: self::END_WARNING_TAG
        );
        return $outputChunk;
    }
}
