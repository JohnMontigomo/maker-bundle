<?= "<?php\n" ?>

namespace <?= $namespace ?>;

<?= $use_statements ?>

class <?= $class_name ?>
{
<?php if ($use_statements): ?>
    public function __construct(
    private readonly  <?= $entity_name ?>RepositoryInterface <?= $repository_name ?>Repository,
    <?php if ($common_factory): ?>
    private readonly  <?= $common_factory ?> $commonFactory,        
    <?php endif ?>
    ) {
    }

<?php endif ?>
}
