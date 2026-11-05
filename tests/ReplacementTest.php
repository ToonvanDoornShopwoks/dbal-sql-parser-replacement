<?php

declare(strict_types=1);

namespace DbalSqlParserReplacement\Tests;

use DbalSqlParserReplacement\Tests\Fixtures\OriginalParser;
use Doctrine\DBAL\SQL\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_fill;
use function count;
use function dirname;
use function implode;
use function mt_rand;
use function mt_srand;
use function realpath;
use function sprintf;
use function str_repeat;

final class ReplacementTest extends TestCase
{
    public function testReplacementIsTheLoadedParser(): void
    {
        self::assertSame(
            realpath(dirname(__DIR__) . '/src/SQL/Parser.php'),
            (new ReflectionClass(Parser::class))->getFileName(),
            'Composer loaded the vendor Parser instead of the replacement.',
        );
    }

    #[DataProvider('statements')]
    public function testOutputMatchesUpstreamParser(string $sql): void
    {
        foreach ([true, false] as $mySQL) {
            $expected = new RecordingVisitor();
            (new OriginalParser($mySQL))->parse($sql, $expected);

            $actual = new RecordingVisitor();
            (new Parser($mySQL))->parse($sql, $actual);

            self::assertSame($expected->parameters, $actual->parameters);
            self::assertSame($expected->result(), $actual->result());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function statements(): iterable
    {
        $columns = [];
        for ($i = 0; $i < 400; $i++) {
            $columns[] = sprintf('`product`.`field_%d` as `product.field_%d`', $i, $i);
        }

        yield 'Shopware-style wide SELECT' => [
            'SELECT ' . implode(', ', $columns)
                . ' FROM `product` WHERE `product`.`id` IN (:ids) AND `product`.`version_id` = :version'
                . ' AND (`product`.`active` = ? OR `product`.`name` LIKE \'%?%\')',
        ];

        yield 'parameter right after 64+ identifiers' => [
            'SELECT ' . str_repeat('`a`,', 200) . ':p, ? FROM t WHERE x = :q',
        ];

        yield 'comments, strings and casts' => [
            "SELECT /* :no ? */ a::text, 'it''s :x ?', \"q\\\"?\" -- trailing :c\nFROM t WHERE a = ?? OR b = :b AND c = ARRAY[1,2] AND d = [col?]",
        ];

        yield 'multi-megabyte INSERT' => [
            'INSERT INTO `t` (`a`, `b`) VALUES ' . implode(', ', array_fill(0, 60000, "(?, 'value-with-some-length')")),
        ];

        // Upstream's MySQL string literal costs one PCRE backtrack frame per byte. With one token per
        // call it survives ~6 KB per literal; batching 64 tokens per call would share that budget
        // across all 64 literals (~179 bytes each) unless the literal pattern is stack-free.
        yield '64 adjacent 1 KB string literals, MySQL escaping' => [
            'INSERT INTO `t` (`description`) VALUES '
                . implode(', ', array_fill(0, 64, "('" . str_repeat('lorem ipsum \\\' dolor \\\\ sit amet ', 32) . "')"))
                . ' ON DUPLICATE KEY UPDATE `updated_at` = :now',
        ];

        yield '64 adjacent 1 KB double-quoted literals' => [
            'SELECT ' . implode(', ', array_fill(0, 64, '"' . str_repeat('x\\"y', 256) . '"')) . ' WHERE a = ?',
        ];

        mt_srand(1804);
        $alphabet = ['`x`', '`y` ', ':n', ':n_1', '?', '??', "'s'", "'a?'", '"d"', '-', '--c', "\n", '/*c*/', '/', '[', ']', 'ARRAY[1]', '::', ' ', 'SELECT ', ',', '\\', "'\\''", 'a'];
        yield from self::randomSoups('random', $alphabet, 200, 300);

        // The literal pattern is rewritten (possessive, stack-free), so hammer quote and backslash
        // combinations specifically: unterminated quotes, trailing backslashes, escaped delimiters.
        $quotes = ["'", '"', '\\', "\\'", '\\"', '\\\\', "''", '""', 'a', ' ', ':p', '?', '`x`', "\n", '--c', '/*c*/', ',', '::'];
        yield from self::randomSoups('quote soup', $quotes, 2000, 40);
    }

    /**
     * The replacement must not fail where upstream succeeds, and the rewritten literal pattern also
     * lifts upstream's own limit: a single MySQL literal above ~6 KB exhausts the JIT stack upstream.
     */
    public function testLargeMySQLStringLiteralsDoNotExhaustThePcreStack(): void
    {
        $literal = "'" . str_repeat(str_repeat('x', 62) . '\\\'', 1024) . "'"; // 64 KB, 1,024 escapes
        $literals = 'SELECT ' . implode(', ', array_fill(0, 64, $literal)) . ' WHERE a = ';

        $visitor = new RecordingVisitor();
        (new Parser(true))->parse($literals . ':p AND b = ?', $visitor);

        self::assertSame([':p', '?'], $visitor->parameters);
        self::assertSame($literals . '{:p} AND b = {?}', $visitor->result());
    }

    /**
     * @param list<string> $alphabet
     *
     * @return iterable<string, array{string}>
     */
    private static function randomSoups(string $prefix, array $alphabet, int $cases, int $maxLength): iterable
    {
        for ($case = 0; $case < $cases; $case++) {
            $sql    = '';
            $length = mt_rand(1, $maxLength);
            for ($i = 0; $i < $length; $i++) {
                $sql .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            yield $prefix . ' #' . $case => [$sql];
        }
    }
}
