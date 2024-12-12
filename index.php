<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

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

function getClientReportingData() {
    $db = getDbConnection();
    $query = "
    SELECT h.URL, r.response
    FROM iwp_new_history h
    JOIN iwp_new_history_raw_details r ON h.historyID = r.historyID
    WHERE h.type = 'clientReporting'
    AND h.historyID IN (
        SELECT MAX(historyID)
        FROM iwp_new_history
        WHERE type = 'clientReporting'
        GROUP BY URL
    )
    ORDER BY h.historyID DESC
    ";
    $stmt = $db->prepare($query);
    $stmt->execute();
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

function processData($rawData) {
    $processedData = [];
    foreach ($rawData as $row) {
        $processedItem = [
            'url' => $row['URL'],
            'response' => null
        ];

        if (isset($row['response']) && $row['response']) {
            $rawResponseData = maybeUnCompress($row['response']);
            removeResponseJunk($rawResponseData);
            if (strrpos($rawResponseData, '_IWP_JSON_PREFIX_') !== false) {
                $responseDataArray = explode('_IWP_JSON_PREFIX_', $rawResponseData);
                $responseRawData = $responseDataArray[1];
                $decodedResponse = processCallReturn(json_decode(base64_decode($responseRawData), true));
                if (isset($decodedResponse['success']['count'])) {
                    $processedItem['response'] = $decodedResponse['success']['count'];
                }
            } else {
                $decodedResponse = processCallReturn(unserialize(base64_decode($rawResponseData)));
                if (isset($decodedResponse['success']['count'])) {
                    $processedItem['response'] = $decodedResponse['success']['count'];
                }
            }
        }

        $processedData[] = $processedItem;
    }
    return $processedData;
}

$app->get('/api/client-reporting', function (Request $request, Response $response) {
    $rawData = getClientReportingData();
    $processedData = processData($rawData);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

