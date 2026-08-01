<?php
$host     = "localhost";
$username = "root";      // Sesuaikan dengan username MySQL Anda
$password = "";          // Sesuaikan dengan password MySQL Anda (kosongkan jika bawaan XAMPP)
$database = "notaris";   // Nama database Anda

$koneksi = mysqli_connect($host, $username, $password, $database);

// Periksa Koneksi
if (!$koneksi) {
    die("Koneksi ke database gagal: " . mysqli_connect_error());
}
?>