<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
$user = require_auth();
if (($user['role'] ?? '') !== 'SUPER_ADMIN') {
    http_response_code(403);
    exit('Akses ditolak. Khusus Platform Admin.');
}
require_once __DIR__ . '/../koneksi.php';
$stats = ['tenants'=>0,'active'=>0,'trial'=>0,'users'=>0];
foreach ([
    'tenants' => 'SELECT COUNT(*) total FROM tenants',
    'active' => "SELECT COUNT(*) total FROM tenants WHERE status='active'",
    'trial' => "SELECT COUNT(*) total FROM tenants WHERE status='trial'",
    'users' => 'SELECT COUNT(*) total FROM users'
] as $key => $sql) {
    $r = $koneksi->query($sql);
    if ($r) $stats[$key] = (int)$r->fetch_assoc()['total'];
}
$r = $koneksi->query('SELECT id,name,slug,status,plan,created_at FROM tenants ORDER BY id DESC LIMIT 100');
$tenants=[];
if ($r) while ($row=$r->fetch_assoc()) $tenants[]=$row;
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Platform Admin - Sistem Notaris</title><style>body{font-family:Arial,sans-serif;background:#f5f7fb;color:#172033;margin:0;padding:30px}.wrap{max-width:1100px;margin:auto}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:25px 0}.card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:20px}.num{font-size:30px;font-weight:700;margin-top:8px}.muted{color:#667085}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #eee}@media(max-width:700px){.grid{grid-template-columns:repeat(2,1fr)}}button{padding:8px 14px}</style></head><body><div class="wrap"><h1>Platform Admin</h1><p class="muted">Sistem Notaris SaaS - <?=h((string)$user['name'])?></p><div class="grid"><div class="card">Total Kantor<div class="num"><?=$stats['tenants']?></div></div><div class="card">Aktif<div class="num"><?=$stats['active']?></div></div><div class="card">Trial<div class="num"><?=$stats['trial']?></div></div><div class="card">Total User<div class="num"><?=$stats['users']?></div></div></div><div class="card"><h2>Daftar Kantor</h2><p class="muted">Platform Admin mengelola tenant tanpa menampilkan isi dokumen kantor.</p><table><tr><th>ID</th><th>Kantor</th><th>Status</th><th>Plan</th><th>Dibuat</th></tr><?php foreach($tenants as $t):?><tr><td><?=h((string)$t['id'])?></td><td><?=h($t['name'])?></td><td><?=h($t['status'])?></td><td><?=h($t['plan'])?></td><td><?=h($t['created_at'])?></td></tr><?php endforeach;?></table></div></div></body></html>