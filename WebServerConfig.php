<?php
declare(strict_types=1);
namespace Viserio\Component\WebServer;

use Viserio\Component\Console\Command\AbstractCommand;
use Viserio\Component\Contract\OptionsResolver\Exception\InvalidArgumentException as OptionsResolverInvalidArgumentException;
use Viserio\Component\Contract\OptionsResolver\ProvidesDefaultOptions as ProvidesDefaultOptionsContract;
use Viserio\Component\Contract\OptionsResolver\RequiresConfig as RequiresConfigContract;
use Viserio\Component\Contract\OptionsResolver\RequiresValidatedConfig as RequiresValidatedConfigContract;
use Viserio\Component\Contract\WebServer\Exception\InvalidArgumentException;
use Viserio\Component\Contract\WebServer\Exception\RuntimeException;
use Viserio\Component\OptionsResolver\Traits\OptionsResolverTrait;

final class WebServerConfig implements RequiresConfigContract, ProvidesDefaultOptionsContract, RequiresValidatedConfigContract
{
    use OptionsResolverTrait;

    /**
     * Resolved options.
     *
     * @var array
     */
    private $resolvedOptions;

    /**
     * Create a new WebServerConfig instance.
     *
     * @param string                                             $documentRoot
     * @param string                                             $environment
     * @param \Viserio\Component\Console\Command\AbstractCommand $command
     */
    public function __construct(string $documentRoot, string $environment, AbstractCommand $command)
    {
        $config = [
            'disable-xdebug'  => ! \ini_get('xdebug.profiler_enable_trigger'),
            'pidfile'         => null,
            'document_root'   => $documentRoot,
            'env'             => $environment,
        ];

        if ($command->hasOption('host')) {
            $config['host'] = $command->option('host');
        }

        if ($command->hasOption('port')) {
            $config['port'] = $command->option('port');
        }

        if ($command->hasOption('router')) {
            $config['router'] = $command->option('router');
        } else {
            $config['router'] = __DIR__ . \DIRECTORY_SEPARATOR . 'Resources' . \DIRECTORY_SEPARATOR . 'router.php';
        }

        if ($command->hasOption('pidfile')) {
            $config['pidfile'] = $command->option('pidfile');
        }

        if ($command->hasOption('disable-xdebug')) {
            $config['disable-xdebug'] = true;
        }

        $this->resolvedOptions = self::findHostnameAndPort(self::resolveOptions($config));

        $_ENV['APP_FRONT_CONTROLLER'] = self::findFrontController(
            $this->resolvedOptions['document_root'],
            $this->resolvedOptions['env']
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function getDefaultOptions(): array
    {
        return [
            'host' => null,
            'port' => null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function getOptionValidators(): array
    {
        return [
            'document_root' => static function ($value) {
                if (! \is_dir($value)) {
                    throw new OptionsResolverInvalidArgumentException(\sprintf('The document root directory [%s] does not exist.', $value));
                }
            },
            'router' => static function ($value) {
                if (! \is_string($value)) {
                    throw OptionsResolverInvalidArgumentException::invalidType('router', $value, ['string'], self::class);
                }

                if (\realpath($value) === false) {
                    throw new OptionsResolverInvalidArgumentException(\sprintf('Router script [%s] does not exist.', $value));
                }
            },
            'host'           => ['string', 'null'],
            'port'           => ['int', 'string', 'null'],
            'disable-xdebug' => ['bool'],
        ];
    }

    /**
     * Return the path to the document folder, where you can find the index.php.
     *
     * @return string
     */
    public function getDocumentRoot(): string
    {
        return $this->resolvedOptions['document_root'];
    }

    /**
     * Return the environment.
     *
     * @return string
     */
    public function getEnv(): string
    {
        return $this->resolvedOptions['env'];
    }

    /**
     * Returns the router file.
     *
     * @return string
     */
    public function getRouter(): string
    {
        return $this->resolvedOptions['router'];
    }

    /**
     * Returns the given host name or the default 127.0.0.1.
     *
     * @return string
     */
    public function getHostname(): string
    {
        return $this->resolvedOptions['host'];
    }

    /**
     * Returns the given port or a found free port between 8000 and 8100.
     *
     * @return string
     */
    public function getPort(): string
    {
        return (string) $this->resolvedOptions['port'];
    }

    /**
     * Return the full address of the hostname:port.
     *
     * @return string
     */
    public function getAddress(): string
    {
        return $this->resolvedOptions['address'];
    }

    /**
     * Check if xdebug should be used.
     *
     * @return bool
     */
    public function hasXdebug(): bool
    {
        return $this->resolvedOptions['disable-xdebug'] === false;
    }

    /**
     * Return a path to the pid file it it was given.
     *
     * @return null|string
     */
    public function getPidFile(): ?string
    {
        return $this->resolvedOptions['pidfile'] ?? null;
    }

    /**
     * Contains resolved hostname if available.
     *
     * @return null|string
     */
    public function getDisplayAddress(): ?string
    {
        if ('0.0.0.0' !== $this->getHostname()) {
            return null;
        }

        if (false === $localHostname = \gethostname()) {
            return null;
        }

        return \gethostbyname($localHostname) . ':' . $this->getPort();
    }

    /**
     * Finds the front controller in root path.
     *
     * @param string $documentRoot
     * @param string $env
     *
     * @throws \Viserio\Component\Contract\WebServer\Exception\InvalidArgumentException
     *
     * @return string
     */
    private static function findFrontController(string $documentRoot, string $env): string
    {
        $fileNames = ['index_' . $env . '.php', 'index.php'];

        foreach ($fileNames as $fileName) {
            if (\file_exists($documentRoot . \DIRECTORY_SEPARATOR . $fileName)) {
                return $fileName;
            }
        }

        throw new InvalidArgumentException(
            \sprintf(
                'Unable to find the front controller under [%s] (none of these files exist: [%s]).',
                $documentRoot,
                \implode(', ', $fileNames)
            )
        );
    }

    /**
     * Finds a host and port.
     *
     * @param array $config
     *
     * @throws \Viserio\Component\Contract\WebServer\Exception\InvalidArgumentException
     *
     * @return array
     */
    private static function findHostnameAndPort(array $config): array
    {
        if ($config['host'] === null) {
            $config['host'] = '127.0.0.1';
            $config['port'] = self::findBestPort($config['host']);
        } elseif (isset($config['host'], $config['port']) && $config['port'] !== null && $config['host'] === '*') {
            $config['host'] = '0.0.0.0';
        } elseif ($config['port'] === null) {
            $config['port'] = self::findBestPort($config['host']);
        }

        if (! \ctype_digit((string) $config['port'])) {
            throw new InvalidArgumentException(\sprintf('Port [%s] is not valid.', (string) $config['port']));
        }

        $config['address'] = $config['host'] . ':' . $config['port'];

        return $config;
    }

    /**
     * Searching for the port between 8000 and 8100.
     *
     * @param string $host
     *
     * @return string
     */
    private static function findBestPort(string $host): string
    {
        $port = 8000;

        while (false !== $fp = @\fsockopen($host, $port, $errno, $errstr, 1)) {
            \fclose($fp);

            if ($port++ >= 8100) {
                throw new RuntimeException('Unable to find a port available to run the web server.');
            }
        }

        return (string) $port;
    }
}
