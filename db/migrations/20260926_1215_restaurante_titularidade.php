<?php
declare(strict_types=1);

return [
    'id' => '20260926_1215_restaurante_titularidade',
    'description' => 'Adiciona o documento protegido de titularidade do restaurante.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $exists = $db->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() "
            . "AND table_name = 'restaurantes' AND column_name = 'documento_titularidade_path'"
        );
        if ((int)$exists->fetchColumn() === 0) {
            $db->exec('ALTER TABLE restaurantes ADD COLUMN documento_titularidade_path VARCHAR(255) NULL AFTER logo');
        }
    },
];
