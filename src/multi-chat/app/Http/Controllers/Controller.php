<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Kuwa API',
    version: '1.0.0',
    description: 'API definition for KuwaClient service'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT'
)]
#[OA\Schema(
    schema: 'BaseModel',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'access_code', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'other_field', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'CreateRoomRequest',
    type: 'object',
    properties: [
        new OA\Property(
            property: 'llm',
            type: 'array',
            items: new OA\Items(type: 'integer')
        ),
    ]
)]
#[OA\Schema(
    schema: 'CreateUserRequest',
    type: 'object',
    properties: [
        new OA\Property(
            property: 'users',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
                    new OA\Property(property: 'group', type: 'string'),
                    new OA\Property(property: 'detail', type: 'string'),
                    new OA\Property(property: 'require_change_password', type: 'boolean'),
                ]
            )
        ),
    ]
)]
#[OA\Schema(
    schema: 'CreateBotRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'llm_access_code', type: 'string'),
        new OA\Property(property: 'bot_name', type: 'string'),
        new OA\Property(property: 'visibility', type: 'integer'),
    ]
)]
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
