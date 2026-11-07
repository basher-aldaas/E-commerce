<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Central OpenAPI document definition.
 *
 * This class holds no logic — it only carries the global OpenAPI attributes
 * (info, servers, security scheme, tags) and the reusable component schemas
 * that the per-controller endpoint annotations reference. l5-swagger scans
 * this file (it lives under the configured `app/` annotations path) when it
 * generates the spec with `php artisan l5-swagger:generate`.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'E-commerce API',
    description: <<<'TXT'
        REST API for the modular E-commerce application (auth, products, categories,
        inventory, orders, notifications).

        **Authentication** — obtain a JWT from `POST /api/v1/auth/login` (or `register`)
        and send it as `Authorization: Bearer <token>` on every protected endpoint.
        Click **Authorize** and paste just the token.

        All responses use a common envelope: `status` (1 = success, 0 = error),
        `data`, `message`, `status_code`, `timestamp`. List endpoints add a
        `pagination` object.

        **Rate limiting** — API routes allow 60 requests per minute by default, per user
        or per guest IP address. Over the limit they return `429` with a `Retry-After`
        header. Login has its own stricter limit.
        TXT,
)]
#[OA\Server(url: 'http://localhost:8000', description: 'Local development server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'JWT obtained from the login/register endpoints.',
)]
#[OA\Tag(name: 'Auth', description: 'Registration, login and logout')]
#[OA\Tag(name: 'Products', description: 'Product catalogue (v1)')]
#[OA\Tag(name: 'Products v2', description: 'Product catalogue (v2 — filtering & richer shape)')]
#[OA\Tag(name: 'Categories', description: 'Product categories')]
#[OA\Tag(name: 'Inventory', description: 'Stock management')]
#[OA\Tag(name: 'Orders', description: 'Customer orders')]
#[OA\Tag(name: 'Notifications', description: 'User notifications (scaffold — not yet implemented)')]

/* ----------------------------------------------------------------------- */
/* Reusable response envelopes                                             */
/* ----------------------------------------------------------------------- */
#[OA\Schema(
    schema: 'ErrorEnvelope',
    title: 'Error envelope',
    properties: [
        new OA\Property(property: 'status', type: 'integer', example: 0),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(), example: []),
        new OA\Property(property: 'message', type: 'string', example: 'Resource not found.'),
        new OA\Property(property: 'status_code', type: 'integer', example: 404),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationErrorEnvelope',
    title: 'Validation error envelope (422)',
    properties: [
        new OA\Property(property: 'status', type: 'integer', example: 0),
        new OA\Property(
            property: 'data',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string'),
            ),
            example: ['email' => ['The email field is required.']],
        ),
        new OA\Property(property: 'message', type: 'string', example: 'Validation failed'),
        new OA\Property(property: 'status_code', type: 'integer', example: 422),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Pagination',
    title: 'Pagination block',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total', type: 'integer', example: 42),
        new OA\Property(property: 'last_page', type: 'integer', example: 3),
    ],
    type: 'object',
)]

/* ----------------------------------------------------------------------- */
/* Entity schemas                                                          */
/* ----------------------------------------------------------------------- */
#[OA\Schema(
    schema: 'AuthResult',
    title: 'Authentication result',
    properties: [
        new OA\Property(property: 'access_token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJ...'),
        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
        new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
        new OA\Property(
            property: 'user',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 2),
                new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                new OA\Property(property: 'email', type: 'string', example: 'user@system.com'),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['user']),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Category',
    title: 'Category',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Electronics'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Phones, laptops, etc.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Product',
    title: 'Product (v1)',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'category_id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Wireless Mouse'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'price', type: 'string', example: '50.00'),
        new OA\Property(property: 'stock', type: 'integer', example: 18),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'image', type: 'string', nullable: true),
        new OA\Property(property: 'image_url', type: 'string', nullable: true),
        new OA\Property(property: 'category', ref: '#/components/schemas/Category', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ProductV2',
    title: 'Product (v2)',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Wireless Mouse'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'price', type: 'string', example: '50.00'),
        new OA\Property(property: 'stock', type: 'integer', example: 18),
        new OA\Property(property: 'in_stock', type: 'boolean', example: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'image_url', type: 'string', nullable: true),
        new OA\Property(
            property: 'category',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'name', type: 'string', example: 'Electronics'),
            ],
            type: 'object',
            nullable: true,
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'OrderItem',
    title: 'Order line item',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 10),
        new OA\Property(property: 'order_id', type: 'integer', example: 9),
        new OA\Property(property: 'product_id', type: 'integer', example: 1),
        new OA\Property(property: 'quantity', type: 'integer', example: 2),
        new OA\Property(property: 'unit_price', type: 'string', example: '50.00'),
        new OA\Property(property: 'product', ref: '#/components/schemas/Product', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Order',
    title: 'Order',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 9),
        new OA\Property(property: 'user_id', type: 'integer', example: 2),
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['pending', 'confirmed', 'processing', 'completed', 'cancelled'],
            example: 'pending',
        ),
        new OA\Property(property: 'total_amount', type: 'string', example: '261.99'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/OrderItem'),
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class OpenApiSpec
{
}