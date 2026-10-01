<?php

$loader = require dirname(__DIR__, 3).'/laravel-report-builder/vendor/autoload.php';
$loader->addPsr4('ElgiborSolution\\AdvancedReports\\Tests\\', __DIR__.'/', true);
$loader->addPsr4('ElgiborSolution\\AdvancedReports\\', dirname(__DIR__).'/src/', true);
$loader->addPsr4('ESolution\\DataSources\\', dirname(__DIR__, 2).'/laravel-form-builder/src/', true);
class_alias(
    \ElgiborSolution\AdvancedReports\AdvancedReportsManager::class,
    \ElgiborSolution\AdvancedReports\Facades\AdvancedReportsManager::class,
);
