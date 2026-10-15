<?php

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Psr\Http\Message\RequestInterface;

App::uses('AwsSesDsn', 'Aws.Network/Email');
App::uses('CakeEmail', 'Network/Email');

class AwsSesDsnTest extends CakeTestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $dsnClassMap = null;

    private ?string $awsRegion = null;

    private ?string $awsDefaultRegion = null;

    public function setUp(): void
    {
        parent::setUp();
        Configure::delete('Aws');
        $this->awsRegion = getenv('AWS_REGION') === false ? null : getenv('AWS_REGION');
        $this->awsDefaultRegion = getenv('AWS_DEFAULT_REGION') === false ? null : getenv('AWS_DEFAULT_REGION');
        putenv('AWS_REGION');
        putenv('AWS_DEFAULT_REGION');
        if (method_exists('CakeEmail', 'getDsnClassMap')) {
            $this->dsnClassMap = CakeEmail::getDsnClassMap();
        }
    }

    public function tearDown(): void
    {
        Configure::delete('Aws');
        putenv($this->awsRegion === null ? 'AWS_REGION' : 'AWS_REGION=' . $this->awsRegion);
        putenv($this->awsDefaultRegion === null ? 'AWS_DEFAULT_REGION' : 'AWS_DEFAULT_REGION=' . $this->awsDefaultRegion);
        if ($this->dsnClassMap !== null) {
            $property = new ReflectionProperty('CakeEmail', '_dsnClassMap');
            $property->setAccessible(true);
            $property->setValue(null, $this->dsnClassMap);
        }
        parent::tearDown();
    }

    /**
     * @param string $scheme Scheme.
     * @param array $query Query parameters.
     * @param array $parts Other parts.
     * @return array
     */
    private function parts(string $scheme, array $query = [], array $parts = []): array
    {
        return ['scheme' => $scheme] + $parts + ['host' => 'default', 'query' => $query];
    }

    public function testApi(): void
    {
        $this->assertSame(
            ['transport' => 'Aws.AmazonSesApi', 'key' => 'ACCESS', 'secret' => 'SE/CRET', 'token' => 'TOKEN', 'region' => 'eu-central-1'],
            AwsSesDsn::api($this->parts('ses+api', ['region' => 'eu-central-1', 'session_token' => 'TOKEN'], ['username' => 'ACCESS', 'password' => 'SE/CRET']), []),
        );
    }

    public function testApiWithoutCredentialsUsesTheRegionFallbacks(): void
    {
        $this->assertSame(['transport' => 'Aws.AmazonSesApi', 'region' => 'eu-west-1'], AwsSesDsn::api($this->parts('ses+api'), []));
        $this->assertSame(['transport' => 'Aws.AmazonSesApi', 'region' => 'us-east-2'], AwsSesDsn::api($this->parts('ses+api'), ['region' => 'us-east-2']));

        putenv('AWS_DEFAULT_REGION=ap-southeast-1');
        $this->assertSame('ap-southeast-1', AwsSesDsn::api($this->parts('ses+api'), [])['region']);
        putenv('AWS_REGION=ap-south-1');
        $this->assertSame('ap-south-1', AwsSesDsn::api($this->parts('ses+api'), [])['region']);
        Configure::write('Aws.region', 'ap-northeast-1');
        $this->assertSame('ap-northeast-1', AwsSesDsn::api($this->parts('ses+api'), [])['region']);
    }

    public function testApiWithEndpointAndOtherOptions(): void
    {
        $this->assertSame(
            ['version' => 'latest', 'transport' => 'Aws.AmazonSesApi', 'region' => 'eu-west-1', 'endpoint' => 'https://localhost:4566'],
            AwsSesDsn::api($this->parts('ses+https', ['version' => 'latest'], ['host' => 'localhost', 'port' => 4566]), []),
        );
    }

    public function testSmtp(): void
    {
        $this->assertSame(
            [
                'transport' => 'Smtp',
                'host' => 'ssl://email-smtp.us-east-1.amazonaws.com',
                'port' => 465,
                'tls' => false,
                'username' => 'SMTP_USER',
                'password' => 'SMTP+PASS',
            ],
            AwsSesDsn::smtp($this->parts('ses+smtp', ['region' => 'us-east-1', 'ping_threshold' => '10'], ['username' => 'SMTP_USER', 'password' => 'SMTP+PASS']), []),
        );
        $this->assertSame(
            ['timeout' => '10', 'transport' => 'Smtp', 'host' => 'ssl://smtp.example.com', 'port' => 2465, 'tls' => false],
            AwsSesDsn::smtp($this->parts('ses+smtps', ['timeout' => '10'], ['host' => 'smtp.example.com', 'port' => 2465]), []),
        );
        $this->assertSame('ssl://email-smtp.eu-west-1.amazonaws.com', AwsSesDsn::smtp($this->parts('ses+smtp'), [])['host']);
    }

    public function testRegisterWithCallable(): void
    {
        $registered = null;
        $this->assertTrue(AwsSesDsn::register(function (array $map) use (&$registered): void {
            $registered = $map;
        }));
        $this->assertSame(AwsSesDsn::classMap(), $registered);
        $this->assertSame(['ses', 'ses+api', 'ses+https', 'ses+smtp', 'ses+smtps'], array_keys($registered));
        $this->assertSame([AwsSesDsn::class, 'api'], $registered['ses+api']);
        $this->assertSame([AwsSesDsn::class, 'smtp'], $registered['ses+smtp']);
    }

    public function testRegister(): void
    {
        if (!method_exists('CakeEmail', 'setDsnClassMap')) {
            $this->assertFalse(AwsSesDsn::register());

            return;
        }

        $this->assertTrue(AwsSesDsn::register());
        $map = CakeEmail::getDsnClassMap();
        foreach (['ses', 'ses+api', 'ses+https', 'ses+smtp', 'ses+smtps'] as $scheme) {
            $this->assertArrayHasKey($scheme, $map);
        }

        $this->assertSame(
            ['transport' => 'Aws.AmazonSesApi', 'key' => 'ACCESS', 'secret' => 'SECRET', 'region' => 'eu-west-1'],
            CakeEmail::parseDsn('ses+api://ACCESS:SECRET@default?region=eu-west-1'),
        );
        $smtp = CakeEmail::parseDsn('ses+smtp://USER:P%40SS@default?region=ap-northeast-1');
        $this->assertSame('ssl://email-smtp.ap-northeast-1.amazonaws.com', $smtp['host']);
        $this->assertSame('P@SS', $smtp['password']);
    }

    public function testSendWithMailerDsn(): void
    {
        $this->skipIf(!method_exists('CakeEmail', 'setDsnClassMap'), 'pieceofcake2/cakephp has no DSN class map.');

        $mock = new MockHandler();
        $request = null;
        Configure::write('Aws', [
            'version' => 'latest',
            'handler' => function (CommandInterface $command, RequestInterface $httpRequest) use ($mock, &$request) {
                $request = $httpRequest;

                return $mock($command, $httpRequest);
            },
        ]);
        $mock->append(new Result(['MessageId' => 'dsn-message-id']));
        AwsSesDsn::register();

        $email = new CakeEmail([
            'dsn' => 'ses+api://dsn-key:dsn-secret@default?region=eu-central-1&session_token=dsn-token',
            'transport' => 'Mail',
        ]);
        $result = $email->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $this->assertInstanceOf('AmazonSesApiTransport', $email->transportClass());
        $this->assertSame('dsn-message-id', $result['id']);
        $this->assertStringContainsString('email.eu-central-1.amazonaws.com', (string)$request->getUri());
        $this->assertStringContainsString('Credential=dsn-key/', $request->getHeaderLine('Authorization'));
        $this->assertSame('dsn-token', $request->getHeaderLine('X-Amz-Security-Token'));
    }
}
