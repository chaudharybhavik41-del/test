
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class BomService
{
    public function __construct(private PDO $pdo) {}

    public function createVersion(int $parentItemId, string $versionCode, ?string $effFrom=null, ?string $effTo=null, ?string $notes=null): int {
        if ($parentItemId<=0 || $versionCode==='') throw new RuntimeException("parent_item_id & version_code required");
        $st=$this->pdo->prepare("INSERT INTO bom_versions (parent_item_id,version_code,effective_from,effective_to,notes) VALUES (?,?,?,?,?)");
        $st->execute([$parentItemId,$versionCode,$effFrom,$effTo,$notes]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addComponent(int $versionId, int $componentItemId, float $qtyPerParent, float $scrapPct=0.0, bool $isPhantom=false, bool $isRemnant=false, int $lineNo=10, ?string $remarks=null): int {
        if ($versionId<=0 || $componentItemId<=0 || $qtyPerParent<=0) throw new RuntimeException("version_id, component_item_id, qty_per_parent required");
        $st=$this->pdo->prepare("INSERT INTO bom_components (version_id,line_no,component_item_id,qty_per_parent,scrap_pct,is_phantom,is_remnant_return,remarks) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$versionId,$lineNo,$componentItemId,$qtyPerParent,$scrapPct,$isPhantom?1:0,$isRemnant?1:0,$remarks]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Explode to a flat list aggregated by leaf item. Handles scrap% and phantom sub-assemblies. */
    public function explode(int $parentItemId, float $parentQty, ?string $asOfDate=null): array {
        if ($parentQty<=0) throw new RuntimeException("parent qty must be > 0");
        $seen = []; $flat = [];
        $this->explodeInner($parentItemId, $parentQty, $asOfDate, $seen, $flat, 0, []);
        // aggregate same items
        $agg=[];
        foreach($flat as $row){
            $k = (string)$row['item_id'];
            if(!isset($agg[$k])) $agg[$k] = ['item_id'=>$row['item_id'],'qty'=>0.0];
            $agg[$k]['qty'] += $row['qty'];
        }
        // round
        foreach($agg as &$a){ $a['qty'] = round($a['qty'], 6); }
        return array_values($agg);
    }

    /** Return a nested tree for UI */
    public function tree(int $parentItemId, float $parentQty=1.0, ?string $asOfDate=null): array {
        $seen=[]; return $this->node($parentItemId, $parentQty, $asOfDate, $seen, 0);
    }

    // ------------------- internals -------------------
    private function node(int $itemId, float $qty, ?string $asOfDate, array &$seen, int $depth): array {
        if(isset($seen[$itemId])) throw new RuntimeException("Cycle detected at item ".$itemId);
        $seen[$itemId]=true;
        $comps = $this->componentsFor($itemId, $asOfDate);
        $children = [];
        foreach($comps as $c){
            $req = $qty * (float)$c['qty_per_parent'] * (1.0 + ((float)$c['scrap_pct'] / 100.0));
            if ((int)$c['is_phantom'] === 1) {
                $children[] = $this->node((int)$c['component_item_id'], $req, $asOfDate, $seen, $depth+1);
            } else {
                $children[] = ['item_id'=>(int)$c['component_item_id'],'qty'=>round($req,6),'children'=>[],'depth'=>$depth+1,'is_leaf'=>true,'is_remnant'=>(int)$c['is_remnant_return']===1];
            }
        }
        unset($seen[$itemId]);
        return ['item_id'=>$itemId,'qty'=>round($qty,6),'children'=>$children,'depth'=>$depth,'is_leaf'=>empty($children)];
    }

    private function explodeInner(int $itemId, float $qty, ?string $asOfDate, array &$seen, array &$flat, int $depth, array $path): void {
        if(isset($seen[$itemId])) throw new RuntimeException("Cycle detected at item ".$itemId);
        $seen[$itemId]=true;
        $comps = $this->componentsFor($itemId, $asOfDate);
        if (!$comps) {
            $flat[] = ['item_id'=>$itemId,'qty'=>$qty,'path'=>$path];
            unset($seen[$itemId]); return;
        }
        foreach($comps as $c){
            $req = $qty * (float)$c['qty_per_parent'] * (1.0 + ((float)$c['scrap_pct'] / 100.0));
            $newPath = array_merge($path, [ ['parent'=>$itemId,'comp'=>(int)$c['component_item_id']] ]);
            if ((int)$c['is_phantom'] === 1) {
                $this->explodeInner((int)$c['component_item_id'], $req, $asOfDate, $seen, $flat, $depth+1, $newPath);
            } else {
                // leaf
                $flat[] = ['item_id'=>(int)$c['component_item_id'],'qty'=>$req,'path'=>$newPath];
            }
        }
        unset($seen[$itemId]);
    }

    /** returns rows of bom_components for active version of item as-of date */
    private function componentsFor(int $parentItemId, ?string $asOfDate): array {
        $date = $asOfDate ?: date('Y-m-d');
        $st=$this->pdo->prepare("
          SELECT bc.* FROM bom_components bc
          JOIN bom_versions bv ON bv.id = bc.version_id
          WHERE bv.parent_item_id=? AND bv.is_active=1
            AND (bv.effective_from IS NULL OR bv.effective_from<=?)
            AND (bv.effective_to   IS NULL OR bv.effective_to  >=?)
          ORDER BY bc.line_no, bc.id
        ");
        $st->execute([$parentItemId,$date,$date]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
