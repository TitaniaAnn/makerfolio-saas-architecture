<?php

declare(strict_types=1);

use MakerfolioArch\TenantDomain;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §6 — the domain state diagram in
 * docs/03-routing-and-tls.md is the TenantDomain edge map, exactly.
 * Parses the mermaid stateDiagram block and compares edge sets both
 * ways, so an edge added to either side without the other fails here.
 */
final class DomainDiagramTest extends TestCase
{
    private const DOC = __DIR__ . '/../docs/03-routing-and-tls.md';

    /** @return list<string> "FROM->TO" for every non-[*] edge in the diagram */
    private static function diagramEdges(): array
    {
        $doc = file_get_contents(self::DOC);
        self::assertNotFalse($doc);
        self::assertSame(
            1,
            preg_match('/```mermaid\s*\nstateDiagram-v2\n(.*?)```/s', $doc, $m),
            'expected exactly one stateDiagram-v2 block in docs/03'
        );

        $edges = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^\s*([A-Z_]+)\s*-->\s*([A-Z_]+)\b/', $line, $e)) {
                $edges[] = "{$e[1]}->{$e[2]}";
            }
        }

        return $edges;
    }

    /** @return list<string> */
    private static function mapEdges(): array
    {
        $edges = [];
        foreach (TenantDomain::transitions() as $from => $tos) {
            foreach ($tos as $to) {
                $edges[] = "{$from}->{$to}";
            }
        }

        return $edges;
    }

    public function test_diagram_has_no_duplicate_edges(): void
    {
        $edges = self::diagramEdges();
        self::assertSame(count($edges), count(array_unique($edges)));
    }

    public function test_diagram_matches_transition_map_exactly(): void
    {
        $diagram = self::diagramEdges();
        $map = self::mapEdges();
        sort($diagram);
        sort($map);

        self::assertSame($map, $diagram, 'docs/03 diagram and TenantDomain::TRANSITIONS have drifted');
    }
}
