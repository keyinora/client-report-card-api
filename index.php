<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

require __DIR__ . '/vendor/autoload.php';

// Set the ResponseFactory
AppFactory::setResponseFactory(new ResponseFactory());

$app = AppFactory::create();
$app->setBasePath('/client_report_card');

$app->get('/api', function (Request $request, Response $response, $args) {
    $params = $request->getQueryParams();
    $url = isset($params['url']) ? $params['url'] : null;

    if (!$url) {
        $response->getBody()->write(json_encode(['error' => 'URL parameter is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // Process the URL parameter here
    // For now, we'll just echo it back
    $result = ['message' => 'Processed URL: ' . $url];

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
# DB HELPER FUNCTIONS
function getDbConnection() {
    global $config;
    $dsn = "mysql:host={$config['SQL_HOST']};dbname={$config['SQL_DATABASE']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($dsn, $config['SQL_USERNAME'], $config['SQL_PASSWORD'], $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}


#Process IWP data
function processData($data) {
    $processedData = [];
    foreach ($data as $row) {
        if (isset($row['response'])) {
            $rawResponseData = maybeUnCompress($row['response']);
            removeResponseJunk($rawResponseData);
            if (strrpos($rawResponseData, '_IWP_JSON_PREFIX_') !== false) {
                $responseDataArray = explode('_IWP_JSON_PREFIX_', $rawResponseData);
                $responseRawData = $responseDataArray[1];
                $responseData = processCallReturn(json_decode(base64_decode($responseRawData), true));
            } else {
                $responseData = processCallReturn(unserialize(base64_decode($rawResponseData)));
            }
            $row['processed_response'] = $responseData;
        }
        $processedData[] = $row;
    }
    return $processedData;
}
# DB QUERIES
function getRecentPTCUpdates($limit = 50) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM `iwp_history` WHERE `type` = 'PTC' AND `action` = 'update' ORDER BY `historyID` DESC LIMIT :limit");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getPTCUpdatesBefore($microtime, $limit = 50) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM `iwp_history` WHERE `type` = 'PTC' AND `action` = 'update' AND `microtimeEnded` <= :microtime ORDER BY `historyID` DESC LIMIT :limit");
    $stmt->bindParam(':microtime', $microtime, PDO::PARAM_STR);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getRecentClientReporting($limit = 50) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM `iwp_history` WHERE `type` = 'clientReporting' ORDER BY `historyID` DESC LIMIT :limit");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

# REST ROUTES
$app->get('/api/ptc-updates', function (Request $request, Response $response) {
    $data = getRecentPTCUpdates();
    $processedData = processData($data);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/ptc-updates-before/{microtime}', function (Request $request, Response $response, $args) {
    $microtime = $args['microtime'];
    $data = getPTCUpdatesBefore($microtime);
    $processedData = processData($data);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/client-reporting', function (Request $request, Response $response) {
    $data = getRecentClientReporting();
    $processedData = processData($data);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});
