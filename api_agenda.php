<?php
include 'koneksi.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");

// Buat struktur Key-Value sederhana untuk kalender
$conn->query("CREATE TABLE IF NOT EXISTS tabel_agenda (
    tanggal VARCHAR(20) PRIMARY KEY,
    deskripsi TEXT
)");

$action = $_GET['action'] ?? '';

if ($action == 'load') {
    $result = $conn->query("SELECT * FROM tabel_agenda");
    $agenda = [];
    while ($row = $result->fetch_assoc()) {
        $agenda[$row['tanggal']] = $row['deskripsi'];
    }
    echo json_encode($agenda);
} 
elseif ($action == 'save') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) exit(json_encode(["status" => "error"]));
    
    // Bersihkan tabel sebelum menyimpan array baru (Metode sinkronisasi total)
    $conn->query("TRUNCATE TABLE tabel_agenda");
    
    $stmt = $conn->prepare("INSERT INTO tabel_agenda (tanggal, deskripsi) VALUES (?, ?)");
    foreach ($data as $tgl => $deskripsi) {
        $stmt->bind_param("ss", $tgl, $deskripsi);
        $stmt->execute();
    }
    $stmt->close();
    
    echo json_encode(["status" => "success"]);
}

$conn->close();
?>