<?php

declare(strict_types=1);

namespace Duva\Symfony\Mailer\Tests;

use Duva\Client;
use Duva\Exceptions\ValidationException;
use Duva\Http\RequestSpec;
use Duva\Http\TestTransport;
use Duva\Symfony\Mailer\DuvaTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class DuvaTransportTest extends TestCase
{
    private TestTransport $http;

    /** @var callable */
    private $handler;

    private Mailer $mailer;

    protected function setUp(): void
    {
        $this->handler = static fn () => TestTransport::response(['id' => 'msg_' . str_repeat('0', 31) . '1', 'status' => 'queued']);
        $this->http = new TestTransport(fn ($config, $spec) => ($this->handler)($config, $spec));
        $client = new Client(apiKey: 'dv_test', domain: 'example.com', maxRetries: 0, transport: $this->http);
        $this->mailer = new Mailer(new DuvaTransport($client));
    }

    private function email(): Email
    {
        return (new Email())->from(new Address('shop@example.com', 'Shop'))->to(new Address('client@example.org', 'Client'))
            ->subject('Order')->text('Body');
    }

    private function sent(): RequestSpec
    {
        self::assertCount(1, $this->http->requests);

        return $this->http->requests[0];
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $body = $this->sent()->body;
        self::assertIsArray($body);

        return $body;
    }

    public function testMapsAMessageToTheDuvaRequest(): void
    {
        $email = $this->email()->cc(new Address('cc@example.org', 'Cc Person'))->bcc('hidden@example.org')
            ->replyTo(new Address('help@example.com', 'Support'))->html('<p>Hi</p>');

        $this->mailer->send($email);

        self::assertSame('POST', $this->sent()->method);
        self::assertSame('/v1/example.com/messages', $this->sent()->path);
        self::assertEquals([
            'from' => 'Shop <shop@example.com>',
            'to' => ['Client <client@example.org>'],
            'subject' => 'Order',
            'cc' => ['Cc Person <cc@example.org>'],
            'bcc' => ['hidden@example.org'],
            'html' => '<p>Hi</p>',
            'text' => 'Body',
            'reply_to' => 'Support <help@example.com>',
        ], $this->body());
    }

    public function testReportsDuvasMessageIdToSymfony(): void
    {
        $sent = $this->mailer->send($this->email());

        self::assertNull($sent);
    }

    public function testTheTransportItselfReportsDuvasMessageId(): void
    {
        $client = new Client(apiKey: 'dv_test', domain: 'example.com', maxRetries: 0, transport: $this->http);
        $sent = (new DuvaTransport($client))->send($this->email());

        self::assertNotNull($sent);
        self::assertSame('msg_' . str_repeat('0', 31) . '1', $sent->getMessageId());
    }

    public function testDropsADisplayNameDuvaWouldRefuseButKeepsTheAddress(): void
    {
        $email = $this->email()->to(new Address('a@example.org', 'Mallory @ evil.com'))
            ->cc(new Address('b@example.org', '=?UTF-8?B?Zm9v?='));

        $this->mailer->send($email);

        self::assertSame(['a@example.org'], $this->body()['to']);
        self::assertSame(['b@example.org'], $this->body()['cc']);
    }

    public function testEnvelopeOnlyRecipientsBecomeBcc(): void
    {
        $client = new Client(apiKey: 'dv_test', domain: 'example.com', maxRetries: 0, transport: $this->http);
        (new DuvaTransport($client))->send(
            $this->email(),
            new Envelope(new Address('shop@example.com'), [new Address('client@example.org'), new Address('audit@example.com')]),
        );

        self::assertSame(['audit@example.com'], $this->body()['bcc']);
    }

    public function testForwardsTagsMetadataAndAllowedHeadersOnly(): void
    {
        $email = $this->email();
        $headers = $email->getHeaders();
        $headers->add(new TagHeader('welcome'));
        $headers->add(new MetadataHeader('order_id', '42'));
        $headers->addTextHeader('List-Unsubscribe', '<https://example.com/u/1>');
        $headers->addTextHeader('X-Custom', 'yes');
        $headers->addTextHeader('X-Duva-Secret', 'no');
        $headers->addTextHeader('Organization', 'Acme');

        $this->mailer->send($email);

        $body = $this->body();
        self::assertSame(['welcome'], $body['tags']);
        self::assertSame(['order_id' => '42'], $body['metadata']);
        self::assertSame(['List-Unsubscribe' => '<https://example.com/u/1>', 'X-Custom' => 'yes'], $body['headers']);
    }

    public function testIdempotencyHeaderBecomesTheKeyAndIsNotForwarded(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Idempotency-Key', 'order-42');

        $this->mailer->send($email);

        self::assertSame('order-42', $this->sent()->idempotencyKey);
        self::assertArrayNotHasKey('headers', $this->body());
    }

    public function testAttachmentsAndInlineImages(): void
    {
        $email = $this->email()->html('<img src="cid:logo.png">')->attach('hello', 'note.txt', 'text/plain')
            ->embed('PNGDATA', 'logo.png', 'image/png');

        $this->mailer->send($email);

        $byName = array_column($this->body()['attachments'], null, 'filename');
        self::assertSame(base64_encode('hello'), $byName['note.txt']['content']);
        self::assertSame('text/plain', $byName['note.txt']['content_type']);
        self::assertNull($byName['note.txt']['content_id']);
        self::assertSame('logo.png', $byName['logo.png']['content_id']);
    }

    public function testAnInlinePartWithAnExplicitContentIdKeepsIt(): void
    {
        $part = (new DataPart('PNG', 'brand.png', 'image/png'))->setContentId('brand@example.com')->asInline();
        $email = $this->email()->html('<img src="cid:brand@example.com">')->addPart($part);

        $this->mailer->send($email);

        self::assertSame('brand@example.com', $this->body()['attachments'][0]['content_id']);
    }

    public function testADuvaErrorBecomesATransportException(): void
    {
        $this->handler = static function (): never {
            throw new ValidationException(422, 'invalid_request', 'Invalid message', '{}', [['field' => 'to[0]', 'message' => 'bad']]);
        };

        try {
            $this->mailer->send($this->email());
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('Invalid message', $e->getMessage());
            self::assertInstanceOf(ValidationException::class, $e->getPrevious());
        }
    }
}
