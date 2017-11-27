<?php

namespace Path\To\Custom\Type;

/**
 * Custom Doctrine data type for JSON.
 *
 * Doctrine has a json_array type but, as its name suggests, it was designed with
 * only arrays in mind. This extending type fixes a bug with the json_array type
 * wherein a null value in database gets converted to an empty array.
 *
 * IMPORTANT NOTE: you must register custom types with Doctrine:
 *      \Doctrine\DBAL\Types\Type::addType('json', '\Path\To\Custom\Type\Json');
 *
 * @link https://github.com/doctrine/dbal/issues/1643
 * @link https://github.com/doctrine/dbal/pull/655
 */
class Json extends \Doctrine\DBAL\Types\JsonArrayType
{
    /**
     * Made to be compatible with Doctrine 2.4 and 2.5; 2.5 added getJsonTypeDeclarationSQL().
     *
     * {@inheritdoc}
     */
    public function getSQLDeclaration(array $fieldDeclaration, \Doctrine\DBAL\Platforms\AbstractPlatform $platform)
    {
        return method_exists($platform, 'getJsonTypeDeclarationSQL') ? (
            $platform->getJsonTypeDeclarationSQL($fieldDeclaration)
        ) : $platform->getClobTypeDeclarationSQL($fieldDeclaration);
    }

    /**
     * When database value is null, we return null instead of empty array like our parent does.
     *
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform)
    {
        return $value === null ? null : parent::convertToPHPValue($value, $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'json';
    }
} 
