<?php

namespace PHPPM\Bridges;

use PHPPM\Bootstraps\ApplicationEnvironmentAwareInterface;
use PHPPM\Bootstraps\BootstrapInterface;
use PHPPM\Bootstraps\HooksInterface;
use PHPPM\Bootstraps\RequestClassProviderInterface;
use React\EventLoop\LoopInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Zend\Diactoros\ServerRequest;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpKernel implements BridgeInterface
{
    /**
     * @var Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface
     */
    protected $psrFactory;

    /**
     * @var Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface
     */
    protected $symfonyFactory;

    /**
     * An application implementing the HttpKernelInterface
     *
     * @var \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    protected $application;

    /**
     * @var BootstrapInterface
     */
    protected $bootstrap;

    /**
     * Bootstrap an application implementing the HttpKernelInterface.
     *
     * In the process of bootstrapping we decorate our application with any number of
     * *middlewares* using StackPHP's Stack\Builder.
     *
     * The app bootstraping itself is actually proxied off to an object implementing the
     * PHPPM\Bridges\BridgeInterface interface which should live within your app itself and
     * be able to be autoloaded.
     *
     * @param string $appBootstrap The name of the class used to bootstrap the application
     * @param string|null $appenv The environment your application will use to bootstrap (if any)
     * @param boolean $debug If debug is enabled
     * @see http://stackphp.com
     */
    public function bootstrap($appBootstrap, $appenv, $debug, LoopInterface $loop)
    {
        $this->psrFactory = new DiactorosFactory();
        $this->symfonyFactory = new HttpFoundationFactory();

        $appBootstrap = $this->normalizeAppBootstrap($appBootstrap);

        $this->bootstrap = new $appBootstrap();
        if ($this->bootstrap instanceof ApplicationEnvironmentAwareInterface) {
            $this->bootstrap->initialize($appenv, $debug);
        }
        if ($this->bootstrap instanceof BootstrapInterface) {
            $this->application = $this->bootstrap->getApplication();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStaticDirectory()
    {
        return $this->bootstrap->getStaticDirectory();
    }

    /**
     * Handle a request using a HttpKernelInterface implementing application.
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws \Exception
     */
    public function onRequest(RequestInterface $request)
    {
        if (null === $this->application) {
            return;
        }

        $serverRequest = new ServerRequest(
            // array $serverParams = [],
            [],
            // array $uploadedFiles = [],
            [],
            // $uri = null,
            $request->getUri(),
            // $method = null,
            $request->getMethod(),
            // $body = 'php://input',
            $request->getBody(),
            // array $headers = [],
            $request->getHeaders(),
            // array $cookies = [],
            $request->getCookies(),
            // array $queryParams = [],
            $request->getQuery(),
            // $parsedBody = null,
            null,
            // $protocol = '1.1'
            $request->getProtocolVersion()
        );

        $syRequest = $symfonyFactory->createRequest($serverRequest);

        if ($this->bootstrap instanceof HooksInterface) {
            $this->bootstrap->preHandle($this->application);
        }

        $syResponse = $this->application->handle($syRequest);

        if ($this->application instanceof TerminableInterface) {
            $this->application->terminate($syRequest, $syResponse);
        }

        if ($this->bootstrap instanceof HooksInterface) {
            $this->bootstrap->postHandle($this->application);
        }

        return $this->psrFactory->createResponse($syResponse);
    }

    /**
     * @param $appBootstrap
     * @return string
     * @throws \RuntimeException
     */
    protected function normalizeAppBootstrap($appBootstrap)
    {
        $appBootstrap = str_replace('\\\\', '\\', $appBootstrap);

        $bootstraps = [
            $appBootstrap,
            '\\' . $appBootstrap,
            '\\PHPPM\Bootstraps\\' . ucfirst($appBootstrap)
        ];

        foreach ($bootstraps as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }
    }
}
