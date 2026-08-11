<?php

namespace Abedin\MultiPay\Facades;

use Illuminate\Support\Facades\Facade;

class MultiPay extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'multipay';
    }
}
