<?php

declare(strict_types=1);

namespace PoPCMSSchema\CustomPostMutations\MutationResolvers;

use DateTime;
use DateTimeInterface;
use PoPCMSSchema\CustomPostMutations\Constants\CustomPostCRUDHookNames;
use PoPCMSSchema\CustomPostMutations\Constants\MutationInputProperties;
use PoPCMSSchema\CustomPostMutations\Exception\CustomPostCRUDMutationException;
use PoPCMSSchema\CustomPostMutations\FeedbackItemProviders\MutationErrorFeedbackItemProvider;
use PoPCMSSchema\CustomPostMutations\TypeAPIs\CustomPostTypeMutationAPIInterface;
use PoPCMSSchema\CustomPosts\Enums\CustomPostStatus;
use PoPCMSSchema\CustomPosts\TypeAPIs\CustomPostTypeAPIInterface;
use PoPCMSSchema\UserRoles\TypeAPIs\UserRoleTypeAPIInterface;
use PoP\ComponentModel\Feedback\FeedbackItemResolution;
use PoP\ComponentModel\Feedback\ObjectTypeFieldResolutionFeedback;
use PoP\ComponentModel\Feedback\ObjectTypeFieldResolutionFeedbackStore;
use PoP\ComponentModel\MutationResolvers\AbstractMutationResolver;
use PoP\ComponentModel\QueryResolution\FieldDataAccessorInterface;
use PoP\LooseContracts\NameResolverInterface;
use PoP\Root\App;
use stdClass;

abstract class AbstractCreateOrUpdateCustomPostMutationResolver extends AbstractMutationResolver implements CustomPostMutationResolverInterface
{
    use CreateOrUpdateCustomPostMutationResolverTrait;

    private ?NameResolverInterface $nameResolver = null;
    private ?UserRoleTypeAPIInterface $userRoleTypeAPI = null;
    private ?CustomPostTypeAPIInterface $customPostTypeAPI = null;
    private ?CustomPostTypeMutationAPIInterface $customPostTypeMutationAPI = null;

    final protected function getNameResolver(): NameResolverInterface
    {
        if ($this->nameResolver === null) {
            /** @var NameResolverInterface */
            $nameResolver = $this->instanceManager->getInstance(NameResolverInterface::class);
            $this->nameResolver = $nameResolver;
        }
        return $this->nameResolver;
    }
    final protected function getUserRoleTypeAPI(): UserRoleTypeAPIInterface
    {
        if ($this->userRoleTypeAPI === null) {
            /** @var UserRoleTypeAPIInterface */
            $userRoleTypeAPI = $this->instanceManager->getInstance(UserRoleTypeAPIInterface::class);
            $this->userRoleTypeAPI = $userRoleTypeAPI;
        }
        return $this->userRoleTypeAPI;
    }
    final protected function getCustomPostTypeAPI(): CustomPostTypeAPIInterface
    {
        if ($this->customPostTypeAPI === null) {
            /** @var CustomPostTypeAPIInterface */
            $customPostTypeAPI = $this->instanceManager->getInstance(CustomPostTypeAPIInterface::class);
            $this->customPostTypeAPI = $customPostTypeAPI;
        }
        return $this->customPostTypeAPI;
    }
    final protected function getCustomPostTypeMutationAPI(): CustomPostTypeMutationAPIInterface
    {
        if ($this->customPostTypeMutationAPI === null) {
            /** @var CustomPostTypeMutationAPIInterface */
            $customPostTypeMutationAPI = $this->instanceManager->getInstance(CustomPostTypeMutationAPIInterface::class);
            $this->customPostTypeMutationAPI = $customPostTypeMutationAPI;
        }
        return $this->customPostTypeMutationAPI;
    }

    protected function validateCreateErrors(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        $this->validateCreateUpdateErrors($fieldDataAccessor, $objectTypeFieldResolutionFeedbackStore);
        if ($objectTypeFieldResolutionFeedbackStore->getErrors() !== []) {
            return;
        }

        $this->validateCreate($fieldDataAccessor, $objectTypeFieldResolutionFeedbackStore);
    }

    protected function validateUpdateErrors(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        // If there are errors here, don't keep validating others
        $this->validateCreateUpdateErrors($fieldDataAccessor, $objectTypeFieldResolutionFeedbackStore);
        if ($objectTypeFieldResolutionFeedbackStore->getErrors() !== []) {
            return;
        }

        $this->validateUpdate($fieldDataAccessor, $objectTypeFieldResolutionFeedbackStore);
    }

    protected function validateCreateUpdateErrors(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        $errorCount = $objectTypeFieldResolutionFeedbackStore->getErrorCount();

        $this->validateIsUserLoggedIn(
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );

        if ($objectTypeFieldResolutionFeedbackStore->getErrorCount() > $errorCount) {
            return;
        }

        $this->validateCanLoggedInUserEditCustomPosts(
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );

        // Check if the user can publish custom posts
        if ($fieldDataAccessor->getValue(MutationInputProperties::STATUS) === CustomPostStatus::PUBLISH) {
            $this->validateCanLoggedInUserPublishCustomPosts(
                $fieldDataAccessor,
                $objectTypeFieldResolutionFeedbackStore,
            );
        }

        if ($objectTypeFieldResolutionFeedbackStore->getErrorCount() > $errorCount) {
            return;
        }

        // Validate that the parent exists
        if ($this->supportsCustomPostParent()) {
            if ($fieldDataAccessor->hasValue(MutationInputProperties::PARENT_BY)) {
                /** @var stdClass|null */
                $parentBy = $fieldDataAccessor->getValue(MutationInputProperties::PARENT_BY);
                if ($parentBy !== null) {
                    $parentCustomPostID = null;
                    $customPostType = $fieldDataAccessor->getValue(MutationInputProperties::CUSTOMPOST_TYPE) ?? $this->getCustomPostType();
                    /**
                     * If there's no custom post type, then it's a nested update mutation,
                     * then get the CPT from the custom post
                     */
                    if ($customPostType === '') {
                        $customPostID = $fieldDataAccessor->getValue(MutationInputProperties::ID);
                        /** @var string */
                        $customPostType = $this->getCustomPostTypeAPI()->getCustomPostType($customPostID);
                    }
                    if (isset($parentBy->{MutationInputProperties::ID})) {
                        $parentCustomPostID = $parentBy->{MutationInputProperties::ID};
                        $this->validateParentCustomPostExists(
                            $parentCustomPostID,
                            $customPostType,
                            $fieldDataAccessor,
                            $objectTypeFieldResolutionFeedbackStore,
                        );
                    } elseif (isset($parentBy->{MutationInputProperties::SLUG_PATH})) {
                        $parentSlugPath = $parentBy->{MutationInputProperties::SLUG_PATH};
                        $parentCustomPostID = $this->validateCustomPostBySlugPathExists(
                            $parentSlugPath,
                            $customPostType,
                            $fieldDataAccessor,
                            $objectTypeFieldResolutionFeedbackStore,
                        );
                    }

                    if ($objectTypeFieldResolutionFeedbackStore->getErrorCount() > $errorCount) {
                        return;
                    }

                    // Validate the parent does not create a recursion
                    if ($parentCustomPostID !== null) {
                        $customPostID = $fieldDataAccessor->getValue(MutationInputProperties::ID);
                        if ($customPostID === $parentCustomPostID) {
                            $objectTypeFieldResolutionFeedbackStore->addError(
                                new ObjectTypeFieldResolutionFeedback(
                                    new FeedbackItemResolution(
                                        MutationErrorFeedbackItemProvider::class,
                                        MutationErrorFeedbackItemProvider::E11,
                                    ),
                                    $fieldDataAccessor->getField(),
                                )
                            );
                        } elseif ($customPostID !== null) {
                            $this->validateParentCustomPostDoesNotCreateRecursion(
                                $parentCustomPostID,
                                $customPostID,
                                $fieldDataAccessor,
                                $objectTypeFieldResolutionFeedbackStore,
                            );
                        }
                    }
                }
            }
        }

        $this->triggerValidateCreateOrUpdateHook(
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    /**
     * Whether this mutation resolver supports custom post parent functionality
     */
    abstract protected function supportsCustomPostParent(): bool;

    protected function triggerValidateCreateOrUpdateHook(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        App::doAction(
            CustomPostCRUDHookNames::VALIDATE_CREATE_OR_UPDATE,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    protected function validateCreate(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        $errorCount = $objectTypeFieldResolutionFeedbackStore->getErrorCount();

        $customPostType = $fieldDataAccessor->getValue(MutationInputProperties::CUSTOMPOST_TYPE) ?? $this->getCustomPostType();
        $this->validateCanLoggedInUserEditCustomPostType(
            $customPostType,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );

        if ($objectTypeFieldResolutionFeedbackStore->getErrorCount() > $errorCount) {
            return;
        }

        $this->triggerValidateCreateHook(
            $customPostType,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    protected function triggerValidateCreateHook(
        string $customPostType,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        App::doAction(
            CustomPostCRUDHookNames::VALIDATE_CREATE,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
            $customPostType,
        );
    }

    protected function validateUpdate(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        $errorCount = $objectTypeFieldResolutionFeedbackStore->getErrorCount();

        $customPostID = $fieldDataAccessor->getValue(MutationInputProperties::ID);
        $this->validateCustomPostExists(
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );

        if ($objectTypeFieldResolutionFeedbackStore->getErrorCount() > $errorCount) {
            return;
        }

        $customPostType = $fieldDataAccessor->getValue(MutationInputProperties::CUSTOMPOST_TYPE) ?? $this->getCustomPostType();
        if ($customPostType !== '') {
            $this->validateIsCustomPostType(
                $customPostID,
                $customPostType,
                $fieldDataAccessor,
                $objectTypeFieldResolutionFeedbackStore,
            );
        }

        if ($objectTypeFieldResolutionFeedbackStore->getErrorCount() > $errorCount) {
            return;
        }

        $this->validateCanLoggedInUserEditCustomPost(
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );

        if ($objectTypeFieldResolutionFeedbackStore->getErrorCount() > $errorCount) {
            return;
        }

        if ($customPostType === '') {
            /** @var string */
            $customPostType = $this->getCustomPostTypeAPI()->getCustomPostType($customPostID);
        }
        $this->triggerValidateUpdateHook(
            $customPostID,
            $customPostType,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    protected function triggerValidateUpdateHook(
        string|int $customPostID,
        string $customPostType,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        App::doAction(
            CustomPostCRUDHookNames::VALIDATE_UPDATE,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
            $customPostType,
            $customPostID,
        );
    }

    protected function additionals(int|string $customPostID, FieldDataAccessorInterface $fieldDataAccessor): void
    {
    }

    protected function updateAdditionals(int|string $customPostID, FieldDataAccessorInterface $fieldDataAccessor): void
    {
    }
    protected function createAdditionals(int|string $customPostID, FieldDataAccessorInterface $fieldDataAccessor): void
    {
    }

    /**
     * @param array<string,mixed> $customPostData
     * @return array<string,mixed>
     */
    protected function addCreateOrUpdateCustomPostData(array $customPostData, FieldDataAccessorInterface $fieldDataAccessor): array
    {
        if ($fieldDataAccessor->hasValue(MutationInputProperties::TITLE)) {
            $customPostData['title'] = $fieldDataAccessor->getValue(MutationInputProperties::TITLE);
        }
        /**
         * @todo In addition to "html", support additional oneof properties for the mutation (eg: provide "blocks" for Gutenberg)
         */
        if ($fieldDataAccessor->hasValue(MutationInputProperties::CONTENT_AS)) {
            /** @var stdClass */
            $contentAs = $fieldDataAccessor->getValue(MutationInputProperties::CONTENT_AS);
            if (isset($contentAs->{MutationInputProperties::HTML})) {
                $customPostData['content'] = $contentAs->{MutationInputProperties::HTML};
            }
        }
        if ($fieldDataAccessor->hasValue(MutationInputProperties::PARENT_BY)) {
            /** @var stdClass|null */
            $parentBy = $fieldDataAccessor->getValue(MutationInputProperties::PARENT_BY);
            if ($parentBy === null) {
                $customPostData['parent-id'] = 0;
            } elseif (isset($parentBy->{MutationInputProperties::ID})) {
                $customPostData['parent-id'] = $parentBy->{MutationInputProperties::ID};
            } elseif (isset($parentBy->{MutationInputProperties::SLUG_PATH})) {
                $customPostData['parent-slug-path'] = $parentBy->{MutationInputProperties::SLUG_PATH};
            }
        }
        if ($fieldDataAccessor->hasValue(MutationInputProperties::EXCERPT)) {
            $customPostData['excerpt'] = $fieldDataAccessor->getValue(MutationInputProperties::EXCERPT);
        }
        if ($fieldDataAccessor->hasValue(MutationInputProperties::SLUG)) {
            $customPostData['slug'] = $fieldDataAccessor->getValue(MutationInputProperties::SLUG);
        }
        if ($fieldDataAccessor->hasValue(MutationInputProperties::STATUS)) {
            $customPostData['status'] = $fieldDataAccessor->getValue(MutationInputProperties::STATUS);
        }
        if ($fieldDataAccessor->hasValue(MutationInputProperties::CUSTOMPOST_TYPE)) {
            $customPostData['custompost-type'] = $fieldDataAccessor->getValue(MutationInputProperties::CUSTOMPOST_TYPE);
        }
        if ($fieldDataAccessor->hasValue(MutationInputProperties::DATE)) {
            /** @var DateTime|null */
            $dateTime = $fieldDataAccessor->getValue(MutationInputProperties::DATE);
            if ($dateTime !== null) {
                $customPostData['date'] = $dateTime->format(DateTimeInterface::ATOM);
            }
        }
        if ($fieldDataAccessor->hasValue(MutationInputProperties::GMT_DATE)) {
            /** @var DateTime|null */
            $gmtDateTime = $fieldDataAccessor->getValue(MutationInputProperties::GMT_DATE);
            if ($gmtDateTime !== null) {
                $customPostData['gmtDate'] = $gmtDateTime->format(DateTimeInterface::ATOM);
            }
        }

        // Inject author, categories, tags, featured image, etc
        return App::applyFilters(
            CustomPostCRUDHookNames::GET_CREATE_OR_UPDATE_DATA,
            $customPostData,
            $fieldDataAccessor,
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function getUpdateCustomPostData(FieldDataAccessorInterface $fieldDataAccessor): array
    {
        $customPostData = array(
            'id' => $fieldDataAccessor->getValue(MutationInputProperties::ID),
        );
        $customPostData = $this->addCreateOrUpdateCustomPostData($customPostData, $fieldDataAccessor);

        // Inject author, categories, tags, featured image, etc
        return App::applyFilters(
            CustomPostCRUDHookNames::GET_UPDATE_DATA,
            $customPostData,
            $fieldDataAccessor,
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function getCreateCustomPostData(FieldDataAccessorInterface $fieldDataAccessor): array
    {
        $customPostData = [
            'custompost-type' => $this->getCustomPostType(),
        ];
        $customPostData = $this->addCreateOrUpdateCustomPostData($customPostData, $fieldDataAccessor);

        // Inject author, categories, tags, featured image, etc
        return App::applyFilters(
            CustomPostCRUDHookNames::GET_CREATE_DATA,
            $customPostData,
            $fieldDataAccessor,
        );
    }

    /**
     * @param array<string,mixed> $customPostData
     * @return string|int the ID of the updated custom post
     * @throws CustomPostCRUDMutationException If there was an error (eg: Custom Post does not exist)
     */
    protected function executeUpdateCustomPost(array $customPostData): string|int
    {
        return $this->getCustomPostTypeMutationAPI()->updateCustomPost($customPostData);
    }

    protected function createUpdateCustomPost(FieldDataAccessorInterface $fieldDataAccessor, int|string $customPostID): void
    {
    }

    /**
     * @return string|int The ID of the updated entity
     * @throws CustomPostCRUDMutationException If there was an error (eg: Custom Post does not exist)
     */
    protected function update(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): string|int {
        $customPostData = $this->getUpdateCustomPostData($fieldDataAccessor);
        $customPostID = $customPostData['id'];

        $customPostID = $this->executeUpdateCustomPost($customPostData);

        $this->createUpdateCustomPost($fieldDataAccessor, $customPostID);

        // Allow for additional operations (eg: set Action categories)
        $this->additionals($customPostID, $fieldDataAccessor);
        $this->updateAdditionals($customPostID, $fieldDataAccessor);

        $this->triggerExecuteCreateOrUpdateHook(
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
        $this->triggerExecuteUpdateHook(
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );

        return $customPostID;
    }

    protected function triggerExecuteCreateOrUpdateHook(
        string|int $customPostID,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        App::doAction(
            CustomPostCRUDHookNames::EXECUTE_CREATE_OR_UPDATE,
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    protected function triggerExecuteUpdateHook(
        string|int $customPostID,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        App::doAction(
            CustomPostCRUDHookNames::EXECUTE_UPDATE,
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    /**
     * @param array<string,mixed> $customPostData
     * @return string|int the ID of the created custom post
     * @throws CustomPostCRUDMutationException If there was an error (eg: some Custom Post creation validation failed)
     */
    protected function executeCreateCustomPost(array $customPostData): string|int
    {
        return $this->getCustomPostTypeMutationAPI()->createCustomPost($customPostData);
    }

    /**
     * @return string|int The ID of the created entity
     * @throws CustomPostCRUDMutationException If there was an error (eg: some Custom Post creation validation failed)
     */
    protected function create(
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): string|int {
        $customPostData = $this->getCreateCustomPostData($fieldDataAccessor);
        $customPostID = $this->executeCreateCustomPost($customPostData);

        $this->createUpdateCustomPost($fieldDataAccessor, $customPostID);

        // Allow for additional operations (eg: set Action categories)
        $this->additionals($customPostID, $fieldDataAccessor);
        $this->createAdditionals($customPostID, $fieldDataAccessor);

        $this->triggerExecuteCreateOrUpdateHook(
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
        $this->triggerExecuteCreateHook(
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );

        return $customPostID;
    }

    protected function triggerExecuteCreateHook(
        string|int $customPostID,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        App::doAction(
            CustomPostCRUDHookNames::EXECUTE_CREATE,
            $customPostID,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    protected function validateParentCustomPostExists(
        string|int $parentCustomPostID,
        string $customPostType,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        if (!$this->getCustomPostTypeAPI()->customPostExists($parentCustomPostID)) {
            $objectTypeFieldResolutionFeedbackStore->addError(
                new ObjectTypeFieldResolutionFeedback(
                    new FeedbackItemResolution(
                        MutationErrorFeedbackItemProvider::class,
                        MutationErrorFeedbackItemProvider::E7,
                        [
                            $parentCustomPostID,
                        ]
                    ),
                    $fieldDataAccessor->getField(),
                )
            );
            return;
        }

        $this->validateIsCustomPostType(
            $parentCustomPostID,
            $customPostType,
            $fieldDataAccessor,
            $objectTypeFieldResolutionFeedbackStore,
        );
    }

    protected function validateCustomPostBySlugPathExists(
        string $slugPath,
        string $customPostType,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): string|int|null {
        $parentCustomPost = $this->getCustomPostTypeAPI()->getCustomPostBySlugPath(
            $slugPath,
            $customPostType
        );

        if ($parentCustomPost !== null) {
            return $this->getCustomPostTypeAPI()->getID($parentCustomPost);
        }

        $objectTypeFieldResolutionFeedbackStore->addError(
            new ObjectTypeFieldResolutionFeedback(
                new FeedbackItemResolution(
                    MutationErrorFeedbackItemProvider::class,
                    MutationErrorFeedbackItemProvider::E10,
                    [
                        $slugPath,
                        $customPostType,
                    ]
                ),
                $fieldDataAccessor->getField(),
            )
        );

        return null;
    }

    protected function validateParentCustomPostDoesNotCreateRecursion(
        string|int $parentCustomPostID,
        string|int $customPostID,
        FieldDataAccessorInterface $fieldDataAccessor,
        ObjectTypeFieldResolutionFeedbackStore $objectTypeFieldResolutionFeedbackStore,
    ): void {
        $parentCustomPostAncestorIDs = $this->getCustomPostTypeAPI()->getCustomPostAncestorIDs($parentCustomPostID);
        if ($parentCustomPostAncestorIDs === null) {
            return;
        }

        if (!in_array($customPostID, $parentCustomPostAncestorIDs)) {
            return;
        }

        $objectTypeFieldResolutionFeedbackStore->addError(
            new ObjectTypeFieldResolutionFeedback(
                new FeedbackItemResolution(
                    MutationErrorFeedbackItemProvider::class,
                    MutationErrorFeedbackItemProvider::E12,
                    [
                        $parentCustomPostID,
                        $customPostID,
                    ]
                ),
                $fieldDataAccessor->getField(),
            )
        );
    }
}
