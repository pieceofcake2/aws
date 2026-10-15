# AWS Component

The `Aws.Aws` component creates AWS service clients with the `Aws` configuration:

```php
class FilesController extends AppController
{
    public $components = ['Aws.Aws'];

    public function index()
    {
        // An Aws\S3\S3Client
        $s3 = $this->Aws->S3;

        // The same, with options that override the Aws configuration
        $s3 = $this->Aws->createS3(['region' => 'us-east-1']);

        $result = $s3->listObjectsV2(['Bucket' => 'my-bucket']);
        $this->set('objects', $result['Contents'] ?? []);
    }
}
```

Reading a property (`$this->Aws->DynamoDb`) calls `createDynamoDb()` of `Aws\Sdk`, and
any method call is passed to `Aws\Sdk`, so every service of the SDK is available.
