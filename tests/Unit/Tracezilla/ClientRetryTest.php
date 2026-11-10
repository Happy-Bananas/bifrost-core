<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use Bifrost\Core\Tracezilla\Client;
use Bifrost\Core\Tracezilla\OAuthTokens;
use Bifrost\Core\Tracezilla\TokenProvider;
use Bifrost\Core\Tracezilla\TokenStore;
use Bifrost\Core\Tracezilla\TracezillaConfiguration;
use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClientRetryTest extends TestCase
{
    private TemporaryDirectory $directory;
    private MockHandler $api;
    private RecordingPause $pause;
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->api = new MockHandler();
        $this->pause = new RecordingPause();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function test_a_read_is_sent_again_after_a_temporary_failure(): void
    {
        $this->api->append(new Response(503), new Response(200, [], '{"data":[]}'));

        self::assertSame(['data' => []], $this->client()->get('/skus'));
        self::assertSame([1], $this->pause->seconds);
        self::assertSame(['GET /skus: tracezilla answered HTTP 503'], $this->pause->reasons);
    }

    public function test_each_retry_waits_longer(): void
    {
        $this->api->append(new Response(502), new Response(504), new Response(500), new Response(200, [], '{"data":[]}'));

        $this->client()->get('/skus');

        self::assertSame([1, 2, 4], $this->pause->seconds);
    }

    public function test_it_gives_up_after_three_retries(): void
    {
        $this->api->append(new Response(503), new Response(503), new Response(503), new Response(503));

        try {
            $this->client()->get('/skus');
            self::fail('The request should have failed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('GET /skus failed with HTTP 503', $exception->getMessage());
        }
        self::assertCount(4, $this->history);
        self::assertSame([1, 2, 4], $this->pause->seconds);
    }

    public function test_it_waits_as_long_as_tracezilla_asks(): void
    {
        $this->api->append(new Response(429, ['Retry-After' => '7']), new Response(200, [], '{"data":[]}'));

        $this->client()->get('/skus');

        self::assertSame([7], $this->pause->seconds);
    }

    public function test_it_never_waits_longer_than_a_minute(): void
    {
        $this->api->append(new Response(429, ['Retry-After' => '3600']), new Response(200, [], '{"data":[]}'));

        $this->client()->get('/skus');

        self::assertSame([Client::MAX_WAIT_SECONDS], $this->pause->seconds);
    }

    public function test_a_read_is_sent_again_when_tracezilla_could_not_be_reached(): void
    {
        $this->api->append(
            new ConnectException('Connection refused', new Request('GET', 'skus')),
            new Response(200, [], '{"data":[]}'),
        );

        $this->client()->get('/skus');

        self::assertSame(['GET /skus: tracezilla could not be reached'], $this->pause->reasons);
    }

    public function test_a_write_is_sent_again_when_tracezilla_had_too_many_requests(): void
    {
        $this->api->append(new Response(429), new Response(201, [], '{"data":{"id":"sku-1"}}'));

        $this->client()->post('/skus', ['sku_code' => 'TZ-A']);

        self::assertCount(2, $this->history);
    }

    public function test_a_write_is_not_sent_twice_after_a_server_error(): void
    {
        // The SKU may already exist: sending the request again could create it twice.
        $this->api->append(new Response(503), new Response(201, [], '{"data":{"id":"sku-1"}}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POST /skus failed with HTTP 503');

        try {
            $this->client()->post('/skus', ['sku_code' => 'TZ-A']);
        } finally {
            self::assertCount(1, $this->history);
            self::assertSame([], $this->pause->seconds);
        }
    }

    public function test_a_write_is_not_sent_twice_when_no_answer_arrived(): void
    {
        $this->api->append(new ConnectException('Connection reset', new Request('PUT', 'skus/1')));

        $this->expectException(ConnectException::class);

        try {
            $this->client()->put('/skus/1', ['global_name' => 'A']);
        } finally {
            self::assertSame([], $this->pause->seconds);
        }
    }

    public function test_a_client_error_is_reported_at_once(): void
    {
        $this->api->append(new Response(422, [], '{"message":"The sku code has already been taken."}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been taken');

        try {
            $this->client()->get('/skus');
        } finally {
            self::assertSame([], $this->pause->seconds);
        }
    }

    private function client(): Client
    {
        $store = new TokenStore($this->directory->path.'/token.json');
        $store->save(new OAuthTokens('access', 'refresh', new DateTimeImmutable('+300 days')));
        $stack = HandlerStack::create($this->api);
        $stack->push(Middleware::history($this->history));

        return new Client(
            new TracezillaConfiguration('https://tracezilla.test', 'team', 'id', 'secret', 'api-user', 'api-password', $store->path(), 5, 5),
            new TokenProvider(new HttpClient(['handler' => HandlerStack::create(new MockHandler())]), $store, 'id', 'secret', 'api-user', 'api-password'),
            new HttpClient(['handler' => $stack, 'base_uri' => 'https://tracezilla.test/api/v1/team/']),
            $this->pause,
        );
    }
}
