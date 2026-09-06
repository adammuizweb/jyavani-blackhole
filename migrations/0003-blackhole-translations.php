<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $id = [
        'Lifecycle archive' => 'Arsip siklus hidup',
        'Blackhole Archive' => 'Arsip Blackhole',
        'Verified Media and File snapshots captured before Core lifecycle mutations.' => 'Snapshot Media dan File terverifikasi yang direkam sebelum mutasi siklus hidup Core.',
        'events' => 'peristiwa',
        'Fail-closed protection is active.' => 'Perlindungan fail-closed aktif.',
        'Permanent purge is blocked when a required artifact cannot be archived.' => 'Penghapusan permanen diblokir ketika artefak yang diperlukan tidak dapat diarsipkan.',
        'Resource' => 'Sumber daya', 'All resources' => 'Semua sumber daya', 'Operation' => 'Operasi', 'All operations' => 'Semua operasi',
        'Archive state' => 'Status arsip', 'All states' => 'Semua status', 'Item ID' => 'ID item', 'Filter' => 'Filter',
        'No archived events' => 'Belum ada peristiwa yang diarsipkan',
        'Lifecycle events will appear after Media or File mutations.' => 'Peristiwa siklus hidup akan muncul setelah mutasi Media atau File.',
        'Occurred' => 'Terjadi', 'Items' => 'Item', 'Artifacts' => 'Artefak', 'Actor' => 'Pelaku', 'verified' => 'terverifikasi',
        'unavailable' => 'tidak tersedia', 'System' => 'Sistem', 'Archive pages' => 'Halaman arsip', 'Previous' => 'Sebelumnya', 'Next' => 'Berikutnya',
        'Page %d of %d' => 'Halaman %d dari %d', 'Archive' => 'Arsip', 'Source' => 'Sumber', 'Bulk' => 'Massal', 'Yes' => 'Ya', 'No' => 'Tidak',
        'Restore is not available yet.' => 'Pemulihan belum tersedia.',
        'Core 2.3.106 does not provide a validated post-purge import API. Archived data and bytes are retained for a future safe restore workflow.' => 'Core 2.3.106 belum menyediakan API impor pasca-penghapusan yang tervalidasi. Data dan byte arsip dipertahankan untuk alur pemulihan aman di masa mendatang.',
        'Item #%d' => 'Item #%d', 'Before snapshot' => 'Snapshot sebelum', 'After snapshot' => 'Snapshot sesudah',
        'No archived bytes' => 'Tidak ada byte yang diarsipkan', 'Event result' => 'Hasil peristiwa', 'Result' => 'Hasil', 'Warnings' => 'Peringatan',
        'Blackhole archive storage is unavailable.' => 'Penyimpanan arsip Blackhole tidak tersedia.',
        'Media' => 'Media', 'File' => 'Berkas', 'Trash' => 'Sampah', 'Restore' => 'Pulihkan', 'Purge' => 'Hapus permanen',
        'Complete' => 'Selesai', 'Pending' => 'Menunggu', 'Not applicable' => 'Tidak berlaku', 'Missing' => 'Hilang', 'Unmanaged' => 'Tidak dikelola',
        'Captured' => 'Direkam', 'Ready' => 'Siap',
    ];
    $de = [
        'Lifecycle archive' => 'Lebenszyklusarchiv', 'Blackhole Archive' => 'Blackhole-Archiv', 'events' => 'Ereignisse',
        'Resource' => 'Ressource', 'All resources' => 'Alle Ressourcen', 'Operation' => 'Vorgang', 'All operations' => 'Alle Vorgange',
        'Archive state' => 'Archivstatus', 'All states' => 'Alle Status', 'Item ID' => 'Element-ID', 'Filter' => 'Filtern',
        'Occurred' => 'Zeitpunkt', 'Items' => 'Elemente', 'Artifacts' => 'Artefakte', 'Actor' => 'Akteur', 'System' => 'System',
        'Previous' => 'Zuruck', 'Next' => 'Weiter', 'Page %d of %d' => 'Seite %d von %d', 'Archive' => 'Archiv', 'Source' => 'Quelle',
        'Bulk' => 'Stapel', 'Yes' => 'Ja', 'No' => 'Nein', 'Restore is not available yet.' => 'Wiederherstellung ist noch nicht verfugbar.',
        'Before snapshot' => 'Vorheriger Snapshot', 'After snapshot' => 'Nachheriger Snapshot', 'No archived bytes' => 'Keine archivierten Bytes',
        'Event result' => 'Ereignisergebnis', 'Result' => 'Ergebnis', 'Warnings' => 'Warnungen', 'Media' => 'Medien', 'File' => 'Datei',
        'Trash' => 'Papierkorb', 'Restore' => 'Wiederherstellen', 'Purge' => 'Endgultig loschen', 'Complete' => 'Vollstandig',
        'Pending' => 'Ausstehend', 'Not applicable' => 'Nicht zutreffend', 'Missing' => 'Fehlt', 'Unmanaged' => 'Nicht verwaltet',
        'Captured' => 'Erfasst', 'Ready' => 'Bereit',
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO ui_translations (scope, source, value, locale) VALUES (?,?,?,?)');
    foreach (['id' => $id, 'de' => $de] as $locale => $translations) {
        foreach ($translations as $source => $value) $insert->execute(['jyavani-blackhole', $source, $value, $locale]);
    }
};
