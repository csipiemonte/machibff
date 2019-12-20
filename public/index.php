<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Symfony\Component\Yaml\Yaml;
use DI\Container;
use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Illuminate\Database\Query\JoinClause;

require __DIR__ . '/../vendor/autoload.php';

set_time_limit(-1);

//getting configurations
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../config/.env');
$env = $_ENV['BFF'];
$container = new Container();
$config = Yaml::parseFile(__DIR__ . '/../config/config.' . $env . '.yml');
$container->set('config', $config);
AppFactory::setContainer($container);
// setting database
$capsule = new Capsule();
$capsule->addConnection([
    "driver" => $config['database']['driver'],
    "host" => $config['database']['host'],
    "database" => $config['database']['db'],
    "username" => $config['database']['user'],
    "password" => $config['database']['pwd'],
    "charset" => 'utf8'
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$version = $config['version'];
$logger = new Logger('cfbackend');
$fileHandler = New RotatingFileHandler(__DIR__ . '/../logs/cittafacile.log', 100);
$fileHandler->setFilenameFormat('{date}-{filename}', 'Ymd');
$logger->pushHandler($fileHandler);

class User {

    public $codice_fiscale;
    public $email;
    public $nome;
    public $cognome;

    public static function create() {
        //decommentare per disabilitare il login
//        return true;
        if (isset($_SERVER['Codice-fiscale-SPID']) && $_SERVER['Codice-fiscale-SPID']) {
            $User = new User;
            $User->codice_fiscale = $_SERVER['Codice-fiscale-SPID'];
            $User->email = $_SERVER['Shib-Email'];
            $User->nome = $_SERVER['Shib-Identita-Nome'];
            $User->cognome = $_SERVER['Shib-Identita-Cognome'];
            return $User;
        } else {
            return null;
        }
    }

}

//Capsule::table('pins')->insert([
//    "fiscal_code" => "asdasd",
//    "type" => "phone",
//    "pin" => 1234,
//    "expiration_time" => date("Y-m-d H:i:s", strtotime("+30 minutes"))
//]);
//initialize application
$app = AppFactory::create();
if ($env === 'prod') {
    $errorMiddleware=$app->addErrorMiddleware(true, true, true);
//    // Add Error Middleware
    $customErrorHandler = function (
            Request $request,
            Throwable $exception,
            bool $displayErrorDetails,
            bool $logErrors,
            bool $logErrorDetails
            ) use ($app, $logger) {
        $logger->error($exception->getCode().' --> '.$exception->getMessage());
        $response = $app->getResponseFactory()->createResponse();
        return $response->withStatus(500, 'Internal Server Error');
    };
    $errorMiddleware->setDefaultErrorHandler($customErrorHandler);
} else {
    $app->addErrorMiddleware(true, true, true);
}

// middleware for setting headers
$app->add(function (Request $request, RequestHandler $handler) {
    $response = $handler->handle($request);
    return $response
                    ->withHeader('Access-Control-Allow-Origin', '*')
                    ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, Access-Control-Expose-Headers')
                    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

//app routing
$app->get('/api/' . $version . '/login[/]', function (Request $request, Response $response, $args) {
    if (!($user = User::create())) {

        return $response->withStatus(401);
    }
    $query = $request->getQueryParams();
    if (isset($query['redirect']) && $query['redirect']) {
        return $response->withStatus(302)->withHeader('Location', $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']);
    } else {
        $response->getBody()->write(json_encode(['id' => $_ENV['USER_ID']]));
        return $response;
    }
});

$smartdataRouteString = '/api/' . $version . '/sm/{p1:utenti|semaforo}[/{p2:catasto|famiglia|profilo|informa-casa|tari|utenze-tari}]';
$app->get($smartdataRouteString, function (Request $request, Response $response, $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $smartdata = $config['smartdata'];
    $client = new Client();
    $url_to_call_params = array_filter(
            [
                $smartdata['url'],
                $args['p1'],
                isset($args['p2']) ? $_ENV['USER_ID'] : null,
                isset($args['p2']) ? $args['p2'] : null,
            ],
            function($entry) {
        return $entry !== null;
    }
    );
    $url_to_call = implode('/', $url_to_call_params);
    $called_url_response = $client->request('GET', $url_to_call,
            [
                'headers' => [
                    'identita' => $_ENV['USER_ID'],
                    'X-Request-ID' => Uuid::uuid4()->toString(),
                ],
                'auth' => [$smartdata['user'], $smartdata['pwd']],
                'debug' => $config['debug'],
                'query' => $request->getQueryParams(),
//        'proxy'=>[
//            'http' => 'http://proxy.csi.it:3128',
//            'https' => 'http://proxy.csi.it:3128',
//            'no'=>['nivolapiemonte.it', '.csi.it']  
//        ],
                'connect_timeout' => 300,
                'http_errors' => false,
                "stream" => true
            ]
    );
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->any('/api/' . $version . '/{url:prefs}/{p1:terms|users}[/{cf}[/{p2:preferences|contacts}]]', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call_params = array_filter(
            [
                $config['notification_platform']['urls'][$args['url']],
                $args['p1'],
                isset($args['cf']) ? $args['cf'] : null,
                isset($args['p2']) ? $args['p2'] : null,
            ],
            function($entry) {
        return $entry !== null;
    }
    );
    $url_to_call = implode('/', $url_to_call_params);
    $called_url_response = $client->request($request->getMethod(), $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => $request->getQueryParams(),
        'body' => $request->getBody()->getContents(),
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->get('/api/' . $version . '/{url:prefs}/{p1:users}[/{cf}[/{p2:terms}]]', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call_params = array_filter(
            [
                $config['notification_platform']['urls'][$args['url']],
                $args['p1'],
                isset($args['cf']) ? $args['cf'] : null,
                isset($args['p2']) ? $args['p2'] : null,
            ],
            function($entry) {
        return $entry !== null;
    }
    );
    $url_to_call = implode('/', $url_to_call_params);
    $called_url_response = $client->request($request->getMethod(), $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => $request->getQueryParams(),
        'body' => $request->getBody()->getContents(),
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
    $body = $called_url_response->getBody()->getContents();
    if ($body == 'User not found') {
        $response = $response->withStatus(200);
        $response->getBody()->write(json_encode(['result' => false]));
        return $response;
    }
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write(json_encode(['result' => true]));
    return $response;
});

$app->put('/api/' . $version . '/{url:prefs}/{p1:users}[/{cf}[/{p2:terms}]]', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call_params = array_filter(
            [
                $config['notification_platform']['urls'][$args['url']],
                $args['p1'],
                isset($args['cf']) ? $args['cf'] : null,
                isset($args['p2']) ? $args['p2'] : null,
            ],
            function($entry) {
        return $entry !== null;
    }
    );
    $url_to_call = implode('/', $url_to_call_params);
    $called_url_response = $client->request($request->getMethod(), $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => $request->getQueryParams(),
        'body' => $request->getBody()->getContents(),
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->delete('/api/' . $version . '/{url:store}/{p1:users}[/{cf}[/{p2:messages}/{msg_id}]]', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call_params = array_filter(
            [
                $config['notification_platform']['urls'][$args['url']],
                $args['p1'],
                isset($args['cf']) ? $args['cf'] : null,
                isset($args['p2']) ? $args['p2'] : null,
                isset($args['msg_id']) ? $args['msg_id'] : null,
            ],
            function($entry) {
        return $entry !== null;
    }
    );
    $url_to_call = implode('/', $url_to_call_params);
    $called_url_response = $client->request($request->getMethod(), $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => $request->getQueryParams(),
        'body' => $request->getBody()->getContents(),
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->put('/api/' . $version . '/{url:store}/{p1:users}[/{cf}[/{p2:messages}[/{p3:status}]]]', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call_params = array_filter(
            [
                $config['notification_platform']['urls'][$args['url']],
                $args['p1'],
                isset($args['cf']) ? $args['cf'] : null,
                isset($args['p2']) ? $args['p2'] : null,
                isset($args['p3']) ? $args['p3'] : null,
            ],
            function($entry) {
        return $entry !== null;
    }
    );
    $url_to_call = implode('/', $url_to_call_params);
    $called_url_response = $client->request($request->getMethod(), $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => $request->getQueryParams(),
        'body' => $request->getBody()->getContents(),
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->get('/api/' . $version . '/{url:store}/{p1:users}[/{cf}[/{p2:messages}]]', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call_params = array_filter(
            [
                $config['notification_platform']['urls'][$args['url']],
                $args['p1'],
                isset($args['cf']) ? $args['cf'] : null,
                isset($args['p2']) ? $args['p2'] : null,
            ],
            function($entry) {
        return $entry !== null;
    }
    );
    $url_to_call = implode('/', $url_to_call_params);
    $query_params = $request->getQueryParams();
    $filter = isset($query_params['filter']) ? json_decode($query_params['filter'], true) : [];
    if (isset($filter['tag'])) {
        if (isset($filter['tag']['match'])) {
            $tag_cleaned = str_replace('+', '', $config['notification_platform']['services_tag']);
            $filter['tag']['match'] = str_replace(['+' . $tag_cleaned, '-' . $tag_cleaned, $tag_cleaned], '', $filter['tag']['match']);
            $filter['tag']['match'] .= ' ' . $config['notification_platform']['services_tag'];
        } else {
            $filter['tag']['match'] = $config['notification_platform']['services_tag'];
        }
    } else {
        $filter['tag'] = [
            'match' => $config['notification_platform']['services_tag']
        ];
    }
    $filter['tag']['match'] .= ' -deleted';
    $query_params['filter'] = json_encode($filter);
    $called_url_response = $client->request($request->getMethod(), $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => $query_params,
        'body' => $request->getBody()->getContents(),
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
//    print_r($query_params);
    $response = $response->withStatus($called_url_response->getStatusCode());
    $called_url_headers = $called_url_response->getHeaders();
    $response = $response->withHeader('Access-Control-Expose-Headers', 'total-elements, total-elements-not-read');
    $response = $response->withHeader('total-elements', isset($called_url_headers['total-elements']) ? $called_url_headers['total-elements'] : '');
    $response = $response->withHeader('total-elements-not-read', isset($called_url_headers['total-elements-not-read']) ? $called_url_headers['total-elements-not-read'] : '');
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->get('/api/' . $version . '/prefs/deluser', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call_params = [
        $config['notification_platform']['urls']['prefs'],
        'users',
        $_ENV['USER_ID'],
    ];
    $url_to_call = implode('/', $url_to_call_params);
    $called_url_response = $client->request('DELETE', $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => $request->getQueryParams(),
        'body' => $request->getBody()->getContents(),
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->put('/api/' . $version . '/prefs/verify/users/{cf}/contacts', function (Request $request, Response $response, array $args) use ($logger) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $bodyjson = json_decode($request->getBody()->getContents());
    try {
        if ($bodyjson->sms)
            $phone_verified = Capsule::table('pins')
                            ->where('fiscal_code', '=', $_ENV['USER_ID'])
                            ->where('type', '=', 'phone')
                            ->where('value', '=', $bodyjson->sms)
                            ->where('verified', '=', true)->first();
        if ($bodyjson->email)
            $email_verified = Capsule::table('pins')
                            ->where('fiscal_code', '=', $_ENV['USER_ID'])
                            ->where('type', '=', 'email')
                            ->where('value', '=', $bodyjson->email)
                            ->where('verified', '=', true)->first();
    } catch (Exception $exc) {
        $logger->error($exc->getMessage());
        $response = $response->withStatus(500, 'Internal server error');
        return $response;
    }
    if ((!$bodyjson->sms || ($bodyjson->sms && $phone_verified)) && (!$bodyjson->email || ($bodyjson->email && $email_verified))) {
        $config = $this->get('config');
        $client = new Client();
        $url_to_call_params = [
            $config['notification_platform']['urls']['prefs'],
            'users',
            $args['cf'],
            'contacts',
        ];
        $url_to_call = implode('/', $url_to_call_params);
        $called_url_response = $client->request($request->getMethod(), $url_to_call, [
            'headers' => [
                'X-Request-ID' => Uuid::uuid4()->toString(),
                'x-authentication' => $config['notification_platform']['key'],
                'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
                'Content-Type' => 'application/json'
            ],
            'debug' => $config['debug'],
            'query' => $request->getQueryParams(),
            'body' => $request->getBody()->getContents(),
            'connect_timeout' => 300,
            'http_errors' => false,
        ]);
        $response = $response->withStatus($called_url_response->getStatusCode());
        $response->getBody()->write($called_url_response->getBody()->getContents());
        return $response;
    } else {
        $logger->error('hacking attemp');
        $response = $response->withStatus(500, 'Internal server error');
        return $response;
    }
});

$app->get('/api/' . $version . '/prefs/services', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call = $config['notification_platform']['urls']['prefs'] . '/services';
    $called_url_response = $client->request('GET', $url_to_call, [
        'headers' => [
            'X-Request-ID' => Uuid::uuid4()->toString(),
            'x-authentication' => $config['notification_platform']['key'],
            'Shib-Iride-IdentitaDigitale' => $_ENV['USER_ID'],
            'Content-Type' => 'application/json'
        ],
        'debug' => $config['debug'],
        'query' => ["filter" => '{ "tags": { "match": "' . $config['notification_platform']['services_tag'] . '" } }'],
        'connect_timeout' => 300,
        'http_errors' => false,
    ]);
    $response = $response->withStatus($called_url_response->getStatusCode());
    $response->getBody()->write($called_url_response->getBody()->getContents());
    return $response;
});

$app->get('/api/' . $version . '/msg/sendpin/email', function (Request $request, Response $response, array $args) use ($logger) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call = $config['notification_platform']['urls']['notifier'] . '/topics/messages';
    $pin = str_pad(rand(0, 99999), 5, 0, STR_PAD_LEFT);
    $params = $request->getQueryParams();
    try {
        $inserted = Capsule::table('pins')->insert([
            "fiscal_code" => $_ENV['USER_ID'],
            "type" => "email",
            "pin" => $pin,
            "expiration_time" => date("Y-m-d H:i:s", strtotime("+30 minutes")),
            "value" => $params['email']
        ]);
    } catch (Exception $exc) {
        $logger->error($exc->getMessage());
        $response = $response->withStatus(500, 'Internal server error');
        return $response;
    }
    if (!$inserted) {
        $logger->error('problem with database inserted=false');
        $response = $response->withStatus(500, 'Internal server error');
        return $response;
    } else {
        $called_url_response = $client->post($url_to_call, [
            'headers' => [
                'x-authentication' => $config['notification_platform']['key'],
                'Content-Type' => 'application/json'
            ],
            'debug' => $config['debug'],
            'body' => json_encode([
                'uuid' => Uuid::uuid4()->toString(),
                'payload' => [
                    'id' => Uuid::uuid4()->toString(),
                    'user_id' => $_ENV['USER_ID'],
                    'trusted' => true,
                    "email" => [
                        "to" => $params['email'],
                        "subject" => "Codice conferma indirizzo email",
                        "body" => $pin,
                        "template_id" => "citfactrust-template.html"
                    ]
                ],
                'priority' => 'high'
            ]),
            'connect_timeout' => 300,
            'http_errors' => false,
        ]);
        $response_code = $called_url_response->getStatusCode();
        if ($response_code != 201) {
            $logger->error($url_to_call . ':code: ' . $response + ':phrase:' + $called_url_response->getReasonPhrase());
            $response = $response->withStatus(500, 'Internal Server Error');
            return $response;
        } else {
            $response = $response->withStatus(200);
            $response->getBody()->write(json_encode([
                'result' => 'ok'
            ]));
            return $response;
        }
    }
});

$app->get('/api/' . $version . '/msg/sendpin/sms', function (Request $request, Response $response, array $args) use ($logger) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $config = $this->get('config');
    $client = new Client();
    $url_to_call = $config['notification_platform']['urls']['notifier'] . '/topics/messages';
    $pin = str_pad(rand(0, 99999), 5, 0, STR_PAD_LEFT);
    $params = $request->getQueryParams();
    try {
        $inserted = Capsule::table('pins')->insert([
            "fiscal_code" => $_ENV['USER_ID'],
            "type" => "phone",
            "pin" => $pin,
            "expiration_time" => date("Y-m-d H:i:s", strtotime("+30 minutes")),
            "value" => $params['phone']
        ]);
    } catch (Exception $exc) {
        $logger->error($exc->getMessage());
        $response = $response->withStatus(500, 'Internal server error');
        return $response;
    }
    if (!$inserted) {
        $logger->error('problem with database inserted=false');
        $response = $response->withStatus(500, 'Internal server error');
        return $response;
    } else {
        $called_url_response = $client->post($url_to_call, [
            'headers' => [
                'x-authentication' => $config['notification_platform']['key'],
                'Content-Type' => 'application/json'
            ],
            'debug' => $config['debug'],
            'body' => json_encode([
                'uuid' => Uuid::uuid4()->toString(),
                'payload' => [
                    'id' => Uuid::uuid4()->toString(),
                    'user_id' => $_ENV['USER_ID'],
                    'trusted' => true,
                    "sms" => [
                        "phone" => $params['phone'],
                        "content" => "Ciao, il tuo codice per confermare il numero di cellulare è: " . $pin,
                    ]
                ],
                'priority' => 'high'
            ]),
            'connect_timeout' => 300,
            'http_errors' => false,
        ]);
        $response_code = $called_url_response->getStatusCode();
        if ($response_code != 201) {
            $logger->error($url_to_call . ':code: ' . $response + ':phrase:' + $called_url_response->getReasonPhrase());
            $response = $response->withStatus(500, 'Internal Server Error');
            return $response;
        } else {
            $response = $response->withStatus(200);
            $response->getBody()->write(json_encode([
                'result' => 'ok'
            ]));
            return $response;
        }
    }
});

$app->get('/api/' . $version . '/verifypin/email', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $params = $request->getQueryParams();
    $pin_to_verify = $params['pin'];
    $email_to_verify = $params['email'];
    $result = Capsule::table('pins')
            ->where('fiscal_code', '=', $_ENV['USER_ID'])
            ->where('pin', '=', $pin_to_verify)
            ->where('type', '=', 'email')
            ->where('expiration_time', '>', date("Y-m-d H:i:s"))
            ->where('value', '=', $email_to_verify)
            ->where('verified', '=', false)
            ->update(['verified' => true]);
    if ($result) {
        $response->getBody()->write(json_encode(['verified' => true]));
        return $response;
    } else {
        $response->getBody()->write(json_encode(['verified' => false]));
        return $response;
    }
});

$app->get('/api/' . $version . '/verifypin/sms', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $params = $request->getQueryParams();
    $pin_to_verify = $params['pin'];
    $phone_to_verify = $params['phone'];
    try {
        $result = Capsule::table('pins')
                ->where('fiscal_code', '=', $_ENV['USER_ID'])
                ->where('pin', '=', $pin_to_verify)
                ->where('type', '=', 'phone')
                ->where('expiration_time', '>', date("Y-m-d H:i:s"))
                ->where('value', '=', $phone_to_verify)
                ->where('verified', '=', false)
                ->update(['verified' => true]);
    } catch (Exception $exc) {
        $logger->error($exc->getMessage());
        $response->getBody()->write(json_encode(['verified' => false]));
        return $response;
    }
    if ($result) {
        $response->getBody()->write(json_encode(['verified' => true]));
        return $response;
    } else {
        $response->getBody()->write(json_encode(['verified' => false]));
        return $response;
    }
});

//servizi temporanei in database
$app->get('/api/' . $version . '/services', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $services = Capsule::table('services as s');
    $services = $services->select(['s.title', 's.text', 's.id as service_id', 's.img', 's.link', 's.category', 'us.value as favorite']);
    $services = $services->join('users_services as us', function(JoinClause $join) {
        $join->on('s.id', '=', 'service_id');
        $join->where('user_id', '=', $_ENV['USER_ID']);
    }, null, null, 'left');
    $result = $services->get();
    $response->getBody()->write(json_encode($result));
    return $response;
});

$app->get('/api/' . $version . '/services/{cf}/favorite/{id}', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $services = Capsule::table('users_services');
    $body_decoded = json_decode($request->getBody()->getContents());
    $result = $services->updateOrInsert(
            ['user_id' => $_ENV['USER_ID'], 'service_id' => $args['id']],
            ['user_id' => $_ENV['USER_ID'], 'service_id' => $args['id'], 'value' => $services->raw('NOT value')]
    );
    $response->getBody()->write(json_encode($result ? ['result' => true] : ['result' => false]));
    return $response;
});

$app->get('/api/' . $version . '/prefs/cleansteps', function (Request $request, Response $response, array $args) use ($logger) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    try {
        $result = Capsule::table('pins')
                ->where('fiscal_code', '=', $_ENV['USER_ID'])
                ->delete();
        $response->getBody()->write(json_encode(['clean' => true]));
        return $response;
    } catch (Exception $exc) {
        $logger->error($exc->getMessage());
        $response->withStatus(500, 'Internal server error');
        return $response;
    }
});

$app->get('/api/' . $version . '/settings/preferences', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $jsonpref = file_get_contents(__DIR__ . '/../config/preferenze.json');
    $response->getBody()->write($jsonpref);
    return $response;
});

$app->get('/api/' . $version . '/getLogoutUrl', function (Request $request, Response $response, array $args) {
    if (!($user = User::create())) {
        return $response->withStatus(401);
    }
    $response->getBody()->write(isset($_SERVER['HTTP_SHIB_HANDLER']) ? str_replace('Shibboleth.sso', 'logout.do', $_SERVER['HTTP_SHIB_HANDLER']) : '');
    return $response;
});

$app->run();
