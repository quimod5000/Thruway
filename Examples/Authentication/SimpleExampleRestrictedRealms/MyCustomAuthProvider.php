<?php

namespace App\Server;

/**
 * Class MyCustomAuthProvider
 */
class MyCustomAuthProvider extends \Thruway\Authentication\AbstractAuthProviderClient
{

    /**
     * @return string
     */
    public function getMethodName()
    {        
        return 'ticket';
    }

    /**
     * Process Authenticate message
     * 
     * @param mixed $signature
     * @param mixed $extra
     * @return array
     */
    public function processAuthenticate($signature, $extra = null)
    {        
        if ($signature == "custom-string-here") {
            return ["SUCCESS", (object)[]];
        } else {
            return ["FAILURE"];
        }

    } 
}