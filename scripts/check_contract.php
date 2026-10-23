<?php

/**
 * Contract drift check: the contract facts hard-coded in this package must still match the
 * published contract.
 *
 * Why it has to exist: the enums and limits in an SDK are all constants copied from the contract.
 * When the contract changes and these do not, nothing raises an error - requests still go out,
 * responses still parse, it is only that some new option gets rejected locally as an illegal value.
 * This kind of failure emits no signal.
 *
 * Why it compares against the published contract rather than the spicy-server repository: this
 * repository is public and spicy-server is not. Giving a public repository's CI a token that can
 * read a private one puts it somewhere anyone can open a pull request against. The contract is
 * public anyway.
 *
 * `--offline` skips the download and checks the constants against the pinned copy alone. It is for
 * syncing the pin from the server's contract before that contract is published: it proves the
 * package and the pin agree, and says nothing about whether the pin is current. CI runs the default
 * mode.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/SpicyClient.php';

const LIVE_URL = 'https://docs.spicyapi.ai/openapi.yaml';
const LOCAL = __DIR__ . '/../contracts/openapi.yaml';

/**
 * Retrieves the published contract.
 *
 * A User-Agent is mandatory: the docs site's edge protection answers 403 to requests without one,
 * while the api host does not (both were tested on 2026-09-20 and they behave differently).
 * Without that one header this check fails in the name of "contract drift" for a reason that has
 * nothing to do with the contract.
 */
function fetchLive(): string
{
    $context = stream_context_create([
        'http' => ['header' => "User-Agent: spicyapi-contract-check\r\n", 'timeout' => 30],
    ]);
    $body = @file_get_contents(LIVE_URL, false, $context);
    if ($body === false) {
        fwrite(STDERR, "[contract] the published contract is unreachable\n");
        exit(1);
    }
    return $body;
}

/** Returns the enum values of $field in the section following $anchor. */
function enumAfter(string $contract, string $anchor, string $field, int $windowChars = 4000): array
{
    $start = strpos($contract, $anchor);
    if ($start === false) {
        fwrite(STDERR, "[contract] the contract has no {$anchor} schema\n");
        exit(1);
    }
    $window = substr($contract, $start, $windowChars);
    $at = strpos($window, $field);
    if ($at === false) {
        fwrite(STDERR, "[contract] {$anchor} has no {$field}\n");
        exit(1);
    }
    if (!preg_match('/enum:\s*\[([^\]]+)\]/', substr($window, $at, 600), $m)) {
        fwrite(STDERR, "[contract] {$anchor}{$field} has no enum\n");
        exit(1);
    }
    $values = array_map(
        static fn (string $v): string => trim(trim($v), "'\""),
        explode(',', $m[1]),
    );
    sort($values);
    return $values;
}

$offline = in_array('--offline', array_slice($argv, 1), true);
$local = is_file(LOCAL) ? (string) file_get_contents(LOCAL) : '';
if ($offline) {
    $live = $local;
} else {
    $live = fetchLive();
    if ($local !== $live) {
        fwrite(STDERR,
            "[contract] contracts/openapi.yaml is stale against the published contract.\n" .
            "[contract] refresh it:  curl -sH 'User-Agent: spicyapi-contract-check' " . LIVE_URL . " -o contracts/openapi.yaml\n" .
            "[contract] then re-check every constant this package hard-codes against that diff.\n");
        exit(1);
    }
}

/**
 * The sorted values of a backed enum.
 *
 * @param class-string<\BackedEnum> $enum
 *
 * @return list<string>
 */
function enumValues(string $enum): array
{
    $values = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
    sort($values);
    return $values;
}

// Each set below is a complete list both here and in the contract, which is what makes a
// value-by-value comparison meaningful; an enum's cases() guarantees a newly added member cannot be
// missed.
$checks = [
    // Upload types are refused locally when unknown, so a type the server gains would be refused by
    // the package while the server plainly accepts it.
    'upload content types' => [enumValues(\SpicyApi\UploadContentType::class), enumAfter($live, '    UploadURLRequest:', 'contentType:')],
    // The asset library's failure reasons: a reason the package does not know still reaches the
    // caller verbatim, but assetErrorReason() would call it unknown and guidance() would not cover it.
    'asset error reasons' => [enumValues(\SpicyApi\AssetErrorReason::class), enumAfter($live, '    ErrorEnvelope:', '                reason:', 8000)],
    // Sorts and sources are refused locally when unknown, for the same reason as upload types.
    'asset list sorts' => [enumValues(\SpicyApi\AssetSort::class), enumAfter($live, 'operationId: listAssets', 'name: sort', 8000)],
    'asset sources' => [enumValues(\SpicyApi\AssetSource::class), enumAfter($live, '    AssetCreateRequest:', 'source:')],
];

$failed = false;
foreach ($checks as $label => [$ours, $theirs]) {
    if ($ours !== $theirs) {
        fwrite(STDERR, sprintf(
            "[contract] %s drifted:\n  package:  %s\n  contract: %s\n",
            $label,
            implode(', ', $ours),
            implode(', ', $theirs),
        ));
        $failed = true;
    }
}
if ($failed) {
    exit(1);
}

printf(
    "[contract] matches %s (%d constant sets verified).\n",
    $offline ? 'the pinned contract (offline)' : 'the published contract',
    count($checks),
);
