<?php

namespace Synapse\Log;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Monolog\Logger;
use Monolog\Handler\LogglyHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\ErrorHandler as MonologErrorHandler;
use Synapse\Log\Handler\RollbarHandler;
use Synapse\Log\Handler\DummyExceptionHandler;
use Synapse\Log\Formatter\ExceptionLineFormatter;
use Synapse\Config\Exception as ConfigException;
use Synapse\Stdlib\Arr;
use RollbarNotifier;

/**
 * Service provider for logging services.
 *
 * Register application logger and injected log handlers.
 */
class LogServiceProvider implements ServiceProviderInterface
{

    /**
     * Register logging related services
     *
     * @param  Application $app Silex application
     */
    public function register(Application $app)
    {
        $app['log.rollbar-handler'] = $app->share(function ($app) {
            $rollbarConfig = Arr::get($app['config']->load('log'), 'rollbar', []);
            return new RollbarHandler($rollbarConfig, $app['environment']);
        });

        $app['log.syslog-handler'] = $app->share(function ($app) {
            return new SyslogHandler($ident, LOG_LOCAL0);
        });

        $app['log.file-handler'] = $app->share(function ($app) {
            $file = Arr::path($app['config']->load('log'), 'file.path');
            $format = '[%datetime%] %channel%.%level_name%: %message% %context% %extra%'.PHP_EOL;

            $handler = new StreamHandler($file, Logger::INFO);
            $handler->setFormatter(new LineFormatter($format));

            return new DummyExceptionHandler($handler);
        });

        $app['log.file-exception-handler'] = $app->share(function ($app) {
            $file = Arr::path($app['config']->load('log'), 'file.path');
            $format = '%context.stacktrace%'.PHP_EOL;

            $handler = new StreamHandler($file, Logger::ERROR);
            $handler->setFormatter(new ExceptionLineFormatter($format));

            return $handler;
        });

        $app['log.loggly-handler'] = $app->share(function ($app) {
            $token = Arr::path($app['config']->load('log'), 'loggly.token');

            if (! $token) {
                throw new ConfigException('Loggly is enabled but the token is not set.');
            }

            return new LogglyHandler($token, Logger::INFO);
        });

        $app['log.handlers'] = $app->share(function ($app) {
            $handlers = [];
            $config = $app['config']->load('log');

            $file = Arr::path($config, 'file.path');
            if ($file) {
                $handlers[] = $app['log.file-handler'];
                $handlers[] = $app['log.file-exception-handler'];
            }

            $enableLoggly = Arr::path($config, 'loggly.enable');
            if ($enableLoggly) {
                $handlers[] = $app['log.loggly-handler'];
            }

            $enableRollbar = Arr::path($config, 'rollbar.enable');
            if ($enableRollbar) {
                $handlers[] = $app['log.rollbar-handler'];
            }

            $syslogIdent = Arr::path($config, 'syslog.ident');
            if ($syslogIdent) {
                $handlers[] = $app['log.syslog-handler'];
            }

            return $handlers;
        });

        $app['log'] = $app->share(function ($app) {
            return new Logger('main', $app['log.handlers']);
        });

        $app->initializer('Synapse\\Log\\LoggerAwareInterface', function ($object, $app) {
            $object->setLogger($app['log']);
            return $object;
        });
    }

    /**
     * Perform extra chores on boot
     *
     * @param  Application $app
     */
    public function boot(Application $app)
    {
        // Register Monolog error handler for fatal errors here because Symfony's handler overrides it
        $monologErrorHandler = new MonologErrorHandler($app['log']);

        $monologErrorHandler->registerErrorHandler();
        $monologErrorHandler->registerFatalHandler();
    }
}
