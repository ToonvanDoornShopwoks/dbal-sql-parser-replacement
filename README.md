# Doctrine DBAL 4 SQL Parser Replacement

Drop-in replacement for `Doctrine\DBAL\SQL\Parser` (doctrine/dbal 4.3–4.5). It is loaded through
Composer `files` autoloading, so the class is declared before Composer would ever load the vendor
copy. No patching of `vendor/` required.

The upstream parser calls `preg_match()` once per token. Shopware builds very wide `SELECT` lists
(hundreds of backtick identifiers), so a single query can cost hundreds of regex calls. This
version changes only the token pattern:

- `OTHER` is possessive (`[^…]++`): a run of plain SQL never has to give characters back.
- The `other` group consumes up to 64 non-parameter tokens per call (`(?:…){1,64}+`). No non-parameter
  alternative can start where a parameter starts, so the sequence of parameters and the reassembled
  SQL are identical to upstream.
- The cap is deliberate: an unbounded run exhausts the PCRE JIT stack on multi-MB SQL, and caps of
  256+ exceed PCRE's compiled pattern size limit.

## Install

```shell
composer require toonvandoornshopwoks/dbal-sql-parser-replacement
```

Nothing else is needed. If you previously applied the equivalent `vendor/` patch, remove it.

## Results

`php bench/bench.php`, 400-column Shopware-style SELECT (18 KB), PHP 8.3:

| Parser      | per parse |
|-------------|-----------|
| upstream    | 0.483 ms  |
| replacement | 0.025 ms  |

## Safety

- `doctrine/dbal` is constrained to `>=4.3.0 <4.6.0`. Upstream `Parser.php` is functionally identical
  across those versions (4.5.0 only drops an unused import). Composer will refuse a DBAL version
  this package has not been checked against, so it can never silently shadow a changed parser.
- `tests/ReplacementTest.php` asserts the replacement is the class actually loaded, and compares
  its output with an untouched copy of upstream 4.4.5 (`tests/Fixtures/OriginalParser.php`) on wide
  SELECTs, a multi-MB INSERT, comments/strings/casts and 200 random token soups, in MySQL and ANSI
  mode.
- `tests/UpstreamParserTest.php` is Doctrine's own `ParserTest` run against the replacement.

```shell
composer install && vendor/bin/phpunit
```

## Upgrading DBAL

When DBAL ships a new minor, diff its `src/SQL/Parser.php` against `tests/Fixtures/OriginalParser.php`.
If it is unchanged (or only cosmetic), widen the constraint. Otherwise port the change, refresh the
fixture, and run the tests.
