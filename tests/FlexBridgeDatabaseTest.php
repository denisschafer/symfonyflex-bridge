<?php

declare(strict_types=1);

namespace Platformsh\FlexBridge\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\FlexBridge\PlatformshFlexEnv;


class FlexBridgeDatabaseTest extends TestCase
{
    protected $relationships;
    protected $defaultDbUrl;

    public function setUp()
    {
        parent::setUp();

        $this->relationships = [
            'database' => [
                [
                    'scheme' => 'mysql',
                    'username' => 'user',
                    'password' => '',
                    'host' => 'database.internal',
                    'port' => '3306',
                    'path' => 'main',
                    'query' => ['is_master' => true],
                    'type' => 'mysql:10.2'
                ]
            ],
            'other_database' => [
                [
                    'scheme' => 'mysql',
                    'username' => 'user2',
                    'password' => '',
                    'host' => 'database.internal',
                    'port' => '3306',
                    'path' => 'other_main',
                    'query' => ['is_master' => true],
                    'type' => 'mysql:10.2'
                ]
            ],
        ];

        $this->defaultDbUrl = sprintf(
            '%s://%s:%s@%s:%s/%s?charset=utf8mb4&serverVersion=mariadb-10.2.12',
            'mysql',
            '',
            '',
            'localhost',
            3306,
            ''
        );
    }

    public function testNotOnPlatformshDoesNotSetDatabase(): void
    {
        (new PlatformshFlexEnv())->mapPlatformShEnvironment();

        $this->assertArrayNotHasKey('DATABASE_URL', $_SERVER);
    }

    public function testNoRelationshipsBecauseBuild(): void
    {
        // Application name but no environment name means build hook.

        putenv('PLATFORM_APPLICATION_NAME=test');
        putenv('PLATFORM_PROJECT_ENTROPY=test');
        putenv('PLATFORM_SMTP_HOST=1.2.3.4');

        //putenv($this->encodeRelationships($this->relationships));

        (new PlatformshFlexEnv())->mapPlatformShEnvironment();

        $this->assertEquals($this->defaultDbUrl, $_SERVER['DATABASE_URL']);
    }

    public function testNoDatabaseRelationshipInRuntime(): void
    {
        putenv('PLATFORM_APPLICATION_NAME=test');
        putenv('PLATFORM_ENVIRONMENT=test');
        putenv('PLATFORM_PROJECT_ENTROPY=test');
        putenv('PLATFORM_SMTP_HOST=1.2.3.4');

        $rels = $this->relationships;
        unset($rels['database']);

        putenv($this->encodeRelationships($rels));

        (new PlatformshFlexEnv())->mapPlatformShEnvironment();

        $this->assertArrayNotHasKey('DATABASE_URL', $_SERVER);
    }

    /**
     * @dataProvider databaseVersionsProvider
     */
    public function testDatabaseRelationshipFormatters(string $type, string $scheme, string $expected): void
    {
        putenv('PLATFORM_APPLICATION_NAME=test');
        putenv('PLATFORM_ENVIRONMENT=test');
        putenv('PLATFORM_PROJECT_ENTROPY=test');
        putenv('PLATFORM_SMTP_HOST=1.2.3.4');

        $rels = $this->relationships;

        $rels['database'][0]['type'] = $type;
        $rels['database'][0]['scheme'] = $scheme;

        putenv($this->encodeRelationships($rels));

        (new PlatformshFlexEnv())->mapPlatformShEnvironment();

        $this->assertEquals($expected, $_SERVER['DATABASE_URL']);
    }

    /**
     * @dataProvider databaseVersionsProvider
     */
    public function testDatabaseRelationshipFormattersFoundationV1(string $type, string $scheme, string $expected): void
    {
        putenv('PLATFORM_APPLICATION_NAME=test');
        putenv('PLATFORM_ENVIRONMENT=test');
        putenv('PLATFORM_PROJECT_ENTROPY=test');
        putenv('PLATFORM_SMTP_HOST=1.2.3.4');

        $rels = $this->relationships;

        $rels['database'][0]['scheme'] = $scheme;

        // On Foundation 1, there is no `type` property.  Therefore we do not know what the DB version
        // should be.  So we fall back to the default guesses and hope. If someone wants to use a different
        // version, they should move to Foundation 3.
        unset($rels['database'][0]['type']);
        switch ($scheme) {
            case 'mysql':
                $default = 'mariadb-' . PlatformshFlexEnv::DEFAULT_MARIADB_VERSION . '.12';
                break;
            case 'pgsql':
                $default = PlatformshFlexEnv::DEFAULT_POSTGRESQL_VERSION;
        }
        $expected = preg_replace('/serverVersion=.+$/', 'serverVersion=' . $default, $expected);

        putenv($this->encodeRelationships($rels));

        (new PlatformshFlexEnv())->mapPlatformShEnvironment();

        $this->assertEquals($expected, $_SERVER['DATABASE_URL']);
    }

    public function databaseVersionsProvider(): iterable
    {
        yield 'postgresql 9.6' => [
            'type' => 'postgresql:9.6',
            'scheme' => 'pgsql',
            'expected' => 'pgsql://user:@database.internal:3306/main?serverVersion=9.6',
        ];
        yield 'postgresql 10' => [
            'type' => 'postgresql:10',
            'scheme' => 'pgsql',
            'expected' => 'pgsql://user:@database.internal:3306/main?serverVersion=10',
        ];

        // This is the oddball that doesn't have a .0, because reasons.
        yield 'mariadb 10.2' => [
            'type' => 'mariadb:10.2',
            'scheme' => 'mysql',
            'expected' => 'mysql://user:@database.internal:3306/main?charset=utf8mb4&serverVersion=mariadb-10.2.12',
        ];

        yield 'mariadb 10.4' => [
            'type' => 'mariadb:10.4',
            'scheme' => 'mysql',
            'expected' => 'mysql://user:@database.internal:3306/main?charset=utf8mb4&serverVersion=mariadb-10.4.0',
        ];

        yield 'mariadb 10.2 aliased' => [
            'type' => 'mysql:10.2',
            'scheme' => 'mysql',
            'expected' => 'mysql://user:@database.internal:3306/main?charset=utf8mb4&serverVersion=mariadb-10.2.12',
        ];

        yield 'mysql 8' => [
            'type' => 'oracle-mysql:8.0',
            'scheme' => 'mysql',
            'expected' => 'mysql://user:@database.internal:3306/main?charset=utf8mb4&serverVersion=8.0',
        ];
    }

    /**
     * @dataProvider databaseNameProvider
     * @param string $dbname
     * @param string $expected
     */
    public function testDatabaseRelationshipNameFromEnv(string $dbname, ?string $expected): void
    {
        putenv('PLATFORM_APPLICATION_NAME=test');
        putenv('PLATFORM_ENVIRONMENT=test');
        putenv('PLATFORM_PROJECT_ENTROPY=test');
        putenv('PLATFORM_SMTP_HOST=1.2.3.4');

        putenv('APP_DB_USERNAME=' . $dbname);

        putenv($this->encodeRelationships($this->relationships));

        (new PlatformshFlexEnv())->mapPlatformShEnvironment();

        $this->assertEquals($expected, $_SERVER['DATABASE_URL']);
    }

    public function databaseNameProvider(): iterable
    {
        yield 'default' => [
            'dbname' => 'database',
            'expected' => 'mysql://user:@database.internal:3306/main?charset=utf8mb4&serverVersion=mariadb-10.2.12',
        ];

        yield 'database2' => [
            'dbname' => 'other_database',
            'expected' => 'mysql://user2:@database.internal:3306/other_main?charset=utf8mb4&serverVersion=mariadb-10.2.12',
        ];

        yield 'non_existing_database_key' => [
            'dbname' => 'fake_database',
            'expected' => null,
        ];
    }

    public function testInitdbRelationshipNames(): void
    {
        $env = new PlatformshFlexEnv();
        $reflector = new \ReflectionClass($env);
        $method = $reflector->getMethod('initdbRelationshipNames');
        $method->setAccessible(true);
        $method->invokeArgs($env, []);

        $property = $reflector->getProperty('dbName');
        $property->setAccessible(true);
        $this->assertEquals($reflector->getConstant('DB_NAME_DEFAULT'), $property->getValue($env));
    }

    public function testInitdbRelationshipNamesWithEnv(): void
    {
        putenv('APP_DB_USERNAME=testValue');
        $env = new PlatformshFlexEnv();
        $reflector = new \ReflectionClass($env);
        $method = $reflector->getMethod('initdbRelationshipNames');
        $method->setAccessible(true);
        $method->invokeArgs($env, []);

        $property = $reflector->getProperty('dbName');
        $property->setAccessible(true);
        $this->assertEquals('testValue', $property->getValue($env));
    }

    protected function encodeRelationships($rels): string
    {
        return sprintf('PLATFORM_RELATIONSHIPS=%s', base64_encode(json_encode($rels)));
    }
}
