<?php
declare(strict_types=1);

/**
 * The API of Chaos — OpenAPI 3.1 spec for the REST front door.
 *
 * MCP clients use index.php's JSON-RPC endpoint directly. Clients that only
 * speak REST-described-by-OpenAPI (Open WebUI's "OpenAPI Tool Server"
 * feature, and similar) instead point at this folder and fetch this file —
 * conventionally at /mcp/openapi.json, rewritten to this script by
 * .htaccess — to discover the same tools as plain HTTP endpoints, served by
 * tools.php.
 *
 * Same catalogue as the MCP side (lib.php's $MCP_TOOLS), described a
 * different way. Nothing here needs auth.
 */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

function openapi_parameters(array $tool): array
{
    $parameters = [];
    foreach ($tool['params'] as $key => $spec) {
        $schema = ['type' => $spec['type']];
        if (isset($spec['minimum'])) {
            $schema['minimum'] = $spec['minimum'];
        }
        if (isset($spec['maximum'])) {
            $schema['maximum'] = $spec['maximum'];
        }
        $parameters[] = [
            'name'        => $key,
            'in'          => 'query',
            'required'    => false,
            'description' => $spec['description'],
            'schema'      => $schema,
        ];
    }
    return $parameters;
}

$paths = [];
foreach ($MCP_TOOLS as $tool) {
    $description = $tool['description'];
    if (str_starts_with($tool['path'], '/unhinged/')) {
        $description .= ' ' . UNHINGED_VOID_NOTE;
    }

    $operation = [
        'operationId' => $tool['name'],
        'summary'     => $tool['description'],
        'description' => $description,
        'responses'   => [
            '200' => [
                'description' => 'The upstream response, passed through as-is.',
                'content'     => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
            '400' => ['description' => 'Invalid arguments.'],
            '404' => ['description' => 'Unknown tool.'],
            '502' => ['description' => 'Could not reach the upstream API.'],
        ],
    ];

    $parameters = openapi_parameters($tool);
    if ($parameters !== []) {
        $operation['parameters'] = $parameters;
    }

    $paths['/tools/' . $tool['name']] = [
        strtolower($tool['method']) => $operation,
    ];
}

echo json_encode([
    'openapi' => '3.1.0',
    'info'    => [
        'title'       => SERVER_TITLE,
        'description' => 'Dismissal, at scale, with an SLA of none. REST access to the same tools the MCP '
            . 'endpoint at ' . FRONTEND_MCP_URL . ' offers, for tool servers that speak OpenAPI instead of '
            . 'MCP. No authentication required. ' . UNHINGED_VOID_NOTE,
        'version'     => SERVER_VERSION,
    ],
    'servers' => [
        ['url' => FRONTEND_MCP_URL],
    ],
    'paths' => (object) $paths,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
