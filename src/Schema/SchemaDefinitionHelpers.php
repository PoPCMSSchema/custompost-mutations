<?php

declare(strict_types=1);

namespace PoPSchema\CustomPostMutations\Schema;

use PoP\Hooks\Facades\HooksAPIFacade;
use PoP\ComponentModel\Schema\SchemaHelpers;
use PoP\ComponentModel\Schema\SchemaDefinition;
use PoP\ComponentModel\Schema\TypeCastingHelpers;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoPSchema\CustomPosts\Enums\CustomPostStatusEnum;
use PoP\ComponentModel\TypeResolvers\TypeResolverInterface;
use PoP\ComponentModel\Facades\Instances\InstanceManagerFacade;
use PoPSchema\CustomPostMutations\MutationResolvers\MutationInputProperties;

class SchemaDefinitionHelpers
{
    public const HOOK_UPDATE_SCHEMA_FIELD_ARGS = __CLASS__ . ':update-schema-field-args';

    private static array $createUpdateCustomPostSchemaFieldArgs = [];

    public static function getCreateUpdateCustomPostSchemaFieldArgs(
        TypeResolverInterface $typeResolver,
        string $fieldName,
        bool $addID
    ): array {
        $key = get_class($typeResolver) . '-' . $fieldName;
        if (is_null(self::$createUpdateCustomPostSchemaFieldArgs[$key])) {
            $hooksAPI = HooksAPIFacade::getInstance();
            $translationAPI = TranslationAPIFacade::getInstance();
            $instanceManager = InstanceManagerFacade::getInstance();
            /**
             * @var CustomPostStatusEnum
             */
            $customPostStatusEnum = $instanceManager->getInstance(CustomPostStatusEnum::class);
            $schemaFieldDefinition = array_merge(
                $addID ? [
                    [
                        SchemaDefinition::ARGNAME_NAME => MutationInputProperties::ID,
                        SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_ID,
                        SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('The ID of the post to update', 'post-mutations'),
                        SchemaDefinition::ARGNAME_MANDATORY => true,
                    ],
                ] : [],
                [
                    [
                        SchemaDefinition::ARGNAME_NAME => MutationInputProperties::TITLE,
                        SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_STRING,
                        SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('The title of the post', 'custompost-mutations'),
                    ],
                    [
                        SchemaDefinition::ARGNAME_NAME => MutationInputProperties::CONTENT,
                        SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_STRING,
                        SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('The content of the post', 'custompost-mutations'),
                    ],
                    [
                        SchemaDefinition::ARGNAME_NAME => MutationInputProperties::STATUS,
                        SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_ENUM,
                        SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('The status of the post', 'custompost-mutations'),
                        SchemaDefinition::ARGNAME_ENUM_NAME => $customPostStatusEnum->getName(),
                        SchemaDefinition::ARGNAME_ENUM_VALUES => SchemaHelpers::convertToSchemaFieldArgEnumValueDefinitions(
                            $customPostStatusEnum->getValues()
                        ),
                    ],
                    // @TODO: Migrate when package "Categories" is completed
                    // [
                    //     SchemaDefinition::ARGNAME_NAME => MutationInputProperties::CATEGORIES,
                    //     SchemaDefinition::ARGNAME_TYPE => TypeCastingHelpers::makeArray(SchemaDefinition::TYPE_ID),
                    //     SchemaDefinition::ARGNAME_DESCRIPTION => sprintf(
                    //         $translationAPI->__('The IDs of the categories (of type %s)', 'custompost-mutations'),
                    //         'PostCategory'// PostCategory::class
                    //     ),
                    // ]
                ]
            );
            self::$createUpdateCustomPostSchemaFieldArgs[$key] = $hooksAPI->applyFilters(
                self::HOOK_UPDATE_SCHEMA_FIELD_ARGS,
                $schemaFieldDefinition,
                $typeResolver,
                $fieldName
            );
        }
        return self::$createUpdateCustomPostSchemaFieldArgs[$key];
    }
}
