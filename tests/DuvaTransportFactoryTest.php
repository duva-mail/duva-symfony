<?php

declare(strict_types=1);

namespace Duva\Symfony\Mailer\Tests;

use Duva\Http\TestTransport;
use Duva\Symfony\Mailer\DuvaMailerBundle;
use Duva\Symfony\Mailer\DuvaTransport;
use Duva\Symfony\Mailer\DuvaTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class DuvaTransportFactoryTest extends TestCase
{
    public function testCreatesADuvaTransportFromADsn(): void
    {
        $transport = (new DuvaTransportFactory())->create(Dsn::fromString('duva://dv_key@default?domain=example.com'));

        self::assertInstanceOf(DuvaTransport::class, $transport);
    }

    public function testSupportsOnlyTheDuvaScheme(): void
    {
        $factory = new DuvaTransportFactory();

        self::assertTrue($factory->supports(Dsn::fromString('duva://dv_key@default?domain=example.com')));
        self::assertFalse($factory->supports(Dsn::fromString('smtp://localhost')));
        $this->expectException(UnsupportedSchemeException::class);
        $factory->create(Dsn::fromString('smtp://localhost'));
    }

    public function testRequiresADomain(): void
    {
        $this->expectException(IncompleteDsnException::class);
        (new DuvaTransportFactory())->create(Dsn::fromString('duva://dv_key@default'));
    }

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(IncompleteDsnException::class);
        (new DuvaTransportFactory())->create(Dsn::fromString('duva://default?domain=example.com'));
    }

    public function testSymfonyBuildsAWorkingMailerFromAMailerDsn(): void
    {
        $http = new TestTransport(static fn () => TestTransport::response(['id' => 'msg_' . str_repeat('0', 31) . '1', 'status' => 'queued']));
        $factory = new DuvaTransportFactory();
        $factory->httpTransport = $http;

        $mailer = new Mailer((new Transport([$factory]))->fromString('duva://dv_secret@default?domain=example.com'));
        $mailer->send((new Email())->from('shop@example.com')->to('a@example.org')->subject('S')->text('x'));

        self::assertCount(1, $http->requests);
        self::assertSame('/v1/example.com/messages', $http->requests[0]->path);
    }

    public function testTheBundleRegistersTheFactoryWithTheMailerTag(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test'); // read by the bundle extension machinery of Symfony < 8
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setDefinition('mailer.transport_factory.abstract', (new \Symfony\Component\DependencyInjection\Definition())->setAbstract(true));
        $bundle = new DuvaMailerBundle();
        $bundle->getContainerExtension()->load([], $container);

        $definition = $container->getDefinition('mailer.transport_factory.duva');
        self::assertInstanceOf(ChildDefinition::class, $definition);
        self::assertSame('mailer.transport_factory.abstract', $definition->getParent());
        self::assertArrayHasKey('mailer.transport_factory', $definition->getTags());
        self::assertSame(DuvaTransportFactory::class, $definition->getClass());
    }
}
