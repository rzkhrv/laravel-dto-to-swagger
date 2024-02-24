<?php

declare(strict_types=1);

namespace Kr0lik\DtoToSwagger\OperationDescriber\Describers;

use InvalidArgumentException;
use Kr0lik\DtoToSwagger\Contract\JsonRequestInterface;
use Kr0lik\DtoToSwagger\Helper\ContextHelper;
use Kr0lik\DtoToSwagger\Helper\NameHelper;
use Kr0lik\DtoToSwagger\Helper\Util;
use Kr0lik\DtoToSwagger\OperationDescriber\OperationDescriberInterface;
use Kr0lik\DtoToSwagger\PropertyDescriber\PropertyDescriber;
use Kr0lik\DtoToSwagger\ReflectionPreparer\ReflectionPreparer;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\RequestBody;
use OpenApi\Annotations\Schema;
use OpenApi\Attributes\Parameter;
use OpenApi\Generator;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\PropertyInfo\Type;

class RequestDescriber implements OperationDescriberInterface
{
    /**
     * @param array<int, array<string, mixed>> $requestErrorResponseSchemas
     */
    public function __construct(
        private PropertyDescriber $propertyDescriber,
        private ReflectionPreparer $reflectionPreparer,
        private array $requestErrorResponseSchemas,
        private ?string $fileUploadType,
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function describe(Operation $operation, ReflectionMethod $reflectionMethod, array $context = []): void
    {
        foreach ($reflectionMethod->getAttributes() as $attribute) {
            $attributeInstance = $attribute->newInstance();

            if ($attributeInstance instanceof RequestBody) {
                Util::createChild($operation, RequestBody::class, (array) $attributeInstance->jsonSerialize());
            }
        }

        foreach ($this->reflectionPreparer->getArgumentTypes($reflectionMethod) as $types) {
            if (1 === count($types) && is_subclass_of($types[0]->getClassName(), JsonRequestInterface::class)) {
                $jsonContent = new Schema([]);

                $context = ContextHelper::getContext($reflectionMethod);

                $this->propertyDescriber->describe($jsonContent, $context, ...$types);

                $request = Util::getChild($operation, RequestBody::class);

                Util::merge($request, [
                    'content' => [
                        'application/json' => [
                            'schema' => $jsonContent,
                        ],
                    ],
                ], true);

                if ([] !== $this->requestErrorResponseSchemas) {
                    Util::merge($operation, ['responses' => $this->requestErrorResponseSchemas]);
                }

                $this->searchAndDescribeParameters($operation, $types[0]);
                $this->searchAndDescribeFIleUploadType($operation, $types[0]);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function searchAndDescribeParameters(Operation $operation, Type $type): void
    {
        $class = $type->getClassName();

        if (null === $class) {
            return;
        }

        $reflectionClass = new ReflectionClass($class);

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            foreach ($reflectionProperty->getAttributes() as $reflectionAttribute) {
                $attributeInstance = $reflectionAttribute->newInstance();

                if ($attributeInstance instanceof Parameter) {
                    $name = $attributeInstance->name;

                    if (Generator::UNDEFINED === $name || null === $name || '' === $name) {
                        $name = NameHelper::getName($reflectionProperty);
                    }

                    $newParameter = Util::getOperationParameter($operation, $name, $attributeInstance->in);
                    Util::merge($newParameter, $attributeInstance);

                    /** @var Schema $schema */
                    $schema = Util::getChild($newParameter, Schema::class);

                    $context = ContextHelper::getContext($reflectionProperty);

                    $this->propertyDescriber->describe($schema, $context, ...$this->reflectionPreparer->getTypes($reflectionProperty));
                }
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function searchAndDescribeFIleUploadType(Operation $operation, Type $type): void
    {
        $fileUploadProperties = $this->searchFIleUploadProperties($type);

        if ([] === $fileUploadProperties) {
            return;
        }

        $uploadContent = new Schema([
            'type' => 'object',
            'properties' => array_map(static fn (): array => ['type' => 'string', 'format' => 'binary'], array_flip($fileUploadProperties)),
        ]);

        $request = Util::getChild($operation, RequestBody::class);

        Util::merge($request, [
            'content' => [
                'multipart/form-data' => [
                    'schema' => $uploadContent,
                ],
            ],
        ]);
    }

    /**
     * @throws ReflectionException
     *
     * @return string[]
     */
    private function searchFIleUploadProperties(Type $type): array
    {
        if (null === $this->fileUploadType || '' === $this->fileUploadType) {
            return [];
        }

        $class = $type->getClassName();

        if (null === $class) {
            return [];
        }

        $reflectionClass = new ReflectionClass($class);

        $fileUploadProperties = [];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if (
                $reflectionProperty->getType() instanceof ReflectionNamedType
                && (
                    $reflectionProperty->getType()->getName() === $this->fileUploadType
                    || is_subclass_of($reflectionProperty->getType()->getName(), $this->fileUploadType)
                )
            ) {
                $fileUploadProperties[] = NameHelper::getName($reflectionProperty);
            }
        }

        return $fileUploadProperties;
    }
}
