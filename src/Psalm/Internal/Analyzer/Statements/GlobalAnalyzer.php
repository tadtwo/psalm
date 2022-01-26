<?php

namespace Psalm\Internal\Analyzer\Statements;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\VariableFetchAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\ReferenceConstraint;
use Psalm\Issue\InvalidGlobal;
use Psalm\IssueBuffer;
use RuntimeException;

use function is_string;

/**
 * @internal
 */
class GlobalAnalyzer
{
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Stmt\Global_ $stmt,
        Context $context,
        ?Context $global_context
    ): void {
        if (!$context->collect_initializations && !$global_context) {
            IssueBuffer::maybeAdd(
                new InvalidGlobal(
                    'Cannot use global scope here',
                    new CodeLocation($statements_analyzer, $stmt)
                ),
                $statements_analyzer->getSource()->getSuppressedIssues()
            );
        }

        $source = $statements_analyzer->getSource();
        $function_storage = $source instanceof FunctionLikeAnalyzer
            ? $source->getFunctionLikeStorage($statements_analyzer)
            : null;

        foreach ($stmt->vars as $var) {
            if ($var instanceof PhpParser\Node\Expr\Variable) {
                if (is_string($var->name)) {
                    $var_id = '$' . $var->name;

                    if ($var->name === 'argv' || $var->name === 'argc') {
                        $context->vars_in_scope[$var_id] = VariableFetchAnalyzer::getGlobalType($var_id);
                    } elseif (isset($function_storage->global_types[$var_id])) {
                        $context->vars_in_scope[$var_id] = clone $function_storage->global_types[$var_id];
                        $context->vars_possibly_in_scope[$var_id] = true;
                    } else {
                        $context->vars_in_scope[$var_id] =
                            $global_context && $global_context->hasVariable($var_id)
                                ? clone $global_context->vars_in_scope[$var_id]
                                : VariableFetchAnalyzer::getGlobalType($var_id);

                        $context->vars_possibly_in_scope[$var_id] = true;

                        $context->byref_constraints[$var_id] = new ReferenceConstraint();
                    }

                    $assignment_node = DataFlowNode::getForAssignment(
                        $var_id,
                        new CodeLocation($statements_analyzer, $var)
                    );
                    $context->vars_in_scope[$var_id]->parent_nodes = [
                        $assignment_node->id => $assignment_node,
                    ];
                    $context->references_to_external_scope[$var_id] = true;

                    if (isset($context->references_in_scope[$var_id])) {
                        // Global shadows existing reference
                        $reference_count = &$context->referenced_counts[$context->references_in_scope[$var_id]];
                        if ($reference_count < 1) {
                            throw new RuntimeException("Incorrect referenced count found");
                        }
                        --$reference_count;
                        unset($context->references_in_scope[$var_id]);
                    }
                    $statements_analyzer->registerVariable(
                        $var_id,
                        new CodeLocation($statements_analyzer, $var),
                        $context->branch_point
                    );
                    $statements_analyzer->getCodebase()->analyzer->addNodeReference(
                        $statements_analyzer->getFilePath(),
                        $var,
                        $var_id
                    );

                    if ($global_context !== null && $global_context->hasVariable($var_id)) {
                        $global_context->referenced_globals[$var_id] = true;
                    }
                }
            }
        }
    }
}
