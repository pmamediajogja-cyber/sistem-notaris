<?php
include 'koneksi.php';

// Menyamakan nama variabel koneksi agar bisa dipakai oleh kode di bawahnya
$conn = $koneksi;

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");



// Fitur Cerdas: Membuat Tabel Otomatis jika belum ada
$sql_create = "CREATE TABLE IF NOT EXISTS tabel_invoice (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    no_invoice VARCHAR(100),
    nama_pihak VARCHAR(255),
    data_json LONGTEXT,
    waktu_simpan TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($sql_create);

$action = $_GET['action'] ?? '';

// LOGIKA 1: MENYIMPAN DATA (CREATE / UPDATE)
if ($action == 'save') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        echo json_encode(["status" => "error", "pesan" => "Tidak ada data yang dikirim"]);
        exit;
    }

    $id = $data['id'] ?? null;
    $no_invoice = $conn->real_escape_string($data['no_invoice']);
    
    // Perbaikan nama pihak untuk mode umum (jika klien 2 kosong)
    $p1 = trim($data['penjual']);
    $p2 = trim($data['pembeli']);
    $nama_pihak_raw = $p2 ? "$p1 & $p2" : $p1;
    $nama_pihak = $conn->real_escape_string($nama_pihak_raw);
    
    $data_json = $conn->real_escape_string(json_encode($data));

    // Jika ID sudah ada, timpa data lama (Update). Jika belum, buat baru (Insert).
    if ($id) {
        $sql = "UPDATE tabel_invoice SET no_invoice='$no_invoice', nama_pihak='$nama_pihak', data_json='$data_json' WHERE id=$id";
    } else {
        $sql = "INSERT INTO tabel_invoice (no_invoice, nama_pihak, data_json) VALUES ('$no_invoice', '$nama_pihak', '$data_json')";
    }

    if ($conn->query($sql) === TRUE) {
        $insert_id = $id ? $id : $conn->insert_id;
        echo json_encode(["status" => "success", "id" => $insert_id]);
    } else {
        echo json_encode(["status" => "error", "pesan" => $conn->error]);
    }
} 
// LOGIKA 2: MEMANGGIL DATA KE LAYAR (READ)
elseif ($action == 'load') {
    $result = $conn->query("SELECT id, no_invoice, nama_pihak, data_json FROM tabel_invoice ORDER BY id DESC");
    $arsip = [];
    while ($row = $result->fetch_assoc()) {
        // Memecah kembali string DNA menjadi format JSON asli
        $arsip[] = [
            "id" => $row['id'],
            "no_invoice" => $row['no_invoice'],
            "nama_pihak" => $row['nama_pihak'],
            "data_json" => json_decode($row['data_json'], true)
        ];
    }
    echo json_encode($arsip);
}
// LOGIKA 3: MENGHAPUS DATA PERMANEN (DELETE)
elseif ($action == 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($conn->query("DELETE FROM tabel_invoice WHERE id=$id")) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error"]);
    }
}

$conn->close();
?>