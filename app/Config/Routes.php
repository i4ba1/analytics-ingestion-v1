<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// Analytics API Routes
$routes->group('v1', ['namespace' => 'Analytics\Controllers'], static function ($routes) {
    // Event ingestion
    $routes->post('events', 'Events::create');
    $routes->get('events', 'Events::index');
    $routes->options('events', 'Events::options');
    
    // Metrics endpoints
    $routes->get('metrics/top-pages', 'Metrics::topPages');
    $routes->get('metrics/top-users', 'Metrics::topUsers');
    $routes->get('metrics/count', 'Metrics::count');
    $routes->get('metrics/daily-stats', 'Metrics::dailyStats');
    $routes->options('metrics/(:any)', 'Metrics::options');
    
    // Health check endpoints
    $routes->get('health', 'Health::index');
    $routes->get('health/ready', 'Health::ready');
    $routes->get('health/live', 'Health::live');
    $routes->get('health/detailed', 'Health::detailed');
    
    // Prometheus metrics
    $routes->get('metrics', 'Health::metrics');
});

// Default route
$routes->get('/', 'Home::index');
