<?php

use Aws\SesV2\SesV2Client;

App::uses('AbstractTransport', 'Network/Email');
App::uses('CakeEmail', 'Network/Email');
App::uses('ComponentCollection', 'Controller');

/**
 * Amazon SES Email Transport for CakePHP 2.x.
 *
 * Uses aws/aws-sdk-php to send emails via Amazon SES API.
 * Supports IAM Role via AWS SDK default credential provider chain if key/secret not configured.
 * Configure AWS settings under Configure::write('Aws').
 */
class AmazonSesApiTransport extends AbstractTransport
{
    /**
     * Default AWS/SES configuration.
     *
     * @var array
     */
    protected $_config = [
        'region' => null,
        'key' => null,
        'secret' => null,
    ];

    private AwsComponent $Aws;

    /**
     * Merge AWS config from Configure::read('Aws') and transport config.
     */
    public function __construct(array $config = [])
    {
        // Merge: defaults -> global -> instance config
        $this->_config = array_merge(
            $this->_config,
            $config
        );

        /** @var AwsComponent $Aws */
        $this->Aws = (new ComponentCollection())
            ->load('Aws.Aws');
    }

    public function send(CakeEmail $email)
    {
        // Initialize SES V2 client
        $clientConfig = [];
        if (!empty($this->_config['region'])) {
            $clientConfig['region'] = $this->_config['region'];
        }
        if (!empty($this->_config['version'])) {
            $clientConfig['version'] = $this->_config['version'];
        }
        if (!empty($this->_config['key']) && !empty($this->_config['secret'])) {
            $clientConfig['credentials'] = [
                'key' => $this->_config['key'],
                'secret' => $this->_config['secret'],
            ];
        }

        /** @var SesV2Client $sesV2Client */
        $sesV2Client = $this->Aws->createSesV2($clientConfig);

        // Extract all email properties
        $from = $this->getAddresses($email->from());
        $to = $this->getAddresses($email->to());
        $cc = $this->getAddresses($email->cc());
        $bcc = $this->getAddresses($email->bcc());
        $replyTo = $this->getAddresses($email->replyTo());
        $subject = mb_decode_mimeheader($email->subject());
        $text = $email->message('text');
        $html = $email->message('html');
        $attachments = $email->attachments();

        // Destination
        $destination = ['ToAddresses' => $to];
        if ($cc) {
            $destination['CcAddresses'] = $cc;
        }
        if ($bcc) {
            $destination['BccAddresses'] = $bcc;
        }

        // Content
        $content = ['Simple' => [
            'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
            'Body' => [],
        ]];
        if (!empty($text)) {
            $content['Simple']['Body']['Text'] = [
                'Data' => $text,
                'Charset' => 'UTF-8',
            ];
        }
        if (!empty($html)) {
            $content['Simple']['Body']['Html'] = [
                'Data' => $html,
                'Charset' => 'UTF-8',
            ];
        }

        // Attachments
        if ($attachments) {
            $content['Simple']['Attachments'] = [];
            foreach ($attachments as $filename => $fileInfo) {
                if (isset($fileInfo['file'])) {
                    // Use raw binary content directly
                    $rawContent = file_get_contents($fileInfo['file']);
                    $contentType = $fileInfo['mimetype'] ?? mime_content_type($fileInfo['file']);
                } else {
                    // Decode CakeEmail's chunk-split Base64 back to binary
                    $encoded = $fileInfo['data'];
                    $rawContent = base64_decode($encoded, true);
                    $contentType = $fileInfo['mimetype'] ?? 'application/octet-stream';
                }
                $content['Simple']['Attachments'][] = [
                    'FileName' => $filename,
                    'RawContent' => $rawContent,
                    'ContentType' => $contentType,
                    'ContentDisposition' => 'ATTACHMENT',
                    'ContentTransferEncoding' => 'BASE64',
                ];
            }
        }

        // Assemble params
        $params = [
            'FromEmailAddress' => current($from),
            'Destination' => $destination,
            'Content' => $content,
        ];
        if ($replyTo) {
            $params['ReplyToAddresses'] = $replyTo;
        }

        // Send email via SES API v2
        $result = $sesV2Client->sendEmail($params);

        return ['id' => $result->get('MessageId')];
    }

    /**
     * Flatten various CakeEmail address formats into a simple array of email strings.
     *
     * @param mixed $addresses string, associative array, or nested array
     *
     * @return string[]
     */
    private function getAddresses(array $addresses): array
    {
        $list = [];
        // Normalize various CakeEmail address formats into email => name pairs
        foreach ((array) $addresses as $email => $name) {
            // Handle numeric keys (list of emails)
            if (is_int($email)) {
                $email = $name;
            }

            // If name is provided and differs, include it; otherwise use email only
            if ($name && $name !== $email) {
                // Escape double quotes in name
                $escapedName = str_replace('"', '\"', $name);
                $list[] = sprintf('"%s" <%s>', $escapedName, $email);
            } else {
                $list[] = $email;
            }
        }

        return $list;
    }
}
