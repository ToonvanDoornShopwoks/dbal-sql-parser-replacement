<?php

declare(strict_types=1);

namespace DbalSqlParserReplacement\Tests;

use Doctrine\DBAL\SQL\Parser\Visitor;

use function implode;

/** Records the parameter sequence and the reassembled SQL; token granularity of "other" is irrelevant. */
final class RecordingVisitor implements Visitor
{
    /** @var list<string> */
    public array $parameters = [];

    /** @var list<string> */
    private array $sql = [];

    public function acceptPositionalParameter(string $sql): void
    {
        $this->parameters[] = $sql;
        $this->sql[]        = '{' . $sql . '}';
    }

    public function acceptNamedParameter(string $sql): void
    {
        $this->parameters[] = $sql;
        $this->sql[]        = '{' . $sql . '}';
    }

    public function acceptOther(string $sql): void
    {
        $this->sql[] = $sql;
    }

    public function result(): string
    {
        return implode('', $this->sql);
    }
}
