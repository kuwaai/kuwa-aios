<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Http\Requests\BotCreateRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Histories;
use App\Jobs\RequestChat;
use App\Models\Chats;
use App\Models\LLMs;
use App\Models\Bots;
use App\Models\User;
use App\Models\Feedback;
use OpenApi\Attributes as OA;
use DB;
use Session;
// use Illuminate\Support\Facades\Log;

class BotController extends Controller
{
    function modelfile_parse($data)
    {
        $commands = [];
        $currentCommand = [
            'name' => '',
            'args' => '',
        ];
        $flags = [
            'system' => false,
            'beforePrompt' => false,
            'afterPrompt' => false,
        ];

        // Split the input data into lines
        $lines = preg_split('/\r\n|\r|\n/', trim($data));

        // Iterate over each line
        foreach ($lines as $line) {
            $line = trim($line);

            // Array of command keywords
            $commandKeywords = [
                'FROM', 'PROCESS-BOT', 'ADAPTER', 'LICENSE', 'TEMPLATE', 'SYSTEM', 'PARAMETER', 'MESSAGE',
                'BEFORE-PROMPT', 'AFTER-PROMPT', 'PROMPTS', 'AUTO-PROMPTS', 'START-PROMPTS', 'WELCOME',
                'INPUT-BOT', 'INPUT-PREFIX', 'INPUT-SUFFIX',
                'OUTPUT-BOT', 'OUTPUT-PREFIX', 'OUTPUT-SUFFIX',
                'SCRIPT'
            ];

            // Check if the line starts with a command keyword
            if (strpos($line, '#') === 0) {
                // If a command is already being accumulated, push it to the commands array
                if ($currentCommand['name'] !== '') {
                    $commands[] = $currentCommand;
                }
                $currentCommand = [
                    'name' => $line,
                    'args' => '',
                ];
            } elseif (
                array_reduce(
                    $commandKeywords,
                    function ($carry, $keyword) use ($line) {
                        return $carry || stripos($line, $keyword) === 0;
                    },
                    false,
                )
            ) {
                // If a command is already being accumulated, push it to the commands array
                if ($currentCommand['name'] !== '') {
                    $commands[] = $currentCommand;
                }

                // Start a new command
                $currentCommand = [
                    'name' => '',
                    'args' => '',
                ];

                // Split the line into command type and arguments
                if (preg_match('/^(\S+)\s*(.*)$/', $line, $matches)) {
                    $commandType = $matches[1];
                    $commandArgs = isset($matches[2]) ? $matches[2] : '';
                } else {
                    $commandType = $line;
                    $commandArgs = '';
                }

                // Set the current command's name and arguments
                $currentCommand['name'] = strtolower($commandType);
                $currentCommand['args'] = trim($commandArgs);

                if (($currentCommand['name'] === 'system' && $flags['system']) || ($currentCommand['name'] === 'before-prompt' && $flags['beforePrompt']) || ($currentCommand['name'] === 'after-prompt' && $flags['afterPrompt'])) {
                    $currentCommand = [
                        'name' => '',
                        'args' => '',
                    ];
                } else {
                    // Set the flag for the current command
                    $flags[$currentCommand['name']] = true;
                }
            } else {
                // If the line does not start with a command keyword, append it to the current command's arguments
                if (strpos($currentCommand['name'], '#') === 0 || (strlen($currentCommand['args']) > 6 && substr($currentCommand['args'], -3) === '"""' && substr($currentCommand['args'], 0, 3) === '"""')) {
                    $commands[] = $currentCommand;
                    // Start a new command
                    $currentCommand = [
                        'name' => '',
                        'args' => '',
                    ];
                    if (preg_match('/^(\S+)\s*(.*)$/', $line, $matches)) {
                        $commandType = $matches[1];
                        $commandArgs = isset($matches[2]) ? $matches[2] : '';
                    } else {
                        $commandType = $line;
                        $commandArgs = '';
                    }
                    $currentCommand['name'] = strtolower($commandType);
                    $currentCommand['args'] = trim($commandArgs);
                    if ($line === '') {
                        $commands[] = $currentCommand;
                    }
                } elseif ($line === '' && $currentCommand['name'] === '' && $currentCommand['args'] === '') {
                    $commands[] = [
                        'name' => '',
                        'args' => '',
                    ];
                } elseif ($currentCommand['name'] !== '') {
                    $currentCommand['args'] .= "\n" . $line;
                }
            }
        }

        // Push the last command to the commands array
        if ($currentCommand['name'] !== '') {
            $commands[] = $currentCommand;
        }
        return $commands;
    }

    public function home(Request $request)
    {
        return view('store');
    }
    public function create(Request $request)
    {
        $rules = (new BotCreateRequest())->rules();
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->route('store.home')->withErrors($validator)->withInput();
        }
        $validated = $validator->validated();
        $bot_type = $request->input('bot_type');
        $model = LLMs::where('name', '=', $request->input('llm_name'))->first();

        if (!$model) {
            $validator->errors()->add('llm_name', 'The selected model does not exist.');
            return redirect()->route('store.home')->withErrors($validator)->withInput();
        }
        $model_id = $model->id;
        if (!$request->user()->hasPerm('model_' . $model_id)) {
            $validator->errors()->add('llm_name', 'You do not have permission to use this model.');
            return redirect()->route('store.home')->withErrors($validator)->withInput();
        }
        $visibility = $request->input('visibility');
        $permissions = [
            0 => 'tab_Manage',
            1 => 'Store_create_community_bot',
            2 => 'Store_create_group_bot',
            3 => 'Store_create_private_bot',
        ];

        if (($bot_type === "server" || $model_id) &&
            isset($permissions[$visibility]) && $request->user()->hasPerm($permissions[$visibility])
        ) {
            $bot = new Bots();
            $config = [];
            if ($request->input('modelfile')) {
                $config['modelfile'] = $this->modelfile_parse($request->input('modelfile'));
            }
            if ($request->input('react_btn')) {
                $config['react_btn'] = $request->input('react_btn');
            }
            $config = json_encode($config);
            $bot->fill([
                'name' => $request->input('bot_name'),
                'type' => $request->input('bot_type'),
                'visibility' => $visibility,
                'description' => $request->input('bot_describe'),
                'owner_id' => $request->user()->id,
                'model_id' => $model_id,
                'config' => $config,
            ]);
            if ($file = $request->file('bot_image')) {
                if ($bot->image) {
                    Storage::delete($bot->image);
                }
                $bot->image = $file->store('public/images');
            }
            $bot->save();
            return redirect()
                ->route('store.home')
                ->with('last_bot_id', $bot->id);
        }

        return redirect()->route('store.home');
    }
    #[OA\Post(
        path: '/api/user/create/bot',
        summary: 'Create a bot',
        tags: ['Bots'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateBotRequest')
        ),
        responses: [new OA\Response(response: 200, description: 'Bot created')]
    )]
    public function api_create_bot(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();

        if (!$result) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'Authentication failed',
            ];

            return response()->json($errorResponse, 401, [], JSON_UNESCAPED_UNICODE);
        }
        $user = $result;
        Auth::setUser(User::find($user->id));
        $visibility = $request->input('visibility');
        $permissions = [
            0 => 'tab_Manage',
            1 => 'Store_create_community_bot',
            2 => 'Store_create_group_bot',
            3 => 'Store_create_private_bot',
        ];

        if (!isset($permissions[$visibility]) || !$request->user()->hasPerm($permissions[$visibility])) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'You have no permission to use the bot creation API',
            ];

            return response()->json($errorResponse, 403, [], JSON_UNESCAPED_UNICODE);
        }

        $rules = (new BotCreateRequest())->rules();
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $errorResponse = [
                'status' => 'error',
                'message' => json_decode($validator->errors()),
            ];
            return response()->json($errorResponse, 400, [], JSON_UNESCAPED_UNICODE);
        }
        $model = LLMs::where('access_code', '=', $request->input('llm_access_code'))->first();

        if (!$model) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'The base model does not exist.',
            ];
            return response()->json($errorResponse, 404, [], JSON_UNESCAPED_UNICODE);
        }
        $model_id = $model->id;
        if (!$request->user()->hasPerm('model_' . $model_id)) {
            $errorResponse = [
                'status' => 'error',
                'message' => 'You do not have permission to use this model.',
            ];
            return response()->json($errorResponse, 403, [], JSON_UNESCAPED_UNICODE);
        }
        $request->merge(['llm_name' => $model->name]);
        $this->create($request);
        return response()->json(['status' => 'success', 'last_bot_id' => session('last_bot_id')], 200, [], JSON_UNESCAPED_UNICODE);
    }

    #[OA\Get(
        path: '/api/user/read/bots',
        summary: 'List all bots available to the user',
        tags: ['Bots'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response returns a list of bots',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', description: 'Indicates the status of the response', example: 'success'),
                        new OA\Property(property: 'result', type: 'array', description: 'An array of bot objects', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
        ]
    )]
    public function api_read_bots(Request $request)
    {
        $user = $request->user();
        if ($user && $user->hasPerm(['tab_Room', 'tab_Store'])) {
                $result = Bots::getBots($user->group_id)->toarray();
                foreach ($result as &$item) {
                    if (!empty($item['image'])) {
                        $item['image'] = Storage::url($item['image']);
                    }
                    if (!empty($item['base_image'])) {
                        $item['base_image'] = Storage::url($item['base_image']);
                    }
                }
                return response()->json(
                    [
                        'status' => 'success',
                        'result' => array_map(function ($item) {
                            unset($item['deleted_at']);
                            return $item;
                        }, $result),
                    ],
                    200,
                    [],
                    JSON_UNESCAPED_UNICODE,
                );
        } else {
            return response()->json(['status' => 'error', 'message' => 'You have no permission to use this Kuwa API'], 403, [], JSON_UNESCAPED_UNICODE);
        }
    }

    public function update(Request $request)
    {
        $bot = Bots::findOrFail($request->input('id'));
        if ($request->user()->id == $bot->owner_id || $request->user()->hasPerm('tab_Manage')) {
            $model_id = LLMs::where('name', '=', $request->input('llm_name'))->first()->id;

            $config = [];
            if ($request->input('modelfile')) {
                $config['modelfile'] = $this->modelfile_parse($request->input('modelfile'));
            }
            if ($request->input('react_btn')) {
                $config['react_btn'] = $request->input('react_btn');
            }
            if ($request->input('visibility') != null) {
                $visibility = $request->input('visibility');
            }
            $permissions = [
                0 => 'tab_Manage',
                1 => 'Store_create_community_bot',
                2 => 'Store_create_group_bot',
                3 => 'Store_create_private_bot',
            ];
            if ($visibility == $bot->visibility || ($visibility != $bot->visibility && isset($permissions[$visibility]) && $request->user()->hasPerm($permissions[$visibility]))) {
                $bot->visibility = $visibility;
                $config = json_encode($config);
                if ($request->input('bot_name')) {
                    $bot->name = $request->input('bot_name');
                }
                $bot->description = $request->input('bot_describe') ?? '';
                if ($file = $request->file('bot_image')) {
                    if ($bot->image) {
                        Storage::delete($bot->image);
                    }
                    $bot->image = $file->store('public/images');
                }
                $bot->model_id = $model_id;
                $bot->config = $config;
                $bot->save();
            }
        }
        if ($referer = $request->input('referer')) {
            if (str_ends_with($referer, 'room')) {
                return redirect()->route('room.home')->with('llms', request()->input('selected_bots'));
            } elseif (preg_match('/room\/\d+$/', $referer)) {
                return redirect()->to($referer);
            } elseif (str_ends_with($referer, 'store')) {
                return redirect()
                    ->route('store.home')
                    ->with('last_bot_tab', ['system', 'community', 'group', 'private'][$bot->visibility]);
            }
        }
        return redirect()
            ->route('store.home')
            ->with('last_bot_tab', ['system', 'private', 'group', 'community'][$bot->visibility]);
    }
    public function delete(Request $request): RedirectResponse
    {
        $bot = Bots::findOrFail($request->input('id'));
        if ($request->user()->id == $bot->owner_id || $request->user()->hasPerm('tab_Manage')) {
            if ($bot->image) {
                Storage::delete($bot->image);
            }
            $bot->delete();
        }
        return Redirect::route('store.home');
    }

    public function listKnowledge(Request $request)
    {
        // Get the directory from the .env file
        $directory = realpath(config('app.KUWA_ROOT') . "/database");

        // Check if the directory exists
        if (!is_dir($directory)) {
            return response()->json(['error' => 'Directory ' . $directory . ' not found'], 404);
        }

        // Get the files in the directory
        $files = scandir($directory);

        // Remove the '.' and '..' entries
        $files = array_diff($files, array('.', '..'));

        // Create the response data
        $responseData = [];
        foreach ($files as $file) {
            if (!$this->isKnowledgeBase($directory . '/' . $file)){
                continue;
            }
            $responseData[] = [
                'name' => $file,
                // 'path' => $directory . '/' . $file,
            ];
        }

        // Return the data in JSON format
        return response()->json($responseData);
    }

    private function isKnowledgeBase($path) {
        $CONFIG_NAME = "config.json";
        // Check if config.json exists directly in the path
        if (file_exists($path . '/' . $CONFIG_NAME)) {
            return true;
        }

        // Check if config.json exists in the db subdirectory
        if (file_exists($path . '/db/' . $CONFIG_NAME)) {
            return true;
        }

        // Check if the path is a zip file
        if (pathinfo($path, PATHINFO_EXTENSION) === "zip") {
            return true;
        }

        // If none of the criteria hold, return false
        return false;
    }

    public function listBots(Request $request)
    {
        $bots = Bots::getBots($request->user()->group_id);
        $bot_list = Bots::sortBotsByName($bots)->values()->all();

        $expose_bot = function (Bots $bot): array{
            return array(
                "name" => $bot['name'],
                "value" => '.bot/' . $bot['name']
            );
        };

        $bot_list = array_map($expose_bot, $bot_list);
        array_unshift($bot_list, array(
            "name" => trans('store.bot.default_bot'),
            "value" => '.bot/.default'
        ));

        return response()->json($bot_list);

    }
}
