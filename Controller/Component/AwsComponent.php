<?php

use Aws\Sdk;

App::uses('Component', 'Controller');
App::uses('Configure', 'Core');

class AwsComponent extends Component
{
    private Sdk $sdk;

    public function __construct($collection, $setting = [])
    {
        parent::__construct($collection, $setting);
        $this->sdk = new Sdk((array) Configure::read('Aws'));
    }

    public function __get($name)
    {
        return $this->sdk->{'create'.$name}();
    }

    public function __call($name, $arguments)
    {
        return $this->sdk->$name(...$arguments);
    }
}
