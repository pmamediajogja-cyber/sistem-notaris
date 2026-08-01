<?php
include 'koneksi.php';

// Menyamakan nama variabel koneksi agar bisa dipakai oleh kode di bawahnya
$conn = $koneksi;

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");

$action = $_GET['action'] ?? '';

// PING INTERCEPTOR SUPER RINGAN (Hanya memastikan server hidup)
if ($action == 'ping') {
    exit(json_encode(["status" => "online"]));
}

// 1. Membuat tabel otomatis
$sql_create = "CREATE TABLE IF NOT EXISTS tabel_pelacakan (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    no_berkas VARCHAR(100),
    jenis_proses VARCHAR(100),
    klien LONGTEXT,
    objek LONGTEXT,
    tgl_masuk VARCHAR(50),
    tgl_ttd VARCHAR(50),
    status VARCHAR(255) DEFAULT 'Pendaftaran Berkas',
    catatan TEXT,
    waktu_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($sql_create);

// 2. AUTO-UPGRADE: Menambahkan struktur pengaman data
$conn->query("ALTER TABLE tabel_pelacakan ADD COLUMN riwayat_status LONGTEXT AFTER catatan");
$conn->query("ALTER TABLE tabel_pelacakan MODIFY klien LONGTEXT");
$conn->query("ALTER TABLE tabel_pelacakan MODIFY objek LONGTEXT");

// INJEKSI PESSIMISTIC LOCKING
$conn->query("ALTER TABLE tabel_pelacakan ADD COLUMN locked_by VARCHAR(50) DEFAULT NULL");
$conn->query("ALTER TABLE tabel_pelacakan ADD COLUMN locked_time DATETIME DEFAULT NULL");

// LOGIKA PEMERIKSAAN GEMBOK (CHECK LOCK)
if ($action == 'check_lock') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = (int)$data['id'];
    $client_id = $conn->real_escape_string($data['client_id']);

    $res = $conn->query("SELECT locked_by, locked_time FROM tabel_pelacakan WHERE id=$id");
    $row = $res->fetch_assoc();

    // Jika digembok oleh komputer lain DAN waktu gembok belum kedaluwarsa (15 Menit)
    if ($row['locked_by'] && $row['locked_by'] !== $client_id && strtotime($row['locked_time']) > strtotime('-15 minutes')) {
        echo json_encode(["status" => "locked", "pesan" => "Berkas ini sedang dikerjakan/dibuka oleh Staf lain. Silakan tunggu beberapa saat."]);
    } else {
        // Pasang gembok untuk komputer ini
        $conn->query("UPDATE tabel_pelacakan SET locked_by='$client_id', locked_time=NOW() WHERE id=$id");
        echo json_encode(["status" => "available"]);
    }
}
// LOGIKA PELEPASAN GEMBOK (RELEASE LOCK)
elseif ($action == 'release_lock') {
    $data = json_decode(file_get_contents("php://input"), true);
    if(!$data) exit;
    $id = (int)$data['id'];
    $client_id = $conn->real_escape_string($data['client_id']);
    
    // Hanya komputer yang menggembok yang boleh melepasnya
    $conn->query("UPDATE tabel_pelacakan SET locked_by=NULL, locked_time=NULL WHERE id=$id AND locked_by='$client_id'");
    echo json_encode(["status" => "released"]);
}
// LOGIKA SIMPAN DATA
elseif ($action == 'save') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) exit(json_encode(["status" => "error"]));

    $id = $data['id'] ?? null;
    $no = $conn->real_escape_string($data['no_berkas']);
    $jenis = $conn->real_escape_string($data['jenis_proses']);
    $klien = $conn->real_escape_string($data['klien']); 
    $objek = $conn->real_escape_string($data['objek']); 
    $tgl_masuk = $conn->real_escape_string($data['tgl_masuk']);
    $tgl_ttd = $conn->real_escape_string($data['tgl_ttd']);
    $status = $conn->real_escape_string($data['status'] ?? 'Pendaftaran Berkas');
    $catatan = $conn->real_escape_string($data['catatan']);
    $riwayat = $conn->real_escape_string($data['riwayat_status']);

    if ($id) {
        // Simpan data sekaligus MENGHANCURKAN gembok karena pekerjaan selesai
        $sql = "UPDATE tabel_pelacakan SET no_berkas='$no', jenis_proses='$jenis', klien='$klien', objek='$objek', tgl_masuk='$tgl_masuk', tgl_ttd='$tgl_ttd', status='$status', catatan='$catatan', riwayat_status='$riwayat', locked_by=NULL, locked_time=NULL WHERE id=$id";
    } else {
        $sql = "INSERT INTO tabel_pelacakan (no_berkas, jenis_proses, klien, objek, tgl_masuk, tgl_ttd, status, catatan, riwayat_status) VALUES ('$no', '$jenis', '$klien', '$objek', '$tgl_masuk', '$tgl_ttd', '$status', '$catatan', '$riwayat')";
    }

    if ($conn->query($sql) === TRUE) echo json_encode(["status" => "success"]);
    else echo json_encode(["status" => "error", "pesan" => $conn->error]);
} 
elseif ($action == 'load') {
    $result = $conn->query("SELECT * FROM tabel_pelacakan ORDER BY waktu_update DESC");
    $arsip = [];
    while ($row = $result->fetch_assoc()) $arsip[] = $row;
    echo json_encode($arsip);
}
elseif ($action == 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($conn->query("DELETE FROM tabel_pelacakan WHERE id=$id")) echo json_encode(["status" => "success"]);
}

$conn->close();
?>