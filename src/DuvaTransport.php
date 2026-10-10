<?php

declare(strict_types=1);

namespace Duva\Symfony\Mailer;

use Duva\Attachment;
use Duva\Client;
use Duva\Exceptions\DuvaException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends a Symfony `Email` through the Duva API.
 *
 * Duva refuses a few things Symfony allows, and this class translates rather than failing late:
 * a display name Duva would reject is dropped (the address is kept), and only the headers Duva
 * accepts are forwarded. Symfony's `TagHeader` and `MetadataHeader` become Duva's `tags` and `metadata`. A message ID is NOT forwarded: Duva sets its own, and the
 * `SentMessage` reports it.
 */
final class DuvaTransport extends AbstractTransport
{
    /** Duva headers allowlist (docs/api.md, "headers"); every other `X-*` is allowed except the reserved prefixes. */
    private const ALLOWED_HEADERS = [
        'list-unsubscribe', 'list-unsubscribe-post', 'list-id', 'in-reply-to', 'references',
        'auto-submitted', 'precedence', 'importance', 'feedback-id',
    ];

    private const RESERVED_PREFIXES = ['x-kumo', 'x-duva', 'x-tenant', 'x-campaign'];

    /** Read by this transport, never forwarded: a stable key so a retried job cannot duplicate a message. */
    public const IDEMPOTENCY_HEADER = 'X-Idempotency-Key';

    /**
     * @param mixed $dispatcher an event dispatcher (its interface differs between Symfony 6.4, 7 and 8)
     */
    public function __construct(
        private readonly Client $client,
        mixed $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (!$original instanceof Email) {
            throw new TransportException('Duva: only Symfony\\Component\\Mime\\Email messages can be sent, got ' . $original::class . '.');
        }

        try {
            $result = $this->client->messages->send($this->params($original, $message));
        } catch (DuvaException | InvalidArgumentException $e) {
            // Symfony's failover transport and Messenger's retries react to TransportException only.
            throw new TransportException('Duva: ' . $e->getMessage(), 0, $e);
        }

        $message->setMessageId($result->id);
    }

    public function __toString(): string
    {
        return 'duva://api';
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Email $email, SentMessage $sent): array
    {
        $from = $email->getFrom();
        if ($from === []) {
            throw new InvalidArgumentException('the message has no From address');
        }
        $to = self::addresses($email->getTo());
        $cc = self::addresses($email->getCc());
        $bcc = self::addresses($email->getBcc());

        // A recipient only in the envelope (an explicit `Envelope`) is a Bcc.
        $known = array_map(strtolower(...), array_map(static fn (Address $a): string => $a->getAddress(), [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]));
        foreach ($sent->getEnvelope()->getRecipients() as $recipient) {
            if (!in_array(strtolower($recipient->getAddress()), $known, true)) {
                $bcc[] = self::format($recipient);
                $known[] = strtolower($recipient->getAddress());
            }
        }

        $params = [
            'from' => self::format($from[0]),
            'to' => $to,
            'subject' => $email->getSubject() ?? '',
        ];
        if ($cc !== []) {
            $params['cc'] = $cc;
        }
        if ($bcc !== []) {
            $params['bcc'] = $bcc;
        }
        $html = self::body($email->getHtmlBody());
        $text = self::body($email->getTextBody());
        if ($html !== null) {
            $params['html'] = $html;
        }
        if ($text !== null) {
            $params['text'] = $text;
        }
        $replyTo = $email->getReplyTo();
        if ($replyTo !== []) {
            $params['reply_to'] = self::format($replyTo[0]);
        }

        $tags = [];
        $metadata = [];
        $headers = [];
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            $lower = strtolower($name);
            if ($header instanceof TagHeader) {
                $tags[] = $header->getValue();
            } elseif ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
            } elseif ($lower === strtolower(self::IDEMPOTENCY_HEADER)) {
                $params['idempotency_key'] = $header->getBodyAsString();
            } elseif (self::forwardable($lower)) {
                $headers[$name] = $header->getBodyAsString();
            }
        }
        if ($tags !== []) {
            $params['tags'] = $tags;
        }
        if ($metadata !== []) {
            $params['metadata'] = $metadata;
        }
        if ($headers !== []) {
            $params['headers'] = $headers;
        }

        $attachments = array_map(static fn (DataPart $part): Attachment => self::attachment($part, $html), $email->getAttachments());
        if ($attachments !== []) {
            $params['attachments'] = $attachments;
        }

        return $params;
    }

    private static function forwardable(string $lowerName): bool
    {
        if (in_array($lowerName, self::ALLOWED_HEADERS, true)) {
            return true;
        }
        if (!str_starts_with($lowerName, 'x-')) {
            return false;
        }
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($lowerName, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function attachment(DataPart $part, ?string $html): Attachment
    {
        $filename = basename(str_replace('\\', '/', $part->getFilename() ?? 'attachment'));

        return Attachment::fromBytes(
            $filename === '' ? 'attachment' : $filename,
            $part->getBody(),
            $part->getMediaType() . '/' . $part->getMediaSubtype(),
            self::contentId($part, $html),
        );
    }

    /**
     * The `Content-ID` of an inline part, as the HTML refers to it (`cid:...`). Symfony lets the HTML refer to an
     * embedded part by its NAME and generates the real Content-ID only when it builds the MIME body, which has not
     * happened yet here: the identifier the HTML uses is therefore the name, or an explicit Content-ID.
     */
    private static function contentId(DataPart $part, ?string $html): ?string
    {
        if ($part->getDisposition() !== 'inline') {
            return null;
        }
        $candidates = array_filter([$part->getName(), $part->hasContentId() ? $part->getContentId() : null]);
        foreach ($candidates as $candidate) {
            if ($html !== null && str_contains($html, 'cid:' . $candidate)) {
                return $candidate;
            }
        }

        return $part->hasContentId() ? $part->getContentId() : null;
    }

    /**
     * @param array<int, Address> $addresses
     * @return array<int, string>
     */
    private static function addresses(array $addresses): array
    {
        return array_map(self::format(...), $addresses);
    }

    /** `Name <address>`, or the bare address when Duva would refuse the name (`@`, quotes, `=?`, control characters). */
    private static function format(Address $address): string
    {
        $name = $address->getName();
        if ($name === '' || preg_match('/[@"<>\\\\\x00-\x1f\x7f]|=\?/', $name) === 1) {
            return $address->getAddress();
        }

        return $name . ' <' . $address->getAddress() . '>';
    }

    /** @param resource|string|null $body */
    private static function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return is_string($body) && $body !== '' ? $body : null;
    }
}
