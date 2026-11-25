<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CleanTempUploads extends BaseCommand
{
    protected $group       = 'Maintenance';
    protected $name        = 'clean:temp';
    protected $description = 'Menghapus semua file di folder uploads/temp yang lebih dari 24 jam';

    public function run(array $params)
    {
        $tempPath = WRITEPATH . 'uploads/temp/';

        if (!is_dir($tempPath)) {
            CLI::write('Folder temp tidak ditemukan.', 'yellow');
            return;
        }

        $files = glob($tempPath . '*');
        $now = time();
        $count = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                // Cek jika umur file lebih dari 24 jam (86400 detik)
                if ($now - filemtime($file) >= 86400) {
                    unlink($file);
                    $count++;
                }
            }
        }

        CLI::write("Berhasil menghapus {$count} file temp yang usang.", 'green');
    }
}
