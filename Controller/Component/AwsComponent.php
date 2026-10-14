<?php

use Aws\Sdk;

App::uses('Component', 'Controller');
App::uses('Configure', 'Core');

/**
 * AWS Component
 *
 * Creates AWS service clients from an `Aws\Sdk` configured with `Configure::read('Aws')`.
 * `$this->Aws->S3` and `$this->Aws->createS3($args)` both return an `Aws\S3\S3Client`.
 *
 * @method \Aws\DynamoDb\DynamoDbClient createDynamoDb(array $args = [])
 * @method \Aws\S3\S3Client createS3(array $args = [])
 * @method \Aws\SesV2\SesV2Client createSesV2(array $args = [])
 */
class AwsComponent extends Component
{
    private Sdk $sdk;

    /**
     * Constructor
     *
     * @param ComponentCollection $collection A ComponentCollection this component can use to lazy load its components
     * @param array $settings Array of configuration settings.
     */
    public function __construct(ComponentCollection $collection, $settings = [])
    {
        parent::__construct($collection, $settings);
        $this->sdk = new Sdk((array)Configure::read('Aws'));
    }

    /**
     * Creates a service client by name, e.g. `$this->Aws->S3`.
     *
     * @param string $name Service name.
     * @return \Aws\AwsClientInterface
     */
    public function __get($name)
    {
        return $this->sdk->{'create' . $name}();
    }

    /**
     * Proxies method calls to the `Aws\Sdk`, e.g. `$this->Aws->createS3($args)`.
     *
     * @param string $name Method name.
     * @param array $arguments Method arguments.
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->sdk->$name(...$arguments);
    }
}
