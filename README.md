# Doctrine DBAL 4 SQL Parser Replacement

Drop-in replacement for `Doctrine\DBAL\SQL\Parser` (doctrine/dbal 4.3–4.5). It is loaded through
Composer `files` autoloading, so the class is declared before Composer would ever load the vendor
copy. No patching of `vendor/` required.

> **Upstreamed.** The same patterns were merged into Doctrine DBAL in
> [doctrine/dbal#7596](https://github.com/doctrine/dbal/pull/7596) for DBAL 4.6.0. This package is a
> backport of that parser for DBAL 4.3–4.5. Once you are on DBAL 4.6.0, remove this package.

The upstream parser calls `preg_match()` once per token. Shopware builds very wide `SELECT` lists
(hundreds of backtick identifiers), so a single query can cost hundreds of regex calls. This
version changes only the regex patterns:

- The `other` group consumes up to 64 non-parameter tokens per call (`(?&token){1,64}+`). No non-parameter
  alternative can start where a parameter starts, so the sequence of parameters and the reassembled
  SQL are identical to upstream.
- `OTHER` is possessive (`[^…]++`): a run of plain SQL never has to give characters back.
- The MySQL string literal pattern is unrolled and possessive (`'[^'\\]*+(?:\\.[^'\\]*+)*+'`).
  Upstream's form costs a PCRE backtrack frame per byte; with 64 tokens per call those frames would
  add up and 64 adjacent 1 KB literals would fail where upstream does not. The new form costs a
  frame per escape sequence only.
- The token is a `(?(DEFINE)(?<token>…))` subroutine, not a repeated group. PCRE compiles `{1,64}` of
  a group into 64 copies; the subroutine is compiled once, so first use per process costs about the
  same as upstream, and each token keeps its own stack budget.
- The multi-line comment and bracket identifier patterns are unrolled and possessive too. This fixes
  an upstream bug: an unterminated `/*` of about 80 bytes throws `Backtrack limit exhausted`.
- ANSI string literals are possessive (`'[^']*+'`).
- The cap is deliberate: an unbounded run exhausts PCRE's backtrack limit on multi-MB SQL when the
  JIT is off.

## How it works

### The problem

Before Doctrine runs a query, it scans the SQL text to find the parameter placeholders (`:name` and
`?`) so it can bind values to them. Everything else in the query is just copied through unchanged.

The upstream parser reads the SQL one small piece at a time. Each piece is a backtick name like
`` `product`.`id` ``, a string literal, a comment, or a chunk of ordinary text. Every piece costs a
separate `preg_match()` call.

Normal queries have only a handful of pieces, so this is fine. Shopware is different: its queries
often have `SELECT` lists with hundreds of backtick column names. A single query then means hundreds
of regex calls, and each call has fixed overhead. Most of the time goes into starting the regex
engine over and over just to say "not a parameter, keep going".

### The fix

Only the regex pattern changes. The parsing logic stays the same.

1. **Take up to 64 boring pieces in one call.** Before, one call matched one non-parameter piece.
   Now one call can match a run of up to 64 of them in a row, stopping as soon as a parameter shows
   up. A 400-column `SELECT` goes from 2,414 calls to 41, about 98% fewer.
2. **Don't second-guess plain text.** The pattern for ordinary SQL text is now possessive (`++`
   instead of `+`). Once the regex engine has grabbed a stretch of plain text, it keeps it and never
   backs up to try a shorter match. That backing up could never succeed here anyway, so this only
   removes wasted work.
3. **Match string literals without a stack.** Upstream's MySQL string pattern makes the regex engine
   remember every character of the literal in case it has to back up. One literal per call fits in
   that budget (about 6 KB). Sixty-four literals in one call would not, so the literal pattern is
   rewritten to match the same strings without remembering anything. A side effect is that literals
   of any size now parse, where upstream fails above about 6 KB.

### Why the output is identical

None of the non-parameter piece types can begin where a parameter begins, so grouping them can never
swallow a `:name` or `?` by accident. The parser finds exactly the same parameters, and the SQL glued
back together is byte-for-byte the same (see [Safety](#safety) for how this is tested).

### Why exactly 64

- **No limit:** on very large SQL (multi-MB inserts), PCRE hits its backtrack limit when the JIT is
  disabled and the parse fails.
- **64:** big enough to remove about 98% of the calls, small enough to stay clear of that limit.

## Install

```shell
composer require toonvandoornshopwoks/dbal-sql-parser-replacement
```

Nothing else is needed. If you previously applied the equivalent `vendor/` patch, remove it.

## Results

`php bench/bench.php`, 400-column Shopware-style SELECT (18 KB), PHP 8.3.29, JIT on:

| Parser      | per parse |
|-------------|-----------|
| upstream    | 0.560 ms  |
| replacement | 0.030 ms  |

For the full numbers (JIT on and off, five statement sizes, first-use cost) see
[doctrine/dbal#7596](https://github.com/doctrine/dbal/pull/7596).

## Safety

- `doctrine/dbal` is constrained to `>=4.3.0 <4.6.0`. Upstream `Parser.php` is functionally identical
  across those versions (4.5.0 only drops an unused import). Composer will refuse a DBAL version
  this package has not been checked against, so it can never silently shadow a changed parser.
- `tests/ReplacementTest.php` asserts the replacement is the class actually loaded, and compares
  its output with an untouched copy of upstream 4.4.5 (`tests/Fixtures/OriginalParser.php`) on wide
  SELECTs, a multi-MB INSERT, comments/strings/casts, 64 adjacent 1 KB string literals, 200 random
  token soups and 2,000 quote/backslash soups, in MySQL and ANSI mode. It also parses 64 adjacent
  256 KB literals, which upstream cannot.
- `tests/UpstreamParserTest.php` is Doctrine's own `ParserTest` run against the replacement, plus the
  `testLargeStatements` cases from doctrine/dbal#7596 (unterminated and adjacent comments, large and
  adjacent bracket identifiers and MySQL literals, parameters around the 64-token boundary, a
  multi-megabyte statement). Run the suite with `-d pcre.jit=0` too.

```shell
composer install && vendor/bin/phpunit
```

## Upgrading DBAL

When DBAL ships a new minor, diff its `src/SQL/Parser.php` against `tests/Fixtures/OriginalParser.php`.
If it is unchanged (or only cosmetic), widen the constraint. Otherwise port the change, refresh the
fixture, and run the tests.
