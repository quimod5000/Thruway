<?php 
require dirname(__DIR__) . '/bootstrap.php';

echo "Staring Thruway Server...\n";
use Thruway\Peer\Router;
use Thruway\Transport\RatchetTransportProvider;
use App\Server\MyCustomAuthProvider;

$router = new Router();
$myRealmName = "thruway-realm";

$myAuth = new \Thruway\Authentication\AuthenticationManager();

// //For now anyone can publish & subscribe
// $rules = [
//     (object)[
//         "role" => "anonymous",
//         "action" => "publish",
//         "uri" => "",
//         "allow" => true
//     ],
//     (object)[
//         "role" => "anonymous",
//         "action" => "subscribe",
//         "uri" => "",
//         "allow" => true
//     ]
// ];

// foreach ($rules as $rule) {
//     $authorizationManager->addAuthorizationRule([$rule]);
// }
$router->registerModule($myAuth);  

//A few notes for Authentication & setAllowRealmAutocreate=false
// 1. create your realms manually
// 2. you must also create a realm for "thruway.auth" since it wont be able to create it internally
// 3. define your realms AFTER defining AuthenticationManager so it gets notified and attached to the new realm
// 4. AuthenticationManager does NOT support passing in a RealmName as a constructor parameter. 
// Tip: check the terminal output for Info messages "Setting registration_id for" thruway.auth.registermethod, 
//   thruway.auth.ticket.onhello, and thruway.auth.ticket.onauthenticate

$realm1 = new \Thruway\Realm($myRealmName);
$router->getRealmManager()->addRealm($realm1);

$realmAuth = new \Thruway\Realm('thruway.auth');
$router->getRealmManager()->addRealm($realmAuth);

$router->getRealmManager()->setAllowRealmAutocreate(false);

//Provide authentication for your custom realm (pass array)
$authProvClient = new MyCustomAuthProvider([$myRealmName]);
$router->addInternalClient($authProvClient);

$transportProvider = new RatchetTransportProvider("127.0.0.1", 8090);
$router->addTransportProvider($transportProvider);


$router->start();