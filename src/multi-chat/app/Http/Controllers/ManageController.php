<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use App\Http\Requests\LLMUpdateRequest;
use App\Http\Requests\LLMCreateRequest;
use App\Models\Groups;
use App\Models\User;
use App\Models\GroupPermissions;
use App\Models\LLMs;
use App\Models\Logs;
use App\Models\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use DB;
use App\Services\ModelConfigurator;

class ManageController extends Controller
{
    public function api_configure_model(Request $request)
    {
        $token = str_replace('Bearer ', '', $request->header('Authorization', ''));
        $result = DB::table('personal_access_tokens')->join('users', 'tokenable_id', '=', 'users.id')->select('users.id')->where('token', $token)->first();
        if (!$result) return response()->json(['status' => 'error', 'message' => 'Authentication failed'], 401);
        Auth::setUser(User::find($result->id));
        if (!Auth::user()->hasPerm('MODEL_CONFIGURE')) return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);

        $validator = Validator::make($request->all(), [
            'access_code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image'],
            'order' => ['nullable', 'integer'],
            'modelfile' => ['nullable', 'string'],
            'do_not_create_bot' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);

        try {
            $configured = ModelConfigurator::configure([
                'access_code' => $request->input('access_code'),
                'name' => $request->input('name'),
                'image' => $request->file('image'),
                'order' => $request->filled('order') ? (int) $request->input('order') : null,
                'modelfile' => $request->filled('modelfile') ? $request->input('modelfile') : null,
                'do_not_create_bot' => filter_var($request->input('do_not_create_bot', false), FILTER_VALIDATE_BOOLEAN),
                'force' => filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN),
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }
        return response()->json(['status' => 'success', ...$configured]);
    }

    public function group_create(Request $request): RedirectResponse
    {
        if ($request->input('name')) {
            $group = new Groups();
            $group->fill(['name' => $request->input('name'), 'describe' => $request->input('describe'), 'invite_token' => $request->input('invite_code')]);
            $group->save();
            if ($request->input('permissions')) {
                $currentTimestamp = now();
                $perm_records = [];

                foreach ($request->input('permissions') as $perm_id) {
                    $perm_records[] = [
                        'group_id' => $group->id,
                        'perm_id' => $perm_id,
                        'created_at' => $currentTimestamp,
                        'updated_at' => $currentTimestamp,
                    ];
                }
                GroupPermissions::insert($perm_records);
            }
            $log = new Logs();
            $log->fill([
                'action' => 'create_group',
                'description' => "Created group {$group->id} with name {$group->name}" . ($group->describe ? " and described {$group->describe}" : '') . ($group->invite_token ? " and invite_token {$group->invite_token}" : '') . "\npermIDs: " . implode(' ', $request->input('permissions') ?? []),
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
            ]);
            $log->save();
            return Redirect::route('manage.home')
                ->with('last_tab', 'groups')
                ->with('last_group', $group->id)
                ->with('last_action', 'create')
                ->with('status', 'success');
        }
    }

    public function group_update(Request $request): RedirectResponse
    {
        $id = $request->input('id');
        if ($id) {
            $group = Groups::find($id);
            $name = $request->input('name');
            $describe = $request->input('describe');
            $group->fill(['name' => $name, 'describe' => $describe, 'invite_token' => $request->input('invite_code')]);
            $group->save();
            $permissions = $request->input('permissions');
            GroupPermissions::where('group_id', '=', $group->id)->delete();
            if ($permissions) {
                $currentTimestamp = now();
                $perm_records = [];

                foreach ($permissions as $perm_id) {
                    $perm_records[] = [
                        'group_id' => $group->id,
                        'perm_id' => $perm_id,
                        'created_at' => $currentTimestamp,
                        'updated_at' => $currentTimestamp,
                    ];
                }
                GroupPermissions::insert($perm_records);
            }
            $log = new Logs();
            $log->fill([
                'action' => 'update_group',
                'description' => "Updated group {$group->id} by name {$group->name}" . ($group->describe ? " and described {$group->describe}" : '') . ($group->invite_token ? " and invite_token {$group->invite_token}" : '') . "\npermIDs: " . implode(' ', $request->input('permissions') ?? []),
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
            ]);
            $log->save();
            return Redirect::route('manage.home')->with('last_tab', 'groups')->with('last_group', $id)->with('last_action', 'update')->with('status', 'success');
        }
        return Redirect::route('manage.home')->with('last_tab', 'groups')->with('last_group', $id);
    }

    public function group_delete(Request $request): RedirectResponse
    {
        $id = $request->input('id');
        if ($id) {
            $group = Groups::find($id);
            User::where('group_id', '=', $id)->update(['group_id' => null]);
            $group->delete();
            $log = new Logs();
            $log->fill([
                'action' => 'delete_group',
                'description' => 'Deleted group ' . $group->id,
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
            ]);
            $log->save();
            return Redirect::route('manage.home')->with('last_tab', 'groups')->with('last_group', null)->with('last_action', 'delete')->with('status', 'success');
        }
        return Redirect::route('manage.home')->with('last_tab', 'groups')->with('last_group', null);
    }

    public function user_update(Request $request): RedirectResponse
    {
        $user = User::find($request->input('id'));
        if ($request->input('group')) {
            $group_id = Groups::where('name', '=', $request->input('group'))->first()->id;
        } else {
            $group_id = null;
        }
        $user->fill(['name' => $request->input('name'), 'email' => $request->input('email'), 'group_id' => $group_id, 'detail' => $request->input('detail'), 'require_change_password' => $request->input('require_change_password') ?? false]);
        if ($request->input('password')) {
            $user->fill(['password' => Hash::make($request->input('password'))]);
        }
        $user->save();
        return Redirect::route('manage.home')
            ->with('last_tab', 'users')
            ->with('last_tool', 'group_selector')
            ->with('list_group', $group_id == null ? -1 : $group_id)
            ->with('edit_user', $request->input('id'));
    }

    public function tab(Request $request): RedirectResponse
    {
        return Redirect::route('manage.home')->with('last_tab', $request->input('last_tab'))->with('last_tool', $request->input('last_tool'))->with('list_group', $request->input('list_group'));
    }

    public function user_create(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|max:255',
            'group' => 'nullable|string',
            'detail' => 'nullable|string',
            'require_change_password' => 'nullable',
        ]);
        if ($validator->fails()) {
            return Redirect::route('manage.home')->with('last_tab', 'users');
        }
        $user = new User();
        if ($request->input('group')) {
            $group_id = Groups::where('name', '=', $request->input('group'))->first()->id;
        } else {
            $group_id = null;
        }
        $user->fill(['name' => $request->input('name'), 'email' => $request->input('email'), 'group_id' => $group_id, 'detail' => $request->input('detail'), 'require_change_password' => $request->input('require_change_password') ?? false]);
        if ($request->input('password')) {
            $user->fill(['password' => Hash::make($request->input('password'))]);
        }
        $user->save();
        $user->markEmailAsVerified();
        return Redirect::route('manage.home')
            ->with('last_tab', 'users')
            ->with('last_tool', 'group_selector')
            ->with('list_group', $group_id == null ? -1 : $group_id)
            ->with('edit_user', $user->id);
    }

    public function search_user(Request $request): RedirectResponse
    {
        return Redirect::route('manage.home')->with('last_tab', 'users')->with('last_tool', 'fuzzy_selector')->with('fuzzy_search', $request->input('search'));
    }

    public function user_delete(Request $request): RedirectResponse
    {
        $id = $request->input('id');
        if ($id) {
            $user = User::find($id);
            $group_id = $user->group_id;
            $user->delete();
            return Redirect::route('manage.home')
                ->with('last_tab', 'users')
                ->with('last_tool', 'group_selector')
                ->with('list_group', $group_id == null ? -1 : $group_id);
        }
        return Redirect::route('manage.home')->with('last_tab', 'users')->with('last_tool', 'group_selector');
    }

    public function llm_update(LLMUpdateRequest $request): RedirectResponse
    {
        $model = LLMs::findOrFail($request->input('id'));
        $validated = $request->validated();
        if ($file = $request->file('image')) {
            if ($model->image) {
                Storage::delete($model->image);
            }
            $validated['image'] = $file->store('public/images');
        }
		if (is_null($validated['order'])) unset($validated['order']);
        $validated['config'] = [];
        if (isset($validated['system_prompt'])) {
            $validated['config']['startup_prompt'] = [['role' => 'system', 'message' => $validated['system_prompt']]];
            unset($validated['system_prompt']);
        }
        if (isset($validated['react_btn'])) {
            $validated['config']['react_btn'] = $validated['react_btn'];
            unset($validated['react_btn']);
        }
        $validated['config'] = json_encode($validated['config']);
        $model->fill($validated);
        $model->save();
        return Redirect::route('manage.home')->with('last_tab', 'llms')->with('last_llm_id', $request->input('id'));
    }

    public function llm_delete(Request $request): RedirectResponse
    {
        $model = LLMs::findOrFail($request->input('id'));
        if ($model->image) {
            Storage::delete($model->image);
        }
        $model->delete();
        Permissions::where('name', '=', 'model_' . $request->input('id'))->delete();
        return Redirect::route('manage.home')->with('last_tab', 'llms')->with('last_llm_id', $request->input('id'));
    }

    #[OA\Post(
        path: '/api/user/create/base_model',
        summary: 'Create a base model',
        tags: ['Models'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/BaseModel')
        ),
        responses: [new OA\Response(response: 200, description: 'Model created')]
    )]
    public function api_create_base_model(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();
        if ($result) {
            $user = $result;
            Auth::setUser(User::find($user->id));
            if (User::find($user->id)->hasPerm('tab_Manage')) {
                $rules = (new LLMCreateRequest())->rules();
                $validator = Validator::make($request->all(), $rules);

                if ($validator->fails()) {
                    return response()->json(['status' => 'error', 'message' => json_decode($validator->errors())], 422, [], JSON_UNESCAPED_UNICODE);
                }
                $this->llm_create($request);
                return response()->json(['status' => 'success', 'last_llm_id' => session('last_llm_id')], 200, [], JSON_UNESCAPED_UNICODE);
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
    #[OA\Get(
        path: '/api/user/read/models',
        summary: 'List base models',
        tags: ['Models'],
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'List of models')]
    )]
    public function api_read_models(Request $request)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name', 'group_id')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();
        if ($result) {
            $user = $result;
            if (User::find($user->id)->hasPerm('tab_Manage')) {
                return response()->json(
                    [
                        'status' => 'success',
                        'result' => LLMs::wherein(
                            'id',
                            DB::table('group_permissions')
                                ->join('permissions', 'group_permissions.perm_id', '=', 'permissions.id')
                                ->select(DB::raw('substring(permissions.name, 7) as model_id'), 'perm_id')
                                ->where('group_permissions.group_id', $user->group_id)
                                ->where('permissions.name', 'like', 'model_%')
                                ->get()
                                ->pluck('model_id'),
                        )
                            ->orderby('id')
                            ->get()
                            ->toarray(),
                    ],
                    200,
                    [],
                    JSON_UNESCAPED_UNICODE,
                );
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

    public function llm_create(Request $request): RedirectResponse
    {
        $rules = (new LLMCreateRequest())->rules();
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $validated = $validator->validated();
        // Validation passed, create your model instance
        $model = new LLMs();
        if ($file = $request->file('image')) {
            $validated['image'] = $file->store('public/images');
        }
		if (is_null($validated['order'])) unset($validated['order']);
        $validated['config'] = [];
        if (isset($validated['system_prompt'])) {
            $validated['config']['startup_prompt'] = [['role' => 'system', 'message' => $validated['system_prompt']]];
            unset($validated['system_prompt']);
        }
        if (isset($validated['react_btn'])) {
            $validated['config']['react_btn'] = $validated['react_btn'];
            unset($validated['react_btn']);
        }
        $validated['config'] = json_encode($validated['config']);
        $validated['healthy'] = Carbon::now();
        $model->fill($validated);
        $model->save();
        $perm = new Permissions();
        $perm->fill(['name' => 'model_' . $model->id]);
        $perm->save();

        $groups = GroupPermissions::pluck('group_id')->toArray();
        $targetPermID = Permissions::where('name', '=', 'tab_Manage')->first()->id;
        $currentTimestamp = now();
        foreach ($groups as $group) {
            GroupPermissions::where('group_id', $group)
                ->where('perm_id', '=', $perm->id)
                ->delete();
            if (GroupPermissions::where('group_id', $group)->where('perm_id', '=', $targetPermID)->exists()) {
                GroupPermissions::insert([
                    'group_id' => $group,
                    'perm_id' => $perm->id,
                    'created_at' => $currentTimestamp,
                    'updated_at' => $currentTimestamp,
                ]);
            }
        }
        return Redirect::route('manage.home')
            ->with('last_tab', 'llms')
            ->with('last_llm_id', $model->id);
    }

    public function llm_toggle(Request $request): RedirectResponse
    {
        $model = LLMs::findOrFail($request->route('llm_id'));
        $model->enabled = !$model->enabled;
        $model->save();
        return Redirect::route('manage.home')->with('last_tab', 'llms')->with('last_llm_id', $request->route('llm_id'));
    }
}
