<?php

declare(strict_types=1);

namespace Platformsh\FlexBridge;

use Platformsh\ConfigReader\Config;
const DEFAULT_MYSQL_ENDPOINT_TYPE = 'mysql:10.2';

const DEFAULT_POSTGRESQL_ENDPOINT_TYPE = 'postgresql:9.6';

mapPlatformShEnvironment();

/**
 * Map Platform.Sh environment variables to the values Symfony Flex expects.
 *
 * This is wrapped up into a function to avoid executing code in the global
 * namespace.
 */
function mapPlatformShEnvironment() : void
{
    $config = new Config();

    if (!$config->inRuntime()) {
        if ($config->inBuild()) {
            // In the build hook we still need to set a fake Doctrine URL in order to
            // work around bugs in Doctrine.
            setDefaultDoctrineUrl();
        }
        return;
    }

    // Set the application secret if it's not already set.
    // We force re-setting the APP_SECRET to ensure it's set in all of PHP's various
    // environment places.
    $secret = getenv('APP_SECRET') ?: $config->projectEntropy;
    setEnvVar('APP_SECRET', $secret);

    // Default to production. You can override this value by setting
    // `env:APP_ENV` as a project variable, or by adding it to the
    // .platform.app.yaml variables block.
    $appEnv = getenv('APP_ENV') ?: 'prod';
    setEnvVar('APP_ENV', $appEnv);

    mapPlatformShDatabase('database', $config);

    mapPlatformShMongoDatabase('mongodatabase', $config);

    // Set the Swiftmailer configuration if it's not set already.
    if (!getenv('MAILER_URL')) {
        mapPlatformShSwiftmailer($config);
    }
}

/**
 * Sets an environment variable in all the myriad places PHP can store it.
 *
 * @param string $name
 *   The name of the variable to set.
 * @param null|string $value
 *   The value to set.  Null to unset it.
 */
function setEnvVar(string $name, ?string $value) : void
{
    if (!putenv("$name=$value")) {
        throw new \RuntimeException('Failed to create environment variable: ' . $name);
    }
    $order = ini_get('variables_order');
    if (stripos($order, 'e') !== false) {
        $_ENV[$name] = $value;
    }
    if (stripos($order, 's') !== false) {
        if (strpos($name, 'HTTP_') !== false) {
            throw new \RuntimeException('Refusing to add ambiguous environment variable ' . $name . ' to $_SERVER');
        }
        $_SERVER[$name] = $value;
    }
}

function mapPlatformShSwiftmailer(Config $config)
{
    $mailUrl = sprintf(
        '%s://%s:%d/',
        'smtp',
        $config->smtpHost,
        25
    );

    setEnvVar('MAILER_URL', $mailUrl);
}

function doctrineFormatter(array $credentials) : string
{
    $dbUrl = sprintf(
        '%s://%s:%s@%s:%d/%s',
        $credentials['scheme'],
        $credentials['username'],
        $credentials['password'],
        $credentials['host'],
        $credentials['port'],
        $credentials['path']
    );

    switch ($credentials['scheme']) {
        case 'mysql':
            $type = $credentials['type'] ?? DEFAULT_MYSQL_ENDPOINT_TYPE;
            $versionPosition = strpos($type, ":");

            // If version is found, use it, otherwise, default to mariadb 10.2
            $dbVersion = (false !== $versionPosition) ? substr($type, $versionPosition + 1) : '10.2';

            // doctrine needs the mariadb-prefix if it's an instance of MariaDB server
            if ($dbVersion !== '5.5') {
                $dbVersion = sprintf('mariadb-%s', $dbVersion);
            }

            // if MariaDB is in version 10.2, doctrine needs to know it's superior to patch version 6 to work properly
            if ($dbVersion === 'mariadb-10.2') {
                $dbVersion = sprintf('%s.12', $dbVersion);
            }

            $dbUrl .= sprintf('?charset=utf8mb4&serverVersion=%s', $dbVersion);
            break;
        case 'pgsql':
            $type = $credentials['type'] ?? DEFAULT_POSTGRESQL_ENDPOINT_TYPE;
            $versionPosition = strpos($type, ":");

            $dbVersion = (false !== $versionPosition) ? substr($type, $versionPosition + 1) : '11';
            $dbUrl .= sprintf('?serverVersion=%s', $dbVersion);
            break;
    }

    return $dbUrl;

}

function mapPlatformShDatabase(string $relationshipName, Config $config) : void
{
    if (!$config->hasRelationship($relationshipName)) {
        return;
    }

    $config->registerFormatter('doctrine', __NAMESPACE__ . '\doctrineFormatter');

    setEnvVar('DATABASE_URL', $config->formattedCredentials($relationshipName, 'doctrine'));
}

function setDefaultDoctrineUrl() : void
{
    // Hack the Doctrine URL to be syntactically valid in a build hook, even
    // though it shouldn't be used.
    $dbUrl = sprintf(
        '%s://%s:%s@%s:%s/%s?charset=utf8mb4&serverVersion=%s',
        'mysql',
        '',
        '',
        'localhost',
        3306,
        '',
        $dbVersion ?? 'mariadb-10.2.12'
    );

    setEnvVar('DATABASE_URL', $dbUrl);
}

/**
 * Set the MONGODB_SERVER, MONGODB_DB, MONGODB_USERNAME, and MONGODB_PASSWORD for Doctrine, if necessary.
 * Usage of the full dsn string is NOT working with doctrine-odm-bundle, that's why
 * we need those 4 env variables.
 *
 * Here is a example of the related doctrine-odm-bundle:
 * doctrine_mongodb:
 *     connections:
 *         default:
 *             server: '%env(MONGODB_SERVER)%'
 *             options: { username: '%env(MONGODB_USERNAME)%', password: '%env(MONGODB_PASSWORD)%', authSource: '%env(MONGODB_DB)%' }
 *     default_database: '%env(MONGODB_DB)%'
 *
 * For more information: https://symfony.com/doc/master/bundles/DoctrineMongoDBBundle/index.html
 */
function mapPlatformShMongoDatabase(string $relationshipName, Config $config): void
{
    if (!$config->hasRelationship($relationshipName)) {
        return;
    }

    $credentials = $config->credentials($relationshipName);

    setEnvVar('MONGODB_SERVER', sprintf('mongodb://%s:%d', $credentials['host'], $credentials['port']));
    setEnvVar('MONGODB_DB', $credentials['path']);
    setEnvVar('MONGODB_USERNAME', $credentials['username']);
    setEnvVar('MONGODB_PASSWORD', $credentials['password']);
}
