# duva-mail/symfony-mailer

[Symfony Mailer](https://symfony.com/doc/current/mailer.html) transport for [Duva](https://duva.ca), the transactional
email API hosted in Canada. Keep using `MailerInterface`, `TemplatedEmail` and Messenger; the messages go out through Duva.

Requires PHP 8.2+ and Symfony 6.4, 7 or 8. Built on [`duva-mail/duva`](https://github.com/duva-mail/duva-php).

## Install

```bash
composer require duva-mail/symfony-mailer
```

Register the bundle (it adds the `duva://` scheme to Symfony Mailer):

```php
// config/bundles.php
return [
    // ...
    Duva\Symfony\Mailer\DuvaMailerBundle::class => ['all' => true],
];
```

You also need a Duva API key and a verified domain ([documentation](https://duva.ca/en/docs)).

## Configure

```dotenv
# .env.local
MAILER_DSN=duva://YOUR_API_KEY@default?domain=example.com
```

```yaml
# config/packages/mailer.yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
```

The host is `default` for `https://api.duva.ca`. Options: `domain` (required), `timeout` (seconds, default 10),
`max_retries` (default 2). The `From` address must belong to the Duva domain. Use a failover to keep an SMTP fallback:

```dotenv
MAILER_DSN="failover(duva://KEY@default?domain=example.com smtp://localhost)"
```

## What is sent

| Symfony | Duva |
|---|---|
| From, To, Cc, Bcc, Reply-To (and an address only in the `Envelope`, sent as Bcc) | `from`, `to`, `cc`, `bcc`, `reply_to` |
| `text` and `html` | `text`, `html` |
| `attach()`, `embed()` and inline `DataPart`s | `attachments` (inline ones keep the `cid:` identifier the HTML uses) |
| `TagHeader`, `MetadataHeader` | `tags`, `metadata` |
| `List-Unsubscribe`, `List-Unsubscribe-Post`, `List-Id`, `In-Reply-To`, `References`, `Auto-Submitted`, `Precedence`, `Importance`, `Feedback-ID`, and `X-*` headers | `headers` |

```php
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;

$email = (new Email())->from('Shop <shop@example.com>')->to('client@example.org')->subject('Order')->html('<p>Thanks</p>');
$email->getHeaders()->add(new TagHeader('order'));
$email->getHeaders()->add(new MetadataHeader('order_id', '42'));
$email->getHeaders()->addTextHeader('X-Idempotency-Key', 'order-42'); // see "Idempotency"
$mailer->send($email);
```

Notes:

- Other headers are not forwarded (Duva rejects them), nor are `X-Duva*`, `X-Kumo*`, `X-Tenant*` and `X-Campaign*`.
- A display name Duva refuses (it contains `@`, quotes, `<>`, control characters or an encoded word `=?…?=`) is dropped;
  the address is kept.
- The message ID Symfony reports (`SentMessage::getMessageId()`) is Duva's.
- Delivery is asynchronous: a successful `send()` means Duva accepted the message, not that it was delivered. Use Duva's
  webhooks or events for the outcome.
- A Duva error is thrown as `Symfony\Component\Mailer\Exception\TransportException` (the original `Duva\Exceptions\*`
  exception is its `getPrevious()`), so failover transports and Messenger retries react to it.

### Idempotency

A retried Messenger handler rebuilds the message, so a retry after a network timeout could send twice. Set a stable key
(an order ID, for example) with the `X-Idempotency-Key` header. It is read by the transport and never sent; Duva ignores a
second message with the same key.

## Testing your application

Use Symfony's `null://null` or `in-memory://` transport in your tests as usual. To exercise this transport without a
network, set `httpTransport` on the factory to the fake transport of the client library (`Duva\Http\TestTransport`):

```php
$factory = new DuvaTransportFactory();
$factory->httpTransport = new TestTransport(fn () => TestTransport::response(['id' => 'msg_...', 'status' => 'queued']));
```

## License

MIT.
