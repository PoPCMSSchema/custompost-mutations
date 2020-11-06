<?php

declare(strict_types=1);

namespace PoPSchema\CustomPostMutations\MutationResolvers;

use PoP\Hooks\Facades\HooksAPIFacade;
use PoPSchema\CustomPosts\Types\Status;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoPSchema\CustomPosts\Facades\CustomPostTypeAPIFacade;
use PoP\ComponentModel\ModuleProcessors\DataloadingConstants;
use PoP\ComponentModel\Facades\ModuleProcessors\ModuleProcessorManagerFacade;
use PoP\ComponentModel\MutationResolvers\AbstractCRUDComponentMutationResolverBridge;

abstract class AbstractCreateUpdateCustomPostMutationResolverBridge extends AbstractCRUDComponentMutationResolverBridge
{
    /**
     * @param mixed $result_id Maybe an int, maybe a string
     */
    public function getSuccessString($result_id): ?string
    {
        $customPostTypeAPI = CustomPostTypeAPIFacade::getInstance();
        $status = $customPostTypeAPI->getStatus($result_id);
        if ($status == Status::PUBLISHED) {
            $success_string = sprintf(
                TranslationAPIFacade::getInstance()->__('<a href="%s" %s>Click here to view it</a>.', 'pop-application'),
                $customPostTypeAPI->getPermalink($result_id),
                getReloadurlLinkattrs()
            );
        } elseif ($status == Status::DRAFT) {
            $success_string = TranslationAPIFacade::getInstance()->__('The status is still “Draft”, so it won\'t be online.', 'pop-application');
        } elseif ($status == Status::PENDING) {
            $success_string = TranslationAPIFacade::getInstance()->__('Now waiting for approval from the admins.', 'pop-application');
        }

        return HooksAPIFacade::getInstance()->applyFilters('gd-createupdate-post:execute:successstring', $success_string, $result_id, $status);
    }

    /**
     * @param mixed $result_id Maybe an int, maybe a string
     */
    protected function modifyDataProperties(array &$data_properties, $result_id): void
    {
        parent::modifyDataProperties($data_properties, $result_id);

        $data_properties[DataloadingConstants::QUERYARGS]['custom-post-status'] = [
            Status::PUBLISHED,
            Status::PENDING,
            Status::DRAFT,
        ];
    }

    public function getFormData(): array
    {
        $cmseditpostshelpers = \PoP\EditPosts\HelperAPIFactory::getInstance();
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();

        $editor = $this->getEditorInput();
        $form_data = array(
            'customPostID' => $_REQUEST[POP_INPUTNAME_POSTID],
            'content' => trim($cmseditpostshelpers->kses(stripslashes($moduleprocessor_manager->getProcessor($editor)->getValue($editor)))),
        );

        if ($this->showCategories()) {
            $form_data['categories'] = $this->getCategories();
        }

        if ($this->supportsTitle()) {
            $form_data['title'] = trim(strip_tags($moduleprocessor_manager->getProcessor([\PoP_Module_Processor_CreateUpdatePostTextFormInputs::class, \PoP_Module_Processor_CreateUpdatePostTextFormInputs::MODULE_FORMINPUT_CUP_TITLE])->getValue([\PoP_Module_Processor_CreateUpdatePostTextFormInputs::class, \PoP_Module_Processor_CreateUpdatePostTextFormInputs::MODULE_FORMINPUT_CUP_TITLE])));
        }

        if ($featuredimage = $this->getFeaturedimageModule()) {
            $form_data['featuredimage'] = $moduleprocessor_manager->getProcessor($featuredimage)->getValue($featuredimage);
        }

        // Status: 2 possibilities:
        // - Moderate: then using the Draft/Pending/Publish Select, user cannot choose 'Publish' when creating a post
        // - No moderation: using the 'Keep as Draft' checkbox, completely omitting value 'Pending', post is either 'draft' or 'publish'
        if ($this->moderate()) {
            $form_data['status'] = $moduleprocessor_manager->getProcessor([\PoP_Module_Processor_CreateUpdatePostSelectFormInputs::class, \PoP_Module_Processor_CreateUpdatePostSelectFormInputs::MODULE_FORMINPUT_CUP_STATUS])->getValue([\PoP_Module_Processor_CreateUpdatePostSelectFormInputs::class, \PoP_Module_Processor_CreateUpdatePostSelectFormInputs::MODULE_FORMINPUT_CUP_STATUS]);
        } else {
            $keepasdraft = $moduleprocessor_manager->getProcessor([\PoP_Module_Processor_CreateUpdatePostCheckboxFormInputs::class, \PoP_Module_Processor_CreateUpdatePostCheckboxFormInputs::MODULE_FORMINPUT_CUP_KEEPASDRAFT])->getValue([\PoP_Module_Processor_CreateUpdatePostCheckboxFormInputs::class, \PoP_Module_Processor_CreateUpdatePostCheckboxFormInputs::MODULE_FORMINPUT_CUP_KEEPASDRAFT]);
            $form_data['status'] = $keepasdraft ? Status::DRAFT : Status::PUBLISHED;
        }

        if ($this->addReferences()) {
            $references = $moduleprocessor_manager->getProcessor([\PoP_Module_Processor_PostSelectableTypeaheadFormComponents::class, \PoP_Module_Processor_PostSelectableTypeaheadFormComponents::MODULE_FORMCOMPONENT_SELECTABLETYPEAHEAD_REFERENCES])->getValue([\PoP_Module_Processor_PostSelectableTypeaheadFormComponents::class, \PoP_Module_Processor_PostSelectableTypeaheadFormComponents::MODULE_FORMCOMPONENT_SELECTABLETYPEAHEAD_REFERENCES]);
            $form_data['references'] = $references ?? array();
        }

        if (\PoP_ApplicationProcessors_Utils::addCategories()) {
            $topics = $moduleprocessor_manager->getProcessor([\PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::class, \PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::MODULE_FORMINPUT_CATEGORIES])->getValue([\PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::class, \PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::MODULE_FORMINPUT_CATEGORIES]);
            $form_data['topics'] = $topics ?? array();
        }

        // Only if the Volunteering is enabled
        if (defined('POP_VOLUNTEERING_INITIALIZED')) {
            if (defined('POP_VOLUNTEERING_ROUTE_VOLUNTEER') && POP_VOLUNTEERING_ROUTE_VOLUNTEER) {
                if ($this->volunteer()) {
                    $form_data['volunteersneeded'] = $moduleprocessor_manager->getProcessor([\GD_Custom_Module_Processor_SelectFormInputs::class, \GD_Custom_Module_Processor_SelectFormInputs::MODULE_FORMINPUT_VOLUNTEERSNEEDED_SELECT])->getValue([\GD_Custom_Module_Processor_SelectFormInputs::class, GD_Custom_Module_Processor_SelectFormInputs::MODULE_FORMINPUT_VOLUNTEERSNEEDED_SELECT]);
                }
            }
        }

        if (\PoP_ApplicationProcessors_Utils::addAppliesto()) {
            $appliesto = $moduleprocessor_manager->getProcessor([\PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::class, \PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::MODULE_FORMINPUT_APPLIESTO])->getValue([\PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::class, PoP_Module_Processor_CreateUpdatePostMultiSelectFormInputs::MODULE_FORMINPUT_APPLIESTO]);
            $form_data['appliesto'] = $appliesto ?? array();
        }

        // Allow plugins to add their own fields
        return HooksAPIFacade::getInstance()->applyFilters(
            'GD_CreateUpdate_Post:form-data',
            $form_data
        );
    }

    protected function addReferences()
    {
        return true;
    }

    protected function volunteer()
    {
        return false;
    }

    protected function getEditorInput()
    {
        return [\PoP_Module_Processor_EditorFormInputs::class, \PoP_Module_Processor_EditorFormInputs::MODULE_FORMINPUT_EDITOR];
    }

    protected function getCategories()
    {
        if ($this->showCategories()) {
            if ($categories_module = $this->getCategoriesModule()) {
                $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();

                // We might decide to allow the user to input many sections, or only one section, so this value might be an array or just the value
                // So treat it always as an array
                $categories = $moduleprocessor_manager->getProcessor($categories_module)->getValue($categories_module);
                if ($categories && !is_array($categories)) {
                    $categories = array($categories);
                }

                return $categories;
            }
        }

        return array();
    }

    protected function showCategories()
    {
        return false;
    }

    protected function canInputMultipleCategories()
    {
        return false;
        // return HooksAPIFacade::getInstance()->applyFilters(
        //     'GD_CreateUpdate_Post:multiple-categories',
        //     true
        // );
    }

    protected function getCategoriesModule()
    {
        if ($this->showCategories()) {
            if ($this->canInputMultipleCategories()) {
                return [\PoP_Module_Processor_CreateUpdatePostButtonGroupFormInputs::class, \PoP_Module_Processor_CreateUpdatePostButtonGroupFormInputs::MODULE_FORMINPUT_BUTTONGROUP_POSTSECTIONS];
            }

            return [\PoP_Module_Processor_CreateUpdatePostButtonGroupFormInputs::class, \PoP_Module_Processor_CreateUpdatePostButtonGroupFormInputs::MODULE_FORMINPUT_BUTTONGROUP_POSTSECTION];
        }

        return null;
    }

    protected function supportsTitle()
    {
        // Not all post types support a title
        return true;
    }

    protected function getFeaturedimageModule()
    {
        return [\PoP_Module_Processor_FeaturedImageFormComponents::class, \PoP_Module_Processor_FeaturedImageFormComponents::MODULE_FORMCOMPONENT_FEATUREDIMAGE];
    }

    protected function moderate()
    {
        return \GD_CreateUpdate_Utils::moderate();
    }
}

