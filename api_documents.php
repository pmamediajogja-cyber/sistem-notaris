<?php
declare(strict_types=1);

require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/koneksi.php';

$conn=$koneksi; security_headers(); $user=require_tenant_user(); $tenantId=tenant_id_from_user($user); $action=$_GET['action']??'list';

function private_storage_root(): string {
    $root=rtrim((string)env_value('STORAGE_ROOT',''),DIRECTORY_SEPARATOR);
    if($root==='') json_response(['status'=>'error','pesan'=>'Penyimpanan dokumen belum dikonfigurasi'],503);
    return $root;
}

if($action==='list'){
    $matterId=isset($_GET['matter_id'])&&is_numeric($_GET['matter_id'])?(int)$_GET['matter_id']:0;
    if($matterId<1) json_response(['status'=>'error','pesan'=>'ID perkara tidak valid'],422);
    $stmt=$conn->prepare('SELECT d.id,d.matter_id,d.original_name,d.mime_type,d.size_bytes,d.sha256,d.status,d.uploaded_by,d.created_at,u.name AS uploaded_by_name FROM documents d LEFT JOIN users u ON u.id=d.uploaded_by AND u.tenant_id=d.tenant_id INNER JOIN matters m ON m.tenant_id=d.tenant_id AND m.id=d.matter_id WHERE d.tenant_id=? AND d.matter_id=? AND d.deleted_at IS NULL ORDER BY d.created_at DESC');
    if(!$stmt) json_response(['status'=>'error','pesan'=>'Layanan dokumen tidak tersedia'],500);
    $stmt->bind_param('ii',$tenantId,$matterId); $stmt->execute(); $result=$stmt->get_result(); $rows=[];
    while($row=$result->fetch_assoc())$rows[]=$row; $stmt->close(); json_response($rows);
}

if($action==='upload'){
    require_post(); require_csrf();
    $matterId=filter_input(INPUT_POST,'matter_id',FILTER_VALIDATE_INT);
    if(!$matterId||$matterId<1||!isset($_FILES['file'])) json_response(['status'=>'error','pesan'=>'Perkara dan file wajib diisi'],422);
    $check=$conn->prepare('SELECT id FROM matters WHERE id=? AND tenant_id=? LIMIT 1');
    if(!$check) json_response(['status'=>'error','pesan'=>'Layanan dokumen tidak tersedia'],500);
    $check->bind_param('ii',$matterId,$tenantId); $check->execute(); $exists=$check->get_result()->fetch_assoc(); $check->close();
    if(!$exists) json_response(['status'=>'error','pesan'=>'Perkara tidak ditemukan'],404);

    $file=$_FILES['file'];
    if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) json_response(['status'=>'error','pesan'=>'Upload file gagal'],422);
    $size=(int)($file['size']??0); if($size<1||$size>15728640) json_response(['status'=>'error','pesan'=>'Ukuran file maksimal 15 MB'],422);
    $tmp=(string)($file['tmp_name']??''); if($tmp===''||!is_uploaded_file($tmp)) json_response(['status'=>'error','pesan'=>'File upload tidak valid'],422);
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=(string)$finfo->file($tmp);
    $allowed=['application/pdf'=>'pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/msword'=>'doc','image/jpeg'=>'jpg','image/png'=>'png'];
    if(!isset($allowed[$mime])) json_response(['status'=>'error','pesan'=>'Tipe file tidak diizinkan'],415);
    $original=basename((string)($file['name']??'dokumen')); if(mb_strlen($original)>255) $original=mb_substr($original,0,255);
    $uuid=bin2hex(random_bytes(16)); $relative='tenants/'.$tenantId.'/matters/'.$matterId.'/'.$uuid.'.'.$allowed[$mime]; $root=private_storage_root(); $destination=$root.DIRECTORY_SEPARATOR.$relative;
    $dir=dirname($destination); if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) json_response(['status'=>'error','pesan'=>'Penyimpanan dokumen tidak tersedia'],500);
    if(!move_uploaded_file($tmp,$destination)){@unlink($destination);json_response(['status'=>'error','pesan'=>'File gagal disimpan'],500);}
    @chmod($destination,0600); $sha=hash_file('sha256',$destination); if($sha===false){@unlink($destination);json_response(['status'=>'error','pesan'=>'Hash file gagal dibuat'],500);}

    $docId=0;
    $conn->begin_transaction();
    try {
        $stmt=$conn->prepare('INSERT INTO documents (tenant_id,matter_id,original_name,storage_key,mime_type,size_bytes,sha256,uploaded_by) VALUES (?,?,?,?,?,?,?,?)');
        if(!$stmt) throw new RuntimeException('document insert prepare failed');
        $uploadedBy=(int)$user['user_id'];
        $stmt->bind_param('iisssisi',$tenantId,$matterId,$original,$relative,$mime,$size,$sha,$uploadedBy);
        if(!$stmt->execute()) { $stmt->close(); throw new RuntimeException('document insert failed'); }
        $docId=$conn->insert_id; $stmt->close();

        $store=$conn->prepare("INSERT INTO document_storage (tenant_id,document_id,storage_key,provider) VALUES (?,?,?,'local')");
        if(!$store) throw new RuntimeException('storage insert prepare failed');
        $store->bind_param('iis',$tenantId,$docId,$relative);
        if(!$store->execute()) { $store->close(); throw new RuntimeException('storage insert failed'); }
        $store->close();
        $conn->commit();
    } catch(Throwable $e) {
        $conn->rollback();
        @unlink($destination);
        json_response(['status'=>'error','pesan'=>'Dokumen gagal disimpan'],500);
    }

    audit_log($conn,'document.upload','documents',(string)$docId); json_response(['status'=>'success','id'=>$docId]);
}

if($action==='delete'){
    require_post(); require_csrf(); $id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
    if(!$id||$id<1) json_response(['status'=>'error','pesan'=>'ID dokumen tidak valid'],422);
    $stmt=$conn->prepare('UPDATE documents SET status=\'deleted\', deleted_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    if(!$stmt) json_response(['status'=>'error','pesan'=>'Layanan dokumen tidak tersedia'],500);
    $stmt->bind_param('ii',$id,$tenantId); $stmt->execute(); $affected=$stmt->affected_rows; $stmt->close();
    if($affected!==1) json_response(['status'=>'error','pesan'=>'Dokumen tidak ditemukan'],404);
    audit_log($conn,'document.delete','documents',(string)$id); json_response(['status'=>'success']);
}
json_response(['status'=>'error','pesan'=>'Aksi tidak dikenal'],400);
