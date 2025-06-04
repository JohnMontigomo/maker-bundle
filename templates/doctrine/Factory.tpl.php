<?= "<?php\n" ?>

namespace <?= $namespace ?>;

/**
* @template T
*/
class <?= $class_name ?>
{
    /**
    * @param class-string $class
    * @return T
    */
    public function make(string $class, ...$parameters)
    {
        return new $class(...$parameters);
    }
}
