<?php

declare(strict_types=1);

// php bench/bench.php  (after composer install)
require __DIR__ . '/../vendor/autoload.php';

use DbalSqlParserReplacement\Tests\Fixtures\OriginalParser;
use DbalSqlParserReplacement\Tests\RecordingVisitor;
use Doctrine\DBAL\SQL\Parser;

$columns = [];
for ($i = 0; $i < 400; $i++) {
    $columns[] = sprintf('`product`.`field_%d` as `product.field_%d`', $i, $i);
}
$sql = 'SELECT ' . implode(', ', $columns) . ' FROM `product` WHERE `product`.`id` IN (:ids) AND `product`.`version_id` = :version';

foreach (['upstream' => new OriginalParser(true), 'replacement' => new Parser(true)] as $name => $parser) {
    $start = hrtime(true);
    for ($i = 0; $i < 2000; $i++) {
        $parser->parse($sql, new RecordingVisitor());
    }
    printf("%-12s %8.3f ms per parse (%d bytes SQL)\n", $name, (hrtime(true) - $start) / 1e6 / 2000, strlen($sql));
}
