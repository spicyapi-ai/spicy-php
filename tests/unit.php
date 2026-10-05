<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SpicyClient.php';

use SpicyApi\CurlTransport;
use SpicyApi\HttpResponse;
use SpicyApi\SpicyApiError;
use SpicyApi\SpicyClient;
use SpicyApi\SpicyTimeoutError;
use SpicyApi\SpicyTransportError;
use SpicyApi\SpicyUploadError;
use SpicyApi\SpicyWebhook;
use SpicyApi\SpicyWebhookError;
use SpicyApi\StreamTransport;
use SpicyApi\TaskErrorCode;
use SpicyApi\TaskState;
use SpicyApi\Transport;
use SpicyApi\UploadContentType;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ok   $label\n"; }
    else { $failed++; echo "  FAIL $label" . ($detail === '' ? '' : " -- $detail") . "\n"; }
}

/**
 * Every tracked text file in the repository, resolved through git.
 *
 * git is asked rather than glob because it already knows what is published: it skips vendor/, the
 * build output and anything ignored, so the scan cannot be fooled into passing by a directory that
 * happens to be empty on this machine.
 *
 * @return list<string>
 */
function spicy_tracked_text_files(string $root): array
{
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $output = [];
    $status = 0;
    exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $output, $status);
    if ($status !== 0 || $output === []) {
        // Not a checkout, or git is unavailable. Returning an empty list here would make the scan
        // pass by reading nothing, so say so loudly instead.
        fwrite(STDERR, "spicy_tracked_text_files: git ls-files returned nothing\n");
        return $cached = [];
    }

    $binary = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mov', 'ico', 'zip', 'gz', 'bin'];
    $files = [];
    foreach ($output as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) { continue; }
        if (in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), $binary, true)) { continue; }
        $files[] = $path;
    }

    return $cached = $files;
}

/** Records every call and replays scripted responses. */
final class FakeTransport implements Transport
{
    public array $calls = [];
    /** @var list<HttpResponse|Throwable> */
    private array $queue;

    public function __construct(array $queue) { $this->queue = $queue; }

    public function send(string $method, string $url, array $headers, $body, float $timeoutSeconds): HttpResponse
    {
        $this->calls[] = [
            'method' => $method, 'url' => $url, 'headers' => $headers,
            'body' => is_resource($body) ? '<stream:' . stream_get_contents($body) . '>' : $body,
            'timeout' => $timeoutSeconds,
        ];
        if ($this->queue === []) { throw new RuntimeException("unexpected call $method $url"); }
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
    public function drained(): bool { return $this->queue === []; }
}

function envelope(mixed $data, int $code = 200, int $status = 200, array $headers = [], string $msg = 'success'): HttpResponse
{
    return new HttpResponse($status, $headers, json_encode(
        ['code' => $code, 'msg' => $msg, 'data' => $data, 'request_id' => 'req_test'],
        JSON_THROW_ON_ERROR,
    ));
}

function errorEnvelope(int $status, int $code, string $msg, array $headers = []): HttpResponse
{
    return new HttpResponse($status, $headers, json_encode(
        ['code' => $code, 'msg' => $msg, 'request_id' => 'req_err'],
        JSON_THROW_ON_ERROR,
    ));
}

putenv('SPICY_API_KEY=sk-spicy-' . str_repeat('a', 48));
$noSleep = static function (float $s): void {};

/* -- 1. Construction and authentication ------------------------------ */
echo "construction and authentication\n";
$t = new FakeTransport([envelope(['total' => 0, 'items' => []])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->listModels();
check('Authorization header is a bearer key', ($t->calls[0]['headers']['Authorization'] ?? '') === 'Bearer sk-spicy-' . str_repeat('a', 48));
check('Accept header set', ($t->calls[0]['headers']['Accept'] ?? '') === 'application/json');
check('no Content-Type on GET', !isset($t->calls[0]['headers']['Content-Type']));
check('base URL is /api/v1', $t->calls[0]['url'] === 'https://api.spicyapi.ai/api/v1/models');

putenv('SPICY_API_KEY');
unset($_SERVER['SPICY_API_KEY']);
try { new SpicyClient(transport: new FakeTransport([])); check('missing key throws', false); }
catch (InvalidArgumentException $e) { check('missing key throws', str_contains($e->getMessage(), 'SPICY_API_KEY')); }
$_SERVER['SPICY_API_KEY'] = 'sk-spicy-fromserver';
try { new SpicyClient(transport: new FakeTransport([])); check('falls back to $_SERVER (php-fpm)', true); }
catch (Throwable $e) { check('falls back to $_SERVER (php-fpm)', false, $e->getMessage()); }
unset($_SERVER['SPICY_API_KEY']);
putenv('SPICY_API_KEY=sk-spicy-' . str_repeat('a', 48));

/* -- 2. Catalogue ---------------------------------------------------- */
echo "catalogue\n";
$t = new FakeTransport([envelope(['model' => 'x/y/text-to-image'])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->getModel('family/1.0/text-to-image');
check('model slug is URL-encoded into one segment',
    $t->calls[0]['url'] === 'https://api.spicyapi.ai/api/v1/models/family%2F1.0%2Ftext-to-image',
    $t->calls[0]['url']);

$t = new FakeTransport([envelope(['total' => 1, 'items' => []])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->listModels(modality: 'video', task: 'image-to-video', includeSchema: true, includeExamples: false);
check('query params encoded',
    str_contains($t->calls[0]['url'], 'modality=video')
    && str_contains($t->calls[0]['url'], 'task=image-to-video')
    && str_contains($t->calls[0]['url'], 'includeSchema=1')
    && str_contains($t->calls[0]['url'], 'includeExamples=0'),
    $t->calls[0]['url']);

/* -- 3. Quotes ------------------------------------------------------- */
echo "quotes\n";
$quoteData = ['quoteId' => 'q_1', 'model' => 'm', 'estimatedCost' => '0.043200000',
    'maxCharge' => '0.043200000', 'currency' => 'USD', 'quantity' => '1', 'unit' => 'image',
    'expiresAt' => '2026-09-20T00:05:00Z'];
$t = new FakeTransport([envelope($quoteData)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$quote = $client->quote('m', ['prompt' => 'p']);
check('quote returns data unwrapped', $quote['quoteId'] === 'q_1' && $quote['estimatedCost'] === '0.043200000');
check('quote posts to /jobs/quote', $t->calls[0]['url'] === 'https://api.spicyapi.ai/api/v1/jobs/quote' && $t->calls[0]['method'] === 'POST');

/* -- 4. Task creation ------------------------------------------------ */
echo "task creation\n";
$created = ['taskId' => 'task_1', 'state' => 'queued', 'estimatedCost' => '0.0432', 'deadlineAt' => '2026-09-20T01:00:00Z'];
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$task = $client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k-1',
    quoteId: 'q_1', expectedCost: '0.0432', retentionSeconds: 3600, callBackUrl: 'https://example.com/hook');
check('HTTP 202 accepted as success', $task['taskId'] === 'task_1');
$sent = json_decode($t->calls[0]['body'], true);
check('body carries model/input/quoteId/expectedCost/callBackUrl',
    $sent['model'] === 'm' && $sent['input'] === ['prompt' => 'p']
    && $sent['quoteId'] === 'q_1' && $sent['expectedCost'] === '0.0432'
    && $sent['callBackUrl'] === 'https://example.com/hook');
check('Idempotency-Key header sent', ($t->calls[0]['headers']['Idempotency-Key'] ?? '') === 'k-1');
check('X-Spicy-Retention header sent', ($t->calls[0]['headers']['X-Spicy-Retention'] ?? '') === '3600');

// An empty input must encode as {} rather than [].
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: [], idempotencyKey: 'k-2');
check('empty input encodes as {} not []', str_contains($t->calls[0]['body'], '"input":{}'), $t->calls[0]['body']);

// An over-long idempotency key.
try {
    (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['a' => 1], idempotencyKey: str_repeat('x', 129));
    check('idempotencyKey >128 rejected locally', false);
} catch (InvalidArgumentException $e) { check('idempotencyKey >128 rejected locally', true); }

// An oversized body is stopped locally.
try {
    (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['prompt' => str_repeat('x', 2_200_000)], idempotencyKey: 'k');
    check('oversized request rejected locally', false);
} catch (SpicyApiError $e) {
    check('oversized request rejected locally', $e->status === 413 && str_contains($e->getMessage(), '2097152'));
}

// Invalid UTF-8 must throw rather than silently send an empty body.
try {
    (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['prompt' => "\xB1\x31"], idempotencyKey: 'k');
    check('invalid UTF-8 throws instead of sending an empty body', false);
} catch (JsonException $e) { check('invalid UTF-8 throws instead of sending an empty body', true); }

/* -- 5. Retry semantics ---------------------------------------------- */
echo "retry semantics\n";
// With an idempotency key, a 500 may be retried automatically.
$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope($created, status: 202)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$task = $client->createTask(model: 'm', input: ['a' => 1], idempotencyKey: 'k');
check('createTask with key retries a 500', $task['taskId'] === 'task_1' && count($t->calls) === 2);

// Without a key, no retry.
$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope($created, status: 202)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
try { $client->createTask(model: 'm', input: ['a' => 1]); check('createTask without key does NOT retry', false); }
catch (SpicyApiError $e) { check('createTask without key does NOT retry', count($t->calls) === 1, 'calls=' . count($t->calls)); }

// A 400 is never retried, not even for a call declared retryable.
$t = new FakeTransport([errorEnvelope(400, 40004, 'change resolution'), envelope([])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
try { $client->listModels(); check('40004 is not retried', false); }
catch (SpicyApiError $e) {
    check('40004 is not retried', count($t->calls) === 1);
    check('40004 guidance says change the parameter, do not retry',
        str_contains($e->guidance(), 'Change the parameter') && str_contains($e->guidance(), 'Do not retry'));
    check('business code surfaced', $e->businessCode === 40004 && $e->getCode() === 40004);
    check('request_id surfaced', $e->requestId === 'req_err');
    check('msg surfaced', $e->getMessage() === 'change resolution');
}

// A Retry-After beyond the ceiling is handed straight back.
$t = new FakeTransport([errorEnvelope(429, 429, 'slow down', ['retry-after' => '600']), envelope([])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep, maxRetryDelaySeconds: 30.0);
try { $client->listModels(); check('Retry-After beyond ceiling is handed back', false); }
catch (SpicyApiError $e) {
    check('Retry-After beyond ceiling is handed back', count($t->calls) === 1);
    check('retryAfterSeconds parsed', $e->retryAfterSeconds === 600.0);
}

// A Retry-After within the ceiling is honoured, then retried.
$t = new FakeTransport([errorEnvelope(429, 429, 'slow', ['retry-after' => '2']), envelope(['total' => 0, 'items' => []])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->listModels();
check('Retry-After within ceiling is honoured then retried', count($t->calls) === 2);

// Three business codes share the 503 status.
$codes = [503 => 'temporarily unavailable', 50301 => 'no usable deployment', 50302 => 'refunded'];
foreach ($codes as $code => $needle) {
    $t = new FakeTransport([errorEnvelope(503, $code, 'unavailable'), errorEnvelope(503, $code, 'unavailable'), errorEnvelope(503, $code, 'unavailable')]);
    $client = new SpicyClient(transport: $t, sleeper: $noSleep);
    try { $client->getTask('task_1'); check("503/$code raises", false); }
    catch (SpicyApiError $e) {
        check("503/$code keeps its business code", $e->status === 503 && $e->businessCode === $code);
        check("503/$code guidance is code-specific", stripos($e->guidance(), $needle) !== false, $e->guidance());
    }
}
$t = new FakeTransport([errorEnvelope(400, 40003, 'mismatch')]);
try { (new SpicyClient(transport: $t, sleeper: $noSleep))->commitUploadedFile('fil_1'); }
catch (SpicyApiError $e) {
    check('40003 guidance says re-upload, not re-commit',
        str_contains($e->guidance(), 'fresh upload ticket') && str_contains($e->guidance(), 'Retrying the commit'));
}

// Transport failure.
$t = new FakeTransport([new SpicyTransportError('net down'), envelope(['total' => 0, 'items' => []])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->listModels();
check('transport error retried on a retryable call', count($t->calls) === 2);
$t = new FakeTransport([new SpicyTransportError('net down')]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
try { $client->createTask(model: 'm', input: ['a' => 1]); check('transport error not retried without key', false); }
catch (SpicyTransportError $e) { check('transport error not retried without key', count($t->calls) === 1); }

// Malformed JSON.
$t = new FakeTransport([new HttpResponse(200, [], '<html>gateway</html>')]);
try { (new SpicyClient(transport: $t, sleeper: $noSleep))->getTask('t'); check('non-JSON body raises', false); }
catch (SpicyApiError $e) { check('non-JSON body raises', str_contains($e->getMessage(), 'not a JSON object')); }

// HTTP 200 with a business code other than 200.
$t = new FakeTransport([new HttpResponse(200, [], json_encode(['code' => 40301, 'msg' => 'nope', 'request_id' => 'r']))]);
try { (new SpicyClient(transport: $t, sleeper: $noSleep))->getTask('t'); check('HTTP 200 with code!=200 raises', false); }
catch (SpicyApiError $e) { check('HTTP 200 with code!=200 raises', $e->businessCode === 40301); }

/* -- 6. Polling ------------------------------------------------------ */
echo "polling\n";
$running = ['taskId' => 't', 'model' => 'm', 'state' => 'running', 'cost' => '0.04', 'settled' => false, 'createdAt' => 'x'];
$succeeded = ['taskId' => 't', 'model' => 'm', 'state' => 'succeeded', 'cost' => '0.04', 'settled' => true,
    'createdAt' => 'x', 'output' => ['assets' => [['key' => 'a', 'url' => 'https://cdn/x.png', 'mime' => 'image/png']]]];
$pending = $succeeded; $pending['output']['assets'][0] = ['key' => 'a', 'pending' => true];

$t = new FakeTransport([envelope($running), envelope($running), envelope($succeeded)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$seen = [];
$final = $client->waitForTerminal('t', onUpdate: function (array $task) use (&$seen): void { $seen[] = $task['state']; });
check('waitForTerminal polls to a terminal state', $final['state'] === 'succeeded' && count($t->calls) === 3);
check('onUpdate fires per poll', $seen === ['running', 'running', 'succeeded']);

$t = new FakeTransport([envelope($pending), envelope($succeeded)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$final = $client->waitForTerminal('t');
check('succeeded-with-pending-assets keeps polling', count($t->calls) === 2 && SpicyClient::readyAssets($final) !== []);

$t = new FakeTransport(array_fill(0, 200, envelope($running)));
$realSleep = static function (float $s): void { usleep(20000); };
$client = new SpicyClient(transport: $t, sleeper: $realSleep);
try { $client->waitForTerminal('t', timeoutSeconds: 0.05); check('wait timeout raises SpicyTimeoutError', false); }
catch (SpicyTimeoutError $e) {
    check('wait timeout raises SpicyTimeoutError', $e->taskId === 't');
    check('wait timeout message says remote state is unknown', str_contains($e->getMessage(), 'remote state is unknown'));
    check('SpicyTimeoutError is a SpicyApiError', $e instanceof SpicyApiError);
}

$t = new FakeTransport([envelope(['taskId' => 't', 'state' => 'teleported', 'model' => 'm', 'cost' => '0', 'settled' => false, 'createdAt' => 'x'])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
try { $client->waitForTerminal('t'); check('unknown state is not treated as terminal', false); }
catch (SpicyApiError $e) { check('unknown state is not treated as terminal', str_contains($e->getMessage(), 'unknown task state')); }

/* ── 7. retry / purge ──────────────────────────────────────────── */
echo "retry / purge\n";
$t = new FakeTransport([envelope(['taskId' => 't2', 'sourceTaskId' => 't1', 'state' => 'queued', 'estimatedCost' => '0.04', 'deadlineAt' => 'x'])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$r = $client->retryTask('t1', 'k');
check('retryTask returns sourceTaskId', $r['sourceTaskId'] === 't1');
check('retryTask body carries taskId', json_decode($t->calls[0]['body'], true) === ['taskId' => 't1']);

$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope(['taskId' => 't', 'contentState' => 'purged', 'billingRetained' => true, 'mediaDeletionPending' => true])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$p = $client->purgeTask('t');
check('purge retries without any Idempotency-Key', count($t->calls) === 2);
check('purge sends no Idempotency-Key header', !isset($t->calls[0]['headers']['Idempotency-Key']));
check('purge reports billingRetained', $p['billingRetained'] === true);

/* -- 8. The three upload steps --------------------------------------- */
echo "upload steps\n";
$tmp = tempnam(sys_get_temp_dir(), 'spicy') . '.png';
file_put_contents($tmp, str_repeat("\x89PNG", 10));
$bytes = filesize($tmp);
$ticket = ['fileId' => 'fil_abc', 'key' => 'spicy://f/fil_abc', 'uploadUrl' => 'https://storage.example/obj?sig=1',
    'method' => 'PUT', 'headers' => ['Content-Type' => 'image/png', 'x-amz-meta-owner' => 'acct_1'],
    'expiresAt' => 'x', 'maxBytes' => 10485760];
$commit = ['fileId' => 'fil_abc', 'status' => 'ready', 'bytes' => $bytes, 'contentType' => 'image/png',
    'sha256' => str_repeat('a', 64), 'uri' => 'spicy://f/fil_abc', 'expiresAt' => 'x'];
$t = new FakeTransport([envelope($ticket), new HttpResponse(200, [], ''), envelope($commit)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$file = $client->uploadFile($tmp);
check('uploadFile returns the spicy:// URI', $file['uri'] === 'spicy://f/fil_abc');
check('step 1 posts contentType + exact bytes',
    json_decode($t->calls[0]['body'], true) === ['contentType' => 'image/png', 'bytes' => $bytes]);
check('step 2 PUTs to the signed URL', $t->calls[1]['method'] === 'PUT' && $t->calls[1]['url'] === 'https://storage.example/obj?sig=1');
check('step 2 forwards every ticket header verbatim',
    $t->calls[1]['headers'] === ['Content-Type' => 'image/png', 'x-amz-meta-owner' => 'acct_1'],
    json_encode($t->calls[1]['headers']));
check('step 2 sends NO Authorization header', !isset($t->calls[1]['headers']['Authorization']));
check('step 2 streams the file', $t->calls[1]['body'] === '<stream:' . str_repeat("\x89PNG", 10) . '>');
check('step 2 uses the long upload timeout', $t->calls[1]['timeout'] === 600.0);
check('step 3 commits by fileId', $t->calls[2]['url'] === 'https://api.spicyapi.ai/api/v1/files/fil_abc/commit');
check('step 3 sends no body', $t->calls[2]['body'] === null);

// A failed PUT is not retried.
$t = new FakeTransport([envelope($ticket), new HttpResponse(403, [], '<Error>SignatureDoesNotMatch</Error>')]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
try { $client->uploadFile($tmp); check('failed PUT raises SpicyUploadError and is not retried', false); }
catch (SpicyUploadError $e) {
    check('failed PUT raises SpicyUploadError and is not retried', count($t->calls) === 2 && $e->status === 403);
    check('storage body is not leaked into the message', !str_contains($e->getMessage(), 'SignatureDoesNotMatch'));
}

// Over the ticket's maxBytes.
$smallTicket = $ticket; $smallTicket['maxBytes'] = 4;
$t = new FakeTransport([envelope($smallTicket)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
try { $client->uploadFile($tmp); check('oversize vs ticket maxBytes rejected before PUT', false); }
catch (SpicyUploadError $e) { check('oversize vs ticket maxBytes rejected before PUT', count($t->calls) === 1); }

// uploadBytes
$t = new FakeTransport([envelope($ticket), new HttpResponse(200, [], ''), envelope($commit)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->uploadBytes('abcd', UploadContentType::Png);
check('uploadBytes sends the in-memory body', $t->calls[1]['body'] === 'abcd');

// The local size gate.
try {
    (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createUploadUrl(UploadContentType::Png, 11 * 1024 * 1024);
    check('image over 10 MiB rejected locally', false);
} catch (SpicyUploadError $e) { check('image over 10 MiB rejected locally', $e->status === 413); }
check('video ceiling is 90 MiB', UploadContentType::Mp4->maxBytes() === 94371840);
check('contentType inferred from extension', UploadContentType::forPath('/a/b/c.MP4') === UploadContentType::Mp4);
try { UploadContentType::forPath('/a/b/c.mov'); check('unknown extension rejected', false); }
catch (InvalidArgumentException $e) { check('unknown extension rejected', str_contains($e->getMessage(), 'mp4')); }
unlink($tmp);

// Reference documents. Without these cases there is no way to upload one at all: createUploadUrl
// takes the enum, not a free string.
// forPath throws on an unknown extension; caught here so a missing mapping fails one check instead
// of ending the suite.
$inferred = static function (string $path): ?UploadContentType {
    try { return UploadContentType::forPath($path); } catch (InvalidArgumentException) { return null; }
};
check('PDF inferred from extension', $inferred('/a/brief.PDF') === UploadContentType::Pdf);
foreach (['a.doc' => 'application/msword', 'a.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'a.xls' => 'application/vnd.ms-excel', 'a.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'a.ppt' => 'application/vnd.ms-powerpoint', 'a.pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
          'a.key' => 'application/vnd.apple.keynote', 'a.pages' => 'application/vnd.apple.pages',
          'a.numbers' => 'application/vnd.apple.numbers', 'a.txt' => 'text/plain',
          'a.md' => 'text/markdown'] as $documentPath => $expectedType) {
    check("$documentPath inferred as $expectedType", $inferred($documentPath)?->value === $expectedType);
}
check('documents are documents, media is not',
    UploadContentType::Pdf->isDocument() && UploadContentType::Markdown->isDocument()
    && !UploadContentType::Png->isDocument() && !UploadContentType::Mp4->isDocument()
    && !UploadContentType::Wav->isDocument());
check('a document gets the 90 MiB ceiling, not the image one', UploadContentType::Pdf->maxBytes() === 94371840);
$docTicket = ['fileId' => 'fil_doc', 'key' => 'spicy://f/fil_doc', 'uploadUrl' => 'https://storage.example/doc?sig=1',
    'method' => 'PUT', 'headers' => ['Content-Type' => 'application/pdf'], 'expiresAt' => 'x', 'maxBytes' => 94371840];
$t = new FakeTransport([envelope($docTicket)]);
(new SpicyClient(transport: $t, sleeper: $noSleep))->createUploadUrl(UploadContentType::Pdf, 20 * 1024 * 1024);
check('a 20 MiB PDF is not refused locally and is declared as application/pdf',
    json_decode($t->calls[0]['body'], true) === ['contentType' => 'application/pdf', 'bytes' => 20 * 1024 * 1024]);

// The enum must equal the pinned contract's list. scripts/check_contract.php compares against the
// published contract over the network; this runs offline, so a forgotten case fails every local run.
$pinned = (string) file_get_contents(__DIR__ . '/../contracts/openapi.yaml');
$anchor = strpos($pinned, '    UploadURLRequest:');
$pinnedTypes = [];
if ($anchor !== false && preg_match('/contentType:\s*\{[^}]*enum:\s*\[([^\]]+)\]/', substr($pinned, $anchor, 2000), $m) === 1) {
    $pinnedTypes = array_map('trim', explode(',', $m[1]));
}
sort($pinnedTypes);
$enumTypes = array_map(static fn (UploadContentType $case): string => $case->value, UploadContentType::cases());
sort($enumTypes);
check('the pinned contract lists upload types at all', count($pinnedTypes) >= 8, (string) count($pinnedTypes));
check('UploadContentType equals the pinned contract enum', $enumTypes === $pinnedTypes,
    implode(',', array_diff($pinnedTypes, $enumTypes)) . ' | ' . implode(',', array_diff($enumTypes, $pinnedTypes)));

/* ── 9. download-url ───────────────────────────────────────────── */
echo "download-url\n";
$t = new FakeTransport([envelope(['key' => 'a', 'url' => 'https://cdn/x?sig=2', 'expiresAt' => 'x'])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$d = $client->createDownloadUrl('t', 'a');
check('createDownloadUrl posts taskId + key', json_decode($t->calls[0]['body'], true) === ['taskId' => 't', 'key' => 'a']);
check('createDownloadUrl returns a url', $d['url'] === 'https://cdn/x?sig=2');
$t = new FakeTransport([envelope(['key' => 'a', 'url' => 'u', 'expiresAt' => 'x'])]);
(new SpicyClient(transport: $t, sleeper: $noSleep))->createDownloadUrl('t');
check('key omitted when not given', json_decode($t->calls[0]['body'], true) === ['taskId' => 't']);

/* -- 10. An output is not always a file ------------------------------ */
echo "outputs\n";
$textOnly = ['taskId' => 't', 'state' => 'succeeded', 'output' => ['text' => 'transcribed lyrics']];
check('outputText reads text', SpicyClient::outputText($textOnly) === 'transcribed lyrics');
check('outputAssets is [] when there is no assets key', SpicyClient::outputAssets($textOnly) === []);
check('readyAssets is [] when there is no assets key', SpicyClient::readyAssets($textOnly) === []);
check('outputText null when absent', SpicyClient::outputText($succeeded) === null);
// An empty string means "the field is there and it is empty", which is not the same as "the field
// is absent". The other clients all return the empty string as-is; folding the two into null would
// make a task that handed in a blank look like one that produced a file.
check('outputText keeps an empty string as an empty string',
    SpicyClient::outputText(['output' => ['text' => '']]) === '');
check('outputText is null only when the field is absent or not a string',
    SpicyClient::outputText(['output' => ['text' => 123]]) === null
    && SpicyClient::outputText(['output' => []]) === null);
check('no output key at all is safe', SpicyClient::outputAssets(['taskId' => 't']) === [] && SpicyClient::outputText(['taskId' => 't']) === null);
$mixed = ['state' => 'succeeded', 'output' => ['assets' => [
    ['key' => 'a', 'url' => 'https://cdn/1'], ['key' => 'b', 'pending' => true], ['key' => 'c', 'unavailable' => true],
]]];
check('readyAssets filters pending and unavailable', count(SpicyClient::readyAssets($mixed)) === 1);
check('outputAssets keeps all three', count(SpicyClient::outputAssets($mixed)) === 3);

/* -- 11. Enums ------------------------------------------------------- */
echo "enums\n";
check('terminal states', TaskState::Succeeded->isTerminal() && TaskState::Failed->isTerminal()
    && TaskState::Canceled->isTerminal() && TaskState::Expired->isTerminal());
check('active states', !TaskState::Queued->isTerminal() && !TaskState::Running->isTerminal());
check('unknown errorCode folds into upstream_failed',
    TaskErrorCode::fromTask(['errorCode' => 'brand_new_code']) === TaskErrorCode::UpstreamFailed);
check('known errorCode maps', TaskErrorCode::fromTask(['errorCode' => 'content_rejected']) === TaskErrorCode::ContentRejected);
check('absent errorCode is null', TaskErrorCode::fromTask(['state' => 'succeeded']) === null);
check('content_rejected is not worth retrying', !TaskErrorCode::ContentRejected->isWorthRetrying());
check('upstream_unavailable is worth retrying', TaskErrorCode::UpstreamUnavailable->isWorthRetrying());

/* -- 12. USD string comparison --------------------------------------- */
echo "USD\n";
check('compareUsd equal', SpicyClient::compareUsd('0.0432', '0.04320') === 0);
check('compareUsd nine decimals', SpicyClient::compareUsd('0.000000001', '0.000000002') === -1);
check('compareUsd magnitude', SpicyClient::compareUsd('10.0', '9.99') === 1);
check('compareUsd negative', SpicyClient::compareUsd('-1.5', '0.5') === -1);
check('compareUsd zero forms', SpicyClient::compareUsd('0', '0.000') === 0);
check('compareUsd survives float-lossy values', SpicyClient::compareUsd('0.1', '0.3') === -1);

/* ── 13. Webhook ───────────────────────────────────────────────── */
echo "Webhook\n";
$secret = 'whsec_test_key';
$now = 1_758_000_000;

// v2
$v2Body = json_encode(['code' => 200, 'msg' => 'success', 'request_id' => 'del_1',
    'data' => ['taskId' => 'task_9', 'model' => 'm', 'state' => 'succeeded', 'cost' => '0.04', 'settled' => true, 'createdAt' => 'x']]);
$sig2 = SpicyWebhook::computeSignature('task_9', (string) $now, $v2Body, $secret);
$v = SpicyWebhook::verify($v2Body, (string) $now, $sig2, 2, $secret, now: $now);
check('v2 verifies', $v->taskId === 'task_9' && $v->payloadVersion === 2);
check('v2 delivery id is request_id', $v->deliveryId === 'del_1');
check('v2 state()', $v->state() === TaskState::Succeeded);
check('v2 taskRecord()', ($v->taskRecord()['model'] ?? null) === 'm');

// v1
$v1Body = json_encode(['task_id' => 'task_9', 'model' => 'm', 'state' => 'failed',
    'error_code' => 'content_rejected', 'cost' => '0', 'created_at' => 'x']);
$sig1 = SpicyWebhook::computeSignature('task_9', (string) $now, $v1Body, $secret);
$v1 = SpicyWebhook::verify($v1Body, (string) $now, $sig1, 1, $secret, now: $now);
check('v1 verifies from top-level task_id', $v1->taskId === 'task_9' && $v1->payloadVersion === 1);
check('v1 state()', $v1->state() === TaskState::Failed);
check('v1 taskRecord() is null', $v1->taskRecord() === null);
check('v1 has no delivery id', $v1->deliveryId === null);

// Reading the wrong version means no taskId.
try { SpicyWebhook::verify($v1Body, (string) $now, $sig1, 2, $secret, now: $now); check('v1 body read as v2 is rejected', false); }
catch (SpicyWebhookError $e) { check('v1 body read as v2 is rejected', $e->reason === 'invalid_payload'); }

// A tampered body.
$tampered = str_replace('succeeded', 'failed___', $v2Body);
try { SpicyWebhook::verify($tampered, (string) $now, $sig2, 2, $secret, now: $now); check('tampered body rejected', false); }
catch (SpicyWebhookError $e) { check('tampered body rejected', $e->reason === 'invalid_signature'); }

// A re-serialised body - the most common PHP mistake. In a real delivery the body is the bytes the
// server's encoder produced; re-encoding in PHP changes the escaping (here / becomes \/) and the
// digest stops matching immediately.
$wireBody = '{"code":200,"msg":"success","request_id":"del_2","data":{"taskId":"task_9",'
    . '"state":"succeeded","output":{"assets":[{"url":"https://cdn.example.com/a.png?x=1&y=2"}]}}}';
$wireSig = SpicyWebhook::computeSignature('task_9', (string) $now, $wireBody, $secret);
check('a wire-shaped body verifies as delivered',
    SpicyWebhook::verify($wireBody, (string) $now, $wireSig, 2, $secret, now: $now)->taskId === 'task_9');
$reencoded = json_encode(json_decode($wireBody, true));
check('re-encoding really does change the bytes', $reencoded !== $wireBody);
try { SpicyWebhook::verify($reencoded, (string) $now, $wireSig, 2, $secret, now: $now); check('re-encoded body rejected', false); }
catch (SpicyWebhookError $e) { check('re-encoded body rejected', $e->reason === 'invalid_signature'); }

// The wrong secret.
try { SpicyWebhook::verify($v2Body, (string) $now, $sig2, 2, 'other', now: $now); check('wrong secret rejected', false); }
catch (SpicyWebhookError $e) { check('wrong secret rejected', $e->reason === 'invalid_signature'); }

// A stale timestamp.
$old = $now - 4000;
$sigOld = SpicyWebhook::computeSignature('task_9', (string) $old, $v2Body, $secret);
try { SpicyWebhook::verify($v2Body, (string) $old, $sigOld, 2, $secret, now: $now); check('stale timestamp rejected', false); }
catch (SpicyWebhookError $e) { check('stale timestamp rejected', $e->reason === 'stale_timestamp'); }
$v = SpicyWebhook::verify($v2Body, (string) $old, $sigOld, 2, $secret, toleranceSeconds: 5000, now: $now);
check('a wider tolerance accepts it', $v->taskId === 'task_9');

// A future timestamp is outside the window too.
$future = $now + 4000;
$sigF = SpicyWebhook::computeSignature('task_9', (string) $future, $v2Body, $secret);
try { SpicyWebhook::verify($v2Body, (string) $future, $sigF, 2, $secret, now: $now); check('future timestamp rejected', false); }
catch (SpicyWebhookError $e) { check('future timestamp rejected', $e->reason === 'stale_timestamp'); }

// Malformed input.
foreach ([['not-a-number', 'invalid_timestamp'], ['', 'invalid_timestamp']] as [$ts, $reason]) {
    try { SpicyWebhook::verify($v2Body, $ts, $sig2, 2, $secret, now: $now); check("timestamp '$ts' rejected", false); }
    catch (SpicyWebhookError $e) { check("timestamp '$ts' rejected", $e->reason === $reason, $e->reason); }
}
try { SpicyWebhook::verify('not json', (string) $now, $sig2, 2, $secret, now: $now); check('non-JSON body rejected', false); }
catch (SpicyWebhookError $e) { check('non-JSON body rejected', $e->reason === 'invalid_json'); }
try { SpicyWebhook::verify('[1,2,3]', (string) $now, $sig2, 2, $secret, now: $now); check('JSON array body rejected', false); }
catch (SpicyWebhookError $e) { check('JSON array body rejected', $e->reason === 'invalid_json'); }
try { SpicyWebhook::verify($v2Body, (string) $now, $sig2, 2, '  ', now: $now); check('blank secret rejected', false); }
catch (SpicyWebhookError $e) { check('blank secret rejected', $e->reason === 'invalid_secret'); }
try { SpicyWebhook::verify(str_repeat('x', 2_000_000), (string) $now, $sig2, 2, $secret, now: $now); check('oversized body rejected before HMAC', false); }
catch (SpicyWebhookError $e) { check('oversized body rejected before HMAC', $e->reason === 'body_too_large'); }
try { SpicyWebhook::verify($v2Body, (string) $now, $sig2, 7, $secret, now: $now); check('unknown payload version rejected', false); }
catch (SpicyWebhookError $e) { check('unknown payload version rejected', $e->reason === 'invalid_payload'); }

// Surrounding whitespace in the headers is tolerated.
$v = SpicyWebhook::verify($v2Body, ' ' . $now . ' ', "  $sig2  ", 2, $secret, now: $now);
check('surrounding whitespace in headers tolerated', $v->taskId === 'task_9');

// verifyRequest reads from $_SERVER.
$server = [
    'HTTP_X_WEBHOOK_SIGNATURE' => $sig2,
    'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $now,
    'HTTP_X_WEBHOOK_PAYLOAD_VERSION' => '2',
];
$v = SpicyWebhook::verifyRequest($secret, $server, $v2Body, toleranceSeconds: 10_000_000_000);
check('verifyRequest reads HTTP_X_WEBHOOK_* from $_SERVER', $v->taskId === 'task_9');
unset($server['HTTP_X_WEBHOOK_PAYLOAD_VERSION']);
$v = SpicyWebhook::verifyRequest($secret, $server, $v2Body, toleranceSeconds: 10_000_000_000);
check('missing version header defaults to v2', $v->payloadVersion === 2);
unset($server['HTTP_X_WEBHOOK_SIGNATURE']);
try { SpicyWebhook::verifyRequest($secret, $server, $v2Body); check('missing signature header rejected', false); }
catch (SpicyWebhookError $e) { check('missing signature header rejected', $e->reason === 'missing_header'); }

// The signature's shape: Base64 over HMAC-SHA256.
$expected = base64_encode(hash_hmac('sha256', 'task_9.' . $now . '.' . hash('sha256', $v2Body), $secret, true));
check('signature is base64(hmac_sha256(taskId.timestamp.hex_sha256(body)))', $sig2 === $expected);
check('signature decodes to 32 bytes', strlen((string) base64_decode($sig2, true)) === 32);

/* -- 13.5 Account, usage and task history ---------------------------- */
echo "account and history\n";
$balanceData = ['available' => '12.340000000', 'held' => '0.043200000', 'total' => '12.383200000'];
$t = new FakeTransport([envelope($balanceData)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$balance = $client->getBalance();
check('getBalance reads GET /chat/credit',
    $t->calls[0]['method'] === 'GET' && $t->calls[0]['url'] === 'https://api.spicyapi.ai/api/v1/chat/credit',
    $t->calls[0]['url']);
check('getBalance unwraps the envelope data', $balance['available'] === '12.340000000');
check('getBalance sends no body', $t->calls[0]['body'] === null);

$usageData = ['from' => '2026-09-01', 'to' => '2026-09-08', 'currency' => 'USD',
    'totalCalls' => 12, 'totalSpend' => '1.234000000', 'days' => [], 'models' => []];
$t = new FakeTransport([envelope($usageData)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$usage = $client->getUsage('2026-09-01', '2026-09-08');
check('getUsage encodes from/to',
    str_contains($t->calls[0]['url'], '/usage?') && str_contains($t->calls[0]['url'], 'from=2026-09-01')
    && str_contains($t->calls[0]['url'], 'to=2026-09-08'), $t->calls[0]['url']);
check('getUsage unwraps the envelope data', $usage['totalSpend'] === '1.234000000');

// With no parameters at all, not even a question mark goes out: the contract states plainly that
// empty parameters are rejected.
$t = new FakeTransport([envelope($usageData)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->getUsage();
check('getUsage with no bounds sends no query string at all',
    $t->calls[0]['url'] === 'https://api.spicyapi.ai/api/v1/usage', $t->calls[0]['url']);

// These are calendar dates, not instants. A timestamp is stopped on the spot rather than becoming
// a quietly misaligned window.
try { (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))->getUsage('2026-09-01T00:00:00Z'); 
    check('getUsage rejects a timestamp where a date belongs', false); }
catch (InvalidArgumentException $e) {
    check('getUsage rejects a timestamp where a date belongs', str_contains($e->getMessage(), 'YYYY-MM-DD'), $e->getMessage());
}

$page1 = ['items' => [['taskId' => 't1'], ['taskId' => 't2']], 'hasMore' => true, 'nextCursor' => 'cur_2'];
$page2 = ['items' => [['taskId' => 't3']], 'hasMore' => false];
$t = new FakeTransport([envelope($page1)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$list = $client->listTasks(from: '2026-09-01', to: '2026-09-08', state: 'succeeded', model: 'demo/1.0/text-to-image', limit: 50, cursor: 'cur_1');
check('listTasks unwraps items/hasMore/nextCursor',
    count($list['items']) === 2 && $list['hasMore'] === true && $list['nextCursor'] === 'cur_2');
foreach (['from=2026-09-01', 'to=2026-09-08', 'state=succeeded', 'model=demo', 'limit=50', 'cursor=cur_1'] as $fragment) {
    check("listTasks encodes $fragment", str_contains($t->calls[0]['url'], $fragment), $t->calls[0]['url']);
}

$t = new FakeTransport([envelope($page2)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->listTasks();
check('listTasks with no filters sends no query string at all',
    $t->calls[0]['url'] === 'https://api.spicyapi.ai/api/v1/jobs', $t->calls[0]['url']);

// limit rejects non-positive values only. The upper bound is deliberately not enforced locally: the
// day the platform relaxes it to 200, a hard local ceiling would reject a value that already works,
// and the user would see nothing but the SDK refusing.
foreach ([0, -1] as $badLimit) {
    try { (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))->listTasks(limit: $badLimit);
        check("listTasks rejects limit=$badLimit", false); }
    catch (InvalidArgumentException $e) { check("listTasks rejects limit=$badLimit", true); }
}
$t = new FakeTransport([envelope($page2)]);
(new SpicyClient(transport: $t, sleeper: $noSleep))->listTasks(limit: 500);
check('listTasks does not impose a local ceiling on limit',
    str_contains($t->calls[0]['url'], 'limit=500'), $t->calls[0]['url']);

// An empty string means "the computed filter came out empty", not "unset". Sending it earns a 400;
// dropping it silently is worse, because the caller gets the entire unfiltered list with no way to
// notice.
try { (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))->listTasks(state: '');
    check('listTasks refuses an empty filter instead of dropping it', false); }
catch (InvalidArgumentException $e) {
    check('listTasks refuses an empty filter instead of dropping it', str_contains($e->getMessage(), 'state'), $e->getMessage());
}

// Paging: every page repeats all the filters and moves only the cursor.
$t = new FakeTransport([envelope($page1), envelope($page2)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$walked = iterator_to_array($client->iterTasks(from: '2026-09-01', state: 'succeeded'), false);
check('iterTasks yields every item across pages',
    array_column($walked, 'taskId') === ['t1', 't2', 't3'], json_encode(array_column($walked, 'taskId')));
check('iterTasks repeats every filter on the second page',
    str_contains($t->calls[1]['url'], 'from=2026-09-01') && str_contains($t->calls[1]['url'], 'state=succeeded'),
    $t->calls[1]['url']);
check('iterTasks carries the server cursor through unchanged',
    str_contains($t->calls[1]['url'], 'cursor=cur_2') && !str_contains($t->calls[0]['url'], 'cursor='),
    $t->calls[1]['url']);
check('iterTasks stops when hasMore is false', count($t->calls) === 2);

// Trusting hasMore alone turns a hasMore:true with no cursor into endless paging - billing for API
// calls forever, while from the outside it just looks like a hang. Both stop conditions are checked.
foreach ([['hasMore' => true], ['hasMore' => true, 'nextCursor' => '']] as $index => $brokenTail) {
    $t = new FakeTransport(array_fill(0, 20, envelope(['items' => [['taskId' => 'x']]] + $brokenTail)));
    $client = new SpicyClient(transport: $t, sleeper: $noSleep);
    $walked = iterator_to_array($client->iterTasks(), false);
    check('iterTasks stops on hasMore=true with no usable cursor #' . ($index + 1),
        count($t->calls) === 1 && count($walked) === 1, 'calls=' . count($t->calls));
}

// An HTTP 200 with a business code other than 200 must never be read as success. It matters most on
// these three endpoints: treated as success, the caller receives an empty result - an empty balance,
// zero usage, an empty task list - and every one of those looks like a plausible answer.
$blankCases = [
    'getBalance' => static fn (SpicyClient $c): array => $c->getBalance(),
    'getUsage' => static fn (SpicyClient $c): array => $c->getUsage(),
    'listTasks' => static fn (SpicyClient $c): array => $c->listTasks(),
];
foreach ($blankCases as $label => $call) {
    $t = new FakeTransport([new HttpResponse(200, [], json_encode(
        ['code' => 40201, 'msg' => 'insufficient balance', 'request_id' => 'r9'], JSON_THROW_ON_ERROR))]);
    $client = new SpicyClient(transport: $t, sleeper: $noSleep);
    try { $call($client); check("$label does not treat HTTP 200 with code 40201 as success", false); }
    catch (SpicyApiError $e) {
        check("$label does not treat HTTP 200 with code 40201 as success",
            $e->businessCode === 40201 && $e->requestId === 'r9');
    }
}
// The other half of the same concern: an envelope without even a data key must not read as an empty
// result either.
foreach ($blankCases as $label => $call) {
    $t = new FakeTransport([new HttpResponse(200, [], json_encode(
        ['code' => 200, 'msg' => 'success', 'request_id' => 'r8'], JSON_THROW_ON_ERROR))]);
    $client = new SpicyClient(transport: $t, sleeper: $noSleep);
    try { $call($client); check("$label does not treat a missing data field as an empty result", false); }
    catch (SpicyApiError $e) {
        check("$label does not treat a missing data field as an empty result",
            str_contains($e->getMessage(), 'omitted data'), $e->getMessage());
    }
}
// All three are read-only and safe to resend, so they should be retried automatically.
$t = new FakeTransport([errorEnvelope(500, 500, 'boom'), envelope($balanceData)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->getBalance();
check('a read-only account call retries a 500', count($t->calls) === 2);

/* -- 13.6 createTask's wait, and isTerminal -------------------------- */
echo "wait and isTerminal\n";
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k');
check('no waitSeconds means no query string on createTask',
    $t->calls[0]['url'] === 'https://api.spicyapi.ai/api/v1/jobs/createTask', $t->calls[0]['url']);

$t = new FakeTransport([envelope($succeeded)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$done = $client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k', waitSeconds: 30);
check('waitSeconds goes on the query string', str_contains($t->calls[0]['url'], '?wait=30'), $t->calls[0]['url']);
// The local request timeout has to accommodate the server-side wait. The collision shows up as
// "wait appears to do nothing", when the real cause is the local side cutting the call off while the
// server is still waiting.
check('the local timeout leaves room for the server-side wait',
    $t->calls[0]['timeout'] >= 30.0 + 9.0, (string) $t->calls[0]['timeout']);
check('a terminal record comes back whole', $done['state'] === 'succeeded');

// The server clamps anything over 60 to 60 and ignores non-positive integers. Nothing is clamped
// locally: copying the ceiling here would bury a constant that will expire. Only negatives are
// rejected, being a caller error rather than a platform limit.
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k', waitSeconds: 90);
check('waitSeconds above the server ceiling is sent unclamped',
    str_contains($t->calls[0]['url'], 'wait=90'), $t->calls[0]['url']);
check('... and the local timeout still covers it',
    $t->calls[0]['timeout'] >= 90.0 + 9.0, (string) $t->calls[0]['timeout']);
$t = new FakeTransport([envelope($created, status: 202)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->createTask(model: 'm', input: ['prompt' => 'p'], idempotencyKey: 'k', waitSeconds: 0);
check('waitSeconds 0 is passed through for the server to ignore',
    str_contains($t->calls[0]['url'], 'wait=0'), $t->calls[0]['url']);
try {
    (new SpicyClient(transport: new FakeTransport([]), sleeper: $noSleep))
        ->createTask(model: 'm', input: ['a' => 1], idempotencyKey: 'k', waitSeconds: -1);
    check('a negative waitSeconds is refused', false);
} catch (InvalidArgumentException $e) { check('a negative waitSeconds is refused', true); }

// When wait runs out, what comes back is a 202 acceptance response - the same shape as a terminal
// record, differing only in state. The assumption "I passed wait, so it must be finished" silently
// carries a still-running task forward.
check('an accepted response is not terminal', SpicyClient::isTerminal($created) === false);
check('a finished record is terminal', SpicyClient::isTerminal($succeeded) === true);
foreach (['failed', 'canceled', 'expired'] as $terminalState) {
    check("isTerminal accepts $terminalState", SpicyClient::isTerminal(['state' => $terminalState]) === true);
}
foreach (['queued', 'running'] as $activeState) {
    check("isTerminal rejects $activeState", SpicyClient::isTerminal(['state' => $activeState]) === false);
}
// An unrecognised state is not terminal: treating it as such would silently discard a task that is
// still running and still being billed.
check('isTerminal treats an unknown state as not terminal',
    SpicyClient::isTerminal(['state' => 'teleported']) === false);
check('isTerminal is safe on a record with no state at all',
    SpicyClient::isTerminal(['taskId' => 't']) === false && SpicyClient::isTerminal([]) === false);

/* -- 14. User-Agent and idempotency-key prefixes --------------------- */
echo "User-Agent\n";
$t = new FakeTransport([envelope(['total' => 0, 'items' => []])]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->listModels();
$ua = $t->calls[0]['headers']['User-Agent'] ?? '';
check('User-Agent does not call itself an example', stripos($ua, 'example') === false, $ua);
check('User-Agent is the product token, optionally with a version',
    preg_match('#^spicyapi-php(/[A-Za-z0-9._+-]+)?$#', $ua) === 1, $ua);
// There can only be one source for the version: the one Composer records from the git tag. Without
// that metadata, no version is sent at all, rather than a hard-coded number bound to disagree with
// the tag eventually.
check('with no Composer metadata the UA carries no version at all',
    class_exists(\Composer\InstalledVersions::class) || $ua === SpicyClient::USER_AGENT_PRODUCT, $ua);
$composerJson = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
check('composer.json still declares no version (the tag is the single source)',
    is_array($composerJson) && !array_key_exists('version', $composerJson));
check('the client source hard-codes no release number for the UA',
    preg_match('/USER_AGENT[A-Z_]*\s*=\s*\'[^\']*\d+\.\d+/', (string) file_get_contents(__DIR__ . '/../src/SpicyClient.php')) !== 1);

$generatedKey = SpicyClient::idempotencyKey();
check('generated idempotency keys are not labelled example', stripos($generatedKey, 'example') === false, $generatedKey);
check('generated idempotency keys carry a product prefix', str_starts_with($generatedKey, 'spicy-'), $generatedKey);
check('idempotencyKey still honours an explicit prefix',
    str_starts_with(SpicyClient::idempotencyKey('order-42'), 'order-42-'));

/* -- 15. Business-code guidance matches the contract ------------------ */
echo "business-code guidance\n";
$advice = static fn (int $code): string => (new SpicyApiError('m', status: 409, businessCode: $code))->guidance();
// Contract (ErrorEnvelope.code): 50302 must be resent under a NEW Idempotency-Key; the original key
// only replays the failure already on record.
check('50302 tells you to send a new idempotency key',
    stripos($advice(50302), 'new Idempotency-Key') !== false, $advice(50302));
check('50302 no longer claims the identical request is safe',
    stripos($advice(50302), 'identical request is safe') === false, $advice(50302));
// In the contract 409 and 40901 are different things, and the advice for them is opposite.
check('409 and 40901 do not share one piece of advice', $advice(409) !== $advice(40901));
check('409 is the idempotency conflict', stripos($advice(409), 'idempotency conflict') !== false, $advice(409));
check('40901 is the price/quote conflict', stripos($advice(40901), 'price changed') !== false, $advice(40901));
check('40901 says to keep the original key, not to take a new one',
    stripos($advice(40901), 'keeping the original Idempotency-Key') !== false, $advice(40901));
// Contract: 40310 is an unverified email address. It is a 403 like a key-scope or IP refusal, and
// the fix is nothing like theirs - no key or network change helps, verifying the address does.
check('40310 points at email verification', stripos($advice(40310), 'verif') !== false
    && stripos($advice(40310), 'email') !== false, $advice(40310));
check('40310 is not given the generic advice', $advice(40310) !== $advice(40399));

/* -- 16. A poll's timeout is clamped to the remaining budget ---------- */
echo "polling timeout semantics\n";
// One polling attempt timing out is not this wait failing. While budget remains, keep polling.
$t = new FakeTransport([new SpicyTimeoutError('poll timed out'), envelope($succeeded)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep, maxRetries: 0);
// Wrapped in a try because the way this guard fails is by letting an exception escape. Uncaught,
// the whole suite would fatal here and the dozens of assertions after it would never run - a guard
// that can kill the suite mid-way goes red in a shape unlike any other failure, and whoever
// investigates starts by suspecting the test itself.
try {
    $final = $client->waitForTerminal('t', timeoutSeconds: 30.0);
    check('a single poll timing out does not end the wait',
        $final['state'] === 'succeeded' && count($t->calls) === 2, 'calls=' . count($t->calls));
} catch (Throwable $e) {
    check('a single poll timing out does not end the wait', false, get_class($e) . ': ' . $e->getMessage());
}

// When the budget genuinely runs out, the exception thrown must carry the taskId - it is the only
// thread back for reconciliation afterwards.
$t = new FakeTransport(array_fill(0, 5, new SpicyTimeoutError('poll timed out')));
$realSleeper = static function (float $s): void { usleep((int) round($s * 1_000_000)); };
$client = new SpicyClient(transport: $t, sleeper: $realSleeper, maxRetries: 0);
try {
    $client->waitForTerminal('task_77', timeoutSeconds: 1.1);
    check('a wait killed by poll timeouts still names the task', false);
} catch (SpicyTimeoutError $e) {
    check('a wait killed by poll timeouts still names the task', $e->taskId === 'task_77', var_export($e->taskId, true));
    check('... and still says the remote state is unknown', str_contains($e->getMessage(), 'remote state is unknown'));
}

// A single poll never gets a timeout longer than the whole wait.
$t = new FakeTransport([envelope($succeeded)]);
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
$client->waitForTerminal('t', timeoutSeconds: 5.0);
check('a poll is never given more time than the wait has left',
    $t->calls[0]['timeout'] <= 5.0, (string) $t->calls[0]['timeout']);

// A call's budget covers its own retries too, or the caller's 5 seconds becomes 3 x 30.
$t = new FakeTransport(array_fill(0, 5, new SpicyTimeoutError('slow')));
$client = new SpicyClient(transport: $t, sleeper: $noSleep);
try { $client->getTask('t', 4.0); check('getTask enforces its own timeout', false); }
catch (SpicyTimeoutError $e) {
    check('getTask enforces its own timeout', true);
    check('every retry of a call is clamped to that call\'s budget',
        max(array_column($t->calls, 'timeout')) <= 4.0,
        implode(',', array_column($t->calls, 'timeout')));
}

/* -- 17. The transport tells a timeout from a refused connection ------ */
echo "transport timeouts\n";
// Bind a loopback socket without serving on it: the kernel completes the handshake while user space
// never accepts, so the request really did go out and the response never comes. That is exactly the
// "timed out locally, outcome unknown" class, and its remedy is the opposite of "connection refused,
// the request never went out" - only the latter is safe to resend without an idempotency key.
putenv('NO_PROXY=127.0.0.1,localhost');
putenv('no_proxy=127.0.0.1,localhost');
$blackhole = @stream_socket_server('tcp://127.0.0.1:0', $bindErrno, $bindError);
if ($blackhole === false) {
    check('a loopback socket can be bound for the timeout test', false, (string) $bindError);
} else {
    $hangUrl = 'http://' . stream_socket_get_name($blackhole, false) . '/hang';
    foreach (['CurlTransport' => new CurlTransport(), 'StreamTransport' => new StreamTransport()] as $name => $transport) {
        try {
            $transport->send('GET', $hangUrl, [], null, 0.4);
            check("$name reports a hung response as a timeout", false, 'nothing was thrown');
        } catch (SpicyTimeoutError $e) {
            check("$name reports a hung response as a timeout", true);
        } catch (SpicyTransportError $e) {
            check("$name reports a hung response as a timeout", false, $e->getMessage());
        }
    }
    fclose($blackhole);
    foreach (['CurlTransport' => new CurlTransport(), 'StreamTransport' => new StreamTransport()] as $name => $transport) {
        try {
            $transport->send('GET', $hangUrl, [], null, 5.0);
            check("$name does not call a refused connection a timeout", false, 'nothing was thrown');
        } catch (SpicyTimeoutError $e) {
            check("$name does not call a refused connection a timeout", false, 'it was reported as a timeout');
        } catch (SpicyTransportError $e) {
            check("$name does not call a refused connection a timeout", true);
        }
    }
}

/* -- 18. The README lines up with the code --------------------------- */
echo "README\n";
$readme = (string) file_get_contents(__DIR__ . '/../README.md');
preg_match_all('/^```php\R(.*?)^```/ms', $readme, $readmeBlocks);
check('the README has php examples to check', count($readmeBlocks[1]) > 0);
$readmePhp = implode("\n", $readmeBlocks[1]);
// Every client method returns an associative array. Writing ->prop is, in PHP 8, merely a warning
// plus a null, and that null only blows up at the next call site - so the error points there rather
// than at this line.
check('the README reads client results as arrays, not objects',
    preg_match('/\$(quote|task|final|uploaded|accepted|file)\s*->/', $readmePhp) !== 1, $readmePhp);
foreach ($readmeBlocks[1] as $blockIndex => $blockCode) {
    $blockFile = tempnam(sys_get_temp_dir(), 'readme') . '.php';
    file_put_contents($blockFile, "<?php\n" . $blockCode);
    $lintOutput = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($blockFile) . ' 2>&1', $lintOutput, $lintStatus);
    check('README php block #' . ($blockIndex + 1) . ' parses', $lintStatus === 0, implode(' ', $lintOutput));
    unlink($blockFile);
}
// verifyRequest takes the secret first. Passing the body as the secret and the secret as the body
// raises no type error - both are strings - and merely makes every genuine delivery fail
// verification, reported as invalid_json, which points at "the body is not JSON" rather than "the
// arguments are the wrong way round".
preg_match_all('/verifyRequest\(\s*([^,)\n]+)/', $readmePhp, $verifyArgs);
check('the README passes the secret as verifyRequest\'s first argument',
    $verifyArgs[1] !== [] && array_filter(
        $verifyArgs[1],
        static fn (string $argument): bool => stripos($argument, 'secret') === false,
    ) === [],
    implode(' | ', $verifyArgs[1]));
// Every exception property the README names must really exist on the class.
preg_match('/carrying (.+?)\./', $readme, $carrying);
preg_match_all('/`\$([A-Za-z]+)`/', $carrying[1] ?? '', $namedProperties);
$realProperties = array_map(
    static fn (ReflectionProperty $property): string => $property->getName(),
    (new ReflectionClass(SpicyApiError::class))->getProperties(ReflectionProperty::IS_PUBLIC),
);
check('every exception property the README names really exists',
    ($namedProperties[1] ?? []) !== [] && array_diff($namedProperties[1], $realProperties) === [],
    'README: ' . implode(',', $namedProperties[1] ?? []) . ' - actual: ' . implode(',', $realProperties));
foreach ([40003, 40004, 40310, 409, 40901, 503, 50301, 50302] as $documentedCode) {
    check("the README error table lists $documentedCode", str_contains($readme, '| `' . $documentedCode . '` |'));
}

/* -- 14. Redline scan ------------------------------------------------- */
echo "redlines\n";
// This used to read a stale copy living in another repository (spicy-docs/public/examples/php/), so
// the whole section scanned something other than the file this package publishes - permanently green
// locally, while in CI that path did not exist at all, file_get_contents returned '', and all
// sixteen banned-word checks passed because nothing can be found in an empty string.
$sourcePath = __DIR__ . '/../src/SpicyClient.php';
$source = (string) file_get_contents($sourcePath);
// Assert something was actually read first: an empty string would satisfy every banned-word check
// below, which is the worst part of the bug above - the guard was green while switched off.
check('the redline scan reads the published client source', strlen($source) > 10000, $sourcePath . ' read ' . strlen($source) . ' bytes');
foreach (['KIE', 'kie.ai', 'WaveSpeed', 'wavespeed', 'Atlas', 'atlascloud', 'NamiFusion', 'namifusion',
          'Sandbase', 'MuleRouter', 'SiftQ', 'Cloudwise', 'Novita', 'credits', 'procurement', 'margin'] as $banned) {
    check("no '$banned' in source", stripos($source, $banned) === false);
}
// Nothing in a public repository may be written in Chinese - not the code, not the comments, not the
// docblocks. This repository is read by developers worldwide, and a comment they cannot read is
// worse than no comment: it looks like documentation while explaining nothing.
//
// The scan covers every tracked text file rather than just the client, because the earlier version
// of this check only looked at one file and would have stayed green while the tests, the workflows
// and the examples drifted back.
$chineseHits = [];
foreach (spicy_tracked_text_files(__DIR__ . '/..') as $trackedFile) {
    $contents = (string) file_get_contents($trackedFile);
    foreach (explode("\n", $contents) as $index => $line) {
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $line) === 1) {
            $chineseHits[] = basename($trackedFile) . ':' . ($index + 1) . ': ' . trim($line);
        }
    }
}
check('no Chinese anywhere in the repository', $chineseHits === [], implode(' | ', array_slice($chineseHits, 0, 3)));
// The counter-proof: a scan that reads nothing would pass the assertion above. This one fails if the
// file list ever comes back suspiciously short.
check('the Chinese scan actually reads the repository',
    count(spicy_tracked_text_files(__DIR__ . '/..')) >= 8,
    (string) count(spicy_tracked_text_files(__DIR__ . '/..')));

echo "\npassed {$passed}, failed {$failed}\n";
exit($failed === 0 ? 0 : 1);
