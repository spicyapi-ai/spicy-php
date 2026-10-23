<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SpicyClient.php';

use SpicyApi\CurlTransport;
use SpicyApi\SpicyApiError;
use SpicyApi\SpicyClient;
use SpicyApi\StreamTransport;
use SpicyApi\Transport;
use SpicyApi\UploadContentType;

// Loopback tests: bypass the proxy, or libcurl sends even 127.0.0.1 through port 7890.
putenv('NO_PROXY=127.0.0.1,localhost');
putenv('no_proxy=127.0.0.1,localhost');
putenv('SPICY_API_KEY=sk-spicy-' . str_repeat('b', 48));

/**
 * Starts a real PHP built-in server and returns the port it listens on.
 *
 * This suite used not to run at all: it assumed somebody had already started a server on 8731,
 * while the repository contained neither the code to start one nor a tests/srv/ directory, so
 * `composer test` - the Tests step in CI - would fatal on the second suite.
 *
 * The kernel picks the port rather than hard-coding one: several sessions often run things on this
 * machine at once, a fixed port eventually collides, and a collision shows up as "connection
 * refused" - a phrase that points at the code under test rather than at the port.
 */
function spicy_start_stub_server(): int
{
    $router = __DIR__ . '/srv/router.php';
    if (!is_file($router)) {
        fwrite(STDERR, "missing {$router}\n");
        exit(1);
    }

    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        fwrite(STDERR, "could not probe for a free port: {$errstr}\n");
        exit(1);
    }
    $name = (string) stream_socket_get_name($probe, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($probe);

    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "the stub server would not start\n");
        exit(1);
    }
    register_shutdown_function(static function () use ($process): void {
        @proc_terminate($process);
        @proc_close($process);
    });

    // proc_open returning does not mean the port is listening yet; wait until it truly connects.
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $ready = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($ready !== false) {
            fclose($ready);
            return $port;
        }
        usleep(50_000);
    }
    fwrite(STDERR, "the stub server did not begin listening within 5 seconds\n");
    exit(1);
}

$port = spicy_start_stub_server();
$base = "http://127.0.0.1:{$port}/api/v1";
$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ok   $label\n"; }
    else { $failed++; echo "  FAIL $label" . ($detail === '' ? '' : " -- $detail") . "\n"; }
}

foreach (['CurlTransport' => new CurlTransport(), 'StreamTransport' => new StreamTransport()] as $name => $transport) {
    echo "$name\n";
    @unlink(__DIR__ . '/srv/flaky.count');
    $client = new SpicyClient(baseUrl: $base, transport: $transport, sleeper: static fn (float $s) => usleep(1000));

    // A GET with an auth header genuinely crossed the network.
    $echo = $client->getTask('task_live');
    check("$name: GET reaches the server", $echo['method'] === 'GET');
    check("$name: query string preserved", str_contains($echo['path'], '/jobs/recordInfo'));
    check("$name: Authorization arrives", ($echo['headers']['authorization'] ?? '') === 'Bearer sk-spicy-' . str_repeat('b', 48));
    check("$name: User-Agent arrives", str_starts_with($echo['headers']['user-agent'] ?? '', SpicyClient::USER_AGENT_PRODUCT));

    // A POST with a JSON body.
    // The prompt deliberately mixes two-, three- and four-byte UTF-8 so that any re-encoding along
    // the way shows up here rather than in somebody's error message.
    $prompt = "a paper-cut city at dusk \u{2014} caf\u{e9}, \u{3c3}\u{3c6}\u{3ac}\u{3bb}\u{3bc}\u{3b1}, \u{1f6a7}";
    $echo = $client->quote('my/model/text-to-image', ['prompt' => $prompt]);
    check("$name: POST body round-trips", $echo['method'] === 'POST'
        && json_decode($echo['body'], true)['input']['prompt'] === $prompt);
    check("$name: Content-Type arrives", ($echo['headers']['content-type'] ?? '') === 'application/json');
    check("$name: UTF-8 is not escaped", str_contains($echo['body'], "caf\u{e9}"), $echo['body']);

    // The asset library uses two methods nothing else here does, PATCH and DELETE, and the only
    // header the client generates by itself. All three must survive a real socket on both transports.
    $echo = $client->assets->create(source: 'url', url: 'https://example.com/still.png', name: $prompt);
    check("$name: an asset save is a POST to /assets", $echo['method'] === 'POST' && str_ends_with($echo['path'], '/api/v1/assets'));
    check("$name: the generated Idempotency-Key arrives",
        str_starts_with($echo['headers']['idempotency-key'] ?? '', 'spicy-'), json_encode($echo['headers']));
    check("$name: the save body round-trips", json_decode($echo['body'], true)['name'] === $prompt);
    $echo = $client->assets->update('spicy://a/ast_live', favorite: true);
    check("$name: PATCH arrives with its body", $echo['method'] === 'PATCH'
        && str_ends_with($echo['path'], '/assets/ast_live') && json_decode($echo['body'], true) === ['favorite' => true],
        json_encode($echo));
    $echo = $client->assets->delete('ast_live');
    check("$name: DELETE arrives with no body", $echo['method'] === 'DELETE' && $echo['body'] === '', json_encode($echo));
    $echo = $client->assets->list(q: 'hero shot', favorite: false, source: ['task_output', 'upload']);
    parse_str((string) parse_url($echo['path'], PHP_URL_QUERY), $received);
    check("$name: the listing filters arrive decoded as sent",
        $received === ['source' => 'task_output,upload', 'q' => 'hero shot', 'favorite' => 'false'], $echo['path']);

    // Retry: a 503 with Retry-After.
    $t0 = microtime(true);
    $result = (new ReflectionClass($client)) instanceof ReflectionClass ? null : null;
    $flaky = (function () use ($client) {
        $method = new ReflectionMethod($client, 'request');
        $method->setAccessible(true);
        return $method->invoke($client, 'GET', '/flaky', null, [], true);
    })();
    check("$name: a 503 with Retry-After is retried and then succeeds", $flaky['attempts'] === 2, json_encode($flaky));

    // Business codes.
    try {
        (function () use ($client) {
            $method = new ReflectionMethod($client, 'request');
            $method->setAccessible(true);
            return $method->invoke($client, 'GET', '/boom', null, [], true);
        })();
        check("$name: 40004 raises", false);
    } catch (SpicyApiError $e) {
        check("$name: 40004 raises with code, msg and request_id",
            $e->businessCode === 40004 && $e->getMessage() === 'change aspect_ratio' && $e->requestId === 'r3');
    }
}

/* The upload leg: really PUT bytes at the loopback "storage", checking streaming and header
   forwarding one by one. */
echo "upload (CurlTransport, streaming)\n";
$payload = random_bytes(300_000);
$tmp = tempnam(sys_get_temp_dir(), 'spicyup') . '.mp4';
file_put_contents($tmp, $payload);

final class TicketTransport implements Transport
{
    public array $calls = [];
    public function __construct(private Transport $inner, private string $storageUrl) {}
    public function send(string $method, string $url, array $headers, $body, float $timeoutSeconds): \SpicyApi\HttpResponse
    {
        $this->calls[] = $url;
        if (str_ends_with($url, '/common/upload-url')) {
            return new \SpicyApi\HttpResponse(200, [], json_encode(['code' => 200, 'msg' => 'success', 'request_id' => 'r',
                'data' => ['fileId' => 'fil_live', 'key' => 'spicy://f/fil_live', 'uploadUrl' => $this->storageUrl,
                    'method' => 'PUT', 'headers' => ['Content-Type' => 'video/mp4', 'x-amz-checksum-mode' => 'ENABLED'],
                    'expiresAt' => 'x', 'maxBytes' => 94371840]]));
        }
        if (str_ends_with($url, '/commit')) {
            return new \SpicyApi\HttpResponse(200, [], json_encode(['code' => 200, 'msg' => 'success', 'request_id' => 'r',
                'data' => ['fileId' => 'fil_live', 'status' => 'ready', 'bytes' => 300000, 'contentType' => 'video/mp4',
                    'sha256' => str_repeat('a', 64), 'uri' => 'spicy://f/fil_live', 'expiresAt' => 'x']]));
        }
        return $this->inner->send($method, $url, $headers, $body, $timeoutSeconds);
    }
}

@unlink(__DIR__ . '/srv/received.bin');
$transport = new TicketTransport(new CurlTransport(), "http://127.0.0.1:{$port}/storage/put");
$client = new SpicyClient(baseUrl: $base, transport: $transport, sleeper: static fn (float $s) => usleep(1000));
$file = $client->uploadFile($tmp);
check('uploadFile commits and returns the URI', $file['uri'] === 'spicy://f/fil_live');
$received = (string) @file_get_contents(__DIR__ . '/srv/received.bin');
check('every byte arrived intact over a real PUT', $received === $payload,
    sprintf('sent=%d received=%d', strlen($payload), strlen($received)));
$sentHeaders = json_decode((string) @file_get_contents(__DIR__ . '/srv/received-headers.json'), true) ?: [];
check('ticket headers arrived verbatim',
    ($sentHeaders['content-type'] ?? '') === 'video/mp4' && ($sentHeaders['x-amz-checksum-mode'] ?? '') === 'ENABLED',
    json_encode($sentHeaders));
check('no Authorization reached the storage host', !isset($sentHeaders['authorization']), json_encode(array_keys($sentHeaders)));
check('Content-Length was set from the stream', (string) ($sentHeaders['content-length'] ?? '') === (string) strlen($payload));
check('Expect: 100-continue suppressed', !isset($sentHeaders['expect']));
unlink($tmp);

/* baseUrl validation */
echo "baseUrl validation\n";
foreach ([
    'http://api.spicyapi.ai/api/v1' => 'HTTPS',
    'https://user:pw@api.spicyapi.ai/api/v1' => 'credentials',
    'https://api.spicyapi.ai/api/v1?x=1' => 'query',
    'not-a-url' => 'absolute',
] as $bad => $needle) {
    try { new SpicyClient(baseUrl: $bad, transport: new CurlTransport()); check("rejects $bad", false); }
    catch (InvalidArgumentException $e) { check("rejects $bad", str_contains($e->getMessage(), $needle), $e->getMessage()); }
}
try { new SpicyClient(baseUrl: 'http://localhost:8731/api/v1', transport: new CurlTransport()); check('allows http on loopback', true); }
catch (Throwable $e) { check('allows http on loopback', false, $e->getMessage()); }

echo "\npassed {$passed}, failed {$failed}\n";
exit($failed === 0 ? 0 : 1);
