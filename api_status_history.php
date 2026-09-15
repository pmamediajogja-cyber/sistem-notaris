<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

$conn=$koneksi; security_headers(); $user=require_tenant_user(); $tenantId=tenant_id_from_user($user); $action=$_GET['action']??'list';

if($action==='list'){
    $matterId=isset($_GET['matter_id'])&&is_numeric($_GET['matter_id'])?(int)$_GET['matter_id']:0;
    if($matterId<1) json_response(['status'=>'error','pesan'=>'ID perkara tidak valid'],422);
    $stmt=$conn->prepare('SELECT h.id,h.matter_id,h.from_status,h.to_status,h.note,h.changed_by,h.created_at,u.name AS changed_by_name FROM status_history h LEFT JOIN users u ON u.id=h.changed_by INNER JOIN matters m ON m.tenant_id=h.tenant_id AND m.id=h.matter_id WHERE h.tenant_id=? AND h.matter_id=? ORDER BY h.created_at ASC,h.id ASC');
    if(!$stmt) json_response(['status'=>'error','pesan'=>'Layanan riwayat tidak tersedia'],500);
    $stmt->bind_param('ii',$tenantId,$matterId); $stmt->execute(); $result=$stmt->get_result(); $rows=[];
    while($row=$result->fetch_assoc())$rows[]=$row; $stmt->close(); json_response($rows);
}

if($action==='change'){
    require_post(); require_csrf(); $data=json_decode(file_get_contents('php://input'),true);
    if(!is_array($data)) json_response(['status'=>'error','pesan'=>'Data status tidak valid'],422);
    $matterId=isset($data['matter_id'])&&is_numeric($data['matter_id'])?(int)$data['matter_id']:0; $to=trim((string)($data['to_status']??'')); $note=trim((string)($data['note']??''));
    if($matterId<1||$to===''||mb_strlen($to)>30||mb_strlen($note)>10000) json_response(['status'=>'error','pesan'=>'Data status tidak valid'],422);
    $conn->begin_transaction();
    try{
        $stmt=$conn->prepare('SELECT status FROM matters WHERE id=? AND tenant_id=? FOR UPDATE');
        if(!$stmt) throw new RuntimeException('prepare'); $stmt->bind_param('ii',$matterId,$tenantId); $stmt->execute(); $matter=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$matter){$conn->rollback();json_response(['status'=>'error','pesan'=>'Perkara tidak ditemukan'],404);}
        $from=(string)$matter['status'];
        if($from===$to){$conn->rollback();json_response(['status'=>'success','changed'=>false]);}
        $update=$conn->prepare('UPDATE matters SET status=?, closed_at=CASE WHEN ?=\'closed\' THEN COALESCE(closed_at,CURRENT_DATE) ELSE NULL END WHERE id=? AND tenant_id=?');
        if(!$update) throw new RuntimeException('prepare'); $update->bind_param('ssii',$to,$to,$matterId,$tenantId); $update->execute(); $update->close();
        $by=(int)$user['user_id']; $hist=$conn->prepare('INSERT INTO status_history (tenant_id,matter_id,from_status,to_status,note,changed_by) VALUES (?,?,?,NULLIF(?,\'\'),NULLIF(?,\'\'),?)');
        if(!$hist) throw new RuntimeException('prepare'); $hist->bind_param('iisssi',$tenantId,$matterId,$from,$to,$note,$by); $hist->execute(); $hist->close(); $conn->commit();
        audit_log($conn,'matter.status_change','status_history',(string)$matterId); json_response(['status'=>'success','changed'=>true]);
    }catch(Throwable $e){$conn->rollback();json_response(['status'=>'error','pesan'=>'Status perkara gagal diubah'],500);}
}
json_response(['status'=>'error','pesan'=>'Aksi tidak dikenal'],400);
