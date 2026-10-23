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
extension, or pass `UploadContentType::Pdf` and friends explicitly. Images go up to 10 MiB, the rest
up to 90 MiB.

LoRA weights (a `.safetensors` file, sent as `application/octet-stream`, `UploadContentType::Lora`)
go up to 2 GiB, and the committed URI goes into `loras[].path`. A file that large can outlast the
ten-minute upload timeout, so raise it for big ones:

```php
$client = new SpicyClient(uploadTimeoutSeconds: 3600.0);
$lora = $client->uploadFile('/path/to/style.safetensors');
$input = ['prompt' => 'a lantern in fog', 'loras' => [['path' => $lora['uri']]]];
```

The other fields of each `loras` entry come from that model's `inputSchema`.

An upload's URI lasts one day. To keep a file for later requests, save it to the
[asset library](#asset-library).

## Asset library

Added in 0.3.0. Files you use again and again — a character portrait, a style reference, a voice
sample — belong in your asset library: each saved file gets a stable `uri` of the form
`spicy://a/<id>` that works in any media field of model inputs sent with keys of the same
workspace, until it is deleted. The library never hands out a public or permanent link.

The key decides the library. A key created in your personal workspace reaches your personal library;
a key created in a team workspace, by any member, reaches that team's shared library and never
anyone's personal one. With a team key, what may change follows the current role of the member who
created it: owner and admin can save, rename, favorite and delete anything; developer can save, and
rename, favorite or delete what they saved; billing and readonly can only read and use assets.

```php
use SpicyApi\AssetSource;

// Save the first output of one of your successful tasks (with a team key, one of the team's).
// source: 'url' with url: ..., and source: 'upload' with fileId: ..., work the same way. The
// required Idempotency-Key is added for you.
$hero = $client->assets->create(source: AssetSource::TaskOutput, taskId: 'job_...', index: 0, name: 'Hero portrait');

// Or upload a local file and save it in one call; the name defaults to the file name.
$voice = $client->assets->uploadFile('/path/to/voice-sample.wav');

// Later, in any request: the uri goes where the model's schema expects a file.
$input = ['prompt' => 'Slow push-in, light rain', 'image_url' => $hero['uri']];

// Every saved image, newest first, across all pages.
foreach ($client->assets->iterAll(kind: 'image', q: 'portrait') as $saved) {
    echo $saved['uri'], ' ', $saved['name'], ' ', $saved['bytes'], "\n";
}
```

| Method | Returns |
| --- | --- |
| `assets->create(source:, taskId:, key:, index:, fileId:, url:, name:, idempotencyKey:)` | The saved asset: `id`, `uri`, `name`, `kind`, `bytes`, `favorite`, `model`, `previewUrl`, … |
| `assets->uploadFile($path, name:, contentType:)` | Uploads with `uploadFile()`, then saves; the saved asset |
| `assets->list(kind:, source:, q:, favorite:, collectionId:, entityId:, sort:, cursor:, limit:)` | One page: `items`, `nextCursor` (absent on the last page) |
| `assets->iterAll(...)` | A generator over every matching asset, with the same filters as `list` |
| `assets->get($assetId)` | One asset with a fresh `previewUrl` |
| `assets->update($assetId, name:, favorite:)` | The updated asset; only what you pass changes, the `uri` stays the same |
| `assets->delete($assetId)` | `['id' => ..., 'deleted' => true]`; the `uri` stops working at once |
| `assets->usage()` | `scope` (which library, role, `canWrite`, `canTopUp`), plan, usage, today's count |
| `assets->characters->list(assetId:, cursor:, limit:)` / `iterAll(...)` | Characters, newest first: `id`, `name`, `note`, `cover`, `assets` (the images in order), `count` |
| `assets->characters->get($characterId)` | One character with its images in order |

- `$assetId` may be the bare `ast_…` id or the `spicy://a/…` reference. An empty id, a blank filter,
  an unknown `sort` or `source`, or a save that mixes two sources throws `InvalidArgumentException`
  before anything is sent.
- `source` takes one value or a list of them (`AssetSource` cases or `task_output`, `upload`,
  `url`). `favorite: true` lists only favorites and `favorite: false` only the rest; leave it null
  for both.
- `sort` is `AssetSort::Recent` (the default, newest first), `Oldest`, `Name` or `Size`, or the same
  values as strings. A `nextCursor` only works with the `sort` it came from; sending it with another
  one fails with `400`. `iterAll()` keeps your filters and `sort` on every page, and its arguments
  are checked when you call it, not when the loop starts.
- Saving the same task output, upload or URL again returns the asset you already have. Pass your
  own `idempotencyKey` to replay a save safely after an uncertain response, for example across a
  restart.
- `previewUrl` is a private preview signed for at most 20 minutes. Show it; store `uri`.
- Storage unlocks with the owner's lifetime top-ups, and the owner's personal and team libraries
  share one allowance. `assets->usage()` reports the whole allowance; with a team key whose creator
  is not the owner, `scope.canTopUp` is false and `lifetimeTopupUsd` and `topupToNextTierUsd` are
  omitted, so read both with `?? null`.
- A refused call throws `SpicyApiError` with a `$reason`: `asset_library_locked`,
  `asset_library_full` and `asset_file_too_large` (400, plan limits — read `assets->usage()` for the
  numbers), `asset_daily_limit` (429, resets at 00:00 UTC, with `$retryAfterSeconds`),
  `asset_storage_full` (503, temporary), `asset_permission_denied` (403, the team role does not
  allow this change), `asset_workspace_forbidden` (403, this key cannot reach that library, for
  example because its creator left the team) and `asset_entity_name_taken` (409, a character with
  that name already exists). The set, which matches the contract, is the `AssetErrorReason` enum;
  `$reason` holds any other value the server sends unchanged, so keep a default branch.

```php
use SpicyApi\AssetErrorReason;
use SpicyApi\SpicyApiError;

try {
    $client->assets->create(source: 'url', url: 'https://example.com/still.png');
} catch (SpicyApiError $error) {
    if ($error->assetErrorReason() !== AssetErrorReason::LibraryFull) {
        throw $error;
    }
    $usage = $client->assets->usage();
    printf("Full on %s; next plan: %s\n", $usage['tier']['name'], $usage['nextTier']['name'] ?? 'none');
}
```

### Favorites, folders and characters

Every asset has a `favorite` flag; in a team library everyone sees the same favorites. Folders
(collections in the API) and characters are created and arranged on the Assets page of the web app;
the API reads them. Each asset also carries `model` and `modelName`, the model that generated it
(empty strings for uploads and links).

```php
use SpicyApi\AssetSort;

// Mark a favorite, then list only favorites in one folder, A to Z.
$client->assets->update($hero['uri'], favorite: true);
$page = $client->assets->list(favorite: true, collectionId: 'col_...', sort: AssetSort::Name);

// A character is a named, ordered set of images. Put its images, in order, into the model's
// reference image field (getModel() shows which field takes a list of images).
$mia = $client->assets->characters->get('ety_...');
$references = array_column($mia['assets'], 'uri');      // ['spicy://a/ast_...', ...]
```

An unknown folder, and an unknown character, both fail with `404`. Characters used to be called
personas: existing `spicy://p/<id>` references keep resolving to the same images, in your personal
library only; new code should use the `spicy://a/` references a character lists.

See the asset library guide:
[organizing](https://docs.spicyapi.ai/docs/media#asset-library-organize) and
[characters](https://docs.spicyapi.ai/docs/media#asset-library-characters).

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

Failures throw `SpicyApiError` carrying `$businessCode`, `$requestId`, `$retryAfterSeconds` and `$reason`.
`$reason` is null unless the server sent one (see [Asset library](#asset-library)).

Branch on the business code or the reason, never on the message — messages are translated, codes
are not. **HTTP
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

`$error->guidance()` returns the same advice at runtime, and the more specific advice for a known
`$reason`.

Retryable failures are retried with backoff inside one request's timeout. When the server's
`Retry-After` is longer than the client's retry ceiling or than what that timeout has left, the
error is thrown at once, with its `$retryAfterSeconds`, instead of being slept through into a local
timeout.

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
