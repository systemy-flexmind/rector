<?php

declare(strict_types=1);

namespace Rector\Php80\Rector\If_;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use Rector\Core\PhpParser\Node\Manipulator\IfManipulator;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * @see https://wiki.php.net/rfc/nullsafe_operator
 * @see \Rector\Php80\Tests\Rector\If_\NullsafeOperatorRector\NullsafeOperatorRectorTest
 */
final class NullsafeOperatorRector extends AbstractRector
{
    /**
     * @var string
     */
    private const NAME = 'name';

    /**
     * @var IfManipulator
     */
    private $ifManipulator;

    public function __construct(IfManipulator $ifManipulator)
    {
        $this->ifManipulator = $ifManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Change if null check with nullsafe operator ?-> with full short circuiting', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function f($o)
    {
        $o2 = $o->mayFail1();
        if ($o2 === null) {
            return null;
        }

        return $o2->mayFail2();
    }
}
CODE_SAMPLE
,
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function f($o)
    {
        return $o?->mayFail1()?->mayFail2();
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [If_::class];
    }

    /**
     * @param If_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $processNullSafeOperator = $this->processNullSafeOperator($node);
        if ($processNullSafeOperator !== null) {
            /** @var Expression $prevNode */
            $prevNode = $node->getAttribute(AttributeKey::PREVIOUS_NODE);
            $this->removeNode($prevNode);
        }

        return $processNullSafeOperator;
    }

    private function processNullSafeOperator(If_ $if): ?Node
    {
        $comparedNode = $this->ifManipulator->matchIfValueReturnValue($if);
        if ($comparedNode === null) {
            return null;
        }

        $prevNode = $if->getAttribute(AttributeKey::PREVIOUS_NODE);
        $nextNode = $if->getAttribute(AttributeKey::NEXT_NODE);

        if ($prevNode === null || $nextNode === null) {
            return null;
        }

        if (! $prevNode instanceof Expression || ! $this->isIfCondUsingAssignVariable($if, $prevNode->expr)) {
            return null;
        }

        /** @var Assign $assign */
        $assign = $prevNode->expr;
        return $this->processAssign($assign, $prevNode, $nextNode);
    }

    private function isIfCondUsingAssignVariable(Node $if, Node $assign): bool
    {
        if (! ($if instanceof If_ && $assign instanceof Assign)) {
            return false;
        }

        return $if->cond instanceof Identical && $this->areNodesEqual($if->cond->left, $assign->var);
    }

    private function processAssign(Assign $assign, Node $prevNode, Node $nextNode): ?Node
    {
        if (property_exists($nextNode, 'expr')) {
            $prevOfPrevNode = $prevNode->getAttribute(AttributeKey::PREVIOUS_NODE);
            if (! $prevOfPrevNode instanceof Stmt || $prevOfPrevNode instanceof If_) {
                $this->removeNode($prevNode);
                $this->removeNode($nextNode);
            }
        }

        if ($assign instanceof Assign && property_exists(
            $assign->expr,
            self::NAME
        ) && property_exists($nextNode, 'expr') && property_exists($nextNode->expr, self::NAME)) {
            return $this->processAssignInCurrentNode($assign, $prevNode, $nextNode);
        }

        return $this->processAssignMayInNextNode($nextNode);
    }

    private function processAssignInCurrentNode(Assign $assign, Node $prevNode, Node $nextNode): ?Node
    {
        $assignNullSafe = $this->processNullSafeExpr($assign->expr);
        $nullSafe = $this->processNullSafeExprResult($assignNullSafe, $nextNode->expr->name);

        $prevAssign = $prevNode->getAttribute(AttributeKey::PREVIOUS_NODE);
        if ($prevAssign instanceof If_) {
            $nullSafe = $this->getNullSafeOnPrevAssignIsIf($prevAssign, $nextNode, $nullSafe);
        }

        if ($nextNode instanceof Return_) {
            $nextNode->expr = $nullSafe;
            return $nextNode;
        }

        return $nullSafe;
    }

    private function processAssignMayInNextNode(Node $nextNode): ?Node
    {
        if (! $nextNode instanceof Expression || ! $nextNode->expr instanceof Assign) {
            return null;
        }

        $mayNextIf = $nextNode->getAttribute(AttributeKey::NEXT_NODE);
        if (! $mayNextIf instanceof If_) {
            return null;
        }

        if ($this->isIfCondUsingAssignVariable($mayNextIf, $nextNode->expr)) {
            return $this->processNullSafeOperator($mayNextIf);
        }

        return null;
    }

    private function processNullSafeExpr(Expr $expr): ?Expr
    {
        if ($expr instanceof MethodCall) {
            return new NullsafeMethodCall($expr->var, $expr->name);
        }

        if (property_exists($expr, 'var') && property_exists($expr, self::NAME)) {
            return new NullsafePropertyFetch($expr->var, $expr->name);
        }

        return null;
    }

    private function processNullSafeExprResult(?Expr $expr, Identifier $nextExprIdentifier): ?Expr
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof NullsafeMethodCall) {
            return new NullsafeMethodCall($expr, $nextExprIdentifier);
        }

        return new NullsafePropertyFetch($expr, $nextExprIdentifier);
    }

    private function getNullSafeOnPrevAssignIsIf(If_ $if, Node $nextNode, ?Expr $expr): ?Expr
    {
        $prevIf = $if->getAttribute(AttributeKey::PREVIOUS_NODE);
        if ($prevIf instanceof Expression && $this->isIfCondUsingAssignVariable($if, $prevIf->expr)) {
            $start = $prevIf;
            while ($prevIf instanceof Expression) {
                $expr = $this->processNullSafeExpr($prevIf->expr->expr);
                /** @var If_ $prevIf */
                $prevIf = $prevIf->getAttribute(AttributeKey::PREVIOUS_NODE);
                /** @var Expression|Identifier $prevIf */
                $prevIf = $prevIf->getAttribute(AttributeKey::PREVIOUS_NODE);

                if (! $prevIf instanceof Expression) {
                    $start = $this->getStartNode($prevIf);
                    break;
                }
            }

            $expr = $this->getNullSafeAfterStartUntilBeforeEnd($start, $expr);
            $expr = $this->processNullSafeExprResult($expr, $nextNode->expr->name);
        }

        return $expr;
    }

    private function getStartNode(Node $node): ?Node
    {
        /** @var If_ $start */
        $start = $node->getAttribute(AttributeKey::NEXT_NODE);
        /** @var Expression $start */
        $start = $start->getAttribute(AttributeKey::NEXT_NODE);
        /** @var If_ $start */
        $start = $start->getAttribute(AttributeKey::NEXT_NODE);

        /** @var Expression $start */
        return $start->getAttribute(AttributeKey::NEXT_NODE);
    }

    private function getNullSafeAfterStartUntilBeforeEnd(?Node $node, ?Expr $expr): ?Expr
    {
        while ($node) {
            $expr = $this->processNullSafeExprResult($expr, $node->expr->expr->name);

            $node = $node->getAttribute(AttributeKey::NEXT_NODE);
            while ($node) {
                /** @var If_ $if */
                $if = $node->getAttribute(AttributeKey::NEXT_NODE);
                if ($node instanceof Expression && $this->isIfCondUsingAssignVariable($if, $node->expr)) {
                    break;
                }

                $node = $node->getAttribute(AttributeKey::NEXT_NODE);
            }
        }

        return $expr;
    }
}
