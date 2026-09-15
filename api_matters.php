<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

$conn = $koneksi;
security_headers();
$user = require_tenant_user();
$tenantId = tenant_id_from_user($user);
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $stmt = $conn->prepare('SELECT m.id, m.client_id, m.matter_code, m.title, m.service_type, m.deed_type, m.status, m.opened_at, m.closed_at, m.assigned_user_id, m.notes, m.created_at, m.updated_at, c.name AS client_name FROM matters m INNER JOIN clients c ON c.tenant_id = m.tenant_id AND c.id = m.client_id WHERE m.tenant_id = ? ORDER BY m.updated_at DESC LIMIT 500');
    if (!$stmt) json_response(['status'=>'error','pesan'=>'Layanan perkara tidak tersedia'],500);
    $stmt->bind_param('i',$tenantId); $stmt->execute(); $result=$stmt->get_result(); $rows=[];
    while($row=$result->fetch_assoc()) $rows[]=$row;
    $stmt->close(); json_response($rows);
}

if ($action === 'save') {
    require_post(); require_csrf();
    $data=json_decode(file_get_contents('php://input'),true);
    if(!is_array($data)) json_response(['status'=>'error','pesan'=>'Data perkara tidak valid'],422);
    $id=isset($data['id'])&&is_numeric($data['id'])?(int)$data['id']:0;
    $clientId=isset($data['client_id'])&&is_numeric($data['client_id'])?(int)$data['client_id']:0;
    $code=trim((string)($data['matter_code']??'')); $title=trim((string)($data['title']??''));
    $service=trim((string)($data['service_type']??'')); $deed=trim((string)($data['deed_type']??''));
    $status=trim((string)($data['status']??'open')); $opened=trim((string)($data['opened_at']??''));
    $closed=trim((string)($data['closed_at']??'')); $assigned=isset($data['assigned_user_id'])&&is_numeric($data['assigned_user_id'])?(int)$data['assigned_user_id']:0;
    $notes=trim((string)($data['notes']??''));
    if($clientId<1||$title===''||mb_strlen($code)>50||mb_strlen($title)>255||mb_strlen($service)>100||mb_strlen($deed)>100||mb_strlen($status)>30||mb_strlen($opened)>10||mb_strlen($closed)>10||mb_strlen($notes)>10000) json_response(['status'=>'error','pesan'=>'Data perkara tidak valid atau terlalu panjang'],422);

    $check=$conn->prepare('SELECT id FROM clients WHERE id=? AND tenant_id=? LIMIT 1');
    if(!$check) json_response(['status'=>'error','pesan'=>'Layanan perkara tidak tersedia'],500);
    $check->bind_param('ii',$clientId,$tenantId); $check->execute(); $exists=$check->get_result()->fetch_assoc(); $check->close();
    if(!$exists) json_response(['status'=>'error','pesan'=>'Klien tidak ditemukan'],404);

    $assignedValue=$assigned>0?$assigned:null; $openedValue=$opened!==''?$opened:null; $closedValue=$closed!==''?$closed:null;
    if($id>0){
        $stmt=$conn->prepare('UPDATE matters SET client_id=?, matter_code=NULLIF(?,\'\'), title=?, service_type=NULLIF(?,\'\'), deed_type=NULLIF(?,\'\'), status=?, opened_at=?, closed_at=?, assigned_user_id=?, notes=NULLIF(?,\'\') WHERE id=? AND tenant_id=?');
        if(!$stmt) json_response(['status'=>'error','pesan'=>'Layanan perkara tidak tersedia'],500);
        $stmt->bind_param('issssssisis', $clientId,$code,$title,$service,$deed,$status,$openedValue,$closedValue,$assignedValue,$notes,$id,$tenantId);
        $stmt->execute(); $stmt->close(); audit_log($conn,'matter.update','matters',(string)$id); json_response(['status'=>'success','id'=>$id]);
    }
    $createdBy=(int)$user['user_id'];
    $stmt=$conn->prepare('INSERT INTO matters (tenant_id,client_id,matter_code,title,service_type,deed_type,status,opened_at,closed_at,assigned_user_id,notes,created_by) VALUES (?, ?, NULLIF(?,\'\'), ?, NULLIF(?,\'\'), NULLIF(?,\'\'), ?, ?, ?, ?, NULLIF(?,\'\'), ?)');
    if(!$stmt) json_response(['status'=>'error','pesan'=>'Layanan perkara tidak tersedia'],500);
    $stmt->bind_param('iisssssssisi',$tenantId,$clientId,$code,$title,$service,$deed,$status,$openedValue,$closedValue,$assignedValue,$notes,$createdBy);
    $stmt->execute(); $newId=$conn->insert_id; $stmt->close(); audit_log($conn,'matter.create','matters',(string)$newId); json_response(['status'=>'success','id'=>$newId]);
}

if($action==='delete'){
    require_post(); require_csrf(); $id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
    if(!$id||$id<1) json_response(['status'=>'error','pesan'=>'ID perkara tidak valid'],422);
    $stmt=$conn->prepare('UPDATE matters SET status=\'closed\', closed_at=COALESCE(closed_at,CURRENT_DATE) WHERE id=? AND tenant_id=?');
    if(!$stmt) json_response(['status'=>'error','pesan'=>'Layanan perkara tidak tersedia'],500);
    $stmt->bind_param('ii',$id,$tenantId); $stmt->execute(); $stmt->close(); audit_log($conn,'matter.close','matters',(string)$id); json_response(['status'=>'success']);
}
json_response(['status'=>'error','pesan'=>'Aksi tidak dikenal'],400);
