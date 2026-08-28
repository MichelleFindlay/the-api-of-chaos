<?php
declare(strict_types=1);

/**
 * The API of Chaos — REST invocation of one tool.
 *
 * The other half of openapi.php: reached at /mcp/tools/<name> (rewritten
 * here by .htaccess, which also supplies ?name=<name>), for OpenAPI-style
 * tool servers rather than MCP clients. Arguments come from the query
 * string, matching openapi.json's declared parameters. Returns the
 * upstream JSON directly — no MCP content/isError wrapper — with a normal
 * HTTP status: 200 on success, 400/404/502 on the ways a call can fail.
 */

require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$name = (string) ($_GET['name'] ?? '');
$tool = $name !== '' ? mcp_find_tool($MCP_TOOLS, $name) : null;

if ($tool === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => "Unknown tool: $name"]);
    exit;
}

if ($method !== $tool['method']) {
    header('Allow: ' . $tool['method'] . ', OPTIONS');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => "This tool takes {$tool['method']}, not $method."]);
    exit;
}

$args = [];
foreach (array_keys($tool['params']) as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) {
        $args[$key] = $_GET[$key];
    }
}

$result = mcp_invoke_tool($MCP_TOOLS, $tool['name'], $args);

header('Content-Type: application/json; charset=utf-8');
if ($result['error'] !== null) {
    http_response_code($result['status']);
    echo json_encode(['detail' => $result['error']]);
    exit;
}

http_response_code($result['status']);
echo json_encode(
    $result['data'],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
