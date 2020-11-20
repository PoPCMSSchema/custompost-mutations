<?php

declare(strict_types=1);

namespace PoPSchema\CustomPostMutations\MutationResolvers;

use PoP\Hooks\Facades\HooksAPIFacade;
use PoP\ComponentModel\ErrorHandling\Error;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\LooseContracts\Facades\NameResolverFacade;
use PoPSchema\CustomPosts\Enums\CustomPostStatusEnum;
use PoPSchema\CustomPosts\Facades\CustomPostTypeAPIFacade;
use PoP\ComponentModel\Facades\Instances\InstanceManagerFacade;
use PoPSchema\UserRoles\Facades\UserRoleTypeDataResolverFacade;
use PoP\ComponentModel\MutationResolvers\AbstractMutationResolver;
use PoPSchema\UserStateMutations\MutationResolvers\ValidateUserLoggedInMutationResolverTrait;
use PoPSchema\CustomPostMutations\Facades\CustomPostTypeAPIFacade as MutationCustomPostTypeAPIFacade;

abstract class AbstractCreateUpdateCustomPostMutationResolver extends AbstractMutationResolver implements CustomPostMutationResolverInterface
{
    use ValidateUserLoggedInMutationResolverTrait;

    public const HOOK_EXECUTE_CREATE_OR_UPDATE = __CLASS__ . ':execute-create-or-update';
    public const HOOK_EXECUTE_CREATE = __CLASS__ . ':execute-create';
    public const HOOK_EXECUTE_UPDATE = __CLASS__ . ':execute-update';
    public const HOOK_VALIDATE_CONTENT = __CLASS__ . ':validate-content';

    // @TODO: Migrate when package "Categories" is completed
    // protected function getCategoryTaxonomy(): ?string
    // {
    //     return null;
    // }

    public function validateErrors(array $form_data): ?array
    {
        $errors = [];

        // Check that the user is logged-in
        $this->validateUserIsLoggedIn($errors);
        if ($errors) {
            return $errors;
        }

        $customPostID = $form_data[MutationInputProperties::ID];
        if ($customPostID) {
            // If already exists any of these errors above, return errors
            $this->validateUpdate($errors, $form_data);
            if ($errors) {
                return $errors;
            }
            $this->validateUpdateContent($errors, $form_data);
        } else {
            // If already exists any of these errors above, return errors
            $this->validateCreate($errors, $form_data);
            if ($errors) {
                return $errors;
            }
            $this->validateCreateContent($errors, $form_data);
        }
        $this->validateContent($errors, $form_data);
        return $errors;
    }

    protected function validateContent(array &$errors, array $form_data): void
    {
        // Allow plugins to add validation for their fields
        $hooksAPI = HooksAPIFacade::getInstance();
        $hooksAPI->doAction(
            self::HOOK_VALIDATE_CONTENT,
            array(&$errors),
            $form_data
        );
    }

    protected function validateCreateContent(array &$errors, array $form_data): void
    {
    }
    protected function validateUpdateContent(array &$errors, array $form_data): void
    {
    }

    protected function validateCreate(array &$errors, array $form_data): void
    {
        $nameResolver = NameResolverFacade::getInstance();
        $translationAPI = TranslationAPIFacade::getInstance();

        // Validate user permission
        $userRoleTypeDataResolver = UserRoleTypeDataResolverFacade::getInstance();
        $vars = ApplicationState::getVars();
        $userID = $vars['global-userstate']['current-user-id'];
        if (!$userRoleTypeDataResolver->userCan(
            $userID,
            $nameResolver->getName('popcms:capability:editPosts')
        )) {
            $errors[] = $translationAPI->__('Your user doesn\'t have permission for editing.', 'pop-application');
        }
    }

    protected function validateUpdate(array &$errors, array $form_data): void
    {
        $translationAPI = TranslationAPIFacade::getInstance();

        $customPostID = $form_data[MutationInputProperties::ID];
        if (!$customPostID) {
            $errors[] = $translationAPI->__('The ID is missing', 'pop-application');
            return;
        }

        $customPostTypeAPI = CustomPostTypeAPIFacade::getInstance();
        $post = $customPostTypeAPI->getCustomPost($customPostID);
        if (!$post) {
            $errors[] = sprintf(
                $translationAPI->__('There is no entity with ID \'%s\'', 'pop-application'),
                $customPostID
            );
            return;
        }

        $status = $customPostTypeAPI->getStatus($customPostID);
        $instanceManager = InstanceManagerFacade::getInstance();
        /**
         * @var CustomPostStatusEnum
         */
        $customPostStatusEnum = $instanceManager->getInstance(CustomPostStatusEnum::class);
        if (!in_array($status, $customPostStatusEnum->getValues())) {
            $errors[] = sprintf(
                $translationAPI->__('Status \'%s\' is not supported', 'pop-application'),
                $status
            );
        }
    }

    /**
     * @param mixed $customPostID
     */
    protected function additionals($customPostID, array $form_data): void
    {
    }
    /**
     * @param mixed $customPostID
     */
    protected function updateAdditionals($customPostID, array $form_data, array $log): void
    {
    }
    /**
     * @param mixed $customPostID
     */
    protected function createAdditionals($customPostID, array $form_data): void
    {
    }

    // protected function addCustomPostType(&$post_data)
    // {
    //     $post_data['custompost-type'] = $this->getCustomPostType();
    // }

    protected function addCreateUpdateCustomPostData(array &$post_data, array $form_data): void
    {
        if (isset($form_data[MutationInputProperties::CONTENT])) {
            $post_data['content'] = $form_data[MutationInputProperties::CONTENT];
        }
        if (isset($form_data[MutationInputProperties::TITLE])) {
            $post_data['title'] = $form_data[MutationInputProperties::TITLE];
        }
        if (isset($form_data[MutationInputProperties::STATUS])) {
            $post_data['status'] = $form_data[MutationInputProperties::STATUS];
        }
    }

    protected function getUpdateCustomPostData(array $form_data): array
    {
        $post_data = array(
            'id' => $form_data[MutationInputProperties::ID],
        );
        $this->addCreateUpdateCustomPostData($post_data, $form_data);

        return $post_data;
    }

    protected function getCreateCustomPostData(array $form_data): array
    {
        $post_data = [
            'custompost-type' => $this->getCustomPostType(),
        ];
        $this->addCreateUpdateCustomPostData($post_data, $form_data);

        // $this->addCustomPostType($post_data);

        return $post_data;
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed the ID of the updated custom post
     */
    protected function executeUpdateCustomPost(array $data)
    {
        $customPostTypeAPI = MutationCustomPostTypeAPIFacade::getInstance();
        return $customPostTypeAPI->updateCustomPost($data);
    }

    // @TODO: Migrate when package "Categories" is completed
    // protected function getCategories(array $form_data): ?array
    // {
    //     return $form_data[MutationInputProperties::CATEGORIES];
    // }

    /**
     * @param mixed $customPostID
     */
    protected function createUpdateCustomPost(array $form_data, $customPostID): void
    {
        // @TODO: Migrate when package "Categories" is completed
        // // Set categories for any taxonomy (not only for "category")
        // if ($cats = $this->getCategories($form_data)) {
        //     $taxonomyapi = \PoPSchema\Taxonomies\FunctionAPIFactory::getInstance();
        //     $taxonomy = $this->getCategoryTaxonomy();
        //     $taxonomyapi->setPostTerms($customPostID, $cats, $taxonomy);
        // }
    }

    /**
     * @param mixed $customPostID
     */
    protected function getUpdateCustomPostDataLog($customPostID, array $form_data): array
    {
        $customPostTypeAPI = CustomPostTypeAPIFacade::getInstance();
        $log = array(
            'previous-status' => $customPostTypeAPI->getStatus($customPostID),
        );

        return $log;
    }

    /**
     * @return mixed The ID of the updated entity, or an Error
     */
    protected function update(array $form_data)
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        $post_data = $this->getUpdateCustomPostData($form_data);
        $customPostID = $post_data['id'];

        // Create the operation log, to see what changed. Needed for
        // - Send email only when post published
        // - Add user notification of post being referenced, only when the reference is new (otherwise it will add the notification each time the user updates the post)
        $log = $this->getUpdateCustomPostDataLog($customPostID, $form_data);

        $result = $this->executeUpdateCustomPost($post_data);

        if ($result === 0) {
            return new Error(
                'update-error',
                $translationAPI->__('Oops, there was a problem... this is embarrassing, huh?', 'pop-application')
            );
        }

        $this->createUpdateCustomPost($form_data, $customPostID);

        // Allow for additional operations (eg: set Action categories)
        $this->additionals($customPostID, $form_data);
        $this->updateAdditionals($customPostID, $form_data, $log);

        // Inject Share profiles here
        $hooksAPI = HooksAPIFacade::getInstance();
        $hooksAPI->doAction(self::HOOK_EXECUTE_CREATE_OR_UPDATE, $customPostID, $form_data);
        $hooksAPI->doAction(self::HOOK_EXECUTE_UPDATE, $customPostID, $log, $form_data);
        return $customPostID;
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed the ID of the created custom post
     */
    protected function executeCreateCustomPost(array $data)
    {
        $customPostTypeAPI = MutationCustomPostTypeAPIFacade::getInstance();
        return $customPostTypeAPI->createCustomPost($data);
    }

    /**
     * @return mixed The ID of the created entity, or an Error
     */
    protected function create(array $form_data)
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        $post_data = $this->getCreateCustomPostData($form_data);
        $customPostID = $this->executeCreateCustomPost($post_data);

        if ($customPostID == 0) {
            return new Error(
                'create-error',
                $translationAPI->__('Oops, there was a problem... this is embarrassing, huh?', 'pop-application')
            );
        }

        $this->createUpdateCustomPost($form_data, $customPostID);

        // Allow for additional operations (eg: set Action categories)
        $this->additionals($customPostID, $form_data);
        $this->createAdditionals($customPostID, $form_data);

        // Inject Share profiles here
        $hooksAPI = HooksAPIFacade::getInstance();
        $hooksAPI->doAction(self::HOOK_EXECUTE_CREATE_OR_UPDATE, $customPostID, $form_data);
        $hooksAPI->doAction(self::HOOK_EXECUTE_CREATE, $customPostID, $form_data);

        return $customPostID;
    }
}
