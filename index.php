<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

// Debug logging
error_log("Request URI: " . $_SERVER['REQUEST_URI']);
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/config.php';

if (!isset($config) || !is_array($config)) {
    throw new Exception("Config file does not set a valid config array");
}

AppFactory::setResponseFactory(new ResponseFactory());
$app = AppFactory::create();
$app->setBasePath('/client_report_card');
$app->addErrorMiddleware(true, true, true);

function getDbConnection() {
    global $config;
    if (!isset($config['SQL_HOST']) || !isset($config['SQL_DATABASE']) || !isset($config['SQL_USERNAME']) || !isset($config['SQL_PASSWORD'])) {
        throw new Exception("Database configuration is incomplete");
    }
    $dsn = "mysql:host={$config['SQL_HOST']};dbname={$config['SQL_DATABASE']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($dsn, $config['SQL_USERNAME'], $config['SQL_PASSWORD'], $options);
    } catch (\PDOException $e) {
        throw new \PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
    }
}

function getRecentPTCUpdates($limit = 50) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT historyID FROM `iwp_new_history` WHERE `type` = 'PTC' AND `action` = 'update' ORDER BY `historyID` DESC LIMIT :limit");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getPTCUpdatesBefore($microtime, $limit = 50) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT historyID FROM `iwp_new_history` WHERE `type` = 'PTC' AND `action` = 'update' AND `microtimeEnded` <= :microtime ORDER BY `historyID` DESC LIMIT :limit");
    $stmt->bindParam(':microtime', $microtime, PDO::PARAM_STR);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getRecentClientReporting($limit = 50) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT historyID FROM `iwp_new_history` WHERE `type` = 'clientReporting' ORDER BY `historyID` DESC LIMIT :limit");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getRawDetails($historyIDs) {
    $db = getDbConnection();
    $placeholders = rtrim(str_repeat('?,', count($historyIDs)), ',');
    $stmt = $db->prepare("SELECT historyID, response FROM `iwp_new_history_raw_details` WHERE historyID IN ($placeholders)");
    $stmt->execute($historyIDs);
    return $stmt->fetchAll();
}

function maybeUnCompress($value) {
    if(!function_exists('gzinflate') || !function_exists('gzinflate')) {
        return $value;
    }
    $unzip = @gzinflate(base64_decode($value));
    return ($unzip === false) ? $value : $unzip;
}

function removeResponseJunk(&$response) {
    $headerPos = stripos($response, '<IWPHEADER');
    if($headerPos !== false) {
        $response = substr($response, $headerPos);
        $response = substr($response, strlen('<IWPHEADER>'), stripos($response, '<ENDIWPHEADER')-strlen('<IWPHEADER>'));
    }
}

function processCallReturn($Array) {
    return $Array;
}

function processData($rawDetails) {
    $processedData = [];
    foreach ($rawDetails as $row) {
        $processedItem = [
            'historyID' => $row['historyID'],
            'processed_response' => null
        ];

        if (isset($row['response']) && $row['response']) {
            $rawResponseData = maybeUnCompress($row['response']);
            removeResponseJunk($rawResponseData);
            if (strrpos($rawResponseData, '_IWP_JSON_PREFIX_') !== false) {
                $responseDataArray = explode('_IWP_JSON_PREFIX_', $rawResponseData);
                $responseRawData = $responseDataArray[1];
                $processedItem['processed_response'] = processCallReturn(json_decode(base64_decode($responseRawData), true));
            } else {
                $processedItem['processed_response'] = processCallReturn(unserialize(base64_decode($rawResponseData)));
            }
        }

        $processedData[] = $processedItem;
    }
    return $processedData;
}
// SELECT * FROM `iwp_new_history` WHERE `type` = 'clientReporting' ORDER BY `historyID` DESC LIMIT 50;
function processEmptyResponse() {
    $emptyResponse = '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';
    $processedData = [];
    
    $rawResponseData = maybeUnCompress($emptyResponse);
    removeResponseJunk($rawResponseData);
    
    if (strrpos($rawResponseData, '_IWP_JSON_PREFIX_') !== false) {
        $responseDataArray = explode('_IWP_JSON_PREFIX_', $rawResponseData);
        $responseRawData = $responseDataArray[1];
        $processedData = processCallReturn(json_decode(base64_decode($responseRawData), true));
    } else {
        $processedData = processCallReturn(unserialize(base64_decode($rawResponseData)));
    }
    
    return $processedData;
}
function getHistoryIDs($type, $action = null, $limit = 50) {
    $db = getDbConnection();
    $sql = "SELECT historyID FROM `iwp_new_history` WHERE `type` = :type";
    if ($action) {
        $sql .= " AND `action` = :action";
    }
    $sql .= " ORDER BY `historyID` DESC LIMIT :limit";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':type', $type, PDO::PARAM_STR);
    if ($action) {
        $stmt->bindParam(':action', $action, PDO::PARAM_STR);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Add this new route to your existing routes
$app->get('/api/history-ids/{type}[/{action}]', function (Request $request, Response $response, $args) {
    $type = $args['type']?? 'PTC';
    $action = $args['action'] ?? 'update';
    $limit = $request->getQueryParams()['limit'] ?? 50;

    $historyIDs = getHistoryIDs($type, $action, $limit);
    $result = [
        'type' => $type,
        'action' => $action,
        'limit' => $limit,
        'historyIDs' => $historyIDs
    ];
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/ptc-updates[/]', function (Request $request, Response $response) {
    $historyIDs = getRecentPTCUpdates();
    $rawDetails = getRawDetails($historyIDs);
    $processedData = processData($rawDetails);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/ptc-updates-before/{microtime}[/]', function (Request $request, Response $response, $args) {
    $microtime = $args['microtime'];
    $historyIDs = getPTCUpdatesBefore($microtime);
    $rawDetails = getRawDetails($historyIDs);
    $processedData = processData($rawDetails);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/client-reporting[/]', function (Request $request, Response $response) {
    $historyIDs = getRecentClientReporting();
    $rawDetails = getRawDetails($historyIDs);
    $processedData = processData($rawDetails);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/process-empty[/]', function (Request $request, Response $response) {
    $processedData = processEmptyResponse();
    $result = [
        'input' => 'empty string',
        'processed_data' => $processedData
    ];
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

// Catch-all route
$app->get('[/{path:.*}]', function (Request $request, Response $response, $args) {
    $path = $args['path'] ?? 'No path provided';
    $response->getBody()->write("Catch-all route hit. Path: " . $path);
    return $response;
});

$app->run();
