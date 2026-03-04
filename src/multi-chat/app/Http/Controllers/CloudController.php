<?php

namespace App\Http\Controllers;

use Illuminate\Http\{
    Request,
    RedirectResponse
};
use Illuminate\Support\Facades\{
    Redirect,
    Storage,
    Auth,
    File
};
use App\Models\{
    SystemSetting,
    User
};
use OpenApi\Attributes as OA;
use DB;
class CloudController extends Controller
{
    function getFileCategory($extension)
    {
        $extension = strtolower($extension);

        $categories = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff', 'tif', 'ico', 'heic', 'img', 'dds'],
            'audio' => ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'wma'],
            'video' => ['mp4', 'webm', 'ogv', 'mkv', 'mov', 'avi', '3gp', 'flv', 'wmv'],
            'document' => ['doc', 'docx', 'odt', 'rtf', 'pages', 'numbers', 'epub', 'mobi', 'htm'],
            'folder' => ['/'],
            'pdf' => ['pdf'],
            'html' => ['html', 'htm', 'xhtml'],
            'text' => [
                'txt', 'json', 'log', 'sql', 'csv', 'xml', 'ini', 'md', 'conf', 'config', 'yml', 'yaml', 'sh',
                'bash', 'bat', 'c', 'cpp', 'h', 'hpp', 'java', 'py', 'js', 'ts', 'jsx', 'tsx', 'php', 'rb',
                'go', 'cs', 'swift', 'rs', 'kt', 'scala', 'rst', 'adoc', 'env', 'properties', 'manifest',
                'plist', 'tex', 'lua', 'perl', 'pl', 'r', 'm', 'matlab', 'sas'
            ],
            'archive' => ['zip', 'rar', 'tar', 'gz', '7z', 'bz2', 'xz', 'tgz', 'zst', 'cab', 'iso', 'jar', 'apk', 'dmg'],
            'code' => ['py', 'c', 'cpp', 'js', 'ts', 'php', 'rb', 'go', 'java', 'cs', 'swift', 'rs', 'kt', 'scala'],
            'spreadsheet' => ['xls', 'xlsx', 'ods', 'csv'],
            'presentation' => ['ppt', 'pptx', 'odp'],
            'font' => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
        ];

        foreach ($categories as $category => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $category;
            }
        }

        return 'file';
    }

    public function home(Request $request)
    {
        return view('cloud');
    }
    function resolvePath($path)
    {
        return array_merge(
            array_values(array_filter(explode('/', trim($path, '/')))),
            (strlen($path) > 1 && substr($path, -1) === '/') ? [''] : []
        );
    }
    function pathToString($pathArray)
    {
        $result = '/' . implode('/', array_filter($pathArray));
        return $result;
    }
    #[OA\Get(
        path: '/api/user/read/cloud/{path}',
        summary: 'List cloud directory or file',
        tags: ['Cloud'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'path',
                in: 'path',
                required: true,
                description: 'The cloud directory or file path. If not provided, defaults to a single dot.',
                schema: new OA\Schema(type: 'string', default: '.')
            ),
        ],
        responses: [new OA\Response(response: 200, description: 'Cloud data listed')]
    )]


    public function api_read_cloud(Request $request, $paths = null)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();
        if ($result) {
            $user = $result;
            if (User::find($user->id)->hasPerm('tab_Cloud')) {
                Auth::setUser(User::find($user->id));
                $authUserId = auth()->id();
                $path = $this->resolvePath($paths);
                $user_dir = $this->resolvePath('/homes/' . $authUserId);
                if (!$request->user()->hasPerm('tab_Manage')) {
                    if (($path[0] ?? null) != 'homes' || ($path[1] ?? null) != $authUserId) {
                        $path = $user_dir;
                    }
                }

                $query_path = $this->pathToString($path);
                $fullPath = storage_path('app/public/root' . $query_path);

                $contents = scandir($fullPath);

                $files = array_filter($contents, function ($item) use ($fullPath) {
                    return is_file($fullPath . '/' . $item);
                });

                $directories = array_filter($contents, function ($item) use ($fullPath) {
                    return is_dir($fullPath . '/' . $item) && !in_array($item, ['.', '..']);
                });

                $directories = array_map(fn($dir) => rtrim($dir, '/') . '/', $directories);

                $items = array_merge($directories, $files);

                $explorer = [];
                foreach ($items as $item) {
                    $itemPath = $fullPath . '/' . $item;

                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        $resolvedPath = readlink($itemPath);
                        $isSymbolicLink = $resolvedPath && $resolvedPath !== $itemPath;
                    } else {
                        if (is_link($itemPath)) {
                            $resolvedPath = readlink($itemPath);
                            $isSymbolicLink = true;
                        } else {
                            $resolvedPath = false;
                            $isSymbolicLink = false;
                        }
                    }

                    $isDirectory = str_ends_with($item, '/') || ($isSymbolicLink && is_dir($resolvedPath)) || is_dir($itemPath);

                    $extension = $isDirectory ? '/' : pathinfo($item, PATHINFO_EXTENSION);

                    $explorer[] = [
                        'name' => basename($item),
                        'is_directory' => $isDirectory,
                        'icon' => $this->getFileCategory($extension),
                    ];
                }

                return response()->json(['status' => 'success', 'result' => compact('query_path', 'explorer')], 200, [], JSON_UNESCAPED_UNICODE);
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

    public function upload(Request $request)
    {
        return view('cloud');
    }
    #[OA\Delete(
        path: '/api/user/delete/cloud/{path}',
        summary: 'Delete cloud file or folder',
        tags: ['Cloud'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'path',
                in: 'path',
                required: true,
                description: 'Path to cloud item to delete',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [new OA\Response(response: 200, description: 'Cloud item deleted')]
    )]
    public function api_delete_cloud(Request $request, $paths = null)
    {
        $result = DB::table('personal_access_tokens')
            ->join('users', 'tokenable_id', '=', 'users.id')
            ->select('tokenable_id', 'users.id', 'users.name')
            ->where('token', str_replace('Bearer ', '', $request->header('Authorization')))
            ->first();

        if ($result) {
            $user = $result;

            if (User::find($user->id)->hasPerm('tab_Cloud')) {
                Auth::setUser(User::find($user->id));
                $authUserId = auth()->id();
                $path = $this->resolvePath($paths);
                $user_dir = $this->resolvePath('/homes/' . $authUserId);
                if (!$request->user()->hasPerm('tab_Manage') && (($path[0] ?? null) != 'homes' || ($path[1] ?? null) != $authUserId)) {
                    return response()->json(
                        [
                            'status' => 'error',
                            'message' => 'Permission not enough to delete this item.',
                        ],
                        403,
                        [],
                        JSON_UNESCAPED_UNICODE,
                    );
                }
                $pathToDelete = '/root' . $this->pathToString($path);
                if (Storage::disk('public')->exists($pathToDelete)) {
                    $fullPath = Storage::disk('public')->path($pathToDelete);

                    if (File::isDirectory($fullPath)) {
                        Storage::disk('public')->deleteDirectory($pathToDelete);
                    } else {
                        Storage::disk('public')->delete($pathToDelete);
                    }

                    return response()->json(
                        [
                            'status' => 'success',
                            'message' => 'File or folder deleted successfully.',
                        ],
                        200,
                        [],
                        JSON_UNESCAPED_UNICODE,
                    );
                } else {
                    return response()->json(
                        [
                            'status' => 'error',
                            'message' => 'File or folder not found.',
                        ],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE,
                    );
                }
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

    public function rename(Request $request)
    {
        return view('cloud');
    }
}