# AWS コンポーネント

`Aws.Aws` コンポーネントは、`Aws` の設定で AWS のサービスクライアントを作成します。

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

プロパティを読み出す (`$this->Aws->DynamoDb`) と `Aws\Sdk` の `createDynamoDb()` が呼ばれ、
メソッド呼び出しはすべて `Aws\Sdk` に渡されます。そのため、SDK のすべてのサービスを利用できます。
