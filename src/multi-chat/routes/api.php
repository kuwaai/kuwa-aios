<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\ManageController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\CloudController;
use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
Route::get('/health', fn() => response()->json(['ok' => true]))->name('api.health');

Route::middleware('ipCheck','auth:sanctum')->group(function () {

    Route::get('user', function (Request $request) {
        // Get the user data
        $userData = $request->user();

        // Filter the user data to include only specific fields
        $filteredUserData = [
            'name' => $userData->name,
            'email' => $userData->email,
            'group_id' => $userData->group_id,
            'email_verified_at' => $userData->email_verified_at,
            'id' => $userData->id,
            'term_accepted' => $userData->term_accepted,
            'announced' => $userData->announced,
            'created_at' => $userData->created_at,
        ];

        return $filteredUserData;
    });

    Route::post('system/updateProject', [SystemController::class, 'api_update_project']);
    Route::post('system/checkUpdate', [SystemController::class, 'api_check_update']);

});
Route::middleware(['ipCheck', 'auth:sanctum'])->controller(RoomController::class)->prefix('rooms')->group(function () {
    Route::get('/', 'api_read_rooms');
    Route::post('/', 'api_create_room');
    Route::get('/{room_id}', 'api_read_room');
    Route::patch('/{room_id}', 'api_rename_room');
    Route::delete('/{room_id}', 'api_delete_room_by_id');
    Route::post('/{room_id}/message', 'api_send_message');
    Route::get('/{room_id}/message', 'api_get_messages');
    Route::post('/{room_id}/abort', 'api_abort_room');
});
Route::middleware(['ipCheck', 'auth:sanctum'])->get('bots', [BotController::class, 'api_read_bots']);
Route::middleware(['ipCheck', 'auth:sanctum'])->group(function () {
    Route::get('cloud/{paths?}', [CloudController::class, 'api_read_cloud'])
        ->where('paths', '.*');
    Route::post('cloud', [ProfileController::class, 'api_upload_file']);
    Route::delete('cloud/{paths?}', [CloudController::class, 'api_delete_cloud'])
        ->where('paths', '.*');
});
Route::middleware('ipCheck')->post('models/configure', [ManageController::class, 'api_configure_model']);
Route::middleware(['ipCheck', 'auth:sanctum'])->controller(ManageController::class)->group(function () {
    Route::get('groups', 'api_list_groups');
    Route::get('groups/{id}', 'api_get_group');
    Route::post('groups', 'api_create_group');
    Route::patch('groups/{id}', 'api_update_group');
    Route::delete('groups/{id}', 'api_delete_group');
});
Route::middleware('ipCheck')->get('islogin', function (Request $request) {
    return ['logged_in' => $request->user() ? true : false];
});

Route::middleware('ipCheck')->get('system/build-info', [SystemController::class, 'api_get_build_info']);