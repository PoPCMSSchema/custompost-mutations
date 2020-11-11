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
            // The ID is set always, but will be used only for update
            MutationInputProperties::ID => $_REQUEST[POP_INPUTNAME_POSTID],
            MutationInputProperties::CONTENT => trim($cmseditpostshelpers->kses(stripslashes($moduleprocessor_manager->getProcessor($editor)->getValue($editor)))),
            MutationInputProperties::TITLE => trim(strip_tags($moduleprocessor_manager->getProcessor([\PoP_Module_Processor_CreateUpdatePostTextFormInputs::class, \PoP_Module_Processor_CreateUpdatePostTextFormInputs::MODULE_FORMINPUT_CUP_TITLE])->getValue([\PoP_Module_Processor_CreateUpdatePostTextFormInputs::class, \PoP_Module_Processor_CreateUpdatePostTextFormInputs::MODULE_FORMINPUT_CUP_TITLE]))),
            MutationInputProperties::STATUS => $moduleprocessor_manager->getProcessor([\PoP_Module_Processor_CreateUpdatePostSelectFormInputs::class, \PoP_Module_Processor_CreateUpdatePostSelectFormInputs::MODULE_FORMINPUT_CUP_STATUS])->getValue([\PoP_Module_Processor_CreateUpdatePostSelectFormInputs::class, \PoP_Module_Processor_CreateUpdatePostSelectFormInputs::MODULE_FORMINPUT_CUP_STATUS]),
        );

        if ($this->showCategories()) {
            $form_data[MutationInputProperties::CATEGORIES] = $this->getCategories();
        }

        if ($featuredimage = $this->getFeaturedimageModule()) {
            $form_data[MutationInputProperties::FEATUREDIMAGE] = $moduleprocessor_manager->getProcessor($featuredimage)->getValue($featuredimage);
        }

        // Allow plugins to add their own fields
        return HooksAPIFacade::getInstance()->applyFilters(
            'GD_CreateUpdate_Post:form-data',
            $form_data
        );
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

    protected function getFeaturedimageModule()
    {
        return [\PoP_Module_Processor_FeaturedImageFormComponents::class, \PoP_Module_Processor_FeaturedImageFormComponents::MODULE_FORMCOMPONENT_FEATUREDIMAGE];
    }
}

