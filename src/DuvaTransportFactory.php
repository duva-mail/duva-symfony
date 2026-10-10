<?php

declare(strict_types=1);

namespace Duva\Symfony\Mailer;

use Duva\Client;
use Duva\Http\TransportInterface as HttpTransport;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * `MAILER_DSN=duva://API_KEY@default?domain=example.com`
 *
 * The host is `default` for https://api.duva.ca, or the host of another Duva endpoint (tests). Options:
 * `domain` (required), `timeout` (seconds), `max_retries`.
 */
final class DuvaTransportFactory extends AbstractTransportFactory
{
    /** For tests: replaces the HTTP transport of the Duva client (e.g. Duva's own TestTransport). */
    public ?HttpTransport $httpTransport = null;

    public function create(Dsn $dsn): TransportInterface
    {
        if ($dsn->getScheme() !== 'duva') {
            throw new UnsupportedSchemeException($dsn, 'duva', $this->getSupportedSchemes());
        }

        $apiKey = $this->getUser($dsn);
        $domain = $dsn->getOption('domain');
        if (!is_string($domain) || $domain === '') {
            throw new IncompleteDsnException('The "domain" option is required: duva://API_KEY@default?domain=example.com');
        }
        $host = 'default' === $dsn->getHost() ? 'api.duva.ca' : $dsn->getHost();
        $scheme = in_array($dsn->getOption('tls', '1'), ['0', 0, false], true) ? 'http' : 'https';
        $baseUrl = $scheme . '://' . $host . ($dsn->getPort() !== null ? ':' . $dsn->getPort() : '');

        $client = new Client(
            apiKey: $apiKey,
            domain: $domain,
            baseUrl: $baseUrl,
            timeout: (float) $dsn->getOption('timeout', 10),
            maxRetries: (int) $dsn->getOption('max_retries', 2),
            transport: $this->httpTransport,
        );

        return new DuvaTransport($client, $this->dispatcher, $this->logger);
    }

    /** @return array<int, string> */
    protected function getSupportedSchemes(): array
    {
        return ['duva'];
    }
}
