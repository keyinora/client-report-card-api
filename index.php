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
