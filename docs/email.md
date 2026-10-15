# Amazon SES Email Transport

`Aws.AmazonSesApi` sends email with the `SendEmail` operation of the Amazon SES API v2.
Set it as the transport of an email profile in `config/email.php` (legacy layout:
`app/Config/email.php`):

```php
class EmailConfig
{
    /**
     * Default email profile values.
     *
     * @var array
     */
    public $default = [
        'transport' => 'Aws.AmazonSesApi',
        'from' => 'example@example.com',
        'charset' => 'utf-8',
        'headerCharset' => 'utf-8',
    ];
}
```

The SES client is created with the `Aws` configuration. These keys of the email profile
override it:

| Key | Description |
| --- | --- |
| `region` | AWS region of SES. |
| `version` | API version. |
| `key`, `secret` | Access key ID and secret access key. When they are not set, the credentials of the `Aws` configuration or the default credential provider chain are used. |

The transport sends the text and HTML parts, the `To`, `Cc`, `Bcc` and `Reply-To`
addresses, and the attachments. `CakeEmail::send()` returns the SES message ID as `id`:

```php
App::uses('CakeEmail', 'Network/Email');

$email = new CakeEmail('default');
$result = $email->to('user@example.com')
    ->subject('Welcome')
    ->send('Hello!');

$messageId = $result['id'];
```

The sender address (`from`) must be verified in SES.
