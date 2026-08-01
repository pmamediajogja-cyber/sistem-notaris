<?php
$host     = "localhost";
$username = "root";      
$password = "";          
$database = "notaris_dian"; // Murni mengarah ke database VIP

$koneksi = mysqli_connect($host, $username, $password, $database);
if (!$koneksi) {
    die("Koneksi ke database VIP gagal: " . mysqli_connect_error());
}
?>