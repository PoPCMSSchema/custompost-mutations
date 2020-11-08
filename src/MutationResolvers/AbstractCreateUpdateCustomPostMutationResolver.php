<?php

declare(strict_types=1);

namespace PoPSchema\CustomPostMutations\MutationResolvers;

define('POP_POSTSCREATION_CONSTANT_VALIDATECATEGORIESTYPE_ATLEASTONE', 1);
define('POP_POSTSCREATION_CONSTANT_VALIDATECATEGORIESTYPE_EXACTLYONE', 2);

use PoP\Hooks\Facades\HooksAPIFacade;
use PoPSchema\CustomPosts\Types\Status;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\LooseContracts\Facades\NameResolverFacade;
use PoPSchema\CustomPosts\Facades\CustomPostTypeAPIFacade;
use PoP\ComponentModel\MutationResolvers\AbstractMutationResolver;
use PoPSchema\CustomPostMutations\Facades\CustomPostTypeAPIFacade as MutationCustomPostTypeAPIFacade;
use PoP\ComponentModel\Error;

abstract class AbstractCreateUpdateCustomPostMutationResolver extends AbstractMutationResolver implements CustomPostMutationResolverInterface
{
    protected function getCategoryTaxonomy(): ?string
    {
        return null;
    }

    protected function addParentCategories()
    {
        return HooksAPIFacade::getInstance()->applyFilters(
            'GD_CreateUpdate_Post:add-parent-categories',
            false,
            $this
        );
    }

    protected function isFeaturedimageMandatory()
    {
        return false;
    }

    protected function validateCategories(array $form_data)
    {
        if (isset($form_data['categories'])) {
            if (is_array($form_data['categories'])) {
                return POP_POSTSCREATION_CONSTANT_VALIDATECATEGORIESTYPE_ATLEASTONE;
            }

            return POP_POSTSCREATION_CONSTANT_VALIDATECATEGORIESTYPE_EXACTLYONE;
        }

        return null;
    }

    protected function getCategoriesErrorMessages()
    {
        return HooksAPIFacade::getInstance()->applyFilters(
            'GD_CreateUpdate_Post:categories-validation:error',
            array(
                'empty-categories' => TranslationAPIFacade::getInstance()->__('The categories have not been set', 'pop-application'),
                'empty-category' => TranslationAPIFacade::getInstance()->__('The category has not been set', 'pop-application'),
                'only-one' => TranslationAPIFacade::getInstance()->__('Only one category can be selected', 'pop-application'),
            )
        );
    }

    // Update Post Validation
    protected function validatecontent(&$errors, $form_data)
    {
        if (isset($form_data['title']) && empty($form_data['title'])) {
            $errors[] = TranslationAPIFacade::getInstance()->__('The title cannot be empty', 'pop-application');
        }

        // Validate the following conditions only if status = pending/publish
        if ($form_data['status'] == Status::DRAFT) {
            return;
        }

        if (empty($form_data['content'])) {
            $errors[] = TranslationAPIFacade::getInstance()->__('The content cannot be empty', 'pop-application');
        }

        if ($this->isFeaturedimageMandatory() && empty($form_data['featuredimage'])) {
            $errors[] = TranslationAPIFacade::getInstance()->__('The featured image has not been set', 'pop-application');
        }

        if ($validateCategories = $this->validateCategories($form_data)) {
            $category_error_msgs = $this->getCategoriesErrorMessages();
            if (empty($form_data['categories'])) {
                if ($validateCategories == POP_POSTSCREATION_CONSTANT_VALIDATECATEGORIESTYPE_ATLEASTONE) {
                    $errors[] = $category_error_msgs['empty-categories'];
                } elseif ($validateCategories == POP_POSTSCREATION_CONSTANT_VALIDATECATEGORIESTYPE_EXACTLYONE) {
                    $errors[] = $category_error_msgs['empty-category'];
                }
            } elseif (count($form_data['categories']) > 1 && $validateCategories == POP_POSTSCREATION_CONSTANT_VALIDATECATEGORIESTYPE_EXACTLYONE) {
                $errors[] = $category_error_msgs['only-one'];
            }
        }

        // Allow plugins to add validation for their fields
        HooksAPIFacade::getInstance()->doAction(
            'GD_CreateUpdate_Post:validatecontent',
            array(&$errors),
            $form_data
        );
    }

    protected function validatecreatecontent(&$errors, $form_data)
    {
    }
    protected function validateupdatecontent(&$errors, $form_data)
    {
        if (isset($form_data['references']) && in_array($form_data['customPostID'], $form_data['references'])) {
            $errors[] = TranslationAPIFacade::getInstance()->__('The post cannot be a response to itself', 'pop-postscreation');
        }
    }

    // Update Post Validation
    protected function validatecreate(&$errors)
    {
        // Validate user permission
        $cmsuserrolesapi = \PoPSchema\UserRoles\FunctionAPIFactory::getInstance();
        if (!$cmsuserrolesapi->currentUserCan(NameResolverFacade::getInstance()->getName('popcms:capability:editPosts'))) {
            $errors[] = TranslationAPIFacade::getInstance()->__('Your user doesn\'t have permission for editing.', 'pop-application');
        }
    }

    // Update Post Validation
    protected function validateupdate(&$errors)
    {
        // The ID comes directly as a parameter in the request, it's not a form field
        $post_id = $_REQUEST[POP_INPUTNAME_POSTID];

        // Validate there is postid
        if (!$post_id) {
            $errors[] = TranslationAPIFacade::getInstance()->__('Cheating, huh?', 'pop-application');
            return;
        }

        $customPostTypeAPI = CustomPostTypeAPIFacade::getInstance();
        $post = $customPostTypeAPI->getCustomPost($post_id);
        if (!$post) {
            $errors[] = TranslationAPIFacade::getInstance()->__('Cheating, huh?', 'pop-application');
            return;
        }

        if (!in_array($customPostTypeAPI->getStatus($post_id), array(Status::DRAFT, Status::PENDING, Status::PUBLISHED))) {
            $errors[] = TranslationAPIFacade::getInstance()->__('Hmmmmm, this post seems to have been deleted...', 'pop-application');
            return;
        }

        // Validation below not needed, since this is done in the Checkpoint already
        // // Validate user permission
        // if (!gdCurrentUserCanEdit($post_id)) {
        //     $errors[] = TranslationAPIFacade::getInstance()->__('Your user doesn\'t have permission for editing.', 'pop-application');
        // }

        // // The nonce comes directly as a parameter in the request, it's not a form field
        // $nonce = $_REQUEST[POP_INPUTNAME_NONCE];
        // if (!gdVerifyNonce($nonce, GD_NONCE_EDITURL, $post_id)) {
        //     $errors[] = TranslationAPIFacade::getInstance()->__('Incorrect URL', 'pop-application');
        //     return;
        // }
    }

    /**
     * Function to override
     */
    protected function additionals($post_id, $form_data)
    {
        // Topics
        if (\PoP_ApplicationProcessors_Utils::addCategories()) {
            \PoPSchema\CustomPostMeta\Utils::updateCustomPostMeta($post_id, GD_METAKEY_POST_CATEGORIES, $form_data['topics']);
        }

        // Only if the Volunteering is enabled
        if (defined('POP_VOLUNTEERING_INITIALIZED')) {
            if (defined('POP_VOLUNTEERING_ROUTE_VOLUNTEER') && POP_VOLUNTEERING_ROUTE_VOLUNTEER) {
                // Volunteers Needed?
                if (isset($form_data['volunteersneeded'])) {
                    \PoPSchema\CustomPostMeta\Utils::updateCustomPostMeta($post_id, GD_METAKEY_POST_VOLUNTEERSNEEDED, $form_data['volunteersneeded'], true, true);
                }
            }
        }

        if (\PoP_ApplicationProcessors_Utils::addAppliesto()) {
            \PoPSchema\CustomPostMeta\Utils::updateCustomPostMeta($post_id, GD_METAKEY_POST_APPLIESTO, $form_data['appliesto']);
        }
    }
    /**
     * Function to override
     */
    protected function updateadditionals($post_id, $form_data, $log)
    {
    }
    /**
     * Function to override
     */
    protected function createadditionals($post_id, $form_data)
    {
    }

    protected function maybeAddParentCategories($categories)
    {
        $categoryapi = \PoPSchema\Categories\FunctionAPIFactory::getInstance();
        // If the categories are nested under other categories, ask if to add those too
        if ($this->addParentCategories()) {
            // Use a while, to also check if the parent category has a parent itself
            $i = 0;
            while ($i < count($categories)) {
                $cat = $categories[$i];
                $i++;

                if ($parent_cat = $categoryapi->getCategoryParent($cat)) {
                    $categories[] = $parent_cat;
                }
            }
        }

        return $categories;
    }

    protected function addCustomPostType(&$post_data)
    {
        $post_data['custom-post-type'] = $this->getCustomPostType();
    }

    protected function getUpdateCustomPostData($form_data)
    {
        $post_data = array(
            'id' => $form_data['customPostID'],
            'post-content' => $form_data['content'],
        );

        if (isset($form_data['title'])) {
            $post_data['post-title'] = $form_data['title'];
        }

        $this->addCustomPostType($post_data);

        // Status: Validate the value is permitted, or get the default value otherwise
        if ($status = \GD_CreateUpdate_Utils::getUpdatepostStatus($form_data['status'], $this->moderate())) {
            $post_data['custom-post-status'] = $status;
        }

        return $post_data;
    }

    protected function moderate()
    {
        return \GD_CreateUpdate_Utils::moderate();
    }

    protected function getCreateCustomPostData($form_data)
    {
        // Status: Validate the value is permitted, or get the default value otherwise
        $status = \GD_CreateUpdate_Utils::getCreatepostStatus($form_data['status'], $this->moderate());
        $post_data = array(
            'post-content' => $form_data['content'],
            'custom-post-status' => $status,
        );

        if (isset($form_data['title'])) {
            $post_data['post-title'] = $form_data['title'];
        }

        $this->addCustomPostType($post_data);

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

    protected function createUpdateCustomPost($form_data, $post_id)
    {
        // Set category taxonomy for taxonomies other than "category"
        $taxonomyapi = \PoPSchema\Taxonomies\FunctionAPIFactory::getInstance();
        $taxonomy = $this->getCategoryTaxonomy();
        if ($cats = $form_data['categories']) {
            $cats = $this->maybeAddParentCategories($cats);
            $taxonomyapi->setPostTerms($post_id, $cats, $taxonomy);
        }

        $this->setfeaturedimage($post_id, $form_data);

        if (isset($form_data['references'])) {
            \PoPSchema\CustomPostMeta\Utils::updateCustomPostMeta($post_id, GD_METAKEY_POST_REFERENCES, $form_data['references']);
        }
    }

    protected function getUpdateCustomPostDataLog($post_id, $form_data)
    {
        $customPostTypeAPI = CustomPostTypeAPIFacade::getInstance();
        $log = array(
            'previous-status' => $customPostTypeAPI->getStatus($post_id),
        );

        if (isset($form_data['references'])) {
            $previous_references = \PoPSchema\CustomPostMeta\Utils::getCustomPostMeta($post_id, GD_METAKEY_POST_REFERENCES);
            $log['new-references'] = array_diff($form_data['references'], $previous_references);
        }

        return $log;
    }

    protected function updatepost($form_data)
    {
        $post_data = $this->getUpdateCustomPostData($form_data);
        $post_id = $post_data['id'];

        // Create the operation log, to see what changed. Needed for
        // - Send email only when post published
        // - Add user notification of post being referenced, only when the reference is new (otherwise it will add the notification each time the user updates the post)
        $log = $this->getUpdateCustomPostDataLog($post_id, $form_data);

        $result = $this->executeUpdateCustomPost($post_data);

        if ($result === 0) {
            return new Error(
                'update-error',
                TranslationAPIFacade::getInstance()->__('Oops, there was a problem... this is embarrassing, huh?', 'pop-application')
            );
        }

        $this->createUpdateCustomPost($form_data, $post_id);

        // Allow for additional operations (eg: set Action categories)
        $this->additionals($post_id, $form_data);
        $this->updateadditionals($post_id, $form_data, $log);

        // Inject Share profiles here
        HooksAPIFacade::getInstance()->doAction('gd_createupdate_post', $post_id, $form_data);
        HooksAPIFacade::getInstance()->doAction('gd_createupdate_post:update', $post_id, $log, $form_data);
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

    protected function createpost($form_data)
    {
        $post_data = $this->getCreateCustomPostData($form_data);
        $post_id = $this->executeCreateCustomPost($post_data);

        if ($post_id == 0) {
            return new Error(
                'create-error',
                TranslationAPIFacade::getInstance()->__('Oops, there was a problem... this is embarrassing, huh?', 'pop-application')
            );
        }

        $this->createUpdateCustomPost($form_data, $post_id);

        // Allow for additional operations (eg: set Action categories)
        $this->additionals($post_id, $form_data);
        $this->createadditionals($post_id, $form_data);

        // Inject Share profiles here
        HooksAPIFacade::getInstance()->doAction('gd_createupdate_post', $post_id, $form_data);
        HooksAPIFacade::getInstance()->doAction('gd_createupdate_post:create', $post_id, $form_data);

        return $post_id;
    }

    protected function setfeaturedimage($post_id, $form_data)
    {
        if (isset($form_data['featuredimage'])) {
            $featuredimage = $form_data['featuredimage'];

            // Featured Image
            if ($featuredimage) {
                \set_post_thumbnail($post_id, $featuredimage);
            } else {
                \delete_post_thumbnail($post_id);
            }
        }
    }

    protected function update(array $form_data)
    {
        return $this->updatepost($form_data);
    }

    protected function create(array $form_data)
    {
        return $this->createpost($form_data);
    }
}
