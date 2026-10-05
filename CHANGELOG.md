# Changelog

The version of this package is its git tag; `composer.json` carries no version field.

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
