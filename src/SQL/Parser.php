<?php

declare(strict_types=1);

namespace Doctrine\DBAL\SQL;

// Drop-in replacement for doctrine/dbal 4.3-4.5 src/SQL/Parser.php, loaded early through Composer
// "files" autoloading so it is declared before Composer could load the vendor copy.
// Only the OTHER constant, the "other" group of $tokenPattern and the MySQL string literal pattern
// differ from upstream 4.4.5.

use Doctrine\DBAL\SQL\Parser\Exception;
use Doctrine\DBAL\SQL\Parser\Exception\RegularExpressionError;
use Doctrine\DBAL\SQL\Parser\Visitor;

use function array_merge;
use function implode;
use function preg_last_error;
use function preg_match;
use function sprintf;
use function strlen;

use const PREG_NO_ERROR;

/**
 * The SQL parser that focuses on identifying prepared statement parameters. It implements parsing other tokens like
 * string literals and comments only as a way to not confuse their contents with the the parameter placeholders.
 *
 * The parsing logic and the implementation is inspired by the PHP PDO parser.
 *
 * @internal
 *
 * @see https://github.com/php/php-src/blob/php-7.4.12/ext/pdo/pdo_sql_parser.re#L49-L69
 */
final class Parser
{
    private const SPECIAL_CHARS = ':\?\'"`\\[\\-\\/';

    private const BACKTICK_IDENTIFIER  = '`[^`]*`';
    private const BRACKET_IDENTIFIER   = '(?<!\b(?i:ARRAY))\[(?:[^\]])*\]';
    private const MULTICHAR            = ':{2,}';
    private const NAMED_PARAMETER      = ':[a-zA-Z0-9_]+';
    private const POSITIONAL_PARAMETER = '(?<!\\?)\\?(?!\\?)';
    private const ONE_LINE_COMMENT     = '--[^\r\n]*';
    private const MULTI_LINE_COMMENT   = '/\*([^*]+|\*+[^/*])*\**\*/';
    private const SPECIAL              = '[' . self::SPECIAL_CHARS . ']';
    // Possessive: a run of plain SQL never needs to give characters back.
    private const OTHER                = '[^' . self::SPECIAL_CHARS . ']++';

    private readonly string $sqlPattern;
    private readonly string $tokenPattern;

    public function __construct(bool $mySQLStringEscaping)
    {
        if ($mySQLStringEscaping) {
            $patterns = [
                $this->getMySQLStringLiteralPattern("'"),
                $this->getMySQLStringLiteralPattern('"'),
            ];
        } else {
            $patterns = [
                $this->getAnsiSQLStringLiteralPattern("'"),
                $this->getAnsiSQLStringLiteralPattern('"'),
            ];
        }

        $patterns = array_merge($patterns, [
            self::BACKTICK_IDENTIFIER,
            self::BRACKET_IDENTIFIER,
            self::MULTICHAR,
            self::ONE_LINE_COMMENT,
            self::MULTI_LINE_COMMENT,
            self::OTHER,
        ]);

        $this->sqlPattern   = sprintf('(%s)', implode('|', $patterns));
        $this->tokenPattern = '~\\G'
            . '(?P<named>' . self::NAMED_PARAMETER . ')'
            . '|(?P<positional>' . self::POSITIONAL_PARAMETER . ')'
            // Consume up to 64 non-parameter tokens per preg_match() call instead of one.
            // Shopware's long SELECT lists are hundreds of backtick identifiers, so this cuts ~98% of the
            // calls (2,414 -> 41 for 400 columns). No sqlPattern alternative can start where a parameter
            // starts, so output is unchanged. Bounded on purpose: an unbounded run exhausts PCRE's
            // backtrack limit on multi-MB SQL with the JIT off, and caps of 192+ exceed PCRE's compiled
            // pattern size limit.
            . '|(?P<other>(?:' . $this->sqlPattern . '){1,64}+|' . self::SPECIAL . ')'
            . '~s';
    }

    /**
     * Parses the given SQL statement
     *
     * @throws Exception
     */
    public function parse(string $sql, Visitor $visitor): void
    {
        $offset = 0;
        $length = strlen($sql);
        while ($offset < $length) {
            if (preg_match($this->tokenPattern, $sql, $matches, 0, $offset) === 1) {
                $match = $matches[0];
                if ($matches['named'] !== '') {
                    $visitor->acceptNamedParameter($match);
                } elseif ($matches['positional'] !== '') {
                    $visitor->acceptPositionalParameter($match);
                } else {
                    $visitor->acceptOther($match);
                }

                $offset += strlen($match);
            } elseif (preg_last_error() !== PREG_NO_ERROR) {
                // @codeCoverageIgnoreStart
                throw RegularExpressionError::new();
                // @codeCoverageIgnoreEnd
            }
        }
    }

    private function getMySQLStringLiteralPattern(string $delimiter): string
    {
        // Upstream: '((\\.)|(?![\'\\]).)*'. Same language (runs of non-delimiter, non-backslash bytes,
        // or a backslash followed by any byte), unrolled and possessive. Upstream's form costs a PCRE
        // backtrack frame per byte, which limits a literal to ~6 KB (1,750 escapes) under the JIT.
        // With up to 64 tokens per preg_match() call those frames would accumulate across all 64
        // literals, so 64 adjacent 1 KB literals would fail where upstream does not. This form costs a
        // frame per escape sequence only: 64 adjacent literals of 31,000 escapes each still parse.
        return $delimiter . '[^' . $delimiter . '\\\\]*+(?:\\\\.[^' . $delimiter . '\\\\]*+)*+' . $delimiter;
    }

    private function getAnsiSQLStringLiteralPattern(string $delimiter): string
    {
        return $delimiter . '[^' . $delimiter . ']*' . $delimiter;
    }
}
