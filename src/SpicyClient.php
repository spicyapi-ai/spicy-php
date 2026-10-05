<?php

/**
 * Official SpicyAPI client for PHP 8.1+.
 *
 * SpicyAPI serves image, video and audio generation models behind one API. This
 * package covers the asynchronous media workflow end to end: discover a model and its
 * input schema, upload local reference material, quote a request, submit it,
 * wait for a terminal state, and collect the result.
 *
 * Text models are deliberately out of scope: they speak the OpenAI, Anthropic
 * and Gemini wire formats, so the established PHP clients for those already work
 * against this service.
 *
 * It depends on no library: ext-json, ext-hash and either ext-curl or the
 * `https://` stream wrapper, all of which ship with a default PHP build.
 *
 * Everything here follows the published OpenAPI contract at
 * https://docs.spicyapi.ai/openapi.yaml. Field names, error codes and limits are
 * quoted from it in the comments so a reader can check them against the source
 * instead of trusting this file.
 *
 * Scope: SpicyAPI is mostly image, video and audio generation, which is
 * asynchronous — create a task, then poll or receive a webhook. That is what this
 * client wraps. Text models speak OpenAI / Anthropic / Gemini on `/v1` and
 * `/v1beta`; for those, point an official SDK at `https://api.spicyapi.ai/v1` and
 * change nothing else. There is no reason to wrap them here. The one piece of
 * this client a chat request still needs is upload: a local image, video or
 * audio file goes to a chat model as the `spicy://f/...` URI that
 * {@see SpicyClient::uploadFile()} returns, inside an `image_url`, `video_url`
 * or `audio_url` content part.
 *
 * Usage (every optional argument is a named argument):
 *
 *   require_once __DIR__ . '/SpicyClient.php';
 *   use SpicyApi\SpicyClient;
 *
 *   $client = new SpicyClient();                       // reads SPICY_API_KEY
 *   $file   = $client->uploadFile('/tmp/reference.png');
 *   $task   = $client->createTask(
 *       model: 'MODEL_ID_FROM_CATALOG',
 *       input: ['prompt' => 'a paper-cut city at dusk', 'image_url' => $file['uri']],
 *       idempotencyKey: SpicyClient::idempotencyKey(),
 *   );
 *   $done = $client->waitForTerminal($task['taskId']);
 *
 * PSR-4 note: one file holds the client, its enums and its exceptions so that
 * copying a single file is enough. In a real project, split it per class.
 *
 * @license The same terms as the repository this file ships in.
 */

declare(strict_types=1);

namespace SpicyApi;

/* -- Task state ----------------------------------------------------------- */

/**
 * Task lifecycle states.
 *
 * openapi.yaml `TaskRecord.state`: queued, running, succeeded, failed,
 * canceled, expired. Four of the six are terminal.
 */
enum TaskState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** A terminal task never changes state again; stop polling. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Canceled, self::Expired => true,
            self::Queued, self::Running => false,
        };
    }
}

/* -- Failure classification ----------------------------------------------- */

/**
 * The closed set of public task failure identifiers.
 *
 * openapi.yaml `TaskRecord.errorCode`. The contract states the set is closed
 * and that any other value "should be handled as `upstream_failed`", which is
 * what {@see self::fromTask()} does — a new platform code must never make a
 * client crash on an unknown enum value.
 *
 * Branch on this, never on `errorMessage`: the message is localized and the
 * upstream half of it is passed through verbatim, so its text is not stable.
 */
enum TaskErrorCode: string
{
    case InvalidRequest = 'invalid_request';
    case UnsupportedCombination = 'unsupported_combination';
    case ContentRejected = 'content_rejected';
    case RateLimited = 'rate_limited';
    case UpstreamUnavailable = 'upstream_unavailable';
    case GenerationFailed = 'generation_failed';
    case Timeout = 'timeout';
    case InvalidAsset = 'invalid_asset';
    case UpstreamFailed = 'upstream_failed';

    /**
     * Read `errorCode` off a task record, folding unknown values into
     * `upstream_failed` as the contract requires.
     *
     * @param array<string,mixed> $task
     */
    public static function fromTask(array $task): ?self
    {
        $raw = $task['errorCode'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return self::tryFrom($raw) ?? self::UpstreamFailed;
    }

    /**
     * Whether resubmitting the identical request could plausibly succeed.
     *
     * `content_rejected` and `invalid_request` are decisions about this exact
     * request, so a retry only spends money to reach the same answer.
     */
    public function isWorthRetrying(): bool
    {
        return match ($this) {
            self::InvalidRequest,
            self::UnsupportedCombination,
            self::ContentRejected,
            self::InvalidAsset => false,
            default => true,
        };
    }
}

/* -- Upload media types ---------------------------------------------------- */

/**
 * Media types the upload ticket endpoint accepts.
 *
 * openapi.yaml `UploadURLRequest.contentType`. Anything else is rejected before
 * a ticket is issued, so the enum is the whole list.
 *
 * Documents (PDF, Office, iWork, plain text and Markdown) are reference files
 * for the models whose schema asks for one. The server checks only that the
 * bytes belong to the declared family; it does not read the content.
 */
enum UploadContentType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Gif = 'image/gif';
    case Mp4 = 'video/mp4';
    case Webm = 'video/webm';
    case Mp3 = 'audio/mpeg';
    case Wav = 'audio/wav';
    case Pdf = 'application/pdf';
    case Doc = 'application/msword';
    case Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case Xls = 'application/vnd.ms-excel';
    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case Ppt = 'application/vnd.ms-powerpoint';
    case Pptx = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    case Keynote = 'application/vnd.apple.keynote';
    case Pages = 'application/vnd.apple.pages';
    case Numbers = 'application/vnd.apple.numbers';
    case Text = 'text/plain';
    case Markdown = 'text/markdown';

    /** File extensions this client can map on its own. */
    private const EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'key' => 'application/vnd.apple.keynote',
        'pages' => 'application/vnd.apple.pages',
        'numbers' => 'application/vnd.apple.numbers',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
    ];

    public function isImage(): bool
    {
        return str_starts_with($this->value, 'image/');
    }

    /** True for the reference document types: neither image, audio nor video. */
    public function isDocument(): bool
    {
        return !$this->isImage()
            && !str_starts_with($this->value, 'video/')
            && !str_starts_with($this->value, 'audio/');
    }

    /**
     * Per-type ceiling, from the `createUploadUrl` description: 10 MiB for
     * images, 90 MiB for supported audio and video (which is also the `bytes`
     * maximum of 94371840 in the request schema). Documents get the same
     * 90 MiB as audio and video. Audio and video additionally must be at most
     * 600 seconds, which only the server can measure.
     *
     * This is a local sanity check. The ticket's own `maxBytes` is what the
     * server will enforce, and {@see SpicyClient::uploadBytes()} checks that too.
     */
    public function maxBytes(): int
    {
        return $this->isImage() ? 10 * 1024 * 1024 : 90 * 1024 * 1024;
    }

    /**
     * Guess the media type from a file extension.
     *
     * Deliberately not `mime_content_type()` / ext-fileinfo: that reads the
     * file's magic bytes, which is better, but it is an optional extension and
     * this client promises to run on a default build. Pass `contentType`
     * explicitly whenever the extension may lie.
     */
    public static function forPath(string $path): self
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $value = self::EXTENSIONS[$extension] ?? null;
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf(
                'cannot infer contentType from "%s"; pass it explicitly. Known extensions: %s',
                $path,
                implode(', ', array_keys(self::EXTENSIONS)),
            ));
        }

        return self::from($value);
    }
}

/* -- Exceptions ------------------------------------------------------------ */

/**
 * Any failure of a SpicyAPI call, carrying everything support needs.
 *
 * Catch this one type to catch every error this client raises for an API call:
 * transport failures, local deadlines and the storage upload leg all extend it.
 *
 * `$businessCode` is the envelope's **business** code, which is not the HTTP
 * status. The two differ exactly where it matters — see {@see self::guidance()}.
 * It is spelled out rather than called `code` because `\Exception` already owns
 * that name; `getCode()` mirrors it, and reads 0 when there was no envelope at
 * all, which `$businessCode` reports honestly as null.
 */
class SpicyApiError extends \RuntimeException
{
    /** HTTP statuses this client may retry on its own. */
    public const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $businessCode = null,
        public readonly string $requestId = '',
        public readonly ?float $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $businessCode ?? 0, $previous);
    }

    /**
     * Whether an automatic retry of the same call is allowed by transport
     * rules alone.
     *
     * This answers "is the status retryable", not "is this request safe to
     * repeat". Task creation is only safe to repeat when it carried an
     * `Idempotency-Key`; without one, a retry can create and bill a second
     * task. {@see SpicyClient::createTask()} encodes that rule, so callers
     * normally never need this method — it exists for code that retries at a
     * higher level.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * What to actually do about this error, per business code.
     *
     * openapi.yaml `ErrorEnvelope.code` and the `Unavailable` response. The
     * three 503 codes are the reason this method exists: `503`, `50301` and
     * `50302` share one HTTP status and call for three different responses, so
     * branching on the status alone is wrong.
     */
    public function guidance(): string
    {
        return match ($this->businessCode) {
            40003 => 'The uploaded bytes do not match the ticket: size, media type or file signature '
                . 'differs from what the ticket declared. Request a fresh upload ticket and upload the '
                . 'file again. Retrying the commit alone will fail identically.',
            40004 => 'The request is valid but no deployment can serve this exact parameter combination. '
                . 'Change the parameter named in the message, then resubmit. Do not retry unchanged.',
            40201 => 'Insufficient balance. Top up, then resubmit.',
            40202 => 'A spend cap was reached. Raise the cap or wait for the window to roll over.',
            40301 => 'This API key is not allowed to call this model. Widen the key\'s model scope.',
            40302 => 'The caller IP is outside this API key\'s allow list. Retrying from the same '
                . 'address will keep failing.',
            40303 => 'The request came from a region this service does not serve. Not retryable.',
            40310 => 'The account has not verified its email address yet, so it cannot run models. Open the '
                . 'verification link sent at sign-up, or send a new one from the Console; the same key '
                . 'starts working as soon as the address is verified.',
            409 => 'Idempotency conflict. This key was already used for a different request, or it '
                . 'belongs to a sibling API key of the same account. Resend the original request under '
                . 'the original key — that returns the first task rather than creating a second — or '
                . 'submit the changed request under a new key.',
            40901 => 'The quote expired or the price changed, and nothing was reserved. Quote again and '
                . 'resend with the new quoteId and expectedCost, keeping the original Idempotency-Key '
                . 'so an already accepted task is recovered instead of created twice.',
            50301 => 'The model currently has no usable deployment or effective price. Retrying the same '
                . 'model soon may work; a different model is the faster fix.',
            50302 => 'Synchronous generation failed upstream and the charge was refunded. Send the '
                . 'request again under a new Idempotency-Key: the original key replays the recorded '
                . 'failure, so a retry that keeps it can never succeed.',
            503 => 'A dependency is temporarily unavailable. Back off, honouring Retry-After when present.',
            429 => 'Account rate limit exceeded. Back off, honouring Retry-After.',
            413 => 'The request body exceeds the accepted size. Upload large media instead of inlining it.',
            default => $this->isRetryable()
                ? 'Transient failure; retry with backoff.'
                : 'Not retryable as sent. Fix the request, then resubmit.',
        };
    }

    /** One line safe to log. It never contains the API key. */
    public function describe(): string
    {
        return sprintf(
            '%s (http=%d code=%s request_id=%s)',
            $this->getMessage(),
            $this->status,
            $this->businessCode === null ? '-' : (string) $this->businessCode,
            $this->requestId === '' ? '-' : $this->requestId,
        );
    }
}

/**
 * The network call never produced an HTTP response: DNS, TLS, connection reset.
 *
 * Distinct from an API error because the request may or may not have reached
 * the server. For a non-idempotent call, treat it as "unknown", not "failed".
 */
final class SpicyTransportError extends SpicyApiError
{
}

/**
 * A local deadline elapsed. This says nothing about the remote task.
 *
 * A wait timeout is not a failure of the generation: the task is probably still
 * running and will still be billed. Persist `taskId` and reconcile it later, or
 * use a webhook.
 */
final class SpicyTimeoutError extends SpicyApiError
{
    public function __construct(
        string $message,
        public readonly ?string $taskId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}

/** The direct-to-storage PUT leg failed. The SpicyAPI call around it did not. */
final class SpicyUploadError extends SpicyApiError
{
}

/** A webhook delivery failed verification and must be discarded, not processed. */
final class SpicyWebhookError extends \RuntimeException
{
    /**
     * @param string $reason One of: body_too_large, invalid_json, invalid_payload,
     *                       invalid_secret, invalid_signature, invalid_timestamp,
     *                       stale_timestamp, missing_header.
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

/* -- Transport ------------------------------------------------------------- */

/** One HTTP response. Header names are lower-cased. */
final class HttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

/**
 * The seam between this client and the network.
 *
 * Swapping it is how the client is tested without a network, and how a host
 * application routes calls through its own egress proxy or instrumentation.
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @param string|resource|null $body Resource bodies are streamed where the
     *                                   implementation can; see the note in
     *                                   {@see StreamTransport}.
     *
     * @throws SpicyTimeoutError   when the local deadline elapsed. Separate from
     *                               a transport failure because the request may
     *                               already have reached the server.
     * @throws SpicyTransportError when no HTTP response was produced at all and
     *                               the deadline was not the reason.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        $body,
        float $timeoutSeconds,
    ): HttpResponse;
}

/** ext-curl transport. Preferred: it streams request bodies without buffering. */
final class CurlTransport implements Transport
{
    public function __construct(private readonly float $connectTimeoutSeconds = 10.0)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('ext-curl is not loaded; use StreamTransport instead');
        }
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        $body,
        float $timeoutSeconds,
    ): HttpResponse {
        $handle = curl_init();
        if ($handle === false) {
            throw new SpicyTransportError('could not initialise a cURL handle');
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeoutSeconds * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($this->connectTimeoutSeconds * 1000)),
            // Never follow redirects: the API does not send 3xx, and a signed URL's redirect
            // target is outside what the signature covers, so following one would send the bytes,
            // headers and all, somewhere unverified.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // Disable 100-continue. curl sends Expect: 100-continue automatically for large bodies,
        // and a good many object-storage endpoints never answer it, costing a wasted second on
        // every upload. An empty value after the colon is curl's convention for "remove this
        // header", not for sending an empty one.
        if (!array_key_exists('expect', array_change_key_case($headers, CASE_LOWER))) {
            $headerLines[] = 'Expect:';
        }
        $options[CURLOPT_HTTPHEADER] = $headerLines;

        if (is_resource($body)) {
            // Streaming upload: a 90 MiB video never enters memory.
            $stat = fstat($body);
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $body;
            $options[CURLOPT_INFILESIZE] = is_array($stat) ? (int) $stat['size'] : -1;
        } elseif (is_string($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // Since PHP 8.0 a curl handle is an object freed when it goes out of scope, curl_close()
        // is a no-op, and it is deprecated from 8.5. It is not called here; the handle is reclaimed
        // along with $handle.

        if ($raw === false || $errorNumber !== 0) {
            // 28 is CURLE_OPERATION_TIMEOUTED. It has to be thrown separately from other
            // transport failures because the two mean opposite things to a caller: a timeout means
            // the request may well have arrived and the outcome is unknown, whereas a refused
            // connection (7) or a failed TLS handshake (35) means it never went out at all - and
            // only the latter is safe to resend without an idempotency key. The literal is used
            // rather than the constant name: the number is stable in libcurl, while the constant
            // has two spellings across PHP versions.
            if ($errorNumber === 28) {
                throw new SpicyTimeoutError(sprintf(
                    'the request exceeded the local %.1fs timeout (curl error 28: %s)',
                    $timeoutSeconds,
                    $errorMessage === '' ? 'unknown' : $errorMessage,
                ));
            }
            // The curl error number goes into the message to tell the remaining failures apart.
            // The URL does not: a signed URL contains credentials.
            throw new SpicyTransportError(sprintf(
                'network request failed (curl error %d: %s)',
                $errorNumber,
                $errorMessage === '' ? 'unknown' : $errorMessage,
            ));
        }

        return new HttpResponse($status, $responseHeaders, is_string($raw) ? $raw : '');
    }
}

/**
 * Stream-wrapper transport for builds without ext-curl.
 *
 * Two ways it differs from {@see CurlTransport}, both of which can take a
 * process or a deployment down quietly:
 *
 *  - the `http://` wrapper has no streaming request body, so a resource body is
 *    read into memory in full. A 90 MiB upload then needs a `memory_limit`
 *    above 90 MiB. Use cURL for uploads whenever ext-curl exists.
 *  - it ignores the `http_proxy` / `https_proxy` environment variables that
 *    libcurl honours. Behind an egress proxy, cURL works and this does not; set
 *    the `proxy` context option below if that is where you are.
 */
final class StreamTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        $body,
        float $timeoutSeconds,
    ): HttpResponse {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
            if ($body === false) {
                throw new SpicyTransportError('could not read the request body stream');
            }
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if (is_string($body)) {
            $headerLines[] = 'Content-Length: ' . strlen($body);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => is_string($body) ? $body : '',
                'timeout' => $timeoutSeconds,
                // Without this, a 4xx or 5xx makes file_get_contents return false and emit a
                // warning, and the error body - code and request_id included - is lost entirely.
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseHeaderLines = [];
        $startedAt = microtime(true);
        // Warnings are suppressed with @ and the return value is used instead: a warning would
        // write the upstream address into the log.
        //
        // fopen plus stream_get_meta_data is used rather than file_get_contents in order to obtain
        // the response headers: the magic local $http_response_header is deprecated from PHP 8.5,
        // and its deprecation notice is emitted at compile time - `php -l` alone produces it, so no
        // runtime branch such as function_exists can hold it back. Switching to
        // http_get_last_response_headers() would cut support down to 8.5 and above.
        $stream = @fopen($url, 'rb', false, $context);
        $raw = false;
        if ($stream !== false) {
            $wrapperData = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            if (is_array($wrapperData)) {
                $responseHeaderLines = $wrapperData;
            }
            $raw = stream_get_contents($stream);
            fclose($stream);
        }
        if ($raw === false && $responseHeaderLines === []) {
            // The stream wrapper reports no error number, so the only usable signal is "no
            // response at all, yet the entire timeout budget was consumed". This is best effort
            // rather than a precise determination, and the cost of being wrong is asymmetric, so
            // borderline cases are counted as timeouts: mistaking a timeout for a connection
            // failure would let a caller believe the request never went out and resend it without
            // a key, whereas the opposite merely costs one automatic retry.
            if (microtime(true) - $startedAt >= $timeoutSeconds) {
                throw new SpicyTimeoutError(sprintf(
                    'the request exceeded the local %.1fs timeout (stream wrapper)',
                    $timeoutSeconds,
                ));
            }
            throw new SpicyTransportError('network request failed (stream wrapper)');
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($responseHeaderLines as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', $line, $matches) === 1) {
                // Take the last status line: through a proxy there may be a 1xx or CONNECT line
                // ahead of it.
                $status = (int) $matches[1];
                $responseHeaders = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return new HttpResponse($status, $responseHeaders, is_string($raw) ? $raw : '');
    }
}

/* -- Client ---------------------------------------------------------------- */

final class SpicyClient
{
    public const API_BASE_URL = 'https://api.spicyapi.ai/api/v1';

    /** Per-API-call ceiling. JSON requests have no reason to take longer. */
    public const REQUEST_TIMEOUT_SECONDS = 30.0;

    /**
     * The direct-to-storage PUT gets its own, much longer ceiling.
     *
     * 30 seconds is right for a JSON call and wrong for bytes: the audio/video
     * limit is 90 MiB, which 30 seconds would demand be pushed at ~25 Mbps.
     * Real uploads would be cut off half-way and report a timeout, which reads
     * like a network fault but is this client hanging up on itself. Ten minutes
     * covers 90 MiB at ~1.2 Mbps.
     */
    public const UPLOAD_TIMEOUT_SECONDS = 600.0;

    /** Local polling deadline. A safety bound for examples, not a platform SLA. */
    public const WAIT_TIMEOUT_SECONDS = 600.0;

    public const WAIT_INTERVAL_SECONDS = 2.0;
    public const MAX_WAIT_INTERVAL_SECONDS = 10.0;

    /**
     * Below this much budget left, {@see self::waitForTerminal()} stops polling.
     *
     * A poll fired with a near-zero timeout is a poll that will time out, and
     * that timeout comes from the request layer, which does not know which task
     * it was polling for. Stopping here instead means the caller gets the wait
     * timeout, which carries the task ID — the one thing needed to reconcile a
     * task that is still running and still billable.
     */
    public const MIN_POLL_BUDGET_SECONDS = 1.0;

    /**
     * Ceiling on the encoded request body, from the `CreateTaskRequest.input`
     * description: "The complete JSON request must fit within 2097152 bytes."
     * Checked locally so an oversized prompt fails with a clear message instead
     * of a 413.
     */
    public const MAX_REQUEST_BYTES = 2097152;

    /**
     * The Composer package this client ships as.
     *
     * The **single source of the version** is the git tag Composer resolved this
     * package to, which Composer records in its own install metadata and
     * {@see self::installedVersion()} reads back. There is deliberately no
     * version constant here and none in composer.json: a second copy of the
     * number is a copy that drifts, and a User-Agent that misreports the version
     * is worse than one that omits it.
     */
    public const PACKAGE_NAME = 'spicyapi/spicyapi';

    /**
     * The product token of the `User-Agent`.
     *
     * Sent bare, with no version, whenever Composer's metadata is not there to
     * read — which is the case for the single-file copy this client is
     * deliberately still usable as.
     */
    public const USER_AGENT_PRODUCT = 'spicyapi-php';

    /** Resolved once per process by {@see self::userAgent()}. */
    private static ?string $userAgent = null;

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly Transport $transport;

    /**
     * The API key is read from the environment and from nowhere else.
     *
     * There is intentionally no way to pass a key as an argument: a key in an
     * argument is a key in a stack trace, in a `var_dump`, and eventually in a
     * commit. If the key lives in a secret manager, export it into the process
     * environment at boot — that is the one place this client looks.
     *
     * Under php-fpm, `getenv()` may be empty while the value is present in
     * `$_SERVER`, so both are checked.
     *
     * @param int   $maxRetries             Automatic retries per call, on top of the first attempt.
     * @param float $retryBaseDelaySeconds  First backoff step; doubles per attempt with jitter.
     * @param float $maxRetryDelaySeconds   Backoff ceiling. A `Retry-After` longer than this is not
     *                                      waited out; the error is returned to the caller instead.
     */
    public function __construct(
        string $apiKeyEnvVar = 'SPICY_API_KEY',
        string $baseUrl = self::API_BASE_URL,
        ?Transport $transport = null,
        private readonly float $requestTimeoutSeconds = self::REQUEST_TIMEOUT_SECONDS,
        private readonly float $uploadTimeoutSeconds = self::UPLOAD_TIMEOUT_SECONDS,
        private readonly int $maxRetries = 2,
        private readonly float $retryBaseDelaySeconds = 0.25,
        private readonly float $maxRetryDelaySeconds = 30.0,
        private readonly ?\Closure $sleeper = null,
    ) {
        $key = getenv($apiKeyEnvVar);
        if (!is_string($key) || trim($key) === '') {
            $key = is_string($_SERVER[$apiKeyEnvVar] ?? null) ? $_SERVER[$apiKeyEnvVar] : '';
        }
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s is not set. Export the API key into the environment; never hard-code it.',
                $apiKeyEnvVar,
            ));
        }
        // A line break would turn this value into a second header injected into the request.
        if (strpbrk($key, "\r\n") !== false) {
            throw new \InvalidArgumentException(sprintf('%s must not contain line breaks', $apiKeyEnvVar));
        }
        $this->apiKey = $key;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->transport = $transport ?? (extension_loaded('curl') ? new CurlTransport() : new StreamTransport());
    }

    /**
     * The API key travels on every call, so the base URL must be HTTPS.
     *
     * Loopback `http://` is the one exception, for a developer pointing this at
     * a local stub. Credentials, queries and fragments in a base URL are
     * rejected rather than silently dropped when paths are appended to it.
     */
    private static function normalizeBaseUrl(string $value): string
    {
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('baseUrl must be an absolute URL');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('baseUrl must not contain credentials');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('baseUrl must not contain a query or fragment');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $isLoopback = $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
        if (strtolower($parts['scheme']) !== 'https' && !($isLoopback && strtolower($parts['scheme']) === 'http')) {
            throw new \InvalidArgumentException(
                'baseUrl must use HTTPS; plain HTTP is allowed only for loopback development',
            );
        }

        return rtrim($value, '/');
    }

    /* -- Catalogue --------------------------------------------------------- */

    /**
     * List every callable model with this account's prices.
     *
     * `GET /api/v1/models`. Returns `{total, items}`. The catalogue is the only
     * contractual source of `model` values for `createTask`; do not hard-code
     * identifiers, and do not read them off a web page.
     *
     * @param string|null $modality image, video, audio or text.
     * @param string|null $task     Exact task, e.g. `text-to-image`. Image editing
     *                              accepts both `edit` and `image-to-image`.
     *
     * @return array<string,mixed>
     */
    public function listModels(
        ?string $modality = null,
        ?string $provider = null,
        ?string $task = null,
        ?string $search = null,
        ?bool $includeSchema = null,
        ?bool $includeExamples = null,
    ): array {
        $suffix = self::queryString([
            'modality' => $modality,
            'provider' => $provider,
            'task' => $task,
            'search' => $search,
            'includeSchema' => $includeSchema === null ? null : ($includeSchema ? '1' : '0'),
            'includeExamples' => $includeExamples === null ? null : ($includeExamples ? '1' : '0'),
        ]);

        return $this->requireArray($this->request('GET', '/models' . $suffix, retryable: true));
    }

    /**
     * Fetch one model, including its input JSON Schema.
     *
     * `GET /api/v1/models/{model}`. Model identifiers contain slashes, so the
     * value is URL-encoded into a single path segment as the contract's
     * `getModel` description instructs.
     *
     * The returned `inputSchema` is what the request `input` is validated
     * against; render forms from it rather than from any static copy.
     *
     * @return array<string,mixed>
     */
    public function getModel(string $model): array
    {
        $encoded = rawurlencode($this->requireIdentifier($model, 'model'));

        return $this->requireArray($this->request('GET', '/models/' . $encoded, retryable: true));
    }

    /* -- Quotes and tasks -------------------------------------------------- */

    /**
     * Price a task without creating it.
     *
     * `POST /api/v1/jobs/quote`. Runs the same validation and admission checks
     * as creation, but reserves no funds and starts no generation. The response
     * is `{quoteId, model, estimatedCost, maxCharge, currency, quantity, unit,
     * expiresAt}`; amounts are decimal **strings** in USD, never floats — see
     * {@see self::compareUsd()}.
     *
     * The quote is signed and bound to this account, this API key and this exact
     * request, and is valid for five minutes. Pass `quoteId` and `expectedCost`
     * to {@see self::createTask()} to be told (business code `40901`) when the
     * price moved, instead of silently paying the new one.
     *
     * A quote reserves no capacity and guarantees no future availability.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function quote(string $model, array $input, ?string $callBackUrl = null): array
    {
        $body = ['model' => $this->requireIdentifier($model, 'model'), 'input' => self::jsonObject($input)];
        if ($callBackUrl !== null) {
            $body['callBackUrl'] = $callBackUrl;
        }

        // A quote moves no money and creates no task, so retrying is safe.
        return $this->requireArray($this->request('POST', '/jobs/quote', body: $body, retryable: true));
    }

    /**
     * Create an asynchronous generation task.
     *
     * `POST /api/v1/jobs/createTask`. HTTP 202 means accepted, not finished;
     * the response is `{taskId, state, estimatedCost, deadlineAt}` with `state`
     * = `queued`. `estimatedCost` is the amount **held**, and the final charge
     * is capped at it.
     *
     * Always pass an `idempotencyKey`. Two things depend on it:
     *
     *  - the server deduplicates for 24 hours per API key, so a replay returns
     *    the original task rather than creating and billing a second one;
     *  - it is the only thing that makes this call safe for this client to
     *    retry automatically. Without a key, a retry after a timeout can create
     *    a second paid task, so `retryable` below is false and the error is
     *    handed straight back.
     *
     * Reusing a key with different request semantics returns business code `409`,
     * and a sibling API key replaying the key gets `409` without the original task
     * ID. That is a different code from `40901`, which is only ever the price
     * moving — see {@see SpicyApiError::guidance()}, where the two call for
     * opposite fixes.
     *
     * @param array<string,mixed> $input           Validated against the model's `inputSchema`.
     *                                             Image fields take public HTTPS URLs, committed
     *                                             `spicy://f/fil_...` URIs, or Data URIs.
     * @param string|null         $callBackUrl     Public HTTPS endpoint for terminal delivery.
     *                                             `http://` is rejected: the delivery carries the
     *                                             prompt and signed result links.
     * @param string|null         $quoteId         From {@see self::quote()}.
     * @param string|null         $expectedCost    Decimal USD string from the same quote.
     * @param int|null            $retentionSeconds Shorten retention for this one task via
     *                                             `X-Spicy-Retention`. The effective value is
     *                                             `min(header, account, platform)` — a request can
     *                                             only shorten retention, never extend it — and it
     *                                             is reported back in the task's `retention`.
     * @param int|null            $waitSeconds     Hold the connection open for up to this many
     *                                             seconds so the first poll is unnecessary.
     *                                             **It does not guarantee a finished task.**
     *
     * `$waitSeconds` changes the shape of what comes back, and that is the whole
     * trap. Reaching a terminal state inside the budget returns the full task
     * record; running out returns the ordinary accepted response. The two differ
     * only in `state`, so branch on {@see self::isTerminal()} and never on the
     * fact that the parameter was passed:
     *
     *     $task = $client->createTask(..., waitSeconds: 30);
     *     if (!SpicyClient::isTerminal($task)) {
     *         $task = $client->waitForTerminal($task['taskId']);
     *     }
     *
     * Disconnecting stops the wait and nothing else: the task keeps running and
     * is billed as usual.
     *
     * @return array<string,mixed>
     */
    public function createTask(
        string $model,
        array $input,
        ?string $idempotencyKey = null,
        ?string $callBackUrl = null,
        ?string $quoteId = null,
        ?string $expectedCost = null,
        ?int $retentionSeconds = null,
        ?int $waitSeconds = null,
    ): array {
        $body = ['model' => $this->requireIdentifier($model, 'model'), 'input' => self::jsonObject($input)];
        if ($callBackUrl !== null) {
            $body['callBackUrl'] = $callBackUrl;
        }
        if ($quoteId !== null) {
            $body['quoteId'] = $quoteId;
        }
        if ($expectedCost !== null) {
            $body['expectedCost'] = $expectedCost;
        }

        $headers = [];
        if ($idempotencyKey !== null) {
            // Contract: 1 to 128 characters, retained per account for 24 hours.
            $key = $this->requireIdentifier($idempotencyKey, 'idempotencyKey');
            if (strlen($key) > 128) {
                throw new \InvalidArgumentException('idempotencyKey must be at most 128 characters');
            }
            $headers['Idempotency-Key'] = $key;
        }
        if ($retentionSeconds !== null) {
            if ($retentionSeconds < 0) {
                throw new \InvalidArgumentException('retentionSeconds must not be negative');
            }
            // No local ceiling: only the server knows the platform limit, and copying it into
            // the client buries a constant that will expire. Anything over it is clamped
            // server-side, and the effective value is read back from the task record's retention.
            $headers['X-Spicy-Retention'] = (string) $retentionSeconds;
        }

        $path = '/jobs/createTask';
        $timeoutSeconds = null;
        if ($waitSeconds !== null) {
            if ($waitSeconds < 0) {
                throw new \InvalidArgumentException('waitSeconds must not be negative');
            }
            // Sent verbatim, with no local clamping. The server clamps anything over 60 down to
            // 60 and ignores non-positive integers rather than rejecting them - the contract's
            // reasoning being that a typo in an optimisation parameter should not fail a
            // generation that would otherwise have succeeded. Copying the ceiling here would bury
            // a constant that will expire: once it is relaxed, this would reject a value that
            // already works. Only negatives are rejected, being an unambiguous caller error rather
            // than a platform limit.
            $path .= '?' . http_build_query(['wait' => $waitSeconds]);
            // The local request timeout has to accommodate the server-side wait. Without this,
            // the default 30-second request timeout cuts the call off locally while the server is
            // still waiting - which looks like "wait does nothing" when the real cause is two
            // timeouts colliding. Ten seconds of headroom covers task creation itself plus the
            // round trip.
            $timeoutSeconds = max($this->requestTimeoutSeconds, (float) $waitSeconds + 10.0);
        }

        return $this->requireArray($this->request(
            'POST',
            $path,
            body: $body,
            headers: $headers,
            // Only the idempotency key decides this. Neither the retention header nor wait makes
            // a resend safe - and conversely, with a key present, resending after a wait timeout
            // still returns the same task.
            retryable: $idempotencyKey !== null,
            timeoutSeconds: $timeoutSeconds,
        ));
    }

    /**
     * Read one task.
     *
     * `GET /api/v1/jobs/recordInfo?taskId=...`. Terminal and non-terminal states
     * share one shape. A task belonging to another account or another API key is
     * reported as 404, indistinguishable from one that never existed.
     *
     * @param float|null $timeoutSeconds Ceiling for this one call including its
     *                                   automatic retries. Defaults to the
     *                                   client's request timeout.
     *
     * @return array<string,mixed>
     */
    public function getTask(string $taskId, ?float $timeoutSeconds = null): array
    {
        $query = http_build_query(['taskId' => $this->requireIdentifier($taskId, 'taskId')]);

        return $this->requireArray($this->request(
            'GET',
            '/jobs/recordInfo?' . $query,
            retryable: true,
            timeoutSeconds: $timeoutSeconds,
        ));
    }

    /**
     * Poll until the task is terminal, or the local deadline elapses.
     *
     * Backs off from two seconds by 1.5x with jitter, capped at ten seconds.
     *
     * Two behaviours worth knowing:
     *
     *  - a `succeeded` task whose assets are still `pending` keeps being polled.
     *    Returning it would hand the caller a result with no usable URL.
     *  - the timeout throws {@see SpicyTimeoutError}, which does **not** mean
     *    the generation failed. The task is still running and still billable.
     *    Persist the task ID and reconcile later, or use a webhook.
     *
     * `$timeoutSeconds` is the budget for the whole wait, and each poll is held
     * inside what is left of it. Two rules follow, and both matter:
     *
     *  - **one poll timing out is not this wait failing.** While budget remains,
     *    polling continues. A single slow response must not end a ten-minute
     *    wait on a task that is still running and still being billed.
     *  - **the exception thrown when the budget really does run out carries the
     *    task ID.** That identifier is the only way to reconcile the task
     *    afterwards, so it must not be lost to a lower layer's timeout.
     *
     * A budget under {@see self::MIN_POLL_BUDGET_SECONDS} therefore throws
     * without polling at all: a request given a near-zero deadline only
     * produces a timeout from the request layer, which does not know the task
     * ID. Use `getTask()` directly for a one-shot look at a task.
     *
     * For anything longer than a request-response cycle, prefer `callBackUrl`
     * plus {@see SpicyWebhook}: a PHP-FPM worker blocked on a video for four
     * minutes is a worker that is not serving traffic.
     *
     * @param callable(array<string,mixed>):void|null $onUpdate Called with each poll result.
     *
     * @return array<string,mixed>
     */
    public function waitForTerminal(
        string $taskId,
        ?float $timeoutSeconds = null,
        ?callable $onUpdate = null,
    ): array {
        $id = $this->requireIdentifier($taskId, 'taskId');
        $budget = $timeoutSeconds ?? self::WAIT_TIMEOUT_SECONDS;
        $deadline = $this->now() + $budget;
        $interval = self::WAIT_INTERVAL_SECONDS;

        while ($this->now() < $deadline) {
            $remaining = $deadline - $this->now();
            // When the remaining budget cannot fund a meaningful request, break out and throw the
            // timeout below - the one that carries the taskId. Without this floor the final lap
            // would issue a request with a near-zero timeout, and that attempt is all but
            // guaranteed to time out - throwing a plain "request timed out" with no taskId, losing
            // the caller the task id at the exact moment they need it most.
            if ($remaining < self::MIN_POLL_BUDGET_SECONDS) {
                break;
            }

            $task = null;
            try {
                // Each attempt's timeout is clamped to the remaining budget. Without that, a
                // 30-second request timeout plus two automatic retries can make
                // waitForTerminal($id, 5.0) block for over ninety seconds before it throws.
                $task = $this->getTask($id, min($this->requestTimeoutSeconds, $remaining));
            } catch (SpicyTimeoutError) {
                // One polling attempt timing out is not this wait failing: while budget remains,
                // keep polling. Letting it propagate gets two things wrong at once - it loses the
                // taskId (this timeout comes from request(), which does not know which task it is
                // polling for), and it promotes one piece of network turbulence into the end of
                // the whole wait. When the budget genuinely runs out, the floor at the top of the
                // loop breaks out and throws the one below, which carries the taskId.
            }

            if ($task !== null) {
                if ($onUpdate !== null) {
                    $onUpdate($task);
                }

                $raw = is_string($task['state'] ?? null) ? $task['state'] : '';
                $state = TaskState::tryFrom($raw);
                if ($state === null) {
                    // An unknown state is not treated as terminal: calling it finished would
                    // silently discard a task that is still running.
                    throw new SpicyApiError(sprintf('unknown task state "%s"', $raw), status: 200, businessCode: 200);
                }
                if ($state->isTerminal() && !self::hasPendingAssets($task)) {
                    return $task;
                }
            }

            $delay = min($this->jitter($interval), max(0.0, $deadline - $this->now()));
            if ($delay > 0) {
                $this->sleep($delay);
            }
            $interval = min($interval * 1.5, self::MAX_WAIT_INTERVAL_SECONDS);
        }

        throw new SpicyTimeoutError(
            sprintf(
                'task %s did not reach a terminal state within the local %.0fs deadline; '
                . 'its remote state is unknown and it may still be running',
                $id,
                $budget,
            ),
            taskId: $id,
        );
    }

    /**
     * Create a new task from a failed or expired one.
     *
     * `POST /api/v1/jobs/retry`. The source task is never modified; schema,
     * price, permissions, balance and availability are all evaluated again, so
     * this can cost a different amount than the original did — and it is a new
     * charge, not a free redo.
     *
     * The optional `Idempotency-Key` is scoped to the source task and the retry
     * action, and, as in {@see self::createTask()}, is what makes automatic
     * retry of this call safe.
     *
     * @return array<string,mixed> `CreateTaskResponse` plus `sourceTaskId`.
     */
    public function retryTask(string $taskId, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $this->requireIdentifier($idempotencyKey, 'idempotencyKey');
        }

        return $this->requireArray($this->request(
            'POST',
            '/jobs/retry',
            body: ['taskId' => $this->requireIdentifier($taskId, 'taskId')],
            headers: $headers,
            retryable: $idempotencyKey !== null,
        ));
    }

    /**
     * Destroy a finished task's stored content.
     *
     * `POST /api/v1/jobs/purge`. Removes the generated media, the result payload
     * and the stored request text including the prompt.
     *
     * **The billing record is not touched.** The ledger, the charged amount, the
     * model, the state, the timestamps and the request_id all survive, and the
     * response repeats that as `billingRetained: true`. What is destroyed is the
     * content, not the spend.
     *
     * Two details that shape the calling code:
     *
     *  - this endpoint does **not** read `Idempotency-Key`. The `taskId` is the
     *    idempotency key, so a repeat returns 200 with the original `purgedAt`
     *    and a retry after a timeout is always safe — which is why this call is
     *    marked retryable without any key.
     *  - a queued or running task returns 400. An accepted generation cannot be
     *    stopped; wait for a terminal state, then purge.
     *
     * `mediaDeletionPending` may be true while `contentState` is already
     * `purged`: the objects are swept in the background within about a minute.
     *
     * @return array<string,mixed>
     */
    public function purgeTask(string $taskId): array
    {
        return $this->requireArray($this->request(
            'POST',
            '/jobs/purge',
            body: ['taskId' => $this->requireIdentifier($taskId, 'taskId')],
            retryable: true,
        ));
    }

    /* -- Account and history ----------------------------------------------- */

    /**
     * This API key's net balance, funding sources and held funds.
     *
     * `GET /api/v1/chat/credit`. Returns `{available, held, total}` plus an
     * optional `funding` overview, all as decimal USD **strings** — compare them
     * with {@see self::compareUsd()}, never by casting to float.
     *
     * `held` is money reserved by tasks that have not settled yet. It is not
     * spent and not available, which is why `available` and `total` differ, and
     * why a balance that looks unchanged after a submission is not evidence that
     * nothing was reserved.
     *
     * Unused promotional credit is separate from wallet funds and may be scoped
     * to particular models, so a non-zero `available` does not by itself promise
     * that any given request can be paid for.
     *
     * @return array<string,mixed>
     */
    public function getBalance(): array
    {
        return $this->requireArray($this->request('GET', '/chat/credit', retryable: true));
    }

    /**
     * Settled spend and call counts for this API key over `[from, to)`.
     *
     * `GET /api/v1/usage?from&to`. Dates are UTC calendar days forming a
     * half-open interval of at most 92 days; `to` defaults to tomorrow in UTC
     * and an omitted `from` to seven days before `to`.
     *
     * **For reconciliation, not for progress.** This endpoint has its own
     * account-wide budget of 30 requests per minute shared by every key, and
     * that limiter fails closed — polling it can lock every key on the account
     * out of its own reporting. Follow a task with {@see self::getTask()}.
     *
     * Two things the numbers do not say. Spend sums only **settled** charges,
     * never pending holds, so a figure read today can still move when a task
     * settles late. And the scope is this API key alone: other keys on the same
     * account, and generations started in the web console, are not counted.
     *
     * @param string|null $from Inclusive UTC date, `YYYY-MM-DD`.
     * @param string|null $to   Exclusive UTC date, `YYYY-MM-DD`.
     *
     * @return array<string,mixed>
     */
    public function getUsage(?string $from = null, ?string $to = null): array
    {
        $suffix = self::queryString([
            'from' => self::requireDate($from, 'from'),
            'to' => self::requireDate($to, 'to'),
        ]);

        return $this->requireArray($this->request('GET', '/usage' . $suffix, retryable: true));
    }

    /**
     * One page of this API key's task history, newest first.
     *
     * `GET /api/v1/jobs`. Returns `{items, hasMore, nextCursor}`; `nextCursor`
     * is present only while `hasMore` is true. Metadata only — no input, no
     * output, no signed media URL. Use {@see self::getTask()} for a result.
     *
     * Scope is this API key: tasks from sibling keys, and generations started in
     * the web console, are not listed.
     *
     * **Keep every filter identical while paginating and change only the
     * cursor.** Pages read live state rather than a frozen snapshot, so a task
     * can move between states mid-walk. {@see self::iterTasks()} does this for
     * you and is the safer way to consume more than one page.
     *
     * @param string|null $from   Inclusive UTC date, `YYYY-MM-DD`.
     * @param string|null $to     Exclusive UTC date, `YYYY-MM-DD`.
     * @param string|null $state  queued, running, succeeded, failed, canceled, expired.
     * @param string|null $model  Exact catalog identifier or a declared alias.
     * @param int|null    $limit  Page size. Only a non-positive value is refused here;
     *                            the ceiling belongs to the server.
     * @param string|null $cursor The previous page's `nextCursor`, passed through
     *                            unchanged. It is opaque; never build one.
     *
     * @return array<string,mixed>
     */
    public function listTasks(
        ?string $from = null,
        ?string $to = null,
        ?string $state = null,
        ?string $model = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('limit must be a positive integer');
        }
        // No local ceiling, the same stance as X-Spicy-Retention: the day the platform relaxes
        // this from 100 to 200, a local check would reject a value that already works, and that
        // failure emits no signal at all - the user sees the SDK refuse while the server plainly
        // accepts it.
        $suffix = self::queryString([
            'from' => self::requireDate($from, 'from'),
            'to' => self::requireDate($to, 'to'),
            'state' => $state,
            'model' => $model,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->requireArray($this->request('GET', '/jobs' . $suffix, retryable: true));
    }

    /**
     * Walk every page of {@see self::listTasks()}, yielding one task at a time.
     *
     * Every filter is repeated unchanged on each request and only the cursor
     * moves, which is what the contract requires: changing a filter mid-walk
     * makes the cursor meaningless.
     *
     * The walk stops when `hasMore` is false **or** `nextCursor` is missing or
     * empty. Both are checked on purpose. Trusting `hasMore` alone means that a
     * server sending `hasMore: true` with no usable cursor turns into an endless
     * loop of paid API calls that, from the outside, looks like the program
     * simply hanging.
     *
     * @return \Generator<int, array<string,mixed>>
     */
    public function iterTasks(
        ?string $from = null,
        ?string $to = null,
        ?string $state = null,
        ?string $model = null,
        ?int $limit = null,
    ): \Generator {
        $cursor = null;
        while (true) {
            $page = $this->listTasks(
                from: $from,
                to: $to,
                state: $state,
                model: $model,
                limit: $limit,
                cursor: $cursor,
            );

            foreach ($page['items'] ?? [] as $task) {
                if (is_array($task)) {
                    yield $task;
                }
            }

            $next = $page['nextCursor'] ?? null;
            if (($page['hasMore'] ?? false) !== true || !is_string($next) || $next === '') {
                return;
            }
            $cursor = $next;
        }
    }

    /* -- The three upload steps -------------------------------------------- */

    /**
     * Step 1 of 3: ask for a presigned upload ticket.
     *
     * `POST /api/v1/common/upload-url`, body `{contentType, bytes}`. The server
     * picks the object key. The response is `{fileId, key, uploadUrl, method,
     * headers, expiresAt, maxBytes}`.
     *
     * `bytes` must be the exact length that will be PUT. The commit step
     * compares it, and a mismatch is business code `40003`.
     *
     * Not marked retryable: each call issues a new ticket, so a blind retry
     * quietly leaves an abandoned one behind.
     *
     * @return array<string,mixed>
     */
    public function createUploadUrl(UploadContentType $contentType, int $bytes): array
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('bytes must be at least 1');
        }
        if ($bytes > $contentType->maxBytes()) {
            throw new SpicyUploadError(sprintf(
                '%s uploads are limited to %d bytes; this file is %d',
                $contentType->value,
                $contentType->maxBytes(),
                $bytes,
            ), status: 413);
        }

        return $this->requireArray($this->request(
            'POST',
            '/common/upload-url',
            body: ['contentType' => $contentType->value, 'bytes' => $bytes],
        ));
    }

    /**
     * Step 3 of 3: commit the uploaded object.
     *
     * `POST /api/v1/files/{fileId}/commit`, no request body. The server compares
     * the stored byte count and media type against the ticket, checks the file
     * signature, hashes it, and copies it to an immutable private key.
     *
     * The returned `uri` — `spicy://f/fil_...` — is the value to put in a model
     * input field. The ticket's `key` is only a compatibility alias and is not
     * usable before this call succeeds.
     *
     * Business code `40003` here means the bytes did not match the ticket;
     * request a new ticket and upload again rather than retrying the commit.
     * Repeating a successful commit is idempotent, so this call is retryable.
     *
     * @return array<string,mixed>
     */
    public function commitUploadedFile(string $fileId): array
    {
        $id = rawurlencode($this->requireIdentifier($fileId, 'fileId'));

        return $this->requireArray($this->request('POST', '/files/' . $id . '/commit', retryable: true));
    }

    /**
     * All three upload steps for a local file, streaming the bytes.
     *
     * With {@see CurlTransport} the file is never loaded into memory, so a
     * 90 MiB video works under a default `memory_limit`.
     *
     * @return array<string,mixed> The committed file; `['uri']` goes into model input.
     */
    public function uploadFile(string $path, ?UploadContentType $contentType = null): array
    {
        $resolved = $contentType ?? UploadContentType::forPath($path);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size < 1) {
            throw new SpicyUploadError(sprintf('"%s" is not a readable, non-empty file', $path));
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new SpicyUploadError(sprintf('could not open "%s" for reading', $path));
        }

        try {
            $ticket = $this->createUploadUrl($resolved, $size);
            $this->putToStorage($ticket, $handle, $size);
        } finally {
            fclose($handle);
        }

        return $this->commitUploadedFile((string) $ticket['fileId']);
    }

    /**
     * All three upload steps for bytes already in memory.
     *
     * @return array<string,mixed>
     */
    public function uploadBytes(string $data, UploadContentType $contentType): array
    {
        $length = strlen($data);
        $ticket = $this->createUploadUrl($contentType, $length);
        $this->putToStorage($ticket, $data, $length);

        return $this->commitUploadedFile((string) $ticket['fileId']);
    }

    /**
     * Step 2 of 3: PUT the bytes straight to object storage.
     *
     * Three rules, all of which have a failure mode attached:
     *
     *  1. **Forward the ticket's `headers` one for one, unchanged.** They are
     *     part of what the URL's signature covers. Dropping, renaming or
     *     re-casing one produces a 403 from the storage host that says nothing
     *     about which header was wrong.
     *  2. **Do not send the SpicyAPI `Authorization` header.** The URL is
     *     already signed; the storage host is a different origin, and attaching
     *     the key hands it to a third party for no benefit. This is why the PUT
     *     goes through the raw transport instead of {@see self::request()}.
     *  3. **No automatic retry.** The body stream has been consumed by the time
     *     a failure is visible; retrying would upload zero bytes and turn a
     *     clear storage error into `40003` at commit time. Retry the whole
     *     three-step flow from a fresh ticket instead.
     *
     * @param array<string,mixed> $ticket
     * @param string|resource     $body
     */
    private function putToStorage(array $ticket, $body, int $length): void
    {
        $maxBytes = is_int($ticket['maxBytes'] ?? null) ? $ticket['maxBytes'] : 0;
        if ($maxBytes > 0 && $length > $maxBytes) {
            throw new SpicyUploadError(
                sprintf('file is %d bytes; the ticket allows %d', $length, $maxBytes),
                status: 413,
            );
        }

        $uploadUrl = is_string($ticket['uploadUrl'] ?? null) ? $ticket['uploadUrl'] : '';
        if ($uploadUrl === '') {
            throw new SpicyUploadError('the upload ticket did not contain an uploadUrl');
        }

        $headers = [];
        foreach ((array) ($ticket['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        $method = strtoupper(is_string($ticket['method'] ?? null) ? $ticket['method'] : 'PUT');

        $response = $this->transport->send($method, $uploadUrl, $headers, $body, $this->uploadTimeoutSeconds);
        if ($response->status < 200 || $response->status >= 300) {
            // The storage side's body is its own XML and is not part of the platform error
            // contract, so it is not passed on.
            throw new SpicyUploadError(
                sprintf('the presigned upload failed with HTTP %d', $response->status),
                status: $response->status,
            );
        }
    }

    /**
     * Mint a fresh download URL for a finished task's output.
     *
     * `POST /api/v1/common/download-url`, body `{taskId, key?}`. Leave `key`
     * empty to select the first output.
     *
     * Usually unnecessary: a ready asset in `output.assets` already carries a
     * usable `url`, and polling the task again refreshes an expired one. This
     * endpoint is for the case where a link was stored and outlived its ~20
     * minute validity while the result itself is still within retention.
     *
     * The URL it returns is a bearer grant. Fetch it with no Authorization
     * header, and do not log it.
     *
     * @return array<string,mixed> `{key, url, expiresAt}`
     */
    public function createDownloadUrl(string $taskId, ?string $key = null): array
    {
        $body = ['taskId' => $this->requireIdentifier($taskId, 'taskId')];
        if ($key !== null) {
            $body['key'] = $key;
        }

        return $this->requireArray($this->request('POST', '/common/download-url', body: $body));
    }

    /* -- Reading outputs ---------------------------------------------------- */

    /**
     * Whether this task has reached a state it will never leave.
     *
     * The judgement to branch on after {@see self::createTask()} with
     * `waitSeconds`: what comes back is **either** the finished record or the
     * ordinary accepted response, and the two differ only in `state`. Assuming
     * the former because the parameter was passed carries a still-running task
     * silently into code written for a finished one.
     *
     * An unknown state reads as false. A state this client has never heard of is
     * not one it can call final, and treating it as terminal would drop a task
     * that is still running and still being billed.
     *
     * Terminal is not the same as ready: a `succeeded` task can still have
     * assets that have not landed. {@see self::waitForTerminal()} waits for both,
     * and {@see self::readyAssets()} filters to the ones with a usable URL.
     *
     * @param array<string,mixed> $task
     */
    public static function isTerminal(array $task): bool
    {
        $state = $task['state'] ?? null;

        return is_string($state) && (TaskState::tryFrom($state)?->isTerminal() ?? false);
    }

    /**
     * The text answer of a task, when it has one.
     *
     * **Not every successful task produces a file.** `TaskOutput` has an
     * optional `text` and an optional `assets` array, and some endpoints — a
     * transcription, for instance — answer purely in `text` and carry no
     * `assets` key at all. Code that reaches straight for `output.assets[0]`
     * throws on those. Check both.
     *
     * Null means the field was absent. An empty string means the field was
     * there and empty, which is a different fact and is returned as such — the
     * other clients for this API do the same, and folding the two together
     * would make a task that answered with nothing indistinguishable from one
     * that answered with files.
     *
     * A speech-to-text task that reports timing also carries
     * `output.transcript` — the detected language, per-word `start` / `end`
     * seconds and, for multichannel audio, one entry per channel. It is part of
     * the returned array as delivered; this method returns only the plain text.
     *
     * @param array<string,mixed> $task
     */
    public static function outputText(array $task): ?string
    {
        $output = $task['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }
        $text = $output['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * The asset list of a task, always an array — empty when there is none.
     *
     * Assets may be `pending` (still being stored) or `unavailable` (gone), and
     * both of those lack a `url`. {@see self::readyAssets()} filters them out.
     *
     * Order matters on a layer decomposition: the first asset is the base image
     * and each later one is a transparent layer whose `layer` object holds its
     * `zIndex` and, when the model reports it, the `boundingBox` that places it
     * on the base. Stack by ascending `zIndex` to rebuild the picture.
     *
     * @param array<string,mixed> $task
     *
     * @return list<array<string,mixed>>
     */
    public static function outputAssets(array $task): array
    {
        $output = $task['output'] ?? null;
        if (!is_array($output) || !is_array($output['assets'] ?? null)) {
            return [];
        }

        return array_values(array_filter($output['assets'], 'is_array'));
    }

    /**
     * Assets that have a usable URL right now.
     *
     * The URLs are first-party signed GETs, normally valid for about 20 minutes
     * and never beyond the result retention window. They are bearer grants:
     * fetch them without the API key, and do not put them in logs or emails.
     *
     * @param array<string,mixed> $task
     *
     * @return list<array<string,mixed>>
     */
    public static function readyAssets(array $task): array
    {
        return array_values(array_filter(
            self::outputAssets($task),
            static fn (array $asset): bool => is_string($asset['url'] ?? null)
                && ($asset['pending'] ?? false) !== true
                && ($asset['unavailable'] ?? false) !== true,
        ));
    }

    /** True when the task succeeded but at least one asset has not landed yet. */
    private static function hasPendingAssets(array $task): bool
    {
        if (($task['state'] ?? null) !== TaskState::Succeeded->value) {
            return false;
        }
        foreach (self::outputAssets($task) as $asset) {
            if (($asset['pending'] ?? false) === true && ($asset['unavailable'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /* -- Helpers ------------------------------------------------------------ */

    /**
     * A random idempotency key.
     *
     * Random is right for "submit this once" and wrong for "submit this order
     * once": if the key is regenerated on retry it deduplicates nothing. Derive
     * it from something stable in your own domain — an order ID, a row ID —
     * whenever one exists.
     */
    public static function idempotencyKey(string $prefix = 'spicy'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    /**
     * Compare two USD amounts from the API.
     *
     * Every amount in this API is a decimal string, deliberately: `estimatedCost`
     * carries up to nine decimal places, and `(float) '0.000000001'` is already
     * lossy. Casting them to float to compare or sum is how a billing
     * reconciliation ends up off by a cent.
     *
     * Uses ext-bcmath when present and falls back to a string comparison that is
     * exact for the API's fixed `^-?[0-9]+(\.[0-9]+)?$` shape.
     *
     * @return int -1, 0 or 1, like the spaceship operator.
     */
    public static function compareUsd(string $left, string $right): int
    {
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 12);
        }

        $normalize = static function (string $value): array {
            $negative = str_starts_with($value, '-');
            $digits = ltrim($value, '+-');
            [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

            return [$negative, ltrim($whole, '0'), rtrim($fraction, '0')];
        };

        [$leftNegative, $leftWhole, $leftFraction] = $normalize($left);
        [$rightNegative, $rightWhole, $rightFraction] = $normalize($right);
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $sign = $leftNegative ? -1 : 1;
        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return strlen($leftWhole) < strlen($rightWhole) ? -$sign : $sign;
        }
        if ($leftWhole !== $rightWhole) {
            return ($leftWhole < $rightWhole ? -1 : 1) * $sign;
        }

        $width = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $width, '0');
        $rightFraction = str_pad($rightFraction, $width, '0');
        if ($leftFraction === $rightFraction) {
            return 0;
        }

        return ($leftFraction < $rightFraction ? -1 : 1) * $sign;
    }

    /* -- Request core -------------------------------------------------------- */

    /**
     * The `User-Agent` this client sends, resolved once per process.
     *
     * `spicyapi-php/1.4.0` when Composer installed the package, and a bare
     * `spicyapi-php` when it did not. The version is never written down twice:
     * it is read from the install metadata Composer derives from the git tag,
     * so it either reports the release the caller actually has or says nothing.
     */
    private static function userAgent(): string
    {
        if (self::$userAgent !== null) {
            return self::$userAgent;
        }
        $version = self::installedVersion();

        return self::$userAgent = $version === null
            ? self::USER_AGENT_PRODUCT
            : self::USER_AGENT_PRODUCT . '/' . $version;
    }

    /**
     * This package's installed version, straight from Composer, or null.
     *
     * Null covers every case where there is no honest answer: no Composer
     * autoloader at all, a single file copied out of the package, or a path
     * repository that carries no version. The caller then sends the product
     * token alone rather than an invented number.
     */
    private static function installedVersion(): ?string
    {
        try {
            if (!class_exists(\Composer\InstalledVersions::class)) {
                return null;
            }
            if (!\Composer\InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
                return null;
            }
            $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($version) || $version === '') {
            return null;
        }
        // Header fields carry safe characters only: a version string derived on a dev branch may
        // contain slashes, spaces or even line breaks, and a line break here means a second header
        // injected into the request.
        $safe = preg_replace('/[^A-Za-z0-9._+-]/', '-', $version);

        return is_string($safe) && $safe !== '' ? $safe : null;
    }

    /**
     * One authenticated API call, with retries and envelope unwrapping.
     *
     * Every JSON response uses `{code, msg, data, request_id}`. A successful
     * HTTP response has `code: 200`; asynchronous success or failure is carried
     * by `data.state`, not by the envelope. So both the status **and** the code
     * are checked, and only `data` is handed back.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string>     $headers
     * @param bool                     $retryable Whether repeating this exact call is safe.
     *                                            Never true for a call that can spend money twice.
     * @param float|null               $timeoutSeconds Ceiling for the whole call, **retries
     *                                            included**. Without it, `maxRetries` attempts of
     *                                            `requestTimeoutSeconds` each stack up: a caller
     *                                            asking for a 5 second bound would wait 91.
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
        bool $retryable = false,
        ?float $timeoutSeconds = null,
    ): mixed {
        $requestHeaders = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => self::userAgent(),
        ];
        if ($body !== null) {
            $requestHeaders['Content-Type'] = 'application/json';
        }
        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }

        $encoded = null;
        if ($body !== null) {
            // JSON_THROW_ON_ERROR is not optional: without it, a prompt containing invalid UTF-8
            // makes json_encode return false silently, so an empty body goes out and an
            // inexplicable 400 comes back. JSON_UNESCAPED_UNICODE keeps the prompt as written and
            // costs fewer bytes.
            $encoded = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if (strlen($encoded) > self::MAX_REQUEST_BYTES) {
                throw new SpicyApiError(sprintf(
                    'the encoded request is %d bytes; the limit is %d. Upload large media and '
                    . 'reference its spicy:// URI instead of inlining it.',
                    strlen($encoded),
                    self::MAX_REQUEST_BYTES,
                ), status: 413, businessCode: 413);
            }
        }

        $url = $this->baseUrl . $path;
        $lastError = null;
        $budget = $timeoutSeconds ?? $this->requestTimeoutSeconds;
        $deadline = $this->now() + $budget;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0) {
                throw new SpicyTimeoutError(sprintf(
                    'the request exceeded the local %.1fs timeout; whether it reached the '
                    . 'server is unknown',
                    $budget,
                ));
            }
            try {
                $response = $this->transport->send(
                    $method,
                    $url,
                    $requestHeaders,
                    $encoded,
                    // Every attempt is clamped to the remaining budget, or retries would multiply
                    // the caller's ceiling by the number of attempts. requestTimeoutSeconds is
                    // deliberately not applied again here: a budget the caller passed explicitly
                    // may be longer (createTask's wait is), and clamping once more would quietly
                    // fold that parameter back to 30 seconds, which looks like "wait does nothing".
                    // Whether to bound a single attempt is decided by the call site that
                    // understands the semantics - waitForTerminal clamps at its end.
                    $remaining,
                );
            } catch (SpicyTimeoutError $error) {
                // Timeouts are caught separately from transport failures: a timeout means the
                // request may have arrived and the outcome is unknown, so the decision still rests
                // solely on $retryable. The type must propagate unchanged - waitForTerminal relies
                // on it to tell "one polling attempt timed out" from "the whole wait failed".
                if (!$retryable || $attempt === $this->maxRetries) {
                    throw $error;
                }
                $lastError = $error;
                $this->sleep(min($this->retryDelay($attempt, null), max(0.0, $deadline - $this->now())));
                continue;
            } catch (SpicyTransportError $error) {
                // A transport failure yields no response, so whether the request reached the
                // server is unknown. Only calls declared retryable are resent.
                if (!$retryable || $attempt === $this->maxRetries) {
                    throw $error;
                }
                $lastError = $error;
                $this->sleep(min($this->retryDelay($attempt, null), max(0.0, $deadline - $this->now())));
                continue;
            }

            $decoded = json_decode($response->body, true);
            $error = self::envelopeError($response, $decoded);
            if ($error === null) {
                /** @var array<string,mixed> $decoded */
                return self::envelopeData($response, $decoded);
            }

            $canRetry = $retryable
                && $attempt < $this->maxRetries
                && in_array($response->status, SpicyApiError::RETRYABLE_STATUSES, true);
            if (!$canRetry) {
                throw $error;
            }
            // When Retry-After exceeds our own waiting ceiling, do not sit it out: hand the error
            // straight back so the caller can schedule it themselves, rather than holding a PHP
            // process asleep.
            if ($error->retryAfterSeconds !== null && $error->retryAfterSeconds > $this->maxRetryDelaySeconds) {
                throw $error;
            }
            $lastError = $error;
            $this->sleep(min(
                $this->retryDelay($attempt, $error->retryAfterSeconds),
                max(0.0, $deadline - $this->now()),
            ));
        }

        throw $lastError ?? new SpicyApiError('the request failed after all retries');
    }

    /**
     * Null when the response is a success envelope; otherwise the error to throw.
     *
     * @param mixed $decoded The body decoded once, and passed in rather than
     *                       decoded again: a model list with schemas is large
     *                       enough that parsing it twice is measurable.
     */
    private static function envelopeError(HttpResponse $response, mixed $decoded): ?SpicyApiError
    {
        $isObject = is_array($decoded) && !array_is_list($decoded);

        $code = $isObject && is_int($decoded['code'] ?? null) ? $decoded['code'] : null;
        $requestId = $isObject && is_string($decoded['request_id'] ?? null) ? $decoded['request_id'] : '';
        $httpOk = $response->status >= 200 && $response->status < 300;

        if ($httpOk && $code === 200) {
            return null;
        }
        if (!$isObject) {
            return new SpicyApiError(
                sprintf('the response was not a JSON object (HTTP %d)', $response->status),
                status: $response->status,
            );
        }

        $message = is_string($decoded['msg'] ?? null) && $decoded['msg'] !== ''
            ? $decoded['msg']
            : sprintf('the request failed with HTTP %d', $response->status);

        return new SpicyApiError(
            $message,
            status: $response->status,
            businessCode: $code,
            requestId: $requestId,
            retryAfterSeconds: self::parseRetryAfter($response->header('Retry-After')),
        );
    }

    /**
     * The envelope's `data`, which is the only part a caller ever wants.
     *
     * @param array<string,mixed> $decoded
     */
    private static function envelopeData(HttpResponse $response, array $decoded): mixed
    {
        if (!array_key_exists('data', $decoded)) {
            throw new SpicyApiError(
                'a successful envelope omitted data',
                status: $response->status,
                businessCode: 200,
                requestId: is_string($decoded['request_id'] ?? null) ? $decoded['request_id'] : '',
            );
        }

        return $decoded['data'];
    }

    /**
     * `Retry-After` in seconds, accepting both forms RFC 9110 allows.
     *
     * The contract's header schema is an integer, but a CDN or proxy in front of
     * the API may rewrite it as an HTTP-date, so both are parsed.
     */
    private static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $trimmed) === 1) {
            return max(0.0, (float) $trimmed);
        }
        $timestamp = strtotime($trimmed);

        return $timestamp === false ? null : max(0.0, (float) ($timestamp - time()));
    }

    /** Exponential backoff with jitter, honouring `Retry-After` when the server sent one. */
    private function retryDelay(int $attempt, ?float $retryAfterSeconds): float
    {
        if ($retryAfterSeconds !== null) {
            return min($retryAfterSeconds, $this->maxRetryDelaySeconds);
        }

        return min($this->jitter($this->retryBaseDelaySeconds * (2 ** $attempt)), $this->maxRetryDelaySeconds);
    }

    /** ±25% so a fleet of workers does not retry in lockstep. */
    private function jitter(float $seconds): float
    {
        return $seconds * (0.75 + (mt_rand() / mt_getrandmax()) * 0.5);
    }

    private function now(): float
    {
        return microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * An empty PHP array encodes as `[]`, and the contract wants an object.
     *
     * `json_encode([])` is `[]`, not `{}`, so a task with no input would be
     * rejected as a type error on `input`. Non-empty associative arrays encode
     * as objects already, so only the empty case needs help. The same trap
     * applies to empty arrays nested inside `input`; that part is the caller's.
     *
     * @param array<string,mixed> $value
     */
    private static function jsonObject(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass() : $value;
    }

    /**
     * Build a query suffix from the parameters that actually have a value.
     *
     * Two rules, both from the contract, which rejects unknown, duplicate **and
     * empty** query parameters:
     *
     *  - null means "not set" and is simply left out;
     *  - an empty or blank string is refused here rather than sent. It is a 400
     *    at the server, and dropping it silently would be worse than either:
     *    a caller who computed a filter and got nothing back would receive the
     *    unfiltered list and have no way to tell.
     *
     * @param array<string,string|int|null> $values
     */
    private static function queryString(array $values): string
    {
        $present = [];
        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }
            $text = is_int($value) ? (string) $value : trim($value);
            if ($text === '') {
                throw new \InvalidArgumentException(sprintf(
                    '%s was given an empty value; pass null to leave it unset',
                    $name,
                ));
            }
            $present[$name] = $text;
        }

        return $present === [] ? '' : '?' . http_build_query($present);
    }

    /**
     * A `YYYY-MM-DD` UTC date, as `format: date` in the contract spells it.
     *
     * Deliberately a string and not a `DateTimeInterface`: these bounds name a
     * calendar day in UTC, and widening them to a moment type would attach a
     * time zone the interval does not have.
     */
    private static function requireDate(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be a UTC date as YYYY-MM-DD, not "%s"',
                $label,
                $value,
            ));
        }

        return $trimmed;
    }

    private function requireIdentifier(string $value, string $label): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException($label . ' is required');
        }
        if (strpbrk($trimmed, "\r\n") !== false) {
            throw new \InvalidArgumentException($label . ' must not contain line breaks');
        }

        return $trimmed;
    }

    /**
     * @param mixed $data
     *
     * @return array<string,mixed>
     */
    private function requireArray(mixed $data): array
    {
        if (!is_array($data)) {
            throw new SpicyApiError('the response data was not an object', status: 200, businessCode: 200);
        }

        return $data;
    }
}

/* -- Webhook verification -------------------------------------------------- */

/** A delivery that passed every check. Safe to act on. */
final class VerifiedWebhook
{
    /**
     * @param array<string,mixed> $payload    The decoded body, exactly as delivered.
     * @param int                 $timestamp  Unix seconds from the signature header.
     * @param string|null         $deliveryId Stable delivery ID (`request_id`), v2 only.
     */
    public function __construct(
        public readonly array $payload,
        public readonly int $payloadVersion,
        public readonly string $taskId,
        public readonly int $timestamp,
        public readonly ?string $deliveryId = null,
    ) {
    }

    /** The task state. Both payload versions spell this key the same way. */
    public function state(): ?TaskState
    {
        $record = $this->payloadVersion === 2 ? ($this->payload['data'] ?? null) : $this->payload;
        if (!is_array($record) || !is_string($record['state'] ?? null)) {
            return null;
        }

        return TaskState::tryFrom($record['state']);
    }

    /**
     * The v2 task record, which is the same shape `recordInfo` returns.
     *
     * Null for v1, whose payload is a different, snake_case shape
     * (`task_id`, `error_code`, `error_message`, `created_at`) — do not read v1
     * with code written against `TaskRecord`.
     *
     * @return array<string,mixed>|null
     */
    public function taskRecord(): ?array
    {
        if ($this->payloadVersion !== 2) {
            return null;
        }
        $record = $this->payload['data'] ?? null;

        return is_array($record) ? $record : null;
    }
}

/**
 * Verify a signed webhook delivery.
 *
 * The scheme, from the `onTaskTerminal` callback in openapi.yaml: HMAC-SHA256
 * with the account webhook key over `taskId.timestamp.sha256(raw_body)`, where
 * the digest is lower-case hex and the result is Base64. `taskId` comes from
 * top-level `task_id` for payload v1 and from `data.taskId` for v2.
 *
 * Three ways PHP code gets this wrong, all of which are silent until someone
 * looks for them:
 *
 *  1. **Re-serializing the body.** The digest covers the bytes that arrived.
 *     `json_encode(json_decode($body))` reorders nothing but changes spacing,
 *     escaping and float formatting, and the signature stops matching — or, if
 *     a framework already parsed the body, the raw bytes are gone entirely.
 *     Read `php://input`, keep the string, and hand that exact string here.
 *  2. **Comparing with `==` or `===`.** String comparison short-circuits on the
 *     first differing byte, which leaks the signature one byte at a time to
 *     anyone who can time the endpoint. `==` is worse still: PHP compares two
 *     numeric strings numerically, so a Base64 value shaped like `0e123` can
 *     equal a different one. {@see hash_equals} is the only correct tool here.
 *  3. **Skipping the timestamp.** A valid signature stays valid forever. Without
 *     a freshness window, a delivery captured once can be replayed for good.
 *
 * Deliveries are retried, so the handler must be idempotent regardless: in v2,
 * `request_id` is the stable delivery ID to deduplicate on.
 *
 * This class verifies the scheme used by the per-task `callBackUrl`:
 * `base64(HMAC-SHA256(secret, "taskId.timestamp.hex(sha256(body))"))`. Saying
 * which scheme matters because a second, account-level delivery scheme is
 * planned; when that ships it gets its own verifier rather than extra arguments
 * here, so code written against this class keeps working unchanged.
 */
final class SpicyWebhook
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';
    public const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';
    public const PAYLOAD_VERSION_HEADER = 'X-Webhook-Payload-Version';

    public const DEFAULT_TOLERANCE_SECONDS = 300;
    public const DEFAULT_MAX_BODY_BYTES = 1048576;

    /**
     * Build the expected signature. Exposed so tests can forge a valid delivery.
     *
     * @param string $timestamp The header value verbatim. It is signed as text,
     *                          so re-formatting it as a number breaks the match.
     */
    public static function computeSignature(
        string $taskId,
        string $timestamp,
        string $rawBody,
        string $secret,
    ): string {
        $digest = hash('sha256', $rawBody);

        return base64_encode(hash_hmac('sha256', $taskId . '.' . $timestamp . '.' . $digest, $secret, true));
    }

    /**
     * Verify a delivery whose parts have already been pulled out of the request.
     *
     * @param string $rawBody The body exactly as received. Never a re-encoded copy.
     *
     * @throws SpicyWebhookError on any failure. Respond 400 and do not process.
     */
    public static function verify(
        string $rawBody,
        string $timestamp,
        string $signature,
        int $payloadVersion,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ): VerifiedWebhook {
        if (strlen($rawBody) > $maxBodyBytes) {
            // Measure the length before computing the HMAC, or an oversized body becomes free CPU
            // for whoever sent it.
            throw new SpicyWebhookError(
                'body_too_large',
                sprintf('the webhook body exceeds %d bytes', $maxBodyBytes),
            );
        }
        if (trim($secret) === '') {
            throw new SpicyWebhookError('invalid_secret', 'a webhook secret is required');
        }
        if ($payloadVersion !== 1 && $payloadVersion !== 2) {
            throw new SpicyWebhookError(
                'invalid_payload',
                sprintf('unsupported payload version "%d"', $payloadVersion),
            );
        }

        $timestamp = trim($timestamp);
        if (preg_match('/^\d+$/', $timestamp) !== 1) {
            throw new SpicyWebhookError('invalid_timestamp', 'the webhook timestamp must be Unix seconds');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new SpicyWebhookError('invalid_json', 'the webhook body is not a JSON object');
        }

        $taskId = self::extractTaskId($payload, $payloadVersion);
        if ($taskId === null) {
            throw new SpicyWebhookError('invalid_payload', sprintf(
                'a payload version %d body must carry a task ID at %s',
                $payloadVersion,
                $payloadVersion === 1 ? 'task_id' : 'data.taskId',
            ));
        }

        $expected = self::computeSignature($taskId, $timestamp, $rawBody, $secret);
        if (!hash_equals($expected, trim($signature))) {
            throw new SpicyWebhookError('invalid_signature', 'the webhook signature does not match');
        }

        // The timestamp is checked after the signature: verifying first means never interpreting
        // a timestamp of unknown origin.
        $seconds = (int) $timestamp;
        $reference = $now ?? time();
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('toleranceSeconds must not be negative');
        }
        if (abs($reference - $seconds) > $toleranceSeconds) {
            throw new SpicyWebhookError('stale_timestamp', sprintf(
                'the webhook timestamp is outside the %d second tolerance window',
                $toleranceSeconds,
            ));
        }

        $deliveryId = null;
        if ($payloadVersion === 2 && is_string($payload['request_id'] ?? null)) {
            $deliveryId = $payload['request_id'];
        }

        return new VerifiedWebhook($payload, $payloadVersion, $taskId, $seconds, $deliveryId);
    }

    /**
     * Verify the delivery currently being served, reading the raw body and the
     * signature headers from the request itself.
     *
     * The body is read from `php://input`, which is the only place the exact
     * delivered bytes exist. It is empty when something upstream already
     * consumed the stream — a framework's body parser, or a
     * `multipart/form-data` request — so pass `$rawBody` yourself when running
     * inside a framework that buffers it.
     *
     * Usage in a plain endpoint:
     *
     *   $secret = getenv('SPICY_WEBHOOK_SECRET') ?: '';
     *   try {
     *       $delivery = SpicyWebhook::verifyRequest($secret);
     *   } catch (SpicyWebhookError $error) {
     *       http_response_code(400);
     *       exit;
     *   }
     *   // Acknowledge first, then do the slow work: the sender retries on timeout.
     *   http_response_code(200);
     *
     * @param array<string,mixed>|null $server Defaults to `$_SERVER`.
     */
    public static function verifyRequest(
        string $secret,
        ?array $server = null,
        ?string $rawBody = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ): VerifiedWebhook {
        $server ??= $_SERVER;
        $body = $rawBody ?? (string) file_get_contents('php://input');

        $signature = self::serverHeader($server, self::SIGNATURE_HEADER);
        $timestamp = self::serverHeader($server, self::TIMESTAMP_HEADER);
        $version = self::serverHeader($server, self::PAYLOAD_VERSION_HEADER);
        foreach ([self::SIGNATURE_HEADER => $signature, self::TIMESTAMP_HEADER => $timestamp] as $name => $value) {
            if ($value === null) {
                throw new SpicyWebhookError('missing_header', sprintf('%s is missing', $name));
            }
        }

        return self::verify(
            rawBody: $body,
            timestamp: (string) $timestamp,
            signature: (string) $signature,
            // A missing header is treated as 2: v2 is the default for new integrations, and v1
            // reaches only accounts that explicitly chose it.
            payloadVersion: $version === null ? 2 : (int) $version,
            secret: $secret,
            toleranceSeconds: $toleranceSeconds,
            maxBodyBytes: $maxBodyBytes,
        );
    }

    /**
     * `X-Webhook-Signature` arrives in `$_SERVER` as `HTTP_X_WEBHOOK_SIGNATURE`.
     *
     * @param array<string,mixed> $server
     */
    private static function serverHeader(array $server, string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $payload */
    private static function extractTaskId(array $payload, int $payloadVersion): ?string
    {
        if ($payloadVersion === 1) {
            return is_string($payload['task_id'] ?? null) && $payload['task_id'] !== ''
                ? $payload['task_id']
                : null;
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data) || !is_string($data['taskId'] ?? null) || $data['taskId'] === '') {
            return null;
        }

        return $data['taskId'];
    }
}
