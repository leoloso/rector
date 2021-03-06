<?php

declare(strict_types=1);

namespace Rector\Core\PhpParser\Node\Value;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\MagicConst\File;
use PhpParser\Node\Stmt\ClassConst;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\ConstantScalarType;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\NodeAnalyzer\ConstFetchAnalyzer;
use Rector\NodeCollector\NodeCollector\ParsedNodeCollector;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Symplify\SmartFileSystem\SmartFileInfo;

/**
 * @see \Rector\Core\Tests\PhpParser\Node\Value\ValueResolverTest
 */
final class ValueResolver
{
    /**
     * @var NodeNameResolver
     */
    private $nodeNameResolver;

    /**
     * @var ConstExprEvaluator
     */
    private $constExprEvaluator;

    /**
     * @var ParsedNodeCollector
     */
    private $parsedNodeCollector;

    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    /**
     * @var ConstFetchAnalyzer
     */
    private $constFetchAnalyzer;

    public function __construct(
        NodeNameResolver $nodeNameResolver,
        NodeTypeResolver $nodeTypeResolver,
        ParsedNodeCollector $parsedNodeCollector,
        ConstFetchAnalyzer $constFetchAnalyzer
    ) {
        $this->nodeNameResolver = $nodeNameResolver;
        $this->parsedNodeCollector = $parsedNodeCollector;
        $this->nodeTypeResolver = $nodeTypeResolver;
        $this->constFetchAnalyzer = $constFetchAnalyzer;
    }

    /**
     * @param mixed $value
     */
    public function isValue(Expr $expr, $value): bool
    {
        return $this->getValue($expr) === $value;
    }

    /**
     * @return mixed|null
     */
    public function getValue(Expr $expr, bool $resolvedClassReference = false)
    {
        if ($expr instanceof Concat) {
            return $this->processConcat($expr, $resolvedClassReference);
        }

        if ($expr instanceof ClassConstFetch && $resolvedClassReference) {
            $class = $this->nodeNameResolver->getName($expr->class);

            if (in_array($class, ['self', 'static'], true)) {
                return $expr->getAttribute(AttributeKey::CLASS_NAME);
            }

            if ($this->nodeNameResolver->isName($expr->name, 'class')) {
                return $class;
            }
        }

        try {
            $constExprEvaluator = $this->getConstExprEvaluator();
            $value = $constExprEvaluator->evaluateDirectly($expr);
        } catch (ConstExprEvaluationException $constExprEvaluationException) {
            $value = null;
        }

        if ($value !== null) {
            return $value;
        }

        if ($expr instanceof ConstFetch) {
            return $this->nodeNameResolver->getName($expr);
        }

        $nodeStaticType = $this->nodeTypeResolver->getStaticType($expr);

        if ($nodeStaticType instanceof ConstantArrayType) {
            return $this->extractConstantArrayTypeValue($nodeStaticType);
        }

        if ($nodeStaticType instanceof ConstantScalarType) {
            return $nodeStaticType->getValue();
        }

        return null;
    }

    /**
     * @param mixed[] $expectedValues
     */
    public function isValues(Expr $expr, array $expectedValues): bool
    {
        foreach ($expectedValues as $expectedValue) {
            if ($this->isValue($expr, $expectedValue)) {
                return true;
            }
        }

        return false;
    }

    public function isFalse(Node $node): bool
    {
        return $this->constFetchAnalyzer->isFalse($node);
    }

    public function isTrueOrFalse(Node $node): bool
    {
        return $this->constFetchAnalyzer->isTrueOrFalse($node);
    }

    public function isTrue(Node $node): bool
    {
        return $this->constFetchAnalyzer->isTrue($node);
    }

    public function isNull(Node $node): bool
    {
        return $this->constFetchAnalyzer->isNull($node);
    }

    private function processConcat(Concat $concat, bool $resolvedClassReference): string
    {
        return $this->getValue($concat->left, $resolvedClassReference) . $this->getValue(
                $concat->right,
                $resolvedClassReference
            );
    }

    private function getConstExprEvaluator(): ConstExprEvaluator
    {
        if ($this->constExprEvaluator !== null) {
            return $this->constExprEvaluator;
        }

        $this->constExprEvaluator = new ConstExprEvaluator(function (Expr $expr) {
            if ($expr instanceof Dir) {
                // __DIR__
                return $this->resolveDirConstant($expr);
            }

            if ($expr instanceof File) {
                // __FILE__
                return $this->resolveFileConstant($expr);
            }

            // resolve "SomeClass::SOME_CONST"
            if ($expr instanceof ClassConstFetch) {
                return $this->resolveClassConstFetch($expr);
            }

            throw new ConstExprEvaluationException(sprintf(
                'Expression of type "%s" cannot be evaluated',
                $expr->getType()
            ));
        });

        return $this->constExprEvaluator;
    }

    /**
     * @return mixed[]
     */
    private function extractConstantArrayTypeValue(ConstantArrayType $constantArrayType): array
    {
        $keys = [];
        foreach ($constantArrayType->getKeyTypes() as $i => $keyType) {
            /** @var ConstantScalarType $keyType */
            $keys[$i] = $keyType->getValue();
        }

        $values = [];
        foreach ($constantArrayType->getValueTypes() as $i => $valueType) {
            if ($valueType instanceof ConstantArrayType) {
                $value = $this->extractConstantArrayTypeValue($valueType);
            } elseif ($valueType instanceof ConstantScalarType) {
                $value = $valueType->getValue();
            } else {
                // not sure about value
                continue;
            }

            $values[$keys[$i]] = $value;
        }

        return $values;
    }

    private function resolveDirConstant(Dir $dir): string
    {
        $fileInfo = $dir->getAttribute(AttributeKey::FILE_INFO);
        if (! $fileInfo instanceof SmartFileInfo) {
            throw new ShouldNotHappenException();
        }

        return $fileInfo->getPath();
    }

    private function resolveFileConstant(File $file): string
    {
        $fileInfo = $file->getAttribute(AttributeKey::FILE_INFO);
        if (! $fileInfo instanceof SmartFileInfo) {
            throw new ShouldNotHappenException();
        }

        return $fileInfo->getPathname();
    }

    /**
     * @return string|mixed
     */
    private function resolveClassConstFetch(ClassConstFetch $classConstFetch)
    {
        $class = $this->nodeNameResolver->getName($classConstFetch->class);
        $constant = $this->nodeNameResolver->getName($classConstFetch->name);

        if ($class === null) {
            throw new ShouldNotHappenException();
        }

        if ($constant === null) {
            throw new ShouldNotHappenException();
        }

        if ($class === 'self') {
            $class = (string) $classConstFetch->class->getAttribute(AttributeKey::CLASS_NAME);
        }

        if ($constant === 'class') {
            return $class;
        }

        $classConstNode = $this->parsedNodeCollector->findClassConstant($class, $constant);

        if (! $classConstNode instanceof ClassConst) {
            // fallback to the name
            return $class . '::' . $constant;
        }

        return $this->constExprEvaluator->evaluateDirectly($classConstNode->consts[0]->value);
    }
}
