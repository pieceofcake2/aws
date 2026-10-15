<?php

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Psr\Http\Message\RequestInterface;

App::uses('AmazonSesApiTransport', 'Aws.Network/Email');
App::uses('CakeEmail', 'Network/Email');

class AmazonSesApiTransportTest extends CakeTestCase
{
    private MockHandler $mock;

    private ?RequestInterface $request = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHandler();
        $mock = $this->mock;
        Configure::write('Aws', [
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'credentials' => ['key' => 'global-key', 'secret' => 'global-secret'],
            'handler' => function (CommandInterface $command, RequestInterface $request) use ($mock) {
                $this->request = $request;

                return $mock($command, $request);
            },
        ]);
    }

    public function tearDown(): void
    {
        Configure::delete('Aws');
        parent::tearDown();
    }

    private function email(array $config = []): CakeEmail
    {
        return new CakeEmail(['transport' => 'Aws.AmazonSesApi'] + $config);
    }

    private function lastCommand(): CommandInterface
    {
        return $this->mock->getLastCommand();
    }

    public function testSendText(): void
    {
        $this->mock->append(new Result(['MessageId' => 'message-id']));

        $result = $this->email()
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $this->assertSame('message-id', $result['id']);

        $command = $this->lastCommand();
        $this->assertSame('SendEmail', $command->getName());
        $this->assertSame('from@example.com', $command['FromEmailAddress']);
        $this->assertSame(['ToAddresses' => ['to@example.com']], $command['Destination']);
        $this->assertSame(['Data' => 'Hello', 'Charset' => 'UTF-8'], $command['Content']['Simple']['Subject']);
        $this->assertStringContainsString('Body', $command['Content']['Simple']['Body']['Text']['Data']);
        $this->assertArrayNotHasKey('Html', $command['Content']['Simple']['Body']);
        $this->assertArrayNotHasKey('Attachments', $command['Content']['Simple']);
        $this->assertArrayNotHasKey('ReplyToAddresses', $command->toArray());
        $this->assertStringContainsString('email.ap-northeast-1.amazonaws.com', (string)$this->request->getUri());
        $this->assertStringContainsString('Credential=global-key/', $this->request->getHeaderLine('Authorization'));
    }

    public function testSendWithAllRecipientsAndBothFormats(): void
    {
        $this->mock->append(new Result(['MessageId' => 'message-id']));

        $this->email()
            ->emailFormat('both')
            ->from('from@example.com', 'Sender "Name"')
            ->to(['to1@example.com' => 'To One', 'to2@example.com'])
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->replyTo('reply@example.com', 'Reply')
            ->subject('こんにちは')
            ->send('Body');

        $command = $this->lastCommand();
        $this->assertSame('"Sender \"Name\"" <from@example.com>', $command['FromEmailAddress']);
        $this->assertSame([
            'ToAddresses' => ['"To One" <to1@example.com>', 'to2@example.com'],
            'CcAddresses' => ['cc@example.com'],
            'BccAddresses' => ['bcc@example.com'],
        ], $command['Destination']);
        $this->assertSame(['"Reply" <reply@example.com>'], $command['ReplyToAddresses']);
        $this->assertSame('こんにちは', $command['Content']['Simple']['Subject']['Data']);
        $this->assertStringContainsString('Body', $command['Content']['Simple']['Body']['Text']['Data']);
        $this->assertStringContainsString('Body', $command['Content']['Simple']['Body']['Html']['Data']);
    }

    public function testSendWithAttachments(): void
    {
        $this->mock->append(new Result(['MessageId' => 'message-id']));

        $this->email()
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Attachments')
            ->attachments([
                'license.txt' => dirname(__DIR__, 4) . DS . 'LICENSE',
                'data.bin' => ['data' => 'binary data', 'mimetype' => 'application/octet-stream'],
            ])
            ->send('Body');

        $attachments = $this->lastCommand()['Content']['Simple']['Attachments'];
        $this->assertCount(2, $attachments);

        $this->assertSame('license.txt', $attachments[0]['FileName']);
        $this->assertSame(file_get_contents(dirname(__DIR__, 4) . DS . 'LICENSE'), $attachments[0]['RawContent']);
        $this->assertSame('text/plain', $attachments[0]['ContentType']);
        $this->assertSame('ATTACHMENT', $attachments[0]['ContentDisposition']);

        $this->assertSame('data.bin', $attachments[1]['FileName']);
        $this->assertSame('binary data', $attachments[1]['RawContent']);
        $this->assertSame('application/octet-stream', $attachments[1]['ContentType']);
    }

    public function testSendWithTransportConfig(): void
    {
        $this->mock->append(new Result(['MessageId' => 'message-id']));

        $this->email([
            'region' => 'us-west-2',
            'key' => 'transport-key',
            'secret' => 'transport-secret',
        ])
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $this->assertStringContainsString('email.us-west-2.amazonaws.com', (string)$this->request->getUri());
        $this->assertStringContainsString('Credential=transport-key/', $this->request->getHeaderLine('Authorization'));
    }

    public function testSendWithSessionTokenAndEndpoint(): void
    {
        $this->mock->append(new Result(['MessageId' => 'message-id']));

        $this->email([
            'region' => 'us-west-2',
            'key' => 'transport-key',
            'secret' => 'transport-secret',
            'token' => 'session-token',
            'endpoint' => 'https://ses.example.com:8443',
        ])
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Hello')
            ->send('Body');

        $this->assertSame('ses.example.com', $this->request->getUri()->getHost());
        $this->assertSame(8443, $this->request->getUri()->getPort());
        $this->assertSame('session-token', $this->request->getHeaderLine('X-Amz-Security-Token'));
    }
}
