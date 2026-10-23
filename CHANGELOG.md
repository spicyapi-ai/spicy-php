# Changelog

The version of this package is its git tag; `composer.json` carries no version field.

## 0.3.0

### Added

- **The asset library, as `$client->assets`.** Saved files are kept until they are deleted and are
  referenced as `spicy://a/<id>` in any media field of model inputs sent with keys of the same
  workspace; an upload's `spicy://` URI lasts one day. The key decides the library: a personal key
  reaches the personal library, a team key that team's shared library, where the creator's current
  role decides what may change.
  - `assets->create(source: ...)` saves a task output (`taskId` with `key` or `index`), a committed
    upload (`fileId`) or a public URL (`url`). It sends the required `Idempotency-Key` itself, the
    same one on every retry of the call; pass `idempotencyKey` to replay a save.
  - `assets->list(...)` and `assets->iterAll(...)` take `kind`, `source` (one value or several),
    `q`, `favorite` (`false` is sent and lists the assets that are not favorites; `null` leaves the
    filter off), `collectionId`, `entityId`, `sort`, `cursor` and `limit`. `iterAll()` is a
    generator that follows `nextCursor` and repeats the filters and sort on every page; a cursor
    that comes back twice is refused instead of looping, and its arguments are checked when it is
    called, not when the loop starts.
  - `assets->get()`, `assets->update()` (`name`, `favorite` or both; only what you pass is sent),
    `assets->delete()` and `assets->usage()`, which carries `scope`.
  - `assets->uploadFile($path, name:, contentType:)` uploads a local file and saves it in one call;
    the name defaults to the file name, cut to 80 characters.
  - `assets->characters->list()`, `iterAll()` and `get()` read the library's characters (named,
    ordered sets of images) with their images in order. They are read-only through the API.
  - New enums `AssetSource`, `AssetSort` and `AssetErrorReason`; `source` and `sort` take a case or
    its string value. Asset ids are accepted as `ast_…` or as the `spicy://a/…` reference. Empty
    ids, blank filters, an unknown `sort` or `source` and a save that mixes two sources throw
    `InvalidArgumentException` before anything is sent.
- **`SpicyApiError::$reason`**, read from a failure envelope's `data.reason`. The asset library uses
  `asset_library_locked`, `asset_library_full`, `asset_file_too_large`, `asset_daily_limit`,
  `asset_storage_full`, `asset_permission_denied`, `asset_workspace_forbidden` and
  `asset_entity_name_taken`, the cases of `AssetErrorReason`; `assetErrorReason()` returns the case
  or null, and any other value stays in `$reason` unchanged. `guidance()` answers a known reason
  before the business code, and `describe()` includes the reason when there is one. The new
  constructor parameter comes last, so existing positional calls are unaffected.
- **LoRA weight uploads.** `UploadContentType::Lora` (`application/octet-stream`, the upload type for
  `loras[].path`) with `.safetensors` inference, `isLora()`, and its own 2 GiB ceiling. Raise
  `uploadTimeoutSeconds` for big files; the ticket stays valid for two hours.

### Changed

- A retryable failure whose `Retry-After` is longer than what the request's timeout has left is now
  thrown at once, with its `$retryAfterSeconds` and `$reason`. It used to be slept through and
  surface as a local timeout, which hid what the server said. A `Retry-After` beyond
  `maxRetryDelaySeconds` was already thrown at once, and shorter waits are retried as before.
  `waitForTerminal()` treats such a poll like one that timed out: it waits as asked and polls again
  while its budget lasts.
- `UploadContentType::isDocument()` is false for LoRA weights.
- The pinned `contracts/openapi.yaml` matches the published contract, including the asset routes
  and `POST /api/v1/files/import` (which this package does not wrap).
- `scripts/check_contract.php` also guards the asset reasons, sorts and sources, and takes
  `--offline` to check against the pinned copy without downloading the published one. The unit
  suite compares the same enums with the pinned contract offline, and checks that every
  `assets->…` call in the README names a real method and real argument names.

## 0.2.0

- Upload reference documents. `UploadContentType` gains the twelve document types the upload
  endpoint now accepts — `Pdf`, `Doc`, `Docx`, `Xls`, `Xlsx`, `Ppt`, `Pptx`, `Keynote`, `Pages`,
  `Numbers`, `Text` and `Markdown` — with extension inference for each (`.md` and `.markdown` both
  map to Markdown) and a new `isDocument()`. Documents share the 90 MiB ceiling of audio and video.
  Until now a document could not be uploaded through this package at all, because
  `createUploadUrl()` takes the enum.
- `SpicyApiError::guidance()` explains business code `40310`: the account has not verified its email
  address, so it cannot run models until it does. The README error table lists it too.
- The pinned contract in `contracts/openapi.yaml` is synced with the published one. Fields added
  since — `output.transcript` on speech-to-text results, `layer` on layer decomposition assets,
  `requestId` on task records — arrive in the returned arrays with no code change, and the README
  says where to find them.
- The README covers getting a local image, video or audio file to a chat model: upload it here and
  send the returned `spicy://f/…` URI in an `image_url`, `video_url` or `audio_url` content part.
- A unit test now compares `UploadContentType` with the pinned contract offline, so a missing case
  fails every local run rather than only the networked drift check.

## 0.1.1

- Initial public release: the asynchronous task flow, three-step upload, quotes, account history and
  webhook verification.
