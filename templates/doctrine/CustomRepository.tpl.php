<?= "<?php\n" ?>

namespace <?= $namespace ?>;

<?= $use_statements ?>

class <?= $class_name ?> extends AbstractRepository <?= $implements_repossitory_interface ?>
{
    public function findById(int $id): ?int
    {
        <?= $repository_name ?>Repository = $this->entityManager->getRepository(<?= $entity_name ?>::class);

        return <?= $repository_name ?>Repository->findOneBy(['id' => $id]);
    }

}
