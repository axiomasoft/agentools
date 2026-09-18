<?php

declare(strict_types=1);

namespace AgenTools\Scenario;

/**
 * SCN-13–SCN-19. This is deliberately a parser plus a tree walker: it never
 * turns an expression into PHP code, a closure, or another executable form.
 */
final class ExpressionEvaluator
{
    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];
    private int $position = 0;
    private int $visited = 0;
    private int $parseDepth = 0;

    public function __construct(private readonly ExpressionLimits $limits = new ExpressionLimits())
    {
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $steps
     */
    public function evaluate(string $expression, array $context, array $steps = []): mixed
    {
        if (mb_strlen($expression, 'UTF-8') > $this->limits->maxLength) {
            throw ExpressionException::limit('expression.max_length', $this->limits->maxLength);
        }

        $this->tokens = $this->tokenize($expression);
        $this->position = 0;
        $this->visited = 0;
        $this->parseDepth = 0;
        $tree = $this->orExpression();
        if ($this->peek()['type'] !== 'eof') {
            throw new ExpressionException(null, 'Unexpected token in expression.');
        }

        return $this->walk($tree, $context, $steps);
    }

    /** @return list<array{type: string, value: string}> */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($input);
        while ($offset < $length) {
            if (preg_match('/\G\s+/A', $input, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G(?:&&|\|\||==|!=)/A', $input, $match, 0, $offset) === 1) {
                $tokens[] = ['type' => 'operator', 'value' => $match[0]];
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G[!()]/A', $input, $match, 0, $offset) === 1) {
                $tokens[] = ['type' => 'operator', 'value' => $match[0]];
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G\$(?:ctx\.[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*|steps\.[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)/A', $input, $match, 0, $offset) === 1) {
                $tokens[] = ['type' => 'reference', 'value' => $match[0]];
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G"(?:[^"\\\\]|\\["\\\\\/ntr])*"/A', $input, $match, 0, $offset) === 1) {
                $tokens[] = ['type' => 'string', 'value' => $match[0]];
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G-?\d+(?:\.\d+)?/A', $input, $match, 0, $offset) === 1) {
                $tokens[] = ['type' => 'number', 'value' => $match[0]];
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G(?:true|false|null|missing)\b/A', $input, $match, 0, $offset) === 1) {
                $tokens[] = ['type' => 'word', 'value' => $match[0]];
                $offset += strlen($match[0]);
                continue;
            }
            throw new ExpressionException(null, 'Expression contains a token outside the published grammar.');
        }
        $tokens[] = ['type' => 'eof', 'value' => ''];
        return $tokens;
    }

    /** @return array<string, mixed> */
    private function orExpression(): array
    {
        $node = $this->andExpression();
        while ($this->take('||')) {
            $node = ['kind' => 'binary', 'operator' => '||', 'left' => $node, 'right' => $this->andExpression()];
        }
        return $node;
    }

    /** @return array<string, mixed> */
    private function andExpression(): array
    {
        $node = $this->notExpression();
        while ($this->take('&&')) {
            $node = ['kind' => 'binary', 'operator' => '&&', 'left' => $node, 'right' => $this->notExpression()];
        }
        return $node;
    }

    /** @return array<string, mixed> */
    private function notExpression(): array
    {
        if ($this->take('!')) {
            return ['kind' => 'not', 'operand' => $this->notExpression()];
        }
        $node = $this->primary();
        if ($this->take('==')) {
            return ['kind' => 'binary', 'operator' => '==', 'left' => $node, 'right' => $this->primary()];
        }
        if ($this->take('!=')) {
            return ['kind' => 'binary', 'operator' => '!=', 'left' => $node, 'right' => $this->primary()];
        }
        return $node;
    }

    /** @return array<string, mixed> */
    private function primary(): array
    {
        if ($this->take('(')) {
            if (++$this->parseDepth > $this->limits->maxDepth) {
                throw ExpressionException::limit('expression.max_depth', $this->limits->maxDepth);
            }
            $node = $this->orExpression();
            $this->expect(')');
            --$this->parseDepth;
            return $node;
        }
        $token = $this->peek();
        if ($token['type'] === 'reference') {
            ++$this->position;
            return ['kind' => 'reference', 'value' => $token['value']];
        }
        if ($token['type'] === 'word' && $token['value'] === 'missing') {
            ++$this->position;
            $this->expect('(');
            $reference = $this->peek();
            if ($reference['type'] !== 'reference') {
                throw new ExpressionException(null, 'missing() accepts exactly one rooted reference.');
            }
            ++$this->position;
            $this->expect(')');
            return ['kind' => 'missing', 'value' => $reference['value']];
        }
        if ($token['type'] === 'string') {
            ++$this->position;
            $decoded = json_decode($token['value'], true, 512, JSON_THROW_ON_ERROR);
            return ['kind' => 'literal', 'value' => $decoded];
        }
        if ($token['type'] === 'number') {
            ++$this->position;
            return ['kind' => 'literal', 'value' => str_contains($token['value'], '.') ? (float) $token['value'] : (int) $token['value']];
        }
        if ($token['type'] === 'word' && in_array($token['value'], ['true', 'false', 'null'], true)) {
            ++$this->position;
            return ['kind' => 'literal', 'value' => match ($token['value']) { 'true' => true, 'false' => false, default => null }];
        }
        throw new ExpressionException(null, 'Expected a primary expression.');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $steps
     */
    private function walk(array $node, array $context, array $steps): mixed
    {
        if (++$this->visited > $this->limits->maxVisitedNodes) {
            throw ExpressionException::limit('expression.max_visited_nodes', $this->limits->maxVisitedNodes);
        }
        $kind = $this->nodeString($node, 'kind');
        return match ($kind) {
            'literal' => $node['value'] ?? null,
            'reference' => $this->resolve($this->nodeString($node, 'value'), $context, $steps, false),
            'missing' => !$this->exists($this->nodeString($node, 'value'), $context, $steps),
            'not' => !$this->boolean($this->walk($this->nodeArray($node, 'operand'), $context, $steps)),
            'binary' => $this->binary($node, $context, $steps),
            default => throw new \LogicException('Unknown expression AST node.'),
        };
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $steps
     */
    private function binary(array $node, array $context, array $steps): mixed
    {
        $left = $this->walk($this->nodeArray($node, 'left'), $context, $steps);
        $right = fn (): mixed => $this->walk($this->nodeArray($node, 'right'), $context, $steps);
        return match ($this->nodeString($node, 'operator')) {
            '&&' => $this->boolean($left) ? $this->boolean($right()) : false,
            '||' => $this->boolean($left) ? true : $this->boolean($right()),
            '==' => $left === $right(),
            '!=' => $left !== $right(),
            default => throw new \LogicException('Unknown expression operator.'),
        };
    }

    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new ExpressionException(null, 'Logical operators accept only boolean operands.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $steps
     */
    private function resolve(string $reference, array $context, array $steps, bool $allowMissing): mixed
    {
        $parts = explode('.', substr($reference, 1));
        $root = array_shift($parts);
        $value = $root === 'ctx' ? $context : ($steps[array_shift($parts) ?? ''] ?? null);
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                if ($allowMissing) {
                    return null;
                }
                throw new ExpressionException(null, "Missing path {$reference}.");
            }
            $value = $value[$part];
        }
        if (is_array($value) && count($value) > $this->limits->maxCollectionSize) {
            throw ExpressionException::limit('expression.max_collection_size', $this->limits->maxCollectionSize);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $steps
     */
    private function exists(string $reference, array $context, array $steps): bool
    {
        $parts = explode('.', substr($reference, 1));
        $root = array_shift($parts);
        if ($root === 'ctx') {
            $value = $context;
        } else {
            $step = array_shift($parts);
            if ($step === null || !array_key_exists($step, $steps)) {
                return false;
            }
            $value = $steps[$step];
        }
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return false;
            }
            $value = $value[$part];
        }
        return true;
    }

    /** @param array<string, mixed> $node */
    private function nodeString(array $node, string $key): string
    {
        if (!is_string($node[$key] ?? null)) {
            throw new \LogicException("Expression AST node requires string {$key}.");
        }
        return $node[$key];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function nodeArray(array $node, string $key): array
    {
        if (!is_array($node[$key] ?? null)) {
            throw new \LogicException("Expression AST node requires child {$key}.");
        }
        $child = [];
        foreach ($node[$key] as $childKey => $childValue) {
            if (!is_string($childKey)) {
                throw new \LogicException('Expression AST node keys must be strings.');
            }
            $child[$childKey] = $childValue;
        }
        return $child;
    }

    /** @return array{type: string, value: string} */
    private function peek(): array { return $this->tokens[$this->position]; }
    private function take(string $value): bool { if ($this->peek()['value'] !== $value) { return false; } ++$this->position; return true; }
    private function expect(string $value): void { if (!$this->take($value)) { throw new ExpressionException(null, "Expected {$value}."); } }
}
