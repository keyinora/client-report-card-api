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

function prevent_api_caching() {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Thu, 1 Jan 1970 00:00:00 GMT");
}

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
# Helper Function
function getCurrentQuarter() {
    $now = new DateTime();
    $quarter = ceil($now->format('n') / 3);
    
    $quarterEnd = new DateTime($now->format('Y') . '-' . (3 * $quarter) . '-' . ($quarter == 4 ? '31' : '30'));
    $twoWeeksBefore = (clone $quarterEnd)->modify('-2 weeks');
    
    if ($now >= $twoWeeksBefore) {
        $quarter = $quarter % 4 + 1;
    }
    
    return $quarter;
}

function getClientDataByCurrentQuarter(){
    try {
        $db = getDbConnection();
        $currentQuarter = getCurrentQuarter();
        $year = date('Y');

        $quarterStart = new DateTime("$year-". (3 * $currentQuarter - 2)."-01");
        $quarterEnd = new DateTime("$year-".(3 * $currentQuarter)."-".($currentQuarter == 4 ? '31' : '30'));

        $sql = "
        SELECT h.URL, r.response
        FROM iwp_new_history h
        JOIN iwp_new_history_raw_details r ON h.historyID = r.historyID
        WHERE h.type = 'clientReporting'
        AND microtimeStarted >= :start_time 
        AND microtimeEnded <= :end_time
        ORDER BY h.historyID DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':start_time' => $quarterStart->getTimestamp(),
            ':end_time' => $quarterEnd->getTimeStamp()
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e){
        error_log("Database error: ". $e->getMessage());
    }
}

# Get Client Data by Year
function getClientDataByYear(){
    try {
        $db = getDbConnection();

        $oneYearAgo = (new DateTime())->modify('-1 year');
    
        $sql = "
        SELECT h.URL, r.response
        FROM iwp_new_history h
        JOIN iwp_new_history_raw_details r ON h.historyID = r.historyID
        WHERE h.type = 'clientReporting'
        AND microtimeStarted >= :start_time
        ORDER BY h.historyID DESC";
    
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':start_time' => $oneYearAgo->getTimestamp()
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e){
        // Log the error or handle it appropriately
        error_log("Database error: ". $e->getMessage());
        return false;
    }
}

#Get information by Report Data
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
function combineArrayDuplicates($array) {
    if (!is_array($array) || empty($array)) {
        return [];
    }

    $result = [];
    foreach ($array as $item) {
        if (!isset($item['url']) || !isset($item['response']) || !is_array($item['response'])) {
            continue;
        }

        $url = $item['url'];
        if (!isset($result[$url])) {
            $result[$url] = $item;
        } else {
            foreach ($item['response'] as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                if (isset($result[$url]['response'][$key])) {
                    $result[$url]['response'][$key] += $value;
                } else {
                    $result[$url]['response'][$key] = $value;
                }
            }
        }
    }
    return array_values($result);
}


$app->get('/api/client-reporting', function (Request $request, Response $response) {
    prevent_api_caching();
    $rowData = getClientReportingData();
    $processedData = processData($rowData);
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

# get Years worth of client data
$app->get('/api/yearly-client-reporting', function(Request $request, Response $response){
    prevent_api_caching();
    # creat method to get rawData
    $rowData = getClientDataByYear();
    $processedData = combineArrayDuplicates(processData($rowData));
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/api/one_year_ago', function(Request $request, Response $response){
    $oneYearAgo = (new DateTime())->modify('-1 year');
    $response->getBody()->write(json_encode($oneYearAgo));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/quarterly-client-report', function(Request $request, Response $response){
    prevent_api_caching();
    $rowData = getClientDataByCurrentQuarter();
    $processedData = combineArrayDuplicates(processData($rowData));
    $response->getBody()->write(json_encode($processedData));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

