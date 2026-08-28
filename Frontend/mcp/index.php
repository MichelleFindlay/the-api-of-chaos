<?php
declare(strict_types=1);

/**
 * The API of Chaos — MCP server.
 *
 * A Model Context Protocol endpoint speaking the Streamable HTTP transport
 * (MCP spec 2025-06-18, backward compatible with 2025-03-26). No auth, no
 * session state, nothing to configure — point a client at this URL and go.
 * Compatible with Claude, ChatGPT/OpenAI's Responses `mcp` tool, and any
 * other client that follows the spec, since the JSON-RPC surface is plain
 * and doesn't lean on any one client's quirks.
 *
 *   POST /mcp/   JSON-RPC 2.0 messages (initialize, tools/list, tools/call)
 *   GET  /mcp/   opens the Streamable HTTP listening stream (200,
 *                text/event-stream). This server never has anything to
 *                push, so it closes right away — but it answers 200, not
 *                405, on purpose: some connector setup flows probe with a
 *                bare GET and read any non-2xx there as "this needs
 *                sign-in", which this server doesn't.
 *
 * For clients that don't speak MCP at all (Open WebUI's OpenAPI Tool
 * Server and similar), the same tool catalogue is also published as a
 * plain REST API — see openapi.json and tools.php in this folder.
 *
 * Every tool call proxies straight to the upstream API, server side, with
 * the caller's address forwarded upstream so /pound/dirt still attributes
 * piles to the right person — same trick as ../index.php, just reached
 * through tool calls instead of a browser terminal.
 */

require __DIR__ . '/lib.php';

/** MCP protocol versions this server understands, newest first. */
const SUPPORTED_MCP_VERSIONS = ['2025-06-18', '2025-03-26'];
const DEFAULT_MCP_VERSION    = '2025-06-18';

// ============================================================== MCP: tools

function mcp_tool_input_schema(array $tool): array
{
    $properties = [];
    foreach ($tool['params'] as $key => $spec) {
        $properties[$key] = [
            'type'        => $spec['type'],
            'description' => $spec['description'],
        ];
        if (isset($spec['minimum'])) {
            $properties[$key]['minimum'] = $spec['minimum'];
        }
        if (isset($spec['maximum'])) {
            $properties[$key]['maximum'] = $spec['maximum'];
        }
    }
    return [
        'type'                 => 'object',
        'properties'           => (object) $properties,
        'additionalProperties' => false,
    ];
}

function mcp_tool_list_result(array $tools): array
{
    $out = [];
    foreach ($tools as $tool) {
        $description = $tool['description'];
        if (str_starts_with($tool['path'], '/unhinged/')) {
            $description .= ' ' . UNHINGED_VOID_NOTE;
        }
        $out[] = [
            'name'        => $tool['name'],
            'title'       => $tool['name'],
            'description' => $description,
            'inputSchema' => mcp_tool_input_schema($tool),
            'annotations' => [
                'title'           => $tool['name'],
                'readOnlyHint'    => $tool['method'] === 'GET',
                'destructiveHint' => $tool['method'] === 'DELETE',
                'idempotentHint'  => false,
                'openWorldHint'   => true,
            ],
        ];
    }
    return ['tools' => $out];
}

function mcp_tool_error_result(string $message): array
{
    return [
        'content' => [['type' => 'text', 'text' => $message]],
        'isError' => true,
    ];
}

function mcp_tool_call_result(array $tools, ?string $name, $arguments): array
{
    $result = mcp_invoke_tool($tools, $name, $arguments);

    if ($result['error'] !== null) {
        return mcp_tool_error_result($result['error']);
    }

    $payload = $result['data'];
    $text    = is_string($payload)
        ? $payload
        : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return [
        'content'            => [['type' => 'text', 'text' => (string) $text]],
        'structuredContent'  => [
            'ok'      => $result['ok'],
            'status'  => $result['status'],
            'took_ms' => $result['took_ms'],
            'data'    => $payload,
        ],
        'isError' => !$result['ok'],
    ];
}

// ============================================================ MCP: JSON-RPC

function mcp_negotiate_version($requested): string
{
    if (is_string($requested) && in_array($requested, SUPPORTED_MCP_VERSIONS, true)) {
        return $requested;
    }
    return DEFAULT_MCP_VERSION;
}

function mcp_result(mixed $id, array $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result];
}

function mcp_error_response(mixed $id, int $code, string $message, mixed $data = null): array
{
    $error = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $error['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
}

/** Handles one JSON-RPC message. Returns null for notifications (no reply due). */
function mcp_handle_message(array $tools, $msg): ?array
{
    if (!is_array($msg)) {
        return mcp_error_response(null, -32600, 'Invalid Request');
    }

    $hasId = array_key_exists('id', $msg);
    $id    = $hasId ? $msg['id'] : null;
    $method = $msg['method'] ?? null;

    if (!is_string($method) || $method === '') {
        return $hasId ? mcp_error_response($id, -32600, 'Invalid Request: missing method') : null;
    }

    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    switch ($method) {
        case 'initialize':
            $version = mcp_negotiate_version($params['protocolVersion'] ?? null);
            return mcp_result($id, [
                'protocolVersion' => $version,
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => [
                    'name'    => SERVER_NAME,
                    'title'   => SERVER_TITLE,
                    'version' => SERVER_VERSION,
                ],
                'instructions' => 'Dismissal, at scale, with an SLA of none. Every tool here calls a live, '
                    . 'silly HTTP API and returns whatever it says — nothing is deterministic, nothing is '
                    . 'load-bearing. ' . UNHINGED_VOID_NOTE,
            ]);

        case 'notifications/initialized':
        case 'notifications/cancelled':
        case 'notifications/roots/list_changed':
            return null;

        case 'ping':
            return mcp_result($id, []);

        case 'tools/list':
            return mcp_result($id, mcp_tool_list_result($tools));

        case 'tools/call':
            $name = $params['name'] ?? null;
            $args = $params['arguments'] ?? [];
            return mcp_result($id, mcp_tool_call_result($tools, is_string($name) ? $name : null, $args));

        case 'resources/list':
            return mcp_result($id, ['resources' => []]);

        case 'resources/templates/list':
            return mcp_result($id, ['resourceTemplates' => []]);

        case 'prompts/list':
            return mcp_result($id, ['prompts' => []]);

        default:
            return $hasId ? mcp_error_response($id, -32601, "Method not found: $method") : null;
    }
}

// =================================================================== router

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version');
header('Access-Control-Expose-Headers: Mcp-Session-Id, Mcp-Protocol-Version');
header('MCP-Protocol-Version: ' . DEFAULT_MCP_VERSION);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method === 'GET' || $method === 'HEAD') {
    // Streamable HTTP transport: GET opens a listening SSE stream for
    // server-initiated messages. This server is stateless and never pushes
    // anything unprompted, so the stream opens and closes immediately — but
    // it answers 200, not 405. A wrong-method status here gets misread by
    // some connector setup checks as an auth challenge, which would be
    // wrong: this endpoint has no auth of any kind.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    http_response_code(200);
    if ($method === 'GET') {
        echo ": " . SERVER_NAME . " — no server-initiated messages; stream closes immediately\n\n";
    }
    exit;
}

if ($method !== 'POST') {
    header('Allow: POST, GET, HEAD, OPTIONS');
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > MAX_BODY_BYTES) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(mcp_error_response(null, -32600, 'Request body too large.'));
    exit;
}

$decoded = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(mcp_error_response(null, -32700, 'Parse error: invalid JSON.'));
    exit;
}
if (!is_array($decoded)) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(mcp_error_response(null, -32600, 'Invalid Request: expected a JSON object or array.'));
    exit;
}

$messages = array_is_list($decoded) ? $decoded : [$decoded];
if ($messages === []) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(mcp_error_response(null, -32600, 'Invalid Request: empty batch.'));
    exit;
}

$responses = [];
foreach ($messages as $msg) {
    $reply = mcp_handle_message($MCP_TOOLS, $msg);
    if ($reply !== null) {
        $responses[] = $reply;
    }
}

if ($responses === []) {
    // Every message was a notification — nothing to reply with.
    http_response_code(202);
    exit;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
$single = !array_is_list($decoded);
echo json_encode(
    $single ? $responses[0] : $responses,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
