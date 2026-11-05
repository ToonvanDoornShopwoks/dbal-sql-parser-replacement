<?php

declare(strict_types=1);

// Generates every number used in docs/blog-post.md.
//   php bench/blog.php > docs/data/results.json
//   php -d pcre.jit=0 bench/blog.php > docs/data/results-nojit.json
require __DIR__ . '/../vendor/autoload.php';

use DbalSqlParserReplacement\Tests\Fixtures\OriginalParser;
use DbalSqlParserReplacement\Tests\RecordingVisitor;
use Doctrine\DBAL\SQL\Parser;
use Doctrine\DBAL\SQL\Parser\Visitor;

/**
 * Same algorithm as the parser, with the two changes switchable so their effect can be isolated.
 * $cap = null means upstream "other" group (one token per call), 0 means unbounded.
 */
final class VariantParser
{
    private string $tokenPattern;

    public function __construct(bool $possessive, ?int $cap)
    {
        $special = ':\?\'"`\\[\\-\\/';
        // Stack-free MySQL literal, same as the shipped parser (see src/SQL/Parser.php).
        $mysql   = static fn (string $d): string => $d . '[^' . $d . '\\\\]*+(?:\\\\.[^' . $d . '\\\\]*+)*+' . $d;
        $sql     = '(' . implode('|', [
            $mysql("'"),
            $mysql('"'),
            '`[^`]*`',
            '(?<!\b(?i:ARRAY))\[(?:[^\]])*\]',
            ':{2,}',
            '--[^\r\n]*',
            '/\*([^*]+|\*+[^/*])*\**\*/',
            '[^' . $special . ']' . ($possessive ? '++' : '+'),
        ]) . ')';
        $other = match (true) {
            $cap === null => $sql,
            $cap === 0    => '(?:' . $sql . ')++', // unbounded
            default       => '(?:' . $sql . '){1,' . $cap . '}+',
        };

        $this->tokenPattern = '~\\G(?P<named>:[a-zA-Z0-9_]+)|(?P<positional>(?<!\\?)\\?(?!\\?))'
            . '|(?P<other>' . $other . '|[' . $special . '])~s';
    }

    public function parse(string $sql, Visitor $visitor): void
    {
        $offset = 0;
        $length = strlen($sql);
        while ($offset < $length) {
            if (preg_match($this->tokenPattern, $sql, $m, 0, $offset) === 1) {
                if ($m['named'] !== '') {
                    $visitor->acceptNamedParameter($m[0]);
                } elseif ($m['positional'] !== '') {
                    $visitor->acceptPositionalParameter($m[0]);
                } else {
                    $visitor->acceptOther($m[0]);
                }
                $offset += strlen($m[0]);
            } elseif (preg_last_error() !== PREG_NO_ERROR) {
                throw new RuntimeException(preg_last_error_msg());
            }
        }
    }
}

/** Counts visitor callbacks: exactly one per successful preg_match() call. */
final class CountingVisitor implements Visitor
{
    public int $calls = 0;

    public function acceptPositionalParameter(string $sql): void { $this->calls++; }
    public function acceptNamedParameter(string $sql): void { $this->calls++; }
    public function acceptOther(string $sql): void { $this->calls++; }
}

function wideSelect(int $columns): string
{
    $c = [];
    for ($i = 0; $i < $columns; $i++) {
        $c[] = sprintf('`product`.`field_%d` as `product.field_%d`', $i, $i);
    }

    return 'SELECT ' . implode(', ', $c)
        . ' FROM `product` WHERE `product`.`id` IN (:ids) AND `product`.`version_id` = :version';
}

/** Median ms per parse over $rounds rounds, each round sized to run ~20 ms. */
function measure(object $parser, string $sql, int $rounds = 15): array
{
    $parser->parse($sql, new CountingVisitor()); // warm up JIT + pattern cache
    $start = hrtime(true);
    $parser->parse($sql, new CountingVisitor());
    $one   = max(1, hrtime(true) - $start);
    $iters = max(3, min(20000, (int) (20e6 / $one)));

    $samples = [];
    for ($r = 0; $r < $rounds; $r++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iters; $i++) {
            $parser->parse($sql, new CountingVisitor());
        }
        $samples[] = (hrtime(true) - $start) / 1e6 / $iters;
    }
    sort($samples);

    return [
        'median_ms' => round($samples[intdiv($rounds, 2)], 5),
        'min_ms'    => round($samples[0], 5),
        'max_ms'    => round($samples[$rounds - 1], 5),
    ];
}

function calls(object $parser, string $sql): int
{
    $v = new CountingVisitor();
    $parser->parse($sql, $v);

    return $v->calls;
}

function sameOutput(string $sql): bool
{
    $a = new RecordingVisitor();
    (new OriginalParser(true))->parse($sql, $a);
    $b = new RecordingVisitor();
    (new Parser(true))->parse($sql, $b);

    return $a->parameters === $b->parameters && $a->result() === $b->result();
}

$upstream    = new OriginalParser(true);
$replacement = new Parser(true);
$out         = [
    'env' => [
        'php'  => PHP_VERSION,
        'pcre' => PCRE_VERSION,
        'jit'  => (bool) ini_get('pcre.jit'),
        'os'   => PHP_OS_FAMILY . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'dbal' => \Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'),
    ],
];

// 1. Realistic query shapes.
$insertRows = implode(', ', array_fill(0, 5000, "(?, ?, 'value-with-some-length', :tenant)"));
$shapes     = [
    'Primary-key lookup' => 'SELECT `id`, `name` FROM `product` WHERE `id` = ? LIMIT 1',
    'Cart rule lookup (20 cols, 6 params)' => 'SELECT ' . implode(', ', array_map(static fn ($i) => "`rule`.`col_$i`", range(1, 20)))
        . ' FROM `rule` LEFT JOIN `rule_condition` ON `rule_condition`.`rule_id` = `rule`.`id`'
        . ' WHERE `rule`.`id` IN (:ids) AND `rule`.`version_id` = :v AND `rule`.`active` = ? AND `rule`.`priority` > ?'
        . " AND `rule`.`name` LIKE '%sale%' AND `rule_condition`.`type` = :type AND `rule`.`created_at` > :since",
    'Product listing (100 cols)' => wideSelect(100),
    'Product detail (400 cols)' => wideSelect(400),
    'Bulk INSERT (5,000 rows, 20,000 params)' => 'INSERT INTO `t` (`a`, `b`, `c`, `d`) VALUES ' . $insertRows,
];
foreach ($shapes as $name => $sql) {
    $u = measure($upstream, $sql);
    $r = measure($replacement, $sql);
    $out['shapes'][] = [
        'name'               => $name,
        'bytes'              => strlen($sql),
        'upstream'           => $u,
        'replacement'        => $r,
        'speedup'            => round($u['median_ms'] / $r['median_ms'], 2),
        'calls_upstream'     => calls($upstream, $sql),
        'calls_replacement'  => calls($replacement, $sql),
        'identical_output'   => sameOutput($sql),
    ];
}

// 2. Scaling with SELECT width.
foreach ([10, 25, 50, 100, 200, 400, 800, 1600] as $cols) {
    $sql = wideSelect($cols);
    $u   = measure($upstream, $sql, 11);
    $r   = measure($replacement, $sql, 11);
    $out['scaling'][] = [
        'columns'           => $cols,
        'bytes'             => strlen($sql),
        'upstream_ms'       => $u['median_ms'],
        'replacement_ms'    => $r['median_ms'],
        'calls_upstream'    => calls($upstream, $sql),
        'calls_replacement' => calls($replacement, $sql),
    ];
}

// 3. Which change does the work? Isolate both on the 400-column SELECT.
$sql = wideSelect(400);
foreach ([
    'Upstream'                  => new VariantParser(false, null),
    'Possessive OTHER only'     => new VariantParser(true, null),
    'Batching ({1,64}+) only'   => new VariantParser(false, 64),
    'Both (this package)'       => new VariantParser(true, 64),
] as $name => $p) {
    $out['ablation'][] = ['name' => $name] + measure($p, $sql) + ['calls' => calls($p, $sql)];
}

// 4. Choosing the cap: speed on the wide SELECT and survival on a ~3 MB INSERT.
$huge = 'INSERT INTO `t` (`a`, `b`) VALUES ' . implode(', ', array_fill(0, 60000, "('value-with-some-length', `x`, 'more', 'and more')"));
foreach ([1, 4, 16, 64, 128, 192, 256, 'unbounded'] as $cap) {
    $row = ['cap' => $cap];
    set_error_handler(static function (int $no, string $msg) use (&$row): bool {
        $row['error'] = $msg;

        return true;
    });
    try {
        $p = new VariantParser(true, $cap === 'unbounded' ? 0 : $cap);
        $p->parse('SELECT 1', new CountingVisitor());
        if (! isset($row['error'])) {
            $row += ['wide_select_ms' => measure($p, $sql, 9)['median_ms'], 'calls' => calls($p, $sql)];
            try {
                $row['huge_insert_ms'] = measure($p, $huge, 3)['median_ms'];
                $row['huge_insert_bytes'] = strlen($huge);
            } catch (RuntimeException $e) {
                $row['huge_insert_error'] = $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $row['error'] ??= $e->getMessage();
    }
    restore_error_handler();
    $out['cap'][] = $row;
}

// 5. Limits: multi-MB statements. "ok" or the PCRE error, for upstream, cap 64 and unbounded.
$limits = [
    'INSERT, 60k rows of (?, string), 1.9 MB' => 'INSERT INTO `t` (`a`, `b`) VALUES ' . implode(', ', array_fill(0, 60000, "(?, 'value-with-some-length')")),
    'INSERT, 60k rows of literals, 2.0 MB'    => 'INSERT INTO `t` VALUES ' . implode(', ', array_fill(0, 60000, "('value-with-some-length', 'x')")),
    'SELECT, 100k identifiers, 1.6 MB'        => 'SELECT ' . str_repeat('`a`.`b` AS `c`, ', 100000) . '1',
    'One 5 MB string literal'                 => "SELECT '" . str_repeat('x', 5000000) . "'",
];
foreach ($limits as $name => $sqlText) {
    $row = ['name' => $name];
    foreach (['upstream' => $upstream, 'cap_64' => $replacement, 'unbounded' => new VariantParser(true, 0)] as $key => $p) {
        try {
            $start = hrtime(true);
            $p->parse($sqlText, new CountingVisitor());
            $row[$key] = sprintf('ok (%.1f ms)', (hrtime(true) - $start) / 1e6);
        } catch (Throwable $e) {
            $row[$key] = $e->getMessage();
        }
    }
    $out['limits'][] = $row;
}

echo json_encode($out, JSON_PRETTY_PRINT), "\n";
