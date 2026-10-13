<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiNotFoundException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiHttpClient;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiKeyResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The whole point of this class is turning HTTP-level outcomes into domain
 * exceptions, so that mapping is what the tests pin down. Callers branch on the
 * exception type — a 401 answered with the same exception as a 503 would make
 * "check your API key" indistinguishable from "CrowdSec is down".
 *
 * LapiKeyResolver is final; it is replaced by a real instance whose resolver
 * closure is not exercised here beyond supplying a key.
 */
final class LapiHttpClientTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private RequestInterface&MockObject $request;
    private LapiKeyResolver $keyResolver;
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(ClientInterface::class);

        // Every with*() returns $this so the builder chain in dispatch() works.
        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('withHeader')->willReturnSelf();
        $this->request->method('withBody')->willReturnSelf();

        // LapiKeyResolver is final. It reads the key from a file path handed to
        // its constructor, so a real one backed by a temp file is both possible
        // and closer to production than a double would be.
        $this->keyFile = tempnam(sys_get_temp_dir(), 'lapi-key-');
        file_put_contents($this->keyFile, "test-key\n");
        $this->keyResolver = new LapiKeyResolver($this->keyFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    private function subject(string $baseUrl = 'http://lapi:8080'): LapiHttpClient
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($this->request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        return new LapiHttpClient(
            $this->httpClient,
            $requestFactory,
            $streamFactory,
            $this->keyResolver,
            $baseUrl,
        );
    }

    private function respondWith(int $status, string $body = ''): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->method('sendRequest')->willReturn($response);
    }

    #[Test]
    public function aJsonBodyIsDecoded(): void
    {
        $this->respondWith(200, '[{"id":1,"value":"1.2.3.4"}]');

        self::assertSame([['id' => 1, 'value' => '1.2.3.4']], $this->subject()->get('/v1/decisions'));
    }

    /**
     * LAPI answers "nothing to report" with a literal null rather than an empty
     * list. Treating that as malformed would turn a quiet CrowdSec into an
     * error banner in the backend module.
     */
    #[Test]
    #[DataProvider('emptyBodyProvider')]
    public function anEmptyOrNullBodyBecomesAnEmptyList(string $body): void
    {
        $this->respondWith(200, $body);

        self::assertSame([], $this->subject()->get('/v1/decisions'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyBodyProvider(): array
    {
        return ['empty string' => [''], 'literal null' => ['null']];
    }

    #[Test]
    public function malformedJsonIsReportedAsUnavailable(): void
    {
        $this->respondWith(200, '{not json');

        $this->expectException(LapiUnavailableException::class);

        $this->subject()->get('/v1/decisions');
    }

    /**
     * A scalar is valid JSON but not a decision list, so it must not slip
     * through as a result the caller would then iterate.
     */
    #[Test]
    public function aNonArrayJsonBodyIsReportedAsUnavailable(): void
    {
        $this->respondWith(200, '"just a string"');

        $this->expectException(LapiUnavailableException::class);

        $this->subject()->get('/v1/decisions');
    }

    #[Test]
    public function anUnauthorizedResponseBecomesAnAuthenticationError(): void
    {
        $this->respondWith(401);

        $this->expectException(LapiAuthenticationException::class);

        $this->subject()->get('/v1/decisions');
    }

    #[Test]
    public function aMissingResourceBecomesANotFoundError(): void
    {
        $this->respondWith(404);

        $this->expectException(LapiNotFoundException::class);

        $this->subject()->get('/v1/decisions/99');
    }

    #[Test]
    #[DataProvider('serverErrorProvider')]
    public function serverSideFailuresBecomeUnavailable(int $status): void
    {
        $this->respondWith($status);

        $this->expectException(LapiUnavailableException::class);

        $this->subject()->get('/v1/decisions');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function serverErrorProvider(): array
    {
        return [
            'internal error' => [500],
            'bad gateway' => [502],
            'service unavailable' => [503],
            // A zero status is what some clients report for a connection that
            // never produced a response at all.
            'no status at all' => [0],
        ];
    }

    #[Test]
    public function anUnexpectedClientErrorBecomesUnavailable(): void
    {
        $this->respondWith(418);

        $this->expectException(LapiUnavailableException::class);

        $this->subject()->get('/v1/decisions');
    }

    /**
     * A transport failure never reaches a status code. It has to surface as the
     * same "LAPI is not reachable" the 5xx path produces.
     */
    #[Test]
    public function aTransportFailureBecomesUnavailable(): void
    {
        $networkError = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        $this->httpClient->method('sendRequest')->willThrowException($networkError);

        $this->expectException(LapiUnavailableException::class);

        $this->subject()->get('/v1/decisions');
    }

    #[Test]
    public function postSendsAnEncodedBodyAndDecodesTheAnswer(): void
    {
        $this->request->expects(self::once())->method('withBody')->willReturnSelf();
        $this->respondWith(200, '["42"]');

        self::assertSame(['42'], $this->subject()->post('/v1/decisions', ['value' => '1.2.3.4']));
    }

    #[Test]
    public function postTreatsAnEmptyAnswerAsAnEmptyList(): void
    {
        $this->respondWith(201, '');

        self::assertSame([], $this->subject()->post('/v1/decisions', ['value' => '1.2.3.4']));
    }

    #[Test]
    public function postRejectsAMalformedAnswer(): void
    {
        $this->respondWith(200, '{broken');

        $this->expectException(LapiUnavailableException::class);

        $this->subject()->post('/v1/decisions', []);
    }

    #[Test]
    public function aSuccessfulDeleteReportsTrue(): void
    {
        $this->respondWith(200);

        self::assertTrue($this->subject()->delete('/v1/decisions/1'));
    }

    /**
     * Deleting a decision that is already gone is the desired end state, not a
     * failure — the caller asked for it to be absent, and it is.
     */
    #[Test]
    public function deletingSomethingAlreadyGoneReportsFalseRatherThanThrowing(): void
    {
        $this->respondWith(404);

        self::assertFalse($this->subject()->delete('/v1/decisions/1'));
    }

    #[Test]
    public function aDeleteThatFailsAuthenticationStillThrows(): void
    {
        $this->respondWith(401);

        $this->expectException(LapiAuthenticationException::class);

        $this->subject()->delete('/v1/decisions/1');
    }
}
