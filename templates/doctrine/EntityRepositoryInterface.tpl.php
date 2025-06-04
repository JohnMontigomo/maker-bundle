<?= "<?php\n"; ?>

namespace <?= $namespace; ?>;

interface EntityRepositoryInterface
{
    public function findById(int $id): ?int;
}
