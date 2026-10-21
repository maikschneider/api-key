<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Tests\Unit\Authentication;

use MaikSchneider\ApiKey\Authentication\CredentialExtractor;
use MaikSchneider\ApiKey\Authentication\InvalidReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CredentialExtractorTest extends UnitTestCase
{
    private const KEY = 't3key_KhhNfTRL-5tLoriJVo5I-BQRJWiLD3GqlvEcPDPeHmg';
    private const OTHER_KEY = 't3key_ZZZZZZZZ-5tLoriJVo5I-BQRJWiLD3GqlvEcPDPeHmg';

    /**
     * @return iterable<string, array{array<string, string|list<string>>, array<string, mixed>, array<string, mixed>, string|InvalidReason|null}>
     */
    public static function requests(): iterable
    {
        yield 'no credentials' => [[], [], [], null];
        yield 'x-api-key' => [['X-API-Key' => self::KEY], [], [], self::KEY];
        yield 'x-api-key lowercase header name' => [['x-api-key' => self::KEY], [], [], self::KEY];
        yield 'x-api-key with whitespace' => [['X-API-Key' => ' ' . self::KEY . ' '], [], [], self::KEY];
        yield 'bearer' => [['Authorization' => 'Bearer ' . self::KEY], [], [], self::KEY];
        yield 'bearer lowercase scheme' => [['Authorization' => 'bearer ' . self::KEY], [], [], self::KEY];
        yield 'bearer from redirect server param' => [[], [], ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY], self::KEY];
        yield 'same key in both headers' => [['X-API-Key' => self::KEY, 'Authorization' => 'Bearer ' . self::KEY], [], [], self::KEY];
        yield 'foreign bearer token' => [['Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln'], [], [], null];
        yield 'basic auth' => [['Authorization' => 'Basic dXNlcjpwYXNz'], [], [], null];
        yield 'foreign bearer and x-api-key' => [['X-API-Key' => self::KEY, 'Authorization' => 'Bearer foreign'], [], [], self::KEY];
        yield 'malformed x-api-key' => [['X-API-Key' => 'secret'], [], [], InvalidReason::Malformed];
        yield 'empty x-api-key' => [['X-API-Key' => ''], [], [], InvalidReason::Malformed];
        yield 'malformed prefixed bearer' => [['Authorization' => 'Bearer t3key_short'], [], [], InvalidReason::Malformed];
        yield 'prefixed bearer with trailing garbage' => [['Authorization' => 'Bearer ' . self::KEY . ' garbage'], [], [], InvalidReason::Malformed];
        yield 'prefixed bearer with trailing comma' => [['Authorization' => 'Bearer ' . self::KEY . ','], [], [], InvalidReason::Malformed];
        yield 'comma-joined prefixed bearers' => [['Authorization' => 'Bearer ' . self::KEY . ', Bearer ' . self::OTHER_KEY], [], [], InvalidReason::Malformed];
        yield 'prefixed bearer after a foreign bearer' => [['Authorization' => 'Bearer foreign, Bearer ' . self::KEY], [], [], InvalidReason::Malformed];
        yield 'comma-joined foreign bearers' => [['Authorization' => 'Bearer foreign, Bearer other'], [], [], null];
        yield 'foreign bearer with the prefix in upper case' => [['Authorization' => 'Bearer T3KEY_foreign'], [], [], null];
        yield 'foreign bearer with the prefix in upper case and x-api-key' => [['X-API-Key' => self::KEY, 'Authorization' => 'Bearer T3KEY_foreign'], [], [], self::KEY];
        yield 'prefixed bearer with trailing spaces' => [['Authorization' => 'Bearer ' . self::KEY . '  '], [], [], self::KEY];
        yield 'malformed redirected bearer' => [[], [], ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY . ' garbage'], InvalidReason::Malformed];
        yield 'foreign bearer with trailing garbage' => [['Authorization' => 'Bearer foreign garbage'], [], [], null];
        yield 'repeated authorization header' => [['Authorization' => ['Bearer ' . self::KEY, 'Bearer t3key_bad']], [], [], InvalidReason::Ambiguous];
        yield 'repeated authorization header with the same key' => [['Authorization' => ['Bearer ' . self::KEY, 'Bearer ' . self::KEY]], [], [], InvalidReason::Ambiguous];
        yield 'key next to basic auth' => [['Authorization' => ['Basic dXNlcjpwYXNz', 'Bearer ' . self::KEY]], [], [], InvalidReason::Ambiguous];
        yield 'repeated foreign authorization header' => [['Authorization' => ['Bearer foreign', 'Basic dXNlcjpwYXNz']], [], [], null];
        yield 'different keys in both headers' => [['X-API-Key' => self::KEY, 'Authorization' => 'Bearer ' . self::OTHER_KEY], [], [], InvalidReason::Ambiguous];
        yield 'repeated x-api-key header' => [['X-API-Key' => [self::KEY, self::OTHER_KEY]], [], [], InvalidReason::Ambiguous];
        yield 'key in query string' => [[], ['api_key' => self::KEY], [], InvalidReason::KeyInQueryString];
        yield 'key nested in query string' => [[], ['filter' => ['token' => 'x' . self::KEY]], [], InvalidReason::KeyInQueryString];
        yield 'key in query string wins over header' => [['X-API-Key' => self::KEY], ['key' => self::KEY], [], InvalidReason::KeyInQueryString];
        yield 'prefix alone in query string' => [['X-API-Key' => self::KEY], ['q' => 't3key_'], [], self::KEY];
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $serverParams
     */
    #[Test]
    #[DataProvider('requests')]
    public function extract(array $headers, array $query, array $serverParams, string|InvalidReason|null $expected): void
    {
        $request = (new ServerRequest('https://example.com/api', 'GET', 'php://input', $headers, $serverParams))
            ->withQueryParams($query);

        self::assertSame($expected, (new CredentialExtractor())->extract($request));
    }

    /**
     * Query strings as sent, before PHP parses them into parameters.
     *
     * @return iterable<string, array{string}>
     */
    public static function rawQueryStrings(): iterable
    {
        yield 'repeated parameter overwrites the key' => ['key=' . self::KEY . '&key=redacted'];
        yield 'key as parameter name' => [self::KEY . '=1'];
        yield 'key in nested parameter name' => ['filter[' . self::KEY . ']=1'];
        yield 'url-encoded key' => ['key=' . implode('', array_map(static fn (string $char): string => '%' . bin2hex($char), str_split(self::KEY)))];
        yield 'key behind an encoded separator' => ['q=x%26token%3D' . self::KEY];
    }

    #[Test]
    #[DataProvider('rawQueryStrings')]
    public function keyInRawQueryStringIsRejectedEvenWithValidHeader(string $query): void
    {
        $request = (new ServerRequest('https://example.com/api?' . $query, 'GET', 'php://input', ['X-API-Key' => self::KEY]));
        parse_str($query, $parsed);

        self::assertSame(InvalidReason::KeyInQueryString, (new CredentialExtractor())->extract($request->withQueryParams($parsed)));
    }

    #[Test]
    public function queryStringWithoutKeyIsIgnored(): void
    {
        $request = (new ServerRequest('https://example.com/api?q=t3key_&page=2', 'GET', 'php://input', ['X-API-Key' => self::KEY]))
            ->withQueryParams(['q' => 't3key_', 'page' => '2']);

        self::assertSame(self::KEY, (new CredentialExtractor())->extract($request));
    }
}
