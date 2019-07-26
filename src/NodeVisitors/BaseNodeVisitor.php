<?php

namespace Waavi\LaravelOpenApiGenerator\NodeVisitors;

use PhpParser\NodeDumper;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FirstFindingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

class BaseNodeVisitor extends NodeVisitorAbstract
{
    protected function dumpNode($node)
    {
        echo "=== CODE =======================================================\n";
        echo (new Standard)->prettyPrint([$node]) . "\n";
        echo "=== NODE =======================================================\n";
        echo (new NodeDumper)->dump($node)."\n";
        echo "----------------------------------------------------------------\n";
        echo "\n";
    }

    protected function traverse($nodes, $nodeVisitor)
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor($nodeVisitor);
        $traverser->traverse(is_array($nodes) ? $nodes : [$nodes]);
    }

    protected function findFirst($node, $callback)
    {
        $this->traverse($node, $findFirst = new FirstFindingVisitor($callback));

        return $findFirst->getFoundNode() ?: null;
    }
}
