<?php

declare(strict_types=1);

/**
 * Derafu: Session - Wiring of the session and the flash messages of Mezzio.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsSession;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Flash\FlashMessageMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * The services that an application gets by importing the file of the package:
 * the middlewares of the session and of the flash messages of Mezzio, and the
 * session of PHP configured with the environment variables `SESSION_*`.
 *
 * The configuration is checked in the cookie that the session sets, which is
 * what the visitor receives.
 */
#[CoversNothing]
final class SessionServicesTest extends TestCase
{
    private const VARIABLES = [
        'SESSION_NAME',
        'SESSION_LIFETIME_SECONDS',
        'SESSION_COOKIE_PATH',
        'SESSION_COOKIE_DOMAIN',
        'SESSION_COOKIE_SECURE',
        'SESSION_COOKIE_HTTP_ONLY',
        'SESSION_COOKIE_SAMESITE',
        'SESSION_CACHE_EXPIRE_MINUTES',
    ];

    private string $savePath;

    private string $previousSavePath;

    protected function setUp(): void
    {
        $this->savePath = sys_get_temp_dir() . '/derafu-session-test-' . bin2hex(random_bytes(4));
        mkdir($this->savePath);
        $this->previousSavePath = session_save_path();
        session_save_path($this->savePath);
    }

    protected function tearDown(): void
    {
        // The session of PHP leaves its data in a global.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unset($_SESSION);

        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
        foreach (glob($this->savePath . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->savePath);
        session_save_path($this->previousSavePath);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/resources/config')))
            ->load('session-services.yaml');
        $container->getDefinition(SessionMiddleware::class)->setPublic(true);
        $container->getDefinition(FlashMessageMiddleware::class)->setPublic(true);
        $container->compile(true);

        return $container;
    }

    /**
     * Handles a request and gives the cookie that the session sets, if it sets
     * one.
     *
     * @param callable(SessionInterface): void $callback What is done with the session.
     */
    private function cookie(ContainerBuilder $container, callable $callback, ?string $sid = null, ?string $name = null): ?string
    {
        $middleware = $container->get(SessionMiddleware::class);
        $this->assertInstanceOf(SessionMiddleware::class, $middleware);

        $request = new ServerRequest();
        if ($sid !== null) {
            $request = $request->withCookieParams([$name ?? 'DERAFU_SESSION' => $sid]);
        }

        $response = $middleware->process($request, new class ($callback) implements RequestHandlerInterface {
            public function __construct(private readonly mixed $callback)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
                assert($session instanceof SessionInterface);
                ($this->callback)($session);

                return new EmptyResponse();
            }
        });

        return $response->getHeaderLine('Set-Cookie') ?: null;
    }

    #[Test]
    public function theDefaultsAreASecureCookieOfTheSite(): void
    {
        $cookie = $this->cookie($this->container(), fn (SessionInterface $s) => $s->set('a', 1));

        $this->assertNotNull($cookie);
        $this->assertStringStartsWith('DERAFU_SESSION=', $cookie);
        $this->assertStringContainsString('Path=/', $cookie);
        $this->assertStringContainsString('Secure', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
    }

    #[Test]
    public function theVariablesOfTheEnvironmentConfigureTheCookie(): void
    {
        putenv('SESSION_NAME=MYSESSION');
        putenv('SESSION_COOKIE_PATH=/app');
        putenv('SESSION_COOKIE_SECURE=false');
        putenv('SESSION_COOKIE_HTTP_ONLY=false');
        putenv('SESSION_COOKIE_SAMESITE=Strict');

        $cookie = $this->cookie($this->container(), fn (SessionInterface $s) => $s->set('a', 1));

        $this->assertNotNull($cookie);
        $this->assertStringStartsWith('MYSESSION=', $cookie);
        $this->assertStringContainsString('Path=/app', $cookie);
        $this->assertStringNotContainsString('Secure', $cookie);
        $this->assertStringNotContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Strict', $cookie);
    }

    #[Test]
    public function theDurationsAreGivenInSecondsAndMinutes(): void
    {
        putenv('SESSION_LIFETIME_SECONDS=120');
        putenv('SESSION_CACHE_EXPIRE_MINUTES=15');

        $container = $this->container();
        $cookie = $this->cookie($container, fn (SessionInterface $s) => $s->set('a', 1));

        $this->assertNotNull($cookie);
        $this->assertMatchesRegularExpression('/Max-Age=1[12]\d/', $cookie);

        $persistence = $container->get(SessionMiddleware::class);
        $this->assertInstanceOf(SessionMiddleware::class, $persistence);
        $property = (new \ReflectionObject($persistence))->getProperty('persistence');
        $this->assertSame(15, (new \ReflectionObject($property->getValue($persistence)))->getProperty('cacheExpire')->getValue($property->getValue($persistence)));
    }

    #[Test]
    public function theSessionThatWasSetIsTheOneTheVisitorComesBackWith(): void
    {
        $container = $this->container();
        $cookie = $this->cookie($container, fn (SessionInterface $s) => $s->set('name', 'Ana'));
        $this->assertNotNull($cookie);
        preg_match('/^DERAFU_SESSION=([^;]+)/', $cookie, $matches);

        $seen = null;
        $again = $this->cookie($container, function (SessionInterface $s) use (&$seen): void {
            $seen = $s->get('name');
        }, $matches[1]);

        $this->assertSame('Ana', $seen);
        $this->assertNull($again, 'A session that did not change must not be set again.');
    }

    #[Test]
    public function theFlashMessagesMiddlewareIsThere(): void
    {
        $this->assertInstanceOf(FlashMessageMiddleware::class, $this->container()->get(FlashMessageMiddleware::class));
    }
}
