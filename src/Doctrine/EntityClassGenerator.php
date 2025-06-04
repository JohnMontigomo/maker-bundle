<?php

/*
 * This file is part of the Symfony MakerBundle package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MakerBundle\Doctrine;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PhpParser\Builder\Class_;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\Maker\Common\EntityIdTypeEnum;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Util\ClassNameDetails;
use Symfony\Bundle\MakerBundle\Util\UseStatementGenerator;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\Attribute\Broadcast;

/**
 * @internal
 */
final class EntityClassGenerator
{
    public function __construct(
        private Generator $generator,
        private DoctrineHelper $doctrineHelper,
    ) {
    }

    public function generateEntityClass(ClassNameDetails $entityClassDetails, bool $apiResource, bool $withPasswordUpgrade = false, bool $generateRepositoryClass = true, bool $broadcast = false, EntityIdTypeEnum $useUuidIdentifier = EntityIdTypeEnum::INT): string
    {
        if ($this->generator->commonFactory) {
            $commonFactoryClassDetails = $this->generator->createClassNameDetails(
                'CommonFactory',
                $this->generator->commonFactory,
                ''
            );
        }
        
        $repoClassDetails = $this->generator->createClassNameDetails(
            $entityClassDetails->getRelativeName(),
            $this->generator->repository,
            'Repository'
        );

        $serviceClassDetails = $this->generator->createClassNameDetails(
            $entityClassDetails->getRelativeName(),
            $this->generator->service,
            'Service'
        );

        $entityInterface = null;
        if ($this->generator->abstarctRepository) {
            $abstarctRepositoryClassDetails = $this->generator->createClassNameDetails(
                'Abstract',
                $this->generator->repository,
                'Repository'
            );

            $entityInterfaceClassDetails = $this->generator->createClassNameDetails(
                'Entity',
                $this->generator->entityInterface,
                'Interface'
            );

            $entityInterface = 'EntityInterface';
        }

        if ($this->generator->entityRepositoryInterface) {
            $entityRepositoryInterfaceClassDetails = $this->generator->createClassNameDetails(
                $entityClassDetails->getRelativeName(),
                $this->generator->entityRepositoryInterface,
                'RepositoryInterface'
            );
        }

        $tableName = $this->doctrineHelper->getPotentialTableName($entityClassDetails->getFullName());

        $useStatements = new UseStatementGenerator([
            $repoClassDetails->getFullName(),
            ['Doctrine\\ORM\\Mapping' => 'ORM'],
        ]);

        if ($broadcast) {
            $useStatements->addUseStatement(Broadcast::class);
        }

        if ($apiResource) {
            $useStatements->addUseStatement(ApiResource::class);
        }

        if (EntityIdTypeEnum::UUID === $useUuidIdentifier) {
            $useStatements->addUseStatement([
                Uuid::class,
                UuidType::class,
            ]);
        }

        if (EntityIdTypeEnum::ULID === $useUuidIdentifier) {
            $useStatements->addUseStatement([
                Ulid::class,
                UlidType::class,
            ]);
        }

        if ($this->generator->abstarctRepository) {
            $useStatements->addUseStatement([
                $entityInterfaceClassDetails->getFullName()
            ]);
        }

        $entityPath = $this->generator->generateClass(
            $entityClassDetails->getFullName(),
            'doctrine/Entity.tpl.php',
            [
                'use_statements' => $useStatements,
                'repository_class_name' => $repoClassDetails->getShortName(),
                'api_resource' => $apiResource,
                'broadcast' => $broadcast,
                'should_escape_table_name' => $this->doctrineHelper->isKeyword($tableName),
                'table_name' => $tableName,
                'id_type' => $useUuidIdentifier,
                'entity_interface' => $entityInterface,
            ]
        );

        if ($this->generator->commonFactory && !class_exists($commonFactoryClassDetails->getFullName())) {
            $this->generator->generateClass(
                $commonFactoryClassDetails->getFullName(),
                'doctrine/Factory.tpl.php',
            );
        }

        if ($this->generator->abstarctRepository
            && !class_exists($entityInterfaceClassDetails->getFullName())
            && !class_exists($abstarctRepositoryClassDetails->getFullName())
        ) {
            $this->generator->generateClass(
                $entityInterfaceClassDetails->getFullName(),
                'doctrine/EntityInterface.tpl.php',
            );

            $this->generator->generateClass(
                $abstarctRepositoryClassDetails->getFullName(),
                'doctrine/AbstractRepository.tpl.php',
                ['use_statements' => new UseStatementGenerator([$entityInterfaceClassDetails->getFullName()])]
            );
        }

        if ($this->generator->entityRepositoryInterface) {
            $this->generator->generateClass(
                $entityRepositoryInterfaceClassDetails->getFullName(),
                'doctrine/EntityRepositoryInterface.tpl.php',
            );
        }

        if ($generateRepositoryClass) {
            if (!$this->generator->abstarctRepository) {
                $this->generateRepositoryClass(
                    $repoClassDetails->getFullName(),
                    $entityClassDetails->getFullName(),
                    $withPasswordUpgrade,
                    true,
                );
            }

            if ($this->generator->abstarctRepository) {
                $useStatementsCustomRepository = new UseStatementGenerator([
                    $entityClassDetails->getFullName(),
                ]);

                if ($this->generator->entityRepositoryInterface) {
                    $useStatementsCustomRepository->addUseStatement($entityRepositoryInterfaceClassDetails->getFullName());
                }

                $this->generator->generateClass(
                    $repoClassDetails->getFullName(),
                    'doctrine/CustomRepository.tpl.php',
                    [
                        'use_statements' => $useStatementsCustomRepository,
                        'implements_repossitory_interface' => $this->generator->entityRepositoryInterface
                                                            ? 'implements '
                                                                . $entityClassDetails->getShortName()
                                                                . 'RepositoryInterface' . PHP_EOL
                                                            : '',
                        'repository_name' => '$' . lcfirst($entityClassDetails->getShortName()),
                        'entity_name' => $entityClassDetails->getShortName(),
                    ]
                );
            }

            $useStatementsService = null;
            if ($this->generator->entityRepositoryInterface) {
                $useStatementsService = new UseStatementGenerator([
                    $entityRepositoryInterfaceClassDetails->getFullName(),
                ]);
            }

            if ($this->generator->commonFactory) {
                $useStatementsService->addUseStatement(
                    $commonFactoryClassDetails->getFullName()
                );
            }

            $this->generator->generateClass(
                $serviceClassDetails->getFullName(),
                'doctrine/Service.tpl.php',
                [
                    'use_statements' =>  $useStatementsService,
                    'repository_name' => '$' . lcfirst($entityClassDetails->getShortName()),
                    'entity_name' => $entityClassDetails->getShortName(),
                    'common_factory' => $this->generator->commonFactory ? $commonFactoryClassDetails->getShortName() : null
                ]
            );
        }

        return $entityPath;
    }

    public function generateRepositoryClass(
        string $repositoryClass,
        string $entityClass,
        bool $withPasswordUpgrade,
        bool $includeExampleComments = true,
    ): void {
        $shortEntityClass = Str::getShortClassName($entityClass);
        $entityAlias      = strtolower($shortEntityClass[0]);

        $passwordUserInterfaceName = UserInterface::class;

        if (interface_exists(PasswordAuthenticatedUserInterface::class)) {
            $passwordUserInterfaceName = PasswordAuthenticatedUserInterface::class;
        }

        $interfaceClassNameDetails = new ClassNameDetails($passwordUserInterfaceName, 'Symfony\Component\Security\Core\User');

        $useStatements = new UseStatementGenerator([
            $entityClass,
            ManagerRegistry::class,
            ServiceEntityRepository::class,
        ]);

        if ($withPasswordUpgrade) {
            $useStatements->addUseStatement([
                $interfaceClassNameDetails->getFullName(),
                PasswordUpgraderInterface::class,
                UnsupportedUserException::class,
            ]);
        }

        $this->generator->generateClass(
            $repositoryClass,
            'doctrine/DefaultRepository.tpl.php',
            [
                'use_statements' => $useStatements,
                'entity_class_name' => $shortEntityClass,
                'entity_alias' => $entityAlias,
                'with_password_upgrade' => $withPasswordUpgrade,
                'password_upgrade_user_interface' => $interfaceClassNameDetails,
                'include_example_comments' => $includeExampleComments,
            ],
        );
    }
}
