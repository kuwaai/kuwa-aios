<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Controllers\BotController;
use App\Models\LLMs;
use App\Models\Bots;

class ImportBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:import {botfile} {--retry=7}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import bot from a botfile.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $botfilePath = $this->argument('botfile');
        $botfileContents = file_get_contents($botfilePath);
        $botfile = $this->parseBotfile($botfileContents);
        
        print('Importing bot "'.$botfile['name']. '" ...'."\n");

        $model = $this->findModel($botfile['base'], $botfile['name'], $this->option('retry'));
        if (!$model) {
            error_log('The bot "'.$botfile['name'].'" cannot be imported because base executor "'.$botfile['base'].'" does not exist.');
            return;
        }
        $model_id = $model->id;
        $visibility = 0; // System bot
        $config = [];
        $botController = new BotController();
        $config['modelfile'] = $botController->modelfile_parse($botfile['modelfile']);
        $config['react_btn'] = ["feedback", "quote", "other"];
        $config = json_encode($config);

        $bot = Bots::where([['name', '=', $botfile['name']], ['visibility', '=', 0]])->first() ?? new Bots();
        $type = match ($botfile['base']) {
            '.tool/kuwa/weblet' => 'server',
            '.tool/kuwa/agent' => 'agent',
            default => 'prompt',
        };
        $bot->fill([
            'name' => $botfile['name'],
            'type' => $type,
            'visibility' => $visibility,
            'description' => $botfile['description'],
            'model_id' => $model_id,
            'config' => $config
        ]);
        if (isset($botfile['avatar'])) {
            $bot->image = $botfile['avatar'];
        }
        $bot->save();

        print('Bot "'.$botfile['name']. '" imported successfully.'."\n");
    
    }
    private function findModel($access_code, $bot_name, $retry)
    {
        $baseDelay = 0.1;
        $model = null;

        for ($attempt=0; $attempt < $retry; $attempt++) {
            $model = LLMs::where('access_code', '=', $access_code)->first();
            if ($model) {
                break;
            }

            // Calculate exponential backoff delay
            $delay = $baseDelay * pow(2, $attempt);

            // Log the error and retry information
            error_log(sprintf('[%s] Base executor "%s" not found. Retry in %g seconds...', $bot_name, $access_code, $delay));
            sleep($delay);
        }
        return $model; 
    }

    private function getExtension($contentType)
    {
        $mimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
        ];

        // Search for the content type in the array
        if (isset($mimeTypes[$contentType])) {
            return $mimeTypes[$contentType];
        }

        // If not found, try to extract extension from content-type using explode
        $parts = explode('/', $contentType);
        if (count($parts) === 2) {
            return $parts[1];
        }

        // If all else fails, return null
        return null;
    }

    private function storeImage($base64EncodedContents, $contentType)
    {
        $imageData = base64_decode($base64EncodedContents);
        $imageName = Str::random(40) . '.' . $this->getExtension($contentType);
        $path = 'public/images/' . $imageName;

        Storage::put($path, $imageData);

        return $path;
    }

    private function parseBotfile($rawContent)
    {
        $botfile = $this->parseHttpRequest($rawContent);
        $botfile = $this->parseMultipartRequest($botfile['body'], $botfile['headers']['content-type']);
        $parsedBotfile = $this->parseKuwaModelfile($botfile[0]['body']);
        $botAvatarPart = array_values(array_filter($botfile, function ($v, $k) {
            // Check if 'content-location' key exists and its value is '/bot-avatar'
            return isset($v['headers']['content-location']) && $v['headers']['content-location'] === '/bot-avatar';
        }, ARRAY_FILTER_USE_BOTH));
        if (count($botAvatarPart) > 0) {
            $avatarPath = $this->storeImage($botAvatarPart[0]['body'], $botAvatarPart[0]['headers']['content-type']);
            $parsedBotfile['avatar'] = $avatarPath;
        }

        return $parsedBotfile;
    }

    private function parseKuwaModelfile($rawContent)
    {
        $kuwaModelfile = [
            'version' => null,
            'name' => null,
            'description' => null,
            'base' => null,
            'modelfile' => ''
        ];
        $lines = explode("\n", $rawContent);

        foreach ($lines as $line) {
            $char_to_remove=" \"\n\r\t\v\x00";
            if (strpos($line, 'KUWABOT version ') === 0) {
                $kuwaModelfile['version'] = trim(str_replace('KUWABOT version ', '', $line), $char_to_remove);
            } elseif (strpos($line, 'KUWABOT name ') === 0) {
                $kuwaModelfile['name'] = trim(str_replace('KUWABOT name ', '', $line), $char_to_remove);
            } elseif (strpos($line, 'KUWABOT description ') === 0) {
                $kuwaModelfile['description'] = trim(str_replace('KUWABOT description ', '', $line), $char_to_remove);
            } elseif (strpos($line, 'KUWABOT base ') === 0) {
                $kuwaModelfile['base'] = trim(str_replace('KUWABOT base ', '', $line), $char_to_remove);
            } else {
                $kuwaModelfile['modelfile'] .= $line . "\n";
            }
        }

        $kuwaModelfile['modelfile'] = trim($kuwaModelfile['modelfile']);

        return $kuwaModelfile;
    }

    private function parseHttpRequest($rawHttpRequest)
    {
        $parsedHttpRequest = [
            'headers' => [],
            'body' => '',
        ];

        // Split headers and body
        list($headerLines, $parsedHttpRequest['body']) = explode("\r\n\r\n", $rawHttpRequest, 2);

        // Parse headers
        $headerLines = explode("\r\n", $headerLines);
        foreach ($headerLines as $headerLine) {
            if (!empty($headerLine)) {
                list($headerName, $headerValue) = explode(": ", $headerLine, 2);
                $parsedHttpRequest['headers'][strtolower($headerName)] = trim($headerValue);
            }
        }
        return $parsedHttpRequest;
    }

    private function parseMultipartRequest($requestBody, $contentType)
    {
        // Validate input
        if (empty($requestBody) || empty($contentType)) {
            throw new \InvalidArgumentException('Request body and boundary are required.');
        }
        $parts = [];

        preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $matches);
        $boundary = $matches[1];
        $bodyParts = explode("--" . $boundary, $requestBody);

        // Remove preamble and epilogue
        array_shift($bodyParts);
        array_pop($bodyParts);

        foreach ($bodyParts as $bodyPart) {
            $parts[] = $this->parseHttpRequest($bodyPart);
        }

        return $parts;
    }
}
