<div align="center">

# spicyapi/spicyapi

**Official PHP SDK for [SpicyAPI](https://spicyapi.ai)** — image, video, audio and text models behind one API.

[Get a key](https://spicyapi.ai) · [Models](https://spicyapi.ai/models) · [Docs](https://docs.spicyapi.ai) · [Status](https://status.spicyapi.ai)

</div>

---

One endpoint in front of 142 model families across 214 callable endpoints, billed in USD per request
rather than in credits. Media generation is asynchronous and quotable before you spend; text models
speak the OpenAI, Anthropic and Gemini wire formats.

```bash
composer require spicyapi/spicyapi
```

Requires PHP 8.1+, `ext-json` and `ext-hash`. **No library dependencies** — `ext-curl` is used when
present, otherwise it falls back to the `https://` stream wrapper.

## Generate something

```php
use SpicyApi\SpicyClient;

$client = new SpicyClient();                 // reads SPICY_API_KEY from the environment

$quote = $client->quote('MODEL_ID_FROM_CATALOG', ['prompt' => 'a lantern in fog']);
printf("at most %s\n", $quote['maxCharge']); // decide before you spend

$task = $client->createTask(
    model: $quote['model'],
    input: ['prompt' => 'a lantern in fog'],
    idempotencyKey: SpicyClient::idempotencyKey(),
    quoteId: $quote['quoteId'],
    expectedCost: $quote['estimatedCost'],
);

$final = $client->waitForTerminal($task['taskId']);
foreach (SpicyClient::readyAssets($final) as $asset) {
    echo $asset['url'], "\n";
}
```

Every call returns the decoded `data` of the response envelope as a plain associative array, so
read it with `$quote['maxCharge']`, not `$quote->maxCharge`.

Build the input from that model's own `inputSchema` — every model has different fields, and
`listModels()` returns them.

## Skip the first poll

`waitSeconds` holds the connection open until the task finishes, so a short job needs no polling
loop at all. **It does not promise a finished task.** If the budget runs out you get the ordinary
accepted response instead — same shape, different `state` — so branch on the state, never on the
fact that you passed the parameter:

```php
$submitted = $client->createTask(
    model: 'MODEL_ID_FROM_CATALOG',
    input: ['prompt' => 'a lantern in fog'],
    idempotencyKey: SpicyClient::idempotencyKey(),
    waitSeconds: 30,
);

if (!SpicyClient::isTerminal($submitted)) {
    $submitted = $client->waitForTerminal($submitted['taskId']);
}
```

The server clamps anything above 60 and ignores anything that is not a positive integer, so nothing
is clamped here. The client widens its own request timeout to cover the wait — otherwise the two
deadlines collide and the parameter looks like it did nothing.

## Start from a local file

Image-to-video, face swap and image editing need your material on our side first. Upload returns a
`spicy://` URI; that is what goes into the input.

```php
$uploaded = $client->uploadFile('/path/to/reference.png');
$task = $client->createTask(
    model: 'MODEL_ID_FROM_CATALOG',
    input: ['image' => $uploaded['uri'], 'prompt' => 'slow dolly in'],
    idempotencyKey: SpicyClient::idempotencyKey(),
);
```

Images, MP4/WebM video, MP3/WAV audio and reference documents (PDF, Word, Excel, PowerPoint,
Keynote, Pages, Numbers, plain text, Markdown) can all be uploaded; the type is inferred from the
extension, or pass `UploadContentType::Pdf` and friends explicitly.

## Not every result is a file

Speech-to-text answers in text, so check `SpicyClient::outputText($task)` before reaching for the
assets. A transcription that reports timing also carries `output.transcript`, with the detected
language and per-word start and end times. A layer decomposition returns a base image followed by
transparent layers, each with a `layer` object holding its `zIndex` and, when the model reports it,
a `boundingBox` on the base.

## Webhooks

```php
use SpicyApi\SpicyWebhook;

// The secret comes first. The other three are optional: $_SERVER and the raw bytes of
// php://input are read for you unless a framework already consumed them.
$delivery = SpicyWebhook::verifyRequest(getenv('SPICY_WEBHOOK_SECRET') ?: '');

// Inside a framework that buffers the body, hand it the exact bytes yourself:
$delivery = SpicyWebhook::verifyRequest(
    secret: getenv('SPICY_WEBHOOK_SECRET') ?: '',
    server: $_SERVER,
    rawBody: $request->getContent(),
);
```

Three ways PHP code gets this wrong, all of which this class avoids:

- **Re-serialising the body before verifying.** `json_decode` then `json_encode` changes the bytes,
  and the signature will never match. Verify the raw bytes.
- **Comparing signatures with `==` or `===`.** Beyond timing, `==` on two strings that both look
  like `0e…` compares them as numbers — `"0e123" == "0e456"` is `true`. This uses `hash_equals`.
- **Skipping the timestamp check.** A valid signature stays valid forever; without a freshness
  window, a captured delivery can be replayed.

## Balance, usage and history

```php
$balance = $client->getBalance();                       // available / held / total, USD strings
$usage = $client->getUsage('2026-09-01', '2026-09-08');  // settled spend over [from, to)

foreach ($client->iterTasks(state: 'succeeded') as $summary) {
    echo $summary['taskId'], "\n";                      // every page, filters repeated for you
}
```

Amounts are decimal strings, never floats — compare them with `SpicyClient::compareUsd()`. `held` is
money reserved by tasks that have not settled, so it is neither spent nor available.

Dates are UTC calendar days, `YYYY-MM-DD`, forming a half-open `[from, to)` interval of at most 92
days. All three calls see only this API key: sibling keys and console generations are not included.

`getUsage()` is for reconciliation, not for progress. It has an account-wide budget of 30 requests
per minute shared by every key, and that limiter fails closed — polling it can lock the whole
account out of its own reporting. Follow a task with `getTask()` instead. Its totals cover settled
charges only, so late settlement can still move a number you read today.

`listTasks()` returns one page as `{items, hasMore, nextCursor}`. Paginating by hand means repeating
every filter unchanged and moving only the cursor, and stopping when `hasMore` is false **or**
`nextCursor` is empty — checking only `hasMore` turns a server-side glitch into an endless loop of
paid calls that looks like a hang. `iterTasks()` does all of that.

## Errors

Failures throw `SpicyApiError` carrying `$businessCode`, `$requestId` and `$retryAfterSeconds`.

Branch on the business code, never on the message — messages are translated, codes are not. **HTTP
503 is shared by three different business codes**, so the status alone cannot tell you what to do:

| Code | Meaning | What to do |
| --- | --- | --- |
| `40003` | uploaded bytes do not match their ticket | upload again |
| `40004` | no deployment serves that parameter combination | change the named parameter |
| `40310` | the account has not verified its email address | open the verification link; the same key then works |
| `409` | the idempotency key was reused for a different request, or by a sibling key | resend the original request under that key, or the changed one under a new key |
| `40901` | the price moved before the task was created | quote again, keep the same idempotency key |
| `503` | a dependency is briefly unavailable | back off by `Retry-After` |
| `50301` | no usable deployment or price right now | refresh the catalogue |
| `50302` | a synchronous generation failed upstream and was refunded | retry with a **new** idempotency key |

`$error->guidance()` returns the same advice at runtime.

## Two things that will save you money

**Keep one idempotency key per submission**, and reuse it for every resend — a lost response does
not prove the task was not created.

**A task that succeeds is charged**, even if the result disappoints. `quote()` reserves nothing.

## What this package does not do

**Text models.** They speak the OpenAI, Anthropic and Gemini wire formats; the established PHP
clients for those already work against `https://api.spicyapi.ai/v1`.

Chat models that read images, video or audio still need this package for one thing: getting a local
file to them. Upload it here and put the returned `spicy://f/…` URI in an `image_url`, `video_url`
or `audio_url` content part. Uploaded images also keep the up-front hold small, and an `audio_url`
part accepts only an uploaded file.

**Browser-side code.** The key belongs on your server, never in anything you ship to a visitor.

## Links

- [Documentation](https://docs.spicyapi.ai)
- [API reference](https://docs.spicyapi.ai/docs/api-reference)

---

<div align="center">
<sub>

Also available in [TypeScript](https://github.com/spicyapi-ai/spicy-sdk) · [Python](https://github.com/spicyapi-ai/spicy-python) · [Go](https://github.com/spicyapi-ai/spicy-go) · **PHP** · [Java](https://github.com/spicyapi-ai/spicy-java)

</sub>
</div>
