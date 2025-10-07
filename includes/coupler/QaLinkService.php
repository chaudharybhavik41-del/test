
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class QaLinkService
{
    public function __construct(private PDO $pdo) {}

    public function link(int $attachmentId, string $docType='heat_cert', ?int $lotId=null, ?int $grnLineId=null, ?int $itemId=null, ?string $notes=null): int {
        if ($attachmentId<=0) throw new RuntimeException("attachment_id required");
        $st=$this->pdo->prepare("INSERT INTO qa_doc_links (attachment_id, doc_type, lot_id, grn_line_id, item_id, notes) VALUES (?,?,?,?,?,?)");
        $st->execute([$attachmentId, $docType, $lotId, $grnLineId, $itemId, $notes]);
        return (int)$this->pdo->lastInsertId();
    }

    public function unlink(int $linkId): bool {
        $st=$this->pdo->prepare("DELETE FROM qa_doc_links WHERE id=?");
        return $st->execute([$linkId]);
    }

    public function list(?int $lotId=null, ?int $grnLineId=null, ?int $attachmentId=null): array {
        $sql="SELECT * FROM qa_doc_links WHERE 1=1"; $p=[];
        if($lotId){ $sql.=" AND lot_id=?"; $p[]=$lotId; }
        if($grnLineId){ $sql.=" AND grn_line_id=?"; $p[]=$grnLineId; }
        if($attachmentId){ $sql.=" AND attachment_id=?"; $p[]=$attachmentId; }
        $sql.=" ORDER BY id DESC LIMIT 500";
        $st=$this->pdo->prepare($sql); $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
}
